<?php
/**
 * SScribe Security Handler
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles security operations for SScribe export files.
 */
class SScribe_Security {

	/**
	 * Protect a directory with .htaccess and index.php files.
	 *
	 * @param string $dir Directory path to protect.
	 */
	public static function protect_directory( string $dir ): void {
		self::validate_path_scope( $dir );

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

			file_put_contents( $htaccess_path, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Required for directory security; path validated above.
		}

		$index_path = $dir . '/index.php';
		if ( ! file_exists( $index_path ) ) {
			file_put_contents( $index_path, "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Required for directory security; path validated above.
		}
	}

	/**
	 * Delete a directory and all its contents recursively.
	 *
	 * @param string $dir      Directory path to delete.
	 * @param int    $max_depth Maximum recursion depth.
	 * @param int    $depth     Current recursion depth.
	 * @return bool True if deleted, false otherwise.
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

		return rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Required for recursive directory deletion; path validated above.
	}

	/**
	 * Validate that a path is within the allowed scope.
	 *
	 * @param string $path Path to validate.
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
	 * Check if a path is within the uploads directory scope.
	 *
	 * @param string $path Path to check.
	 * @return bool True if path is in scope.
	 */
	private static function is_path_in_scope( string $path ): bool {
		if ( str_contains( $path, '..' ) ) {
			return false;
		}

		$upload_dir = wp_upload_dir();
		$base_dir   = trailingslashit( $upload_dir['basedir'] );

		// Use realpath for canonical path to prevent symlink bypass
		$real_path     = realpath( $path );
		$real_base_dir = realpath( $base_dir );

		if ( false === $real_path || false === $real_base_dir ) {
			return false;
		}

		// Normalize slashes for cross-platform comparison
		$real_path     = str_replace( '\\', '/', $real_path );
		$real_base_dir = str_replace( '\\', '/', $real_base_dir );

		return str_starts_with( $real_path, $real_base_dir . '/' );
	}
}
