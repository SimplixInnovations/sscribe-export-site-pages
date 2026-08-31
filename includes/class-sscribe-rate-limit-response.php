<?php
/**
 * SScribe Rate Limit Response Helper
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
 * Centralised translation of {@see SScribe_Rate_Limit_Decision} into the
 * SScribe JSON error response shape, including the correct HTTP status
 * (429 for genuine quota exhaustion, 503 for internal contention).
 *
 * Call sites use this helper instead of branching on the decision
 * themselves, so the response contract cannot drift between handlers.
 *
 * Canonical error codes (kept in sync with the docs/audit checklist):
 *   - 'rate_limited'        : genuine quota exhaustion (HTTP 429)
 *   - 'rate_limiter_busy'   : internal contention        (HTTP 503)
 *   - 'rate_limit_allowed'  : explicit allow             (HTTP 200, observability)
 */
final class SScribe_Rate_Limit_Response {

	/**
	 * Emit the canonical SScribe JSON error response for a rate-limit
	 * decision and terminate the request.
	 *
	 * Adds an HTTP `Retry-After` header (seconds, rounded up) so external
	 * clients without knowledge of the SScribe contract can also honor
	 * the back-off.
	 *
	 * @param SScribe_Rate_Limit_Decision $decision Decision returned from the limiter.
	 * @return never Never returns.
	 */
	public static function emit( SScribe_Rate_Limit_Decision $decision ): never {
		$status = $decision->http_status();

		if ( function_exists( 'status_header' ) ) {
			status_header( $status );
		}
		if ( function_exists( 'header' ) && ! headers_sent() ) {
			$retry_seconds = (int) ceil( $decision->retry_after_ms / 1000 );
			if ( $retry_seconds < 1 ) {
				$retry_seconds = 1;
			}
			header( 'Retry-After: ' . $retry_seconds );
		}

		SScribe_AJAX_Guard::error(
			array(
				'code'       => $decision->error_code(),
				'message'    => self::localised_message( $decision ),
				'request_id' => \SScribe_Request_Id::current(),
				'retry'      => true,
				'retry_in'   => $decision->retry_after_ms,
				'bucket'     => $decision->bucket,
				'limit'      => $decision->limit,
				'remaining'  => $decision->remaining,
				'reset_at'   => $decision->reset_at,
			),
			$status
		);
	}

	/**
	 * Localised human-readable message for the decision.
	 *
	 * @param SScribe_Rate_Limit_Decision $decision Decision.
	 * @return string Translated message.
	 */
	private static function localised_message( SScribe_Rate_Limit_Decision $decision ): string {
		switch ( $decision->reason ) {
			case SScribe_Rate_Limit_Decision::REASON_QUOTA_EXCEEDED:
				return __( 'Too many requests. Please wait for the per-minute quota to reset.', 'sscribe-export-site-pages' );
			case SScribe_Rate_Limit_Decision::REASON_LIMITER_CONTENTION:
				return __( 'The export subsystem is briefly busy. Please retry in a moment.', 'sscribe-export-site-pages' );
			case SScribe_Rate_Limit_Decision::REASON_ALLOWED:
			default:
				return __( 'Request permitted.', 'sscribe-export-site-pages' );
		}
	}
}
