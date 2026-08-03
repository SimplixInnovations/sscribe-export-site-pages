<?php
/**
 * SScribe ZIP Handler
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
 * Handles ZIP archive creation and cleanup for export packages.
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
	 * Cached download nonce for the current request context.
	 *
	 * @var string|null
	 */
	private ?string $cached_nonce = null;

	/**
	 * Initialize the ZIP handler.
	 */
	public function __construct() {
		$this->logger = SScribe_Logger::instance( SScribe_Logger::is_logging_enabled() );
		$upload_dir   = wp_upload_dir();
		if ( ! empty( $upload_dir['error'] ) ) {
			$this->export_dir = '';
			$this->logger->warning(
				'wp_upload_dir() returned an error : export directory unavailable',
				array( 'error' => $upload_dir['error'] )
			);
			return;
		}
		$this->export_dir = $upload_dir['basedir'] . '/sscribe-exports';
	}

	/**
	 * Get the export directory, creating it if needed.
	 *
	 * @return string
	 * @throws \InvalidArgumentException When export directory is unavailable.
	 */
	public function get_export_dir(): string {
		if ( '' === $this->export_dir ) {
			throw new \InvalidArgumentException(
				'Export directory unavailable: wp_upload_dir() failed during initialization.'
			);
		}
		if ( ! file_exists( $this->export_dir ) ) {
			SScribe_Security::protect_directory( $this->export_dir );
		} elseif ( ! file_exists( $this->export_dir . '/.htaccess' ) ) {

			SScribe_Security::protect_directory( $this->export_dir );
		}
		return $this->export_dir;
	}

	/**
	 * Check if the export directory is available.
	 *
	 * @return bool True if the export directory was successfully initialized.
	 */
	public function is_available(): bool {
		return '' !== $this->export_dir;
	}

	/**
	 * Create a temporary working directory.
	 *
	 * @return string
	 */
	public function create_temp_dir(): string {
		$export_dir    = $this->get_export_dir();
		$random_suffix = bin2hex( random_bytes( 6 ) );
		$temp_dir      = $export_dir . '/temp-' . $random_suffix;
		wp_mkdir_p( $temp_dir );
		return $temp_dir;
	}

	/**
	 * Create a ZIP archive from export files.
	 *
	 * @param string $source_dir    Source directory path.
	 * @param string $zip_name      ZIP filename (auto-generated if empty).
	 * @param array  $formats       Export formats to include.
	 * @param bool   $has_language  Whether language metadata is available.
	 * @param array  $lang_metadata Language metadata array.
	 * @param string $session_id    Session identifier for locking.
	 * @return string|false ZIP file path or false on failure.
	 * @throws \Throwable Re-thrown from the assembly try/catch when ZIP
	 *                   creation fails after a successful open; the caller
	 *                   is responsible for cleaning up the temp file.
	 */
	public function create_zip( string $source_dir, string $zip_name = '', array $formats = array( 'docx' ), bool $has_language = true, array $lang_metadata = array(), string $session_id = '' ): string|false {
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

		$all_files         = array();
		$format_extensions = array(
			'docx'     => 'docx',
			'pdf'      => 'pdf',
			'html'     => 'html',
			'markdown' => 'md',
		);

		foreach ( $formats as $format ) {
			$ext = isset( $format_extensions[ $format ] ) ? $format_extensions[ $format ] : null;
			if ( ! $ext ) {
				continue;
			}

			$found = glob( $source_dir . '/*/*.' . $ext );
			if ( $found ) {
				$all_files[ $format ] = $found;
			}
		}

		if ( empty( $all_files ) ) {
			$this->logger->error( 'No export files found in source directory', array( 'source_dir' => $source_dir ) );
			$this->delete_directory( $source_dir );
			return false;
		}

		$all_file_paths = array();
		foreach ( $all_files as $format_files ) {
			foreach ( $format_files as $file_path ) {
				$all_file_paths[] = $file_path;
			}
		}
		$total_source_size = array_sum( array_map( 'filesize', $all_file_paths ) );
		$available_space = false;
		if ( function_exists( 'disk_free_space' ) ) {
			try {
				$available_space = @disk_free_space( $this->export_dir );
			} catch ( \Throwable $e ) {
				$available_space = false;
			}
		}
		if ( false !== $available_space && $total_source_size > ( $available_space * 0.95 ) ) {
			$this->logger->error(
				'Insufficient disk space to create ZIP archive',
				array(
					'required_bytes'    => $total_source_size,
					'available_bytes'   => $available_space,
				)
			);
			$this->delete_directory( $source_dir );
			return false;
		}

		$zip        = new ZipArchive();
		$zip_opened = false;

		// Staging ZIP lives INSIDE the export directory so the final
		// move stays on a single filesystem. Cross-directory renames
		// can silently drop the file on Windows (rename returns true
		// but the destination is empty) and the default-deny posture
		// rejects writes to sys_get_temp_dir() anyway. A `.tmp` suffix
		// keeps it out of the cleanup glob + download list until it is
		// atomically renamed to the final basename in the same dir.
		$random_suffix   = bin2hex( random_bytes( 6 ) );
		$tmp_zip         = $this->export_dir . '/.tmp-sscribe-' . $random_suffix . '.zip';
		$assembly_failed = false;
		try {
			if ( $zip->open( $tmp_zip, ZipArchive::CREATE | ZipArchive::OVERWRITE ) !== true ) {
				$this->logger->error( 'Failed to create ZIP file', array( 'zip_path' => $zip_path ) );
				$this->delete_directory( $source_dir );

				if ( file_exists( $tmp_zip ) ) {
					wp_delete_file( $tmp_zip );
				}
				return false;
			}
			$zip_opened = true;

			$use_folders = count( $formats ) > 1;

			$use_lang_folders = (bool) $has_language;

			$this->logger->debug(
				'ZIP folder structure config',
				array(
					'use_folders'      => $use_folders,
					'use_lang_folders' => $use_lang_folders,
					'has_language'     => $has_language,
					'formats'          => $formats,
					'total_files'      => array_sum( array_map( 'count', $all_files ) ),
				)
			);

			$zip_entries = array();

			foreach ( $all_files as $format => $files ) {
				$folder_name = strtoupper( $format );

				foreach ( $files as $file ) {
					$basename      = basename( $file );

					$parent_dir    = strtoupper( basename( dirname( $file ) ) );
					$lang_code     = $parent_dir;

					$archive_entry = preg_replace( '/[\/\\\\:*?"<>|]/', '-', $basename );

					if ( in_array( $basename, array( 'index.php', '.htaccess' ), true ) ) {
						continue;
					}

					if ( $use_lang_folders ) {
						$archive_entry = $folder_name . '/' . $lang_code . '/' . $archive_entry;
					} elseif ( $use_folders ) {
						$archive_entry = $folder_name . '/' . $archive_entry;
					}

					if ( ! $zip->addFile( $file, $archive_entry ) ) {
						$this->logger->warning(
							'Failed to add file to ZIP',
							array(
								'file'  => $file,
								'entry' => $archive_entry,
								'zip'   => basename( $zip_path ),
							)
						);
						continue;
					}
					$zip_entries[] = array(
						'source'    => $basename,
						'zip_path'  => $archive_entry,
						'lang_code' => $lang_code,
						'size'      => file_exists( $file ) ? (int) filesize( $file ) : 0,
					);
				}
			}

			foreach ( $formats as $format ) {
				$ext = isset( $format_extensions[ $format ] ) ? $format_extensions[ $format ] : null;
				if ( ! $ext ) {
					continue;
				}
				$files_for_format = isset( $all_files[ $format ] ) ? $all_files[ $format ] : array();
				if ( empty( $files_for_format ) ) {
					$failure_msg  = "Export format: {$format}\n";
					$failure_msg .= "Status: FAILED : no files generated for this format.\n";
					$failure_msg .= 'Please check the debug log for error details.';
					$manifest_name = strtoupper( $format ) . '_EXPORT_FAILED.txt';
					$zip->addFromString( $manifest_name, $failure_msg );
				}
			}

			$this->logger->debug(
				'ZIP entries created',
				array(
					'entry_count' => count( $zip_entries ),
				)
			);
		} catch ( \Throwable $e ) {
			$assembly_failed = true;
			throw $e;
		} finally {

			if ( $zip_opened ) {
				$zip->close();
			}

			if ( $assembly_failed && file_exists( $tmp_zip ) ) {
				wp_delete_file( $tmp_zip );
			}
		}

		if ( file_exists( $tmp_zip ) && filesize( $tmp_zip ) > 0 ) {
			// Both staging and final paths live in $this->export_dir, so
			// the rename stays on one filesystem. No cross-drive move,
			// no Windows drop. Clean up the staging file on rename failure
			// rather than serving a `.tmp` URL.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- WP_Filesystem unavailable; zip finalization.
			if ( ! @rename( $tmp_zip, $zip_path ) ) {
				$this->logger->error(
					'Failed to move staging ZIP to final location : zip discarded',
					array(
						'temp_zip'  => $tmp_zip,
						'final_zip' => $zip_path,
					)
				);
				wp_delete_file( $tmp_zip );
				$this->delete_directory( $source_dir );
				return false;
			}
		} elseif ( file_exists( $tmp_zip ) ) {
			wp_delete_file( $tmp_zip );
		}

		$this->delete_directory( $source_dir );

		$lock_key         = 'sscribe_index_lock';
		$locked           = false;
		$lock_using_cache = wp_using_ext_object_cache();
		$lock_attempts    = array( 100000, 200000, 400000 );

		foreach ( $lock_attempts as $lock_delay ) {
			if ( $lock_using_cache ) {
				if ( wp_cache_add( $lock_key, time(), 'transient', 30 ) ) {
					$locked = true;
					break;
				}
			} elseif ( set_transient( $lock_key, time(), 30 ) ) {
				$locked = true;
				break;
			}
			usleep( $lock_delay );
		}

		if ( ! $locked ) {
			$this->logger->warning(
				'Export indexing skipped - could not acquire exclusive lock (concurrent finalize detected)',
				array( 'zip' => basename( $zip_path ) )
			);

			return file_exists( $zip_path ) ? $zip_path : false;
		}

		if ( ! $this->verify_zip_integrity( $zip_path ) ) {
			$this->logger->error(
				'ZIP verification failed before return',
				array( 'zip_path' => $zip_path )
			);
			wp_delete_file( $zip_path );
			if ( $lock_using_cache ) {
				wp_cache_delete( $lock_key, 'transient' );
			}
			delete_transient( $lock_key );
			return false;
		}

		try {
			$basename = basename( $zip_path );
			$row      = array(
				'created_at' => time(),
				'user_id'    => get_current_user_id(),
				'formats'    => $formats,
				'lang_code'  => $lang_metadata['lang_code'] ?? '',
				'lang_name'  => $lang_metadata['lang_name'] ?? '',
				'flag_url'   => $lang_metadata['flag_url'] ?? '',
				'session_id' => $session_id,
			);

			update_option( 'sscribe_export_row_' . md5( $basename ), $row, false );

			$index   = get_option( 'sscribe_export_index', array() );
			$index[] = $basename;
			$index   = array_values( array_unique( $index ) );

			if ( count( $index ) > 50 ) {
				$removed = array_slice( $index, 0, count( $index ) - 50 );
				$index   = array_slice( $index, -50 );
				foreach ( $removed as $removed_basename ) {
					delete_option( 'sscribe_export_row_' . md5( (string) $removed_basename ) );
					$file_path = $this->export_dir . '/' . ltrim( (string) $removed_basename, '/\\' );
					if ( file_exists( $file_path ) ) {
						wp_delete_file( $file_path );
					}
				}
			}

			update_option( 'sscribe_export_index', $index, false );
		} finally {

			if ( $lock_using_cache ) {
				wp_cache_delete( $lock_key, 'transient' );
			} else {
				delete_transient( $lock_key );
			}
		}

		return file_exists( $zip_path ) ? $zip_path : false;
	}

	/**
	 * Verify ZIP file integrity after creation.
	 *
	 * @param string $zip_path Path to the ZIP file.
	 * @return bool True if ZIP is valid and readable.
	 */
	private function verify_zip_integrity( string $zip_path ): bool {
		if ( ! file_exists( $zip_path ) || ! is_readable( $zip_path ) ) {
			return false;
		}

		$zip    = new ZipArchive();

		$readonly_mode = defined( 'ZipArchive::READONLY' ) ? ZipArchive::READONLY : 1;
		$result = $zip->open( $zip_path, $readonly_mode );
		if ( true !== $result ) {
			$this->logger->error(
				'ZIP integrity verification failed',
				array(
					'zip_path' => $zip_path,
					'error'    => $zip->getStatusString(),
				)
			);
			return false;
		}

		$num_files = $zip->numFiles; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		if ( $num_files < 1 ) {
			$zip->close();
			$this->logger->error(
				'ZIP contains no files',
				array(
					'zip_path' => $zip_path,
				)
			);
			return false;
		}

		$first_file_entry = null;
		for ( $i = 0; $i < $num_files; $i++ ) {
			$entry = $zip->statIndex( $i );
			if ( ! $entry || empty( $entry['name'] ) ) {
				continue;
			}

			if ( substr( $entry['name'], -1 ) === '/' ) {
				continue;
			}
			$first_file_entry = $entry;
			break;
		}
		$zip->close();

		if ( ! $first_file_entry ) {
			$this->logger->error(
				'ZIP appears truncated or corrupted : no readable file entries found',
				array(
					'zip_path'    => $zip_path,
					'total_index' => $num_files,
				)
			);
			return false;
		}

		if ( 0 === $first_file_entry['size'] ) {
			$this->logger->error(
				'ZIP appears truncated or corrupted : first file entry is empty',
				array(
					'zip_path'      => $zip_path,
					'first_entry'   => $first_file_entry,
					'total_index'   => $num_files,
				)
			);
			return false;
		}

		return true;
	}

	/**
	 * Look up a single export entry by filename.
	 *
	 * @param string $zip_filename Basename of the ZIP.
	 * @return array<string, mixed>|null Row data, or null if not present.
	 */
	public function get_export_entry( string $zip_filename ): ?array {
		$row = get_option( 'sscribe_export_row_' . md5( $zip_filename ), null );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * List export entries in newest-first order.
	 *
	 * Reads from per-row options so the index does not need to be
	 * rewritten on every write : the option containing just the
	 * basenames is bounded in size.
	 *
	 * @return array<string, array<string, mixed>> Map of basename => row data.
	 */
	public function list_export_entries(): array {
		$index   = get_option( 'sscribe_export_index', array() );
		$entries = array();
		foreach ( (array) $index as $basename ) {
			$row = $this->get_export_entry( (string) $basename );
			if ( null !== $row ) {
				$entries[ (string) $basename ] = $row;
			}
		}
		return $entries;
	}

	/**
	 * Get the AJAX download URL for a ZIP file.
	 *
	 * @param string $zip_filename ZIP filename.
	 * @return string
	 */
	public function get_ajax_download_url( string $zip_filename ): string {
		if ( ! is_user_logged_in() ) {
			return '';
		}
		if ( null === $this->cached_nonce ) {
			$this->cached_nonce = wp_create_nonce( 'sscribe_download' );
		}
		return add_query_arg(
			array(
				'action' => 'sscribe_download',
				'file'   => $zip_filename,
				'nonce'  => $this->cached_nonce,
			),
			admin_url( 'admin-ajax.php' )
		);
	}

	/**
	 * Clean up expired export files.
	 *
	 * @return int Number of cleaned items.
	 */
	public function cleanup_expired(): int {
		if ( get_transient( 'sscribe_cron_exports_lock' ) ) {
			return 0;
		}
		set_transient( 'sscribe_cron_exports_lock', true, 5 * MINUTE_IN_SECONDS );

		try {
			$cleaned  = 0;
			$files    = glob( $this->export_dir . '/*.zip' ) ?: array();
			$max_age  = 3 * DAY_IN_SECONDS;
			$now      = time();
			$exports  = get_option( 'sscribe_export_index', array() );
			$modified = false;

			foreach ( (array) $exports as $basename ) {
				$basename   = (string) $basename;
				$file_path  = $this->export_dir . '/' . ltrim( $basename, '/\\' );

				if ( ! file_exists( $file_path ) ) {
					$exports    = array_values(
						array_filter(
							(array) $exports,
							static function ( $b ) use ( $basename ) {
								return (string) $b !== $basename;
							}
						)
					);
					delete_option( 'sscribe_export_row_' . md5( $basename ) );
					SScribe_Export_Log::delete_by_filename( $basename );
					$modified = true;
					++$cleaned;
					continue;
				}

				$file_time = filemtime( $file_path );
				if ( $file_time && ( $now - $file_time ) > $max_age ) {
					wp_delete_file( $file_path );
					$exports    = array_values(
						array_filter(
							(array) $exports,
							static function ( $b ) use ( $basename ) {
								return (string) $b !== $basename;
							}
						)
					);
					delete_option( 'sscribe_export_row_' . md5( $basename ) );
					SScribe_Export_Log::delete_by_filename( $basename );
					$modified = true;
					++$cleaned;
				}
			}

			if ( ! empty( $files ) ) {
				$indexed_basenames = array_keys( $exports );
				foreach ( $files as $file_path ) {
					$basename = basename( $file_path );

					if ( ! in_array( $basename, $indexed_basenames, true ) ) {
						$file_time = filemtime( $file_path );
						if ( $file_time && ( $now - $file_time ) > $max_age ) {
							wp_delete_file( $file_path );
							SScribe_Export_Log::delete_by_filename( $basename );
							++$cleaned;
						}
					}
				}
			}

			if ( $modified ) {
				update_option( 'sscribe_export_index', $exports, false );
			}

			$cleaned += $this->cleanup_stale_temp_dirs();

			return $cleaned;
		} finally {
			delete_transient( 'sscribe_cron_exports_lock' );
		}
	}

	/**
	 * Clean up stale temporary directories.
	 *
	 * @return int Number of cleaned directories.
	 */
	private function cleanup_stale_temp_dirs(): int {
		$cleaned = 0;
		$max_age = 3 * DAY_IN_SECONDS;
		$now     = time();

		$temp_dirs = glob( $this->export_dir . '/temp-*', GLOB_ONLYDIR ) ?: array();
		if ( ! empty( $temp_dirs ) ) {
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
	 * Delete a directory and its contents.
	 *
	 * @param string $dir Directory path.
	 * @return bool
	 */
	public function delete_directory( string $dir ): bool {
		return SScribe_Security::delete_directory( $dir );
	}
}
