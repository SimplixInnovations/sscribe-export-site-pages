<?php
/**
 * Rate limiter for SScribe export AJAX endpoints.
 *
 * Provides token-bucket rate limiting using WordPress transients with
 * per-user and per-anonymous-IP tracking. Authenticated users are keyed
 * by user ID; unauthenticated requests by a truncated SHA-256 hash of
 * the remote IP address.
 *
 * The default limit is 200 requests per 60-second window. Administrators
 * (those holding the configured export capability) automatically receive
 * an elevated limit of 1000/minute, filterable via the
 * {@see 'sscribe_rate_limit_admin'} hook.
 *
 * @package       SScribe
 * @since         1.1.5
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rate limiter for export AJAX endpoints.
 *
 * Configuration is entirely via class constants and filterable hooks.
 *
 * @since 1.1.5
 */
class SScribe_Export_Rate_Limiter {

	/**
	 * Default maximum requests per time window.
	 *
	 * @var int
	 */
	private const RATE_LIMIT_MAX = 200;

	/**
	 * Rate limit time window in seconds.
	 *
	 * @var int
	 */
	private const RATE_LIMIT_WINDOW = 60;

	/**
	 * Check whether the current user has exceeded the rate limit.
	 *
	 * @param string $export_capability WP capability for elevated rate limit.
	 *                                  Default 'manage_options'.
	 *
	 * @return bool True if within limits, false if exceeded.
	 */
	public function check_rate_limit( string $export_capability = 'manage_options' ): bool {
		$user_id = get_current_user_id();

		if ( $user_id > 0 ) {
			$transient_key = 'sscribe_rate_' . $user_id;
		} else {
			$remote_ip     = isset( $_SERVER['REMOTE_ADDR'] )
				? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
				: '0.0.0.0';
			$transient_key = 'sscribe_rate_anon_' . substr( hash( 'sha256', $remote_ip ), 0, 12 );
		}

		$now = time();

		$rate_limit = current_user_can( $export_capability )
			? (int) apply_filters( 'sscribe_rate_limit_admin', 1000 )
			: self::RATE_LIMIT_MAX;

		$data = get_transient( $transient_key );

		if ( false === $data ) {
			$data = array(
				'count'    => 0,
				'reset_at' => $now + self::RATE_LIMIT_WINDOW,
			);
		}

		if ( isset( $data['reset_at'] ) && $data['reset_at'] <= $now ) {
			$data = array(
				'count'    => 0,
				'reset_at' => $now + self::RATE_LIMIT_WINDOW,
			);
		}

		if ( $data['count'] >= $rate_limit ) {
			return false;
		}

		++$data['count'];

		set_transient( $transient_key, $data, self::RATE_LIMIT_WINDOW + 5 );

		return true;
	}
}
