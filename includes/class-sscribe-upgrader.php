<?php
/**
 * Version-aware upgrade system for SScribe.
 *
 * Handles schema migrations, data transformations, and cleanup
 * when the plugin is updated between versions.
 *
 * @package SScribe
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SScribe_Upgrader
 *
 * Q-3: Manages incremental upgrades between plugin versions.
 */
class SScribe_Upgrader {

	/**
	 * Option key for storing the installed schema version.
	 */
	private const SCHEMA_VERSION_OPTION = 'sscribe_schema_version';

	/**
	 * Run any pending upgrades.
	 *
	 * Called on `plugins_loaded` to check if the installed version
	 * differs from the code version and apply migrations.
	 *
	 * @return void
	 */
	public static function maybe_upgrade(): void {
		$installed_version = get_option( self::SCHEMA_VERSION_OPTION, '0' );

		if ( version_compare( $installed_version, SSCRIBE_VERSION, '>=' ) ) {
			return;
		}

		// Prevent concurrent upgrades.
		if ( get_transient( 'sscribe_upgrade_lock' ) ) {
			return;
		}
		set_transient( 'sscribe_upgrade_lock', true, 20 * MINUTE_IN_SECONDS );

		try {
			try {
				self::run_migrations( $installed_version );
				update_option( self::SCHEMA_VERSION_OPTION, SSCRIBE_VERSION, false );
				update_option( 'sscribe_version', SSCRIBE_VERSION );
			} catch ( \Throwable $e ) {
				update_option( 'sscribe_upgrade_last_error', $e->getMessage() );
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug-only error logging for upgrade failures
					error_log( 'SScribe Upgrade Error: ' . $e->getMessage() );
				}
			}
		} finally {
			delete_transient( 'sscribe_upgrade_lock' );
		}
	}

	/**
	 * Run incremental migrations from the installed version to current.
	 *
	 * @param string $from_version Currently installed version.
	 * @return void
	 */
	private static function run_migrations( string $from_version ): void {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// Migration: 3.30.13 — Add dedicated sessions table + stats index.
		if ( version_compare( $from_version, '3.30.13', '<' ) ) {
			$table_sessions = $wpdb->prefix . 'sscribe_sessions';
			$sql_sessions   = "CREATE TABLE $table_sessions (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				session_id VARCHAR(64) NOT NULL,
				user_id BIGINT UNSIGNED NOT NULL,
				status ENUM('pending', 'processing', 'completed', 'failed', 'paused', 'cancelled') DEFAULT 'pending',
				language VARCHAR(10) NOT NULL DEFAULT '',
				post_status VARCHAR(20) NOT NULL DEFAULT 'publish',
				formats LONGTEXT,
				total_pages INT UNSIGNED DEFAULT 0,
				processed_pages INT UNSIGNED DEFAULT 0,
				current_page_index INT UNSIGNED DEFAULT 0,
				page_ids LONGTEXT,
				session_data LONGTEXT,
				signature VARCHAR(64),
				created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				expires_at DATETIME,
				PRIMARY KEY  (id),
				UNIQUE KEY idx_session_id (session_id),
				KEY idx_user_id (user_id),
				KEY idx_status (status),
				KEY idx_expires_at (expires_at)
			) $charset_collate;";
			dbDelta( $sql_sessions );

			// Add missing index on stats table.
			$table_stats = $wpdb->prefix . 'sscribe_export_stats';
			try {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema introspection
				$index_check = $wpdb->get_results(
					$wpdb->prepare(
						'SHOW INDEX FROM %i WHERE Key_name = %s',
						$table_stats,
						'idx_export_session_id'
					)
				);
				if ( empty( $index_check ) ) {
					$sql_stats = "ALTER TABLE {$table_stats} ADD INDEX idx_export_session_id (export_session_id)";
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
					$wpdb->query( $sql_stats );
				}
			} catch ( \Throwable $e ) {
				update_option( 'sscribe_upgrade_last_error', 'Error adding idx_export_session_id: ' . $e->getMessage() );
			}
		}

		// Migration: 3.33.0 — Fix export_session_id column width (VARCHAR(12) → VARCHAR(64)).
		if ( version_compare( $from_version, '3.33.0', '<' ) ) {
			$table_stats = $wpdb->prefix . 'sscribe_export_stats';
			try {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema introspection
				$col          = $wpdb->get_row(
					$wpdb->prepare(
						'SHOW COLUMNS FROM %i LIKE %s',
						$table_stats,
						'export_session_id'
					)
				);
				$needs_modify = true;
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- MySQL SHOW COLUMNS result
				if ( $col && isset( $col->Type ) && stripos( $col->Type, 'varchar(64)' ) !== false ) {
					$needs_modify = false;
				}
				if ( $needs_modify ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$wpdb->query( "ALTER TABLE {$table_stats} MODIFY COLUMN export_session_id VARCHAR(64) NOT NULL" );
				}
			} catch ( \Throwable $e ) {
				update_option( 'sscribe_upgrade_last_error', 'Error modifying export_session_id: ' . $e->getMessage() );
			}
		}
	}
}
