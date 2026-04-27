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
		set_transient( 'sscribe_upgrade_lock', true, 2 * MINUTE_IN_SECONDS );

		try {
			self::run_migrations( $installed_version );
			update_option( self::SCHEMA_VERSION_OPTION, SSCRIBE_VERSION, false );
			update_option( 'sscribe_version', SSCRIBE_VERSION );
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
				formats JSON,
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
				INDEX idx_user_id (user_id),
				INDEX idx_status (status),
				INDEX idx_expires_at (expires_at)
			) $charset_collate;";
			dbDelta( $sql_sessions );

			// Add missing index on stats table.
			$table_stats = $wpdb->prefix . 'sscribe_export_stats';
			$sql_stats   = "ALTER TABLE $table_stats ADD INDEX idx_export_session_id (export_session_id)";
			// dbDelta handles ALTER TABLE poorly; use direct query.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( $sql_stats );
		}
	}
}
