<?php
/**
 * SScribe Rate Limit Decision
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
 * Structured result of a rate-limit check.
 *
 * Replaces the legacy bool-only return value so callers can distinguish
 * genuine quota exhaustion from internal micro-lock contention and
 * communicate that difference to the client (HTTP 429 vs 503).
 *
 * Reasons:
 *   - allowed:            request permitted, counter incremented.
 *   - quota_exceeded:     user/IP is over the per-minute budget; retry after reset_at.
 *   - limiter_contention: micro-lock could not be acquired; short bounded retry advised.
 */
final class SScribe_Rate_Limit_Decision {

	public const REASON_ALLOWED            = 'allowed';
	public const REASON_QUOTA_EXCEEDED     = 'quota_exceeded';
	public const REASON_LIMITER_CONTENTION = 'limiter_contention';

	/**
	 * Build the decision. Prefer the static factories.
	 *
	 * @param bool     $allowed        True when the request is permitted.
	 * @param string   $reason         Canonical reason constant.
	 * @param string   $bucket         Canonical bucket name.
	 * @param int      $retry_after_ms Recommended retry delay in ms.
	 * @param int      $limit          Per-minute limit value.
	 * @param int|null $remaining      Remaining budget or null when unknown.
	 * @param int|null $reset_at       Unix timestamp when the bucket resets.
	 */
	public function __construct(
		public readonly bool $allowed,
		public readonly string $reason,
		public readonly string $bucket,
		public readonly int $retry_after_ms,
		public readonly int $limit,
		public readonly ?int $remaining,
		public readonly ?int $reset_at
	) {
	}

	/**
	 * Build an "allowed" decision for the supplied bucket.
	 *
	 * @param string $bucket    Canonical bucket name.
	 * @param int    $limit     Per-minute limit value.
	 * @param int    $remaining Remaining budget after this call.
	 * @param int    $reset_at  Unix timestamp when the bucket resets.
	 * @return self Decision.
	 */
	public static function allowed( string $bucket, int $limit, int $remaining, int $reset_at ): self {
		return new self(
			true,
			self::REASON_ALLOWED,
			$bucket,
			0,
			$limit,
			$remaining,
			$reset_at
		);
	}

	/**
	 * Build a "quota exceeded" decision for the supplied bucket.
	 *
	 * @param string $bucket         Canonical bucket name.
	 * @param int    $limit          Per-minute limit value.
	 * @param int    $retry_after_ms Recommended retry delay in ms (clamped to >=1000).
	 * @param int    $reset_at       Unix timestamp when the bucket resets.
	 * @return self Decision.
	 */
	public static function quota_exceeded( string $bucket, int $limit, int $retry_after_ms, int $reset_at ): self {
		return new self(
			false,
			self::REASON_QUOTA_EXCEEDED,
			$bucket,
			max( 1000, $retry_after_ms ),
			$limit,
			0,
			$reset_at
		);
	}

	/**
	 * Build a "limiter contention" decision for the supplied bucket.
	 *
	 * @param string $bucket         Canonical bucket name.
	 * @param int    $limit          Per-minute limit value (informational).
	 * @param int    $retry_after_ms Recommended retry delay in ms (clamped to >=200).
	 * @return self Decision.
	 */
	public static function limiter_contention( string $bucket, int $limit, int $retry_after_ms ): self {
		return new self(
			false,
			self::REASON_LIMITER_CONTENTION,
			$bucket,
			max( 200, $retry_after_ms ),
			$limit,
			null,
			null
		);
	}

	/**
	 * Map the decision to a stable error code suitable for SScribe JSON error payloads.
	 */
	public function error_code(): string {
		switch ( $this->reason ) {
			case self::REASON_QUOTA_EXCEEDED:
				return 'rate_limited';
			case self::REASON_LIMITER_CONTENTION:
				return 'rate_limiter_busy';
			case self::REASON_ALLOWED:
			default:
				return 'rate_limit_allowed';
		}
	}

	/**
	 * Recommended HTTP status code for the decision.
	 *
	 * Per spec: only genuine quota exhaustion is 429. Internal micro-lock
	 * contention is 503 because the lock normally lives for milliseconds
	 * and is not user-facing rate limiting.
	 */
	public function http_status(): int {
		switch ( $this->reason ) {
			case self::REASON_QUOTA_EXCEEDED:
				return 429;
			case self::REASON_LIMITER_CONTENTION:
				return 503;
			case self::REASON_ALLOWED:
			default:
				return 200;
		}
	}
}
