<?php
/**
 * SScribe Logger Common Trait
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
 * Shared logging utilities for logger implementations.
 *
 * Provides the PSR-3-style level dispatch methods (debug/info/notice/
 * warning/error/critical/alert/emergency) plus a session-id setter.
 * Each method is a one-line `$this->log()` call — the subclass's
 * `log()` implementation does the actual filtering, formatting, and
 * persistence.
 *
 * Subclasses MUST declare a `$session_id` property (string|null) for
 * `set_session_id()` and `get_context_enrichment()` to work — this
 * matches what each subclass already does, so no class change is
 * needed beyond removing the now-duplicated methods.
 */
trait SScribe_Logger_Common {

	/**
	 * Cached request ID for this request.
	 *
	 * @var string|null
	 */
	private ?string $cached_request_id = null;

	/**
	 * Enrich log context with runtime metadata.
	 *
	 * @return array Enriched context array.
	 */
	protected function get_context_enrichment(): array {
		$context = array(
			'plugin_version' => defined( 'SSCRIBE_VERSION' ) ? (string) SSCRIBE_VERSION : 'unknown',
			'php_version'    => PHP_VERSION,
			'memory_usage'   => size_format( memory_get_usage( true ) ),
			'request_id'     => $this->get_request_id(),
		);

		// Add session_id if available (subclasses should set this property).
		if ( isset( $this->session_id ) && null !== $this->session_id ) {
			$context['session_id'] = $this->session_id;
		}

		return $context;
	}

	/**
	 * Generate a unique request identifier (cached per request).
	 *
	 * @return string 12-character hex request ID.
	 */
	protected function get_request_id(): string {
		if ( null === $this->cached_request_id ) {
			$this->cached_request_id = substr( md5( microtime( true ) . (string) random_int( 0, PHP_INT_MAX ) ), 0, 12 );
		}
		return $this->cached_request_id;
	}

	/**
	 * Set the current session ID for log correlation.
	 *
	 * Shared across all logger implementations — the property is
	 * expected to be declared on the using class.
	 *
	 * @param string $session_id Unique session identifier.
	 */
	public function set_session_id( string $session_id ): void {
		$this->session_id = $session_id;
	}

	/**
	 * Log a debug-level message.
	 *
	 * @param string $message Log message.
	 * @param array  $context Additional context data.
	 */
	public function debug( string $message, array $context = array() ): void {
		$this->log( self::LEVEL_DEBUG, $message, $context );
	}

	/**
	 * Log an info-level message.
	 *
	 * @param string $message Log message.
	 * @param array  $context Additional context data.
	 */
	public function info( string $message, array $context = array() ): void {
		$this->log( self::LEVEL_INFO, $message, $context );
	}

	/**
	 * Log a notice-level message.
	 *
	 * @param string $message Log message.
	 * @param array  $context Additional context data.
	 */
	public function notice( string $message, array $context = array() ): void {
		$this->log( self::LEVEL_NOTICE, $message, $context );
	}

	/**
	 * Log a warning-level message.
	 *
	 * @param string $message Log message.
	 * @param array  $context Additional context data.
	 */
	public function warning( string $message, array $context = array() ): void {
		$this->log( self::LEVEL_WARNING, $message, $context );
	}

	/**
	 * Log an error-level message.
	 *
	 * @param string $message Log message.
	 * @param array  $context Additional context data.
	 */
	public function error( string $message, array $context = array() ): void {
		$this->log( self::LEVEL_ERROR, $message, $context );
	}

	/**
	 * Log a critical-level message.
	 *
	 * @param string $message Log message.
	 * @param array  $context Additional context data.
	 */
	public function critical( string $message, array $context = array() ): void {
		$this->log( self::LEVEL_CRITICAL, $message, $context );
	}

	/**
	 * Log an alert-level message.
	 *
	 * @param string $message Log message.
	 * @param array  $context Additional context data.
	 */
	public function alert( string $message, array $context = array() ): void {
		$this->log( self::LEVEL_ALERT, $message, $context );
	}

	/**
	 * Log an emergency-level message.
	 *
	 * @param string $message Log message.
	 * @param array  $context Additional context data.
	 */
	public function emergency( string $message, array $context = array() ): void {
		$this->log( self::LEVEL_EMERGENCY, $message, $context );
	}

	/**
	 * Get the canonical level priority map.
	 *
	 * Single source of truth for the level → integer priority mapping
	 * used by `should_log()` filtering in subclasses. Returned as a
	 * method (not a constant) so subclasses can share it without
	 * exposing it as a class constant that legacy tests look up.
	 *
	 * @return array<string, int>
	 */
	protected static function get_level_priority_map(): array {
		return array(
			self::LEVEL_DEBUG     => 0,
			self::LEVEL_INFO      => 1,
			self::LEVEL_NOTICE    => 2,
			self::LEVEL_WARNING   => 3,
			self::LEVEL_ERROR     => 4,
			self::LEVEL_CRITICAL  => 5,
			self::LEVEL_ALERT     => 6,
			self::LEVEL_EMERGENCY => 7,
		);
	}

	/**
	 * Check whether `$level` meets the `$min_level` threshold.
	 *
	 * Used by Enhanced and Structured's `should_log()` to dedupe the
	 * priority comparison. Returns true if `$level` is at least as
	 * severe as `$min_level`.
	 *
	 * @param string $level     Level being checked.
	 * @param string $min_level Threshold level.
	 * @return bool
	 */
	protected static function level_meets_threshold( string $level, string $min_level ): bool {
		$map      = self::get_level_priority_map();
		$current  = $map[ $min_level ] ?? 1;
		$check    = $map[ $level ] ?? 1;
		return $check >= $current;
	}
}
