<?php
/**
 * SScribe Logger Common Trait
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared logging utilities for logger implementations.
 */
trait SScribe_Logger_Common {

	/**
	 * Enrich log context with runtime metadata.
	 *
	 * @return array Enriched context array.
	 */
	protected function get_context_enrichment(): array {
		return array(
			'plugin_version' => defined( 'SSCRIBE_VERSION' ) ? (string) SSCRIBE_VERSION : 'unknown',
			'php_version'    => PHP_VERSION,
			'memory_usage'   => size_format( memory_get_usage( true ) ),
			'request_id'     => $this->get_request_id(),
		);
	}

	/**
	 * Generate a unique request identifier.
	 *
	 * @return string 12-character hex request ID.
	 */
	protected function get_request_id(): string {
		return substr( md5( microtime( true ) . (string) random_int( 0, PHP_INT_MAX ) ), 0, 12 );
	}
}
