<?php
/**
 * SScribe Security Handler
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
 * Handles security operations for SScribe export files.
 */
class SScribe_Security {

	/**
	 * Protect a directory with .htaccess and index.php files.
	 *
	 * @param string $dir Directory path to protect.
	 * @throws \InvalidArgumentException|\RuntimeException When validation or directory creation fails.
	 */
	public static function protect_directory( string $dir ): void {
		self::validate_path_scope( $dir );

		if ( ! is_dir( $dir ) ) {
			if ( ! wp_mkdir_p( $dir ) ) {
				throw new \RuntimeException( 'Unable to create the protected SScribe directory.' );
			}
		}

		$htaccess_path = $dir . '/.htaccess';
		$content       = "Options -Indexes\n";
		$content      .= "<Files \"*\">\n";
		$content      .= "  <IfModule mod_authz_core.c>\n";
		$content      .= "    Require all denied\n";
		$content      .= "  </IfModule>\n";
		$content      .= "  <IfModule !mod_authz_core.c>\n";
		$content      .= "    Order Allow,Deny\n";
		$content      .= "    Deny from all\n";
		$content      .= "  </IfModule>\n";
		$content      .= "</Files>\n";

		$existing_htaccess = is_file( $htaccess_path ) && ! is_link( $htaccess_path )
			? file_get_contents( $htaccess_path )
			: false;
		if ( ! is_string( $existing_htaccess ) || ! hash_equals( $content, $existing_htaccess ) ) {
			self::write_file( $htaccess_path, $content );
		}

		$index_path = $dir . '/index.php';
		if ( ! file_exists( $index_path ) ) {
			self::write_file( $index_path, "<?php\n// Silence is golden.\n", 0444 );
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
		if ( ! is_dir( $dir ) || is_link( $dir ) ) {
			return false;
		}

		if ( $depth >= $max_depth ) {
			return false;
		}

		if ( ! self::is_path_in_scope( $dir ) ) {
			return false;
		}

		$scanned = scandir( $dir );
		if ( false === $scanned ) {
			return false;
		}
		$files = array_diff( $scanned, array( '.', '..' ) );
		foreach ( $files as $file ) {
			$path = $dir . '/' . $file;

			if ( is_link( $path ) ) {
				// Unlink the directory entry itself. Resolving the target first
				// could delete a file outside the plugin-owned tree.
				wp_delete_file( $path );
			} elseif ( is_dir( $path ) ) {

				self::delete_directory( $path, $max_depth, $depth + 1 );
			} else {
				chmod( $path, 0600 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Allows owner cleanup of read-only guard files on Windows.
				wp_delete_file( $path );
			}
		}

		return self::remove_directory( $dir );
	}

	/**
	 * Remove an empty directory using WP Filesystem API.
	 *
	 * @param string $dir Directory path to remove.
	 * @return bool True if removed, false otherwise.
	 */
	private static function remove_directory( string $dir ): bool {
		global $wp_filesystem;

		if ( empty( $wp_filesystem ) && ! self::initialize_wp_filesystem() ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Direct fallback when the WordPress filesystem API is unavailable.
			return @rmdir( $dir );
		}

		return $wp_filesystem->rmdir( $dir );
	}

	/**
	 * Write content to a file using WP Filesystem API with fallback.
	 *
	 * @param string   $file_path File path to write to.
	 * @param string   $content   Content to write.
	 * @param int|null $chmod     Optional chmod mode.
	 * @return bool True on success, false on failure.
	 */
	private static function write_file( string $file_path, string $content, ?int $chmod = null ): bool {
		global $wp_filesystem;

		$chmod = null === $chmod ? ( defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : 0644 ) : $chmod;

		if ( empty( $wp_filesystem ) && ! self::initialize_wp_filesystem() ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Direct fallback when the WordPress filesystem API is unavailable.
			if ( false === file_put_contents( $file_path, $content, LOCK_EX ) ) {
				return false;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Apply the same permission contract as WP_Filesystem::put_contents().
			chmod( $file_path, $chmod );
			return true;
		}

		return $wp_filesystem->put_contents( $file_path, $content, $chmod );
	}

	/**
	 * Initialize the WordPress Filesystem API when its bootstrap is available.
	 *
	 * WordPress normally provides wp-admin/includes/file.php. Test harnesses,
	 * recovery contexts, and unusually stripped installations may not. In that
	 * case callers deliberately use their bounded direct-operation fallback
	 * rather than fatalling while trying to load a file that does not exist.
	 *
	 * @return bool True when a usable global filesystem object is available.
	 */
	private static function initialize_wp_filesystem(): bool {
		global $wp_filesystem;

		if ( ! empty( $wp_filesystem ) ) {
			return true;
		}

		if ( ! function_exists( 'WP_Filesystem' ) ) {
			$filesystem_bootstrap = ABSPATH . 'wp-admin/includes/file.php';
			if ( is_file( $filesystem_bootstrap ) ) {
				require_once $filesystem_bootstrap;
			}
		}

		if ( ! function_exists( 'WP_Filesystem' ) || ! WP_Filesystem() ) {
			return false;
		}

		return ! empty( $wp_filesystem );
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
					'Directory "%s" is outside the plugin-owned storage scope.',
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages escape at the rendering site, not here.
					basename( $path )
				)
			);
		}
	}

