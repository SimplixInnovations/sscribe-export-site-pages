<?php
/**
 * WP_Filesystem wrapper for SScribe.
 *
 * @package SScribe
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SScribe_Filesystem
 *
 * Provides a wrapper around WP_Filesystem with fallback to direct file operations.
 * Ensures compatibility across different hosting environments.
 */
class SScribe_Filesystem {

	/**
	 * WP_Filesystem instance.
	 *
	 * @var WP_Filesystem_Base|null
	 */
	private static ?WP_Filesystem_Base $fs = null;

	/**
	 * Whether WP_Filesystem is initialized.
	 *
	 * @var bool
	 */
	private static bool $initialized = false;

	/**
	 * Last error message.
	 *
	 * @var string
	 */
	private static string $last_error = '';

	/**
	 * Logger instance.
	 *
	 * @var SScribe_Logger_Interface
	 */
	private SScribe_Logger_Interface $logger;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->logger = SScribe_Logger::instance( defined( 'SSCRIBE_DEBUG' ) && SSCRIBE_DEBUG );
		$this->initialize();
	}

	/**
	 * Initialize WP_Filesystem.
	 *
	 * @return bool True if initialized successfully.
	 */
	private function initialize(): bool {
		if ( self::$initialized ) {
			return null !== self::$fs;
		}

		self::$initialized = true;

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

		$credentials = request_filesystem_credentials( '', '', false, false, null );

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
	 * Write content to a file.
	 *
	 * @param string $file    File path.
	 * @param string $content Content to write.
	 * @param int    $mode    File permissions (optional).
	 * @return bool True on success, false on failure.
	 */
	public function put_contents( string $file, string $content, int $mode = 0644 ): bool {
		self::$last_error = '';

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
			}

			return $result;
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
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- WP_Filesystem fallback for file permissions.
			if ( ! chmod( $file, $mode ) ) {
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
	 * Read file contents.
	 *
	 * @param string $file File path.
	 * @return string|false File contents or false on failure.
	 */
	public function get_contents( string $file ): string|false {
		self::$last_error = '';

		if ( self::$fs instanceof WP_Filesystem_Base ) {
			return self::$fs->get_contents( $file );
		}

		if ( ! file_exists( $file ) ) {
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
	 * @param string $file File path.
	 * @return bool True on success, false on failure.
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
	 * @param string $path Directory path.
	 * @param int    $mode Permissions (optional).
	 * @return bool True on success, false on failure.
	 */
	public function mkdir( string $path, int $mode = 0755 ): bool {
		self::$last_error = '';

		if ( self::$fs instanceof WP_Filesystem_Base ) {
			return self::$fs->mkdir( $path, $mode );
		}

		return wp_mkdir_p( $path );
	}

	/**
	 * Check if a file exists.
	 *
	 * @param string $path File path.
	 * @return bool True if exists.
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
	 * @param string $path Path to check.
	 * @return bool True if directory.
	 */
	public function is_dir( string $path ): bool {
		if ( self::$fs instanceof WP_Filesystem_Base ) {
			return self::$fs->is_dir( $path );
		}

		return is_dir( $path );
	}

	/**
	 * Check if a path is writable.
	 *
	 * @param string $path Path to check.
	 * @return bool True if writable.
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
	 * @param string $path Directory path.
	 * @return array|false Array of files or false on failure.
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

		return array_diff( $files, array( '.', '..' ) );
	}

	/**
	 * Copy a file.
	 *
	 * @param string $source      Source file path.
	 * @param string $destination Destination file path.
	 * @param bool   $overwrite   Whether to overwrite destination.
	 * @param int    $mode        Permissions for destination.
	 * @return bool True on success.
	 */
	public function copy( string $source, string $destination, bool $overwrite = false, int $mode = 0644 ): bool {
		self::$last_error = '';

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
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- WP_Filesystem fallback for file permissions. Wrapped in function_exists() to safely handle environments where chmod is disabled.
			chmod( $destination, $mode );
		}

		return $result;
	}

	/**
	 * Move a file.
	 *
	 * @param string $source      Source file path.
	 * @param string $destination Destination file path.
	 * @param bool   $overwrite   Whether to overwrite destination.
	 * @return bool True on success.
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

		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- WP_Filesystem fallback for file moving.
		return rename( $source, $destination );
	}

	/**
	 * Get the last error message.
	 *
	 * @return string Error message.
	 */
	public function get_last_error(): string {
		return self::$last_error;
	}

	/**
	 * Check if WP_Filesystem is being used.
	 *
	 * @return bool True if WP_Filesystem is active.
	 */
	public function is_wp_filesystem(): bool {
		return self::$fs instanceof WP_Filesystem_Base;
	}

	/**
	 * Get the filesystem method being used.
	 *
	 * @return string Filesystem method name.
	 */
	public function get_method(): string {
		if ( self::$fs instanceof WP_Filesystem_Base ) {
			return get_class( self::$fs );
		}

		return 'direct';
	}
}
