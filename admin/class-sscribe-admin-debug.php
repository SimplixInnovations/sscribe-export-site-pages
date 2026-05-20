<?php
/**
 * SScribe Admin Debug Console
 *
 * Handles debug AJAX endpoints, log parsing, and log file management.
 * Extracted from SScribe_Admin to reduce class size and improve maintainability.
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Debug console handler for SScribe admin.
 */
class SScribe_Admin_Debug {

	/**
	 * Register all debug AJAX hooks.
	 */
	public function register_hooks(): void {
		add_action( 'wp_ajax_sscribe_debug_save_settings', array( $this, 'ajax_debug_save_settings' ) );
		add_action( 'wp_ajax_sscribe_debug_fetch_logs', array( $this, 'ajax_debug_fetch_logs' ) );
		add_action( 'wp_ajax_sscribe_debug_clear_logs', array( $this, 'ajax_debug_clear_logs' ) );
		add_action( 'wp_ajax_sscribe_debug_export_logs', array( $this, 'ajax_debug_export_logs' ) );
		add_action( 'wp_ajax_sscribe_debug_get_files', array( $this, 'ajax_debug_get_files' ) );
		add_action( 'wp_ajax_sscribe_debug_fetch_rotated', array( $this, 'ajax_debug_fetch_rotated' ) );
		add_action( 'wp_ajax_sscribe_debug_delete_rotated', array( $this, 'ajax_debug_delete_rotated' ) );
	}

	/**
	 * AJAX: Save debug settings.
	 */
	public function ajax_debug_save_settings(): void {
		check_ajax_referer( 'sscribe_export_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'sscribe-export-site-pages' ) ) );
		}

		$settings = array(
			'debug_enabled' => isset( $_POST['debug_enabled'] ) ? (bool) $_POST['debug_enabled'] : false,
			'log_level'     => isset( $_POST['log_level'] ) ? sanitize_text_field( wp_unslash( $_POST['log_level'] ) ) : 'DEBUG',
			'auto_refresh'  => isset( $_POST['auto_refresh'] ) ? (bool) $_POST['auto_refresh'] : true,
		);

		$saved = SScribe_Settings::save_debug_settings( $settings );

		if ( $saved ) {
			wp_send_json_success();
		} else {
			wp_send_json_error( array( 'message' => __( 'Failed to save settings.', 'sscribe-export-site-pages' ) ) );
		}
	}

	/**
	 * AJAX: Fetch debug logs.
	 */
	public function ajax_debug_fetch_logs(): void {
		check_ajax_referer( 'sscribe_export_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'sscribe-export-site-pages' ) ) );
		}

		$rate_limiter = new SScribe_Export_Rate_Limiter();
		if ( ! $rate_limiter->check_rate_limit() ) {
			wp_send_json_error( array( 'message' => __( 'Rate limit exceeded. Please wait before trying again.', 'sscribe-export-site-pages' ) ) );
		}

		$filter_level = isset( $_GET['filter_level'] ) ? sanitize_text_field( wp_unslash( $_GET['filter_level'] ) ) : 'ALL';
		$search       = isset( $_GET['search'] ) ? sanitize_text_field( wp_unslash( $_GET['search'] ) ) : '';
		$offset       = isset( $_GET['offset'] ) ? absint( $_GET['offset'] ) : 0;
		$limit        = isset( $_GET['limit'] ) ? absint( $_GET['limit'] ) : 100;

		if ( $limit < 1 || $limit > 500 ) {
			$limit = 100;
		}
		if ( $offset < 0 ) {
			$offset = 0;
		}

		$logger = SScribe_Logger::instance( true );
		$logs   = $logger->get_logs();

		$entries = $this->parse_log_entries( $logs, $filter_level, $search );

		wp_send_json_success(
			array(
				'entries' => array_slice( $entries, $offset, $limit ),
				'count'   => count( $entries ),
				'offset'  => $offset,
				'limit'   => $limit,
			)
		);
	}

	/**
	 * AJAX: Clear debug logs.
	 */
	public function ajax_debug_clear_logs(): void {
		check_ajax_referer( 'sscribe_export_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'sscribe-export-site-pages' ) ) );
		}

		$rate_limiter = new SScribe_Export_Rate_Limiter();
		if ( ! $rate_limiter->check_rate_limit() ) {
			wp_send_json_error( array( 'message' => __( 'Rate limit exceeded. Please wait before trying again.', 'sscribe-export-site-pages' ) ) );
		}

		$logger = SScribe_Logger::instance( true );
		$logger->clear_logs();

		wp_send_json_success();
	}

