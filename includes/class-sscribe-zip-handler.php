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
	private string $export_dir;

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
	 * Per-request export metadata cache keyed by normalized basename.
	 *
	 * @var array<string, array<string, mixed>|null>
	 */
	private array $export_row_cache = array();

	/**
	 * Initialize the ZIP handler.
	 */
	public function __construct() {
		$this->logger = SScribe_Logger::instance( SScribe_Logger::is_logging_enabled() );
		$export_dir   = SScribe_Private_Storage::get_export_dir();
		if ( '' === $export_dir ) {
			$this->export_dir = '';
			$this->logger->warning(
				'Private export storage is unavailable'
			);
			return;
		}
		$this->export_dir = $export_dir;
	}

	/**
	 * Get the export directory, creating it if needed.
	 *
	 * @return string
	 * @throws \InvalidArgumentException When export directory is unavailable.
	 */
	public function get_export_dir(): string {
		$this->export_dir = SScribe_Private_Storage::get_export_dir();
		if ( '' === $this->export_dir ) {
			throw new \InvalidArgumentException(
				'Export directory unavailable: no safe private storage path is writable.'
			);
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
	 * @throws \InvalidArgumentException|\RuntimeException When the workspace is unavailable.
	 */
	public function create_temp_dir(): string {
		$export_dir    = $this->get_export_dir();
		$random_suffix = bin2hex( random_bytes( 6 ) );
		$temp_dir      = $export_dir . '/temp-' . $random_suffix;
		if ( ! wp_mkdir_p( $temp_dir ) || ! is_dir( $temp_dir ) ) {
			throw new \RuntimeException( 'Unable to create the SScribe export working directory.' );
		}
		SScribe_Private_Storage::harden_directory( $temp_dir );
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
		try {
			$this->get_export_dir();
		} catch ( \InvalidArgumentException $e ) {
			$this->logger->error( 'Export directory unavailable during ZIP creation' );
			return false;
		}

		$source_dir = $this->resolve_temp_directory( $source_dir );
		if ( '' === $source_dir ) {
			$this->logger->error( 'Rejected ZIP source directory outside the SScribe export workspace' );
			return false;
		}

		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->logger->error( 'ZipArchive not available' );
			$this->delete_directory( $source_dir );
			return false;
		}

		if ( empty( $zip_name ) ) {
			$random_suffix = bin2hex( random_bytes( 3 ) );
			$zip_name      = 'sscribe-export-' . gmdate( 'Y-m-d-His' ) . '-' . $random_suffix;
		}

		$zip_stem = sanitize_file_name( $zip_name );
		$zip_stem = preg_replace( '/[^A-Za-z0-9._-]+/', '-', $zip_stem ) ?? '';
		$zip_stem = trim( $zip_stem, '.-_' );
		$zip_stem = substr( $zip_stem, 0, 180 );
		if ( '' === $zip_stem ) {
			$zip_stem = 'sscribe-export-' . gmdate( 'Y-m-d-His' ) . '-' . bin2hex( random_bytes( 3 ) );
		}

		$zip_path = $this->export_dir . '/' . $zip_stem . '.zip';
		if ( file_exists( $zip_path ) || is_link( $zip_path ) ) {
			$zip_path = $this->export_dir . '/' . substr( $zip_stem, 0, 171 ) . '-' . bin2hex( random_bytes( 4 ) ) . '.zip';
		}

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
				$found = array_values(
					array_filter(
						$found,
						fn( string $file ): bool => $this->is_safe_source_file( $file, $source_dir )
					)
				);
				if ( ! empty( $found ) ) {
					$all_files[ $format ] = $found;
				}
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

		// Acquire the export-index lock BEFORE the build as a fast-fail gate.
		// Long archive assembly can outlive this initial lease, so ownership
		// is renewed with an exact-value compare-and-swap before the archive
		// is published and again before row/index metadata is committed.
		$lock_manager = new SScribe_Export_Lock_Manager( $this->logger );
		$lock_name    = 'export-index';
		$lock_token   = $lock_manager->acquire_lock( $lock_name, 120, 115 );

		if ( null === $lock_token ) {
			$this->logger->error(
				'Export package could not be queued because the export index is busy',
				array( 'zip' => basename( $zip_path ) )
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
		$random_suffix   = '';
		$tmp_zip         = '';
		$assembly_failed = false;
		try {
			// random_bytes() can throw on entropy exhaustion (rare, but
			// documented). Generating the suffix inside the try keeps the
			// cleanup path reachable on failure.
			$random_suffix = bin2hex( random_bytes( 6 ) );
			$tmp_zip       = $this->export_dir . '/.tmp-sscribe-' . $random_suffix . '.zip';
			if ( $zip->open( $tmp_zip, ZipArchive::CREATE | ZipArchive::OVERWRITE ) !== true ) {
				$this->logger->error( 'Failed to create ZIP file', array( 'zip_path' => $zip_path ) );
				$this->delete_directory( $source_dir );

				if ( file_exists( $tmp_zip ) ) {
					wp_delete_file( $tmp_zip );
				}
				$lock_manager->release_lock( $lock_name, $lock_token );
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
						$this->logger->error(
							'Failed to add file to ZIP; archive assembly aborted',
							array(
								'file'  => $file,
								'entry' => $archive_entry,
								'zip'   => basename( $zip_path ),
							)
						);
						$assembly_failed = true;
						break 2;
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
					if ( ! $zip->addFromString( $manifest_name, $failure_msg ) ) {
						$this->logger->error(
							'Failed to add export-failure manifest to ZIP; archive assembly aborted',
							array(
								'manifest' => $manifest_name,
								'zip'      => basename( $zip_path ),
							)
						);
						$assembly_failed = true;
						break;
					}
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
			$this->delete_directory( $source_dir );
			$lock_manager->release_lock( $lock_name, $lock_token );
			throw $e;
		} finally {

			if ( $zip_opened ) {
				$close_ok = $zip->close();
				if ( ! $close_ok ) {
					$assembly_failed = true;
					$this->logger->error(
						'Failed to finalize ZIP archive; archive assembly aborted',
						array( 'zip' => basename( $zip_path ) )
					);
				}
			}

			if ( $assembly_failed && file_exists( $tmp_zip ) ) {
				wp_delete_file( $tmp_zip );
			}
		}

		if ( $assembly_failed ) {
			$this->delete_directory( $source_dir );
			$lock_manager->release_lock( $lock_name, $lock_token );
			return false;
		}

		if ( ! $lock_manager->renew_lock( $lock_name, $lock_token, 120 ) ) {
			$this->logger->error(
				'Export package lost export-index ownership before archive publication',
				array( 'zip' => basename( $zip_path ) )
			);
			if ( file_exists( $tmp_zip ) ) {
				wp_delete_file( $tmp_zip );
			}
			$this->delete_directory( $source_dir );
			$lock_manager->release_lock( $lock_name, $lock_token );
			return false;
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
				$lock_manager->release_lock( $lock_name, $lock_token );
				return false;
			}
			SScribe_Private_Storage::harden_file( $zip_path );
		} elseif ( file_exists( $tmp_zip ) ) {
			wp_delete_file( $tmp_zip );
			$lock_manager->release_lock( $lock_name, $lock_token );
			$this->delete_directory( $source_dir );
			return false;
		}

		$this->delete_directory( $source_dir );

		if ( ! $this->verify_zip_integrity( $zip_path ) ) {
			$this->logger->error(
				'ZIP verification failed before return',
				array( 'zip_path' => $zip_path )
			);
			wp_delete_file( $zip_path );
			$lock_manager->release_lock( $lock_name, $lock_token );
			return false;
		}

		if ( ! $lock_manager->renew_lock( $lock_name, $lock_token, 120 ) ) {
			$this->logger->error(
				'Export package lost export-index ownership before metadata publication',
				array( 'zip' => basename( $zip_path ) )
			);
			wp_delete_file( $zip_path );
			$lock_manager->release_lock( $lock_name, $lock_token );
			return false;
		}

		try {
			$basename = basename( $zip_path );
			$row      = array(
				'created_at'  => time(),
				'user_id'     => get_current_user_id(),
				'formats'     => $formats,
				'lang_code'   => $lang_metadata['lang_code'] ?? '',
				'lang_name'   => $lang_metadata['lang_name'] ?? '',
				'flag_url'    => $lang_metadata['flag_url'] ?? '',
				'session_id'  => $session_id,
				'dl_token'    => $this->generate_dl_token(),
				'dl_token_at' => time(),
			);

			$row_option = 'sscribe_export_row_' . md5( $basename );
			$row_saved  = update_option( $row_option, $row, false );
			if ( ! $row_saved && get_option( $row_option, null ) !== $row ) {
				$this->logger->error(
					'Export metadata could not be persisted; ZIP discarded',
					array( 'filename' => $basename )
				);
				wp_delete_file( $zip_path );
				delete_option( $row_option );
				return false;
			}

			$index   = get_option( 'sscribe_export_index', array() );
			$index[] = $basename;
			$index   = array_values( array_unique( $index ) );
			$removed = array();

			if ( count( $index ) > 50 ) {
				$removed = array_slice( $index, 0, count( $index ) - 50 );
				$index   = array_slice( $index, -50 );
			}

			// Commit the replacement bounded index before destroying any history
			// it evicts. If persistence fails, the previous index and its files
			// remain intact and only this newly-created archive is rolled back.
			$index_saved = update_option( 'sscribe_export_index', $index, false );
			if ( ! $index_saved && get_option( 'sscribe_export_index', array() ) !== $index ) {
				$this->logger->error(
					'Export index could not be persisted; ZIP discarded',
					array( 'filename' => $basename )
				);
				delete_option( $row_option );
				wp_delete_file( $zip_path );
				return false;
			}

			foreach ( $removed as $removed_basename ) {
				$removed_basename = (string) $removed_basename;
				delete_option( 'sscribe_export_row_' . md5( $removed_basename ) );
				$removed_basename = $this->normalize_zip_filename( $removed_basename );
				if ( '' === $removed_basename ) {
					continue;
				}
				$file_path = $this->export_dir . '/' . $removed_basename;
				if ( file_exists( $file_path ) ) {
					wp_delete_file( $file_path );
				}
			}
		} finally {
			$lock_manager->release_lock( $lock_name, $lock_token );
		}

		return file_exists( $zip_path ) ? $zip_path : false;
	}

	/**
	 * Resolve a plugin-created temporary export directory.
	 *
	 * @param string $source_dir Candidate directory.
	 * @return string Canonical directory path, or an empty string when unsafe.
	 */
	private function resolve_temp_directory( string $source_dir ): string {
		if ( '' === $source_dir || is_link( $source_dir ) || ! is_dir( $source_dir ) ) {
			return '';
		}

		$export_real = realpath( $this->export_dir );
		$source_real = realpath( $source_dir );
		if ( false === $export_real || false === $source_real ) {
			return '';
		}

		$safe_prefix = rtrim( $export_real, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR;
		if ( ! str_starts_with( $source_real, $safe_prefix ) || ! str_starts_with( basename( $source_real ), 'temp-' ) ) {
			return '';
		}

		return $source_real;
	}

	/**
	 * Check that a ZIP input is a regular file inside the working directory.
	 *
	 * @param string $file        Candidate file.
	 * @param string $source_root Canonical working directory.
	 * @return bool
	 */
	private function is_safe_source_file( string $file, string $source_root ): bool {
		if ( is_link( $file ) || ! is_file( $file ) ) {
			return false;
		}

		$file_real = realpath( $file );
		if ( false === $file_real ) {
			return false;
		}

		$safe_prefix = rtrim( $source_root, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR;
		return str_starts_with( $file_real, $safe_prefix );
	}

	/**
	 * Validate an export archive basename read from request or option data.
	 *
	 * @param string $filename Candidate filename.
	 * @return string Validated basename, or an empty string.
	 */
	private function normalize_zip_filename( string $filename ): string {
		$filename = trim( $filename );
		if (
			'' === $filename
			|| strlen( $filename ) > 204
			|| basename( str_replace( '\\', '/', $filename ) ) !== $filename
			|| 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]{0,199}\.zip$/D', $filename )
		) {
			return '';
		}

		return sanitize_file_name( $filename ) === $filename ? $filename : '';
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
		$zip_filename = $this->normalize_zip_filename( $zip_filename );
		if ( '' === $zip_filename ) {
			return null;
		}
		if ( array_key_exists( $zip_filename, $this->export_row_cache ) ) {
			return $this->export_row_cache[ $zip_filename ];
		}
		$row = get_option( 'sscribe_export_row_' . md5( $zip_filename ), null );
		$this->export_row_cache[ $zip_filename ] = is_array( $row ) ? $row : null;
		return $this->export_row_cache[ $zip_filename ];
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
		global $wpdb;

		$index = array_values(
			array_filter(
				array_map(
					fn( $basename ): string => $this->normalize_zip_filename( (string) $basename ),
					array_slice( (array) get_option( 'sscribe_export_index', array() ), -50 )
				)
			)
		);
		if ( empty( $index ) ) {
			return array();
		}

		$option_to_basename = array();
		foreach ( $index as $basename ) {
			$option_to_basename[ 'sscribe_export_row_' . md5( $basename ) ] = $basename;
		}
		$option_names = array_keys( $option_to_basename );
		$placeholders = implode( ',', array_fill( 0, count( $option_names ), '%s' ) );
		$sql          = "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name IN ({$placeholders})";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded internal metadata batch; exact option names are prepared.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$option_names ) );

		$loaded = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$option_name = isset( $row->option_name ) ? (string) $row->option_name : '';
			if ( ! isset( $option_to_basename[ $option_name ] ) ) {
				continue;
			}
			$value = isset( $row->option_value ) ? maybe_unserialize( $row->option_value ) : null;
			if ( is_array( $value ) ) {
				$loaded[ $option_to_basename[ $option_name ] ] = $value;
			}
		}

		$entries = array();
		foreach ( $index as $basename ) {
			$row = $loaded[ $basename ] ?? null;
			$this->export_row_cache[ $basename ] = $row;
			if ( null !== $row ) {
				$entries[ $basename ] = $row;
			}
		}
		return $entries;
	}

	/**
	 * Get the AJAX download URL for a ZIP file.
	 *
	 * The URL embeds the row's current single-use token (`token=...`).
	 * Multiple admin views may render that token without invalidating
	 * each other; the server rotates it only after a successful
	 * redemption. A replay with the consumed token therefore fails.
	 *
	 * @param string $zip_filename ZIP filename.
	 * @return string
	 */
	public function get_ajax_download_url( string $zip_filename ): string {
		$zip_filename = $this->normalize_zip_filename( $zip_filename );
		if ( '' === $zip_filename || ! is_user_logged_in() ) {
			return '';
		}
		if ( null === $this->cached_nonce ) {
			$this->cached_nonce = wp_create_nonce( 'sscribe_download' );
		}
		$row   = $this->get_export_entry( $zip_filename );
		$token = is_array( $row ) && isset( $row['dl_token'] ) && is_string( $row['dl_token'] )
			? $row['dl_token']
			: '';
		if ( 1 !== preg_match( '/^[a-f0-9]{32}$/', $token ) ) {
			$lock_manager = new SScribe_Export_Lock_Manager( $this->logger );
			$lock_name    = 'download-' . md5( $zip_filename );
			$lock_token   = $lock_manager->acquire_lock( $lock_name, 30, 25 );
			if ( null === $lock_token ) {
				return '';
			}
			try {
				$row   = get_option( 'sscribe_export_row_' . md5( $zip_filename ), null );
				$token = is_array( $row ) && isset( $row['dl_token'] ) && is_string( $row['dl_token'] )
					? $row['dl_token']
					: '';
				if ( 1 !== preg_match( '/^[a-f0-9]{32}$/', $token ) ) {
					$token = $this->rotate_dl_token( $zip_filename );
				}
			} finally {
				$lock_manager->release_lock( $lock_name, $lock_token );
			}
		}
		if ( '' === $token ) {
			return '';
		}
		$args = array(
			'action' => 'sscribe_download',
			'file'   => $zip_filename,
			'nonce'  => $this->cached_nonce,
			'token'  => $token,
		);
		return add_query_arg( $args, admin_url( 'admin-ajax.php' ) );
	}

	/**
	 * Rotate the per-row single-use download token.
	 *
	 * Generates a fresh 32-hex token, persists it on the export row,
	 * and returns the new value. URL generation calls this when a row
	 * has no valid token; successful redemption rotates the stored
	 * value before the archive is streamed.
	 *
	 * @param string $zip_filename ZIP basename.
	 * @return string New token, or empty string if the row is missing.
	 */
	public function rotate_dl_token( string $zip_filename ): string {
		$zip_filename = $this->normalize_zip_filename( $zip_filename );
		if ( '' === $zip_filename ) {
			return '';
		}
		$option_name = 'sscribe_export_row_' . md5( $zip_filename );
		$row         = get_option( $option_name, null );
		if ( ! is_array( $row ) ) {
			return '';
		}
		$token              = $this->generate_dl_token();
		$row['dl_token']    = $token;
		$row['dl_token_at'] = time();
		if ( update_option( $option_name, $row, false ) ) {
			$this->export_row_cache[ $zip_filename ] = $row;
			return $token;
		}
		return '';
	}

	/**
	 * Atomically validate and rotate a presented download token.
	 *
	 * A per-export atomic lock serializes the compare-and-rotate section.
	 * The compare is constant-time (hash_equals), and persistence fails
	 * closed before the caller is authorized to stream the archive.
	 *
	 * @param string $zip_filename ZIP basename.
	 * @param string $presented   Token presented in the URL.
	 * @return bool True when the token matched and has been rotated.
	 */
	public function consume_dl_token( string $zip_filename, string $presented ): bool {
		$zip_filename = $this->normalize_zip_filename( $zip_filename );
		if ( '' === $zip_filename || '' === $presented ) {
			return false;
		}

		$lock_manager = new SScribe_Export_Lock_Manager( $this->logger );
		$lock_name    = 'download-' . md5( $zip_filename );
		$lock_token   = $lock_manager->acquire_lock( $lock_name, 30, 25 );
		if ( null === $lock_token ) {
			$this->logger->debug(
				'Download token redemption already in progress',
				array( 'filename' => $zip_filename )
			);
			return false;
		}

		$option_name = 'sscribe_export_row_' . md5( $zip_filename );
		try {
			$row = get_option( $option_name, null );
			if ( ! is_array( $row ) ) {
				return false;
			}
			$stored = isset( $row['dl_token'] ) && is_string( $row['dl_token'] ) ? $row['dl_token'] : '';
			if ( '' === $stored || ! hash_equals( $stored, $presented ) ) {
				$this->logger->debug(
					'Download token row already consumed or absent',
					array( 'filename' => $zip_filename )
				);
				return false;
			}
			$row['dl_token']    = $this->generate_dl_token();
			$row['dl_token_at'] = time();
			if ( ! update_option( $option_name, $row, false ) ) {
				$this->logger->warning(
					'Download token rotation could not be persisted',
					array( 'filename' => $zip_filename )
				);
				return false;
			}
			return true;
		} finally {
			$lock_manager->release_lock( $lock_name, $lock_token );
		}
	}

	/**
	 * Generate a 32-character hexadecimal download token.
	 *
	 * @return string
	 */
	private function generate_dl_token(): string {
		try {
			return bin2hex( random_bytes( 16 ) );
		} catch ( \Throwable $e ) {
			return substr( hash( 'sha256', wp_generate_password( 64, true, true ) ), 0, 32 );
		}
	}

	/**
	 * Delete one user-owned export and its metadata atomically.
	 *
	 * @param string $zip_filename ZIP basename.
	 * @param int    $user_id      Expected owner ID.
	 * @return bool True when the export was removed or was already absent.
	 */
	public function delete_export( string $zip_filename, int $user_id ): bool {
		$zip_filename = $this->normalize_zip_filename( $zip_filename );
		if ( '' === $zip_filename || $user_id <= 0 || ! $this->is_available() ) {
			return false;
		}

		$lock_manager = new SScribe_Export_Lock_Manager( $this->logger );
		$lock_name    = 'export-index';
		$lock_token   = $lock_manager->acquire_lock( $lock_name, 30, 25 );
		if ( null === $lock_token ) {
			return false;
		}

		try {
			$row = $this->get_export_entry( $zip_filename );
			if ( null === $row || (int) ( $row['user_id'] ?? 0 ) !== $user_id ) {
				return false;
			}

			$export_real = realpath( $this->export_dir );
			if ( false === $export_real ) {
				return false;
			}

			$file_path = $this->export_dir . '/' . $zip_filename;
			if ( file_exists( $file_path ) || is_link( $file_path ) ) {
				if ( is_link( $file_path ) || ! is_file( $file_path ) ) {
					return false;
				}

				$file_real   = realpath( $file_path );
				$safe_prefix = rtrim( $export_real, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR;
				if ( false === $file_real || ! str_starts_with( $file_real, $safe_prefix ) ) {
					return false;
				}

				wp_delete_file( $file_real );
				if ( file_exists( $file_real ) ) {
					return false;
				}
			}

			delete_option( 'sscribe_export_row_' . md5( $zip_filename ) );
			$index = array_values(
				array_filter(
					(array) get_option( 'sscribe_export_index', array() ),
					static fn( $basename ): bool => (string) $basename !== $zip_filename
				)
			);
			update_option( 'sscribe_export_index', array_slice( $index, -50 ), false );
			SScribe_Export_Log::delete_by_filename( $zip_filename );

			return true;
		} finally {
			$lock_manager->release_lock( $lock_name, $lock_token );
		}
	}

	/**
	 * Clean up expired export files.
	 *
	 * @return int Number of cleaned items.
	 */
	public function cleanup_expired(): int {
		if ( ! $this->is_available() || ! is_dir( $this->export_dir ) ) {
			return 0;
		}

		$lock_manager = new SScribe_Export_Lock_Manager( $this->logger );
		$lock_name    = 'export-index';
		$lock_token   = $lock_manager->acquire_lock( $lock_name, 5 * MINUTE_IN_SECONDS, 290 );
		if ( null === $lock_token ) {
			return 0;
		}

		try {
			$cleaned  = 0;
			$files    = glob( $this->export_dir . '/*.zip' ) ?: array();
			$max_age  = 3 * DAY_IN_SECONDS;
			$now      = time();
			$exports  = get_option( 'sscribe_export_index', array() );
			$modified = false;

			foreach ( (array) $exports as $basename ) {
				$basename   = (string) $basename;
				$safe_basename = $this->normalize_zip_filename( $basename );
				if ( '' === $safe_basename ) {
					$exports = array_values(
						array_filter(
							(array) $exports,
							static fn( $item ): bool => (string) $item !== $basename
						)
					);
					delete_option( 'sscribe_export_row_' . md5( $basename ) );
					$modified = true;
					continue;
				}
				$basename  = $safe_basename;
				$file_path = $this->export_dir . '/' . $basename;

				if ( ! file_exists( $file_path ) && ! is_link( $file_path ) ) {
					$exports    = array_values(
						array_filter(
							(array) $exports,
							static function ( $b ) use ( $basename ) {
								return is_string( $b ) && $b !== $basename;
							}
						)
					);
					delete_option( 'sscribe_export_row_' . md5( $basename ) );
					SScribe_Export_Log::delete_by_filename( $basename );
					$modified = true;
					++$cleaned;
					continue;
				}

				if ( is_link( $file_path ) || ! is_file( $file_path ) ) {
					if ( is_link( $file_path ) ) {
						wp_delete_file( $file_path );
						if ( is_link( $file_path ) ) {
							continue;
						}
					}
					$exports = array_values(
						array_filter(
							(array) $exports,
							static fn( $item ): bool => (string) $item !== $basename
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
					if ( file_exists( $file_path ) ) {
						continue;
					}
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
				$indexed_basenames = array_values( array_filter( (array) $exports, 'is_string' ) );
				foreach ( $files as $file_path ) {
					$basename = basename( $file_path );

					if ( ! in_array( $basename, $indexed_basenames, true ) && ! is_link( $file_path ) && is_file( $file_path ) ) {
						$file_time = filemtime( $file_path );
						if ( $file_time && ( $now - $file_time ) > $max_age ) {
							wp_delete_file( $file_path );
							if ( ! file_exists( $file_path ) ) {
								SScribe_Export_Log::delete_by_filename( $basename );
								++$cleaned;
							}
						}
					}
				}
			}

			if ( $modified ) {
				update_option( 'sscribe_export_index', $exports, false );
			}

			$cleaned += $this->cleanup_stale_temp_dirs();
			$cleaned += SScribe_Image_Processor::cleanup_stale();

			return $cleaned;
		} finally {
			$lock_manager->release_lock( $lock_name, $lock_token );
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

		$temp_dirs    = glob( $this->export_dir . '/temp-*', GLOB_ONLYDIR ) ?: array();
		$scratch_dirs = glob( $this->export_dir . '/phpword-scratch/run-*', GLOB_ONLYDIR ) ?: array();
		$pdf_dirs     = glob( $this->export_dir . '/mpdf-tmp/run-*', GLOB_ONLYDIR ) ?: array();
		$temp_dirs    = array_merge( $temp_dirs, $scratch_dirs, $pdf_dirs );
		if ( ! empty( $temp_dirs ) ) {
			foreach ( $temp_dirs as $temp_dir ) {
				if ( is_link( $temp_dir ) || ! is_dir( $temp_dir ) ) {
					continue;
				}

				$dir_time = filemtime( $temp_dir );
				if ( false !== $dir_time && ( $now - $dir_time ) > $max_age && SScribe_Security::delete_directory( $temp_dir ) ) {
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
		$dir = $this->resolve_temp_directory( $dir );
		return '' !== $dir && SScribe_Security::delete_directory( $dir );
	}
}
