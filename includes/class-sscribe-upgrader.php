<?php
/**
 * SScribe Upgrader
 *
 * @package SScribe_Export_Site_Pages
 * @license GPL v2 or later
 * @link    https://www.gnu.org/licenses/gpl-2.0.html
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles database schema upgrades and migrations.
 */
class SScribe_Upgrader {

	private const SCHEMA_VERSION_OPTION      = 'sscribe_schema_version';
	private const FAILURE_COUNT_OPTION       = 'sscribe_upgrade_failures';
	private const NEXT_ATTEMPT_OPTION        = 'sscribe_upgrade_next_attempt';
	private const LAST_ERROR_OPTION          = 'sscribe_upgrade_last_error';
	private const MAX_RETRY_BACKOFF_SECONDS  = HOUR_IN_SECONDS;

	/**
	 * Check and run any pending database migrations.
	 *
	 * Upgrades are intentionally excluded from ordinary frontend traffic.
	 * Fresh installs create the canonical schema during activation; upgrades
	 * from older versions run on the next administrative/AJAX/cron/CLI request.
	 * Persisted exponential backoff prevents a permanently failing migration
	 * from turning every eligible request into repeated DDL/filesystem work.
	 */
	public static function maybe_upgrade(): void {
		$installed_version = get_option( self::SCHEMA_VERSION_OPTION, '0' );
		$installed_version = is_scalar( $installed_version ) ? (string) $installed_version : '0';

		if ( version_compare( $installed_version, SSCRIBE_VERSION, '>=' ) ) {
			return;
		}

		if ( ! self::is_upgrade_context() ) {
			return;
		}

		$next_attempt = (int) get_option( self::NEXT_ATTEMPT_OPTION, 0 );
		if ( $next_attempt > time() ) {
			return;
		}

		// Honor the legacy transient lock during rolling updates from older builds.
		if ( false !== get_transient( 'sscribe_upgrade_lock' ) ) {
			return;
		}

		$lock_manager = new SScribe_Export_Lock_Manager();
		$lock_name    = 'upgrade';
		$lock_token   = $lock_manager->acquire_lock( $lock_name, 20 * MINUTE_IN_SECONDS, 19 * MINUTE_IN_SECONDS );
		if ( null === $lock_token ) {
			return;
		}

		try {
			try {
				self::run_migrations( $installed_version );

				delete_transient( 'sscribe_admin_page_data_v2_' . $installed_version );
				delete_transient( 'sscribe_wpml_languages' );
				update_option( self::SCHEMA_VERSION_OPTION, SSCRIBE_VERSION, false );
				update_option( 'sscribe_version', SSCRIBE_VERSION, false );
				delete_option( self::LAST_ERROR_OPTION );
				delete_option( self::FAILURE_COUNT_OPTION );
				delete_option( self::NEXT_ATTEMPT_OPTION );
			} catch ( \Throwable $e ) {
				$failures  = max( 0, (int) get_option( self::FAILURE_COUNT_OPTION, 0 ) ) + 1;
				$exponent  = min( 6, max( 0, $failures - 1 ) );
				$delay     = min( self::MAX_RETRY_BACKOFF_SECONDS, MINUTE_IN_SECONDS * ( 2 ** $exponent ) );
				$retry_at  = time() + $delay;
				$reference = substr( hash( 'sha256', get_class( $e ) . '|' . $e->getMessage() ), 0, 12 );

				update_option( self::FAILURE_COUNT_OPTION, $failures, false );
				update_option( self::NEXT_ATTEMPT_OPTION, $retry_at, false );
				update_option(
					self::LAST_ERROR_OPTION,
					array(
						'message'      => 'The database upgrade did not complete and will be retried with backoff.',
						'reference'    => $reference,
						'time'         => gmdate( 'Y-m-d H:i:s \U\T\C' ),
						'failures'     => $failures,
						'next_attempt' => gmdate( 'Y-m-d H:i:s \U\T\C', $retry_at ),
					),
					false
				);
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( 'SScribe upgrade error [' . $reference . ']: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug-only error logging for upgrade failures.
				}
			}
		} finally {
			$lock_manager->release_lock( $lock_name, $lock_token );
		}
	}

