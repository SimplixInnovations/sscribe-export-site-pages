<?php
/**
 * SScribe Logger Interface
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface for SScribe logging implementations.
 *
 * Defines the contract for all logger classes, supporting PSR-3
 * style log levels and session-scoped logging.
 */
interface SScribe_Logger_Interface {

	public const LEVEL_DEBUG     = 'debug';
	public const LEVEL_INFO      = 'info';
	public const LEVEL_NOTICE    = 'notice';
	public const LEVEL_WARNING   = 'warning';
	public const LEVEL_ERROR     = 'error';
	public const LEVEL_CRITICAL  = 'critical';
	public const LEVEL_ALERT     = 'alert';
	public const LEVEL_EMERGENCY = 'emergency';

	public const MAX_LOG_FILE_SIZE = 10485760;

	/**
	 * Set the current session ID for log correlation.
	 *
	 * @param string $session_id Unique session identifier.
	 */
	public function set_session_id( string $session_id ): void;

	/**
	 * Log a debug message.
	 *
	 * @param string $message Log message.
	 * @param array  $context Additional context data.
	 */
	public function debug( string $message, array $context = array() ): void;

	/**
	 * Log an informational message.
	 *
	 * @param string $message Log message.
	 * @param array  $context Additional context data.
	 */
	public function info( string $message, array $context = array() ): void;

	/**
	 * Log a notice-level message.
	 *
	 * @param string $message Log message.
	 * @param array  $context Additional context data.
	 */
	public function notice( string $message, array $context = array() ): void;

	/**
	 * Log a warning message.
	 *
	 * @param string $message Log message.
	 * @param array  $context Additional context data.
	 */
	public function warning( string $message, array $context = array() ): void;

	/**
	 * Log an error message.
	 *
	 * @param string $message Log message.
	 * @param array  $context Additional context data.
	 */
	public function error( string $message, array $context = array() ): void;

	/**
	 * Log a critical message.
	 *
	 * @param string $message Log message.
	 * @param array  $context Additional context data.
	 */
	public function critical( string $message, array $context = array() ): void;

	/**
	 * Log an alert message.
	 *
	 * @param string $message Log message.
	 * @param array  $context Additional context data.
	 */
	public function alert( string $message, array $context = array() ): void;

	/**
	 * Log an emergency message.
	 *
	 * @param string $message Log message.
	 * @param array  $context Additional context data.
	 */
	public function emergency( string $message, array $context = array() ): void;

	/**
	 * Log a message at the specified level.
	 *
	 * @param string $level   Log level constant.
	 * @param string $message Log message.
	 * @param array  $context Additional context data.
	 */
	public function log( string $level, string $message, array $context = array() ): void;

	/**
	 * Check if logging is enabled.
	 *
	 * @return bool True if logging is active.
	 */
	public function is_enabled(): bool;

	/**
	 * Retrieve all logged entries.
	 *
	 * @return array Array of log entries.
	 */
	public function get_logs(): array;

	/**
	 * Clear all log entries.
	 */
	public function clear_logs(): void;
}
