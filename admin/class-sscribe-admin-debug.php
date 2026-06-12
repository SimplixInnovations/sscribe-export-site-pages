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

// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified centrally in verify_request_authorization().

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Debug console handler for SScribe admin.
 */
class SScribe_Admin_Debug {

	/**
	 * Cached logger filename to avoid repeated instantiation.
	 *
	 * @var string|null
	 */
	private ?string $cached_log_filename = null;

	/**
	 * Whether hooks have been registered for this instance.
	 *
	 * @var bool
	 */
	private bool $hooks_registered = false;

	/**
	 * Maximum log lines fetched in a single request.
	 *
	 * Bounded to keep memory use predictable on large log files.
	 * Used by ajax_debug_fetch_logs() and ajax_debug_export_logs().
	 */
	private const MAX_FETCH_LINES = 5000;

	/**
	 * Get required capability for debug actions.
	 *
	 * @return string
	 */
	private static function get_export_capability(): string {
		return SScribe_Capabilities::get_required();
	}

	/**
	 * Convert a UTC timestamp string to the site's local timezone.
	 *
	 * @param string $utc_timestamp Timestamp in 'Y-m-d H:i:s' format from gmdate().
	 * @return string Converted timestamp in 'Y-m-d H:i:s' format, or original if conversion fails.
	 */
	private function convert_utc_timestamp_to_site_timezone( string $utc_timestamp ): string {
		if ( empty( $utc_timestamp ) ) {
			return '';
		}

		try {
			$utc = new DateTimeImmutable( $utc_timestamp, new DateTimeZone( 'UTC' ) );
			$site_tz = wp_timezone(); // Returns DateTimeZone for site's timezone setting.
			return $utc->setTimezone( $site_tz )->format( 'Y-m-d H:i:s' );
		} catch ( Exception $e ) {
			return $utc_timestamp; // Fallback: return as-is if conversion fails.
		}
	}

	/**
	 * Verify request authorization (nonce, capability, rate limit).
	 *
	 * Calls wp_send_json_error and returns false on failure.
	 *
	 * @param string $rate_bucket Rate limit bucket identifier.
	 * @return bool True if authorized.
	 */
	private function verify_request_authorization( string $rate_bucket = 'debug' ): bool {
		if ( ! check_ajax_referer( 'sscribe_export_nonce', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid security token.', 'sscribe-export-site-pages' ) ), 403 );
			return false;
		}

		if ( ! current_user_can( self::get_export_capability() ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'sscribe-export-site-pages' ) ), 403 );
			return false;
		}

		$rate_limiter = new SScribe_Export_Rate_Limiter();
		$rate_check = $rate_limiter->check_rate_limit( self::get_export_capability(), $rate_bucket );
		if ( false === $rate_check ) {
			wp_send_json_error( array( 'message' => __( 'Rate limit exceeded. Please wait before trying again.', 'sscribe-export-site-pages' ) ), 429 );
			return false;
		}

