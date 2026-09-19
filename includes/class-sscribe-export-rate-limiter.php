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
 *
 * Bucket-aware so harmless UI reads do not consume the same logical quota
 * as export mutations. Returns a {@see SScribe_Rate_Limit_Decision} so the
 * caller can distinguish genuine quota exhaustion from internal
 * micro-lock contention and report different HTTP status codes.
 */
class SScribe_Export_Rate_Limiter {

	private const RATE_LIMIT_MAX = 200;

	private const RATE_LIMIT_WINDOW = 60;

	private const LOCK_CACHE_GROUP = 'sscribe_rate_limit_locks';

	private const DATA_CACHE_GROUP = 'sscribe_rate_limits';

	private const OPTION_LOCK_PREFIX = 'sscribe_rate_lock_';

	/**
	 * Canonical rate-limit buckets.
	 *
	 * - export_start    : starting or clearing an export.
	 * - export_batch    : mid-flight batch continuation calls.
	 * - export_finalize : finalize / download / ZIP handoff.
	 * - export_read     : status counts, preview, recent exports, active-session check.
	 * - debug_read      : debug log fetch / file listing.
	 * - debug_write     : debug settings save / clear logs.
	 * - health          : preflight / health check.
	 *
	 * Legacy alias `export` is retained for back-compat with on-disk
	 * transient keys and existing call sites; treat it as a synonym for
	 * `export_start`.
	 */
	public const BUCKETS = array(
		'export_start',
		'export_batch',
		'export_finalize',
		'export_read',
		'debug_read',
		'debug_write',
		'health',
		'export',
	);

	public const BUCKET_DEFAULT = 'export';

	/**
	 * Back-compat shim that returns a bool.
	 *
	 * Existing callers that have not yet been migrated to the decision
	 * contract continue to receive `true` when allowed and `false` when
	 * denied, regardless of the underlying reason (quota exhaustion vs
	 * internal contention). New code should call {@see check_rate_limit_decision()}.
	 *
	 * @param string $export_capability Required capability.
	 * @param string $bucket            Rate limit bucket name.
	 * @return bool True when allowed; false when limited or contended.
	 */
	public function check_rate_limit( string $export_capability = 'sscribe_export', string $bucket = self::BUCKET_DEFAULT ): bool {
		$decision = $this->check_rate_limit_decision( $export_capability, $bucket );
		return $decision->allowed;
	}

