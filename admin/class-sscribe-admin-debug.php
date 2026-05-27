<?php
/**
 * SScribe Admin Debug Console
 *
 * Handles debug AJAX endpoints, log parsing, and log file management.
 * Extracted from SScribe_Admin to reduce class size and improve maintainability.
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
 * Debug console handler for SScribe admin.
 */
class SScribe_Admin_Debug {

	/**
	 * Get required capability for debug actions.
	 *
	 * @return string
	 */
	private function get_export_capability(): string {
		return SScribe_Capabilities::get_required();
	}

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
		if ( ! check_ajax_referer( 'sscribe_export_nonce', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid security token.', 'sscribe-export-site-pages' ) ), 403 );
			return;
		}

		if ( ! current_user_can( $this->get_export_capability() ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'sscribe-export-site-pages' ) ), 403 );
			return;
		}

		$rate_limiter = new SScribe_Export_Rate_Limiter();
		if ( ! $rate_limiter->check_rate_limit( $this->get_export_capability(), 'debug' ) ) {
			wp_send_json_error( array( 'message' => __( 'Rate limit exceeded. Please wait before trying again.', 'sscribe-export-site-pages' ) ), 429 );
			return;
		}

		$allowed_levels = array(
			SScribe_Settings::LEVEL_ALL,
			SScribe_Settings::LEVEL_DEBUG,
			SScribe_Settings::LEVEL_INFO,
			SScribe_Settings::LEVEL_NOTICE,
			SScribe_Settings::LEVEL_WARNING,
			SScribe_Settings::LEVEL_ERROR,
			SScribe_Settings::LEVEL_CRITICAL,
		);
		$log_level = isset( $_POST['log_level'] ) ? sanitize_text_field( wp_unslash( $_POST['log_level'] ) ) : 'DEBUG';
		if ( ! in_array( $log_level, $allowed_levels, true ) ) {
			$log_level = 'DEBUG';
		}

		$settings = array(
			'debug_enabled' => isset( $_POST['debug_enabled'] ) ? filter_var( wp_unslash( $_POST['debug_enabled'] ), FILTER_VALIDATE_BOOLEAN ) : false,
			'log_level'     => $log_level,
			'auto_refresh'  => isset( $_POST['auto_refresh'] ) ? filter_var( wp_unslash( $_POST['auto_refresh'] ), FILTER_VALIDATE_BOOLEAN ) : true,
		);

		$saved = SScribe_Settings::save_debug_settings( $settings );

		if ( $saved ) {
			wp_send_json_success( SScribe_Settings::get_debug_settings() );
		} else {
			wp_send_json_error( array( 'message' => __( 'Failed to save settings.', 'sscribe-export-site-pages' ) ), 500 );
			return;
		}
	}

	/**
	 * AJAX: Fetch debug logs.
	 */
	public function ajax_debug_fetch_logs(): void {
		if ( ! check_ajax_referer( 'sscribe_export_nonce', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid security token.', 'sscribe-export-site-pages' ) ), 403 );
			return;
		}

		if ( ! current_user_can( $this->get_export_capability() ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'sscribe-export-site-pages' ) ), 403 );
			return;
		}

		$rate_limiter = new SScribe_Export_Rate_Limiter();
		if ( ! $rate_limiter->check_rate_limit( $this->get_export_capability(), 'debug' ) ) {
			wp_send_json_error( array( 'message' => __( 'Rate limit exceeded. Please wait before trying again.', 'sscribe-export-site-pages' ) ), 429 );
			return;
		}

		$filter_level = isset( $_POST['filter_level'] ) ? sanitize_text_field( wp_unslash( $_POST['filter_level'] ) ) : 'ALL';
		$search       = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
		$session_id   = isset( $_POST['session_id'] ) ? sanitize_text_field( wp_unslash( $_POST['session_id'] ) ) : '';
		$offset       = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
		$limit        = isset( $_POST['limit'] ) ? absint( $_POST['limit'] ) : 500;

		if ( $limit < 1 || $limit > 500 ) {
			$limit = 500;
		}

		// Always pass true when reading logs - user is authenticated and authorized to view them.
		$logger = SScribe_Logger::instance( true );
		$logs   = $logger->get_logs();

		$entries = $this->parse_log_entries( $logs, $filter_level, $search, $session_id, true );

		// Determine log file existence for status reporting.
		$upload_dir  = wp_upload_dir();
		$log_dir     = $upload_dir['basedir'] . '/sscribe-logs';
		$log_file    = $log_dir . '/sscribe_debug_' . gmdate( 'Y-m-d' ) . '.log';
		$log_exists  = file_exists( $log_file );
		$debug_enabled = SScribe_Settings::is_debug_enabled() || ( defined( 'SSCRIBE_DEBUG' ) && SSCRIBE_DEBUG );

		wp_send_json_success(
			array(
				'entries'       => array_slice( $entries, $offset, $limit ),
				'count'         => count( $entries ),
				'offset'        => $offset,
				'limit'         => $limit,
				'status'        => $log_exists ? 'ok' : 'no_log_file',
				'debug_enabled'  => $debug_enabled,
			)
		);
	}

	/**
	 * AJAX: Clear debug logs.
	 */
	public function ajax_debug_clear_logs(): void {
		if ( ! check_ajax_referer( 'sscribe_export_nonce', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid security token.', 'sscribe-export-site-pages' ) ), 403 );
			return;
		}

		if ( ! current_user_can( $this->get_export_capability() ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'sscribe-export-site-pages' ) ), 403 );
			return;
		}

		$rate_limiter = new SScribe_Export_Rate_Limiter();
		if ( ! $rate_limiter->check_rate_limit( $this->get_export_capability(), 'debug' ) ) {
			wp_send_json_error( array( 'message' => __( 'Rate limit exceeded. Please wait before trying again.', 'sscribe-export-site-pages' ) ), 429 );
			return;
		}

		$logger = SScribe_Logger::instance( true );
		$logger->clear_logs();

		wp_send_json_success();
	}

	/**
	 * AJAX: Export logs as JSON.
	 */
	public function ajax_debug_export_logs(): void {
		if ( ! check_ajax_referer( 'sscribe_export_nonce', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid security token.', 'sscribe-export-site-pages' ) ), 403 );
			return;
		}

		if ( ! current_user_can( $this->get_export_capability() ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'sscribe-export-site-pages' ) ), 403 );
			return;
		}

		$rate_limiter = new SScribe_Export_Rate_Limiter();
		if ( ! $rate_limiter->check_rate_limit( $this->get_export_capability(), 'debug' ) ) {
			wp_send_json_error( array( 'message' => __( 'Rate limit exceeded. Please wait before trying again.', 'sscribe-export-site-pages' ) ), 429 );
			return;
		}

		$filename = isset( $_POST['filename'] ) ? sanitize_text_field( wp_unslash( $_POST['filename'] ) ) : '';

		if ( ! empty( $filename ) ) {
			$upload_dir = wp_upload_dir();
			$log_dir    = $upload_dir['basedir'] . '/sscribe-logs';
			$file_path  = $log_dir . '/' . $filename;

			$real_file_path = realpath( $file_path );
			$real_log_dir   = realpath( $log_dir );

			if ( false === $real_file_path || false === $real_log_dir ) {
				wp_send_json_error( array( 'message' => __( 'File not found.', 'sscribe-export-site-pages' ) ), 404 );
				return;
			}

			if ( ! preg_match( '/\.(log|json)$/', $filename ) ) {
				wp_send_json_error( array( 'message' => __( 'Invalid file type.', 'sscribe-export-site-pages' ) ), 400 );
				return;
			}

			if ( 0 === strpos( $real_file_path, $real_log_dir . DIRECTORY_SEPARATOR ) ) {
				$content = file_get_contents( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local log file for download.
				if ( false === $content ) {
					wp_send_json_error( array( 'message' => __( 'Failed to read file.', 'sscribe-export-site-pages' ) ), 500 );
					return;
				}
				$this->download_json( $filename, $content );
				return; // Defense in depth: prevent fallthrough to second download block.
			} else {
				wp_send_json_error( array( 'message' => __( 'File not found.', 'sscribe-export-site-pages' ) ), 404 );
				return;
			}
		}

		$filter_level = isset( $_POST['filter_level'] ) ? sanitize_text_field( wp_unslash( $_POST['filter_level'] ) ) : 'ALL';
		$search       = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
		$session_id   = isset( $_POST['session_id'] ) ? sanitize_text_field( wp_unslash( $_POST['session_id'] ) ) : '';

		$logger = SScribe_Logger::instance( true );
		$logs   = $logger->get_logs();

		$entries = $this->parse_log_entries( $logs, $filter_level, $search, $session_id, true );

		$json_content = wp_json_encode(
			array(
				'entries'  => $entries,
				'count'    => count( $entries ),
				'exported' => wp_date( 'Y-m-d H:i:s' ),
			)
		);

		if ( false === $json_content ) {
			wp_send_json_error( array( 'message' => __( 'Failed to encode log data.', 'sscribe-export-site-pages' ) ), 500 );
			return;
		}

		$export_filename = 'sscribe-debug-export-' . gmdate( 'Y-m-d-His' ) . '.json';
		$this->download_json( $export_filename, $json_content );
	}

	/**
	 * AJAX: Get rotated log files list.
	 */
		if ( ! check_ajax_referer( 'sscribe_export_nonce', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid security token.', 'sscribe-export-site-pages' ) ), 403 );
			return;
		}

		if ( ! current_user_can( $this->get_export_capability() ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'sscribe-export-site-pages' ) ), 403 );
			return;
		}

		$rate_limiter = new SScribe_Export_Rate_Limiter();
		if ( ! $rate_limiter->check_rate_limit( $this->get_export_capability(), 'debug' ) ) {
			wp_send_json_error( array( 'message' => __( 'Rate limit exceeded. Please wait before trying again.', 'sscribe-export-site-pages' ) ), 429 );
			return;
		}

		$upload_dir = wp_upload_dir();
		$log_dir    = $upload_dir['basedir'] . '/sscribe-logs';

		if ( ! is_dir( $log_dir ) ) {
			wp_send_json_success( array( 'files' => array() ) );
			return;
		}

		$log_files  = glob( $log_dir . '/*.log' ) ?: array();
		$json_files = glob( $log_dir . '/*.json' ) ?: array();
		$files      = array_merge( $log_files, $json_files );
		$result     = array();
		$current_log = 'sscribe_debug_' . gmdate( 'Y-m-d' ) . '.log';

		foreach ( $files as $file ) {
			if ( is_file( $file ) ) {
				$stat = stat( $file );
				if ( false === $stat ) {
					continue;
				}
				$basename = basename( $file );
				if ( $basename === $current_log ) {
					continue;
				}
				$result[] = array(
					'name' => $basename,
					'size' => size_format( $stat['size'] ),
					'date' => wp_date( 'Y-m-d H:i:s', $stat['mtime'] ),
					'mtime' => $stat['mtime'],
				);
			}
		}

		usort(
			$result,
			fn($a, $b) => $b['mtime'] <=> $a['mtime']
		);

		foreach ( $result as &$file_info ) {
			unset( $file_info['mtime'] );
		}
		unset( $file_info );

		wp_send_json_success( array( 'files' => $result ) );
	}

	/**
	 * AJAX: Fetch content of a rotated log.
	 */
	public function ajax_debug_fetch_rotated(): void {
		if ( ! check_ajax_referer( 'sscribe_export_nonce', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid security token.', 'sscribe-export-site-pages' ) ), 403 );
			return;
		}

		if ( ! current_user_can( $this->get_export_capability() ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'sscribe-export-site-pages' ) ), 403 );
			return;
		}

		$rate_limiter = new SScribe_Export_Rate_Limiter();
		if ( ! $rate_limiter->check_rate_limit( $this->get_export_capability(), 'debug' ) ) {
			wp_send_json_error( array( 'message' => __( 'Rate limit exceeded. Please wait before trying again.', 'sscribe-export-site-pages' ) ), 429 );
			return;
		}

		$filename = isset( $_POST['filename'] ) ? sanitize_text_field( wp_unslash( $_POST['filename'] ) ) : '';
		$offset   = absint( $_POST['offset'] ?? 0 );
		$limit    = absint( $_POST['limit'] ?? 100 );

		if ( empty( $filename ) ) {
			wp_send_json_error( array( 'message' => __( 'Filename required.', 'sscribe-export-site-pages' ) ), 400 );
			return;
		}

		if ( ! preg_match( '/\.(log|json)$/', $filename ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid file type.', 'sscribe-export-site-pages' ) ), 400 );
			return;
		}

		if ( $limit < 1 || $limit > 500 ) {
			$limit = 100;
		}

		$upload_dir = wp_upload_dir();
		$log_dir    = $upload_dir['basedir'] . '/sscribe-logs';
		$file_path  = $log_dir . '/' . $filename;

		$real_file_path = realpath( $file_path );
		$real_log_dir   = realpath( $log_dir );

		if ( false === $real_file_path || false === $real_log_dir || 0 !== strpos( $real_file_path, $real_log_dir . DIRECTORY_SEPARATOR ) ) {
			wp_send_json_error( array( 'message' => __( 'File not found.', 'sscribe-export-site-pages' ) ), 404 );
			return;
		}

		$content = file_get_contents( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local rotated log file.
		if ( false === $content ) {
			wp_send_json_error( array( 'message' => __( 'Failed to read file.', 'sscribe-export-site-pages' ) ), 500 );
			return;
		}

		$entries = $this->parse_log_entries( explode( PHP_EOL, $content ), 'ALL', '', '', false );
		$count   = count( $entries );

		// Clamp high offset to valid range.
		if ( $offset >= $count ) {
			$offset = max( 0, $count - 1 );
		}

		wp_send_json_success(
			array(
				'entries' => array_slice( $entries, $offset, $limit ),
				'count'   => $count,
			)
		);
	}

	/**
	 * AJAX: Delete a rotated log file.
	 */
	public function ajax_debug_delete_rotated(): void {
		if ( ! check_ajax_referer( 'sscribe_export_nonce', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid security token.', 'sscribe-export-site-pages' ) ), 403 );
			return;
		}

		if ( ! current_user_can( $this->get_export_capability() ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'sscribe-export-site-pages' ) ), 403 );
			return;
		}

		$rate_limiter = new SScribe_Export_Rate_Limiter();
		if ( ! $rate_limiter->check_rate_limit( $this->get_export_capability(), 'debug' ) ) {
			wp_send_json_error( array( 'message' => __( 'Rate limit exceeded. Please wait before trying again.', 'sscribe-export-site-pages' ) ), 429 );
			return;
		}

		$filename = isset( $_POST['filename'] ) ? sanitize_text_field( wp_unslash( $_POST['filename'] ) ) : '';

		if ( empty( $filename ) ) {
			wp_send_json_error( array( 'message' => __( 'Filename required.', 'sscribe-export-site-pages' ) ), 400 );
			return;
		}

		if ( ! preg_match( '/\.(log|json)$/', $filename ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid file type.', 'sscribe-export-site-pages' ) ), 400 );
			return;
		}

		$upload_dir = wp_upload_dir();
		$log_dir    = $upload_dir['basedir'] . '/sscribe-logs';
		$file_path  = $log_dir . '/' . $filename;

		$real_file_path = realpath( $file_path );
		$real_log_dir   = realpath( $log_dir );

		if ( false === $real_file_path || false === $real_log_dir ) {
			wp_send_json_error( array( 'message' => __( 'File not found.', 'sscribe-export-site-pages' ) ), 404 );
			return;
		}

		$safe_log_dir = rtrim( $real_log_dir, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR;
		if ( 0 !== strpos( $real_file_path, $safe_log_dir ) ) {
			wp_send_json_error( array( 'message' => __( 'File not found.', 'sscribe-export-site-pages' ) ), 404 );
			return;
		}

		// Prevent deletion of the active (non-rotated) log file.
		$current_log = SScribe_Logger::instance( true )->get_log_file();
		if ( 0 === strpos( $real_file_path, $safe_log_dir ) && realpath( $current_log ) === $real_file_path ) {
			wp_send_json_error( array( 'message' => __( 'Cannot delete the active log file.', 'sscribe-export-site-pages' ) ), 403 );
			return;
		}

		wp_delete_file( $file_path );
		if ( file_exists( $file_path ) ) {
			wp_send_json_error( array( 'message' => __( 'Failed to delete file.', 'sscribe-export-site-pages' ) ), 500 );
		} else {
			wp_send_json_success();
		}
	}

	/**
	 * Parse log entries from raw log lines.
	 *
	 * @param array  $lines        Raw log lines.
	 * @param string $filter_level Level filter.
	 * @param string $search       Search query.
	 * @param string $session_id   Session ID filter.
	 * @param bool   $reverse      Whether to reverse entries (newest first).
	 * @return array Parsed entries.
	 */
	private function parse_log_entries( array $lines, string $filter_level, string $search, string $session_id = '', bool $reverse = true ): array {
		$entries = array();

		foreach ( $lines as $line ) {
			if ( empty( trim( $line ) ) ) {
				continue;
			}

			$entry = $this->parse_log_line( $line );

			if ( 'ALL' !== $filter_level && strtoupper( $entry['level'] ) !== $filter_level ) {
				continue;
			}

			if ( ! empty( $session_id ) ) {
				$entry_session = $entry['context']['session_id'] ?? '';
				if ( false === strpos( $entry_session, $session_id ) ) {
					continue;
				}
			}

			if ( ! empty( $search ) ) {
				$search_lower = strtolower( $search );
				$message     = strtolower( $entry['message'] );
				$context_json = wp_json_encode( $entry['context'] ) ?: '';
				$context_lower = strtolower( $context_json );

				if ( false === strpos( $message, $search_lower )
					&& false === strpos( $context_lower, $search_lower )
				) {
					continue;
				}
			}

			$entries[] = $entry;
		}

		return $reverse ? array_reverse( $entries ) : $entries;
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

		// Parse non-JSON log lines: [timestamp] [level] message | {context_json}
		// - Removed /s modifier to prevent . from matching newlines
		// - Changed .+? to [^\n]+? to prevent newline matching without /s
		// - Changed {.+} to \{[^\n]+\} without length cap to prevent backtracking on crafted input
		if ( preg_match( '/^\[([^\]]+)\]\s+\[([^\]]+)\]\s+([^\n]+?)(?:\s*\|\s*(\{[^\n]+\}))?$/', $line, $matches ) ) {
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
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		$safe_filename = preg_replace( '/[\r\n"\x00]/', '', $filename );
		$safe_filename = sanitize_file_name( $safe_filename );
		if ( empty( $safe_filename ) ) {
			$safe_filename = 'export.json';
		}

		header( 'Content-Type: application/json' );
		header( 'Content-Disposition: attachment; filename="' . $safe_filename . '"' );
		header( 'Content-Length: ' . mb_strlen( $content, '8bit' ) );
		header( 'Cache-Control: no-store, no-cache, must-revalidate' );
		header( 'Pragma: no-cache' );
		header( 'X-Content-Type-Options: nosniff' );
		header( "Content-Security-Policy: default-src 'none'" );

		echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		wp_die();
	}
}
