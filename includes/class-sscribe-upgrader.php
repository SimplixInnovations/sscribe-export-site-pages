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

	/**
	 * Check and run any pending database migrations.
	 */
	public static function maybe_upgrade(): void {
		$installed_version = get_option( self::SCHEMA_VERSION_OPTION, '0' );
		$installed_version = is_scalar( $installed_version ) ? (string) $installed_version : '0';

		if ( version_compare( $installed_version, SSCRIBE_VERSION, '>=' ) ) {
			return;
		}

		$allowed_context = ( function_exists( 'is_admin' ) && is_admin() )
			|| ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() )
			|| ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() )
			|| ( defined( 'WP_CLI' ) && WP_CLI );
		if ( ! $allowed_context ) {
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
				$delay     = min( HOUR_IN_SECONDS, MINUTE_IN_SECONDS * ( 2 ** min( 6, $failures - 1 ) ) );
				update_option( self::UPGRADE_FAILURES_OPTION, $failures, false );
				update_option( self::UPGRADE_NEXT_ATTEMPT_OPTION, time() + $delay, false );
				update_option(
					'sscribe_upgrade_last_error',
					array(
						'message'   => 'The database upgrade did not complete and will be retried.',
						'reference' => $reference,
						'time'      => gmdate( 'Y-m-d H:i:s \U\T\C' ),
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
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();

		$upgrade_functions = ABSPATH . 'wp-admin/includes/upgrade.php';
		if ( file_exists( $upgrade_functions ) ) {
			require_once $upgrade_functions;
		}
		if ( ! function_exists( 'dbDelta' ) ) {
			throw new \RuntimeException( 'WordPress database upgrade functions are unavailable.' );
		}

		$table_logs = $wpdb->prefix . 'sscribe_export_logs';
		$sql_logs   = "CREATE TABLE $table_logs (
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
			KEY idx_session_id (session_id),
			KEY idx_user_id (user_id),
			KEY idx_request_id (request_id)
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
			KEY idx_user_id (user_id),
			KEY idx_export_date (export_date),
			KEY idx_status (status)
		) $charset_collate;";

		if ( version_compare( $from_version, '1.1.0', '<' ) ) {
			dbDelta( $sql_logs );
			dbDelta( $sql_stats );
			SScribe_Audit_Trail::create_table();
		}

		$sqlite = self::is_sqlite_database();
		if ( $sqlite && version_compare( $from_version, '1.1.7', '<' ) ) {
			self::run_sqlite_schema_convergence( $sql_logs, $sql_stats );
		}

		if ( ! $sqlite && version_compare( $from_version, '1.1.0', '<' ) ) {
			try {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema introspection; plugin-controlled table name.
				$index_check = $wpdb->get_results(
					$wpdb->prepare(
						'SHOW INDEX FROM ' . $wpdb->prefix . 'sscribe_export_stats WHERE Key_name = %s',
						'idx_export_session_id'
					)
				);
				if ( empty( $index_check ) ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared -- Schema change; table name is plugin-controlled constant.
					$result = $wpdb->query( 'ALTER TABLE `' . $wpdb->prefix . 'sscribe_export_stats` ADD INDEX idx_export_session_id (export_session_id)' );
					self::assert_schema_query_succeeded( $result );
				}
			} catch ( \Throwable $e ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The exception is handled internally by maybe_upgrade() and is never rendered.
				throw new \RuntimeException( 'Failed while adding the export-session index.', 0, $e );
			}
		}

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

		if ( ! $sqlite && version_compare( $from_version, '1.1.0', '<' ) ) {
			try {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema introspection; plugin-controlled table name.
				$col          = $wpdb->get_row(
					$wpdb->prepare(
						'SHOW COLUMNS FROM ' . $wpdb->prefix . 'sscribe_export_stats LIKE %s',
						$wpdb->esc_like( 'export_session_id' )
					)
				);
				$needs_modify = true;
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- MySQL SHOW COLUMNS result.
				if ( $col && isset( $col->Type ) && stripos( $col->Type, 'varchar(64)' ) !== false ) {
					$needs_modify = false;
				}
				if ( $needs_modify ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared -- Schema change; table name is plugin-controlled constant.
					$result = $wpdb->query( 'ALTER TABLE `' . $wpdb->prefix . 'sscribe_export_stats` MODIFY COLUMN export_session_id VARCHAR(64) NOT NULL' );
					self::assert_schema_query_succeeded( $result );
				}
			} catch ( \Throwable $e ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The exception is handled internally by maybe_upgrade() and is never rendered.
				throw new \RuntimeException( 'Failed while updating the export-session column.', 0, $e );
			}
		}

		if ( ! $sqlite && version_compare( $from_version, '1.1.3', '<' ) ) {
			try {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema introspection; plugin-controlled table name.
				$col = $wpdb->get_row(
					$wpdb->prepare(
						'SHOW COLUMNS FROM ' . $wpdb->prefix . 'sscribe_export_logs LIKE %s',
						$wpdb->esc_like( 'session_id' )
					)
				);
				if ( ! $col ) {
					// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Static DDL string; $wpdb->prefix is a plugin-controlled constant; the column definitions are hardcoded.
					$alter_sql = 'ALTER TABLE `' . $wpdb->prefix . 'sscribe_export_logs` ADD COLUMN session_id VARCHAR(60) DEFAULT NULL AFTER context, ADD KEY idx_session_id (session_id)';
					// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared -- Schema change; $alter_sql is a hardcoded DDL string with no user input; $wpdb->prefix is a plugin-controlled constant.
					$result = $wpdb->query( $alter_sql );
					self::assert_schema_query_succeeded( $result );
				}
			} catch ( \Throwable $e ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The exception is handled internally by maybe_upgrade() and is never rendered.
				throw new \RuntimeException( 'Failed while adding the session-log column.', 0, $e );
			}
		}

		if ( ! $sqlite && version_compare( $from_version, '1.1.7', '<' ) ) {
			try {
				// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared -- Plugin-controlled table and static schema migration.
				$result = $wpdb->query( 'ALTER TABLE `' . $wpdb->prefix . "sscribe_export_stats` MODIFY COLUMN status VARCHAR(20) NOT NULL DEFAULT 'processing'" );
				self::assert_schema_query_succeeded( $result );
			} catch ( \Throwable $e ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The exception is handled internally by maybe_upgrade() and is never rendered.
				throw new \RuntimeException( 'Failed while updating the export-status column.', 0, $e );
			}
		}

		if ( version_compare( $from_version, '2.0.0', '<' ) && ! SScribe_Private_Storage::migrate_legacy_storage() ) {
			throw new \RuntimeException( 'Failed while migrating export artifacts to private storage.' );
		}
	}

	private static function is_sqlite_database(): bool {
		if ( defined( 'DB_ENGINE' ) && 'sqlite' === strtolower( (string) DB_ENGINE ) ) {
			return true;
		}
		global $wpdb;
		return is_object( $wpdb ) && false !== stripos( get_class( $wpdb ), 'sqlite' );
	}

	private static function run_sqlite_schema_convergence( string $sql_logs, string $sql_stats ): void {
		dbDelta( $sql_logs );
		dbDelta( $sql_stats );
		SScribe_Audit_Trail::create_table();
	}

	/**
	 * Convert a failed wpdb schema query into a retryable migration failure.
	 *
	 * @param int|bool $result wpdb query result.
	 * @throws \RuntimeException When wpdb reports failure.
	 */
	private static function assert_schema_query_succeeded( $result ): void {
		if ( false === $result ) {
			throw new \RuntimeException( 'A required database schema query failed.' );
		}
	}
}
