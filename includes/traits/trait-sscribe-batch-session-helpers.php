<?php
/**
 * SScribe Batch Session Helpers Trait
 *
 * Shared session-related helpers used by both SScribe_Batch_Processor
 * and SScribe_Batch_Session_Handler. Extracted to dedupe the rate-limit
 * check and capability lookup that both classes need.
 *
 * Using classes MUST provide a `get_rate_limiter()` method that returns
 * an SScribe_Export_Rate_Limiter instance — they already do.
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
 * Shared helpers for batch export session management.
 */
trait SScribe_Batch_Session_Helpers {

	/**
	 * Get the capability required for export operations.
	 *
	 * @return string Capability name.
	 */
	protected function get_required_capability(): string {
		return SScribe_Capabilities::get_required();
	}

	/**
	 * Verify the rate limit hasn't been exceeded.
	 *
	 * @return bool True if the rate limit check passes.
	 */
	protected function check_rate_limit(): bool {
		return $this->get_rate_limiter()->check_rate_limit( $this->get_required_capability() );
	}
}
