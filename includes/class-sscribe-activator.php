<?php
/**
 * Fired during plugin activation.
 *
 * @package SScribe
 */

// Prevent direct access.
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
	public static function activate() {
		 self::create_export_directory();
		self::schedule_cleanup();
		update_option( 'sscribe_version', SSCRIBE_VERSION );
	}

	/**
	 * Create the export directory with security files.
	 *
	 * @return void
	 */
	private static function create_export_directory() {
		 $upload_dir = wp_upload_dir();
		$export_path = $upload_dir['basedir'] . '/sscribe-exports';

		if ( ! file_exists( $export_path ) ) {
			wp_mkdir_p( $export_path );
		}

		// .htaccess to prevent direct access.
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
			// Allow PHP to serve files via download handler.
			$htaccess_content .= "<Files \"*.php\">\n";
			$htaccess_content .= "  <IfModule mod_authz_core.c>\n";
			$htaccess_content .= "    Require all granted\n";
			$htaccess_content .= "  </IfModule>\n";
			$htaccess_content .= "  <IfModule !mod_authz_core.c>\n";
			$htaccess_content .= "    Order Allow,Deny\n";
			$htaccess_content .= "    Allow from all\n";
			$htaccess_content .= "  </IfModule>\n";
			$htaccess_content .= "</Files>\n";

            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $htaccess_path, $htaccess_content );
		}

		// index.php to prevent directory listing.
		$index_path = $export_path . '/index.php';
		if ( ! file_exists( $index_path ) ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $index_path, "<?php\n// Silence is golden.\n" );
		}
	}

	/**
	 * Schedule hourly cleanup cron event.
	 *
	 * @return void
	 */
	private static function schedule_cleanup() {
		if ( ! wp_next_scheduled( 'sscribe_cleanup_exports' ) ) {
			wp_schedule_event( time(), 'hourly', 'sscribe_cleanup_exports' );
		}
	}
}
