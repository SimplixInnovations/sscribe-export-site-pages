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
 * @since         1.1.1
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rate limiter for export AJAX endpoints.
 *
 * Designed as a stateless utility — configuration is entirely via
 * class constants and filterable hooks, so it needs no injected
 * dependencies beyond standard WordPress API functions.
 *
 * @since 1.1.1
 */
class SScribe_Export_Rate_Limiter {

	/**
	 * Default maximum requests per time window.
	 *
	 * 200 req/min supports large batch exports where the AJAX client
	 * polls every few seconds per page across multiple concurrent
	 * format renders.
	 *
	 * @var int
	 * @since 1.1.1
	 */
	private const RATE_LIMIT_MAX = 200;

	/**
	 * Rate limit time window in seconds.
	 *
	 * @var int
	 * @since 1.1.1
	 */
	private const RATE_LIMIT_WINDOW = 60;

	/**
	 * Check whether the current user has exceeded the rate limit.
	 *
	 * Returns true if the request is within limits, false if the user
	 * has exceeded the maximum allowed requests in the current window.
	 * The window resets automatically after {@see RATE_LIMIT_WINDOW}
	 * seconds from the first request.
	 *
	 * @since 1.1.1
	 *
	 * @param string $export_capability WordPress capability required to
	 *                                  perform exports. Users holding this
	 *                                  capability receive an elevated rate
	 *                                  limit (default 1000/min, filterable
	 *                                  via {@see 'sscribe_rate_limit_admin'}).
	 *                                  Default 'manage_options'.
	 *
	 * @return bool True if within limits, false if exceeded.
	 */
	public function check_rate_limit( string $export_capability = 'manage_options' ): bool {
		$user_id = get_current_user_id();

		/*
		 * Key selection: authenticated users are tracked by user ID to
		 * prevent IP-based collisions behind NAT. Anonymous users use a
		 * truncated SHA-256 of their remote address.
		 */
		if ( $user_id > 0 ) {
			$transient_key = 'sscribe_rate_' . $user_id;
		} else {
			$remote_ip     = isset( $_SERVER['REMOTE_ADDR'] )
				? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
				: '0.0.0.0';
			$transient_key = 'sscribe_rate_anon_' . substr( hash( 'sha256', $remote_ip ), 0, 12 );
		}

		$now = time();

		/*
		 * Determine rate limit: administrators (by capability) get a
		 * higher ceiling to support large export operations without
		 * interruption. The admin limit is filterable so hosting
		 * providers or site owners can tune it.
		 *
		 * @since 1.1.1
		 * @param int $admin_rate_limit Maximum requests per window for admins.
		 */
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

		// Reset counter if the window has expired.
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

		// Store with a 5-second buffer to prevent premature window expiry.
		set_transient( $transient_key, $data, self::RATE_LIMIT_WINDOW + 5 );

		return true;
	}
}
