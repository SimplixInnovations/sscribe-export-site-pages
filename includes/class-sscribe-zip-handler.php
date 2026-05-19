<?php

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SScribe_Zip_Handler {

	private readonly string $export_dir;

	private readonly SScribe_Logger_Interface $logger;

	public function __construct() {
		$upload_dir = wp_upload_dir();
		if ( ! empty( $upload_dir['error'] ) ) {
			$this->export_dir = '';
			$this->logger     = SScribe_Logger::instance( defined( 'SSCRIBE_DEBUG' ) && SSCRIBE_DEBUG );
			$this->logger->warning(
				'wp_upload_dir() returned an error — export directory unavailable',
				array( 'error' => $upload_dir['error'] )
			);
			return;
		}
		$this->export_dir = $upload_dir['basedir'] . '/sscribe-exports';
		$this->logger     = SScribe_Logger::instance( defined( 'SSCRIBE_DEBUG' ) && SSCRIBE_DEBUG );
	}

	public function get_export_dir(): string {
		if ( ! file_exists( $this->export_dir ) ) {
			SScribe_Security::protect_directory( $this->export_dir );
		}
		return $this->export_dir;
	}

	public function create_temp_dir(): string {
		$random_suffix = bin2hex( random_bytes( 6 ) );
		$temp_dir      = $this->export_dir . '/temp-' . $random_suffix;
		wp_mkdir_p( $temp_dir );
		return $temp_dir;
	}

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

						$lang_code = $this->extract_lang_from_filename( $basename );

						if ( $lang_code ) {

							$clean_name    = $this->remove_lang_from_filename( $basename );
							$archive_entry = $folder_name . '/' . $lang_code . '/' . $clean_name;
						} else {

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

		$lock_key     = 'sscribe_index_lock';
		$locked       = false;
		$lock_using_cache = wp_using_ext_object_cache();
		$lock_attempts = array( 100000, 200000, 400000 );

		if ( false !== get_transient( $lock_key ) && ( time() - (int) get_transient( $lock_key ) ) > 30 ) {
			if ( $lock_using_cache ) {
				wp_cache_delete( $lock_key, 'transient' );
			}
			delete_transient( $lock_key );
		}

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

			if ( ! $locked ) {
				$this->logger->warning(
					'Export indexed without exclusive lock (possible race)',
					array( 'zip' => basename( $zip_path ) )
				);
			}
		} finally {
			if ( $locked ) {
				if ( $lock_using_cache ) {
					wp_cache_delete( $lock_key, 'transient' );
				}
				delete_transient( $lock_key );
			}
		}

		return file_exists( $zip_path ) ? $zip_path : false;
	}

	private function extract_lang_from_filename( string $filename ): ?string {

		if ( ! preg_match( '/-([A-Z]{2,3})\.[A-Za-z]+$/', $filename, $matches ) ) {
			return null;
		}

		return in_array( $matches[1], SScribe_RTL_Helper::get_all_known_codes(), true ) ? $matches[1] : null;
	}

	private function remove_lang_from_filename( string $filename ): string {

		$parts = pathinfo( $filename );
		$base  = $parts['filename'];
		$ext   = isset( $parts['extension'] ) ? '.' . $parts['extension'] : '';

		$clean_base = preg_replace( '/-[A-Z]{2,3}$/', '', $base ) ?? $base;

		return $clean_base . $ext;
	}

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

				foreach ( $exports as $basename => $data ) {
					$file_path = $this->export_dir . $basename;

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

	public function delete_directory( string $dir ): bool {
		return SScribe_Security::delete_directory( $dir );
	}
}