	/**
	 * AJAX: Export logs as JSON.
	 */
	public function ajax_debug_export_logs(): void {
		check_ajax_referer( 'sscribe_export_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'sscribe-export-site-pages' ) );
		}

		$rate_limiter = new SScribe_Export_Rate_Limiter();
		if ( ! $rate_limiter->check_rate_limit() ) {
			wp_die( esc_html__( 'Rate limit exceeded. Please wait before trying again.', 'sscribe-export-site-pages' ) );
		}

		$filename = isset( $_GET['filename'] ) ? sanitize_text_field( wp_unslash( $_GET['filename'] ) ) : '';

		if ( ! empty( $filename ) ) {
			$upload_dir = wp_upload_dir();
			$log_dir    = $upload_dir['basedir'] . '/sscribe-logs';
			$file_path  = $log_dir . '/' . $filename;

			if ( file_exists( $file_path ) && 0 === strpos( realpath( $file_path ), realpath( $log_dir ) ) ) {
				$content = file_get_contents( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				$this->download_json( $filename, $content );
			}
		}

		$filter_level = isset( $_GET['filter_level'] ) ? sanitize_text_field( wp_unslash( $_GET['filter_level'] ) ) : 'ALL';
		$search       = isset( $_GET['search'] ) ? sanitize_text_field( wp_unslash( $_GET['search'] ) ) : '';
		$offset       = isset( $_GET['offset'] ) ? absint( $_GET['offset'] ) : 0;
		$limit        = isset( $_GET['limit'] ) ? absint( $_GET['limit'] ) : 100;

		if ( $limit < 1 || $limit > 500 ) {
			$limit = 100;
		}
		if ( $offset < 0 ) {
			$offset = 0;
		}

		$logger = SScribe_Logger::instance( true );
		$logs   = $logger->get_logs();

		$entries = $this->parse_log_entries( $logs, $filter_level, $search );

		wp_send_json_success(
			array(
				'entries' => array_slice( $entries, $offset, $limit ),
				'count'   => count( $entries ),
			)
		);
	}

	/**
	 * AJAX: Get rotated log files list.
	 */
	public function ajax_debug_get_files(): void {
		check_ajax_referer( 'sscribe_export_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'sscribe-export-site-pages' ) ) );
		}

		$rate_limiter = new SScribe_Export_Rate_Limiter();
		if ( ! $rate_limiter->check_rate_limit() ) {
			wp_send_json_error( array( 'message' => __( 'Rate limit exceeded. Please wait before trying again.', 'sscribe-export-site-pages' ) ) );
		}

		$upload_dir = wp_upload_dir();
		$log_dir    = $upload_dir['basedir'] . '/sscribe-logs';

		if ( ! is_dir( $log_dir ) ) {
			wp_send_json_success( array( 'files' => array() ) );
		}

		$files  = glob( $log_dir . '/*_debug_*.log' );
		$result = array();

		if ( is_array( $files ) ) {
			foreach ( $files as $file ) {
				if ( is_file( $file ) ) {
					$stat       = stat( $file );
					$result[]   = array(
						'name' => basename( $file ),
						'size' => size_format( $stat['size'] ),
						'date' => wp_date( 'Y-m-d H:i:s', $stat['mtime'] ),
					);
				}
			}
		}

		usort(
			$result,
			function ( $a, $b ) {
				return strcmp( $b['date'], $a['date'] );
			}
		);

		wp_send_json_success( array( 'files' => $result ) );
	}

	/**
	 * AJAX: Fetch content of a rotated log.
	 */
	public function ajax_debug_fetch_rotated(): void {
		check_ajax_referer( 'sscribe_export_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'sscribe-export-site-pages' ) ) );
		}

		$rate_limiter = new SScribe_Export_Rate_Limiter();
		if ( ! $rate_limiter->check_rate_limit() ) {
			wp_send_json_error( array( 'message' => __( 'Rate limit exceeded. Please wait before trying again.', 'sscribe-export-site-pages' ) ) );
		}

		$filename = isset( $_GET['filename'] ) ? sanitize_text_field( wp_unslash( $_GET['filename'] ) ) : '';
		$offset   = isset( $_GET['offset'] ) ? absint( $_GET['offset'] ) : 0;
		$limit    = isset( $_GET['limit'] ) ? absint( $_GET['limit'] ) : 100;

		if ( empty( $filename ) ) {
			wp_send_json_error( array( 'message' => __( 'Filename required.', 'sscribe-export-site-pages' ) ) );
		}

		if ( $limit < 1 || $limit > 500 ) {
			$limit = 100;
		}
		if ( $offset < 0 ) {
			$offset = 0;
		}

		$upload_dir = wp_upload_dir();
		$log_dir    = $upload_dir['basedir'] . '/sscribe-logs';
		$file_path  = $log_dir . '/' . $filename;

		if ( ! file_exists( $file_path ) || 0 !== strpos( realpath( $file_path ), realpath( $log_dir ) ) ) {
			wp_send_json_error( array( 'message' => __( 'File not found.', 'sscribe-export-site-pages' ) ) );
		}

		$content = file_get_contents( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$entries = $this->parse_log_entries( explode( PHP_EOL, $content ), 'ALL', '' );

		wp_send_json_success(
			array(
				'entries' => array_slice( $entries, $offset, $limit ),
				'count'   => count( $entries ),
			)
		);
	}

	/**
	 * AJAX: Delete a rotated log file.
	 */
	public function ajax_debug_delete_rotated(): void {
		check_ajax_referer( 'sscribe_export_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'sscribe-export-site-pages' ) ) );
		}

		$rate_limiter = new SScribe_Export_Rate_Limiter();
		if ( ! $rate_limiter->check_rate_limit() ) {
			wp_send_json_error( array( 'message' => __( 'Rate limit exceeded. Please wait before trying again.', 'sscribe-export-site-pages' ) ) );
		}

		$filename = isset( $_POST['filename'] ) ? sanitize_text_field( wp_unslash( $_POST['filename'] ) ) : '';

		if ( empty( $filename ) ) {
			wp_send_json_error( array( 'message' => __( 'Filename required.', 'sscribe-export-site-pages' ) ) );
		}

		$upload_dir = wp_upload_dir();
		$log_dir    = $upload_dir['basedir'] . '/sscribe-logs';
		$file_path  = $log_dir . '/' . $filename;

		if ( ! file_exists( $file_path ) || 0 !== strpos( realpath( $file_path ), realpath( $log_dir ) ) ) {
			wp_send_json_error( array( 'message' => __( 'File not found.', 'sscribe-export-site-pages' ) ) );
		}

		if ( wp_delete_file( $file_path ) ) {
			wp_send_json_success();
		} else {
			wp_send_json_error( array( 'message' => __( 'Failed to delete file.', 'sscribe-export-site-pages' ) ) );
		}
	}

	/**
	 * Parse log entries from raw log lines.
	 *
	 * @param array  $lines        Raw log lines.
	 * @param string $filter_level Level filter.
	 * @param string $search       Search query.
	 * @return array Parsed entries.
	 */
	private function parse_log_entries( array $lines, string $filter_level, string $search ): array {
		$entries = array();

		foreach ( $lines as $line ) {
			if ( empty( trim( $line ) ) ) {
				continue;
			}

			$entry = $this->parse_log_line( $line );

			if ( 'ALL' !== $filter_level && strtoupper( $entry['level'] ) !== $filter_level ) {
				continue;
			}

			if ( ! empty( $search ) ) {
				$search_lower = strtolower( $search );
				$message      = strtolower( $entry['message'] );
				$context_json = json_encode( $entry['context'] );

				if ( false === strpos( $message, $search_lower )
					&& false === strpos( $context_json, $search_lower )
				) {
					continue;
				}
			}

			$entries[] = $entry;
		}

		return array_reverse( $entries );
	}

	/**
	 * Parse a single log line.
	 *
	 * @param string $line Raw log line.
	 * @return array Parsed entry.
	 */
	private function parse_log_line( string $line ): array {
		$json = json_decode( $line, true );
		if ( is_array( $json ) ) {
			return array(
				'timestamp' => $json['timestamp'] ?? $json['time'] ?? '',
				'level'     => $json['level'] ?? 'INFO',
				'message'   => $json['message'] ?? '',
				'context'   => $json['context'] ?? array(),
			);
		}

		if ( preg_match( '/^\[([^\]]+)\]\s+\[([^\]]+)\]\s+(.+?)(?:\s*\|\s*({.+}))?$/s', $line, $matches ) ) {
			$context = array();
			if ( ! empty( $matches[4] ) ) {
				$context = json_decode( $matches[4], true ) ?: array();
			}

			return array(
				'timestamp' => $matches[1],
				'level'     => $matches[2],
				'message'   => $matches[3],
				'context'   => $context,
			);
		}

		return array(
			'timestamp' => '',
			'level'     => 'INFO',
			'message'   => $line,
			'context'   => array(),
		);
	}

	/**
	 * Output JSON as downloadable file.
	 *
	 * @param string $filename Filename.
	 * @param string $content  JSON content.
	 */
	private function download_json( string $filename, string $content ): void {
		header( 'Content-Type: application/json' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . strlen( $content ) );
		header( 'Cache-Control: no-cache' );

		echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		wp_die();
	}
}
