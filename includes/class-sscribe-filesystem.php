<?php
/**
 * SScribe Filesystem
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
 * Filesystem abstraction layer for SScribe plugin.
 *
 * Provides a unified interface for file operations with WP_Filesystem fallback
 * when standard WordPress filesystem methods are unavailable.
 */
class SScribe_Filesystem {

	/**
	 * WP_Filesystem instance.
	 *
	 * @var WP_Filesystem_Base|null
	 */
	private static ?WP_Filesystem_Base $fs = null;

	/**
	 * Last error message from operations.
	 *
	 * @var string
	 */
	private static string $last_error = '';

	/**
	 * Logger instance for error reporting.
	 *
	 * @var SScribe_Logger_Interface
	 */
	private SScribe_Logger_Interface $logger;

	/**
	 * Sentinel: the path resolves inside the SScribe export directory :
	 * always safe to write.
	 */
	public const SSCRIBE_PATH_ALLOWED = 'allowed';

	/**
	 * Sentinel: the path resolves outside the SScribe export directory
	 * (whether via a symlink or not) : refuse the write.
	 *
	 * SScribe writes runtime artifacts only to its resolved private storage
	 * directory. All other paths are rejected by default, even if the literal
	 * text does not start with the export root.
	 */
	public const SSCRIBE_PATH_REJECT = 'reject';

	/**
	 * Constructor.
	 *
	 * Initializes the filesystem handler and logger.
	 *
	 * @return void
	 */
	public function __construct() {
		$this->logger = SScribe_Logger::instance( SScribe_Logger::is_logging_enabled() );
		$this->initialize();
	}

	/**
	 * Initialize WP_Filesystem if available.
	 *
	 * Attempts to initialize WordPress filesystem abstraction with fallback
	 * to direct PHP filesystem operations if WP_Filesystem is unavailable.
	 *
	 * Only marks the cache as initialized when initialization SUCCEEDS, so
	 * a request that initializes too early (before credentials are available)
	 * can still retry on the next instantiation once the runtime is ready.
	 *
	 * @return bool True if WP_Filesystem is available, false otherwise.
	 */
	private function initialize(): bool {
		if ( self::$fs instanceof WP_Filesystem_Base ) {
			return true;
		}

		if ( ! function_exists( 'WP_Filesystem' ) ) {
			$file_path = ABSPATH . 'wp-admin/includes/file.php';
			if ( file_exists( $file_path ) ) {
				require_once $file_path;
			} else {
				$this->logger->debug( 'WP_Filesystem file not available, using direct file operations' );
				return false;
			}
		}

		if ( ! function_exists( 'request_filesystem_credentials' ) ) {
			$this->logger->debug( 'WP_Filesystem functions not available, using direct file operations' );
			return false;
		}

		ob_start();
		$credentials = request_filesystem_credentials( admin_url(), '', false, false, null );
		ob_end_clean();

		if ( false === $credentials ) {
			$this->logger->debug( 'Could not get filesystem credentials, using direct file operations' );
			return false;
		}

		if ( ! WP_Filesystem( $credentials ) ) {
			$this->logger->debug( 'WP_Filesystem initialization failed, using direct file operations' );
			return false;
		}

		global $wp_filesystem;

		if ( ! $wp_filesystem instanceof WP_Filesystem_Base ) {
			$this->logger->debug( 'WP_Filesystem not properly initialized, using direct file operations' );
			return false;
		}

		self::$fs = $wp_filesystem;
		$this->logger->debug( 'WP_Filesystem initialized successfully', array( 'method' => get_class( self::$fs ) ) );

		return true;
	}

