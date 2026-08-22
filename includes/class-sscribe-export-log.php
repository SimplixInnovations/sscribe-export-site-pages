<?php
/**
 * SScribe Export Log.
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
 * Export log management.
 *
 * @package SScribe_Export_Site_Pages
 * @subpackage Log
 */
class SScribe_Export_Log {
	private const MAX_PAGE_ENTRIES = 1000;

	private const MAX_ERROR_ENTRIES = 500;

	private const MAX_LOG_BYTES = 5_242_880;

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
	 * Whether the WordPress uploads directory is available.
	 *
	 * @var bool
	 */
	private bool $storage_available;

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
		$upload_dir              = wp_upload_dir();
		$valid_session           = 1 === preg_match( '/^[a-f0-9]{16}$/D', $session_id );
		$this->storage_available = $valid_session && empty( $upload_dir['error'] ) && ! empty( $upload_dir['basedir'] );
		$this->log_dir           = $this->storage_available
			? trailingslashit( (string) $upload_dir['basedir'] ) . 'sscribe-exports/logs'
			: '';

		$this->session_id = $valid_session ? $session_id : '';
		$this->log_file = $this->storage_available ? $this->log_dir . '/export_' . $this->session_id . '.json' : '';

		$this->init_log();
	}

	/**
	 * Initialize the log file if it doesn't exist.
	 *
	 * @return void
	 */
	private function init_log(): void {
		if ( ! $this->storage_available ) {
			$this->data_cache = $this->get_default_log_data();
			return;
		}

		if ( is_link( $this->log_dir ) || is_link( $this->log_file ) ) {
			$this->storage_available = false;
			$this->data_cache        = $this->get_default_log_data();
			return;
		}

		if ( ! file_exists( $this->log_dir ) ) {
			SScribe_Security::protect_directory( $this->log_dir );
		}
		if ( ! is_dir( $this->log_dir ) || is_link( $this->log_dir ) ) {
			$this->storage_available = false;
			$this->data_cache        = $this->get_default_log_data();
			return;
		}

		if ( ! file_exists( $this->log_file ) ) {
			$this->write_log( $this->get_default_log_data() );
		}
	}

	/**
	 * Get a new in-memory log record.
	 *
	 * @return array Log data.
	 */
	private function get_default_log_data(): array {
		return array(
			'session_id'   => $this->session_id,
			'created_at'   => current_time( 'mysql', true ),
			'total_pages'  => 0,
			'processed'    => 0,
			'success'      => 0,
			'failed'       => 0,
			'pages'        => array(),
			'errors'       => array(),
			'status'       => 'started',
			'completed_at' => null,
		);
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
		if ( ! $this->storage_available ) {
			$this->dirty = false;
			return;
		}
		if ( $this->dirty && null !== $this->data_cache && ! is_link( $this->log_file ) ) {
			$this->data_cache = $this->bound_log_data( $this->data_cache );
			$json             = wp_json_encode( $this->data_cache, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
			if ( false === $json ) {
				$this->dirty = false;
				return;
			}
			if ( strlen( $json ) > self::MAX_LOG_BYTES ) {
				$this->data_cache['pages']  = array_slice( (array) $this->data_cache['pages'], -200, null, true );
				$this->data_cache['errors'] = array_slice( (array) $this->data_cache['errors'], -100 );
				$json                       = wp_json_encode( $this->data_cache, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
				if ( false === $json || strlen( $json ) > self::MAX_LOG_BYTES ) {
					$this->dirty = false;
					return;
				}
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Intended logging file operation.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Intended logging file permission (0600).
			$result = file_put_contents( $this->log_file, $json, LOCK_EX );
			if ( false !== $result ) {
				$this->dirty = false;
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Setting 0600 for log file security; only effective on Unix-like systems.
				chmod( $this->log_file, 0600 );
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
		if ( ! isset( $data['pages'][ $page_id ] ) && count( (array) $data['pages'] ) >= self::MAX_PAGE_ENTRIES ) {
			return;
		}

		$data['pages'][ $page_id ] = array(
			'id'         => $page_id,
			'title'      => self::limit_text( $title, 300 ),
			'slug'       => self::limit_text( $slug, 200 ),
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
	 * Update the status of a page in the export log.
	 *
	 * @param int    $page_id Page ID.
	 * @param string $status  New status.
	 */
	public function update_page_status( int $page_id, string $status ): void {
		$data = $this->read_log();

		if ( isset( $data['pages'][ $page_id ] ) ) {
			$data['pages'][ $page_id ]['status'] = $status;
		} elseif ( count( (array) $data['pages'] ) < self::MAX_PAGE_ENTRIES ) {
			$data['pages'][ $page_id ] = array(
				'id'         => $page_id,
				'status'     => $status,
				'start_time' => microtime( true ),
				'end_time'   => null,
				'duration'   => null,
				'formats'    => array(),
				'error'      => null,
				'memory'     => size_format( memory_get_usage( true ) ),
			);
		}

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

				foreach ( array_slice( $formats, 0, 20 ) as $fmt ) {
					if ( ! is_scalar( $fmt ) ) {
						continue;
					}
					$fmt = substr( sanitize_key( (string) $fmt ), 0, 40 );
					if ( '' === $fmt ) {
						continue;
					}
					$data['pages'][ $page_id ]['formats'][ $fmt ] = array(
						'success' => true,
						'file'    => '',
						'error'   => '',
					);
				}
			}
		}

		++$data['success'];
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
			$data['pages'][ $page_id ]['error']    = self::limit_text( $error_message, 1000 );
			$data['pages'][ $page_id ]['formats']  = array_slice( $formats ?? array(), 0, 20, true );
			$data['pages'][ $page_id ]['memory']   = size_format( memory_get_usage( true ) );
		} elseif ( count( (array) $data['pages'] ) < self::MAX_PAGE_ENTRIES ) {
			$data['pages'][ $page_id ] = array(
				'id'      => $page_id,
				'title'   => 'Unknown',
				'slug'    => 'unknown',
				'status'  => 'failed',
				'error'   => self::limit_text( $error_message, 1000 ),
				'formats' => array_slice( $formats ?? array(), 0, 20, true ),
				'memory'  => size_format( memory_get_usage( true ) ),
			);
		}

		++$data['failed'];
		++$data['processed'];
		$data['errors'][] = array(
			'page_id' => $page_id,
			'message' => self::limit_text( $error_message, 1000 ),
			'time'    => current_time( 'mysql', true ),
		);
		$data['errors']   = array_slice( $data['errors'], -self::MAX_ERROR_ENTRIES );

		$this->write_log( $data );
	}

	/**
	 * Record a retry attempt on a page.
	 *
	 * Called by the batch processor's retry loop so the audit trail
	 * shows not just the final outcome of a page export but also the
	 * intermediate retries that were attempted. Without this, a user
	 * inspecting the log would only see the last attempt and could
	 * not tell whether a transient error was recovered.
	 *
	 * @param int    $page_id  Page ID.
	 * @param string $format   Format being retried.
	 * @param int    $attempt  Attempt number (1-based: 1 = first retry).
	 * @param string $category Error category of the previous attempt.
	 * @return void
	 */
	public function log_page_retry( int $page_id, string $format, int $attempt, string $category ): void {
		$data = $this->read_log();

		if ( ! isset( $data['pages'][ $page_id ] ) ) {
			if ( count( (array) $data['pages'] ) >= self::MAX_PAGE_ENTRIES ) {
				return;
			}

			$data['pages'][ $page_id ] = array(
				'status'        => 'retrying',
				'retries'       => array(),
				'start_time'    => microtime( true ),
			);
		}

		$data['pages'][ $page_id ]['retries'][] = array(
			'format'   => substr( sanitize_key( $format ), 0, 40 ),
			'attempt'  => $attempt,
			'category' => $category,
			'time'     => current_time( 'mysql', true ),
		);
		$data['pages'][ $page_id ]['retries']   = array_slice( $data['pages'][ $page_id ]['retries'], -20 );

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
		$format = substr( sanitize_key( $format ), 0, 40 );

		if ( '' !== $format && isset( $data['pages'][ $page_id ] ) ) {
			$data['pages'][ $page_id ]['formats'][ $format ] = array(
				'success' => $success,
				'file'    => $file_path ? basename( $file_path ) : '',
				'error'   => self::limit_text( $error, 1000 ),
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
		$zip_file = basename( $zip_path );
		if ( sanitize_file_name( $zip_file ) !== $zip_file || 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]{0,199}\.zip$/D', $zip_file ) ) {
			$zip_file = '';
		}
		$data                 = $this->read_log();
		$data['status']       = 'complete';
		$data['completed_at'] = current_time( 'mysql', true );
		$data['zip_file']     = $zip_file;
		$data['files_in_zip'] = max( 0, $files_in_zip );
		$this->write_log( $data );
		$this->flush();

		if ( ! empty( $data['zip_file'] ) ) {
			$index_key = 'sscribe_zip_index_' . md5( $data['zip_file'] );

			// Persist the index in all three layers (object cache,
			// transient, option) so the read path can short-circuit
			// the glob() scan regardless of which cache layer is
			// available. Without this, hosts without an object cache
			// fall through to glob() on every log lookup until the
			// read site lazily populates the transient.
			wp_cache_set( $index_key, $this->session_id, 'sscribe_zip_index', 30 * DAY_IN_SECONDS );
			set_transient( $index_key, $this->session_id, 30 * DAY_IN_SECONDS );

			update_option( 'sscribe_log_zip_' . md5( $data['zip_file'] ), $this->session_id, false );
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
		$data['completed_at'] = current_time( 'mysql', true );
		$data['errors'][]     = array(
			'page_id' => 0,
			'message' => self::limit_text( $error_message, 1000 ),
			'time'    => current_time( 'mysql', true ),
		);
		$data['errors']       = array_slice( $data['errors'], -self::MAX_ERROR_ENTRIES );
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

		if ( ! is_file( $this->log_file ) || is_link( $this->log_file ) ) {
			$this->data_cache = $this->get_default_log_data();
			return $this->data_cache;
		}

		$file_size = filesize( $this->log_file );
		if ( false === $file_size || $file_size > self::MAX_LOG_BYTES ) {
			$this->data_cache = $this->get_default_log_data();
			return $this->data_cache;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Safe filesystem read.

		$json = file_get_contents( $this->log_file );
		if ( false === $json ) {
			$this->data_cache = $this->get_default_log_data();
			return $this->data_cache;
		}

		$this->data_cache = json_decode( $json, true );
		$this->data_cache = is_array( $this->data_cache ) ? $this->normalize_log_data( $this->data_cache ) : $this->get_default_log_data();

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
	 * Bound user-controlled strings and arrays before persisting a log.
	 *
	 * @param array $data Log data.
	 * @return array Bounded data.
	 */
	private function bound_log_data( array $data ): array {
		$data['pages']  = array_slice( (array) ( $data['pages'] ?? array() ), -self::MAX_PAGE_ENTRIES, null, true );
		$data['errors'] = array_slice( (array) ( $data['errors'] ?? array() ), -self::MAX_ERROR_ENTRIES );

		foreach ( $data['pages'] as &$page ) {
			if ( ! is_array( $page ) ) {
				$page = array();
				continue;
			}
			$page['title']   = self::limit_text( is_scalar( $page['title'] ?? null ) ? (string) $page['title'] : '', 300 );
			$page['slug']    = self::limit_text( is_scalar( $page['slug'] ?? null ) ? (string) $page['slug'] : '', 200 );
			$page['error']   = self::limit_text( is_scalar( $page['error'] ?? null ) ? (string) $page['error'] : '', 1000 );
			$page['formats'] = array_slice( (array) ( $page['formats'] ?? array() ), 0, 20, true );
			$page['retries'] = array_slice( (array) ( $page['retries'] ?? array() ), -20 );
		}
		unset( $page );

		foreach ( $data['errors'] as &$error ) {
			if ( is_array( $error ) ) {
				$error['message'] = self::limit_text( is_scalar( $error['message'] ?? null ) ? (string) $error['message'] : '', 1000 );
			}
		}
		unset( $error );

		return $data;
	}

	/**
	 * Restore the bounded log schema after a partial or damaged disk write.
	 *
	 * @param array $data Decoded JSON data.
	 * @return array
	 */
	private function normalize_log_data( array $data ): array {
		$data               = array_merge( $this->get_default_log_data(), $data );
		$data['session_id'] = $this->session_id;
		foreach ( array( 'total_pages', 'processed', 'success', 'failed' ) as $counter ) {
			$data[ $counter ] = isset( $data[ $counter ] ) && is_numeric( $data[ $counter ] ) ? max( 0, (int) $data[ $counter ] ) : 0;
		}
		$status         = is_scalar( $data['status'] ?? null ) ? sanitize_key( (string) $data['status'] ) : 'started';
		$data['status'] = in_array( $status, array( 'started', 'processing', 'complete', 'failed', 'cancelled' ), true ) ? $status : 'started';

		return $this->bound_log_data( $data );
	}

	/**
	 * Convert log text to a bounded single-line value.
	 *
	 * @param string $value  Raw value.
	 * @param int    $length Maximum characters.
	 * @return string Bounded text.
	 */
	private static function limit_text( string $value, int $length ): string {
		$value = sanitize_text_field( $value );
		return SScribe_Helpers::mb_substr( $value, 0, $length );
	}

	/**
	 * Delete the log file.
	 *
	 * @return void
	 */
	public function delete(): void {
		$data = $this->read_log();
		if ( ! empty( $data['zip_file'] ) && is_string( $data['zip_file'] ) ) {
			self::clear_zip_index( $data['zip_file'] );
		}
		$this->data_cache = null;
		$this->dirty      = false;
		if ( $this->storage_available && file_exists( $this->log_file ) ) {
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
		if ( 1 !== preg_match( '/^[a-f0-9]{16}$/D', $session_id ) ) {
			return null;
		}
		$upload_dir = wp_upload_dir();
		if ( ! empty( $upload_dir['error'] ) || empty( $upload_dir['basedir'] ) ) {
			return null;
		}
		$log_file   = $upload_dir['basedir'] . '/sscribe-exports/logs/export_' . $session_id . '.json';

		return self::read_log_file( $log_file );
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
		if ( ! empty( $upload_dir['error'] ) || empty( $upload_dir['basedir'] ) ) {
			return null;
		}
		$log_dir    = $upload_dir['basedir'] . '/sscribe-exports/logs';

		if ( ! is_dir( $log_dir ) || is_link( $log_dir ) ) {
			return null;
		}

		$index_key  = 'sscribe_zip_index_' . md5( $filename );
		$session_id = wp_cache_get( $index_key, 'sscribe_zip_index' );
		if ( false === $session_id ) {
			$session_id = get_transient( $index_key );
		}

		if ( is_string( $session_id ) && 1 === preg_match( '/^[a-f0-9]{16}$/D', $session_id ) ) {
			$data = self::read_log_file( $log_dir . '/export_' . $session_id . '.json' );
			if ( is_array( $data ) && isset( $data['zip_file'] ) && $data['zip_file'] === $filename ) {
				return $data;
			}
		}

		$option_key = 'sscribe_log_zip_' . md5( $filename );
		$session_id = get_option( $option_key, false );

		if ( is_string( $session_id ) && 1 === preg_match( '/^[a-f0-9]{16}$/D', $session_id ) ) {

			wp_cache_set( $index_key, $session_id, 'sscribe_zip_index', 30 * DAY_IN_SECONDS );
			set_transient( $index_key, $session_id, 30 * DAY_IN_SECONDS );

			$data = self::read_log_file( $log_dir . '/export_' . $session_id . '.json' );
			if ( is_array( $data ) && isset( $data['zip_file'] ) && $data['zip_file'] === $filename ) {
				return $data;
			}
		}

		if ( ! apply_filters( 'sscribe_enable_log_full_scan', true ) ) {
			return null;
		}

		$files = self::list_log_files( $log_dir, 501 );

		if ( count( $files ) > 200 ) {
			$logger = SScribe_Logger::instance( SScribe_Logger::is_logging_enabled() );
			$logger->warning(
				'O(n) log scan triggered : directory has ' . count( $files ) . ' files. Consider running cleanup.',
				array(
					'filename'  => $filename,
					'filecount' => count( $files ),
				)
			);
		}

		foreach ( array_slice( $files, 0, 500 ) as $file ) {
				$data = self::read_log_file( $file );
			if ( is_array( $data ) && isset( $data['zip_file'] ) && $data['zip_file'] === $filename ) {

				if ( isset( $data['session_id'] ) && is_string( $data['session_id'] ) && 1 === preg_match( '/^[a-f0-9]{16}$/D', $data['session_id'] ) ) {

					set_transient( $index_key, $data['session_id'], 30 * DAY_IN_SECONDS );
					update_option( 'sscribe_log_zip_' . md5( $filename ), $data['session_id'], false );
				}
				return $data;
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
		if ( ! empty( $upload_dir['error'] ) || empty( $upload_dir['basedir'] ) ) {
			return false;
		}
		$log_dir    = $upload_dir['basedir'] . '/sscribe-exports/logs';

		if ( ! is_dir( $log_dir ) || is_link( $log_dir ) ) {
			return false;
		}

		$index_key = 'sscribe_zip_index_' . md5( $filename );
		wp_cache_delete( $index_key, 'sscribe_zip_index' );
		delete_transient( $index_key );

		delete_option( 'sscribe_log_zip_' . md5( $filename ) );

		$files = self::list_log_files( $log_dir, 500 );

		foreach ( array_slice( $files, 0, 500 ) as $file ) {
			$data = self::read_log_file( $file );
			if ( is_array( $data ) && isset( $data['zip_file'] ) && $data['zip_file'] === $filename ) {
				wp_delete_file( $file );
				return true;
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
		if ( ! empty( $upload_dir['error'] ) || empty( $upload_dir['basedir'] ) ) {
			return 0;
		}
		$log_dir    = $upload_dir['basedir'] . '/sscribe-exports/logs';

		if ( ! is_dir( $log_dir ) || is_link( $log_dir ) ) {
			return 0;
		}

		$files   = self::list_log_files( $log_dir, 2000 );
		$deleted = 0;
		$max_age = $max_age_hours * HOUR_IN_SECONDS;
		$now     = time();

		foreach ( $files as $file ) {
				$file_time = filemtime( $file );
			if ( ! $file_time ) {
				continue;
			}

			if ( ( $now - $file_time ) > $max_age ) {
				$data = self::read_log_file( $file );
				if ( wp_delete_file( $file ) ) {
					if ( is_array( $data ) && ! empty( $data['zip_file'] ) && is_string( $data['zip_file'] ) ) {
						self::clear_zip_index( $data['zip_file'] );
					}
					++$deleted;
				}
			}
		}

		return $deleted;
	}

	/**
	 * List a bounded set of regular export-log files without glob expansion.
	 *
	 * @param string $log_dir Canonical log directory.
	 * @param int    $limit   Maximum rows to return.
	 * @return array<int, string>
	 */
	private static function list_log_files( string $log_dir, int $limit ): array {
		if ( ! is_dir( $log_dir ) || is_link( $log_dir ) ) {
			return array();
		}

		$limit = max( 1, min( 5000, $limit ) );
		$files = array();
		try {
			$iterator = new \DirectoryIterator( $log_dir );
		} catch ( \UnexpectedValueException ) {
			return array();
		}

		foreach ( $iterator as $entry ) {
			if ( count( $files ) >= $limit ) {
				break;
			}
			if ( $entry->isDot() || $entry->isLink() || ! $entry->isFile() || 1 !== preg_match( '/^export_[a-f0-9]{16}\.json$/D', $entry->getFilename() ) ) {
				continue;
			}
			$files[] = $entry->getPathname();
		}

		return $files;
	}

	/**
	 * Delete an export log directly by its validated session ID.
	 *
	 * @param string $session_id Export session ID.
	 * @return bool True when absent or removed.
	 */
	public static function delete_by_session( string $session_id ): bool {
		if ( 1 !== preg_match( '/^[a-f0-9]{16}$/D', $session_id ) ) {
			return false;
		}
		$upload_dir = wp_upload_dir();
		if ( ! empty( $upload_dir['error'] ) || empty( $upload_dir['basedir'] ) ) {
			return false;
		}
		$log_dir = trailingslashit( (string) $upload_dir['basedir'] ) . 'sscribe-exports/logs';
		$path    = $log_dir . '/export_' . $session_id . '.json';
		if ( ! file_exists( $path ) && ! is_link( $path ) ) {
			return true;
		}
		if ( is_link( $log_dir ) || is_link( $path ) || ! is_file( $path ) ) {
			return false;
		}

		wp_delete_file( $path );
		return ! file_exists( $path );
	}

	/**
	 * Read a bounded, regular JSON log file.
	 *
	 * @param string $log_file Absolute log path.
	 * @return array|null Decoded data.
	 */
	private static function read_log_file( string $log_file ): ?array {
		if ( ! is_file( $log_file ) || is_link( $log_file ) ) {
			return null;
		}
		$file_size = filesize( $log_file );
		if ( false === $file_size || $file_size > self::MAX_LOG_BYTES ) {
			return null;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Canonical plugin log file with a strict size bound.
		$json = file_get_contents( $log_file );
		if ( false === $json || '' === $json ) {
			return null;
		}
		$data = json_decode( $json, true );
		return is_array( $data ) ? $data : null;
	}

	/**
	 * Remove all cache layers for a ZIP-to-log index.
	 *
	 * @param string $filename ZIP basename.
	 * @return void
	 */
	private static function clear_zip_index( string $filename ): void {
		$filename = basename( $filename );
		if ( sanitize_file_name( $filename ) !== $filename || '.zip' !== strtolower( substr( $filename, -4 ) ) ) {
			return;
		}
		$index_key = 'sscribe_zip_index_' . md5( $filename );
		wp_cache_delete( $index_key, 'sscribe_zip_index' );
		delete_transient( $index_key );
		delete_option( 'sscribe_log_zip_' . md5( $filename ) );
	}
}
