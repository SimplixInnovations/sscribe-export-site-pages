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
 */
interface SScribe_Logger_Interface {

	/**
	 * Log a debug message.
	 *
	 * @param string $message Log message.
	 * @param array  $data    Optional data to include.
	 */
	public function debug( string $message, array $data = array() ): void;

	/**
	 * Log an error message.
	 *
	 * @param string $message Log message.
	 * @param array  $data    Optional data to include.
	 */
	public function error( string $message, array $data = array() ): void;

	/**
	 * Check if logging is enabled.
	 *
	 * @return bool
	 */
	public function is_enabled(): bool;
}
