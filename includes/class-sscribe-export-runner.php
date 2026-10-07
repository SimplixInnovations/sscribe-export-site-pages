<?php
/**
 * SScribe Export Runner
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
 * Drives an export from start to finished ZIP in a single process.
 *
 * This is the loop the admin screen runs over AJAX, done in-process for
 * callers with nobody watching, such as WP-CLI.
 */
final class SScribe_Export_Runner {

	private const STALL_LIMIT = 3;

	/**
	 * Session id of the most recent run, once started.
	 *
	 * @var string
	 */
	private string $last_session_id = '';

	/**
	 * Set up the runner.
	 *
	 * @param SScribe_Export_Pipeline_Interface $pipeline       Export steps to drive.
	 * @param int                               $max_steps      Upper bound on batch steps per run.
	 * @param int                               $retry_sleep_ms Wait between retries of a busy step, in milliseconds.
	 * @param int                               $max_retries    Retries allowed for a busy step in a row.
	 */
	public function __construct(
		private readonly SScribe_Export_Pipeline_Interface $pipeline,
		private readonly int $max_steps = 100000,
		private readonly int $retry_sleep_ms = 500,
		private readonly int $max_retries = 20
	) {
	}

	/**
	 * Run a job to completion.
	 *
	 * @param SScribe_Export_Job               $job     What to export.
	 * @param SScribe_Export_Context_Interface $context Who is running the export and how.
	 * @return SScribe_Export_Outcome The finished export, or the reason it stopped.
	 */
	public function run( SScribe_Export_Job $job, SScribe_Export_Context_Interface $context ): SScribe_Export_Outcome {
		$this->last_session_id = '';

		$started = $this->pipeline->start_export_job( $job, $context );
		if ( ! $started->is_success() ) {
			return $started;
		}

		$session_id            = (string) ( $started->payload()['session_id'] ?? '' );
		$this->last_session_id = $session_id;

		$last_processed = 0;
		$stalled_steps  = 0;
		$retries        = 0;

		for ( $step = 0; $step < $this->max_steps; $step++ ) {
			$outcome = $this->pipeline->process_batch_step( $session_id, $context );

			if ( $this->is_busy( $outcome ) ) {
				if ( $retries >= $this->max_retries ) {
					return $outcome;
				}
				++$retries;
				$this->pause();
				continue;
			}

			if ( ! $outcome->is_success() ) {
				return $outcome;
			}

			$retries = 0;
			$payload = $outcome->payload();
			$status  = (string) ( $payload['status'] ?? '' );

			if ( 'complete' === $status ) {
				return $outcome;
			}

			if ( 'finalizing' === $status ) {
				return $this->pipeline->finalize_session( $session_id, $context );
			}

			if ( ! empty( $payload['cancelled'] ) ) {
				return self::cancelled();
			}

			$processed = (int) ( $payload['processed'] ?? 0 );
			if ( $processed > $last_processed ) {
				$last_processed = $processed;
				$stalled_steps  = 0;
				continue;
			}

			++$stalled_steps;
			if ( $stalled_steps >= self::STALL_LIMIT ) {
				return SScribe_Export_Outcome::fail(
					array(
						'code'    => 'stalled',
						'message' => __( 'Export did not advance; see the export log.', 'sscribe-export-site-pages' ),
					),
					500
				);
			}
		}

		return SScribe_Export_Outcome::fail(
			array(
				'code'    => 'step_limit',
				'message' => __( 'Export stopped after too many steps without finishing.', 'sscribe-export-site-pages' ),
			),
			500
		);
	}

	/**
	 * Session id of the most recent run, or an empty string.
	 *
	 * @return string
	 */
	public function last_session_id(): string {
		return $this->last_session_id;
	}

	/**
	 * Whether the step should simply be tried again.
	 *
	 * @param SScribe_Export_Outcome $outcome Step outcome.
	 * @return bool
	 */
	private function is_busy( SScribe_Export_Outcome $outcome ): bool {
		return in_array(
			$outcome->kind(),
			array( SScribe_Export_Outcome::KIND_LOCK_CONFLICT, SScribe_Export_Outcome::KIND_RATE_LIMITED ),
			true
		);
	}

	/**
	 * Wait before retrying a busy step.
	 *
	 * @return void
	 */
	private function pause(): void {
		if ( $this->retry_sleep_ms <= 0 ) {
			return;
		}

		usleep( $this->retry_sleep_ms * 1000 );
	}

	/**
	 * Outcome for a run that was cancelled part way through a batch.
	 *
	 * @return SScribe_Export_Outcome
	 */
	private static function cancelled(): SScribe_Export_Outcome {
		return SScribe_Export_Outcome::fail(
			array(
				'code'      => 'cancelled',
				'message'   => __( 'Export was cancelled.', 'sscribe-export-site-pages' ),
				'cancelled' => true,
			),
			200
		);
	}
}
