<?php
/**
 * SScribe Export Log
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SScribe_Export_Log {

	/**
	 * Session identifier.
	 *
	 * @var string
	 */
	private readonly string $session_id;

	/**
	 * Log directory path.
	 *
	 * @var string
	 */
	private readonly string $log_dir;

	/**
	 * Log file path.
	 *
	 * @var string
	 */
	private readonly string $log_file;

	/**
	 * Cached log data.
	 *
	 * @var array|null
	 */
	private ?array $data_cache = null;

	/**
	 * Whether cached data has unsaved changes.
	 *
	 * @var bool
	 */
	private bool $dirty = false;

	/**
	 * Initialize the export log.
	 *
	 * @param string $session_id Session identifier.
	 */
	public function __construct( string $session_id ) {
		$upload_dir    = wp_upload_dir();
		$this->log_dir = $upload_dir['basedir'] . '/sscribe-logs';

		if ( preg_match( '/^[a-f0-9]{16}$/', $session_id ) ) {
			$this->session_id = $session_id;
		} else {
			$this->session_id = sanitize_file_name( $session_id );
		}
		$this->log_file = $this->log_dir . '/export_' . $this->session_id . '.json';

		$this->init_log();
	}

	/**
	 * Initialize the log file if it doesn't exist.
	 *
	 * @return void
	 */
	private function init_log(): void {
		if ( ! file_exists( $this->log_dir ) ) {
			SScribe_Security::protect_directory( $this->log_dir );
		}

		if ( ! file_exists( $this->log_file ) ) {
			$data = array(
				'session_id'   => $this->session_id,
				'created_at'   => current_time( 'mysql' ),
				'total_pages'  => 0,
				'processed'    => 0,
				'success'      => 0,
				'failed'       => 0,
				'pages'        => array(),
				'errors'       => array(),
				'status'       => 'started',
				'completed_at' => null,
			);
			$this->write_log( $data );
		}
	}

	/**
	 * Flush dirty cache to disk on destruction.
	 */
	public function __destruct() {
		$this->flush();
	}

	/**
	 * Flush cached data to disk if dirty.
	 *
	 * @return void
	 */
	public function flush(): void {
		if ( $this->dirty && null !== $this->data_cache ) {
			$json = wp_json_encode( $this->data_cache, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
			if ( false === $json ) {
				$this->dirty = false;
				return;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Intended logging file operation.

			$result = file_put_contents( $this->log_file, $json, LOCK_EX );
			if ( false !== $result ) {
				$this->dirty = false;
			}
		}
	}

	/**
	 * Set the total number of pages for this export.
	 *
	 * @param int $total Total page count.
	 * @return void
	 */
	public function set_total_pages( int $total ): void {
		$data                = $this->read_log();
		$data['total_pages'] = $total;
		$this->write_log( $data );
	}

	/**
	 * Log the start of processing a page.
	 *
	 * @param int    $page_id Page ID.
	 * @param string $title   Page title.
	 * @param string $slug    Page slug.
	 * @return void
	 */
	public function log_page_start( int $page_id, string $title, string $slug ): void {
		$data = $this->read_log();

		$data['pages'][ $page_id ] = array(
			'id'         => $page_id,
			'title'      => $title,
			'slug'       => $slug,
			'status'     => 'processing',
			'start_time' => microtime( true ),
			'end_time'   => null,
			'duration'   => null,
			'formats'    => array(),
			'error'      => null,
			'memory'     => size_format( memory_get_usage( true ) ),
		);

		$this->write_log( $data );
	}

	/**
	 * Log successful processing of a page.
	 *
	 * @param int   $page_id Page ID.
	 * @param array $formats Export formats.
	 * @return void
	 */
	public function log_page_success( int $page_id, array $formats ): void {
		$data = $this->read_log();

		if ( isset( $data['pages'][ $page_id ] ) ) {
			$end_time   = microtime( true );
			$start_time = $data['pages'][ $page_id ]['start_time'] ?? $end_time;

			$data['pages'][ $page_id ]['status']   = 'success';
			$data['pages'][ $page_id ]['end_time'] = $end_time;
			$data['pages'][ $page_id ]['duration'] = round( $end_time - $start_time, 3 );
			$data['pages'][ $page_id ]['memory']   = size_format( memory_get_usage( true ) );

			if ( empty( $data['pages'][ $page_id ]['formats'] ) ) {

				foreach ( $formats as $fmt ) {
					$data['pages'][ $page_id ]['formats'][ $fmt ] = array(
						'success' => true,
						'file'    => '',
						'error'   => '',
					);
				}
			}

			++$data['success'];
		}

		++$data['processed'];
		$this->write_log( $data );
	}

	/**
	 * Log failed processing of a page.
	 *
	 * @param int        $page_id       Page ID.
	 * @param string     $error_message Error description.
	 * @param array|null $formats   Export formats.
	 * @return void
	 */
	public function log_page_failure( int $page_id, string $error_message, ?array $formats = null ): void {
		$data = $this->read_log();

		if ( isset( $data['pages'][ $page_id ] ) ) {
			$end_time   = microtime( true );
			$start_time = $data['pages'][ $page_id ]['start_time'] ?? $end_time;

			$data['pages'][ $page_id ]['status']   = 'failed';
			$data['pages'][ $page_id ]['end_time'] = $end_time;
			$data['pages'][ $page_id ]['duration'] = round( $end_time - $start_time, 3 );
			$data['pages'][ $page_id ]['error']    = $error_message;
			$data['pages'][ $page_id ]['formats']  = $formats ?? array();
			$data['pages'][ $page_id ]['memory']   = size_format( memory_get_usage( true ) );
		} else {
			$data['pages'][ $page_id ] = array(
				'id'      => $page_id,
				'title'   => 'Unknown',
				'slug'    => 'unknown',
				'status'  => 'failed',
				'error'   => $error_message,
				'formats' => $formats ?? array(),
				'memory'  => size_format( memory_get_usage( true ) ),
			);
		}

		++$data['failed'];
		++$data['processed'];
		$data['errors'][] = array(
			'page_id' => $page_id,
			'message' => $error_message,
			'time'    => current_time( 'mysql' ),
		);

		$this->write_log( $data );
	}

	/**
	 * Log the result of a specific format export.
	 *
	 * @param int    $page_id   Page ID.
	 * @param string $format    Format identifier.
	 * @param bool   $success   Whether the export succeeded.
	 * @param string $file_path Output file path.
	 * @param string $error     Error message if failed.
	 * @return void
	 */
	public function log_format_result( int $page_id, string $format, bool $success, string $file_path = '', string $error = '' ): void {
		$data = $this->read_log();

		if ( isset( $data['pages'][ $page_id ] ) ) {
			$data['pages'][ $page_id ]['formats'][ $format ] = array(
				'success' => $success,
				'file'    => $file_path ? basename( $file_path ) : '',
				'error'   => $error,
			);
			$this->write_log( $data );
		}
	}

	/**
	 * Mark the export as complete.
	 *
	 * @param string $zip_path     ZIP file path.
	 * @param int    $files_in_zip Number of files in ZIP.
	 * @return void
	 */
	public function mark_complete( string $zip_path = '', int $files_in_zip = 0 ): void {
		$data                 = $this->read_log();
		$data['status']       = 'complete';
		$data['completed_at'] = current_time( 'mysql' );
		$data['zip_file']     = basename( $zip_path );
		$data['files_in_zip'] = $files_in_zip;
		$this->write_log( $data );
		$this->flush();

		if ( ! empty( $data['zip_file'] ) ) {
			$index_key = 'sscribe_zip_index_' . md5( $data['zip_file'] );
			set_transient( $index_key, $this->session_id, 30 * DAY_IN_SECONDS );
		}
	}

	/**
	 * Mark the export as failed.
	 *
	 * @param string $error_message Error description.
	 * @return void
	 */
	public function mark_failed( string $error_message ): void {
		$data                 = $this->read_log();
		$data['status']       = 'failed';
		$data['completed_at'] = current_time( 'mysql' );
		$data['errors'][]     = array(
			'page_id' => 0,
			'message' => $error_message,
			'time'    => current_time( 'mysql' ),
		);
		$this->write_log( $data );
		$this->flush();
	}

	/**
	 * Get the full log data.
	 *
	 * @return array
	 */
	public function get_log(): array {
		return $this->read_log();
	}

	/**
	 * Get a summary of the export log.
	 *
	 * @return array
	 */
	public function get_summary(): array {
		$data = $this->read_log();
		return array(
			'session_id'  => $data['session_id'],
			'total_pages' => $data['total_pages'],
			'processed'   => $data['processed'],
			'success'     => $data['success'],
			'failed'      => $data['failed'],
			'status'      => $data['status'],
			'errors'      => count( $data['errors'] ),
		);
	}

	/**
	 * Read the log file from disk (with caching).
	 *
	 * @return array
	 */
	private function read_log(): array {
		if ( null !== $this->data_cache ) {
			return $this->data_cache;
		}

		if ( ! file_exists( $this->log_file ) ) {
			$this->data_cache = array();
			return $this->data_cache;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Safe filesystem read.

		$json = file_get_contents( $this->log_file );
		if ( false === $json ) {
			$this->data_cache = array();
			return $this->data_cache;
		}

		$this->data_cache = json_decode( $json, true );
		$this->data_cache = is_array( $this->data_cache ) ? $this->data_cache : array();

		return $this->data_cache;
	}

	/**
	 * Write log data to cache (lazy write).
	 *
	 * @param array $data Log data.
	 * @return void
	 */
	private function write_log( array $data ): void {
		$this->data_cache = $data;
		$this->dirty      = true;
	}

	/**
	 * Delete the log file.
	 *
	 * @return void
	 */
	public function delete(): void {
		$this->data_cache = null;
		$this->dirty      = false;
		if ( file_exists( $this->log_file ) ) {
			wp_delete_file( $this->log_file );
		}
	}

	/**
	 * Get log data by session ID.
	 *
	 * @param string $session_id Session identifier.
	 * @return array|null
	 */
	public static function get_log_by_session( string $session_id ): ?array {
		$session_id = sanitize_file_name( $session_id );
		$upload_dir = wp_upload_dir();
		$log_file   = $upload_dir['basedir'] . '/sscribe-logs/export_' . $session_id . '.json';

		if ( ! file_exists( $log_file ) ) {
			return null;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Safe filesystem read.

		$json = file_get_contents( $log_file );

		if ( ! $json ) {
			return null;
		}

		$data = json_decode( $json, true );

		return is_array( $data ) ? $data : null;
	}

	/**
	 * Get log data by ZIP filename.
	 *
	 * @param string $filename ZIP filename.
	 * @return array|null
	 */
	public static function get_log_by_filename( string $filename ): ?array {

		$clean = sanitize_file_name( $filename );
		if ( $clean !== $filename || '.zip' !== substr( $filename, -4 ) ) {
			return null;
		}

		$upload_dir = wp_upload_dir();
		$log_dir    = $upload_dir['basedir'] . '/sscribe-logs';

		if ( ! is_dir( $log_dir ) ) {
			return null;
		}

		$index_key  = 'sscribe_zip_index_' . md5( $filename );
		$session_id = get_transient( $index_key );

		if ( false !== $session_id && is_string( $session_id ) ) {
			$log_file = $log_dir . '/export_' . sanitize_file_name( $session_id ) . '.json';
			if ( file_exists( $log_file ) ) {
				$json = file_get_contents( $log_file );
				if ( $json ) {
					$data = json_decode( $json, true );
					if ( is_array( $data ) && isset( $data['zip_file'] ) && $data['zip_file'] === $filename ) {
						return $data;
					}
				}
			}
		}

		$files = glob( $log_dir . '/export_*.json' );

		if ( is_array( $files ) ) {
			foreach ( $files as $file ) {
				$json = file_get_contents( $file );
				if ( $json ) {
					$data = json_decode( $json, true );
					if ( is_array( $data ) && isset( $data['zip_file'] ) && $data['zip_file'] === $filename ) {

						if ( isset( $data['session_id'] ) ) {
							set_transient( $index_key, $data['session_id'], 30 * DAY_IN_SECONDS );
						}
						return $data;
					}
				}
			}
		}

		return null;
	}

	/**
	 * Delete log by ZIP filename.
	 *
	 * @param string $filename ZIP filename.
	 * @return bool
	 */
	public static function delete_by_filename( string $filename ): bool {

		$clean = sanitize_file_name( $filename );
		if ( $clean !== $filename || '.zip' !== substr( $filename, -4 ) ) {
			return false;
		}

		$upload_dir = wp_upload_dir();
		$log_dir    = $upload_dir['basedir'] . '/sscribe-logs';

		if ( ! is_dir( $log_dir ) ) {
			return false;
		}

		$index_key = 'sscribe_zip_index_' . md5( $filename );
		delete_transient( $index_key );

		$files = glob( $log_dir . '/export_*.json' );

		if ( ! is_array( $files ) ) {
			return false;
		}

		foreach ( $files as $file ) {
			$json = file_get_contents( $file );
			if ( $json ) {
				$data = json_decode( $json, true );
				if ( is_array( $data ) && isset( $data['zip_file'] ) && $data['zip_file'] === $filename ) {
					wp_delete_file( $file );
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Clean up old log files.
	 *
	 * @param int $max_age_hours Maximum age in hours.
	 * @return int Number of deleted files.
	 */
	public static function cleanup_old_logs( int $max_age_hours = 6 ): int {
		$upload_dir = wp_upload_dir();
		$log_dir    = $upload_dir['basedir'] . '/sscribe-logs';

		if ( ! is_dir( $log_dir ) ) {
			return 0;
		}

		$files   = glob( $log_dir . '/export_*.json' );
		$deleted = 0;
		$max_age = $max_age_hours * HOUR_IN_SECONDS;
		$now     = time();

		if ( is_array( $files ) ) {
			foreach ( $files as $file ) {
				$file_time = filemtime( $file );
				if ( ! $file_time ) {
					continue;
				}

				if ( ( $now - $file_time ) > $max_age ) {

					$json = file_get_contents( $file );
					if ( $json ) {
						$data = json_decode( $json, true );
						if ( is_array( $data ) ) {
							$status = $data['status'] ?? '';

							if ( in_array( $status, array( 'started', 'processing', 'finalizing' ), true ) ) {
								continue;
							}
						}
					}

					if ( wp_delete_file( $file ) ) {
						++$deleted;
					}
				}
			}
		}

		return $deleted;
	}
}
