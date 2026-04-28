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
 * and file system hardening with path-traversal prevention.
 */
class SScribe_Security {

	/**
	 * Protect a directory with .htaccess and index.php files.
	 *
	 * Creates .htaccess rules to deny all direct access (both Apache 2.4+
	 * and legacy 2.2 syntax) and an empty index.php to prevent directory
	 * listing on servers that ignore .htaccess.
	 *
	 * SECURITY: Validates that the target directory resides within the
	 * WordPress uploads directory to prevent arbitrary .htaccess
	 * creation via path traversal.
	 *
	 * @param string $dir Absolute path to the directory to protect.
	 * @return void
	 *
	 * @throws \InvalidArgumentException If directory is outside allowed scope.
	 */
	public static function protect_directory( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		self::validate_path_scope( $dir );

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

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Required for directory security; path validated above.
			file_put_contents( $htaccess_path, $content );
		}

		$index_path = $dir . '/index.php';
		if ( ! file_exists( $index_path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Required for directory security; path validated above.
			file_put_contents( $index_path, "<?php\n// Silence is golden.\n" );
		}
	}

	/**
	 * Recursively delete a directory and its contents.
	 *
	 * SECURITY: Checks for symlinks BEFORE recursing into directories
	 * to prevent symlink-based path traversal attacks.
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

		if ( ! self::is_path_in_scope( $dir ) ) {
			return false;
		}

		$files = array_diff( scandir( $dir ), array( '.', '..' ) );
		foreach ( $files as $file ) {
			$path = $dir . '/' . $file;

			if ( is_link( $path ) ) {
				wp_delete_file( $path );
			} elseif ( is_dir( $path ) ) {
				self::delete_directory( $path, $max_depth, $depth + 1 );
			} else {
				wp_delete_file( $path );
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Required for recursive directory deletion; path validated above.
		return rmdir( $dir );
	}

	/**
	 * Throw if the path is outside the allowed uploads scope.
	 *
	 * @param string $path Absolute path to validate.
	 * @return void
	 *
	 * @throws \InvalidArgumentException If path is outside allowed scope.
	 */
	private static function validate_path_scope( string $path ): void {
		if ( ! self::is_path_in_scope( $path ) ) {
			throw new \InvalidArgumentException(
				sprintf(
					'Directory "%s" is outside the allowed uploads scope.',
					esc_html( basename( $path ) )
				)
			);
		}
	}

	/**
	 * Check if a path is within the allowed uploads directory scope.
	 *
	 * Uses string-prefix comparison so it works correctly in both
	 * production (wp-content/uploads) and test (sys_get_temp_dir) environments.
	 * Relies on wp_upload_dir() to define the valid boundary.
	 *
	 * @param string $path Absolute path to check.
	 * @return bool True if the path is in scope.
	 */
	private static function is_path_in_scope( string $path ): bool {
		$upload_dir = wp_upload_dir();
		$base_dir   = trailingslashit( $upload_dir['basedir'] );

		return str_starts_with( str_replace( '\\', '/', $path ), str_replace( '\\', '/', $base_dir ) );
	}
}
