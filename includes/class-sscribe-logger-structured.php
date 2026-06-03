<?php
/**
 * SScribe Logger Structured
 *
 * @package SScribe_Export_Site_Pages
 * @license GPL v2 or later
 * @link    https://www.gnu.org/licenses/gpl-2.0.html
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once SSCRIBE_PLUGIN_DIR . 'includes/interfaces/interface-sscribe-logger.php';
require_once SSCRIBE_PLUGIN_DIR . 'includes/traits/trait-sscribe-logger-common.php';

/**
 * Structured JSON logger implementation.
 */
class SScribe_Logger_Structured implements SScribe_Logger_Interface {

	use SScribe_Logger_Common;

	/**
	 * Log level priority mapping.
	 *
	 * @var array<string, int>
	 */
	private const LEVEL_PRIORITY = array(
		self::LEVEL_DEBUG     => 0,
		self::LEVEL_INFO      => 1,
		self::LEVEL_NOTICE    => 2,
		self::LEVEL_WARNING   => 3,
		self::LEVEL_ERROR     => 4,
		self::LEVEL_CRITICAL  => 5,
		self::LEVEL_ALERT     => 6,
		self::LEVEL_EMERGENCY => 7,
	);

	/**
	 * Log directory path.
	 *
	 * @var string
	 */
	private readonly string $log_dir;

	/**
	 * Minimum log level.
	 *
	 * @var string
	 */
	private readonly string $min_level;

	/**
	 * Current request ID.
	 *
	 * @var string
	 */
	private readonly string $request_id;

	/**
	 * Current session ID for context.
	 *
	 * @var string|null
	 */
	private ?string $session_id = null;

	/**
	 * Constructor.
	 *
	 * @param string $min_level Minimum log level to capture.
	 */
	public function __construct( string $min_level = self::LEVEL_ERROR ) {
		$upload_dir       = wp_upload_dir();
		$this->log_dir    = $upload_dir['basedir'] . '/sscribe-logs';
		$this->min_level  = $min_level;
		$this->request_id = $this->get_request_id();

		if ( ! is_dir( $this->log_dir ) ) {
			wp_mkdir_p( $this->log_dir );
		}

		add_action( 'shutdown', array( $this, 'flush' ) );
	}

	/**
	 * Log debug message.
	 *
	 * @param string $message Log message.
	 * @param array  $context Additional context data.
	 */
	public function debug( string $message, array $context = array() ): void {
		$this->log( self::LEVEL_DEBUG, $message, $context );
	}

	/**
	 * Log info message.
	 *
	 * @param string $message Log message.
	 * @param array  $context Additional context data.
	 */
	public function info( string $message, array $context = array() ): void {
		$this->log( self::LEVEL_INFO, $message, $context );
	}

	/**
	 * Log notice message.
	 *
	 * @param string $message Log message.
	 * @param array  $context Additional context data.
	 */
	public function notice( string $message, array $context = array() ): void {
		$this->log( self::LEVEL_NOTICE, $message, $context );
	}

	/**
	 * Log warning message.
	 *
	 * @param string $message Log message.
	 * @param array  $context Additional context data.
	 */
	public function warning( string $message, array $context = array() ): void {
		$this->log( self::LEVEL_WARNING, $message, $context );
	}

	/**
	 * Log error message.
	 *
	 * @param string $message Log message.
	 * @param array  $context Additional context data.
	 */
	public function error( string $message, array $context = array() ): void {
		$this->log( self::LEVEL_ERROR, $message, $context );
	}

	/**
	 * Log critical message.
	 *
	 * @param string $message Log message.
	 * @param array  $context Additional context data.
	 */
	public function critical( string $message, array $context = array() ): void {
		$this->log( self::LEVEL_CRITICAL, $message, $context );
	}

	/**
	 * Log alert message.
	 *
	 * @param string $message Log message.
	 * @param array  $context Additional context data.
	 */
	public function alert( string $message, array $context = array() ): void {
		$this->log( self::LEVEL_ALERT, $message, $context );
	}

	/**
	 * Log emergency message.
	 *
	 * @param string $message Log message.
	 * @param array  $context Additional context data.
	 */
	public function emergency( string $message, array $context = array() ): void {
		$this->log( self::LEVEL_EMERGENCY, $message, $context );
	}

	/**
	 * Check if logger is enabled.
	 *
	 * @return bool True if logger is active.
	 */
	public function is_enabled(): bool {
		return true;
	}

	/**
	 * Set session ID for log context.
	 *
	 * @param string $session_id Session identifier.
	 */
	public function set_session_id( string $session_id ): void {
		$this->session_id = $session_id;
	}

	/**
	 * Check if a log level should be captured.
	 *
	 * @param string $level Log level to check.
	 * @return bool True if level meets minimum threshold.
	 */
	private function should_log( string $level ): bool {
		$current = self::LEVEL_PRIORITY[ $this->min_level ] ?? 1;
		$check   = self::LEVEL_PRIORITY[ $level ] ?? 1;
		return $check >= $current;
	}

	/**
	 * Log a message at specified level.
	 *
	 * @param string $level   Log level.
	 * @param string $message Log message.
	 * @param array  $context Additional context data.
	 */
	public function log( string $level, string $message, array $context = array() ): void {
		if ( ! $this->should_log( $level ) ) {
			return;
		}

		$base_entry = array(
			'timestamp'    => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'level'        => $level,
			'message'      => $message,
			'service'      => 'sscribe-export-site-pages',
			'version'      => defined( 'SSCRIBE_VERSION' ) ? SSCRIBE_VERSION : 'unknown',
			'request_id'   => $this->request_id,
			'php_version'  => PHP_VERSION,
			'memory_usage' => size_format( memory_get_usage( true ) ),
			'memory_peak'  => size_format( memory_get_peak_usage( true ) ),
			'user_id'      => get_current_user_id(),
			'wp_site_url'  => get_option( 'siteurl', '' ),
		);

		if ( null !== $this->session_id ) {
			$base_entry['session_id'] = $this->session_id;
		}

		$entry = array_merge(
			$base_entry,
			$this->sanitize_context( $context )
		);

		error_log( wp_json_encode( $entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Structured logging output.
	}

	/**
	 * Sanitize context data for logging.
	 *
	 * @param array $context Context data to sanitize.
	 * @return array Sanitized context.
	 */
	private function sanitize_context( array $context ): array {
		$sanitized = array();
		foreach ( $context as $key => $value ) {
			if ( is_object( $value ) ) {
				$value = method_exists( $value, '__toString' )
					? (string) $value
					: get_class( $value );
			} elseif ( is_resource( $value ) ) {
				$value = get_resource_type( $value );
			} elseif ( is_array( $value ) ) {
				$value = $this->sanitize_context( $value );
			}
			$sanitized[ sanitize_key( (string) $key ) ] = $value;
		}
		return $sanitized;
	}

	/**
	 * Flush handler on shutdown.
	 */
	public function flush(): void {
	}

	/**
	 * Get recent log entries.
	 *
	 * @param int $limit Maximum number of entries to return.
	 * @return array Recent log entries.
	 */
	public function get_logs( int $limit = 100 ): array {
		return array();
	}

	/**
	 * Clear all log entries (not supported in structured logger).
	 */
	public function clear_logs(): void {
		// Structured logger does not support clearing individual entries.
	}

	/**
	 * Get the current log file path.
	 *
	 * Structured logger writes to error_log rather than files,
	 * so this returns empty string.
	 *
	 * @return string Empty string (no file-based log).
	 */
	public function get_log_file(): string {
		return '';
	}
}
