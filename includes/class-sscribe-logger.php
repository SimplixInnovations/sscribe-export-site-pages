<?php
/**
 * Logging service for SScribe.
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
 * Centralized logging service with file-based output.
 */
class SScribe_Logger implements SScribe_Logger_Interface {

	/**
	 * Whether logging is enabled.
	 *
	 * @var bool
	 */
	private bool $enabled;

	/**
	 * Log directory path.
	 *
	 * @var string
	 */
	private string $log_dir;

	/**
	 * Log file prefix.
	 *
	 * @var string
	 */
	private string $prefix;

	/**
	 * Constructor.
	 *
	 * @param bool   $enabled Whether logging is enabled.
	 * @param string $log_dir Optional custom log directory.
	 * @param string $prefix  Optional log file prefix.
	 */
	public function __construct( bool $enabled = true, string $log_dir = '', string $prefix = 'sscribe' ) {
		$this->enabled = $enabled;
		$this->prefix  = $prefix;

		if ( empty( $log_dir ) && function_exists( 'wp_upload_dir' ) ) {
			$upload_dir   = wp_upload_dir();
			$this->log_dir = trailingslashit( $upload_dir['basedir'] ) . 'sscribe-logs/';
		} else {
			$this->log_dir = trailingslashit( $log_dir );
		}

		$this->ensure_log_directory();
	}

	/**
	 * Ensure log directory exists and is protected.
	 */
	private function ensure_log_directory(): void {
		if ( ! is_dir( $this->log_dir ) ) {
			wp_mkdir_p( $this->log_dir );
		}

		$htaccess = $this->log_dir . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			file_put_contents( $htaccess, "Deny from all\n" );
		}

		$index = $this->log_dir . 'index.html';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, '' );
		}
	}

	/**
	 * Get the log file path.
	 *
	 * @return string
	 */
	public function get_log_file(): string {
		return $this->log_dir . $this->prefix . '-' . gmdate( 'Y-m-d' ) . '.log';
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
	 */
	public function debug( string $message, array $data = array() ): void {
		$this->log( 'DEBUG', $message, $data );
	}

	/**
	 * Log an error message.
	 *
	 * @param string $message Log message.
	 * @param array  $data    Optional data to include.
	 */
	public function error( string $message, array $data = array() ): void {
		$this->log( 'ERROR', $message, $data );
	}

	/**
	 * Write to log file.
	 *
	 * @param string $level   Log level.
	 * @param string $message Log message.
	 * @param array  $data    Optional data.
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

		$entry .= "\n";

		$result = file_put_contents( $this->get_log_file(), $entry, FILE_APPEND | LOCK_EX );

		if ( $result === false && $this->enabled ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'SScribe: Failed to write to log file: ' . $this->get_log_file() );
		}
	}
}
