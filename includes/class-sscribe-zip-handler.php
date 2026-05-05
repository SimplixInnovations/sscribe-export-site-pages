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
	 * ZIP structure when multiple languages are exported:
	 *   FORMAT/LANG/P001-Title.ext (e.g., DOCX/AR/P001-Title.docx)
	 * ZIP structure when single language:
	 *   FORMAT/P001-Title.ext or just P001-Title.ext (flat)
	 *
	 * @param string $source_dir      Directory containing export files.
	 * @param string $zip_name        Desired ZIP filename (without extension).
	 * @param array  $formats         Export formats used.
	 * @param bool   $has_language    Whether a specific language was selected (false = all languages).
	 * @param array  $lang_metadata   Language metadata: lang_code, lang_name, flag_url.
	 * @return string|false Path to ZIP file or false on failure.
	 */
	public function create_zip( string $source_dir, string $zip_name = '', array $formats = array( 'docx' ), bool $has_language = true, array $lang_metadata = array() ): string|false {
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
			// When exporting all languages, organize into FORMAT/LANG/ subfolders.
			$use_lang_folders = ! $has_language;

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
					$archive_entry = $basename;
					$lang_code     = null;

					if ( $use_lang_folders ) {
						// Extract language code from filename: P001-Title-AR.docx → AR.
						// Pattern: ends with -XX.ext where XX is 2-letter language code.
						$lang_code = $this->extract_lang_from_filename( $basename );

						if ( $lang_code ) {
							// Remove language suffix from filename since it's in the folder path.
							$clean_name    = $this->remove_lang_from_filename( $basename );
							$archive_entry = $folder_name . '/' . $lang_code . '/' . $clean_name;
						} else {
							// No language code found — put in format folder directly.
							$archive_entry = $folder_name . '/' . $basename;
						}
					} elseif ( $use_folders ) {
						$archive_entry = $folder_name . '/' . $basename;
					}

					$zip->addFile( $file, $archive_entry );
					$zip_entries[] = array(
						'source'    => $basename,
						'zip_path'  => $archive_entry,
						'lang_code' => $lang_code ?? null,
						'size'      => file_exists( $file ) ? (int) filesize( $file ) : 0,
					);
				}
			}

			$this->logger->debug(
				'ZIP entries created',
				array(
					'entry_count' => count( $zip_entries ),
					'entries'     => array_slice( $zip_entries, 0, 20 ),
				)
			);
		} finally {
			$zip->close();
		}

		$this->delete_directory( $source_dir );

		// Use atomic transient-based lock with TTL to prevent race conditions during indexing.
		// set_transient() with expiry is crash-safe unlike add_option() (no TTL, permanent orphan).
		// Also clear any stale lock at start in case prior process crashed before cleanup.
		$lock_key = 'sscribe_index_lock_' . get_current_user_id();
		delete_transient( $lock_key ); // Clear any stale lock from crashed process.
		$locked   = false;
		$timeout  = 5; // Seconds.
		$start    = time();

		while ( time() - $start < $timeout ) {
			if ( set_transient( $lock_key, time(), 30 ) ) {
				$locked = true;
				break;
			}
			usleep( 50000 ); // 50ms
		}

		// SECURITY: If lock not acquired, abort indexing to prevent race condition.
		// The ZIP file exists but won't appear in history panel (acceptable degradation).
		if ( ! $locked ) {
			$this->logger->error( 'Failed to acquire export index lock - export created but not indexed' );
			return file_exists( $zip_path ) ? $zip_path : false;
		}

		try {
			$exports                          = get_option( 'sscribe_export_index', array() );
			$exports[ basename( $zip_path ) ] = array(
				'created_at' => time(),
				'user_id'    => get_current_user_id(),
				'formats'    => $formats,
				'lang_code'  => $lang_metadata['lang_code'] ?? '',
				'lang_name'  => $lang_metadata['lang_name'] ?? '',
				'flag_url'   => $lang_metadata['flag_url'] ?? '',
			);
			update_option( 'sscribe_export_index', $exports, false );
		} finally {
			delete_transient( $lock_key );
		}

		return file_exists( $zip_path ) ? $zip_path : false;
	}

	/**
	 * Extract language code from a filename like P001-Title-AR.docx.
	 *
	 * Looks for a 2-letter uppercase language code before the file extension.
	 * Only matches SINGLE language codes (e.g., AR, EN, FR) not compound codes.
	 * This prevents page slugs like "SD-AR" from being misidentified as "AR" language.
	 *
	 * @param string $filename The filename (basename only).
	 * @return string|null Language code (e.g., 'AR') or null if not found.
	 */
	private function extract_lang_from_filename( string $filename ): ?string {
		// Match -XX.ext where XX is EXACTLY 2 uppercase letters.
		if ( ! preg_match( '/-([A-Z]{2})\.[A-Za-z]+$/', $filename, $matches ) ) {
			return null;
		}

		// Validate against known 2-letter language codes to prevent
		// false matches on page title segments (e.g., P001-GO.docx).
		static $known_codes = array(
			'AR',
			'EN',
			'FR',
			'DE',
			'ES',
			'IT',
			'PT',
			'NL',
			'RU',
			'ZH',
			'JA',
			'KO',
			'HE',
			'FA',
			'UR',
			'TR',
			'PL',
			'SV',
			'DA',
			'FI',
			'NB',
			'CS',
			'SK',
			'HU',
			'RO',
			'BG',
			'HR',
			'SR',
			'UK',
			'VI',
			'TH',
			'ID',
			'MS',
			'EL',
			'HI',
			'BN',
			'LT',
			'LV',
			'ET',
			'SL',
		);

		return in_array( $matches[1], $known_codes, true ) ? $matches[1] : null;
	}

	/**
	 * Remove language code suffix from a filename.
	 *
	 * Converts P001-Title-AR.docx → P001-Title.docx.
	 *
	 * @param string $filename The filename (basename only).
	 * @return string Filename without language suffix.
	 */
	private function remove_lang_from_filename( string $filename ): string {
		// Use pathinfo to safely remove -XX suffix from filename base only.
		$parts = pathinfo( $filename );
		$base  = $parts['filename'];
		$ext   = isset( $parts['extension'] ) ? '.' . $parts['extension'] : '';

		// Remove trailing -XX ONLY when XX is EXACTLY 2 letters (language code).
		// This prevents misinterpreting page slug segments as language codes.
		// P001-SD-AR.docx → P001-SD-AR.docx (not P001-SD.docx).
		$clean_base = preg_replace( '/-[A-Z]{2}$/', '', $base );

		return $clean_base . $ext;
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
		if ( get_transient( 'sscribe_cron_exports_lock' ) ) {
			return 0;
		}
		set_transient( 'sscribe_cron_exports_lock', true, 2 * MINUTE_IN_SECONDS );

		try {
			$cleaned = 0;
			$files   = glob( $this->export_dir . '/*.zip' );

			if ( empty( $files ) ) {
				$cleaned = $this->cleanup_stale_temp_dirs();
			} else {
				$max_age  = 3 * DAY_IN_SECONDS;
				$now      = time();
				$exports  = get_option( 'sscribe_export_index', array() );
				$modified = false;

				foreach ( $files as $file ) {
					$file_time = filemtime( $file );
					if ( $file_time && ( $now - $file_time ) > $max_age ) {
						$basename = basename( $file );
						wp_delete_file( $file );
						unset( $exports[ $basename ] );
						SScribe_Export_Log::delete_by_filename( $basename );
						$modified = true;
						++$cleaned;
					}
				}

				if ( $modified ) {
					update_option( 'sscribe_export_index', $exports, false );
				}

				$cleaned += $this->cleanup_stale_temp_dirs();
			}

			return $cleaned;
		} finally {
			delete_transient( 'sscribe_cron_exports_lock' );
		}
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
