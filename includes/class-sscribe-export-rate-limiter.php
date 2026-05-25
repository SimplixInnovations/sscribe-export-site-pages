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
	 * @param string $export_capability Required capability.
	 * @return bool
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

		$now       = time();
		$lock_key  = $transient_key . '_lock';

		$rate_limit = current_user_can( $export_capability )
			? (int) apply_filters( 'sscribe_rate_limit_admin', 500 )
			: self::RATE_LIMIT_MAX;

		// Acquire micro-lock with retries.
		$locked = false;
		for ( $i = 0; $i < 3; $i++ ) {
			if ( wp_using_ext_object_cache() ) {
				$locked = wp_cache_add( $lock_key, 1, '', 2 );
			} else {
				$existing_lock = get_transient( $lock_key );
				if ( false === $existing_lock ) {
					$locked = set_transient( $lock_key, 1, 2 );
				}
			}
			if ( $locked ) {
				break;
			}
			usleep( 50000 );
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
}
