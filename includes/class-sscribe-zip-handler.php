<?php
/**
 * Handles ZIP packaging and file cleanup.
 *
 * @package SScribe
 */

declare(strict_types=1);

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SScribe_Zip_Handler
 *
 * Bundles export files into ZIP packages and manages automatic cleanup.
 */
class SScribe_Zip_Handler {


	/**
	 * Export directory path.
	 *
	 * @var string
	 */
	private readonly string $export_dir;

	/**
	 * Logger instance.
	 *
	 * @var SScribe_Logger_Interface
	 */
	private readonly SScribe_Logger_Interface $logger;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$upload_dir       = wp_upload_dir();
		$this->export_dir = $upload_dir['basedir'] . '/sscribe-exports';
		$this->logger     = SScribe_Logger::instance( defined( 'SSCRIBE_DEBUG' ) && SSCRIBE_DEBUG );
	}

	/**
	 * Get the export directory path.
	 *
	 * @return string
	 */
	public function get_export_dir(): string {
		if ( ! file_exists( $this->export_dir ) ) {
			SScribe_Security::protect_directory( $this->export_dir );
		}
		return $this->export_dir;
	}

	/**
	 * Create a temporary directory for DOCX files.
	 *
	 * @return string Path to temporary directory.
	 */
	public function create_temp_dir(): string {
		$random_suffix = bin2hex( random_bytes( 6 ) );
		$temp_dir      = $this->export_dir . '/temp-' . $random_suffix;
		wp_mkdir_p( $temp_dir );
		return $temp_dir;
	}

	/**
	 * Bundle export files from a directory into a ZIP.
	 *
	 * @param string $source_dir Directory containing export files.
	 * @param string $zip_name   Desired ZIP filename (without extension).
	 * @param array  $formats    Export formats used.
	 * @return string|false Path to ZIP file or false on failure.
	 */
	public function create_zip( string $source_dir, string $zip_name = '', array $formats = array( 'docx' ) ): string|false {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->logger->error( 'ZipArchive not available' );
			$this->delete_directory( $source_dir );
			return false;
		}

		if ( empty( $zip_name ) ) {
			$random_suffix = bin2hex( random_bytes( 3 ) );
			$zip_name      = 'sscribe-export-' . gmdate( 'Y-m-d-His' ) . '-' . $random_suffix;
		}

		$zip_path = $this->export_dir . '/' . sanitize_file_name( $zip_name ) . '.zip';

		$zip = new ZipArchive();
		try {
			if ( $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) !== true ) {
				$this->logger->error( 'Failed to create ZIP file', array( 'zip_path' => $zip_path ) );
				$this->delete_directory( $source_dir );
				return false;
			}

			$all_files         = array();
			$format_extensions = array(
				'docx'     => 'docx',
				'pdf'      => 'pdf',
				'html'     => 'html',
				'markdown' => 'md',
			);

			foreach ( $formats as $format ) {
				// Finding #7 fix: Only allow known, safe extensions to prevent glob injection.
				$ext = isset( $format_extensions[ $format ] ) ? $format_extensions[ $format ] : null;
				if ( ! $ext ) {
					continue;
				}
				$found = glob( $source_dir . '/*.' . $ext );
				if ( $found ) {
					$all_files[ $format ] = $found;
				}
			}

			if ( empty( $all_files ) ) {
				$this->logger->error( 'No export files found in source directory', array( 'source_dir' => $source_dir ) );
				$this->delete_directory( $source_dir );
				return false;
			}

			$use_folders = count( $formats ) > 1;

			foreach ( $all_files as $format => $files ) {
				$folder_name = strtoupper( $format );

				foreach ( $files as $file ) {
					if ( $use_folders ) {
						$zip->addFile( $file, $folder_name . '/' . basename( $file ) );
					} else {
						$zip->addFile( $file, basename( $file ) );
					}
				}
			}
		} finally {
			$zip->close();
		}

		$this->delete_directory( $source_dir );

		// Finding #5 fix: Use a transient-based lock to prevent race conditions during indexing.
		$lock_key = 'sscribe_export_index_lock';
		$locked   = false;
		$timeout  = 5; // seconds
		$start    = time();

		while ( time() - $start < $timeout ) {
			if ( add_transient( $lock_key, '1', 10 ) ) {
				$locked = true;
				break;
			}
			usleep( 50000 ); // 50ms
		}

		$exports                          = get_option( 'sscribe_export_index', array() );
		$exports[ basename( $zip_path ) ] = array(
			'created_at' => time(),
			'user_id'    => get_current_user_id(),
			'formats'    => $formats,
		);
		update_option( 'sscribe_export_index', $exports, false );

		if ( $locked ) {
			delete_transient( $lock_key );
		}

		return file_exists( $zip_path ) ? $zip_path : false;
	}

	/**
	 * Get the admin-ajax download URL.
	 *
	 * @param string $zip_filename The ZIP filename.
	 * @return string AJAX download URL.
	 */
	public function get_ajax_download_url( string $zip_filename ): string {
		return add_query_arg(
			array(
				'action' => 'sscribe_download',
				'file'   => sanitize_file_name( $zip_filename ),
				'nonce'  => wp_create_nonce( 'sscribe_download' ),
			),
			admin_url( 'admin-ajax.php' )
		);
	}

	/**
	 * Cleanup expired export files (older than 24 hours).
	 *
	 * @return int Number of files cleaned up.
	 */
	public function cleanup_expired(): int {
		$cleaned = 0;
		$files   = glob( $this->export_dir . '/*.zip' );

		if ( empty( $files ) ) {
			// Also clean up any stale temp directories.
			return $this->cleanup_stale_temp_dirs();
		}

		$max_age  = 3 * DAY_IN_SECONDS;
		$now      = time();
		$exports  = get_option( 'sscribe_export_index', array() );
		$modified = false;

		foreach ( $files as $file ) {
			$file_time = filemtime( $file );
			if ( $file_time && ( $now - $file_time ) > $max_age ) {
				wp_delete_file( $file );
				unset( $exports[ basename( $file ) ] );
				$modified = true;
				++$cleaned;
			}
		}

		if ( $modified ) {
			update_option( 'sscribe_export_index', $exports, false );
		}

		return $cleaned + $this->cleanup_stale_temp_dirs();
	}

	/**
	 * Clean up stale temporary directories older than 24 hours.
	 *
	 * @return int Number of directories cleaned up.
	 */
	private function cleanup_stale_temp_dirs(): int {
		$cleaned = 0;
		$max_age = 3 * DAY_IN_SECONDS;
		$now     = time();

		$temp_dirs = glob( $this->export_dir . '/temp-*', GLOB_ONLYDIR );
		if ( $temp_dirs ) {
			foreach ( $temp_dirs as $temp_dir ) {
				$dir_time = filemtime( $temp_dir );
				if ( $dir_time && ( $now - $dir_time ) > $max_age ) {
					SScribe_Security::delete_directory( $temp_dir );
					++$cleaned;
				}
			}
		}

		return $cleaned;
	}

	/**
	 * Recursively delete a directory and its contents.
	 *
	 * @param string $dir Directory path.
	 * @return bool
	 */
	public function delete_directory( string $dir ): bool {
		return SScribe_Security::delete_directory( $dir );
	}
}
