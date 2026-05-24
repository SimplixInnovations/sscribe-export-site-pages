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
		$content      = "Options -Indexes\n";
		$content     .= "<Files \"*\">\n";
		$content     .= "  <IfModule mod_authz_core.c>\n";
		$content     .= "    Require all denied\n";
		$content     .= "  </IfModule>\n";
		$content     .= "  <IfModule !mod_authz_core.c>\n";
		$content     .= "    Order Allow,Deny\n";
		$content     .= "    Deny from all\n";
		$content     .= "  </IfModule>\n";
		$content     .= "</Files>\n";

		file_put_contents( $htaccess_path, $content, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Required for directory security; path validated above.

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
		if ( '' === trim( $path ) || str_contains( $path, '..' ) ) {
			return false;
		}

		$upload_dir = wp_upload_dir();
		$base_dir   = trailingslashit( $upload_dir['basedir'] );

		// Use realpath for canonical path to prevent symlink bypass when the target exists.
		$real_base_dir = realpath( $base_dir );
		if ( false === $real_base_dir ) {
			return false;
		}

		$real_path = realpath( $path );
		if ( false !== $real_path ) {
			return self::path_starts_with( $real_path, $real_base_dir );
		}

		$parent = dirname( $path );
		while ( ! file_exists( $parent ) && dirname( $parent ) !== $parent ) {
			$parent = dirname( $parent );
		}

		$real_parent = realpath( $parent );
		if ( false === $real_parent || ! self::path_starts_with( $real_parent, $real_base_dir, true ) ) {
			return false;
		}

		$normalized_path = self::normalize_path_for_compare( $path );
		$normalized_base = rtrim( self::normalize_path_for_compare( $real_base_dir ), '/' );

		return str_starts_with( $normalized_path, $normalized_base . '/' );
	}

	/**
	 * Check whether a path is inside a base directory.
	 *
	 * @param string $path Path to check.
	 * @param string $base Base directory.
	 * @param bool   $allow_equal Whether the base directory itself is allowed.
	 * @return bool True if path is inside base.
	 */
	private static function path_starts_with( string $path, string $base, bool $allow_equal = false ): bool {
		$path = self::normalize_path_for_compare( $path );
		$base = rtrim( self::normalize_path_for_compare( $base ), '/' );

		if ( $allow_equal && $path === $base ) {
			return true;
		}

		return $path !== $base && str_starts_with( $path, $base . '/' );
	}

	/**
	 * Normalize paths for safe cross-platform comparisons.
	 *
	 * @param string $path Path to normalize.
	 * @return string Normalized path.
	 */
	private static function normalize_path_for_compare( string $path ): string {
		$path = str_replace( '\\', '/', $path );
		$path = rtrim( $path, '/' );

		if ( 'Windows' === PHP_OS_FAMILY ) {
			$path = strtolower( $path );
		}

		return $path;
	}
}
