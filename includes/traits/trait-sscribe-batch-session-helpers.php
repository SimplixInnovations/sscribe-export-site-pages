<?php
/**
 * SScribe Batch Session Helpers Trait
 *
 * Shared session-related helpers consumed by SScribe_Session (via the
 * SScribe_Session_AJAX trait). Extracted to dedupe the rate-limit
 * check and capability lookup that AJAX endpoints need.
 *
 * Using classes MUST provide a `get_rate_limiter()` method that returns
 * an SScribe_Export_Rate_Limiter instance : they already do.
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
	 * Returns the structured decision so callers can distinguish genuine
	 * quota exhaustion (HTTP 429) from internal micro-lock contention
	 * (HTTP 503) and emit the canonical response via
	 * {@see SScribe_Rate_Limit_Response::emit()}.
	 *
	 * @param string $bucket Rate-limit bucket name.
	 * @return SScribe_Rate_Limit_Decision Decision describing the outcome.
	 */
	protected function check_rate_limit_decision( string $bucket = 'export_start' ): \SScribe_Rate_Limit_Decision {
		return $this->get_rate_limiter()->check_rate_limit_decision( $this->get_required_capability(), $bucket );
	}
}
