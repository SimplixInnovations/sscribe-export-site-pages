<?php
/**
 * SScribe Lock Response Helper
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
 * Centralised translation of a batch-lock conflict into the SScribe JSON
 * error response shape with HTTP 409 Conflict semantics.
 *
 * Used by ajax_cancel_export and any other endpoint that races against an
 * in-flight batch iteration. The JS client treats 409 as a short,
 * bounded retry (default 5 seconds) because the conflicting iteration
 * normally completes in a few hundred milliseconds.
 */
final class SScribe_Lock_Response {

	/**
	 * Emit the canonical SScribe JSON error response for a batch-lock
	 * conflict and terminate the request.
	 *
	 * @param string $session_id  Session identifier (echoed for debugging).
	 * @param int    $retry_after_ms Recommended retry delay in ms.
	 * @return never Never returns.
	 */
	public static function emit_conflict( string $session_id, int $retry_after_ms = 5000 ): never {
		$status       = 409;
		$retry_after  = max( 500, min( 30000, $retry_after_ms ) );

		if ( function_exists( 'status_header' ) ) {
			status_header( $status );
		}
		if ( function_exists( 'header' ) && ! headers_sent() ) {
			$retry_seconds = (int) ceil( $retry_after / 1000 );
			if ( $retry_seconds < 1 ) {
				$retry_seconds = 1;
			}
			header( 'Retry-After: ' . $retry_seconds );
		}

		SScribe_AJAX_Guard::error(
			array(
				'code'       => 'batch_in_progress',
				'message'    => __( 'A batch is processing. Try again in a moment.', 'sscribe-export-site-pages' ),
				'request_id' => \SScribe_Request_Id::current(),
				'retry'      => true,
				'retry_in'   => $retry_after,
				'session_id' => $session_id,
			),
			$status
		);
	}
}
