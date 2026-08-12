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

	private const LOCK_CACHE_GROUP = 'sscribe_rate_limit_locks';

	private const DATA_CACHE_GROUP = 'sscribe_rate_limits';

	private const OPTION_LOCK_PREFIX = 'sscribe_rate_lock_';

	/**
	 * Check if current user/IP is within rate limits.
	 *
	 * Uses micro-lock pattern to prevent race conditions on concurrent requests.
	 *
	 * @param string $export_capability Required capability.
	 * @param string $bucket            Rate limit bucket name (default: 'export').
	 *                                 Use 'debug' for debug console actions to keep
	 *                                 them in a separate bucket from export actions.
	 * @return bool True when allowed; false when limited or contended.
	 */
	public function check_rate_limit( string $export_capability = 'sscribe_export', string $bucket = 'export' ): bool {
		$user_id = get_current_user_id();
		$bucket  = substr( sanitize_key( $bucket ), 0, 40 );
		$bucket  = '' !== $bucket ? $bucket : 'export';

		if ( $user_id > 0 ) {
			$transient_key = 'sscribe_rate_' . $bucket . '_' . $user_id;
		} else {
			$remote_ip = $this->get_client_ip();
			$transient_key = 'sscribe_rate_' . $bucket . '_anon_' . substr( hash( 'sha256', $remote_ip ), 0, 12 );
		}

		$now             = time();
		$cache_lock_key  = $transient_key . '_lock';
		$option_lock_key = self::OPTION_LOCK_PREFIX . substr( hash( 'sha256', $transient_key ), 0, 32 );
		$lock_token      = wp_generate_password( 16, false );
		$using_cache     = (bool) wp_using_ext_object_cache();

		$rate_limit = current_user_can( $export_capability )
			? max( 1, (int) apply_filters( 'sscribe_rate_limit_admin', 500 ) )
			: self::RATE_LIMIT_MAX;

		$locked = false;
		$attempts = 0;
		while ( ! $locked && $attempts < 2 ) {
			if ( $using_cache ) {
				$locked = wp_cache_add( $cache_lock_key, $lock_token, self::LOCK_CACHE_GROUP, 5 );
			} else {
				$existing_lock = get_option( $option_lock_key, false );
				if ( is_string( $existing_lock ) ) {
					$parts     = explode( '|', $existing_lock, 2 );
					$lock_time = isset( $parts[0] ) && ctype_digit( $parts[0] ) ? (int) $parts[0] : 0;
					if ( 0 === $lock_time || $now - $lock_time > 5 || $now - $lock_time < -5 ) {
						delete_option( $option_lock_key );
					}
				}

				$locked = add_option( $option_lock_key, $now . '|' . $lock_token, '', false );
				if ( $locked ) {
					$verified = get_option( $option_lock_key, false );
					$locked   = is_string( $verified ) && hash_equals( $now . '|' . $lock_token, $verified );
				}
			}
			++$attempts;
			if ( ! $locked && $attempts < 2 ) {
				usleep( 50000 );
			}
		}

		if ( ! $locked ) {
			return false;
		}

		$data = $using_cache
			? wp_cache_get( $transient_key, self::DATA_CACHE_GROUP )
			: get_transient( $transient_key );

		if ( ! is_array( $data ) || ! isset( $data['count'], $data['reset_at'] ) ) {
			$data = array(
				'count'    => 0,
				'reset_at' => $now + self::RATE_LIMIT_WINDOW,
			);
		}

		if ( (int) $data['reset_at'] <= $now ) {
			$data = array(
				'count'    => 0,
				'reset_at' => $now + self::RATE_LIMIT_WINDOW,
			);
		}

		if ( (int) $data['count'] >= $rate_limit ) {
			$this->release_lock( $using_cache, $cache_lock_key, $option_lock_key, $lock_token );
			return false;
		}

		$new_count = (int) $data['count'];
		if ( $using_cache ) {
			$incremented = wp_cache_incr( $transient_key, 1, self::DATA_CACHE_GROUP );
			if ( false === $incremented ) {
				wp_cache_add( $transient_key, 1, self::DATA_CACHE_GROUP, self::RATE_LIMIT_WINDOW + 5 );
				$new_count = 1;
			} else {
				$new_count = (int) $incremented;
			}
		} else {
			++$data['count'];
			set_transient( $transient_key, $data, self::RATE_LIMIT_WINDOW + 5 );
			$new_count = $data['count'];
		}

		if ( $new_count > $rate_limit ) {
			$this->release_lock( $using_cache, $cache_lock_key, $option_lock_key, $lock_token );
			return false;
		}

		$this->release_lock( $using_cache, $cache_lock_key, $option_lock_key, $lock_token );

		return true;
	}

	/**
	 * Release the micro-lock if it is still owned by this request.
	 *
	 * @param bool   $using_cache    Whether a persistent object cache is active.
	 * @param string $cache_key      Object-cache lock key.
	 * @param string $option_key     Database lock option key.
	 * @param string $lock_token     Owner token.
	 * @return void
	 */
	private function release_lock( bool $using_cache, string $cache_key, string $option_key, string $lock_token ): void {
		if ( $using_cache ) {
			$stored = wp_cache_get( $cache_key, self::LOCK_CACHE_GROUP );
			if ( is_string( $stored ) && hash_equals( $lock_token, $stored ) ) {
				wp_cache_delete( $cache_key, self::LOCK_CACHE_GROUP );
			}
			return;
		}

		$stored = get_option( $option_key, false );
		if ( ! is_string( $stored ) ) {
			return;
		}

		$parts = explode( '|', $stored, 2 );
		if ( isset( $parts[1] ) && hash_equals( $lock_token, $parts[1] ) ) {
			delete_option( $option_key );
		}
	}

	/**
	 * Get the best available client IP address, preferring trusted proxies.
	 *
	 * @return string Sanitized IP address or '0.0.0.0' as fallback.
	 */
	private function get_client_ip(): string {
		return SScribe_Helpers::get_client_ip();
	}
}
