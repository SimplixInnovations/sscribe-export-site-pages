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
		if ( property_exists( $this, 'session_id' ) && null !== $this->session_id ) {
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
}
