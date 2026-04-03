<?php
/**
 * Rate limiting for SScribe.
 *
 * @package SScribe
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SScribe_Rate_Limiter
 *
 * Implements rate limiting for export operations.
 */
class SScribe_Rate_Limiter {

	/**
	 * Default rate limit: requests per window.
	 */
	public const DEFAULT_LIMIT = 100;

	/**
	 * Default window in seconds.
	 */
	public const DEFAULT_WINDOW = 3600;

	/**
	 * Prefix for transients.
	 */
	private const TRANSIENT_PREFIX = 'sscribe_rate_';

	/**
	 * Check if user is within rate limits.
	 *
	 * @param int|null $user_id User ID (defaults to current user).
	 * @param string   $action  Action name (for per-action limits).
	 * @return bool True if within limits.
	 */
	public static function is_allowed( ?int $user_id = null, string $action = 'export' ): bool {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		$user_id = $user_id ?? get_current_user_id();
		$key     = self::get_rate_key( $user_id, $action );
		$limit   = self::get_limit( $action );
		$window  = self::get_window( $action );

		$current = self::get_current_count( $key, $window );

		return $current < $limit;
	}

	/**
	 * Record an action for rate limiting.
	 *
	 * @param int|null $user_id User ID (defaults to current user).
	 * @param string   $action  Action name.
	 * @return bool Success.
	 */
	public static function record( ?int $user_id = null, string $action = 'export' ): bool {
		$user_id = $user_id ?? get_current_user_id();
		$key     = self::get_rate_key( $user_id, $action );
		$window  = self::get_window( $action );

		$data = get_transient( $key );

		if ( false === $data ) {
			$data = array(
				'count'    => 0,
				'reset_at' => time() + $window,
			);
		}

		if ( isset( $data['reset_at'] ) && $data['reset_at'] <= time() ) {
			$data = array(
				'count'    => 0,
				'reset_at' => time() + $window,
			);
		}

		++$data['count'];

		return set_transient( $key, $data, $window );
	}

	/**
	 * Get remaining requests for user.
	 *
	 * @param int|null $user_id User ID.
	 * @param string   $action  Action name.
	 * @return int Remaining requests.
	 */
	public static function get_remaining( ?int $user_id = null, string $action = 'export' ): int {
		$user_id = $user_id ?? get_current_user_id();
		$key     = self::get_rate_key( $user_id, $action );
		$limit   = self::get_limit( $action );
		$window  = self::get_window( $action );

		$current = self::get_current_count( $key, $window );

		return max( 0, $limit - $current );
	}

	/**
	 * Get time until rate limit resets.
	 *
	 * @param int|null $user_id User ID.
	 * @param string   $action  Action name.
	 * @return int Seconds until reset.
	 */
	public static function get_reset_time( ?int $user_id = null, string $action = 'export' ): int {
		$user_id = $user_id ?? get_current_user_id();
		$key     = self::get_rate_key( $user_id, $action );
		$window  = self::get_window( $action );

		$data = get_transient( $key );

		if ( false === $data || ! isset( $data['reset_at'] ) ) {
			return $window;
		}

		return max( 0, $data['reset_at'] - time() );
	}

	/**
	 * Reset rate limit for user.
	 *
	 * @param int    $user_id User ID.
	 * @param string $action  Action name.
	 * @return bool Success.
	 */
	public static function reset( int $user_id, string $action = 'export' ): bool {
		$key = self::get_rate_key( $user_id, $action );
		return delete_transient( $key );
	}

	/**
	 * Get rate limit key for transient.
	 *
	 * @param int    $user_id User ID.
	 * @param string $action  Action name.
	 * @return string Transient key.
	 */
	private static function get_rate_key( int $user_id, string $action ): string {
		return self::TRANSIENT_PREFIX . $action . '_' . $user_id;
	}

	/**
	 * Get rate limit for action.
	 *
	 * @param string $action Action name.
	 * @return int Limit.
	 */
	private static function get_limit( string $action ): int {
		$limits = array(
			'export'    => (int) apply_filters( 'sscribe_rate_limit_export', self::DEFAULT_LIMIT ),
			'download'  => (int) apply_filters( 'sscribe_rate_limit_download', 500 ),
			'preflight' => (int) apply_filters( 'sscribe_rate_limit_preflight', 200 ),
		);

		return $limits[ $action ] ?? self::DEFAULT_LIMIT;
	}

	/**
	 * Get window for action.
	 *
	 * @param string $action Action name.
	 * @return int Window in seconds.
	 */
	private static function get_window( string $action ): int {
		$windows = array(
			'export'    => (int) apply_filters( 'sscribe_rate_window_export', self::DEFAULT_WINDOW ),
			'download'  => (int) apply_filters( 'sscribe_rate_window_download', 3600 ),
			'preflight' => (int) apply_filters( 'sscribe_rate_window_preflight', 3600 ),
		);

		return $windows[ $action ] ?? self::DEFAULT_WINDOW;
	}

	/**
	 * Get current count for rate limit.
	 *
	 * @param string $key    Transient key.
	 * @param int    $window Window in seconds.
	 * @return int Current count.
	 */
	private static function get_current_count( string $key, int $window ): int {
		$data = get_transient( $key );

		if ( false === $data ) {
			return 0;
		}

		if ( isset( $data['reset_at'] ) && $data['reset_at'] <= time() ) {
			return 0;
		}

		return $data['count'] ?? 0;
	}

	/**
	 * Get rate limit headers for API response.
	 *
	 * @param int|null $user_id User ID.
	 * @param string   $action  Action name.
	 * @return array Headers.
	 */
	public static function get_headers( ?int $user_id = null, string $action = 'export' ): array {
		$limit     = self::get_limit( $action );
		$remaining = self::get_remaining( $user_id, $action );
		$reset     = self::get_reset_time( $user_id, $action );

		return array(
			'X-RateLimit-Limit'     => $limit,
			'X-RateLimit-Remaining' => $remaining,
			'X-RateLimit-Reset'     => $reset,
		);
	}
}
