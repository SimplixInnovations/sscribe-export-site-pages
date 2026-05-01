<?php
/**
 * Shared logger trait for SScribe.
 *
 * Provides common context enrichment and request ID generation
 * used by both SScribe_Logger and SScribe_Logger_Enhanced.
 *
 * @package SScribe
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Trait SScribe_Logger_Common
 *
 * Provides get_context_enrichment() and get_request_id() for loggers.
 *
 * Note: SScribe_Logger overrides both methods as private/static using PHP's
 * class-over-trait precedence. This is intentional — the static logger has
 * a different lifecycle (no instance request ID). Other loggers
 * (SScribe_Logger_Enhanced, SScribe_Logger_Structured) inherit from
 * the trait and may override get_request_id() per their caching needs.
 */

trait SScribe_Logger_Common {
	/**
	 * Get standard context enrichment for log entries.
	 *
	 * @return array<string, string>
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
	 * Generate a per-request correlation ID.
	 *
	 * Uses cryptographically secure random_int() for unpredictability.
	 *
	 * @return string 12-character hex string.
	 */
	protected function get_request_id(): string {
		return substr( md5( microtime( true ) . (string) random_int( 0, PHP_INT_MAX ) ), 0, 12 );
	}
}
