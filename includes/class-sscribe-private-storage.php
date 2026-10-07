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
	public const DIRECTORY_NAME          = 'sscribe-export-site-pages';
	public const DEFAULT_DIRECTORY_NAME  = 'sscribe-exports';
	public const STORAGE_KEY_OPTION      = 'sscribe_storage_key';

	/**
	 * Return the export leaf directory name, honoring the `sscribe_storage_layout` filter.
	 *
	 * The canonical name is `sscribe-exports`. Operators may override it via the
	 * filter when the default collides with another tool, contains reserved
	 * characters, or needs to match a corporate-naming convention. Any value that
	 * is not a safe single-segment filename (path separators, NUL, `..` traversal,
	 * non-`[a-z0-9._-]` characters, leading dot, length > 60) is silently replaced
	 * with the default to prevent the resolver from constructing unsafe paths.
	 *
	 * The legacy paths reported by `get_legacy_storage_dirs()` are NOT affected:
	 * historical locations shipped by prior plugin releases are fixed regardless
	 * of operator preference, so an upgrade that renamed the live directory can
	 * still locate and migrate any leftover public artifacts.
	 *
	 * @return string Single-segment directory name.
	 */
	public static function get_directory_name(): string {
		$candidate = apply_filters( 'sscribe_storage_layout', self::DEFAULT_DIRECTORY_NAME );
		if ( ! is_string( $candidate ) ) {
			return self::DEFAULT_DIRECTORY_NAME;
		}
		$candidate = strtolower( trim( $candidate ) );
		if ( '' === $candidate ) {
			return self::DEFAULT_DIRECTORY_NAME;
		}
		if ( '.' === $candidate || '..' === $candidate ) {
			return self::DEFAULT_DIRECTORY_NAME;
		}
		if (
			str_contains( $candidate, "\0" )
			|| str_contains( $candidate, '/' )
			|| str_contains( $candidate, '\\' )
			|| str_contains( $candidate, '..' )
			|| 1 !== preg_match( '#^[a-z0-9._-]{1,60}$#D', $candidate )
		) {
			return self::DEFAULT_DIRECTORY_NAME;
		}
		if ( '.' === $candidate[0] ) {
			return self::DEFAULT_DIRECTORY_NAME;
		}
		return $candidate;
	}

	/**
	 * Return the canonical export directory and optionally create it.
	 *
	 * The default base is the site's uploads directory. An operator override
	 * (SSCRIBE_PRIVATE_STORAGE_DIR or the sscribe_private_storage_base_candidates
	 * filter) replaces it entirely and fails closed when unusable.
	 *
	 * @param bool $create Create the directory when missing.
	 * @return string Absolute path, or an empty string when no safe path exists.
	 */
	public static function get_export_dir( bool $create = true ): string {
		static $resolved_paths = array();

		$site_key = self::get_site_key();
		if ( '' === $site_key ) {
			return '';
		}

		$bases     = self::resolve_bases();
		$cache_key = ( $create ? 'create' : 'read' )
			. '|' . get_current_blog_id()
			. '|' . $site_key
			. '|' . self::get_directory_name()
			. '|' . md5( (string) wp_json_encode( $bases ) );

		if ( array_key_exists( $cache_key, $resolved_paths ) ) {
			list( $cached_path, $cached_base ) = $resolved_paths[ $cache_key ];
			if ( in_array( $cached_base, $bases, true ) && self::claim_storage_path( $cached_path, $cached_base, $create ) ) {
				return $cached_path;
			}
			unset( $resolved_paths[ $cache_key ] );
		}

		foreach ( $bases as $canonical_base ) {
			$path = $canonical_base . DIRECTORY_SEPARATOR . self::DIRECTORY_NAME . DIRECTORY_SEPARATOR . $site_key . DIRECTORY_SEPARATOR . self::get_directory_name();
			if ( ! self::claim_storage_path( $path, $canonical_base, $create ) ) {
				continue;
			}
			$resolved_paths[ $cache_key ] = array( $path, $canonical_base );
			return $path;
		}

		return '';
	}

	/**
	 * Validate a storage path below a canonical base and add guard files.
	 *
	 * @param string $path           Final managed path.
	 * @param string $canonical_base Validated canonical base.
	 * @param bool   $create         Create missing directories and guard files.
	 * @return bool True when the path is safe to use.
	 */
	private static function claim_storage_path( string $path, string $canonical_base, bool $create ): bool {
		if (
			! self::path_is_within( $path, $canonical_base, false )
			|| ! self::prepare_managed_path( $path, $canonical_base, $create )
		) {
			return false;
		}

		if ( self::path_exists( $path ) ) {
			$real = realpath( $path );
			if (
				false === $real
				|| ! is_dir( $path )
				|| is_link( $path )
				|| self::normalize_path( $real ) !== self::normalize_path( $path )
				|| ! self::path_is_within( $real, $canonical_base, false )
				|| ( $create && ! wp_is_writable( $real ) )
			) {
				return false;
			}
		}

		if ( $create ) {
			if ( ! self::is_override_active() ) {
				self::guard_directory( $canonical_base . DIRECTORY_SEPARATOR . self::DIRECTORY_NAME );
			}
			self::guard_directory( $path );
		}

		return true;
	}

	/**
	 * Write or restore the deny files for one managed directory.
	 *
	 * @param string $directory Managed directory.
	 */
	private static function guard_directory( string $directory ): void {
		$complete = true;
		foreach ( SScribe_Security::GUARD_FILES as $guard_name ) {
			if ( ! is_file( $directory . '/' . $guard_name ) || is_link( $directory . '/' . $guard_name ) ) {
				$complete = false;
				break;
			}
		}
		if ( $complete ) {
			return;
		}
		SScribe_Security::protect_directory( $directory );
		foreach ( SScribe_Security::GUARD_FILES as $guard_name ) {
			self::harden_file( $directory . '/' . $guard_name );
		}
	}

	/**
	 * Return this site's random storage key, creating it on first use.
	 *
	 * The key is stored per site (options are per blog on multisite) and is
	 * the only per-site segment of the storage path, so the location cannot
	 * be derived from public information.
	 *
	 * @param bool $create Generate and store a key when none exists.
	 * @return string 32-character lowercase alphanumeric key, or an empty string.
	 */
	public static function get_site_key( bool $create = true ): string {
		$stored = get_option( self::STORAGE_KEY_OPTION, '' );
		if ( self::is_valid_site_key( $stored ) ) {
			return (string) $stored;
		}
		if ( ! $create ) {
			return '';
		}

		$key = function_exists( 'wp_generate_password' )
			? strtolower( wp_generate_password( 32, false, false ) )
			: bin2hex( random_bytes( 16 ) );
		if ( ! self::is_valid_site_key( $key ) ) {
			return '';
		}

		if ( add_option( self::STORAGE_KEY_OPTION, $key, '', false ) ) {
			return $key;
		}

		$current = get_option( self::STORAGE_KEY_OPTION, '' );
		if ( self::is_valid_site_key( $current ) ) {
			return (string) $current;
		}

		return update_option( self::STORAGE_KEY_OPTION, $key, false ) ? $key : '';
	}

	/**
	 * Check the shape of a stored site key.
	 *
	 * @param mixed $key Candidate key.
	 * @return bool True for a 32-character lowercase alphanumeric string.
	 */
	private static function is_valid_site_key( mixed $key ): bool {
		return is_string( $key ) && 1 === preg_match( '/^[a-z0-9]{32}$/D', $key );
	}

	/**
	 * Report whether an operator override replaces the uploads default.
	 *
	 * @return bool True when SSCRIBE_PRIVATE_STORAGE_DIR or the base filter is in use.
	 */
	public static function is_override_active(): bool {
		return defined( 'SSCRIBE_PRIVATE_STORAGE_DIR' ) || array() !== self::get_override_bases();
	}

	/**
	 * Return the storage mode for diagnostics.
	 *
	 * @return string Either 'override' or 'uploads'.
	 */
	public static function get_storage_mode(): string {
		return self::is_override_active() ? 'override' : 'uploads';
	}

	/**
	 * Return the operator-supplied base directories, in priority order.
	 *
	 * @return string[]
	 */
	public static function get_override_bases(): array {
		if ( defined( 'SSCRIBE_PRIVATE_STORAGE_DIR' ) ) {
			return array( (string) SSCRIBE_PRIVATE_STORAGE_DIR );
		}

		$filtered = apply_filters( 'sscribe_private_storage_base_candidates', array() );
		if ( ! is_array( $filtered ) ) {
			return array();
		}

		$unique = array();
		foreach ( $filtered as $candidate ) {
			if ( ! is_string( $candidate ) ) {
				continue;
			}
			$candidate = rtrim( trim( $candidate ), '/\\' );
			if ( '' === $candidate || in_array( $candidate, $unique, true ) ) {
				continue;
			}
			$unique[] = $candidate;
		}

		return $unique;
	}

	/**
	 * Return the validated canonical bases for the active storage mode.
	 *
	 * @return string[]
	 */
	private static function resolve_bases(): array {
		if ( ! self::is_override_active() ) {
			$uploads_base = self::get_uploads_base();
			return '' === $uploads_base ? array() : array( $uploads_base );
		}

		$bases = array();
		foreach ( self::get_override_bases() as $candidate ) {
			$canonical = self::validate_base_candidate( $candidate );
			if ( '' !== $canonical && ! in_array( $canonical, $bases, true ) ) {
				$bases[] = $canonical;
			}
		}

		return $bases;
	}

	/**
	 * Return the canonical uploads base directory when it is usable.
	 *
	 * @return string Canonical absolute path, or an empty string.
	 */
	private static function get_uploads_base(): string {
		$basedir = self::get_uploads_basedir();
		if (
			'' === $basedir
			|| str_contains( $basedir, "\0" )
			|| ! is_dir( $basedir )
		) {
			return '';
		}

		$canonical = realpath( $basedir );
		if ( false === $canonical || ! is_dir( $canonical ) || ! wp_is_writable( $canonical ) ) {
			return '';
		}

		clearstatcache( true, $canonical );
		if ( 'Windows' !== PHP_OS_FAMILY && function_exists( 'fileperms' ) ) {
			$permissions = @fileperms( $canonical );
			if ( false !== $permissions && ( ( (int) $permissions & 0002 ) && ! ( (int) $permissions & 01000 ) ) ) {
				return '';
			}
		}

		return rtrim( $canonical, '/\\' );
	}

	/**
	 * Return the configured uploads base directory without validation.
	 *
	 * @return string Uploads base directory, or an empty string.
	 */
	public static function get_uploads_basedir(): string {
		if ( ! function_exists( 'wp_upload_dir' ) ) {
			return '';
		}
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			return '';
		}

		return rtrim( trim( (string) $uploads['basedir'] ), '/\\' );
	}

	/**
	 * Return the location an administrator should inspect when storage fails.
	 *
	 * @return string Empty when storage is available, otherwise the parent location.
	 */
	public static function get_unavailable_location(): string {
		if ( '' !== self::get_export_dir() ) {
			return '';
		}
		if ( self::is_override_active() ) {
			$overrides = self::get_override_bases();
			$first     = $overrides[0] ?? '';
			return '' !== $first ? $first : 'SSCRIBE_PRIVATE_STORAGE_DIR';
		}
		$basedir = self::get_uploads_basedir();

		return '' !== $basedir ? $basedir : __( 'the uploads directory', 'sscribe-export-site-pages' );
	}

	/**
	 * Validate and optionally create every SScribe-managed path component.
	 *
	 * The comparison is intentionally lexical from the already-canonical base.
	 * Calling realpath() on the full target first would hide an intermediate
	 * symlink whose destination remains inside the base. Existing components
	 * are therefore inspected one by one before any child is created.
	 *
	 * @param string $path           Final managed path.
	 * @param string $canonical_base Validated canonical base.
	 * @param bool   $create         Create missing managed directories.
	 * @return bool True when every component is a real directory and the path
	 *              can be used safely.
	 */
	private static function prepare_managed_path( string $path, string $canonical_base, bool $create ): bool {
		$base_normalized = rtrim( self::normalize_path( $canonical_base ), '/' );
		$path_normalized = self::normalize_path( $path );
		if (
			'' === $base_normalized
			|| '' === $path_normalized
			|| ! str_starts_with( $path_normalized, $base_normalized . '/' )
		) {
			return false;
		}

		$relative = substr( $path_normalized, strlen( $base_normalized ) + 1 );
		$segments = explode( '/', $relative );
		$cursor   = rtrim( $canonical_base, '/\\' );

		foreach ( $segments as $segment ) {
			if ( '' === $segment || '.' === $segment || '..' === $segment ) {
				return false;
			}

			$cursor .= DIRECTORY_SEPARATOR . $segment;
			clearstatcache( true, $cursor );

			// is_link() must be checked before file_exists(): dangling links return
			// false from file_exists() and must never be treated as creatable paths.
			if ( is_link( $cursor ) ) {
				return false;
			}

			if ( ! file_exists( $cursor ) ) {
				if ( ! $create ) {
					continue;
				}
				if ( ! wp_mkdir_p( $cursor ) ) {
					return false;
				}
				clearstatcache( true, $cursor );
			}

			if ( ! is_dir( $cursor ) || is_link( $cursor ) ) {
				return false;
			}

			$real = realpath( $cursor );
			if (
				false === $real
				|| ! self::path_is_within( $real, $canonical_base, false )
			) {
				return false;
			}

			if ( $create ) {
				if ( ! wp_is_writable( $real ) ) {
					return false;
				}
				self::harden_directory( $real );
				clearstatcache( true, $real );

				// On POSIX, a managed directory must not remain group/other
				// writable after hardening. Unlike the candidate base, managed
				// descendants never need shared-sticky semantics.
				if ( 'Windows' !== PHP_OS_FAMILY && function_exists( 'fileperms' ) ) {
					$permissions = @fileperms( $real );
					if ( false === $permissions || ( (int) $permissions & 0022 ) ) {
						return false;
					}
				}
			}
		}

		return true;
	}

	/**
	 * Validate and canonicalize one private-storage base candidate.
	 *
	 * @param string $base Candidate base directory.
	 * @return string Canonical absolute base or an empty string when unsafe.
	 */
	private static function validate_base_candidate( string $base ): string {
		$base = rtrim( trim( $base ), '/\\' );
		$normalized_segments = preg_split( '#[\\\\/]+#', $base );
		if (
			'' === $base
			|| str_contains( $base, "\0" )
			|| ! self::is_absolute_path( $base )
			|| ! is_array( $normalized_segments )
			|| in_array( '.', $normalized_segments, true )
			|| in_array( '..', $normalized_segments, true )
			|| dirname( $base ) === $base
			|| ! is_dir( $base )
			|| is_link( $base )
			|| ! wp_is_writable( $base )
		) {
			return '';
		}

		$canonical_base = realpath( $base );
		if (
			false === $canonical_base
			|| ! is_dir( $canonical_base )
			|| ! wp_is_writable( $canonical_base )
			|| ! self::is_owned_by_current_process( $canonical_base )
		) {
			return '';
		}

		return rtrim( $canonical_base, '/\\' );
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
		if ( ! self::prepare_managed_path( $path, $root, $create ) ) {
			return '';
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
		if ( '' === $root ) {
			return true;
		}
		$deleted = ! file_exists( $root ) || SScribe_Security::delete_directory( $root );
		if ( $deleted ) {
			$site_dir = dirname( $root );
			self::remove_guarded_directory( $site_dir );
			if ( self::DIRECTORY_NAME === basename( dirname( $site_dir ) ) ) {
				self::remove_guarded_directory( dirname( $site_dir ) );
			}
		}

		return $deleted;
	}

	/**
	 * Remove a directory that holds nothing except SScribe guard files.
	 *
	 * @param string $directory Directory path.
	 */
	public static function remove_guarded_directory( string $directory ): void {
		if ( ! is_dir( $directory ) || is_link( $directory ) ) {
			return;
		}
		$entries = scandir( $directory );
		if ( ! is_array( $entries ) ) {
			return;
		}
		$entries = array_values( array_diff( $entries, array( '.', '..' ) ) );
		if ( array() !== array_diff( $entries, SScribe_Security::GUARD_FILES ) ) {
			return;
		}
		foreach ( $entries as $guard_name ) {
			$guard_path = $directory . DIRECTORY_SEPARATOR . $guard_name;
			if ( is_file( $guard_path ) && ! is_link( $guard_path ) ) {
				chmod( $guard_path, self::FILE_MODE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Makes a plugin-owned guard file removable on Windows.
			}
			wp_delete_file( $guard_path );
		}
		self::remove_empty_directory( $directory );
	}

	/**
	 * Move every entry of a previous storage directory into the current one.
	 *
	 * Links are removed rather than followed, collisions keep both copies,
	 * and each file is copied, verified, and only then removed from the source.
	 *
	 * @param string $source Previous storage directory.
	 * @param string $target Current private storage directory.
	 * @return bool True when every entry moved.
	 */
	public static function move_into_storage( string $source, string $target ): bool {
		if ( '' === $target || ! self::is_owned_path( $target ) ) {
			return false;
		}

		return self::move_directory_contents( $source, $target );
	}

	/**
	 * Remove a previous storage tree without following links.
	 *
	 * @param string $directory Directory to remove.
	 * @param int    $depth     Current recursion depth.
	 * @return bool True when the directory no longer exists.
	 */
	public static function remove_previous_tree( string $directory, int $depth = 0 ): bool {
		if ( is_link( $directory ) ) {
			wp_delete_file( $directory );
			return ! self::path_exists( $directory );
		}
		if ( ! is_dir( $directory ) ) {
			return ! self::path_exists( $directory );
		}
		if ( $depth > 20 ) {
			return false;
		}
		$entries = scandir( $directory );
		if ( false === $entries ) {
			return false;
		}
		foreach ( array_diff( $entries, array( '.', '..' ) ) as $name ) {
			$entry = $directory . DIRECTORY_SEPARATOR . $name;
			if ( is_dir( $entry ) && ! is_link( $entry ) ) {
				self::remove_previous_tree( $entry, $depth + 1 );
				continue;
			}
			if ( is_file( $entry ) && ! is_link( $entry ) ) {
				chmod( $entry, self::FILE_MODE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Makes a plugin-owned file removable on Windows.
			}
			wp_delete_file( $entry );
		}
		self::remove_empty_directory( $directory );

		return ! self::path_exists( $directory );
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
	 * Validate a private-storage base against its effective access boundary.
	 *
	 * Ownership alone is not a sufficient access signal on modern hosting:
	 * ACLs, container bind mounts, and managed-volume mappings can make a
	 * root-owned 0700/0755 directory writable by PHP without changing the
	 * traditional mode bits. Rejecting every such path makes activation
	 * fail even though PHP has a private, writable location.
	 *
	 * We therefore combine effective writability with the mode boundary:
	 * a foreign-owned base is acceptable when PHP can write it and the
	 * Unix mode does not grant group/other write access. The standard
	 * shared-host /tmp case (world-writable + sticky bit) remains an
	 * explicit exception because sticky deletion protection prevents one
	 * tenant from replacing another tenant's entries. Foreign-owned 0775
	 * and 0777 directories without that protection remain rejected.
	 *
	 * Admins can explicitly allow a provider-specific path with the
	 * `sscribe_private_storage_allow_foreign_owner` filter.
	 *
	 * @param string $base Base directory to inspect.
	 * @return bool True when the base satisfies the ownership/access policy.
	 */
	private static function is_owned_by_current_process( string $base ): bool {
		if ( function_exists( 'apply_filters' ) ) {
			$forced = apply_filters( 'sscribe_private_storage_allow_foreign_owner', false, $base );
			if ( true === $forced ) {
				return true;
			}
		}

		// Windows ACLs do not map reliably to POSIX mode bits. The candidate
		// has already passed absolute-path, writability, symlink, and
		// public-root checks, and every SScribe-managed descendant is hardened
		// separately after creation.
		if ( 'Windows' === PHP_OS_FAMILY ) {
			return wp_is_writable( $base );
		}

		// On POSIX, ownership must not bypass a writable-by-group/world parent.
		// Another principal able to modify the candidate base could replace an
		// SScribe child between validation and creation. Evaluate the effective
		// write access and mode boundary consistently whether access comes from
		// ownership, an ACL/container mapping, or the standard sticky /tmp mode.
		if ( ! function_exists( 'fileperms' ) ) {
			return false;
		}
		$perms = @fileperms( $base );
		if ( false === $perms ) {
			return false;
		}

		return self::foreign_owned_base_permissions_are_safe( (int) $perms, wp_is_writable( $base ) );
	}

	/**
	 * Apply the foreign-owner permission policy to already-resolved mode bits.
	 *
	 * This pure helper keeps the ACL/container-volume case deterministic in
	 * tests without requiring privileged chown operations in CI.
	 *
	 * @param int  $permissions     fileperms()-style mode bits.
	 * @param bool $writable_by_php Whether PHP has effective write access.
	 * @return bool True when the foreign-owned base is safe to use.
	 */
	private static function foreign_owned_base_permissions_are_safe( int $permissions, bool $writable_by_php ): bool {
		if ( ! $writable_by_php ) {
			return false;
		}

		// Shared /tmp convention: world-writable is acceptable only with the
		// sticky bit, which prevents non-owners from deleting/replacing entries.
		if ( ( $permissions & 0002 ) && ( $permissions & 01000 ) ) {
			return true;
		}

		// Any remaining group/other write bit lets another principal modify
		// the base without sticky deletion protection.
		if ( $permissions & 0022 ) {
			return false;
		}

		// No group/other write bits are exposed; effective writability can be
		// supplied by an ACL/container mapping without weakening POSIX access.
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
		$path = self::resolve_path_for_comparison( $path );
		$root = self::resolve_path_for_comparison( $root );
		if ( '' === $path || '' === $root ) {
			return false;
		}
		return $path === $root ? $allow_equal : str_starts_with( $path, rtrim( $root, '/' ) . '/' );
	}

	/**
	 * Resolve a path canonically even when its final segments do not exist yet.
	 *
	 * Realpath() returns false for a not-yet-created child. For containment
	 * checks that can hide an intermediate symlink: '/tmp/link/new' must resolve
	 * through '/tmp/link' before comparison with its real target. Walk upward to
	 * the nearest existing ancestor, resolve that ancestor, then append the
	 * missing tail without following any nonexistent component.
	 *
	 * @param string $path Path to resolve.
	 * @return string Normalized canonical comparison path, or an empty string
	 *                when no existing ancestor can be resolved safely.
	 */
	private static function resolve_path_for_comparison( string $path ): string {
		$path = trim( $path );
		if ( '' === $path || str_contains( $path, "\0" ) ) {
			return '';
		}

		$resolved = realpath( $path );
		if ( false !== $resolved ) {
			return self::normalize_path( $resolved );
		}

		$missing = array();
		$cursor  = rtrim( $path, '/\\' );
		while ( '' !== $cursor && ! file_exists( $cursor ) && ! is_link( $cursor ) ) {
			$parent = dirname( $cursor );
			if ( $parent === $cursor ) {
				return '';
			}
			$missing[] = basename( $cursor );
			$cursor    = $parent;
		}

		$ancestor = realpath( $cursor );
		if ( false === $ancestor ) {
			return '';
		}

		$canonical = rtrim( self::normalize_path( $ancestor ), '/' );
		foreach ( array_reverse( $missing ) as $segment ) {
			if ( '' === $segment || '.' === $segment || '..' === $segment ) {
				return '';
			}
			$canonical .= '/' . $segment;
		}

		return self::normalize_path( $canonical );
	}

	/**
	 * Normalize a path for platform-safe comparisons.
	 *
	 * @param string $path Path to normalize.
	 * @return string Normalized path.
	 */
	private static function normalize_path( string $path ): string {
		$path = function_exists( 'wp_normalize_path' ) ? wp_normalize_path( $path ) : str_replace( '\\', '/', $path );
		$path = preg_match( '#^(?:/+|[a-z]:/+)$#i', $path ) ? rtrim( $path, '/' ) . '/' : rtrim( $path, '/' );
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
		// path_exists() also accepts plain files; scanning one would emit a
		// PHP warning and poison strict test runs.
		if ( ! is_dir( $source ) || is_link( $source ) ) {
			return false;
		}
		$entries = scandir( $source );
		if ( false === $entries ) {
			return false;
		}
		$migrated   = true;
		$deny_files = array();
		foreach ( array_diff( $entries, array( '.', '..' ) ) as $name ) {
			$from = $source . DIRECTORY_SEPARATOR . $name;
			$to   = $target . DIRECTORY_SEPARATOR . $name;
			if ( in_array( $name, SScribe_Security::GUARD_FILES, true ) && is_file( $from ) && ! is_link( $from ) ) {
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
