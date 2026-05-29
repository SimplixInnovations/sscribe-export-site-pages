<?php
/**
 * SScribe Export Rate Limiter
 *
 * @package SScribe_Export_Site_Pages
 * @license GPL v2 or later
 * @link    https://www.gnu.org/licenses/gpl-2.0.html
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rate limiting for export operations per user/IP.
 */
class SScribe_Export_Rate_Limiter {

	private const RATE_LIMIT_MAX = 200;

	private const RATE_LIMIT_WINDOW = 60;

	/**
	 * Check if current user/IP is within rate limits.
	 *
	 * Uses micro-lock pattern to prevent race conditions on concurrent requests.
	 *
	 * Note: The micro-lock (lines 52-77) uses a best-effort approach with retries
	 * and verification read-back. A narrow race window exists between when the lock
	 * transient is set and when it's verified (lines 60-66). Additionally, the
	 * rate limit counter increment (lines 79-106) has a TOCTOU race: two concurrent
	 * requests can read the same count before either writes the incremented value.
	 * This may allow slightly more requests than the strict limit in high-concurrency
	 * scenarios, but is acceptable for rate-limiting UX purposes.
	 *
	 * @param string $export_capability Required capability.
	 * @param string $bucket            Rate limit bucket name (default: 'export').
	 *                                 Use 'debug' for debug console actions to keep
	 *                                 them in a separate bucket from export actions.
	 * @return bool
	 */
	public function check_rate_limit( string $export_capability = 'manage_options', string $bucket = 'export' ): bool {
		$user_id = get_current_user_id();

		if ( $user_id > 0 ) {
			$transient_key = 'sscribe_rate_' . $bucket . '_' . $user_id;
		} else {
			$remote_ip = $this->get_client_ip();
			$transient_key = 'sscribe_rate_' . $bucket . '_anon_' . substr( hash( 'sha256', $remote_ip ), 0, 12 );
		}

		$now      = time();
		$lock_key = $transient_key . '_lock';

		$rate_limit = current_user_can( $export_capability )
			? (int) apply_filters( 'sscribe_rate_limit_admin', 500 )
			: self::RATE_LIMIT_MAX;

		$locked = false;
		if ( wp_using_ext_object_cache() ) {
			$locked = wp_cache_add( $lock_key, 1, '', 2 );
		} else {
			$existing_lock = get_transient( $lock_key );
			if ( false === $existing_lock ) {
				$locked = set_transient( $lock_key, 1, 2 );
				if ( $locked ) {
					$verified = get_transient( $lock_key );
					$locked   = false !== $verified && 1 === (int) $verified;
				}
			}
		}

		if ( ! $locked ) {
			return false;
		}

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
			if ( wp_using_ext_object_cache() ) {
				wp_cache_delete( $lock_key, '' );
			} else {
				delete_transient( $lock_key );
			}
			return false;
		}

		++$data['count'];

		set_transient( $transient_key, $data, self::RATE_LIMIT_WINDOW + 5 );

		if ( wp_using_ext_object_cache() ) {
			wp_cache_delete( $lock_key, '' );
		} else {
			delete_transient( $lock_key );
		}

		return true;
	}

	private function get_client_ip(): string {
		$ip = '';
		if ( isset( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
			$ip = $_SERVER['HTTP_CF_CONNECTING_IP'];
		} elseif ( isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) && preg_match( '/^([0-9.]+,?)+$/i', $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$ip = trim( explode( ',', $_SERVER['HTTP_X_FORWARDED_FOR'] )[0] );
		} elseif ( isset( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = $_SERVER['REMOTE_ADDR'];
		}
		return $ip ? sanitize_text_field( wp_unslash( $ip ) ) : '0.0.0.0';
	}
}
