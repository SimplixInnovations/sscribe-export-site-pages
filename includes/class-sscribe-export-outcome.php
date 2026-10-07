<?php
/**
 * SScribe Export Outcome
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
 * Result of one export step or finalize call.
 *
 * The export core returns one of these instead of writing a response, so
 * the AJAX handlers and any non-HTTP driver can decide how to deliver it.
 */
final class SScribe_Export_Outcome {

	public const KIND_OK            = 'ok';
	public const KIND_ERROR         = 'error';
	public const KIND_RATE_LIMITED  = 'rate_limited';
	public const KIND_LOCK_CONFLICT = 'lock_conflict';

	/**
	 * Build an outcome. Use the named constructors instead.
	 *
	 * @param string                           $kind           One of the KIND_* constants.
	 * @param array<string|int, mixed>         $payload        Response payload.
	 * @param int                              $http_status    HTTP status to answer with.
	 * @param SScribe_Rate_Limit_Decision|null $decision       Rate limit decision, when rate limited.
	 * @param string                           $session_id     Session id, when the lock was busy.
	 * @param int                              $retry_after_ms Suggested retry delay in milliseconds.
	 */
	private function __construct(
		private readonly string $kind,
		private readonly array $payload,
		private readonly int $http_status,
		private readonly ?SScribe_Rate_Limit_Decision $decision,
		private readonly string $session_id,
		private readonly int $retry_after_ms
	) {
	}

	/**
	 * A successful step.
	 *
	 * @param array<string|int, mixed> $payload     Response payload.
	 * @param int                      $http_status HTTP status.
	 * @return self
	 */
	public static function ok( array $payload = array(), int $http_status = 200 ): self {
		return new self( self::KIND_OK, $payload, $http_status, null, '', 0 );
	}

	/**
	 * A failed step.
	 *
	 * @param array<string|int, mixed> $payload     Error payload.
	 * @param int                      $http_status HTTP status.
	 * @return self
	 */
	public static function fail( array $payload, int $http_status ): self {
		return new self( self::KIND_ERROR, $payload, $http_status, null, '', 0 );
	}

	/**
	 * The caller hit the rate limiter.
	 *
	 * @param SScribe_Rate_Limit_Decision $decision Denying decision.
	 * @return self
	 */
	public static function rate_limited( SScribe_Rate_Limit_Decision $decision ): self {
		return new self(
			self::KIND_RATE_LIMITED,
			array(
				'code'     => $decision->error_code(),
				'retry'    => true,
				'retry_in' => $decision->retry_after_ms,
			),
			$decision->http_status(),
			$decision,
			'',
			$decision->retry_after_ms
		);
	}

	/**
	 * Another request holds the session lock.
	 *
	 * @param string $session_id     Session id.
	 * @param int    $retry_after_ms Suggested retry delay in milliseconds.
	 * @return self
	 */
	public static function lock_conflict( string $session_id, int $retry_after_ms = 5000 ): self {
		return new self(
			self::KIND_LOCK_CONFLICT,
			array(
				'code'       => 'batch_in_progress',
				'retry'      => true,
				'retry_in'   => $retry_after_ms,
				'session_id' => $session_id,
			),
			409,
			null,
			$session_id,
			$retry_after_ms
		);
	}

	/**
	 * Whether the step succeeded.
	 *
	 * @return bool
	 */
	public function is_success(): bool {
		return self::KIND_OK === $this->kind;
	}

	/**
	 * Outcome kind, one of the KIND_* constants.
	 *
	 * @return string
	 */
	public function kind(): string {
		return $this->kind;
	}

	/**
	 * Response payload.
	 *
	 * @return array<string|int, mixed>
	 */
	public function payload(): array {
		return $this->payload;
	}

	/**
	 * HTTP status.
	 *
	 * @return int
	 */
	public function http_status(): int {
		return $this->http_status;
	}

	/**
	 * Machine-readable code from the payload, or an empty string.
	 *
	 * @return string
	 */
	public function code(): string {
		$code = $this->payload['code'] ?? '';
		return is_string( $code ) ? $code : '';
	}

	/**
	 * Rate limit decision for rate-limited outcomes.
	 *
	 * @return SScribe_Rate_Limit_Decision|null
	 */
	public function decision(): ?SScribe_Rate_Limit_Decision {
		return $this->decision;
	}

	/**
	 * Session id for lock conflicts.
	 *
	 * @return string
	 */
	public function session_id(): string {
		return $this->session_id;
	}

	/**
	 * Suggested retry delay in milliseconds.
	 *
	 * @return int
	 */
	public function retry_after_ms(): int {
		return $this->retry_after_ms;
	}
}
