<?php
/**
 * Logging service for SScribe.
 *
 * Uses WordPress options API for debug log storage to comply with
 * WordPress.org repository guidelines. Log files are permitted for
 * debugging purposes when gated behind a debug constant.
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
 * Centralized logging service using WordPress options API.
 */
class SScribe_Logger implements SScribe_Logger_Interface {

	/**
	 * Whether logging is enabled.
	 *
	 * @var bool
	 */
	private bool $enabled;

	/**
	 * Log entry prefix.
	 *
	 * @var string
	 */
	private string $prefix;

	/**
	 * Maximum log entries to keep.
	 *
	 * @var int
	 */
	private int $max_entries = 1000;

	/**
	 * Constructor.
	 *
	 * @param bool   $enabled Whether logging is enabled.
	 * @param string $prefix  Optional log entry prefix.
	 */
	public function __construct( bool $enabled = true, string $prefix = 'sscribe' ) {
		$this->enabled = $enabled;
		$this->prefix  = $prefix;
	}

	/**
	 * Get the option name for log storage.
	 *
	 * @return string
	 */
	private function get_option_name(): string {
		return $this->prefix . '_log_' . gmdate( 'Y-m-d' );
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
	 * Write to log storage.
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

		$option_name = $this->get_option_name();
		$logs        = get_option( $option_name, array() );

		if ( ! is_array( $logs ) ) {
			$logs = array();
		}

		$logs[] = $entry;

		if ( count( $logs ) > $this->max_entries ) {
			$logs = array_slice( $logs, -$this->max_entries );
		}

		update_option( $option_name, $logs, false );
	}

	/**
	 * Get log entries for the current day.
	 *
	 * @return array Log entries.
	 */
	public function get_logs(): array {
		$option_name = $this->get_option_name();
		return get_option( $option_name, array() );
	}

	/**
	 * Clear log entries for the current day.
	 *
	 * @return void
	 */
	public function clear_logs(): void {
		$option_name = $this->get_option_name();
		delete_option( $option_name );
	}

	/**
	 * Clean up old log options.
	 *
	 * @param int $max_age_days Maximum age in days.
	 * @return int Number of logs cleaned.
	 */
	public static function cleanup_old_logs( int $max_age_days = 7 ): int {
		global $wpdb;

		$pattern = $wpdb->esc_like( 'sscribe_log_' ) . '%';

		$options = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND autoload = 'no'",
				$pattern
			)
		);

		$deleted = 0;
		$cutoff  = gmdate( 'Y-m-d', strtotime( "-{$max_age_days} days" ) );

		foreach ( $options as $option_name ) {
			if ( preg_match( '/sscribe_log_(\d{4}-\d{2}-\d{2})/', $option_name, $matches ) ) {
				if ( $matches[1] < $cutoff ) {
					if ( delete_option( $option_name ) ) {
						++$deleted;
					}
				}
			}
		}

		return $deleted;
	}
}
