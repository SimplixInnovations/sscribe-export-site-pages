<?php
/**
 * SScribe private storage resolver.
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SScribe_Private_Storage {

	public const FILE_MODE   = 0600;
	public const DIR_MODE    = 0700;
	private const DIRECTORY_NAME = 'sscribe-export-site-pages';

	/**
	 * Return the canonical export directory and optionally create it.
	 *
	 * @param bool $create Create the directory when missing.
	 * @return string Absolute path, or an empty string when no safe path exists.
	 */
	public static function get_export_dir( bool $create = true ): string {
		$explicit = defined( 'SSCRIBE_PRIVATE_STORAGE_DIR' );
		$base     = $explicit
			? (string) SSCRIBE_PRIVATE_STORAGE_DIR
			: sys_get_temp_dir();
		$base     = rtrim( trim( $base ), '/\\' );
		if (
			'' === $base
			|| str_contains( $base, "\0" )
			|| ! self::is_absolute_path( $base )
			|| ! is_dir( $base )
			|| is_link( $base )
			|| ! wp_is_writable( $base )
			|| ! self::is_owned_by_current_process( $base )
		) {
			return '';
		}

		$canonical_base = realpath( $base );
		if ( false === $canonical_base || self::normalize_path( $base ) !== self::normalize_path( $canonical_base ) ) {
			return '';
		}

		$site_key = 'site-' . get_current_blog_id() . '-' . substr( hash( 'sha256', self::normalize_path( ABSPATH ) ), 0, 12 );
		$path     = $canonical_base . DIRECTORY_SEPARATOR . self::DIRECTORY_NAME . DIRECTORY_SEPARATOR . $site_key . DIRECTORY_SEPARATOR . 'sscribe-exports';
		if ( ! self::is_outside_public_roots( $path ) ) {
			return '';
		}

		if ( is_link( $path ) ) {
			return '';
		}
		if ( $create && ! is_dir( $path ) && ! wp_mkdir_p( $path ) ) {
			return '';
		}
		if ( self::path_exists( $path ) ) {
			$real = realpath( $path );
			if (
				false === $real
				|| ! is_dir( $path )
				|| is_link( $path )
				|| self::normalize_path( $path ) !== self::normalize_path( $real )
				|| ! self::is_outside_public_roots( $real )
				|| ( $create && ! wp_is_writable( $real ) )
			) {
				return '';
			}
		}
		if ( $create ) {
			self::harden_directory( $path );
			SScribe_Security::protect_directory( $path );
			self::harden_file( $path . '/.htaccess' );
			self::harden_file( $path . '/index.php' );
		}

		return $path;
	}

	/**
	 * Return a validated private subdirectory.
	 *
	 * @param string $relative Relative directory name.
	 * @param bool   $create   Create the directory when missing.
	 * @return string Absolute path, or an empty string on failure.
	 */
	public static function get_subdirectory( string $relative, bool $create = true ): string {
		$relative = trim( str_replace( '\\', '/', $relative ), '/' );
		if ( '' === $relative || 1 !== preg_match( '#^[a-z0-9][a-z0-9/_-]*$#D', $relative ) || str_contains( $relative, '..' ) ) {
			return '';
		}
		$root = self::get_export_dir( $create );
		if ( '' === $root ) {
			return '';
		}
		$path = $root . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relative );
		if ( $create && ! is_dir( $path ) && ! wp_mkdir_p( $path ) ) {
			return '';
		}
		if ( self::path_exists( $path ) ) {
			if ( ! is_dir( $path ) || is_link( $path ) || ! self::is_owned_path( $path ) ) {
				return '';
			}
		}
		if ( $create ) {
			self::harden_directory( $path );
		}

		return $path;
	}

	/**
	 * Return the former public upload directory.
	 */
	public static function get_legacy_export_dir(): string {
		$directories = self::get_legacy_storage_dirs();

		return $directories['exports'];
	}

	/**
	 * Return every public directory used by previous plugin releases.
	 *
	 * @return array{exports:string,logs:string,mpdf_temp:string}
	 */
	public static function get_legacy_storage_dirs(): array {
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			return array(
				'exports'   => '',
				'logs'      => '',
				'mpdf_temp' => '',
			);
		}
		$base = untrailingslashit( (string) $uploads['basedir'] );

		return array(
			'exports'   => $base . '/sscribe-exports',
			'logs'      => $base . '/sscribe-logs',
			'mpdf_temp' => $base . '/sscribe/mpdf-tmp',
		);
	}

	/**
	 * Move safe legacy artifacts to private storage and remove public remnants.
	 */
	public static function migrate_legacy_storage(): bool {
		$directories = self::get_legacy_storage_dirs();
		$destinations = array(
			'exports'   => '',
			'logs'      => 'logs',
			'mpdf_temp' => 'mpdf-tmp',
		);
		$migrated = true;
		foreach ( $destinations as $key => $relative ) {
			$legacy = $directories[ $key ];
			if ( '' === $legacy || ! self::path_exists( $legacy ) ) {
				continue;
			}
			if ( 'mpdf_temp' === $key && is_link( dirname( $legacy ) ) ) {
				wp_delete_file( dirname( $legacy ) );
				$migrated = ! self::path_exists( dirname( $legacy ) ) && $migrated;
				continue;
			}
			if ( is_link( $legacy ) ) {
				wp_delete_file( $legacy );
				$migrated = ! self::path_exists( $legacy ) && $migrated;
				continue;
			}
			$target = '' === $relative ? self::get_export_dir() : self::get_subdirectory( $relative );
			if ( '' === $target ) {
				$migrated = false;
				continue;
			}

			$current = self::move_directory_contents( $legacy, $target );
			self::remove_empty_directory( $legacy );
			$migrated = $current && ! self::path_exists( $legacy ) && $migrated;
		}
		self::remove_legacy_parent( $directories );

		return $migrated;
	}

	/**
	 * Remove this site's private storage tree.
	 */
	public static function delete_owned_storage(): bool {
		$root = self::get_export_dir( false );
		return '' === $root || ! file_exists( $root ) || SScribe_Security::delete_directory( $root );
	}

	/**
	 * Remove the former public storage tree.
	 */
	public static function delete_legacy_storage(): bool {
		$directories = self::get_legacy_storage_dirs();
		$deleted     = true;
		foreach ( $directories as $key => $legacy ) {
			if ( '' === $legacy || ! self::path_exists( $legacy ) ) {
				continue;
			}
			if ( 'mpdf_temp' === $key && is_link( dirname( $legacy ) ) ) {
				wp_delete_file( dirname( $legacy ) );
				$deleted = ! self::path_exists( dirname( $legacy ) ) && $deleted;
				continue;
			}
			if ( is_link( $legacy ) ) {
				wp_delete_file( $legacy );
				$deleted = ! self::path_exists( $legacy ) && $deleted;
				continue;
			}
			$deleted = SScribe_Security::delete_directory( $legacy ) && $deleted;
		}
		self::remove_legacy_parent( $directories );

		return $deleted;
	}

	/**
	 * Remove the former sscribe container after its last owned child is gone.
	 *
	 * @param array{exports:string,logs:string,mpdf_temp:string} $directories Legacy directories.
	 */
	private static function remove_legacy_parent( array $directories ): void {
		$mpdf_temp = $directories['mpdf_temp'];
		if ( '' !== $mpdf_temp ) {
			self::remove_empty_directory( dirname( $mpdf_temp ) );
		}
	}

	/**
	 * Remove a verified empty directory without following links.
	 *
	 * @param string $directory Directory path.
	 */
	private static function remove_empty_directory( string $directory ): void {
		if ( ! is_dir( $directory ) || is_link( $directory ) ) {
			return;
		}
		$entries = scandir( $directory );
		if ( is_array( $entries ) && 2 === count( $entries ) ) {
			rmdir( $directory ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Removes a verified empty legacy directory.
		}
	}

	/**
	 * Check whether a path resolves within this site's private tree.
	 *
	 * @param string $path Candidate path.
	 */
	public static function is_owned_path( string $path ): bool {
		$root = self::get_export_dir( false );
		return '' !== $root && self::path_is_within( $path, $root, true );
	}

	/**
	 * Apply owner-only file permissions.
	 *
	 * @param string $path File path.
	 */
	public static function harden_file( string $path ): void {
		if ( is_file( $path ) && ! is_link( $path ) ) {
			chmod( $path, self::FILE_MODE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Private artifact permission boundary.
		}
	}

	/**
	 * Apply owner-only directory permissions.
	 *
	 * @param string $path Directory path.
	 */
	public static function harden_directory( string $path ): void {
		if ( is_dir( $path ) && ! is_link( $path ) ) {
			chmod( $path, self::DIR_MODE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Private directory permission boundary.
		}
	}

	/**
	 * Reject the system temp default when another user owns the directory.
	 *
	 * Shared hosts expose `/tmp` (or the OS equivalent) to every PHP
	 * process under the same path. A malicious co-tenant could plant a
	 * symlink at the canonical SScribe subdirectory before the plugin
	 * ever ran, redirecting exports, logs, and image staging to an
	 * attacker-controlled target. When the operator has not explicitly
	 * pinned `SSCRIBE_PRIVATE_STORAGE_DIR` we require the base directory
	 * to be owned by the current PHP process so the first `mkdir` cannot
	 * follow a foreign-owned symlink.
	 *
	 * @param string $base Base directory to inspect.
	 * @return bool True when posix/fileowner are unavailable on the
	 *              platform, or when the resolved owner equals the
	 *              current process UID. False when fileowner() fails
	 *              on the path or the resolved owner differs.
	 */
	private static function is_owned_by_current_process( string $base ): bool {
		if ( ! function_exists( 'posix_geteuid' ) || ! function_exists( 'fileowner' ) ) {
			return true;
		}
		$owner = @fileowner( $base );
		if ( false === $owner ) {
			return false;
		}
		return (int) posix_geteuid() === (int) $owner;
	}

	/**
	 * Check that a path is outside every web-served WordPress root.
	 *
	 * @param string $path Candidate path.
	 * @return bool True when the path is private.
	 */
	private static function is_outside_public_roots( string $path ): bool {
		$document_root = isset( $_SERVER['DOCUMENT_ROOT'] )
			? sanitize_text_field( wp_unslash( $_SERVER['DOCUMENT_ROOT'] ) )
			: '';
		$roots = array( ABSPATH, WP_CONTENT_DIR, $document_root );
		$uploads = wp_upload_dir();
		if ( empty( $uploads['error'] ) && ! empty( $uploads['basedir'] ) ) {
			$roots[] = (string) $uploads['basedir'];
		}
		foreach ( array_filter( $roots ) as $root ) {
			if ( self::path_is_within( $path, $root, true ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Compare canonical paths at directory boundaries.
	 *
	 * @param string $path        Candidate path.
	 * @param string $root        Allowed root.
	 * @param bool   $allow_equal Whether the root itself is allowed.
	 * @return bool True when contained.
	 */
	private static function path_is_within( string $path, string $root, bool $allow_equal ): bool {
		$path = self::normalize_path( (string) ( realpath( $path ) ?: $path ) );
		$root = rtrim( self::normalize_path( (string) ( realpath( $root ) ?: $root ) ), '/' );
		return ( $allow_equal && $path === $root ) || str_starts_with( $path, $root . '/' );
	}

	/**
	 * Normalize a path for platform-safe comparisons.
	 *
	 * @param string $path Path to normalize.
	 * @return string Normalized path.
	 */
	private static function normalize_path( string $path ): string {
		$path = function_exists( 'wp_normalize_path' ) ? wp_normalize_path( $path ) : str_replace( '\\', '/', $path );
		$path = rtrim( $path, '/' );
		return 'Windows' === PHP_OS_FAMILY ? strtolower( $path ) : $path;
	}

	/**
	 * Determine whether a path is absolute on Unix or Windows.
	 *
	 * @param string $path Path to inspect.
	 * @return bool True for an absolute path.
	 */
	private static function is_absolute_path( string $path ): bool {
		if ( '' === $path ) {
			return false;
		}
		return function_exists( 'path_is_absolute' )
			? path_is_absolute( $path )
			: ( '/' === $path[0] || '\\' === $path[0] || 1 === preg_match( '/^[a-zA-Z]:[\\\\\/]/D', $path ) );
	}

	/**
	 * Recursively migrate safe directory entries without following links.
	 *
	 * @param string $source Legacy source directory.
	 * @param string $target Private destination directory.
	 * @return bool True when every entry migrated.
	 */
	private static function move_directory_contents( string $source, string $target ): bool {
		$entries = scandir( $source );
		if ( false === $entries ) {
			return false;
		}
		$migrated   = true;
		$deny_files = array();
		foreach ( array_diff( $entries, array( '.', '..' ) ) as $name ) {
			$from = $source . DIRECTORY_SEPARATOR . $name;
			$to   = $target . DIRECTORY_SEPARATOR . $name;
			if ( in_array( $name, array( '.htaccess', 'index.php' ), true ) && is_file( $from ) && ! is_link( $from ) ) {
				$deny_files[] = $from;
			} elseif ( is_link( $from ) ) {
				wp_delete_file( $from );
				$migrated = $migrated && ! self::path_exists( $from );
			} elseif ( is_dir( $from ) ) {
				if ( ! is_dir( $to ) && ! wp_mkdir_p( $to ) ) {
					$migrated = false;
					continue;
				}
				if ( is_link( $to ) || ! self::is_owned_path( $to ) ) {
					$migrated = false;
					continue;
				}
				if ( is_dir( $to ) ) {
					self::harden_directory( $to );
				}
				$migrated = self::move_directory_contents( $from, $to ) && $migrated;
				$remaining = scandir( $from );
				if ( is_array( $remaining ) && 2 === count( $remaining ) ) {
					rmdir( $from ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Removes an empty legacy directory after verified migration.
				}
			} elseif ( is_file( $from ) ) {
				if ( file_exists( $to ) && ! self::files_match( $from, $to ) ) {
					$to = self::collision_destination( $from, $target, $name );
				}
				$migrated = self::migrate_file( $from, $to ) && $migrated;
			} else {
				$migrated = false;
			}
		}

		foreach ( $deny_files as $deny_file ) {
			chmod( $deny_file, self::FILE_MODE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Makes legacy deny files removable on Windows.
			wp_delete_file( $deny_file );
			$migrated = $migrated && ! file_exists( $deny_file );
		}

		return $migrated;
	}

	/**
	 * Build a deterministic non-overwriting destination for a collision.
	 *
	 * @param string $source Legacy source file.
	 * @param string $target Private destination directory.
	 * @param string $name   Original filename.
	 * @return string Collision-safe destination.
	 */
	private static function collision_destination( string $source, string $target, string $name ): string {
		$extension = pathinfo( $name, PATHINFO_EXTENSION );
		$stem      = pathinfo( $name, PATHINFO_FILENAME );
		$hash      = hash_file( 'sha256', $source );
		$suffix    = '-legacy-' . substr( is_string( $hash ) ? $hash : hash( 'sha256', $name ), 0, 10 );
		$filename  = $stem . $suffix . ( '' !== $extension ? '.' . $extension : '' );

		return $target . DIRECTORY_SEPARATOR . $filename;
	}

	/**
	 * Copy, verify, atomically publish, and then remove one legacy file.
	 *
	 * @param string $source      Legacy source file.
	 * @param string $destination Private destination file.
	 * @return bool True when the verified destination exists and source is gone.
	 */
	private static function migrate_file( string $source, string $destination ): bool {
		if ( file_exists( $destination ) ) {
			if ( ! is_file( $destination ) || ! self::files_match( $source, $destination ) ) {
				return false;
			}
			chmod( $source, 0600 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Makes a verified legacy source removable on Windows.
			wp_delete_file( $source );
			return ! file_exists( $source );
		}

		$temporary = tempnam( dirname( $destination ), '.sscribe-migrate-' );
		if ( false === $temporary ) {
			return false;
		}
		$copied = copy( $source, $temporary ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy -- Cross-filesystem migration requires a verified copy.
		if ( ! $copied || ! self::files_match( $source, $temporary ) ) {
			wp_delete_file( $temporary );
			return false;
		}
		if ( ! rename( $temporary, $destination ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Atomic publish inside the private storage directory.
			wp_delete_file( $temporary );
			return false;
		}
		self::harden_file( $destination );
		if ( ! self::files_match( $source, $destination ) ) {
			return false;
		}
		chmod( $source, 0600 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Makes a verified legacy source removable on Windows.
		wp_delete_file( $source );

		return ! file_exists( $source );
	}

	/**
	 * Compare file size and SHA-256 digest.
	 *
	 * @param string $source      Source file.
	 * @param string $destination Destination file.
	 * @return bool True when bytes match.
	 */
	private static function files_match( string $source, string $destination ): bool {
		if ( ! is_file( $source ) || ! is_file( $destination ) || filesize( $source ) !== filesize( $destination ) ) {
			return false;
		}
		$source_hash      = hash_file( 'sha256', $source );
		$destination_hash = hash_file( 'sha256', $destination );

		return is_string( $source_hash )
			&& is_string( $destination_hash )
			&& hash_equals( $source_hash, $destination_hash );
	}

	/**
	 * Check a path after clearing filesystem metadata caches.
	 *
	 * @param string $path Path to inspect.
	 * @return bool True for files, directories, or symbolic links.
	 * @phpstan-impure
	 */
	private static function path_exists( string $path ): bool {
		clearstatcache( true, $path );
		return file_exists( $path ) || is_link( $path );
	}
}