	/**
	 * Sanitize a file path to prevent traversal attacks.
	 *
	 * Defense in depth: callers should sanitize user-controlled filenames
	 * (e.g. page titles) before constructing paths, but this guard prevents
	 * path traversal if a future caller forgets. The full path is normalized
	 * to collapse any embedded ".." or "." segments; the basename itself
	 * is NOT modified (so legitimate filenames with spaces, Unicode, etc.
	 * pass through unchanged).
	 *
	 * If the input path contains no traversal segments, it is returned
	 * as-is (after separator normalization). This preserves backward
	 * compatibility with existing callers that construct paths from
	 * trusted upload-dir constants and a sanitized basename.
	 *
	 * @param string $file Original file path.
	 * @return string Path with traversal segments collapsed, or original
	 *                 path if it contains none.
	 */
	public static function sanitize_path( string $file ): string {
		if ( '' === $file ) {
			return $file;
		}

		if ( false === strpos( $file, '..' ) && false === strpos( $file, "\0" ) ) {
			return $file;
		}

		if ( false === strpos( $file, '..' ) ) {
			return str_replace( "\0", '', $file );
		}

		$is_unix_absolute = '/' === $file[0];

		$normalized = str_replace( '\\', '/', $file );
		$normalized = preg_replace( '#/+#', '/', $normalized );

		$segments = explode( '/', $normalized );
		$cleaned  = array();
		foreach ( $segments as $segment ) {
			if ( '' === $segment || '.' === $segment || '..' === $segment ) {
				continue;
			}
			$cleaned[] = $segment;
		}

		if ( empty( $cleaned ) ) {
			return '';
		}

		$reassembled = implode( '/', $cleaned );

		if ( $is_unix_absolute && '/' !== $reassembled[0] ) {
			$reassembled = '/' . $reassembled;
		}

		return $reassembled;
	}

