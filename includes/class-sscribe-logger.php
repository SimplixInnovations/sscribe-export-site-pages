<?php
/**
 * Logging service for SScribe.
 *
 * Uses filesystem log storage with in-memory buffering to optimize performance
 * and prevent database bloat.
 *
 * @package SScribe
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once SSCRIBE_PLUGIN_DIR . 'includes/interfaces/interface-sscribe-logger.php';
require_once SSCRIBE_PLUGIN_DIR . 'includes/traits/trait-sscribe-logger-common.php';

/**
 * Class SScribe_Logger
 *
 * Centralized logging service with buffered filesystem writes.
 *
 * Automatically upgrades to SScribe_Logger_Enhanced when:
 * - Query Monitor is active
 * - Database logging is enabled via SSCRIBE_DB_LOGGING constant
 * - Advanced logging features are requested.
 */
class SScribe_Logger implements SScribe_Logger_Interface {

	use SScribe_Logger_Common;

	/**
	 * Singleton instances keyed by prefix.
	 *
	 * @var array<string, self|SScribe_Logger_Enhanced>
	 */
	private static array $instances = array();

	/**
	 * Per-request log correlation ID.
	 *
	 * @var string|null
	 */
	private static ?string $request_id = null;

	/**
	 * Export session ID for correlation in log entries.
	 *
	 * @var string|null
	 */
	private ?string $session_id = null;

	/**
	 * Log entries buffer.
	 *
	 * @var array
	 */
	private array $buffer = array();

	/**
	 * Log directory path.
	 *
	 * @var string
	 */
	private readonly string $log_dir;

	/**
	 * Whether shutdown hook is registered.
	 *
	 * @var bool
	 */
	private bool $shutdown_registered = false;

	/**
	 * Get or create singleton instance.
	 *
	 * Automatically returns enhanced logger when Query Monitor is active
	 * or database logging is enabled.
	 *
	 * @param bool   $enabled Whether logging is enabled.
	 * @param string $prefix  Optional log entry prefix.
	 * @param array  $options Optional logger options (enable_db, enable_qm, etc).
	 * @return SScribe_Logger_Interface
	 */
	public static function instance( bool $enabled = true, string $prefix = 'sscribe', array $options = array() ): SScribe_Logger_Interface {
		// Use JSON for safe serialization (avoiding PHP object injection risks).
		$key = $prefix . '_' . ( $enabled ? '1' : '0' ) . '_' . md5( wp_json_encode( $options ) );

		if ( ! isset( self::$instances[ $key ] ) ) {
			// Check if we should use enhanced logger.
			$use_enhanced = self::should_use_enhanced();

			if ( $use_enhanced && class_exists( 'SScribe_Logger_Enhanced' ) ) {
				self::$instances[ $key ] = new SScribe_Logger_Enhanced( $options );
			} else {
				self::$instances[ $key ] = new self( $enabled, $prefix );
			}
		}

		return self::$instances[ $key ];
	}