		return true;
	}

	/**
	 * Register all debug AJAX hooks.
	 */
	public function register_hooks(): void {
		if ( $this->hooks_registered ) {
			return;
		}
		$this->hooks_registered = true;

		add_action( 'wp_ajax_sscribe_debug_save_settings', array( $this, 'ajax_debug_save_settings' ) );
		add_action( 'wp_ajax_sscribe_debug_fetch_logs', array( $this, 'ajax_debug_fetch_logs' ) );
		add_action( 'wp_ajax_sscribe_debug_clear_logs', array( $this, 'ajax_debug_clear_logs' ) );
		add_action( 'wp_ajax_sscribe_debug_export_logs', array( $this, 'ajax_debug_export_logs' ) );
		add_action( 'wp_ajax_sscribe_debug_get_files', array( $this, 'ajax_debug_get_rotated_log_files' ) );
		add_action( 'wp_ajax_sscribe_debug_fetch_rotated', array( $this, 'ajax_debug_fetch_rotated' ) );
		add_action( 'wp_ajax_sscribe_debug_delete_rotated', array( $this, 'ajax_debug_delete_rotated' ) );
		add_action( 'wp_ajax_sscribe_debug_refresh_nonce', array( $this, 'ajax_debug_refresh_nonce' ) );
	}

	/**
	 * AJAX: Save debug settings.
	 *
	 * @internal
	 */
	public function ajax_debug_save_settings(): void {
		if ( ! $this->verify_request_authorization( 'debug_settings' ) ) {
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
		$log_level      = isset( $_POST['log_level'] ) ? sanitize_text_field( wp_unslash( $_POST['log_level'] ) ) : 'DEBUG';
		if ( ! in_array( $log_level, $allowed_levels, true ) ) {
			$log_level = 'DEBUG';
		}

		$settings = array(
			'debug_enabled' => (bool) filter_var( wp_unslash( $_POST['debug_enabled'] ?? '' ), FILTER_VALIDATE_BOOLEAN ),
			'log_level'     => $log_level,
			'auto_refresh'  => (bool) filter_var( wp_unslash( $_POST['auto_refresh'] ?? '' ), FILTER_VALIDATE_BOOLEAN ),
		);

		$saved = SScribe_Settings::save_debug_settings( $settings );

		if ( $saved ) {
			$response          = SScribe_Settings::get_debug_settings();
			$response['nonce'] = wp_create_nonce( 'sscribe_export_nonce' );
			wp_send_json_success( $response );
		} else {
			wp_send_json_error(
				array(
					'message' => __( 'Failed to save settings. Please try again or refresh the page.', 'sscribe-export-site-pages' ),
					'nonce'   => wp_create_nonce( 'sscribe_export_nonce' ),
				),
				500
			);
		}
	}

	/**
	 * AJAX: Fetch debug logs.
	 *
	 * Pagination model: tail-read a fixed window of the most recent log
	 * lines, parse + filter, and slice the window into the requested page.
	 *
	 * The previous implementation scaled the tail-read window with `$offset`
	 * (fetch_count = $offset + $limit + 1000). That made different pages read
	 * different windows from the file, so a new log line written between two
	 * requests would shift every later page's contents by one row — entries
	 * could appear twice or be skipped entirely. We now always read the same
	 * `MAX_FETCH` window (capped at 5000 lines) and slice that single window
	 * by `$offset`. This is the same model as `tail -n | less` and is the
	 * best a tail-only reader can offer without byte-offset cursors.
	 *
	 * The response includes a `has_more` flag (true when the window hit the
	 * cap — there *may* be older entries beyond it) so the JS infinite-scroll
	 * observer knows whether to keep asking for the next page.
	 *
	 * @internal
	 */
	public function ajax_debug_fetch_logs(): void {
		if ( ! $this->verify_request_authorization( 'debug_read' ) ) {
			return;
		}

		$filter_level = isset( $_POST['filter_level'] ) ? sanitize_text_field( wp_unslash( $_POST['filter_level'] ) ) : 'ALL';
		$filter_level = strtoupper( $filter_level );
		// Validate against the known level set + ALL. An unknown level
		// (typo, tampered value, removed constant) would otherwise fall
		// through parse_log_entries() with $filter_priority = null and
		// silently bypass level filtering entirely.
		$allowed_levels = array(
			'ALL',
			'DEBUG',
			'INFO',
			'NOTICE',
			'WARNING',
			'ERROR',
			'CRITICAL',
			'ALERT',
			'EMERGENCY',
		);
		if ( ! in_array( $filter_level, $allowed_levels, true ) ) {
			$filter_level = 'ALL';
		}
		$search       = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
		$session_id   = isset( $_POST['session_id'] ) ? sanitize_text_field( wp_unslash( $_POST['session_id'] ) ) : '';
		$offset       = isset( $_POST['offset'] ) ? absint( wp_unslash( $_POST['offset'] ) ) : 0;
		$limit        = isset( $_POST['limit'] ) ? max( 1, min( 200, absint( wp_unslash( $_POST['limit'] ) ) ) ) : 200;

		$logger = SScribe_Logger::instance( true );

		// Always tail-read the same window regardless of $offset, so the
		// window the user is paging through is identical between requests
		// (modulo new lines being written). MAX_FETCH caps memory use on
		// very large log files.
		$logs = $logger->get_logs( self::MAX_FETCH_LINES );

		$entries = $this->parse_log_entries( $logs, $filter_level, $search, $session_id, true );
		$total   = count( $entries );

		// has_more = true when the tail-read hit MAX_FETCH_LINES. The window
		// we held was the LAST 5000 lines of the file, so older entries may
		// exist beyond the window. JS should stop paginating at this point
		// (or surface a "showing latest 5000 entries" notice).
		$has_more = count( $logs ) >= self::MAX_FETCH_LINES;

		$upload_dir    = wp_upload_dir();
		$log_dir       = $upload_dir['basedir'] . '/sscribe-logs';
		$log_file      = $log_dir . '/sscribe_debug_' . gmdate( 'Y-m-d' ) . '.log';
		$log_exists    = file_exists( $log_file );
		$debug_enabled = SScribe_Settings::is_debug_enabled() || ( defined( 'SSCRIBE_DEBUG' ) && SSCRIBE_DEBUG );

		wp_send_json_success(
			array(
				'entries'       => array_slice( $entries, $offset, $limit ),
				'count'         => $total,
				'offset'        => $offset,
				'limit'         => $limit,
				'has_more'      => $has_more,
				'window_cap'    => self::MAX_FETCH_LINES,
				'status'        => $log_exists ? 'ok' : 'no_log_file',
				'debug_enabled' => $debug_enabled,
				'nonce'         => wp_create_nonce( 'sscribe_export_nonce' ),
			)
		);
	}

	/**
	 * AJAX: Clear debug logs.
	 *
	 * @internal
	 */
	public function ajax_debug_clear_logs(): void {
		if ( ! $this->verify_request_authorization() ) {
			return;
		}

		try {
			$logger = SScribe_Logger::instance( true );
			$logger->clear_logs();

			wp_send_json_success(
				array(
					'message' => __( 'Logs cleared.', 'sscribe-export-site-pages' ),
					'nonce'   => wp_create_nonce( 'sscribe_export_nonce' ),
				)
			);
		} catch ( \Throwable $e ) {
			wp_send_json_error(
				array(
					'message' => __( 'Failed to clear logs. Please try again.', 'sscribe-export-site-pages' ),
					'nonce'   => wp_create_nonce( 'sscribe_export_nonce' ),
				),
				500
			);
		}
	}

	/**
	 * AJAX: Export logs as JSON.
	 *
	 * @internal
	 */
	public function ajax_debug_export_logs(): void {
		if ( ! $this->verify_request_authorization() ) {
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
				wp_send_json_error(
					array(
						'message' => __( 'File not found.', 'sscribe-export-site-pages' ),
						'nonce'   => wp_create_nonce( 'sscribe_export_nonce' ),
					),
					404
				);
				return;
			}

			if ( ! preg_match( '/\.(log|json)$/', $filename ) ) {
				wp_send_json_error(
					array(
						'message' => __( 'Invalid file type.', 'sscribe-export-site-pages' ),
						'nonce'   => wp_create_nonce( 'sscribe_export_nonce' ),
					),
					400
				);
				return;
			}

			$safe_log_dir = rtrim( $real_log_dir, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR;
			if ( 0 !== strpos( $real_file_path, $safe_log_dir ) ) {
				wp_send_json_error(
					array(
						'message' => __( 'File not found.', 'sscribe-export-site-pages' ),
						'nonce'   => wp_create_nonce( 'sscribe_export_nonce' ),
					),
					404
				);
				return;
			}

			$content = file_get_contents( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local log file for download.
			if ( false === $content ) {
				wp_send_json_error(
					array(
						'message' => __( 'Failed to read file.', 'sscribe-export-site-pages' ),
						'nonce'   => wp_create_nonce( 'sscribe_export_nonce' ),
					),
					500
				);
				return;
			}
			$this->download_json( $filename, $content );
			return;
		}

		$filter_level = isset( $_POST['filter_level'] ) ? sanitize_text_field( wp_unslash( $_POST['filter_level'] ) ) : 'ALL';
		$search       = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
		$session_id   = isset( $_POST['session_id'] ) ? sanitize_text_field( wp_unslash( $_POST['session_id'] ) ) : '';

		$logger = SScribe_Logger::instance( true );
		// Cap the export at MAX_FETCH_LINES to prevent OOM on sites with
		// months of accumulated debug logs. Same cap as ajax_debug_fetch_logs().
		$logs = $logger->get_logs( self::MAX_FETCH_LINES );

		$entries = $this->parse_log_entries( $logs, $filter_level, $search, $session_id, true );

		$json_content = wp_json_encode(
			array(
				'entries'  => $entries,
				'count'    => count( $entries ),
				'exported' => wp_date( 'Y-m-d H:i:s' ),
			)
		);

		if ( false === $json_content ) {
			wp_send_json_error(
				array(
					'message' => __( 'Failed to encode log data.', 'sscribe-export-site-pages' ),
					'nonce'   => wp_create_nonce( 'sscribe_export_nonce' ),
				),
				500
			);
			return;
		}

		$export_filename = 'sscribe-debug-export-' . gmdate( 'Y-m-d-His' ) . '.json';
		$this->download_json( $export_filename, $json_content );
	}

	/**
	 * AJAX: Refresh the export nonce (call after form-based exports).
	 *
	 * @internal
	 */
	public function ajax_debug_refresh_nonce(): void {
		if ( ! $this->verify_request_authorization() ) {
			return;
		}
		wp_send_json_success(
			array(
				'nonce' => wp_create_nonce( 'sscribe_export_nonce' ),
			)
		);
	}

	/**
	 * AJAX: Get rotated log files list.
	 *
	 * @internal
	 */
	public function ajax_debug_get_rotated_log_files(): void {
		if ( ! $this->verify_request_authorization( 'debug_read' ) ) {
			return;
		}

		$upload_dir = wp_upload_dir();
		$log_dir    = $upload_dir['basedir'] . '/sscribe-logs';

		if ( ! is_dir( $log_dir ) ) {
			wp_send_json_success( array( 'files' => array() ) );
			return;
		}

		$log_files   = glob( $log_dir . '/*.log' );
		$log_files   = is_array( $log_files ) ? $log_files : array();
		$files       = $log_files;
		$result      = array();
		$current_log = $this->get_logger_log_file();

		foreach ( $files as $file ) {
			if ( is_file( $file ) ) {
				$stat = stat( $file );
				if ( false === $stat ) {
					continue;
				}
				$basename = basename( $file );
				if ( ! empty( $current_log ) && $basename === $current_log ) {
					continue;
				}
				$result[] = array(
					'name'  => $basename,
					'size'  => size_format( $stat['size'] ),
					'date'  => wp_date( 'Y-m-d H:i:s', $stat['mtime'] ),
					'mtime' => $stat['mtime'],
				);
			}
		}

		usort(
			$result,
			fn( $a, $b ) => $b['mtime'] <=> $a['mtime']
		);

		// Cap the number of files returned to prevent performance issues.
		$max_files   = 50;
		$total_count = count( $result );
		if ( $total_count > $max_files ) {
			$result = array_slice( $result, 0, $max_files );
		}

		$result = array_map(
			fn( $f ) => array(
				'name' => $f['name'],
				'size' => $f['size'],
				'date' => $f['date'],
			),
			$result
		);

		wp_send_json_success(
			array(
				'files'       => $result,
				'total_count' => $total_count,
			)
		);
	}

	/**
	 * AJAX: Fetch content of a rotated log.
	 *
	 * @internal
	 */
	public function ajax_debug_fetch_rotated(): void {
		if ( ! $this->verify_request_authorization() ) {
			return;
		}

		$filename = isset( $_POST['filename'] ) ? sanitize_text_field( wp_unslash( $_POST['filename'] ) ) : '';
		$offset   = isset( $_POST['offset'] ) ? absint( wp_unslash( $_POST['offset'] ) ) : 0;
		$limit    = isset( $_POST['limit'] ) ? max( 1, min( 200, absint( wp_unslash( $_POST['limit'] ) ) ) ) : 200;

		if ( empty( $filename ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Filename required.', 'sscribe-export-site-pages' ),
					'nonce'   => wp_create_nonce( 'sscribe_export_nonce' ),
				),
				400
			);
			return;
		}

		if ( ! preg_match( '/\.(log|json)$/', $filename ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Invalid file type.', 'sscribe-export-site-pages' ),
					'nonce'   => wp_create_nonce( 'sscribe_export_nonce' ),
				),
				400
			);
			return;
		}

		$upload_dir = wp_upload_dir();
		$log_dir    = $upload_dir['basedir'] . '/sscribe-logs';
		$file_path  = $log_dir . '/' . $filename;

		$real_file_path = realpath( $file_path );
		$real_log_dir   = realpath( $log_dir );

		$safe_log_dir = rtrim( (string) $real_log_dir, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR;
		if ( false === $real_file_path || false === $real_log_dir || 0 !== strpos( $real_file_path, $safe_log_dir ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'File not found.', 'sscribe-export-site-pages' ),
					'nonce'   => wp_create_nonce( 'sscribe_export_nonce' ),
				),
				404
			);
			return;
		}

		$content = file_get_contents( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local rotated log file.
		if ( false === $content ) {
			wp_send_json_error(
				array(
					'message' => __( 'Failed to read file.', 'sscribe-export-site-pages' ),
					'nonce'   => wp_create_nonce( 'sscribe_export_nonce' ),
				),
				500
			);
			return;
		}

		$lines   = explode( PHP_EOL, $content );
		$entries = $this->parse_log_entries( $lines, 'ALL', '', '', false );
		$count   = count( $entries );

		// Clamp high offset to valid range.
		$effective_offset = $offset;
		if ( $effective_offset >= $count ) {
			$effective_offset = max( 0, $count - 1 );
		}

		wp_send_json_success(
			array(
				'entries'          => array_slice( $entries, $effective_offset, $limit ),
				'count'            => $count,
				'effective_offset' => $effective_offset,
			)
		);
	}

	/**
	 * AJAX: Delete a rotated log file.
	 *
	 * @internal
	 */
	public function ajax_debug_delete_rotated(): void {
		if ( ! $this->verify_request_authorization() ) {
			return;
		}

		$filename = isset( $_POST['filename'] ) ? sanitize_text_field( wp_unslash( $_POST['filename'] ) ) : '';

		if ( empty( $filename ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Filename required.', 'sscribe-export-site-pages' ),
					'nonce'   => wp_create_nonce( 'sscribe_export_nonce' ),
				),
				400
			);
			return;
		}

		if ( ! preg_match( '/\.(log|json)$/', $filename ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Invalid file type.', 'sscribe-export-site-pages' ),
					'nonce'   => wp_create_nonce( 'sscribe_export_nonce' ),
				),
				400
			);
			return;
		}

		$upload_dir = wp_upload_dir();
		$log_dir    = $upload_dir['basedir'] . '/sscribe-logs';
		$file_path  = $log_dir . '/' . $filename;

		$real_file_path = realpath( $file_path );
		$real_log_dir   = realpath( $log_dir );

		if ( false === $real_file_path || false === $real_log_dir ) {
			wp_send_json_error(
				array(
					'message' => __( 'File not found.', 'sscribe-export-site-pages' ),
					'nonce'   => wp_create_nonce( 'sscribe_export_nonce' ),
				),
				404
			);
			return;
		}

		$safe_log_dir = rtrim( $real_log_dir, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR;
		if ( 0 !== strpos( $real_file_path, $safe_log_dir ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'File not found.', 'sscribe-export-site-pages' ),
					'nonce'   => wp_create_nonce( 'sscribe_export_nonce' ),
				),
				404
			);
			return;
		}

		// Prevent deletion of the active (non-rotated) log file.
		$logger     = SScribe_Logger::instance( true );
		$active_log = $logger->get_log_file();
		if ( $active_log && realpath( $active_log ) === $real_file_path ) {
			wp_send_json_error(
				array(
					'message' => __( 'Cannot delete the active log file.', 'sscribe-export-site-pages' ),
					'nonce'   => wp_create_nonce( 'sscribe_export_nonce' ),
				),
				403
			);
			return;
		}

		wp_delete_file( $file_path );
		if ( file_exists( $file_path ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Failed to delete file.', 'sscribe-export-site-pages' ),
					'nonce'   => wp_create_nonce( 'sscribe_export_nonce' ),
				),
				500
			);
		} else {
			wp_send_json_success(
				array(
					'message' => __( 'File deleted.', 'sscribe-export-site-pages' ),
					'nonce'   => wp_create_nonce( 'sscribe_export_nonce' ),
				)
			);
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

		$priorities      = array(
			'RAW'       => -1,
			'DEBUG'     => 0,
			'INFO'      => 1,
			'NOTICE'    => 2,
			'WARNING'   => 3,
			'ERROR'     => 4,
			'CRITICAL'  => 5,
			'ALERT'     => 6,
			'EMERGENCY' => 7,
		);
		$filter_priority = $priorities[ $filter_level ] ?? null;

		foreach ( $lines as $line ) {
			if ( empty( trim( $line ) ) ) {
				continue;
			}

			$entry = $this->parse_log_line( $line );

			if ( 'ALL' !== $filter_level && null !== $filter_priority ) {
				$entry_priority = $priorities[ strtoupper( $entry['level'] ) ] ?? 0;
				if ( $entry_priority < $filter_priority ) {
					continue;
				}
			}

			if ( ! empty( $session_id ) ) {
				$entry_session = $entry['context']['session_id'] ?? '';
				if ( false === strpos( $entry_session, $session_id ) ) {
					continue;
				}
			}

			if ( ! empty( $search ) ) {
				$search_lower  = mb_strtolower( $search, 'UTF-8' );
				$message       = mb_strtolower( $entry['message'], 'UTF-8' );
				$context_json  = wp_json_encode( $entry['context'] );
				$context_json  = ( false === $context_json ) ? '' : $context_json;
				$context_lower = mb_strtolower( $context_json, 'UTF-8' );

				if ( false === mb_strpos( $message, $search_lower, 0, 'UTF-8' )
					&& false === mb_strpos( $context_lower, $search_lower, 0, 'UTF-8' )
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
		// Guard against extremely long lines (malformed/binary content).
		if ( mb_strlen( $line ) > 10000 ) {
			return array(
				'timestamp' => '',
				'level'     => 'RAW',
				'message'   => mb_substr( $line, 0, 200 ) . '... [truncated]',
				'context'   => array(),
			);
		}

		$json = json_decode( $line, true );
		if ( is_array( $json ) ) {
			$raw_timestamp = $json['timestamp'] ?? $json['time'] ?? '';
			return array(
				'timestamp' => $this->convert_utc_timestamp_to_site_timezone( $raw_timestamp ),
				'level'     => $json['level'] ?? 'INFO',
				'message'   => $json['message'] ?? '',
				'context'   => $json['context'] ?? array(),
			);
		}

		// Parse non-JSON log lines: [timestamp] [level] message | {context_json}.
		// Try to match the pattern [timestamp] [level] first, then split message from context.
		if ( preg_match( '/^\[([^\]]+)\]\s+\[([^\]]+)\]\s+(.+)$/', $line, $matches ) ) {
			$timestamp = $matches[1];
			$level     = $matches[2];
			$rest      = $matches[3];

			// Check if the rest ends with a JSON context: message | {"key":"value"}
			// Find the last ' | {' that is followed by valid JSON ending with }.
			$context      = array();
			$message_part = $rest;

			$last_sep = strrpos( $rest, ' | {' );
			if ( false !== $last_sep ) {
				$potential_json = substr( $rest, $last_sep + 3 );
				if ( ! empty( $potential_json ) ) {
					$decoded = json_decode( $potential_json, true );
					if ( is_array( $decoded ) ) {
						$context      = $decoded;
						$message_part = substr( $rest, 0, $last_sep );
					}
				}
			}

			return array(
				'timestamp' => $this->convert_utc_timestamp_to_site_timezone( $timestamp ),
				'level'     => $level,
				'message'   => $message_part,
				'context'   => $context,
			);
		}

		// Unparseable line - use RAW level to distinguish from real log entries.
		return array(
			'timestamp' => '',
			'level'     => 'RAW',
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

		// Disable zlib compression to ensure Content-Length is accurate.
		if ( function_exists( 'ini_set' ) ) {
			// phpcs:ignore WordPress.PHP.IniSet.Risky, Squiz.PHP.DiscouragedFunctions.Discouraged
			@ini_set( 'zlib.output_compression', 'Off' );
		}

		$safe_filename = preg_replace( '/[\r\n"\x00]/', '', $filename );
		$safe_filename = sanitize_file_name( $safe_filename );
		if ( empty( $safe_filename ) ) {
			$safe_filename = 'export.json';
		}

		header( 'Content-Type: application/json' );
		header( 'Content-Disposition: attachment; filename="' . $safe_filename . '"' );
		header( 'Content-Encoding: none' );
		header( 'Content-Length: ' . mb_strlen( $content, '8bit' ) );
		header( 'Cache-Control: no-store, no-cache, must-revalidate' );
		header( 'Pragma: no-cache' );
		header( 'X-Content-Type-Options: nosniff' );

		echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		wp_die();
	}

	/**
	 * Get the current logger's log file path.
	 *
	 * Used to identify the active log file that should be excluded from
	 * the rotated logs list and protected from deletion.
	 *
	 * Cache is valid for request lifetime only (new instance per request in standard WordPress).
	 *
	 * @return string Basename of the current log file.
	 */
	private function get_logger_log_file(): string {
		if ( null !== $this->cached_log_filename ) {
			return $this->cached_log_filename;
		}

		$logger                    = SScribe_Logger::instance( true );
		$log_file_path             = $logger->get_log_file();
		$this->cached_log_filename = basename( $log_file_path );
		return $this->cached_log_filename;
	}
}