	/**
	 * Primary rate-limit entry point returning a structured decision.
	 *
	 * @param string $export_capability Required capability.
	 * @param string $bucket            Rate limit bucket name.
	 * @return SScribe_Rate_Limit_Decision Decision describing the outcome.
	 */
	public function check_rate_limit_decision( string $export_capability = 'sscribe_export', string $bucket = self::BUCKET_DEFAULT ): SScribe_Rate_Limit_Decision {
		$bucket = $this->normalise_bucket( $bucket );

		$user_id = get_current_user_id();
		if ( $user_id > 0 ) {
			$transient_key = 'sscribe_rate_' . $bucket . '_' . $user_id;
		} else {
			$remote_ip      = $this->get_client_ip();
			$transient_key  = 'sscribe_rate_' . $bucket . '_anon_' . substr( hash( 'sha256', $remote_ip ), 0, 12 );
		}

		$now             = time();
		$cache_lock_key  = $transient_key . '_lock';
		$option_lock_key = self::OPTION_LOCK_PREFIX . substr( hash( 'sha256', $transient_key ), 0, 32 );
		$lock_token      = wp_generate_password( 16, false );
		$using_cache     = (bool) wp_using_ext_object_cache();

		$rate_limit = current_user_can( $export_capability )
			? max( 1, (int) apply_filters( 'sscribe_rate_limit_admin', 500 ) )
			: self::RATE_LIMIT_MAX;

		$locked  = false;
		$attempts = 0;
		// Micro-lock TTL is 5s (see the stale-lock cleanup below). The
		// retry budget must be wide enough for legitimate concurrent
		// contention to drain but narrow enough that we do not pile
		// requests on top of a slow request handler. Two AJAX calls
		// from the same page (sscribe_check_active_session +
		// sscribe_get_status_counts, both at bucket 'export_read')
		// serialize through this lock; on WP-Playground the WASM-compiled
		// request handler can take 600-4000ms per request when the
		// handler is cold (compiles PHP from bytecode on first hit).
		// 50 × 100ms = 5000ms covers the worst observed WASM cold-start
		// envelope. We accept the wider budget here because (a) cold
		// start is the dominant cost on the test path, (b) the JS-side
		// 2500ms retry in admin/js/sscribe-admin.js is still the
		// production-time fallback for genuine contention (two users on
		// the same page), and (c) the rate-limit window is 60s, so a
		// single AJAX call waiting up to 5s for the lock is bounded by
		// the lock TTL itself — it cannot spin forever. When the budget
		// is exhausted the caller gets 503 with code=rate_limiter_busy,
		// which the export UI handles as retryable.
		while ( ! $locked && $attempts < 50 ) {
			if ( $using_cache ) {
				$locked = wp_cache_add( $cache_lock_key, $lock_token, self::LOCK_CACHE_GROUP, 5 );
			} else {
				$existing_lock = get_option( $option_lock_key, false );
				if ( false !== $existing_lock ) {
					if ( ! is_string( $existing_lock ) ) {
						return SScribe_Rate_Limit_Decision::limiter_contention( $bucket, $rate_limit, 750 );
					}
					$parts     = explode( '|', $existing_lock, 2 );
					$lock_time = isset( $parts[0] ) && ctype_digit( $parts[0] ) ? (int) $parts[0] : 0;
					if ( 0 === $lock_time || $now - $lock_time > 5 || $now - $lock_time < -5 ) {
						if ( ! $this->delete_owned_option_lock( $option_lock_key, $existing_lock ) ) {
							return SScribe_Rate_Limit_Decision::limiter_contention( $bucket, $rate_limit, 750 );
						}
					}
				}

				$locked = add_option( $option_lock_key, $now . '|' . $lock_token, '', false );
				if ( $locked ) {
					$verified = get_option( $option_lock_key, false );
					$locked   = is_string( $verified ) && hash_equals( $now . '|' . $lock_token, $verified );
				}
			}
			++$attempts;
			if ( ! $locked && $attempts < 50 ) {
				usleep( 100000 );
			}
		}

		if ( ! $locked ) {
			return SScribe_Rate_Limit_Decision::limiter_contention( $bucket, $rate_limit, 750 );
		}

		$cache_ttl = self::RATE_LIMIT_WINDOW + 5;

		if ( $using_cache ) {
			// Phase 14: persistent object-cache path uses TWO keys per
			// logical counter so wp_cache_incr() operates on a scalar integer
			// and wp_cache_get() reads integers. Mixing the two on a single
			// key (array via get; scalar via incr) fails on Redis
			// (mixed-type error) and can silently double-count on backends
			// that fall back to "set to 1" when incr is rejected.
			$count_key = $transient_key . ':count';
			$reset_key = $transient_key . ':reset';

			$count = wp_cache_get( $count_key, self::DATA_CACHE_GROUP );
			$reset = wp_cache_get( $reset_key, self::DATA_CACHE_GROUP );
			if ( ! is_int( $count ) || ! is_int( $reset ) ) {
				$count = 0;
				$reset = $now + self::RATE_LIMIT_WINDOW;
				wp_cache_set( $count_key, $count, self::DATA_CACHE_GROUP, $cache_ttl );
				wp_cache_set( $reset_key, $reset, self::DATA_CACHE_GROUP, $cache_ttl );
			}
			if ( $reset <= $now ) {
				$count = 0;
				$reset = $now + self::RATE_LIMIT_WINDOW;
				wp_cache_set( $count_key, $count, self::DATA_CACHE_GROUP, $cache_ttl );
				wp_cache_set( $reset_key, $reset, self::DATA_CACHE_GROUP, $cache_ttl );
			}
			$data = array(
				'count'    => $count,
				'reset_at' => $reset,
			);
		} else {
			$data = get_transient( $transient_key );
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
		}

		if ( (int) $data['count'] >= $rate_limit ) {
			$retry_ms = max( 1000, ( (int) $data['reset_at'] - $now ) * 1000 );
			$this->release_lock( $using_cache, $cache_lock_key, $option_lock_key, $lock_token );
			return SScribe_Rate_Limit_Decision::quota_exceeded( $bucket, $rate_limit, $retry_ms, (int) $data['reset_at'] );
		}

		$new_count = (int) $data['count'];
		if ( $using_cache ) {
			// Phase 14: incr on the scalar :count key.
			$incremented = wp_cache_incr( $count_key, 1, self::DATA_CACHE_GROUP );
			if ( false === $incremented ) {
				wp_cache_set( $count_key, 1, self::DATA_CACHE_GROUP, $cache_ttl );
				$new_count = 1;
			} else {
				$new_count = (int) $incremented;
			}
		} else {
			++$data['count'];
			set_transient( $transient_key, $data, $cache_ttl );
			$new_count = $data['count'];
		}

		if ( $new_count > $rate_limit ) {
			$retry_ms = max( 1000, ( (int) $data['reset_at'] - $now ) * 1000 );
			$this->release_lock( $using_cache, $cache_lock_key, $option_lock_key, $lock_token );
			return SScribe_Rate_Limit_Decision::quota_exceeded( $bucket, $rate_limit, $retry_ms, (int) $data['reset_at'] );
		}

		$this->release_lock( $using_cache, $cache_lock_key, $option_lock_key, $lock_token );

		return SScribe_Rate_Limit_Decision::allowed(
			$bucket,
			$rate_limit,
			(int) max( 0, $rate_limit - $new_count ),
			(int) $data['reset_at']
		);
	}

	/**
	 * Normalise a bucket name against the canonical allow-list.
	 *
	 * Falls back to {@see self::BUCKET_DEFAULT} when the supplied value
	 * is unknown, so unknown buckets never silently share a counter with
	 * any other call site.
	 *
	 * @param string $bucket Raw bucket name.
	 * @return string Canonical bucket name.
	 */
	private function normalise_bucket( string $bucket ): string {
		$bucket = substr( sanitize_key( $bucket ), 0, 40 );
		if ( '' === $bucket ) {
			return self::BUCKET_DEFAULT;
		}
		if ( in_array( $bucket, self::BUCKETS, true ) ) {
			return $bucket;
		}
		return self::BUCKET_DEFAULT;
	}

	/**
	 * Delete an option-backed micro-lock only when its complete observed value
	 * still owns the row. This prevents a stale reclaimer from deleting a live
	 * successor acquired between observation and deletion.
	 *
	 * @param string $option_key Lock option name.
	 * @param string $lock_value Complete observed lock value.
	 * @return bool True only when the exact row was deleted.
	 */
	private function delete_owned_option_lock( string $option_key, string $lock_value ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Ownership-conditional deletion cannot be expressed through delete_option().
		$deleted = $wpdb->delete(
			$wpdb->options,
			array(
				'option_name'  => $option_key,
				'option_value' => $lock_value,
			),
			array( '%s', '%s' )
		);
		wp_cache_delete( $option_key, 'options' );

		return 1 === $deleted;
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