	/**
	 * Determine if enhanced logger should be used.
	 *
	 * @return bool True if enhanced logger should be used.
	 */
	private static function should_use_enhanced(): bool {
		if ( class_exists( 'QM_Collector' ) && ! ( defined( 'QM_DISABLED' ) && QM_DISABLED ) && is_admin() ) {
			return true;
		}

		// Use enhanced if database logging is explicitly enabled.
		if ( defined( 'SSCRIBE_DB_LOGGING' ) && SSCRIBE_DB_LOGGING ) {
			return true;
		}

		// Use enhanced in debug mode.
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'SSCRIBE_DEBUG' ) && SSCRIBE_DEBUG ) {
			return true;
		}

		return false;
	}

	/**
	 * Constructor.
	 *
	 * @param bool   $enabled Whether logging is enabled.
	 * @param string $prefix  Optional log entry prefix.
	 */
	public function __construct(
		private readonly bool $enabled = true,
		private readonly string $prefix = 'sscribe'
	) {
		$upload_dir    = wp_upload_dir();
		$this->log_dir = $upload_dir['basedir'] . '/sscribe-logs';

		if ( $this->enabled ) {
			$this->shutdown_registered = true;
			add_action( 'shutdown', array( $this, 'flush' ) );
		}
	}

	/**
	 * Destructor to ensure buffer is flushed.
	 */
	public function __destruct() {
		$this->flush();
	}

	/**
	 * Get the log file path, ensuring directory exists and is protected.
	 *
	 * @return string
	 */
	private function get_log_file(): string {
		if ( ! file_exists( $this->log_dir ) ) {
			SScribe_Security::protect_directory( $this->log_dir );
		}
		return $this->log_dir . '/' . $this->prefix . '_debug_' . gmdate( 'Y-m-d' ) . '.log';
	}

	/**
	 * Check if logging is enabled.
	 *
	 * @return bool
	 */
	public function is_enabled(): bool {
		return $this->enabled;
	}

	/**
	 * Set the session ID for correlation in log entries.
	 *
	 * When set, all subsequent log entries will include this session_id
	 * in their context data, enabling correlation across export operations.
	 *
	 * @param string $session_id The export session identifier.
	 */
	public function set_session_id( string $session_id ): void {
		$this->session_id = $session_id;
	}

	/**
	 * Log a debug message.
	 *
	 * @param string $message Log message.
	 * @param array  $data    Optional data to include.
	 * @return void
	 */
	public function debug( string $message, array $data = array() ): void {
		$this->log_internal( 'debug', $message, $data );
	}

	/**
	 * Log an info message.
	 *
	 * @param string $message Log message.
	 * @param array  $data    Optional data to include.
	 * @return void
	 */
	public function info( string $message, array $data = array() ): void {
		$this->log_internal( 'info', $message, $data );
	}

	/**
	 * Log a notice message.
	 *
	 * @param string $message Log message.
	 * @param array  $data    Optional data to include.
	 * @return void
	 */
	public function notice( string $message, array $data = array() ): void {
		$this->log_internal( 'notice', $message, $data );
	}

	/**
	 * Log a warning message.
	 *
	 * @param string $message Log message.
	 * @param array  $data    Optional data to include.
	 * @return void
	 */
	public function warning( string $message, array $data = array() ): void {
		$this->log_internal( 'warning', $message, $data );
	}

	/**
	 * Log an error message.
	 *
	 * @param string $message Log message.
	 * @param array  $data    Optional data to include.
	 * @return void
	 */
	public function error( string $message, array $data = array() ): void {
		$this->log_internal( 'error', $message, $data );
	}

	/**
	 * Log a critical message.
	 *
	 * @param string $message Log message.
	 * @param array  $data    Optional data to include.
	 * @return void
	 */
	public function critical( string $message, array $data = array() ): void {
		$this->error( 'CRITICAL: ' . $message, $data );
	}

	/**
	 * Log an alert message.
	 *
	 * @param string $message Log message.
	 * @param array  $data    Optional data to include.
	 * @return void
	 */
	public function alert( string $message, array $data = array() ): void {
		$this->log_internal( 'alert', $message, $data );
	}

	/**
	 * Log an emergency message.
	 *
	 * @param string $message Log message.
	 * @param array  $data    Optional data to include.
	 * @return void
	 */
	public function emergency( string $message, array $data = array() ): void {
		$this->log_internal( 'emergency', $message, $data );
	}

	/**
	 * Log a message with a specific level (PSR-3 compatible).
	 *
	 * @param string $level   Log level.
	 * @param string $message Log message.
	 * @param array  $data    Optional data.
	 * @return void
	 */
	public function log( string $level, string $message, array $data = array() ): void {
		$this->log_internal( $level, $message, $data );
	}

	/**
	 * Buffer a log entry (internal method).
	 *
	 * @param string $level   Log level.
	 * @param string $message Log message.
	 * @param array  $data    Optional data.
	 * @return void
	 */
	private function log_internal( string $level, string $message, array $data = array() ): void {
		if ( ! $this->enabled ) {
			return;
		}

		$data        = array_merge( $this->get_context_enrichment(), $data );
		$timestamp   = gmdate( 'Y-m-d H:i:s' );
		$level_upper = strtoupper( $level );
		$entry       = "[{$timestamp}] [{$level_upper}] {$message}";

		if ( ! empty( $data ) ) {
			$entry .= ' | ' . wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		}

		$this->buffer[] = $entry;
	}

	/**
	 * Get standard context enrichment for log entries.
	 *
	 * @return array<string, string>
	 */
	private function get_context_enrichment(): array {
		$context = array(
			'plugin_version' => defined( 'SSCRIBE_VERSION' ) ? (string) SSCRIBE_VERSION : 'unknown',
			'php_version'    => PHP_VERSION,
			'memory_usage'   => size_format( memory_get_usage( true ) ),
			'request_id'     => self::get_request_id(),
		);

		// Include session_id for export operation correlation.
		if ( null !== $this->session_id ) {
			$context['session_id'] = $this->session_id;
		}

		return $context;
	}

	/**
	 * Get the per-request correlation ID.
	 *
	 * @return string
	 */
	private static function get_request_id(): string {
		if ( null === self::$request_id ) {
			// Use random_int() instead of wp_rand() for test bootstrap compatibility.
			self::$request_id = substr( md5( microtime( true ) . (string) random_int( 0, PHP_INT_MAX ) ), 0, 12 );
		}

		return self::$request_id;
	}

	/**
	 * Flush buffered logs to the filesystem.
	 *
	 * Implements log rotation: when the file exceeds MAX_LOG_FILE_SIZE,
	 * the current log is renamed to a timestamped backup and a fresh
	 * log is started. A warning entry is written to indicate truncation.
	 *
	 * @return void
	 */
	public function flush(): void {
		if ( empty( $this->buffer ) || ! $this->enabled ) {
			return;
		}

		$log_file = $this->get_log_file();

		// Log rotation: if the file exceeds MAX_LOG_FILE_SIZE, rotate it.
		if ( file_exists( $log_file ) && filesize( $log_file ) >= self::MAX_LOG_FILE_SIZE ) {
			$rotated_file = $this->log_dir . '/' . $this->prefix . '_debug_' . gmdate( 'Y-m-d_H-i-s' ) . '.log';
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rename -- Safe filesystem rename for log rotation.
			rename( $log_file, $rotated_file );

			// Write a warning entry to the new fresh log indicating that rotation occurred.
			$warning_entry = sprintf(
				"[%s] [WARNING] Log file exceeded %s bytes — rotated to %s\n",
				gmdate( 'Y-m-d H:i:s' ),
				size_format( self::MAX_LOG_FILE_SIZE ),
				basename( $rotated_file )
			);
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Required for debug logging per plugin requirements.
			file_put_contents( $log_file, $warning_entry, LOCK_EX );
		}

		$content = implode( PHP_EOL, $this->buffer ) . PHP_EOL;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Required for debug logging per plugin requirements.
		$result = file_put_contents( $log_file, $content, FILE_APPEND | LOCK_EX );
		if ( false === $result ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Reporting flush failure when file_put_contents fails; no better alternative in production.
			error_log( 'SScribe_Logger: Failed to flush log to ' . $log_file );
		}

		$this->buffer = array();
	}

	/**
	 * Get log entries (buffered + flushed to file).
	 *
	 * Returns in-memory buffered entries merged with any previously flushed
	 * entries from the log file, so callers always see the full picture.
	 * Returns an empty array when logging is disabled.
	 *
	 * @return array Log entries.
	 */
	public function get_logs(): array {
		if ( ! $this->enabled ) {
			return array();
		}

		$file_entries = array();
		$log_file     = $this->get_log_file();

		if ( file_exists( $log_file ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Safe filesystem read.
			$contents = file_get_contents( $log_file );
			if ( $contents ) {
				$file_entries = explode( PHP_EOL, trim( $contents ) );
			}
		}

		return array_merge( $file_entries, $this->buffer );
	}

	/**
	 * Clear log entries for the current day.
	 *
	 * @return void
	 */
	public function clear_logs(): void {
		$this->buffer = array();
		if ( ! $this->enabled ) {
			return;
		}
		$log_file = $this->get_log_file();
		if ( file_exists( $log_file ) ) {
			wp_delete_file( $log_file );
		}
	}

	/**
	 * Clean up old log files.
	 *
	 * @param int $max_age_days Maximum age in days.
	 * @return int Number of logs cleaned.
	 */
	public static function cleanup_old_logs( int $max_age_days = 7 ): int {
		$upload_dir = wp_upload_dir();
		$log_dir    = $upload_dir['basedir'] . '/sscribe-logs';

		if ( ! is_dir( $log_dir ) ) {
			return 0;
		}

		$files   = glob( $log_dir . '/*_debug_*.log' );
		$deleted = 0;
		$max_age = $max_age_days * DAY_IN_SECONDS;
		$now     = time();

		if ( is_array( $files ) ) {
			foreach ( $files as $file ) {
				$file_time = filemtime( $file );
				if ( $file_time && ( $now - $file_time ) > $max_age ) {
					if ( wp_delete_file( $file ) ) {
						++$deleted;
					}
				}
			}
		}

		return $deleted;
	}
}
