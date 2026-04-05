<?php
/**
 * Security helper functions for SScribe.
 *
 * @package SScribe
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SScribe_Security
 *
 * Provides reusable security methods for directory protection
 * and file system hardening.
 */
class SScribe_Security {

	/**
	 * Protect a directory with .htaccess and index.php files.
	 *
	 * Creates .htaccess rules to deny all direct access (both Apache 2.4+
	 * and legacy 2.2 syntax) and an empty index.php to prevent directory
	 * listing on servers that ignore .htaccess.
	 *
	 * @param string $dir Absolute path to the directory to protect.
	 * @return void
	 */
	public static function protect_directory( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		$htaccess_path = $dir . '/.htaccess';
		if ( ! file_exists( $htaccess_path ) ) {
			$content  = "Options -Indexes\n";
			$content .= "<Files \"*\">\n";
			$content .= "  <IfModule mod_authz_core.c>\n";
			$content .= "    Require all denied\n";
			$content .= "  </IfModule>\n";
			$content .= "  <IfModule !mod_authz_core.c>\n";
			$content .= "    Order Allow,Deny\n";
			$content .= "    Deny from all\n";
			$content .= "  </IfModule>\n";
			$content .= "</Files>\n";

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Required for directory security.
			file_put_contents( $htaccess_path, $content );
		}

		$index_path = $dir . '/index.php';
		if ( ! file_exists( $index_path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Required for directory security.
			file_put_contents( $index_path, "<?php\n// Silence is golden.\n" );
		}
	}

	/**
	 * Recursively delete a directory and its contents with depth protection.
	 *
	 * @param string $dir        Directory path.
	 * @param int    $max_depth  Maximum recursion depth (default 20).
	 * @param int    $depth      Current recursion depth (internal use).
	 * @return bool True if directory was deleted, false otherwise.
	 */
	public static function delete_directory( string $dir, int $max_depth = 20, int $depth = 0 ): bool {
		if ( ! is_dir( $dir ) ) {
			return false;
		}

		if ( $depth >= $max_depth ) {
			return false;
		}

		$files = array_diff( scandir( $dir ), array( '.', '..' ) );
		foreach ( $files as $file ) {
			$path = $dir . '/' . $file;
			if ( is_dir( $path ) && ! is_link( $path ) ) {
				self::delete_directory( $path, $max_depth, $depth + 1 );
			} else if ( is_link( $path ) ) {
				wp_delete_file( $path );
			} else {
				wp_delete_file( $path );
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Required for recursive directory deletion.
		return rmdir( $dir );
	}
}
