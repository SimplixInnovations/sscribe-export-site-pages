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
	 * Hard ceiling on rotated log file size we will open for paginated reads.
	 *
	 * Files above this size are still listed in the rotated-logs tab (sizes
	 * use only stat()/filesize()), but the contents endpoint refuses with
	 * 413 Payload Too Large. The logger normally rotates at 2 MB; the 10 MB
	 * ceiling also protects sites with a low PHP memory limit if a file was
	 * placed in the directory manually.
	 */
	private const MAX_ROTATED_LOG_BYTES = 10 * 1024 * 1024;

	/**
	 * Get required capability for debug actions.
	 *
	 * @return string
	 */
	private static function get_debug_capability(): string {
		return 'manage_options';
	}

	/**
	 * Resolve the plugin-owned debug log directory.
	 *
	 * @return string|null Absolute directory path, or null when private storage is unavailable.
	 */
	private static function get_log_directory(): ?string {
		$log_directory = SScribe_Private_Storage::get_subdirectory( 'logs', false );
		return '' === $log_directory || is_link( $log_directory ) ? null : $log_directory;
	}

	/**
	 * Read one bounded scalar text value from the AJAX request.
	 *
	 * @param string $key        POST field name.
	 * @param int    $max_length Maximum character count.
	 * @return string
	 */
	private static function get_post_text( string $key, int $max_length = 200 ): string {
		return SScribe_AJAX_Guard::post_text( $key, '', max( 1, $max_length ) );
	}

	/**
	 * Read one bounded non-negative integer from the AJAX request.
	 *
	 * @param string $key     POST field name.
	 * @param int    $default Default value.
	 * @param int    $maximum Maximum accepted value.
	 * @return int
	 */
	private static function get_post_integer( string $key, int $default, int $maximum ): int {
		return SScribe_AJAX_Guard::post_integer( $key, $default, 0, $maximum );
	}

	/**
	 * Validate an SScribe active or rotated debug-log basename.
	 *
	 * @param string $filename Filename to validate.
	 * @return bool
	 */
	private static function is_debug_log_filename( string $filename ): bool {
		return 1 === preg_match( '/^[a-z0-9_-]{1,80}_debug_\d{4}-\d{2}-\d{2}(?:_\d{2}-\d{2}-\d{2})?\.log$/Di', $filename );
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
			$site_tz = wp_timezone();
			return $utc->setTimezone( $site_tz )->format( 'Y-m-d H:i:s' );
		} catch ( Exception $e ) {
			return $utc_timestamp;
		}
	}

	/**
	 * Verify request authorization (nonce, capability, rate limit).
	 *
	 * Calls wp_send_json_error and returns false on failure.
	 *
	 * @param string $rate_bucket         Rate limit bucket identifier.
	 * @param string $required_capability Capability required for this action.
	 * @return bool True if authorized.
	 */
	private function verify_request_authorization( string $rate_bucket = 'debug', string $required_capability = '' ): bool {
		if ( ! check_ajax_referer( 'sscribe_export_nonce', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid security token.', 'sscribe-export-site-pages' ) ), 403 );
			return false;
		}

		$required_capability = '' !== $required_capability ? $required_capability : self::get_debug_capability();
		if ( ! current_user_can( $required_capability ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'sscribe-export-site-pages' ) ), 403 );
			return false;
		}

		$rate_limiter = new SScribe_Export_Rate_Limiter();
		$rate_check   = $rate_limiter->check_rate_limit( $required_capability, $rate_bucket );
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
		if ( ! $this->verify_request_authorization( 'debug_settings', 'manage_options' ) ) {
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
		$log_level      = strtoupper( self::get_post_text( 'log_level', 20 ) );
		if ( ! in_array( $log_level, $allowed_levels, true ) ) {
			$log_level = 'DEBUG';
		}

		$settings = array(
			'debug_enabled' => SScribe_AJAX_Guard::post_boolean( 'debug_enabled' ),
			'log_level'     => $log_level,
			'auto_refresh'  => SScribe_AJAX_Guard::post_boolean( 'auto_refresh' ),
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
	 * requests would shift every later page's contents by one row : entries
	 * could appear twice or be skipped entirely. We now always read the same
	 * `MAX_FETCH` window (capped at 5000 lines) and slice that single window
	 * by `$offset`. This is the same model as `tail -n | less` and is the
	 * best a tail-only reader can offer without byte-offset cursors.
	 *
	 * The response includes a `has_more` flag (true when the window hit the
	 * cap : there *may* be older entries beyond it) so the JS infinite-scroll
	 * observer knows whether to keep asking for the next page.
	 *
	 * @internal
	 */
	public function ajax_debug_fetch_logs(): void {
		if ( ! $this->verify_request_authorization( 'debug_read' ) ) {
			return;
		}

		$filter_level = strtoupper( self::get_post_text( 'filter_level', 20 ) ?: 'ALL' );

		$allowed_levels = array(
			'ALL',
			'AUDIT',
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
		$search     = self::get_post_text( 'search', 200 );
		$session_id = self::get_post_text( 'session_id', 64 );
		$offset     = self::get_post_integer( 'offset', 0, self::MAX_FETCH_LINES );
		$limit      = max( 1, self::get_post_integer( 'limit', 200, 200 ) );

		$logger = SScribe_Logger::instance( true );

		$logs = $logger->get_logs( self::MAX_FETCH_LINES );

		$entries = $this->parse_log_entries( $logs, $filter_level, $search, $session_id, true );
		$total   = count( $entries );

		$has_more = count( $logs ) >= self::MAX_FETCH_LINES;

		$log_file      = $logger->get_log_file();
		$log_exists    = '' !== $log_file && is_file( $log_file ) && ! is_link( $log_file );
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
		if ( ! $this->verify_request_authorization( 'debug_delete', 'manage_options' ) ) {
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
		if ( ! $this->verify_request_authorization( 'debug_read' ) ) {
			return;
		}

		$raw_filename = self::get_post_text( 'filename', 200 );
		$filename     = sanitize_file_name( $raw_filename );
		if ( '' !== $raw_filename && ! hash_equals( $raw_filename, $filename ) ) {
			$filename = '';
		}

		if ( '' !== $raw_filename ) {
			if ( ! self::is_debug_log_filename( $filename ) ) {
				wp_send_json_error(
					array(
						'message' => __( 'Invalid filename.', 'sscribe-export-site-pages' ),
						'nonce'   => wp_create_nonce( 'sscribe_export_nonce' ),
					),
					400
				);
				return;
			}

			$log_dir = self::get_log_directory();
			if ( null === $log_dir ) {
				wp_send_json_error( array( 'message' => __( 'Private log storage is unavailable.', 'sscribe-export-site-pages' ) ), 500 );
				return;
			}
			$file_path  = $log_dir . '/' . $filename;

			$real_file_path = realpath( $file_path );
			$real_log_dir   = realpath( $log_dir );

			if ( false === $real_file_path || false === $real_log_dir || is_link( $file_path ) || ! is_file( $real_file_path ) ) {
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

			$file_size = filesize( $real_file_path );
			if ( false === $file_size || $file_size > self::MAX_ROTATED_LOG_BYTES ) {
				wp_send_json_error(
					array(
						'message' => __( 'Log file is too large to export through the browser.', 'sscribe-export-site-pages' ),
						'nonce'   => wp_create_nonce( 'sscribe_export_nonce' ),
					),
					413
				);
				return;
			}

			$lines = self::read_bounded_log_lines( $real_file_path, self::MAX_FETCH_LINES );
			if ( null === $lines ) {
				wp_send_json_error(
					array(
						'message' => __( 'Failed to read file.', 'sscribe-export-site-pages' ),
						'nonce'   => wp_create_nonce( 'sscribe_export_nonce' ),
					),
					500
				);
				return;
			}
			$entries = $this->parse_log_entries( $lines, 'ALL', '', '', false );
			$content = wp_json_encode(
				array(
					'entries'  => $entries,
					'count'    => count( $entries ),
					'source'   => $filename,
					'exported' => wp_date( 'Y-m-d H:i:s' ),
				)
			);
			if ( false === $content ) {
				wp_send_json_error( array( 'message' => __( 'Failed to encode log data.', 'sscribe-export-site-pages' ) ), 500 );
				return;
			}
			$this->download_json( preg_replace( '/\.log$/i', '.json', $filename ) ?? 'sscribe-debug-export.json', $content );
			return;
		}

		$filter_level = strtoupper( self::get_post_text( 'filter_level', 20 ) ?: 'ALL' );
		if ( ! in_array( $filter_level, array( 'ALL', 'AUDIT', 'DEBUG', 'INFO', 'NOTICE', 'WARNING', 'ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY' ), true ) ) {
			$filter_level = 'ALL';
		}
		$search     = self::get_post_text( 'search', 200 );
		$session_id = self::get_post_text( 'session_id', 64 );

		$logger = SScribe_Logger::instance( true );

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

		$log_dir = self::get_log_directory();
		if ( null === $log_dir ) {
			wp_send_json_error( array( 'message' => __( 'Private log storage is unavailable.', 'sscribe-export-site-pages' ) ), 500 );
			return;
		}

		if ( ! is_dir( $log_dir ) ) {
			wp_send_json_success( array( 'files' => array() ) );
			return;
		}

		$result      = array();
		$current_log = $this->get_logger_log_file();
		$scanned     = 0;
		$truncated   = false;

		try {
			$iterator = new DirectoryIterator( $log_dir );
			foreach ( $iterator as $file ) {
				if ( $file->isDot() ) {
					continue;
				}
				if ( ++$scanned > 500 ) {
					$truncated = true;
					break;
				}
				$basename = $file->getBasename();
				if ( $file->isLink() || ! $file->isFile() || ! self::is_debug_log_filename( $basename ) ) {
					continue;
				}
				if ( ! empty( $current_log ) && $basename === $current_log ) {
					continue;
				}
				$result[] = array(
					'name'  => $basename,
					'size'  => size_format( $file->getSize() ),
					'date'  => wp_date( 'Y-m-d H:i:s', $file->getMTime() ),
					'mtime' => $file->getMTime(),
				);
			}
		} catch ( \Throwable $e ) {
			wp_send_json_error( array( 'message' => __( 'Unable to read the log directory.', 'sscribe-export-site-pages' ) ), 500 );
			return;
		}

		usort(
			$result,
			fn( $a, $b ) => $b['mtime'] <=> $a['mtime']
		);

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
				'truncated'   => $truncated,
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

		$raw_filename = self::get_post_text( 'filename', 200 );
		$filename     = sanitize_file_name( $raw_filename );
		if ( ! hash_equals( $raw_filename, $filename ) ) {
			$filename = '';
		}
		$offset   = self::get_post_integer( 'offset', 0, self::MAX_FETCH_LINES );
		$limit    = max( 1, self::get_post_integer( 'limit', 200, 200 ) );

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

		if ( ! self::is_debug_log_filename( $filename ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Invalid file type.', 'sscribe-export-site-pages' ),
					'nonce'   => wp_create_nonce( 'sscribe_export_nonce' ),
				),
				400
			);
			return;
		}

		$log_dir = self::get_log_directory();
		if ( null === $log_dir ) {
			wp_send_json_error( array( 'message' => __( 'Private log storage is unavailable.', 'sscribe-export-site-pages' ) ), 500 );
			return;
		}
		$file_path  = $log_dir . '/' . $filename;

		$real_file_path = realpath( $file_path );
		$real_log_dir   = realpath( $log_dir );

		$safe_log_dir = rtrim( (string) $real_log_dir, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR;
		if ( false === $real_file_path || false === $real_log_dir || is_link( $file_path ) || ! is_file( $real_file_path ) || 0 !== strpos( $real_file_path, $safe_log_dir ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'File not found.', 'sscribe-export-site-pages' ),
					'nonce'   => wp_create_nonce( 'sscribe_export_nonce' ),
				),
				404
			);
			return;
		}

		$file_size = filesize( $real_file_path );
		if ( false === $file_size ) {
			wp_send_json_error(
				array(
					'message' => __( 'Unable to stat file.', 'sscribe-export-site-pages' ),
					'nonce'   => wp_create_nonce( 'sscribe_export_nonce' ),
				),
				500
			);
			return;
		}

		// Hard ceiling on bytes we will open for paginated reads.
		// Rotated logs are bounded by MAX_LOG_FILE_SIZE (2MB) at the
		// logger, so anything over 10MB here means manual files were
		// dropped into the directory - refuse rather than exhausting memory.
		if ( $file_size > self::MAX_ROTATED_LOG_BYTES ) {
			wp_send_json_error(
				array(
					/* translators: %s: file size in MB */
					'message' => sprintf( __( 'Rotated log is %s MB and exceeds the safe browser-read limit.', 'sscribe-export-site-pages' ), (string) (int) ( $file_size / ( 1024 * 1024 ) ) ),
					'nonce'   => wp_create_nonce( 'sscribe_export_nonce' ),
				),
				413
			);
			return;
		}

		// Bounded seek-then-read pattern: only the requested slice lives
		// in memory. This is the same pattern SScribe_Logger::get_logs()
		// uses for the active log tail (class-sscribe-logger.php:339).
		try {
			$file = new SplFileObject( $real_file_path, 'r' );
			$file->seek( PHP_INT_MAX );
			$total_lines = $file->key();

			$effective_offset = $offset;
			if ( $effective_offset >= $total_lines ) {
				$effective_offset = max( 0, $total_lines - 1 );
			}

			$file->seek( $effective_offset );
			$raw_lines = array();
			$read      = 0;
			while ( $read < $limit && ! $file->eof() ) {
				$line = $file->current();
				$file->next();
				if ( false !== $line && '' !== trim( $line ) ) {
					$raw_lines[] = rtrim( $line, "\r\n" );
				}
				++$read;
			}
			unset( $file );
		} catch ( \Throwable $e ) {
			wp_send_json_error(
				array(
					'message' => __( 'Failed to read file.', 'sscribe-export-site-pages' ),
					'nonce'   => wp_create_nonce( 'sscribe_export_nonce' ),
				),
				500
			);
			return;
		}

		$entries = $this->parse_log_entries( $raw_lines, 'ALL', '', '', false );

		wp_send_json_success(
			array(
				'entries'          => $entries,
				'count'            => $total_lines,
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
		if ( ! $this->verify_request_authorization( 'debug_delete', 'manage_options' ) ) {
			return;
		}

		$raw_filename = self::get_post_text( 'filename', 200 );
		$filename     = sanitize_file_name( $raw_filename );
		if ( ! hash_equals( $raw_filename, $filename ) ) {
			$filename = '';
		}

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

		if ( ! self::is_debug_log_filename( $filename ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Invalid file type.', 'sscribe-export-site-pages' ),
					'nonce'   => wp_create_nonce( 'sscribe_export_nonce' ),
				),
				400
			);
			return;
		}

		$log_dir = self::get_log_directory();
		if ( null === $log_dir ) {
			wp_send_json_error( array( 'message' => __( 'Private log storage is unavailable.', 'sscribe-export-site-pages' ) ), 500 );
			return;
		}
		$file_path  = $log_dir . '/' . $filename;

		$real_file_path = realpath( $file_path );
		$real_log_dir   = realpath( $log_dir );

		if ( false === $real_file_path || false === $real_log_dir || is_link( $file_path ) || ! is_file( $real_file_path ) ) {
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
	 * Read a bounded tail window from a canonical log file.
	 *
	 * @param string $file_path Canonical regular-file path.
	 * @param int    $max_lines Maximum non-empty lines to return.
	 * @return array<string>|null Lines in file order, or null on failure.
	 */
	private static function read_bounded_log_lines( string $file_path, int $max_lines ): ?array {
		$max_lines = max( 1, min( self::MAX_FETCH_LINES, $max_lines ) );

		try {
			$file = new SplFileObject( $file_path, 'r' );
			$file->seek( PHP_INT_MAX );
			$total_lines = max( 0, $file->key() );
			$file->seek( max( 0, $total_lines - $max_lines ) );

			$lines      = array();
			$line_count = 0;
			while ( $line_count < $max_lines && ! $file->eof() ) {
				$line = $file->current();
				$file->next();
				if ( false !== $line && '' !== trim( $line ) ) {
					$lines[] = rtrim( $line, "\r\n" );
					++$line_count;
				}
			}
			unset( $file );
			return $lines;
		} catch ( \Throwable $e ) {
			return null;
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
		$filter_audit    = ( 'AUDIT' === $filter_level );

		foreach ( $lines as $line ) {
			$line = is_scalar( $line ) ? (string) $line : '';
			if ( empty( trim( $line ) ) ) {
				continue;
			}

			$entry = $this->parse_log_line( $line );

			if ( $filter_audit ) {
				$entry_message = isset( $entry['message'] ) && is_scalar( $entry['message'] ) ? (string) $entry['message'] : '';
				if ( 0 !== strncasecmp( $entry_message, '[AUDIT]', 7 ) ) {
					continue;
				}
			} elseif ( 'ALL' !== $filter_level && null !== $filter_priority ) {
				$entry_priority = $priorities[ strtoupper( $entry['level'] ) ] ?? 0;
				if ( $entry_priority < $filter_priority ) {
					continue;
				}
			}

			if ( ! empty( $session_id ) ) {
				$entry_session = $entry['context']['session_id'] ?? '';
				$entry_session = is_scalar( $entry_session ) ? (string) $entry_session : '';
				if ( false === strpos( $entry_session, $session_id ) ) {
					continue;
				}
			}

			if ( ! empty( $search ) ) {
				$search_lower  = SScribe_Helpers::mb_strtolower( $search );
				$message       = SScribe_Helpers::mb_strtolower( $entry['message'] );
				$context_json  = wp_json_encode( $entry['context'] );
				$context_json  = ( false === $context_json ) ? '' : $context_json;
				$context_lower = SScribe_Helpers::mb_strtolower( $context_json );

				if ( false === SScribe_Helpers::mb_strpos( $message, $search_lower, 0 )
					&& false === SScribe_Helpers::mb_strpos( $context_lower, $search_lower, 0 )
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

		if ( SScribe_Helpers::mb_strlen( $line ) > 10000 ) {
			return array(
				'timestamp' => '',
				'level'     => 'RAW',
				'message'   => SScribe_Helpers::mb_substr( $line, 0, 200 ) . '... [truncated]',
				'context'   => array(),
			);
		}

		$json = json_decode( $line, true );
		if ( is_array( $json ) ) {
			$raw_timestamp = $json['timestamp'] ?? $json['time'] ?? '';
			$raw_timestamp = is_scalar( $raw_timestamp ) ? mb_substr( (string) $raw_timestamp, 0, 64 ) : '';
			$level         = isset( $json['level'] ) && is_scalar( $json['level'] ) ? strtoupper( mb_substr( (string) $json['level'], 0, 20 ) ) : 'INFO';
			$message       = isset( $json['message'] ) && is_scalar( $json['message'] ) ? mb_substr( (string) $json['message'], 0, 4000 ) : '';
			$context       = isset( $json['context'] ) && is_array( $json['context'] ) ? $json['context'] : array();
			return array(
				'timestamp' => $this->convert_utc_timestamp_to_site_timezone( $raw_timestamp ),
				'level'     => $level,
				'message'   => $message,
				'context'   => $context,
			);
		}

		if ( preg_match( '/^\[([^\]]+)\]\s+\[([^\]]+)\]\s+(.+)$/', $line, $matches ) ) {
			$timestamp = $matches[1];
			$level     = $matches[2];
			$rest      = $matches[3];

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
		header( 'Content-Length: ' . SScribe_Helpers::mb_strlen( $content ) );
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