	/**
	 * Write contents to a file.
	 *
	 * @param string $file    File path to write to.
	 * @param string $content Content to write.
	 * @param int    $mode    File permission mode (default: 0600).
	 * @return bool True if write succeeded, false otherwise.
	 */
	public function put_contents( string $file, string $content, int $mode = 0600 ): bool {
		self::$last_error = '';

		$file = self::sanitize_path( $file );

		$safety = $this->is_path_safe_for_write( $file );
		if ( self::SSCRIBE_PATH_REJECT === $safety ) {
			self::$last_error = 'Refusing to write outside SScribe export directory';
			$this->logger->warning(
				'Refused write : path resolves outside SScribe export directory',
				array(
					'file' => $file,
				)
			);
			return false;
		}

		if ( self::$fs instanceof WP_Filesystem_Base ) {
			$result = self::$fs->put_contents( $file, $content, $mode );

			if ( ! $result ) {
				self::$last_error = 'WP_Filesystem put_contents failed';
				$this->logger->error(
					'WP_Filesystem write failed',
					array(
						'file' => $file,
						'size' => strlen( $content ),
					)
				);
				return false;
			}

			if ( $mode && self::$fs->chmod( $file, $mode ) === false ) {
				$this->logger->warning(
					'WP_Filesystem chmod failed : file may have unexpected permissions',
					array(
						'file' => $file,
						'mode' => decoct( $mode ),
					)
				);
			}

			return true;
		}

		$dir = dirname( $file );

		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- WP_Filesystem fallback for hosting environments without WP_Filesystem support. The file path is restricted to SScribe's private storage root by is_path_safe_for_write() above.
		$result = file_put_contents( $file, $content );

		if ( false === $result ) {
			self::$last_error = 'file_put_contents failed';
			$this->logger->error(
				'Direct file write failed',
				array(
					'file' => $file,
					'size' => strlen( $content ),
				)
			);
			return false;
		}

		if ( $mode ) {
			if ( ! chmod( $file, $mode ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- WP_Filesystem fallback for file permissions.
				$this->logger->warning(
					'Failed to set file permissions',
					array(
						'file' => $file,
						'mode' => decoct( $mode ),
					)
				);
			}
		}

		return true;
	}

	/**
	 * Read contents from a file.
	 *
	 * @param string $file File path to read.
	 * @return string|false File contents or false on failure.
	 */
	public function get_contents( string $file ): string|false {
		self::$last_error = '';

		$file = self::sanitize_path( $file );

		if ( ! $this->is_path_safe_for_plugin_read( $file ) ) {
			self::$last_error = 'Path is outside the allowed read scope';
			return false;
		}

		if ( self::$fs instanceof WP_Filesystem_Base ) {
			$content = self::$fs->get_contents( $file );
			return false !== $content ? $content : false;
		}

		if ( ! is_file( $file ) ) {
			self::$last_error = 'File does not exist';
			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- WP_Filesystem fallback for local file reading.
		$content = file_get_contents( $file );

		if ( false === $content ) {
			self::$last_error = 'Failed to read file contents';
			return false;
		}

		return $content;
	}

	/**
	 * Delete a file.
	 *
	 * @param string $file File path to delete.
	 * @return bool True if delete succeeded or file doesn't exist, false otherwise.
	 */
	public function delete( string $file ): bool {
		self::$last_error = '';

		if ( self::SSCRIBE_PATH_ALLOWED !== $this->is_path_safe_for_write( $file ) ) {
			self::$last_error = 'Path is outside the allowed delete scope';
			$this->logger->warning(
				'Rejected file deletion outside export directory',
				array( 'file' => basename( $file ) )
			);
			return false;
		}

		if ( self::$fs instanceof WP_Filesystem_Base ) {
			return self::$fs->delete( $file );
		}

		if ( ! file_exists( $file ) ) {
			return true;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- WP_Filesystem fallback for file deletion.
		$result = unlink( $file );

		if ( ! $result ) {
			self::$last_error = 'Failed to delete file';
			$this->logger->warning(
				'File deletion failed',
				array( 'file' => $file )
			);
			return false;
		}

		return true;
	}

	/**
	 * Create a directory.
	 *
	 * Default permission is 0700 (owner-only). The .htaccess guard file
	 * written by SScribe_Security::protect_directory() blocks HTTP access
	 * regardless, but a tighter default on the filesystem itself limits
	 * the blast radius of any other plugin/user reading the log/exports
	 * directory out-of-band.
	 *
	 * Phase 38 containment: every public filesystem mutation must verify
	 * its target resolves inside the plugin-owned export directory before
	 * touching the disk. A caller passing `/tmp/foo` or any path that
	 * resolves outside the export root gets a rejection, not a directory
	 * at an arbitrary location. Use {@see self::mkdir_under_private_root()}
	 * when the caller has only a relative path.
	 *
	 * @param string $path Directory path to create.
	 * @param int    $mode Directory permissions (default: 0700).
	 * @return bool True if directory created or exists, false otherwise.
	 */
	public function mkdir( string $path, int $mode = 0700 ): bool {
		self::$last_error = '';

		if ( self::SSCRIBE_PATH_REJECT === $this->is_path_safe_for_write( $path ) ) {
			self::$last_error = 'Refusing to mkdir outside SScribe export directory';
			$this->logger->warning(
				'Refused mkdir : path resolves outside SScribe export directory',
				array(
					'path' => $path,
				)
			);
			return false;
		}

		if ( self::$fs instanceof WP_Filesystem_Base ) {
			return self::$fs->mkdir( $path, $mode );
		}

		if ( ! is_dir( $path ) ) {
			if ( ! wp_mkdir_p( $path ) ) {
				return false;
			}

			if ( $mode && function_exists( 'chmod' ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Fallback when WP_Filesystem unavailable.
				chmod( $path, $mode );
			}
		}

		return true;
	}

	/**
	 * Root-scoped directory creation.
	 *
	 * Phase 38 helper: callers that need to create a directory inside
	 * the plugin-owned export area must pass a *relative* path. The
	 * helper resolves it under {@see SScribe_Private_Storage::get_export_dir()}
	 * and runs the standard `wp_mkdir_p()` plumbing.
	 *
	 * The relative path is rejected when it:
	 *
	 *  - is empty or whitespace-only;
	 *  - starts with `/` (Unix absolute), a Windows drive letter, or
	 *    a UNC prefix (any absolute path);
	 *  - contains a NUL byte;
	 *  - contains a `..` traversal segment after normalization;
	 *  - resolves to a symlink.
	 *
	 * A traversal attempt never reaches the disk because the rejection
	 * happens before any `wp_mkdir_p()` call. The function returns the
	 * absolute path on success so callers can chain follow-up writes
	 * without re-resolving the export root.
	 *
	 * @param string $relative_path Path relative to the export root.
	 * @param int    $mode          Directory permissions (default: 0700).
	 * @return string Absolute path on success, empty string on failure.
	 */
	public function mkdir_under_private_root( string $relative_path, int $mode = 0700 ): string {
		self::$last_error = '';

		$normalized = trim( str_replace( '\\', '/', $relative_path ) );

		if ( '' === $normalized || str_contains( $normalized, "\0" ) ) {
			self::$last_error = 'Relative path is empty or contains a NUL byte';
			$this->logger->warning( 'Refused mkdir_under_private_root : empty or NUL', array( 'input' => $relative_path ) );
			return '';
		}

		// Refuse absolute paths (Unix leading slash, Windows drive letter,
		// UNC prefix). The check runs against the un-stripped input so
		// `/tmp/foo` is still rejected as absolute rather than being
		// silently accepted as a sibling segment under the export root.
		if ( self::is_absolute_path_string( $normalized ) ) {
			self::$last_error = 'Relative path is absolute; only paths relative to the export root are accepted';
			$this->logger->warning( 'Refused mkdir_under_private_root : absolute path', array( 'input' => $relative_path ) );
			return '';
		}

		$normalized = ltrim( $normalized, '/' );

		$segments = explode( '/', $normalized );
		$clean    = array();
		foreach ( $segments as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}
			if ( '..' === $segment ) {
				self::$last_error = 'Relative path contains a .. traversal segment';
				$this->logger->warning( 'Refused mkdir_under_private_root : traversal', array( 'input' => $relative_path ) );
				return '';
			}
			$clean[] = $segment;
		}

		if ( empty( $clean ) ) {
			self::$last_error = 'Relative path collapses to empty after normalization';
			return '';
		}

		$export_root = $this->get_export_dir();
		if ( '' === $export_root ) {
			self::$last_error = 'Export root is not available';
			$this->logger->warning( 'Refused mkdir_under_private_root : no export root' );
			return '';
		}

		$target = rtrim( $export_root, '/\\' ) . DIRECTORY_SEPARATOR . implode( DIRECTORY_SEPARATOR, $clean );

		// Walk each component before creating the next one. A final
		// realpath() containment check is too late: wp_mkdir_p() would
		// already have followed an attacker-planted intermediate symlink
		// and could create directories outside the private root before the
		// escape was detected.
		$cursor = rtrim( $export_root, '/\\' );
		foreach ( $clean as $segment ) {
			$cursor .= DIRECTORY_SEPARATOR . $segment;
			clearstatcache( true, $cursor );

			if ( is_link( $cursor ) ) {
				self::$last_error = 'Path contains a symlink';
				$this->logger->warning( 'Refused mkdir_under_private_root : intermediate symlink', array( 'target' => $cursor ) );
				return '';
			}
			if ( file_exists( $cursor ) ) {
				if ( ! is_dir( $cursor ) ) {
					self::$last_error = 'Path component is not a directory';
					return '';
				}
				continue;
			}
			if ( ! wp_mkdir_p( $cursor ) ) {
				self::$last_error = 'wp_mkdir_p failed for contained target';
				$this->logger->error( 'mkdir_under_private_root : wp_mkdir_p failed', array( 'target' => $cursor ) );
				return '';
			}
			clearstatcache( true, $cursor );
			if ( is_link( $cursor ) || ! is_dir( $cursor ) ) {
				self::$last_error = 'Created path component is unsafe';
				return '';
			}
		}

		if ( $mode && function_exists( 'chmod' ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Apply requested mode after create.
			@chmod( $target, $mode );
		}

		// Final containment re-check: confirm the resolved target is
		// still inside the export root after creation. This catches the
		// edge case where a sibling directory was symlinked between
		// validation and creation.
		$target_real = realpath( $target );
		$root_real   = realpath( $export_root );
		if ( false === $target_real || false === $root_real ) {
			self::$last_error = 'Could not resolve target or root';
			return '';
		}
		$target_norm = rtrim( self::normalize_path( $target_real ), '/' );
		$root_norm   = rtrim( self::normalize_path( $root_real ), '/' );
		if ( $target_norm !== $root_norm && ! str_starts_with( $target_norm, $root_norm . '/' ) ) {
			self::$last_error = 'Target resolved outside export root after creation';
			$this->logger->warning( 'Refused mkdir_under_private_root : escape after create', array( 'target' => $target_real ) );
			return '';
		}

		return $target;
	}

	/**
	 * Detect whether a string is an absolute filesystem path on the
	 * current platform. Used by {@see self::mkdir_under_private_root()}
	 * to reject Unix-style absolute paths and Windows drive letters.
	 *
	 * @param string $candidate Normalized (leading separator-stripped) path.
	 * @return bool True when the path is absolute.
	 */
	private static function is_absolute_path_string( string $candidate ): bool {
		// Unix-style absolute (leading slash).
		if ( str_starts_with( $candidate, '/' ) ) {
			return true;
		}
		// Windows drive letter (C:, D:, …).
		if ( strlen( $candidate ) >= 2 && ctype_alpha( $candidate[0] ) && ':' === $candidate[1] ) {
			return true;
		}
		// UNC prefix (\\server\share).
		if ( str_starts_with( $candidate, '\\\\' ) ) {
			return true;
		}
		// Defense-in-depth: embedded absolute segments such as
		// `/etc/passwd` slipping through via concatenation.
		if ( preg_match( '#^[/\\\\]+[A-Za-z]:#', $candidate ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Check if file exists.
	 *
	 * @param string $path File or directory path.
	 * @return bool True if exists, false otherwise.
	 */
	public function exists( string $path ): bool {
		if ( self::$fs instanceof WP_Filesystem_Base ) {
			return self::$fs->exists( $path );
		}

		return file_exists( $path );
	}

	/**
	 * Check if path is a directory.
	 *
	 * @param string $path Directory path to check.
	 * @return bool True if is directory, false otherwise.
	 */
	public function is_dir( string $path ): bool {
		if ( self::$fs instanceof WP_Filesystem_Base ) {
			return self::$fs->is_dir( $path );
		}

		return is_dir( $path );
	}

	/**
	 * Check if path is writable.
	 *
	 * @param string $path Path to check.
	 * @return bool True if writable, false otherwise.
	 */
	public function is_writable( string $path ): bool {
		if ( self::$fs instanceof WP_Filesystem_Base ) {
			return self::$fs->is_writable( $path );
		}

		if ( function_exists( 'wp_is_writable' ) ) {
			return wp_is_writable( $path );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- WP_Filesystem fallback.
		return is_writable( $path );
	}

	/**
	 * List files in a directory.
	 *
	 * @param string $path Directory path to list.
	 * @return array|false Array of filenames or false on failure.
	 */
	public function dirlist( string $path ): array|false {
		if ( self::$fs instanceof WP_Filesystem_Base ) {
			return self::$fs->dirlist( $path );
		}

		if ( ! is_dir( $path ) ) {
			return false;
		}

		$files = scandir( $path );

		if ( false === $files ) {
			return false;
		}

		$result = array();
		foreach ( array_diff( $files, array( '.', '..' ) ) as $name ) {

			if ( '' === $name || '.' === $name[0] || 'index.php' === $name ) {
				continue;
			}
			$full          = trailingslashit( $path ) . $name;
			$result[ $name ] = array(
				'name'         => $name,
				'type'         => is_dir( $full ) ? 'd' : 'f',
				'size'         => is_file( $full ) ? filesize( $full ) : 0,
				'lastmodified' => filemtime( $full ),
			);
		}

		return $result;
	}

	/**
	 * Copy a file.
	 *
	 * @param string $source      Source file path.
	 * @param string $destination Destination file path.
	 * @param bool   $overwrite   Whether to overwrite existing file (default: false).
	 * @param int    $mode        File permission mode (default: 0600).
	 * @return bool True if copy succeeded, false otherwise.
	 */
	public function copy( string $source, string $destination, bool $overwrite = false, int $mode = 0600 ): bool {
		self::$last_error = '';

		$safety = $this->is_path_safe_for_write( $destination );
		if ( self::SSCRIBE_PATH_REJECT === $safety ) {
			self::$last_error = 'Refusing to copy outside SScribe export directory (symlink attack suspected)';
			$this->logger->warning(
				'Refused copy : destination resolves outside SScribe export directory',
				array(
					'source'      => $source,
					'destination' => $destination,
				)
			);
			return false;
		}

		$source_safety = $this->is_path_safe_for_read( $source );
		if ( self::SSCRIBE_PATH_REJECT === $source_safety ) {
			self::$last_error = 'Refusing to copy source outside SScribe export directory';
			$this->logger->warning(
				'Refused copy: source is not a regular file inside the SScribe export directory',
				array(
					'source'      => $source,
					'destination' => $destination,
				)
			);
			return false;
		}

		if ( self::$fs instanceof WP_Filesystem_Base ) {
			return self::$fs->copy( $source, $destination, $overwrite, $mode );
		}

		if ( ! $overwrite && file_exists( $destination ) ) {
			self::$last_error = 'Destination file already exists';
			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy -- WP_Filesystem fallback for file copy.
		$result = copy( $source, $destination );

		if ( $result && $mode && function_exists( 'chmod' ) ) {
			chmod( $destination, $mode ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- WP_Filesystem fallback for file permissions. Wrapped in function_exists() to safely handle environments where chmod is disabled.
		}

		return $result;
	}

	/**
	 * Move a file.
	 *
	 * @param string $source      Source file path.
	 * @param string $destination Destination file path.
	 * @param bool   $overwrite  Whether to overwrite existing file (default: false).
	 * @return bool True if move succeeded, false otherwise.
	 */
	public function move( string $source, string $destination, bool $overwrite = false ): bool {
		self::$last_error = '';

		$safety = $this->is_path_safe_for_write( $destination );
		if ( self::SSCRIBE_PATH_REJECT === $safety ) {
			self::$last_error = 'Refusing to move outside SScribe export directory (symlink attack suspected)';
			$this->logger->warning(
				'Refused move : destination resolves outside SScribe export directory',
				array(
					'source'      => $source,
					'destination' => $destination,
				)
			);
			return false;
		}

		$source_safety = $this->is_path_safe_for_read( $source );
		if ( self::SSCRIBE_PATH_REJECT === $source_safety ) {
			self::$last_error = 'Refusing to move source outside SScribe export directory';
			$this->logger->warning(
				'Refused move: source is not a regular file inside the SScribe export directory',
				array(
					'source'      => $source,
					'destination' => $destination,
				)
			);
			return false;
		}

		if ( self::$fs instanceof WP_Filesystem_Base ) {
			return self::$fs->move( $source, $destination, $overwrite );
		}

		if ( ! $overwrite && file_exists( $destination ) ) {
			self::$last_error = 'Destination file already exists';
			return false;
		}

		return rename( $source, $destination ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- WP_Filesystem fallback for file moving.
	}

	/**
	 * Decide whether an existing source is safe to read, copy, or move.
	 *
	 * The source must be a regular, non-symlink file whose canonical path is
	 * inside the plugin-owned export directory. Canonicalizing the file itself
	 * (rather than only its parent) prevents an in-directory symlink from
	 * exposing an arbitrary local file through an exported ZIP.
	 *
	 * @param string $file Existing source file path.
	 * @return string One of the SSCRIBE_PATH_* sentinels.
	 */
	/**
	 * Read scope for the generic get_contents() API: files under the SScribe
	 * export directory or the plugin directory itself. Extension plugins may
	 * legitimately read bundled assets (fonts, icons), but nothing else.
	 *
	 * @param string $file File path.
	 * @return bool True when the path is inside a plugin-owned directory.
	 */
	private function is_path_safe_for_plugin_read( string $file ): bool {
		if ( '' === $file || str_contains( $file, ' ' ) || is_link( $file ) ) {
			return false;
		}

		$file_real = realpath( $file );
		if ( false === $file_real ) {
			return false;
		}

		$roots = array( $this->get_export_dir(), SSCRIBE_PLUGIN_DIR );
		foreach ( $roots as $root ) {
			if ( '' === $root ) {
				continue;
			}
			$root_real = realpath( $root );
			if ( false === $root_real ) {
				continue;
			}
			$prefix = rtrim( self::normalize_path( $root_real ), '/' ) . '/';
			if ( 0 === strpos( self::normalize_path( $file_real ), $prefix ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Read-scope guard: only files inside the export directory are allowed
	 * for the internal read paths that bypass the WP_Filesystem API.
	 *
	 * @param string $file File path.
	 * @return string SSCRIBE_PATH_ALLOWED or SSCRIBE_PATH_REJECT.
	 */
	private function is_path_safe_for_read( string $file ): string {
		$allowed_root = $this->get_export_dir();
		if ( '' === $file || '' === $allowed_root || str_contains( $file, "\0" ) || is_link( $file ) ) {
			return self::SSCRIBE_PATH_REJECT;
		}

		$file_real = realpath( $file );
		$root_real = realpath( $allowed_root );
		if ( false === $file_real || false === $root_real || ! is_file( $file_real ) ) {
			return self::SSCRIBE_PATH_REJECT;
		}

		$file_abs    = self::normalize_path( $file_real );
		$root_prefix = rtrim( self::normalize_path( $root_real ), '/' ) . '/';

		return 0 === strpos( $file_abs, $root_prefix )
			? self::SSCRIBE_PATH_ALLOWED
			: self::SSCRIBE_PATH_REJECT;
	}

	/**
	 * Get last error message.
	 *
	 * @return string Last error message.
	 */
	public function get_last_error(): string {
		return self::$last_error;
	}

	/**
	 * Verify that the directory containing `$file` is within an explicitly
	 * allowed directory, following symlinks.
	 *
	 * Defense in depth against symlink attacks: a malicious plugin or
	 * shell access could create a symlink inside the private export directory
	 * that points outside its root (e.g. into `wp-config.php` or another site
	 * user's home dir). Without this check, a write through the
	 * symlink would succeed and write to the attacker-controlled
	 * target.
	 *
	 * The check resolves the *parent directory* of the target file
	 * (not the file itself, which may not exist yet) and verifies
	 * the resolved absolute path is a prefix of the allowed root.
	 *
	 * @param string $file         Target file path (the file being written).
	 * @param string $allowed_root Absolute path of the allowed root directory.
	 * @return bool True if the write is safe; false if a symlink attack is suspected.
	 */
	public function is_within_allowed_directory( string $file, string $allowed_root ): bool {
		if ( '' === $file || '' === $allowed_root ) {
			return false;
		}

		$allowed_real = realpath( $allowed_root );
		if ( false === $allowed_real ) {

			$allowed_real = self::normalize_path( $allowed_root );
		} else {

			$allowed_real = self::normalize_path( $allowed_real );
		}

		$parent      = dirname( $file );
		$parent_real = realpath( $parent );
		if ( false === $parent_real ) {

			$parent_real = self::normalize_path( $parent );
		} else {
			$parent_real = self::normalize_path( $parent_real );
		}

		$parent_real = rtrim( $parent_real, '/' ) . '/';
		$allowed_real = rtrim( $allowed_real, '/' ) . '/';

		$cmp = ( defined( 'PHP_OS_FAMILY' ) && 'Windows' === PHP_OS_FAMILY )
			? 'strcasecmp'
			: 'strcmp';

		return 0 === strpos( $parent_real, $allowed_real )
			|| 0 === $cmp( $parent_real, $allowed_real );
	}

	/**
	 * Normalize a path by resolving `.` and `..` segments lexically
	 * (no filesystem access). Used when realpath() is not available
	 * because the path does not yet exist.
	 *
	 * On Windows, the result is lowercased so callers can compare
	 * it against realpath() output (Windows filesystems are
	 * case-insensitive). This matches the lowercase normalization in
	 * `SScribe_Security::normalize_path_for_compare()` so the
	 * filesystem primitives and the security check use the same
	 * case-folding rule.
	 *
	 * @param string $path Path to normalize.
	 * @return string Normalized absolute path.
	 */
	private static function normalize_path( string $path ): string {
		$path = str_replace( '\\', '/', $path );
		$is_absolute = ( 0 === strpos( $path, '/' ) );
		$segments   = explode( '/', $path );
		$resolved   = array();
		foreach ( $segments as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}
			if ( '..' === $segment ) {
				array_pop( $resolved );
				continue;
			}
			$resolved[] = $segment;
		}
		$normalized = ( $is_absolute ? '/' : '' ) . implode( '/', $resolved );
		if ( defined( 'PHP_OS_FAMILY' ) && 'Windows' === PHP_OS_FAMILY ) {
			$normalized = strtolower( $normalized );
		}
		return $normalized;
	}

	/**
	 * Decide whether a write to `$file` is safe.
	 *
	 * The check enforces SScribe's private-storage boundary. The only accepted
	 * destination is this site's resolved plugin-owned export directory.
	 *
	 * Two outcomes:
	 *
	 *   - {@see self::SSCRIBE_PATH_ALLOWED}: the literal target path
	 *     is under the SScribe export directory AND the parent
	 *     directory (if it exists) resolves back inside the export
	 *     root via realpath() (defends against symlink planting).
	 *   - {@see self::SSCRIBE_PATH_REJECT}: anything else, including
	 *     paths whose literal text is already outside the export
	 *     directory, paths where the parent does not exist, and
	 *     paths where the private resolver is unavailable. Default-deny
	 *     protects against misuse even from future callers.
	 *
	 * @param string $file Target file path.
	 * @return string One of the SSCRIBE_PATH_* sentinels.
	 */
	public function is_path_safe_for_write( string $file ): string {
		$allowed_root = $this->get_export_dir();
		if ( '' === $allowed_root ) {

			return self::SSCRIBE_PATH_REJECT;
		}

		$file_abs      = self::normalize_path( $file );
		$allowed_abs   = self::normalize_path( $allowed_root );
		$allowed_prefix = rtrim( $allowed_abs, '/' ) . '/';
		$literal_in_export = $file_abs === $allowed_abs || 0 === strpos( $file_abs, $allowed_prefix );

		if ( ! $literal_in_export ) {

			return self::SSCRIBE_PATH_REJECT;
		}
		if ( is_link( $file ) ) {
			$target = realpath( $file );
			if ( false === $target || 0 !== strpos( self::normalize_path( $target ), self::normalize_path( $allowed_root ) ) ) {

				return self::SSCRIBE_PATH_REJECT;
			}
		}

		$parent = dirname( $file );
		$nearest_existing = $parent;
		// A dangling symlink reports file_exists() === false. Stop at links
		// explicitly so we never climb past an attacker-planted symlink and
		// accidentally validate only its safe-looking parent directory.
		while (
			! file_exists( $nearest_existing )
			&& ! is_link( $nearest_existing )
			&& dirname( $nearest_existing ) !== $nearest_existing
		) {
			$nearest_existing = dirname( $nearest_existing );
		}

		$ancestor_real = realpath( $nearest_existing );
		$root_real     = realpath( $allowed_root );
		if ( false === $ancestor_real || false === $root_real ) {

			return self::SSCRIBE_PATH_REJECT;
		}
		$ancestor_abs = rtrim( self::normalize_path( $ancestor_real ), '/' );
		$root_abs     = rtrim( self::normalize_path( $root_real ), '/' );
		if ( $ancestor_abs !== $root_abs && ! str_starts_with( $ancestor_abs, $root_abs . '/' ) ) {
			return self::SSCRIBE_PATH_REJECT;
		}

		return self::SSCRIBE_PATH_ALLOWED;
	}

	/**
	 * Get the absolute path of the SScribe export directory.
	 *
	 * Returns an empty string if no safe private location is available.
	 *
	 * @return string Absolute path, or empty string on failure.
	 */
	private function get_export_dir(): string {
		return SScribe_Private_Storage::get_export_dir();
	}

	/**
	 * Check if WP_Filesystem is being used.
	 *
	 * @return bool True if WP_Filesystem is active, false if using direct operations.
	 */
	public function is_wp_filesystem(): bool {
		return self::$fs instanceof WP_Filesystem_Base;
	}

	/**
	 * Get filesystem method name.
	 *
	 * @return string Filesystem method ('direct', 'ftpext', etc.) or class name if WP_Filesystem.
	 */
	public function get_method(): string {
		if ( self::$fs instanceof WP_Filesystem_Base ) {
			return get_class( self::$fs );
		}

		return 'direct';
	}
}
