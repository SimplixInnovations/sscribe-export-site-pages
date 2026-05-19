<?php

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SScribe_Filesystem {

	private static ?WP_Filesystem_Base $fs = null;

	private static bool $initialized = false;

	private static string $last_error = '';

	private SScribe_Logger_Interface $logger;

	public function __construct() {
		$this->logger = SScribe_Logger::instance( defined( 'SSCRIBE_DEBUG' ) && SSCRIBE_DEBUG );
		$this->initialize();
	}

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

	public function mkdir( string $path, int $mode = 0755 ): bool {
		self::$last_error = '';

		if ( self::$fs instanceof WP_Filesystem_Base ) {
			return self::$fs->mkdir( $path, $mode );
		}

		return wp_mkdir_p( $path );
	}

	public function exists( string $path ): bool {
		if ( self::$fs instanceof WP_Filesystem_Base ) {
			return self::$fs->exists( $path );
		}

		return file_exists( $path );
	}

	public function is_dir( string $path ): bool {
		if ( self::$fs instanceof WP_Filesystem_Base ) {
			return self::$fs->is_dir( $path );
		}

		return is_dir( $path );
	}

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
			chmod( $destination, $mode ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- WP_Filesystem fallback for file permissions. Wrapped in function_exists() to safely handle environments where chmod is disabled.
		}

		return $result;
	}

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

	public function get_last_error(): string {
		return self::$last_error;
	}

	public function is_wp_filesystem(): bool {
		return self::$fs instanceof WP_Filesystem_Base;
	}

	public function get_method(): string {
		if ( self::$fs instanceof WP_Filesystem_Base ) {
			return get_class( self::$fs );
		}

		return 'direct';
	}
}
