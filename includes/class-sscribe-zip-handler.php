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
				'wp_upload_dir() returned an error — export directory unavailable',
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
			// Directory exists but .htaccess was removed — re-apply protection.
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

		// Gather all exportable files first so we can validate disk space
		// before creating the ZIP archive.
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

		// Guard against disk exhaustion: ensure sufficient space before opening ZIP.
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
		// Build ZIP in a temp file first so that if any exception occurs during
		// assembly, the temp file is cleaned up by the catch/finally below.
		// PHP does NOT auto-delete temp files on exception, so we have to do it
		// ourselves to avoid orphaned partial ZIPs.
		$tmp_zip          = wp_tempnam( 'sscribe-export-' );
		$assembly_failed  = false;
		if ( false === $tmp_zip ) {
			$this->logger->error( 'Failed to create temp file for ZIP' );
			$this->delete_directory( $source_dir );
			return false;
		}
		try {
			if ( $zip->open( $tmp_zip, ZipArchive::CREATE | ZipArchive::OVERWRITE ) !== true ) {
				$this->logger->error( 'Failed to create ZIP file', array( 'zip_path' => $zip_path ) );
				$this->delete_directory( $source_dir );
				// Use wp_delete_file() for temp file cleanup (WP-recommended).
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
					// Preserve Unicode letters; strip only filesystem-unsafe characters.
					$archive_entry = preg_replace( '/[\/\\\\:*?"<>|]/', '-', $basename );
					$lang_code     = null;

					if ( in_array( $basename, array( 'index.php', '.htaccess' ), true ) ) {
						continue;
					}

					if ( $use_lang_folders ) {

						$lang_code = $this->extract_lang_from_filename( $basename );

						if ( $lang_code ) {

							$clean_name    = $this->remove_lang_from_filename( $basename );
							// Preserve Unicode letters; strip only filesystem-unsafe characters.
							$archive_entry = $folder_name . '/' . $lang_code . '/' . preg_replace( '/[\/\\\\:*?"<>|]/', '-', $clean_name );
						} else {

							$archive_entry = $folder_name . '/' . preg_replace( '/[\/\\\\:*?"<>|]/', '-', $basename );
						}
					} elseif ( $use_folders ) {
						$archive_entry = $folder_name . '/' . preg_replace( '/[\/\\\\:*?"<>|]/', '-', $basename );
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
						'lang_code' => $lang_code ?? null,
						'size'      => file_exists( $file ) ? (int) filesize( $file ) : 0,
					);
				}
			}

			// For each requested format that produced zero files, add a failure
			// manifest so the user knows the format was attempted but failed —
			// without this, a missing format is silent and confusing.
			foreach ( $formats as $format ) {
				$ext = isset( $format_extensions[ $format ] ) ? $format_extensions[ $format ] : null;
				if ( ! $ext ) {
					continue;
				}
				$files_for_format = isset( $all_files[ $format ] ) ? $all_files[ $format ] : array();
				if ( empty( $files_for_format ) ) {
					$failure_msg  = "Export format: {$format}\n";
					$failure_msg .= "Status: FAILED — no files generated for this format.\n";
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
			// Only call close() if open() actually succeeded.
			// Calling close() on a never-opened ZipArchive throws warnings on some PHP versions.
			if ( $zip_opened ) {
				$zip->close();
			}
			// If the assembly failed partway, delete the orphaned partial ZIP.
			// On success the temp file is still needed for the rename step
			// below — only delete when we know assembly didn't complete.
			if ( $assembly_failed && file_exists( $tmp_zip ) ) {
				wp_delete_file( $tmp_zip );
			}
		}

		// Move the completed ZIP from the temp file to its final destination.
		// If the move fails (e.g., disk full), fall back to the temp path.
		$zip_finalized = false;
		if ( file_exists( $tmp_zip ) && filesize( $tmp_zip ) > 0 ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Fallback when WP_Filesystem unavailable; zip finalization.
			if ( @rename( $tmp_zip, $zip_path ) ) {
				$zip_finalized = true;
			} else {
				$this->logger->warning(
					'Failed to move temp ZIP to final location — serving from temp path',
					array(
						'temp_zip'  => $tmp_zip,
						'final_zip' => $zip_path,
					)
				);
				$zip_path = $tmp_zip;
			}
			// Use wp_delete_file() for temp file cleanup (WP-recommended).
		} elseif ( isset( $tmp_zip ) && file_exists( $tmp_zip ) ) {
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

		// If lock could not be acquired, another request is likely writing.
		// Skip indexing but DO NOT delete the ZIP - it was successfully created
		// and deleting it causes silent data loss. The ZIP will be cleaned up
		// by cleanup_expired() or the user can access it directly.
		if ( ! $locked ) {
			$this->logger->warning(
				'Export indexing skipped - could not acquire exclusive lock (concurrent finalize detected)',
				array( 'zip' => basename( $zip_path ) )
			);
			// Return the path anyway so the caller has access to the successfully created ZIP.
			return file_exists( $zip_path ) ? $zip_path : false;
		}

		// Final integrity verification before returning.
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

		// Update the export index with the new ZIP.
		try {
			$exports                          = get_option( 'sscribe_export_index', array() );
			$exports[ basename( $zip_path ) ] = array(
				'created_at' => time(),
				'user_id'    => get_current_user_id(),
				'formats'    => $formats,
				'lang_code'  => $lang_metadata['lang_code'] ?? '',
				'lang_name'  => $lang_metadata['lang_name'] ?? '',
				'flag_url'   => $lang_metadata['flag_url'] ?? '',
				'session_id' => $session_id,
			);

			// Cap index at 50 entries to prevent wp_options bloat.
			if ( count( $exports ) > 50 ) {
				$removed = array_slice( $exports, 0, count( $exports ) - 50, true );
				$exports = array_slice( $exports, -50, 50, true );

				// Delete files for entries being removed from the index
				// to prevent orphaned files from accumulating.
				foreach ( $removed as $basename => $data ) {
					$file_path = $this->export_dir . '/' . ltrim( (string) $basename, '/\\' );
					if ( file_exists( $file_path ) ) {
						wp_delete_file( $file_path );
					}
				}
			}

			update_option( 'sscribe_export_index', $exports, false );
		} finally {
			// Only unlock using the method that was used to acquire the lock.
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
		// ZipArchive::READONLY available since PHP 7.4.3. Fallback to 1 (ZIPARCHIVE::READONLY)
		// for PHPStan which may not have this constant in its stubs.
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

		// Verify the ZIP contains at least one readable file entry.
		// A truncated ZIP can have numFiles > 0 but no readable entries.
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

		// Find the first ACTUAL file entry (skip directory entries which
		// have size === 0 by design). For multi-format exports (DOCX/, PDF/,
		// etc.) the first index is often a directory entry — checking it
		// for size > 0 would falsely flag the archive as truncated.
		$first_file_entry = null;
		for ( $i = 0; $i < $num_files; $i++ ) {
			$entry = $zip->statIndex( $i );
			if ( ! $entry || empty( $entry['name'] ) ) {
				continue;
			}
			// Directory entries end with '/'. Skip them — they have size 0
			// by design, not because the archive is corrupted.
			if ( substr( $entry['name'], -1 ) === '/' ) {
				continue;
			}
			$first_file_entry = $entry;
			break;
		}
		$zip->close();

		if ( ! $first_file_entry ) {
			$this->logger->error(
				'ZIP appears truncated or corrupted — no readable file entries found',
				array(
					'zip_path'    => $zip_path,
					'total_index' => $num_files,
				)
			);
			return false;
		}

		// First file entry must have content (size > 0) to be considered valid.
		if ( 0 === $first_file_entry['size'] ) {
			$this->logger->error(
				'ZIP appears truncated or corrupted — first file entry is empty',
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
	 * Extract language code from filename.
	 *
	 * @param string $filename Export filename.
	 * @return string|null Language code or null.
	 */
	private function extract_lang_from_filename( string $filename ): ?string {

		if ( ! preg_match( '/-([A-Z]{2,3})\.[A-Za-z]+$/', $filename, $matches ) ) {
			return null;
		}

		return in_array( $matches[1], SScribe_RTL_Helper::get_all_known_codes(), true ) ? $matches[1] : null;
	}

	/**
	 * Remove language code from filename.
	 *
	 * @param string $filename Export filename.
	 * @return string Cleaned filename.
	 */
	private function remove_lang_from_filename( string $filename ): string {

		$parts = pathinfo( $filename );
		$base  = $parts['filename'];
		$ext   = isset( $parts['extension'] ) ? '.' . $parts['extension'] : '';

		$clean_base = preg_replace( '/-[A-Z]{2,3}$/', '', $base ) ?? $base;

		return $clean_base . $ext;
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

			// Clean up ZIPs that are in the index first.
			foreach ( $exports as $basename => $data ) {
				$file_path = $this->export_dir . '/' . ltrim( (string) $basename, '/\\' );

				if ( ! file_exists( $file_path ) ) {
					unset( $exports[ $basename ] );
					SScribe_Export_Log::delete_by_filename( $basename );
					$modified = true;
					++$cleaned;
					continue;
				}

				$file_time = filemtime( $file_path );
				if ( $file_time && ( $now - $file_time ) > $max_age ) {
					wp_delete_file( $file_path );
					unset( $exports[ $basename ] );
					SScribe_Export_Log::delete_by_filename( $basename );
					$modified = true;
					++$cleaned;
				}
			}

			// Clean up ZIPs that exist on disk but are NOT in the index (orphaned files).
			if ( ! empty( $files ) ) {
				$indexed_basenames = array_keys( $exports );
				foreach ( $files as $file_path ) {
					$basename = basename( $file_path );
					// If basename is not in the index, it's orphaned and should be cleaned up.
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
