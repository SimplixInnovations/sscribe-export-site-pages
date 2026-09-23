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

	private const SCHEMA_VERSION_OPTION = 'sscribe_schema_version';

	private const UPGRADE_FAILURES_OPTION = 'sscribe_upgrade_failures';

	private const UPGRADE_NEXT_ATTEMPT_OPTION = 'sscribe_upgrade_next_attempt';

	private const UPGRADE_BACKOFF_BASE_SECONDS = 30;

	private const UPGRADE_BACKOFF_MAX_SECONDS = 6 * HOUR_IN_SECONDS;

	/**
	 * Check and run any pending database migrations.
	 */
	public static function maybe_upgrade(): void {
		$installed_version = get_option( self::SCHEMA_VERSION_OPTION, '0' );
		$installed_version = is_scalar( $installed_version ) ? (string) $installed_version : '0';

		if ( version_compare( $installed_version, SSCRIBE_VERSION, '>=' ) ) {
			return;
		}

		// Schema work must never turn an ordinary anonymous frontend request into
		// a DDL retry loop. Admin, AJAX, cron, and WP-CLI are controlled execution
		// contexts where an upgrade failure can be surfaced and retried safely.
		if (
			! is_admin()
			&& ! wp_doing_ajax()
			&& ! wp_doing_cron()
			&& ! ( defined( 'WP_CLI' ) && WP_CLI )
		) {
			return;
		}

		$next_attempt = (int) get_option( self::UPGRADE_NEXT_ATTEMPT_OPTION, 0 );
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
				delete_option( 'sscribe_upgrade_last_error' );
				delete_option( self::UPGRADE_FAILURES_OPTION );
				delete_option( self::UPGRADE_NEXT_ATTEMPT_OPTION );
			} catch ( \Throwable $e ) {
				$reference = substr( hash( 'sha256', get_class( $e ) . '|' . $e->getMessage() ), 0, 12 );
				$failures  = max( 0, (int) get_option( self::UPGRADE_FAILURES_OPTION, 0 ) ) + 1;
				$exponent  = min( 10, max( 0, $failures - 1 ) );
				$delay     = min(
					self::UPGRADE_BACKOFF_MAX_SECONDS,
					self::UPGRADE_BACKOFF_BASE_SECONDS * ( 2 ** $exponent )
				);
				$next_attempt = time() + $delay;
				update_option( self::UPGRADE_FAILURES_OPTION, $failures, false );
				update_option( self::UPGRADE_NEXT_ATTEMPT_OPTION, $next_attempt, false );
				update_option(
					'sscribe_upgrade_last_error',
					array(
						'message'      => 'The database upgrade did not complete and will be retried.',
						'reference'    => $reference,
						'time'         => gmdate( 'Y-m-d H:i:s \U\T\C' ),
						'failures'     => $failures,
						'next_attempt' => gmdate( 'Y-m-d H:i:s \U\T\C', $next_attempt ),
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
	 * Execute database migrations from a specific version.
	 *
	 * @param string $from_version Version to migrate from.
	 * @throws \RuntimeException When a required schema change fails.
	 */
	private static function run_migrations( string $from_version ): void {
		// One canonical schema definition is safer than a parallel set of MySQL-
		// specific SHOW/ALTER statements. dbDelta() is the WordPress-supported
		// reconciliation path and is already exercised by the plugin's real
		// SQLite and MySQL integration matrices.
		SScribe_Activator::create_database_tables( false );

		if ( version_compare( $from_version, '1.1.1', '<' ) ) {
			$metrics = get_option( 'sscribe_export_metrics', array() );
			if ( isset( $metrics['formats'] ) && is_array( $metrics['formats'] ) ) {
				$migrated      = false;
				$known_formats = array( 'docx', 'pdf', 'html', 'markdown' );
				foreach ( $known_formats as $key ) {
					if ( ! array_key_exists( $key, $metrics['formats'] ) ) {
						continue;
					}
					$new_key = $key . '_page';
					if ( ! array_key_exists( $new_key, $metrics['formats'] ) ) {
						$metrics['formats'][ $new_key ] = $metrics['formats'][ $key ];
					}
					unset( $metrics['formats'][ $key ] );
					$migrated = true;
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
