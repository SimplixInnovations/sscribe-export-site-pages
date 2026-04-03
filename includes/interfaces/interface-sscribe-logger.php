<?php
/**
 * Logger interface for SScribe.
 *
 * @package SScribe
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface SScribe_Logger_Interface
 *
 * PSR-3 compatible logger interface.
 */
interface SScribe_Logger_Interface {

	/**
	 * Log levels (PSR-3 compatible).
	 */
	public const LEVEL_DEBUG     = 'debug';
	public const LEVEL_INFO      = 'info';
	public const LEVEL_NOTICE    = 'notice';
	public const LEVEL_WARNING   = 'warning';
	public const LEVEL_ERROR     = 'error';
	public const LEVEL_CRITICAL  = 'critical';
	public const LEVEL_ALERT     = 'alert';
	public const LEVEL_EMERGENCY = 'emergency';

	/**
	 * Log a debug message.
	 *
	 * @param string $message Log message.
	 * @param array  $context Optional context data.
	 */
	public function debug( string $message, array $context = array() ): void;

	/**
	 * Log an info message.
	 *
	 * @param string $message Log message.
	 * @param array  $context Optional context data.
	 */
	public function info( string $message, array $context = array() ): void;

	/**
	 * Log a notice message.
	 *
	 * @param string $message Log message.
	 * @param array  $context Optional context data.
	 */
	public function notice( string $message, array $context = array() ): void;

	/**
	 * Log a warning message.
	 *
	 * @param string $message Log message.
	 * @param array  $context Optional context data.
	 */
	public function warning( string $message, array $context = array() ): void;

	/**
	 * Log an error message.
	 *
	 * @param string $message Log message.
	 * @param array  $context Optional context data.
	 */
	public function error( string $message, array $context = array() ): void;

	/**
	 * Log a critical message.
	 *
	 * @param string $message Log message.
	 * @param array  $context Optional context data.
	 */
	public function critical( string $message, array $context = array() ): void;

	/**
	 * Log an alert message.
	 *
	 * @param string $message Log message.
	 * @param array  $context Optional context data.
	 */
	public function alert( string $message, array $context = array() ): void;

	/**
	 * Log an emergency message.
	 *
	 * @param string $message Log message.
	 * @param array  $context Optional context data.
	 */
	public function emergency( string $message, array $context = array() ): void;

	/**
	 * Log a message with a specific level.
	 *
	 * @param string $level   Log level.
	 * @param string $message Log message.
	 * @param array  $context Optional context data.
	 */
	public function log( string $level, string $message, array $context = array() ): void;

	/**
	 * Check if logging is enabled.
	 *
	 * @return bool
	 */
	public function is_enabled(): bool;
}
