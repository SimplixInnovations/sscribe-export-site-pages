<?php
declare(strict_types=1);

/**
 * Logging service for SScribe.
 *
 * Uses filesystem log storage with in-memory buffering to optimize performance
 * and prevent database bloat.
 *
 * @package SScribe
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once SSCRIBE_PLUGIN_DIR . 'includes/interfaces/interface-sscribe-logger.php';

/**
 * Class SScribe_Logger
 *
 * Centralized logging service with buffered filesystem writes.
 */
class SScribe_Logger implements SScribe_Logger_Interface {

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
			wp_mkdir_p( $this->log_dir );
			// Protect directory from direct access
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Required to secure the log directory.
			file_put_contents( $this->log_dir . '/.htaccess', 'deny from all' );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Required to secure the log directory.
			file_put_contents( $this->log_dir . '/index.html', '' );
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
	 * Log a debug message.
	 *
	 * @param string $message Log message.
	 * @param array  $data    Optional data to include.
	 * @return void
	 */
	public function debug( string $message, array $data = array() ): void {
		$this->log( 'DEBUG', $message, $data );
	}

	/**
	 * Log an error message.
	 *
	 * @param string $message Log message.
	 * @param array  $data    Optional data to include.
	 * @return void
	 */
	public function error( string $message, array $data = array() ): void {
		$this->log( 'ERROR', $message, $data );
	}

	/**
	 * Buffer a log entry.
	 *
	 * @param string $level   Log level.
	 * @param string $message Log message.
	 * @param array  $data    Optional data.
	 * @return void
	 */
	private function log( string $level, string $message, array $data = array() ): void {
		if ( ! $this->enabled ) {
			return;
		}

		$timestamp = gmdate( 'Y-m-d H:i:s' );
		$entry     = "[{$timestamp}] [{$level}] {$message}";

		if ( ! empty( $data ) ) {
			$entry .= ' | ' . wp_json_encode( $data, JSON_UNESCAPED_UNICODE );
		}

		$this->buffer[] = $entry;
	}

	/**
	 * Flush buffered logs to the filesystem.
	 *
	 * @return void
	 */
	public function flush(): void {
		if ( empty( $this->buffer ) || ! $this->enabled ) {
			return;
		}

		$log_file = $this->get_log_file();
		$content  = implode( PHP_EOL, $this->buffer ) . PHP_EOL;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Required for debug logging per plugin requirements.
		file_put_contents( $log_file, $content, FILE_APPEND | LOCK_EX );

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
