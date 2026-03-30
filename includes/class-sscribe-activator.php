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
		self::schedule_cleanup();
		self::cleanup_orphaned_data();
		update_option( 'sscribe_version', SSCRIBE_VERSION );
	}

	/**
	 * Create the export directory with security files.
	 *
	 * @return void
	 */
	private static function create_export_directory(): void {
		$upload_dir  = wp_upload_dir();
		$export_path = $upload_dir['basedir'] . '/sscribe-exports';

		if ( ! file_exists( $export_path ) ) {
			wp_mkdir_p( $export_path );
		}

		$htaccess_path = $export_path . '/.htaccess';
		if ( ! file_exists( $htaccess_path ) ) {
			$htaccess_content  = "Options -Indexes\n";
			$htaccess_content .= "<Files \"*\">\n";
			$htaccess_content .= "  <IfModule mod_authz_core.c>\n";
			$htaccess_content .= "    Require all denied\n";
			$htaccess_content .= "  </IfModule>\n";
			$htaccess_content .= "  <IfModule !mod_authz_core.c>\n";
			$htaccess_content .= "    Order Allow,Deny\n";
			$htaccess_content .= "    Deny from all\n";
			$htaccess_content .= "  </IfModule>\n";
			$htaccess_content .= "</Files>\n";

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $htaccess_path, $htaccess_content );
		}

		$index_path = $export_path . '/index.php';
		if ( ! file_exists( $index_path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $index_path, "<?php\n// Silence is golden.\n" );
		}
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

		$session_pattern = $wpdb->esc_like( '_transient_sscribe_session_' ) . '%';
		$lock_pattern    = $wpdb->esc_like( '_transient_sscribe_lock_' ) . '%';
		$rate_pattern    = $wpdb->esc_like( '_transient_sscribe_rate_' ) . '%';

		$patterns = array( $session_pattern, $lock_pattern, $rate_pattern );

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