	/**
	 * Whether this request is an appropriate place to perform migrations.
	 */
	private static function is_upgrade_context(): bool {
		return is_admin()
			|| wp_doing_ajax()
			|| wp_doing_cron()
			|| ( defined( 'WP_CLI' ) && WP_CLI );
	}

	/**
	 * Execute database migrations from a specific version.
	 *
	 * Schema convergence uses the same canonical dbDelta-compatible
	 * definitions as activation. This avoids database-engine-specific SHOW/
	 * ALTER introspection and keeps MySQL and the supported SQLite integration
	 * on one path.
	 *
	 * @param string $from_version Version to migrate from.
	 * @throws \RuntimeException When the WordPress upgrade helper is unavailable
	 *                           or a required storage migration fails.
	 */
	private static function run_migrations( string $from_version ): void {
		global $wpdb;

		$upgrade_functions = ABSPATH . 'wp-admin/includes/upgrade.php';
		if ( file_exists( $upgrade_functions ) ) {
			require_once $upgrade_functions;
		}
		if ( ! function_exists( 'dbDelta' ) ) {
			throw new \RuntimeException( 'WordPress database upgrade functions are unavailable.' );
		}

		$charset_collate = $wpdb->get_charset_collate();
		$table_logs      = $wpdb->prefix . 'sscribe_export_logs';
		$sql_logs        = "CREATE TABLE $table_logs (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			timestamp DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			level VARCHAR(20) NOT NULL,
			message TEXT NOT NULL,
			context LONGTEXT,
			session_id VARCHAR(60) DEFAULT NULL,
			user_id BIGINT UNSIGNED,
			request_id VARCHAR(12),
			memory_usage VARCHAR(20),
			PRIMARY KEY  (id),
			KEY idx_timestamp (timestamp),
			KEY idx_level (level),
			KEY idx_user_id (user_id),
			KEY idx_request_id (request_id),
			KEY idx_session_id (session_id)
		) $charset_collate;";

		$table_stats = $wpdb->prefix . 'sscribe_export_stats';
		$sql_stats   = "CREATE TABLE $table_stats (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			export_session_id VARCHAR(64) NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL,
			export_date DATETIME NOT NULL,
			total_pages INT UNSIGNED,
			successful_pages INT UNSIGNED,
			failed_pages INT UNSIGNED,
			formats LONGTEXT,
			memory_peak VARCHAR(20),
			duration_seconds FLOAT,
			file_size_mb DECIMAL(10, 2),
			status VARCHAR(20) NOT NULL DEFAULT 'processing',
			error_message TEXT,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_export_session_id (export_session_id),
			KEY idx_export_date (export_date),
			KEY idx_user_id (user_id),
			KEY idx_status (status)
		) $charset_collate;";

		// dbDelta is idempotent and converges missing columns/indexes on both
		// supported database paths. Run it for every pending plugin upgrade so
		// an installation that skipped an intermediate historical release still
		// reaches the exact current schema.
		dbDelta( $sql_logs );
		dbDelta( $sql_stats );
		SScribe_Audit_Trail::create_table();

		if ( version_compare( $from_version, '1.1.1', '<' ) ) {
			$metrics = get_option( 'sscribe_export_metrics', array() );
			if ( isset( $metrics['formats'] ) && is_array( $metrics['formats'] ) ) {
				$migrated      = false;
				$known_formats = array( 'docx', 'pdf', 'html', 'markdown' );
				foreach ( array_keys( $metrics['formats'] ) as $key ) {
					if ( false === strpos( $key, '_' ) && in_array( $key, $known_formats, true ) ) {
						$new_key = $key . '_page';
						if ( ! isset( $metrics['formats'][ $new_key ] ) ) {
							$metrics['formats'][ $new_key ] = $metrics['formats'][ $key ];
						}
						unset( $metrics['formats'][ $key ] );
						$migrated = true;
					}
				}
				if ( $migrated ) {
					update_option( 'sscribe_export_metrics', $metrics, false );
				}
			}
		}

		if ( version_compare( $from_version, '2.0.0', '<' ) && ! SScribe_Private_Storage::migrate_legacy_storage() ) {
			throw new \RuntimeException( 'Failed while migrating export artifacts to private storage.' );
		}
	}
}
