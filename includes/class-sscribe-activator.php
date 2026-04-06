<?php
/**
 * Fired during plugin activation.
 *
 * @package SScribe
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SScribe_Activator
 *
 * Creates the export directory, security files, and schedules cleanup cron.
 */
class SScribe_Activator {

	/**
	 * Run activation tasks.
	 *
	 * @return void
	 */
	public static function activate(): void {
		self::create_export_directory();
		self::create_database_tables();
		self::schedule_cleanup();
		self::cleanup_orphaned_data();
		update_option( 'sscribe_version', SSCRIBE_VERSION );
	}

	/**
	 * Create database tables for logging and stats.
	 *
	 * @return void
	 */
	private static function create_database_tables(): void {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		$table_logs = $wpdb->prefix . 'sscribe_export_logs';

		$sql_logs = "CREATE TABLE $table_logs (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			timestamp DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			level VARCHAR(20) NOT NULL,
			message TEXT NOT NULL,
			context LONGTEXT,
			user_id BIGINT UNSIGNED,
			request_id VARCHAR(12),
			memory_usage VARCHAR(20),
			PRIMARY KEY  (id),
			INDEX idx_timestamp (timestamp),
			INDEX idx_level (level),
			INDEX idx_user_id (user_id),
			INDEX idx_request_id (request_id)
		) $charset_collate;";

		$table_stats = $wpdb->prefix . 'sscribe_export_stats';

		$sql_stats = "CREATE TABLE $table_stats (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			export_session_id VARCHAR(12) NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL,
			export_date DATETIME NOT NULL,
			total_pages INT UNSIGNED,
			successful_pages INT UNSIGNED,
			failed_pages INT UNSIGNED,
			formats JSON,
			memory_peak VARCHAR(20),
			duration_seconds FLOAT,
			file_size_mb DECIMAL(10, 2),
			status ENUM('completed', 'failed', 'paused') DEFAULT 'completed',
			error_message TEXT,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			INDEX idx_export_date (export_date),
			INDEX idx_user_id (user_id),
			INDEX idx_status (status)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta( $sql_logs );
		dbDelta( $sql_stats );

		// Create audit log table.
		require_once SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-audit-trail.php';
		SScribe_Audit_Trail::create_table();
	}

	/**
	 * Create the export directory with security files.
	 *
	 * @return void
	 */
	private static function create_export_directory(): void {
		$upload_dir  = wp_upload_dir();
		$export_path = $upload_dir['basedir'] . '/sscribe-exports';

		SScribe_Security::protect_directory( $export_path );
	}

	/**
	 * Schedule hourly cleanup cron events.
	 *
	 * @return void
	 */
	private static function schedule_cleanup(): void {
		if ( ! wp_next_scheduled( 'sscribe_cleanup_exports' ) ) {
			wp_schedule_event( time(), 'hourly', 'sscribe_cleanup_exports' );
		}

		if ( ! wp_next_scheduled( 'sscribe_cleanup_sessions' ) ) {
			wp_schedule_event( time(), 'hourly', 'sscribe_cleanup_sessions' );
		}
	}

	/**
	 * Clean up orphaned sessions and locks from previous installations.
	 *
	 * This ensures a clean state when reinstalling or updating the plugin.
	 *
	 * @return void
	 */
	private static function cleanup_orphaned_data(): void {
		global $wpdb;

		// Clean up legacy transient-based sessions (pre-3.5.0).
		$session_pattern = $wpdb->esc_like( '_transient_sscribe_session_' ) . '%';
		$lock_pattern    = $wpdb->esc_like( '_transient_sscribe_lock_' ) . '%';
		$rate_pattern    = $wpdb->esc_like( '_transient_sscribe_rate_' ) . '%';

		// Clean up raw option-based sessions (3.5.0+).
		$session_option_pattern = $wpdb->esc_like( 'sscribe_session_' ) . '%';

		$patterns = array( $session_pattern, $lock_pattern, $rate_pattern, $session_option_pattern );

		foreach ( $patterns as $pattern ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cleanup operation during activation.
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
					$pattern
				)
			);
		}

		$timeout_patterns = array(
			$wpdb->esc_like( '_transient_timeout_sscribe_session_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_sscribe_lock_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_sscribe_rate_' ) . '%',
		);

		foreach ( $timeout_patterns as $pattern ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cleanup operation during activation.
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
					$pattern
				)
			);
		}
	}
}
