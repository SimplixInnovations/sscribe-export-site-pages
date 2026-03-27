<?php
/**
 * Export log for tracking page export status.
 *
 * Uses wp_options table for storage to comply with WordPress.org
 * repository guidelines that prohibit direct filesystem writes.
 *
 * @package SScribe
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SScribe_Export_Log
 *
 * Tracks export progress and results using WordPress options API.
 */
class SScribe_Export_Log {

	/**
	 * Option name prefix for log storage.
	 *
	 * @var string
	 */
	private string $option_prefix = 'sscribe_log_';

	/**
	 * Session ID for this log.
	 *
	 * @var string
	 */
	private string $session_id;

	/**
	 * Constructor.
	 *
	 * @param string $session_id The session identifier.
	 */
	public function __construct( string $session_id ) {
		$this->session_id = sanitize_key( $session_id );
		$this->init_log();
	}

	/**
	 * Initialize the log if it doesn't exist.
	 *
	 * @return void
	 */
	private function init_log(): void {
		$option_name = $this->get_option_name();

		if ( false === get_option( $option_name ) ) {
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
	 * Set the total number of pages to export.
	 *
	 * @param int $total Total page count.
	 * @return void
	 */
	public function set_total_pages( int $total ): void {
		$data = $this->read_log();
		$data['total_pages'] = $total;
		$this->write_log( $data );
	}

	/**
	 * Log the start of a page export.
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
	 * Log successful page export.
	 *
	 * @param int   $page_id Page ID.
	 * @param array $formats Formats exported.
	 * @return void
	 */
	public function log_page_success( int $page_id, array $formats ): void {
		$data = $this->read_log();

		if ( isset( $data['pages'][ $page_id ] ) ) {
			$end_time   = microtime( true );
			$start_time = $data['pages'][ $page_id ]['start_time'] ?? $end_time;

			$data['pages'][ $page_id ]['status']     = 'success';
			$data['pages'][ $page_id ]['end_time']   = $end_time;
			$data['pages'][ $page_id ]['duration']   = round( $end_time - $start_time, 3 );
			$data['pages'][ $page_id ]['formats']    = $formats;
			$data['pages'][ $page_id ]['memory']     = size_format( memory_get_usage( true ) );
			$data['success']++;
		}

		$data['processed']++;
		$this->write_log( $data );
	}

	/**
	 * Log failed page export.
	 *
	 * @param int         $page_id       Page ID.
	 * @param string      $error_message Error message.
	 * @param array|null  $formats       Formats attempted.
	 * @return void
	 */
	public function log_page_failure( int $page_id, string $error_message, ?array $formats = null ): void {
		$data = $this->read_log();

		if ( isset( $data['pages'][ $page_id ] ) ) {
			$end_time   = microtime( true );
			$start_time = $data['pages'][ $page_id ]['start_time'] ?? $end_time;

			$data['pages'][ $page_id ]['status']     = 'failed';
			$data['pages'][ $page_id ]['end_time']   = $end_time;
			$data['pages'][ $page_id ]['duration']   = round( $end_time - $start_time, 3 );
			$data['pages'][ $page_id ]['error']      = $error_message;
			$data['pages'][ $page_id ]['formats']    = $formats ?? array();
			$data['pages'][ $page_id ]['memory']     = size_format( memory_get_usage( true ) );
		} else {
			$data['pages'][ $page_id ] = array(
				'id'       => $page_id,
				'title'    => 'Unknown',
				'slug'     => 'unknown',
				'status'   => 'failed',
				'error'    => $error_message,
				'formats'  => $formats ?? array(),
				'memory'   => size_format( memory_get_usage( true ) ),
			);
		}

		$data['failed']++;
		$data['processed']++;
		$data['errors'][] = array(
			'page_id' => $page_id,
			'message' => $error_message,
			'time'    => current_time( 'mysql' ),
		);

		$this->write_log( $data );
	}

	/**
	 * Log format-specific result.
	 *
	 * @param int    $page_id  Page ID.
	 * @param string $format   Format name.
	 * @param bool   $success  Whether export succeeded.
	 * @param string $file_path File path (optional).
	 * @param string $error    Error message (optional).
	 * @return void
	 */
	public function log_format_result( int $page_id, string $format, bool $success, string $file_path = '', string $error = '' ): void {
		$data = $this->read_log();

		if ( isset( $data['pages'][ $page_id ] ) ) {
			$data['pages'][ $page_id ]['formats'][ $format ] = array(
				'success'  => $success,
				'file'     => $file_path ? basename( $file_path ) : '',
				'error'    => $error,
			);
			$this->write_log( $data );
		}
	}

	/**
	 * Mark export as complete.
	 *
	 * @param string $zip_path     Path to ZIP file.
	 * @param int    $files_in_zip Number of files in ZIP.
	 * @return void
	 */
	public function mark_complete( string $zip_path = '', int $files_in_zip = 0 ): void {
		$data = $this->read_log();
		$data['status']       = 'complete';
		$data['completed_at'] = current_time( 'mysql' );
		$data['zip_file']     = basename( $zip_path );
		$data['files_in_zip'] = $files_in_zip;
		$this->write_log( $data );
	}

	/**
	 * Mark export as failed.
	 *
	 * @param string $error_message Error message.
	 * @return void
	 */
	public function mark_failed( string $error_message ): void {
		$data = $this->read_log();
		$data['status']       = 'failed';
		$data['completed_at'] = current_time( 'mysql' );
		$data['errors'][]     = array(
			'page_id' => 0,
			'message' => $error_message,
			'time'    => current_time( 'mysql' ),
		);
		$this->write_log( $data );
	}

	/**
	 * Get the full log data.
	 *
	 * @return array Log data.
	 */
	public function get_log(): array {
		return $this->read_log();
	}

	/**
	 * Get a summary of the log.
	 *
	 * @return array Summary data.
	 */
	public function get_summary(): array {
		$data = $this->read_log();
		return array(
			'session_id'   => $data['session_id'],
			'total_pages'  => $data['total_pages'],
			'processed'    => $data['processed'],
			'success'      => $data['success'],
			'failed'       => $data['failed'],
			'status'       => $data['status'],
			'errors'       => count( $data['errors'] ),
		);
	}

	/**
	 * Read log data from database.
	 *
	 * @return array Log data or empty array.
	 */
	private function read_log(): array {
		$option_name = $this->get_option_name();
		$json        = get_option( $option_name );

		if ( false === $json ) {
			return array();
		}

		$data = json_decode( $json, true );

		return is_array( $data ) ? $data : array();
	}

	/**
	 * Write log data to database.
	 *
	 * @param array $data Log data to write.
	 * @return void
	 */
	private function write_log( array $data ): void {
		$option_name = $this->get_option_name();
		$json        = wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
		update_option( $option_name, $json, false );
	}

	/**
	 * Delete this log.
	 *
	 * @return void
	 */
	public function delete(): void {
		$option_name = $this->get_option_name();
		delete_option( $option_name );
	}

	/**
	 * Get the option name for this log.
	 *
	 * @return string Option name.
	 */
	private function get_option_name(): string {
		return $this->option_prefix . $this->session_id;
	}

	/**
	 * Get log by session ID (static helper).
	 *
	 * @param string $session_id Session identifier.
	 * @return array|null Log data or null.
	 */
	public static function get_log_by_session( string $session_id ): ?array {
		$session_id = sanitize_key( $session_id );
		$option_name = 'sscribe_log_' . $session_id;
		$json        = get_option( $option_name );

		if ( false === $json ) {
			return null;
		}

		$data = json_decode( $json, true );

		return is_array( $data ) ? $data : null;
	}

	/**
	 * Get log by ZIP filename (static helper).
	 *
	 * @param string $filename ZIP filename.
	 * @return array|null Log data or null.
	 */
	public static function get_log_by_filename( string $filename ): ?array {
		if ( ! preg_match( '/^sscribe-export-([a-z0-9]+)-/i', $filename, $matches ) ) {
			return null;
		}

		global $wpdb;

		$pattern = $wpdb->esc_like( 'sscribe_log_' ) . '%';

		$options = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE %s AND autoload = 'no' ORDER BY option_id DESC",
				$pattern
			)
		);

		foreach ( $options as $option ) {
			$data = json_decode( $option->option_value, true );

			if ( $data && isset( $data['zip_file'] ) && $data['zip_file'] === $filename ) {
				return $data;
			}
		}

		return null;
	}

	/**
	 * Clean up old logs.
	 *
	 * @param int $max_age_hours Maximum age in hours. Default 2.
	 * @return int Number of logs deleted.
	 */
	public static function cleanup_old_logs( int $max_age_hours = 2 ): int {
		global $wpdb;

		$pattern  = $wpdb->esc_like( 'sscribe_log_' ) . '%';
		$now      = time();
		$max_age  = $max_age_hours * HOUR_IN_SECONDS;

		$options = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s AND autoload = 'no'",
				$pattern
			)
		);

		$deleted = 0;

		foreach ( $options as $option ) {
			$data = json_decode( $option->option_value, true );

			if ( ! is_array( $data ) ) {
				continue;
			}

			$created = isset( $data['created_at'] ) ? strtotime( $data['created_at'] ) : 0;
			if ( $created && ( $now - $created ) > $max_age ) {
				if ( delete_option( $option->option_name ) ) {
					++$deleted;
				}
			}
		}

		return $deleted;
	}
}
