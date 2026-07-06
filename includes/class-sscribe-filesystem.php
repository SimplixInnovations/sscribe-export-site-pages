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
	 * via a symlink : refuse the write.
	 */
	public const SSCRIBE_PATH_REJECT = 'reject';

	/**
	 * Sentinel: the path is outside the SScribe export directory but
	 * is not a symlink (e.g. WP temp dir) : allow, since the caller
	 * is performing a legitimate write that does not need protection.
	 */
	public const SSCRIBE_PATH_EXTERNAL = 'external';

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
			self::$last_error = 'Refusing to write outside SScribe export directory (symlink attack suspected)';
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

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- WP_Filesystem fallback for hosting environments without WP_Filesystem support.
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
	 * @param string $path Directory path to create.
	 * @param int    $mode Directory permissions (default: 0700).
	 * @return bool True if directory created or exists, false otherwise.
	 */
	public function mkdir( string $path, int $mode = 0700 ): bool {
		self::$last_error = '';

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
	 * Get last error message.
	 *
	 * @return string Last error message.
	 */
	public function get_last_error(): string {
		return self::$last_error;
	}

	/**
	 * Verify that the directory containing `$file` is within the
	 * SScribe uploads directory (or another explicitly allowed
	 * directory), following symlinks.
	 *
	 * Defense in depth against symlink attacks: a malicious plugin or
	 * shell access could create a symlink inside
	 * `wp-content/uploads/sscribe-exports/` that points outside the
	 * uploads directory (e.g. into `wp-config.php` or another site
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
	 * Decide whether a write to `$file` is safe from a symlink-attack
	 * perspective.
	 *
	 * The check resolves the parent directory of `$file` with
	 * realpath() and compares it to the SScribe export directory
	 * (computed lazily via wp_upload_dir()). Three outcomes:
	 *
	 *   - {@see self::SSCRIBE_PATH_ALLOWED}: the file's parent resolves
	 *     to a directory that is *inside* the SScribe export root.
	 *     The write is safe.
	 *   - {@see self::SSCRIBE_PATH_REJECT}: the file's parent is
	 *     *outside* the export root, and the literal path of the
	 *     file looks like it should be inside (i.e. the file was
	 *     planted under the export root, but a symlink in the path
	 *     redirects the write). Refuse the write.
	 *   - {@see self::SSCRIBE_PATH_EXTERNAL}: the file's parent is
	 *     outside the export root but the literal path is also
	 *     outside (e.g. WP temp dir). Allow the write : the caller
	 *     is performing a legitimate external write.
	 *
	 * @param string $file Target file path.
	 * @return string One of the SSCRIBE_PATH_* sentinels.
	 */
	public function is_path_safe_for_write( string $file ): string {
		$allowed_root = $this->get_export_dir();
		if ( '' === $allowed_root ) {
			
			
			
			return self::SSCRIBE_PATH_EXTERNAL;
		}

		$file_abs     = self::normalize_path( $file );
		$allowed_abs  = self::normalize_path( $allowed_root );
		$literal_in_export = ( 0 === strpos( $file_abs, $allowed_abs ) );

		if ( ! $literal_in_export ) {
			
			
			return self::SSCRIBE_PATH_EXTERNAL;
		}

		
		
		$parent = dirname( $file );
		if ( ! is_dir( $parent ) ) {
			
			
			return self::SSCRIBE_PATH_ALLOWED;
		}

		$parent_real = realpath( $parent );
		if ( false === $parent_real ) {
			
			
			return self::SSCRIBE_PATH_REJECT;
		}

		if ( $this->is_within_allowed_directory( $file, $parent_real ) ) {
			return self::SSCRIBE_PATH_ALLOWED;
		}

		return self::SSCRIBE_PATH_REJECT;
	}

	/**
	 * Get the absolute path of the SScribe export directory.
	 *
	 * Returns an empty string if wp_upload_dir() is unavailable.
	 *
	 * @return string Absolute path, or empty string on failure.
	 */
	private function get_export_dir(): string {
		if ( ! function_exists( 'wp_upload_dir' ) ) {
			return '';
		}
		$upload_dir = wp_upload_dir();
		if ( ! empty( $upload_dir['error'] ) || empty( $upload_dir['basedir'] ) ) {
			return '';
		}
		return trailingslashit( $upload_dir['basedir'] ) . 'sscribe-exports';
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
