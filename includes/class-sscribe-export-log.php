<?php
/**
 * Export log for tracking page export status.
 *
 * @package SScribe
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SScribe_Export_Log {

	private string $log_dir;
	private string $log_file;
	private string $session_id;

	public function __construct( string $session_id ) {
		$this->session_id = $session_id;
		$this->log_dir    = $this->ensure_log_directory();
		$this->log_file   = $this->log_dir . 'export-' . $session_id . '.json';
		$this->init_log();
	}

	private function ensure_log_directory(): string {
		$upload_dir = wp_upload_dir();
		$log_dir    = trailingslashit( $upload_dir['basedir'] ) . 'sscribe-logs/';

		if ( ! is_dir( $log_dir ) ) {
			wp_mkdir_p( $log_dir );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $log_dir . '.htaccess', 'Deny from all' );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $log_dir . 'index.html', '' );
		}

		return $log_dir;
	}

	private function init_log(): void {
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

	public function set_total_pages( int $total ): void {
		$data = $this->read_log();
		$data['total_pages'] = $total;
		$this->write_log( $data );
	}

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

	public function log_page_success( int $page_id, array $formats ): void {
		$data = $this->read_log();
		
		if ( isset( $data['pages'][ $page_id ] ) ) {
			$end_time = microtime( true );
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

	public function log_page_failure( int $page_id, string $error_message, ?array $formats = null ): void {
		$data = $this->read_log();
		
		if ( isset( $data['pages'][ $page_id ] ) ) {
			$end_time = microtime( true );
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

	public function mark_complete( string $zip_path = '', int $files_in_zip = 0 ): void {
		$data = $this->read_log();
		$data['status']       = 'complete';
		$data['completed_at'] = current_time( 'mysql' );
		$data['zip_file']     = basename( $zip_path );
		$data['files_in_zip'] = $files_in_zip;
		$this->write_log( $data );
	}

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

	public function get_log(): array {
		return $this->read_log();
	}

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

	private function read_log(): array {
		if ( ! file_exists( $this->log_file ) ) {
			return array();
		}
		
		$content = file_get_contents( $this->log_file );
		$data    = json_decode( $content, true );
		
		return is_array( $data ) ? $data : array();
	}

	private function write_log( array $data ): void {
		$json = wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $this->log_file, $json, LOCK_EX );
	}

	public function delete(): void {
		if ( file_exists( $this->log_file ) ) {
			wp_delete_file( $this->log_file );
		}
	}

	public function get_file_path(): string {
		return $this->log_file;
	}

	public static function get_log_by_session( string $session_id ): ?array {
		$upload_dir = wp_upload_dir();
		$log_file   = trailingslashit( $upload_dir['basedir'] ) . 'sscribe-logs/export-' . $session_id . '.json';
		
		if ( ! file_exists( $log_file ) ) {
			return null;
		}
		
		$content = file_get_contents( $log_file );
		$data    = json_decode( $content, true );
		
		return is_array( $data ) ? $data : null;
	}

	public static function get_log_by_filename( string $filename ): ?array {
		if ( ! preg_match( '/^sscribe-export-([a-z0-9]+)-/i', $filename, $matches ) ) {
			return null;
		}
		
		$upload_dir = wp_upload_dir();
		$log_dir    = trailingslashit( $upload_dir['basedir'] ) . 'sscribe-logs/';
		
		$pattern = $log_dir . 'export-*.json';
		$files   = glob( $pattern );
		
		if ( ! $files ) {
			return null;
		}
		
		usort( $files, function( $a, $b ) {
			return filemtime( $b ) - filemtime( $a );
		});
		
		foreach ( $files as $file ) {
			$content = file_get_contents( $file );
			$data    = json_decode( $content, true );
			
			if ( $data && isset( $data['zip_file'] ) && $data['zip_file'] === $filename ) {
				return $data;
			}
		}
		
		return null;
	}

	public static function cleanup_old_logs( int $max_age_hours = 2 ): int {
		$upload_dir = wp_upload_dir();
		$log_dir    = trailingslashit( $upload_dir['basedir'] ) . 'sscribe-logs/';
		$files      = glob( $log_dir . 'export-*.json' );
		$deleted    = 0;
		
		if ( ! $files ) {
			return 0;
		}
		
		$now      = time();
		$max_age  = $max_age_hours * HOUR_IN_SECONDS;
		
		foreach ( $files as $file ) {
			$file_time = filemtime( $file );
			if ( $file_time && ( $now - $file_time ) > $max_age ) {
				wp_delete_file( $file );
				$deleted++;
			}
		}
		
		return $deleted;
	}
}