	/**
	 * Check if a path is within private or legacy plugin-owned storage.
	 *
	 * @param string $path Path to check.
	 * @return bool True if path is in scope.
	 */
	private static function is_path_in_scope( string $path ): bool {
		if ( '' === trim( $path ) ) {
			return false;
		}

		// Fail closed against parent-directory traversal. A `..` segment
		// between path separators can defeat the literal-prefix comparison
		// below because `normalize_path_for_compare()` does not collapse
		// segments. SScribe-internal callers never construct such paths.
		if ( preg_match( '#(?:^|[/\\\\])\.\.(?:[/\\\\]|$)#', $path ) ) {
			return false;
		}

		$upload_dir = wp_upload_dir();
		$base_dirs  = array();
		if ( empty( $upload_dir['error'] ) && ! empty( $upload_dir['basedir'] ) ) {
			$uploads_base = trailingslashit( (string) $upload_dir['basedir'] );
			$base_dirs[] = $uploads_base . 'sscribe-exports';
			$base_dirs[] = $uploads_base . 'sscribe-logs';
			if ( ! is_link( $uploads_base . 'sscribe' ) ) {
				$base_dirs[] = $uploads_base . 'sscribe/mpdf-tmp';
			}
		}
		if ( class_exists( 'SScribe_Private_Storage' ) ) {
			$private_dir = SScribe_Private_Storage::get_export_dir( false );
			if ( '' !== $private_dir ) {
				$base_dirs[] = $private_dir;
			}
		}

		$resolved_bases = array();
		foreach ( $base_dirs as $base_dir ) {
			$real_base_dir = realpath( $base_dir );
			if ( false !== $real_base_dir ) {
				$resolved_bases[] = $real_base_dir;
			}
		}

		// Existing paths, including symlinks, are judged solely by their
		// resolved target. Never fall back to literal-parent containment after
		// realpath() has proved that an existing symlink points elsewhere.
		$real_path = realpath( $path );
		if ( false !== $real_path ) {
			foreach ( $resolved_bases as $real_base_dir ) {
				if ( self::path_starts_with( $real_path, $real_base_dir, true ) ) {
					return true;
				}
			}
			return false;
		}

		// A dangling symlink cannot be safely protected: its future target may
		// appear outside plugin-owned storage after this validation completes.
		if ( is_link( $path ) ) {
			return false;
		}

		// For a path that genuinely does not exist yet, resolve the nearest
		// existing ancestor (stopping at any intermediate symlink) and require
		// both the ancestor and the canonical literal path to remain in scope.
		$parent = dirname( $path );
		while (
			! file_exists( $parent )
			&& ! is_link( $parent )
			&& dirname( $parent ) !== $parent
		) {
			$parent = dirname( $parent );
		}
		$real_parent = realpath( $parent );
		if ( false === $real_parent ) {
			return false;
		}

		$canonical = self::canonicalize_path( $path );
		if ( '' === $canonical ) {
			return false;
		}

		foreach ( $resolved_bases as $real_base_dir ) {
			if ( ! self::path_starts_with( $real_parent, $real_base_dir, true ) ) {
				continue;
			}
			$canonical_base = rtrim( self::canonicalize_path( $real_base_dir ), '/' );
			if ( $canonical === $canonical_base || str_starts_with( $canonical, $canonical_base . '/' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Fully resolve `..` and `.` segments in a path without touching the
	 * filesystem. Unlike `realpath()` the result is defined for paths that
	 * do not yet exist. Used by `is_path_in_scope()` so a `..` traversal
	 * segment cannot bypass the literal-prefix containment check.
	 *
	 * @param string $path Path to canonicalize.
	 * @return string Canonical path, or empty string when the path escapes
	 *                above its own root (e.g. `../../../etc/passwd`).
	 */
	private static function canonicalize_path( string $path ): string {
		if ( '' === $path ) {
			return '';
		}
		$is_windows = ( 'Windows' === PHP_OS_FAMILY );
		$normalized = str_replace( '\\', '/', $path );
		if ( $is_windows ) {
			$normalized = strtolower( $normalized );
		}
		$prefix    = '';
		$drive_letter = '';
		if ( $is_windows && preg_match( '#^([a-z]):(/.*)$#', $normalized, $m ) ) {
			$drive_letter = $m[1] . ':';
			$normalized   = $m[2];
		} elseif ( 0 === strpos( $normalized, '//' ) || 0 === strpos( $normalized, '\\\\' ) ) {
			$prefix = '//';
			$normalized = substr( $normalized, 2 );
		} elseif ( 0 === strpos( $normalized, '/' ) ) {
			$prefix = '/';
			$normalized = substr( $normalized, 1 );
		}
		$segments = explode( '/', $normalized );
		$stack    = array();
		foreach ( $segments as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}
			if ( '..' === $segment ) {
				if ( empty( $stack ) ) {
					return '';
				}
				array_pop( $stack );
				continue;
			}
			$stack[] = $segment;
		}
		return $prefix . $drive_letter . ( '' === $prefix ? '' : '/' ) . implode( '/', $stack );
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
