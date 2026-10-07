<?php
/**
 * SScribe Export Outcome Emitter Trait
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
 * Turns an SScribe_Export_Outcome into the matching AJAX response.
 */
trait SScribe_Export_Outcome_Emitter {

	/**
	 * Send the outcome as a JSON response and stop.
	 *
	 * @param SScribe_Export_Outcome $outcome Outcome to send.
	 * @return never
	 */
	private function emit_export_outcome( SScribe_Export_Outcome $outcome ): never {
		$kind = $outcome->kind();

		if ( SScribe_Export_Outcome::KIND_RATE_LIMITED === $kind && null !== $outcome->decision() ) {
			$decision = $outcome->decision();
			if ( class_exists( 'SScribe_Rate_Limit_Response' ) ) {
				\SScribe_Rate_Limit_Response::emit( $decision );
			}
			SScribe_AJAX_Guard::error(
				array(
					'code'     => $decision->error_code(),
					'message'  => __( 'Too many requests. Please wait a moment.', 'sscribe-export-site-pages' ),
					'retry'    => true,
					'retry_in' => $decision->retry_after_ms,
				),
				$decision->http_status()
			);
		}

		if ( SScribe_Export_Outcome::KIND_LOCK_CONFLICT === $kind ) {
			\SScribe_Lock_Response::emit_conflict( $outcome->session_id(), $outcome->retry_after_ms() );
		}

		if ( SScribe_Export_Outcome::KIND_OK === $kind ) {
			if ( 200 === $outcome->http_status() ) {
				SScribe_AJAX_Guard::success( $outcome->payload() );
			}
			SScribe_AJAX_Guard::success( $outcome->payload(), $outcome->http_status() );
		}

		SScribe_AJAX_Guard::error( $outcome->payload(), $outcome->http_status() );
	}
}
