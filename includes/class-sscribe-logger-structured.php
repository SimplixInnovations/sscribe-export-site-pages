<?php
/**
 * Structured JSON logging for SScribe.
 *
 * Outputs machine-parseable JSON log entries for integration with
 * log aggregation systems (ELK, Datadog, CloudWatch, etc.).
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
 * Class SScribe_Logger_Structured
 *
 * Feature 4: Structured JSON logger for production observability.
 */
class SScribe_Logger_Structured implements SScribe_Logger_Interface {

	use SScribe_Logger_Common;

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
	 * Minimum log level to record.
	 *
	 * @var string
	 */
	private readonly string $min_level;

	/**
	 * Per-request correlation ID.
	 *
	 * @var string
	 */
	private readonly string $request_id;

	/**
	 * Constructor.
	 *
	 * @param string $min_level Minimum log level to record.
	 */
	public function __construct( string $min_level = self::LEVEL_DEBUG ) {
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
	 * Log a debug message.
	 *
	 * @param string $message Log message.
	 * @param array  $context Additional context data.
	 */
	public function debug( string $message, array $context = array() ): void {
		$this->log( self::LEVEL_DEBUG, $message, $context );
	}

	/**
	 * Log an info message.
	 *
	 * @param string $message Log message.
	 * @param array  $context Additional context data.
	 */
	public function info( string $message, array $context = array() ): void {
		$this->log( self::LEVEL_INFO, $message, $context );
	}

	/**
	 * Log a notice.
	 *
	 * @param string $message Log message.
	 * @param array  $context Additional context data.
	 */
	public function notice( string $message, array $context = array() ): void {
		$this->log( self::LEVEL_NOTICE, $message, $context );
	}

	/**
	 * Log a warning.
	 *
	 * @param string $message Log message.
	 * @param array  $context Additional context data.
	 */
	public function warning( string $message, array $context = array() ): void {
		$this->log( self::LEVEL_WARNING, $message, $context );
	}

	/**
	 * Log an error.
	 *
	 * @param string $message Log message.
	 * @param array  $context Additional context data.
	 */
	public function error( string $message, array $context = array() ): void {
		$this->log( self::LEVEL_ERROR, $message, $context );
	}

	/**
	 * Log a critical/fatal error.
	 *
	 * @param string $message Log message.
	 * @param array  $context Additional context data.
	 */
	public function critical( string $message, array $context = array() ): void {
		$this->log( self::LEVEL_CRITICAL, $message, $context );
	}

	/**
	 * Log an alert message.
	 *
	 * @param string $message Log message.
	 * @param array  $context Additional context data.
	 */
	public function alert( string $message, array $context = array() ): void {
		$this->log( self::LEVEL_ALERT, $message, $context );
	}

	/**
	 * Log an emergency message.
	 *
	 * @param string $message Log message.
	 * @param array  $context Additional context data.
	 */
	public function emergency( string $message, array $context = array() ): void {
		$this->log( self::LEVEL_EMERGENCY, $message, $context );
	}

	/**
	 * Check if logging is enabled.
	 *
	 * @return bool Always true for structured logger.
	 */
	public function is_enabled(): bool {
		return true;
	}

	/**
	 * Check if a level should be logged.
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
	 * Log a message at the specified level.
	 *
	 * @param string $level Log level.
	 * @param string $message Log message.
	 * @param array  $context Additional context data.
	 */
	public function log( string $level, string $message, array $context = array() ): void {
		if ( ! $this->should_log( $level ) ) {
			return;
		}

		$entry = array_merge(
			array(
				'timestamp'    => gmdate( 'Y-m-d\TH:i:s\Z' ),
				'level'        => $level,
				'message'      => $message,
				'service'      => 'sscribe-export-site-pages',
				'version'      => defined( 'SSCRIBE_VERSION' ) ? SSCRIBE_VERSION : 'unknown',
				'request_id'   => $this->request_id,
				'php_version'  => PHP_VERSION,
				'memory_usage' => memory_get_usage( true ),
				'memory_peak'  => memory_get_peak_usage( true ),
				'user_id'      => get_current_user_id(),
				'wp_site_url'  => get_option( 'siteurl', '' ),
			),
			$this->sanitize_context( $context )
		);

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Structured logging output.
		error_log( wp_json_encode( $entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
	}

	/**
	 * Sanitize context values for safe JSON output.
	 *
	 * @param array $context Raw context data.
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
	 * Flush buffered logs (no-op for this implementation).
	 *
	 * Structured logs are written immediately via error_log.
	 */
	public function flush(): void {
	}

	/**
	 * Get log entries (not supported for structured output).
	 *
	 * @param int $limit Maximum results (unused).
	 * @return array Empty array.
	 */
	public function get_logs( int $limit = 100 ): array {
		return array();
	}
}
