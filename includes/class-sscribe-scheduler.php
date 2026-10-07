<?php
/**
 * SScribe Scheduler
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
 * Runs schedules from WP-Cron or WP-CLI.
 *
 * A run goes idle -> running -> success or failed. Under WP-Cron a run
 * works in slices of a few seconds; when a slice ends before the export
 * does, a single event resumes the same session shortly after.
 */
final class SScribe_Scheduler {

	public const TICK_HOOK              = 'sscribe_scheduler_tick';
	public const CONTINUE_HOOK          = 'sscribe_schedule_continue';
	public const BUSY_RETRY_DELAY       = 600;
	public const CONTINUE_DELAY         = 30;
	public const DEFAULT_RETENTION_DAYS = 3;
	public const DEFAULT_MAX_ARCHIVES   = 30;

	private const STALE_AFTER   = 21600;
	private const MAX_BUDGET    = 45;
	private const MIN_BUDGET    = 10;
	private const BUDGET_MARGIN = 15;
	private const CLAIM_TTL     = 30;
	private const MAX_STEPS     = 100000;

	/**
	 * Tells whether a user has an interactive export in progress.
	 *
	 * @var callable(int): bool
	 */
	private $owner_is_busy;

	/**
	 * Set up the scheduler.
	 *
	 * @param SScribe_Schedule_Store                 $store          Where schedules live.
	 * @param SScribe_Export_Pipeline_Interface|null $pipeline       Export steps; the batch processor when null.
	 * @param SScribe_Zip_Handler|null               $zip_handler    Archive store; the shared handler when null.
	 * @param callable|null                          $owner_is_busy  Called with a user id; the session store is asked when null.
	 * @param float|null                             $time_budget    Seconds per slice; worked out from the environment when null.
	 * @param int                                    $retry_sleep_ms Wait between retries of a busy batch step.
	 */
	public function __construct(
		private readonly SScribe_Schedule_Store $store,
		private readonly ?SScribe_Export_Pipeline_Interface $pipeline = null,
		private readonly ?SScribe_Zip_Handler $zip_handler = null,
		?callable $owner_is_busy = null,
		private readonly ?float $time_budget = null,
		private readonly int $retry_sleep_ms = 500
	) {
		$this->owner_is_busy = $owner_is_busy ?? array( self::class, 'user_has_active_session' );
	}

	/**
	 * Make sure the hourly tick is queued.
	 *
	 * @return bool Whether the tick is queued afterwards.
	 */
	public static function ensure_scheduled(): bool {
		if ( false !== wp_next_scheduled( self::TICK_HOOK ) ) {
			return true;
		}

		return true === wp_schedule_event( time() + MINUTE_IN_SECONDS, 'hourly', self::TICK_HOOK );
	}

	/**
	 * Remove the tick and every queued continuation.
	 *
	 * @return void
	 */
	public static function unschedule_all(): void {
		wp_unschedule_hook( self::TICK_HOOK );
		wp_unschedule_hook( self::CONTINUE_HOOK );
	}

	/**
	 * Seconds one slice of work may take; 0 means no limit.
	 *
	 * WP-CLI and servers without an execution limit run a whole export in
	 * one go. Otherwise a slice stays well inside max_execution_time.
	 *
	 * @return int
	 */
	public static function time_budget(): int {
		$limit    = ini_get( 'max_execution_time' );
		$computed = 0;
		if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) && '0' !== $limit ) {
			$computed = min( self::MAX_BUDGET, max( self::MIN_BUDGET, (int) $limit - self::BUDGET_MARGIN ) );
		}

		return max( 0, (int) apply_filters( 'sscribe_schedule_time_budget', $computed ) );
	}

	/**
	 * Recover stalled runs, then start every schedule that is due.
	 *
	 * When the slice runs out before every due schedule has started, a
	 * follow-up tick is queued for the rest.
	 *
	 * @return array<string, SScribe_Export_Outcome> Outcomes keyed by schedule id.
	 */
	public function tick(): array {
		$now      = time();
		$deadline = $this->deadline();
		$this->recover_stalled( $now );

		$outcomes = array();
		foreach ( $this->store->due( $now ) as $schedule ) {
			if ( array() !== $outcomes && $deadline > 0 && microtime( true ) >= $deadline ) {
				wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::TICK_HOOK );
				break;
			}
			$outcomes[ $schedule->id ] = $this->run( $schedule->id );
		}

		return $outcomes;
	}

	/**
	 * Start a schedule now.
	 *
	 * @param string $id    Schedule id.
	 * @param bool   $force Run even when the schedule is disabled.
	 * @return SScribe_Export_Outcome The finished export, a "paused" outcome, or the reason it did not run.
	 */
	public function run( string $id, bool $force = false ): SScribe_Export_Outcome {
		$schedule = $this->store->get( $id );
		if ( null === $schedule ) {
			return self::failure( 'schedule_not_found', __( 'No schedule has that id.', 'sscribe-export-site-pages' ), 404 );
		}
		if ( ! $schedule->enabled && ! $force ) {
			return self::failure( 'schedule_disabled', __( 'The schedule is disabled.', 'sscribe-export-site-pages' ), 409 );
		}
		if ( $schedule->is_running() ) {
			return self::failure( 'schedule_running', __( 'The schedule is already running.', 'sscribe-export-site-pages' ), 409 );
		}

		$owner_problem = self::owner_problem( $schedule );
		if ( '' !== $owner_problem ) {
			return $this->finish_failed( $schedule, '', 'schedule_owner', $owner_problem );
		}

		wp_set_current_user( $schedule->owner_user_id );

		if ( ( $this->owner_is_busy )( $schedule->owner_user_id ) ) {
			return $this->postpone_for_busy_owner( $schedule );
		}

		$claimed = $this->claim( $schedule );
		if ( null === $claimed ) {
			return self::failure( 'schedule_running', __( 'The schedule is already running.', 'sscribe-export-site-pages' ), 409 );
		}

		return $this->start( $claimed );
	}

	/**
	 * Resume a paused run. Hooked to the continuation event.
	 *
	 * @param string $id         Schedule id.
	 * @param string $session_id Export session the run was working on.
	 * @return SScribe_Export_Outcome
	 */
	public function continue_run( string $id, string $session_id ): SScribe_Export_Outcome {
		$schedule = $this->store->get( $id );
		if ( null === $schedule || '' === $session_id || $schedule->running_session !== $session_id ) {
			self::logger()->warning(
				'Scheduled export continuation ignored; the run is no longer active',
				array( 'schedule_id' => sanitize_key( $id ) )
			);
			return self::failure( 'schedule_not_running', __( 'That scheduled run is no longer active.', 'sscribe-export-site-pages' ), 409 );
		}

		$owner_problem = self::owner_problem( $schedule );
		if ( '' !== $owner_problem ) {
			return $this->finish_failed( $schedule, $session_id, 'schedule_owner', $owner_problem );
		}

		wp_set_current_user( $schedule->owner_user_id );

		return $this->advance( $schedule, $session_id, $this->deadline() );
	}

	/**
	 * Whether a user has an interactive export in progress.
	 *
	 * @param int $user_id User id.
	 * @return bool
	 */
	public static function user_has_active_session( int $user_id ): bool {
		$session = self::service( SScribe_Session::class );
		if ( ! $session instanceof SScribe_Session ) {
			$session = new SScribe_Session();
		}

		return null !== $session->get_active_session_data( $user_id );
	}

	/**
	 * Create the export session for a claimed run and work on it.
	 *
	 * An incremental run with nothing changed since the last success counts
	 * as a success without an archive.
	 *
	 * @param SScribe_Schedule $schedule Claimed schedule.
	 * @return SScribe_Export_Outcome
	 */
	private function start( SScribe_Schedule $schedule ): SScribe_Export_Outcome {
		$pipeline = $this->pipeline();
		if ( null === $pipeline ) {
			return $this->finish_failed( $schedule, '', 'service_unavailable', __( 'The export service is unavailable.', 'sscribe-export-site-pages' ) );
		}

		$deadline = $this->deadline();
		$job      = $schedule->to_job();
		$started  = $pipeline->start_export_job( $job, new SScribe_Headless_Export_Context( $schedule->owner_user_id ) );

		if ( ! $started->is_success() ) {
			if ( 'no_pages_selected' === $started->code() && $job->modified_since > 0 ) {
				$this->finish_success( $schedule, '', array( 'pages' => 0 ) );
				return SScribe_Export_Outcome::ok(
					array(
						'status'  => 'complete',
						'pages'   => 0,
						'message' => __( 'Nothing changed since the last run.', 'sscribe-export-site-pages' ),
					)
				);
			}
			return $this->finish_failed( $schedule, '', $started->code(), self::message_of( $started ), $started );
		}

		$session_id = sanitize_key( (string) ( $started->payload()['session_id'] ?? '' ) );
		if ( '' === $session_id ) {
			return $this->finish_failed( $schedule, '', 'session_missing', __( 'The export did not start a session.', 'sscribe-export-site-pages' ) );
		}

		$running = $schedule->with( array( 'running_session' => $session_id ) );
		if ( ! $this->store->save( $running ) ) {
			return $this->finish_failed( $schedule, '', 'schedule_save_failed', __( 'The schedule could not be saved.', 'sscribe-export-site-pages' ) );
		}

		return $this->advance( $running, $session_id, $deadline );
	}

	/**
	 * Work on a running session until it finishes, fails or the slice ends.
	 *
	 * @param SScribe_Schedule $schedule   Running schedule.
	 * @param string           $session_id Export session.
	 * @param float            $deadline   Unix time to pause at; 0 never pauses.
	 * @return SScribe_Export_Outcome
	 */
	private function advance( SScribe_Schedule $schedule, string $session_id, float $deadline ): SScribe_Export_Outcome {
		$pipeline = $this->pipeline();
		if ( null === $pipeline ) {
			return $this->finish_failed( $schedule, $session_id, 'service_unavailable', __( 'The export service is unavailable.', 'sscribe-export-site-pages' ) );
		}

		$runner  = new SScribe_Export_Runner( $pipeline, self::MAX_STEPS, $this->retry_sleep_ms );
		$outcome = $runner->advance( $session_id, new SScribe_Headless_Export_Context( $schedule->owner_user_id ), $deadline );

		if ( ! $outcome->is_success() ) {
			return $this->finish_failed( $schedule, $session_id, $outcome->code(), self::message_of( $outcome ), $outcome );
		}

		if ( 'paused' === ( $outcome->payload()['status'] ?? '' ) ) {
			$queued = wp_schedule_single_event( time() + self::CONTINUE_DELAY, self::CONTINUE_HOOK, array( $schedule->id, $session_id ) );
			if ( true !== $queued ) {
				return $this->finish_failed( $schedule, $session_id, 'continue_not_queued', __( 'The next part of the export could not be queued.', 'sscribe-export-site-pages' ) );
			}
			return $outcome;
		}

		$this->finish_success( $schedule, $session_id, $outcome->payload() );

		return $outcome;
	}

	/**
	 * Record a successful run, tag and prune its archives, then notify.
	 *
	 * The watermark for the next incremental run is the start of this one,
	 * so posts edited while it ran are picked up next time.
	 *
	 * @param SScribe_Schedule         $schedule   Schedule that ran.
	 * @param string                   $session_id Session the run used, empty when none was needed.
	 * @param array<string|int, mixed> $payload    Finished export payload.
	 * @return void
	 */
	private function finish_success( SScribe_Schedule $schedule, string $session_id, array $payload ): void {
		$current = $this->current( $schedule, $session_id );
		if ( null === $current ) {
			return;
		}

		$now      = time();
		$basename = self::clean_basename( (string) ( $payload['filename'] ?? '' ) );
		if ( '' !== $basename ) {
			$this->tag_archive( $current, $basename, $now );
		}

		$updated = $current->with(
			array(
				'last_run_at'     => $current->run_started_at > 0 ? $current->run_started_at : $now,
				'last_run_status' => 'success',
				'last_run_file'   => $basename,
				'last_error'      => '',
				'running_session' => '',
				'run_started_at'  => 0,
				'next_run_at'     => $current->compute_next_run( $now, wp_timezone()->getName() ),
			)
		);
		$this->persist( $updated );

		if ( '' !== $basename ) {
			$this->apply_retention( $updated, $basename, $now );
		}

		$this->notify( $updated, $payload );

		do_action( 'sscribe_schedule_completed', $updated, $payload );
	}

	/**
	 * Record a failed run, notify, and keep the schedule going.
	 *
	 * @param SScribe_Schedule            $schedule   Schedule that failed.
	 * @param string                      $session_id Session the run used, empty when none.
	 * @param string                      $code       Machine-readable reason.
	 * @param string                      $message    Translated explanation.
	 * @param SScribe_Export_Outcome|null $outcome    Outcome to hand back; a new failure when null.
	 * @return SScribe_Export_Outcome
	 */
	private function finish_failed( SScribe_Schedule $schedule, string $session_id, string $code, string $message, ?SScribe_Export_Outcome $outcome = null ): SScribe_Export_Outcome {
		$code    = '' !== $code ? sanitize_key( $code ) : 'export_failed';
		$message = self::strip_paths( $message );
		$outcome = $outcome ?? self::failure( $code, $message, 500 );

		self::logger()->error(
			'Scheduled export failed',
			array(
				'schedule_id' => $schedule->id,
				'code'        => $code,
			)
		);
		SScribe_Operational_Logger::record(
			SScribe_Operational_Logger::LEVEL_ERROR,
			'Scheduled export failed',
			array(
				'error_code'  => $code,
				'schedule_id' => $schedule->id,
			)
		);

		$current = $this->current( $schedule, $session_id );
		if ( null === $current ) {
			return $outcome;
		}

		$updated = $current->with(
			array(
				'last_run_status' => 'failed',
				'last_error'      => $message,
				'running_session' => '',
				'run_started_at'  => 0,
				'next_run_at'     => $current->compute_next_run( time(), wp_timezone()->getName() ),
			)
		);
		$this->persist( $updated );

		$failure = array(
			'code'    => $code,
			'message' => $message,
		);
		$this->notify( $updated, $failure );

		do_action( 'sscribe_schedule_failed', $updated, $code, $message );

		return $outcome;
	}

	/**
	 * Mark a schedule as running unless another request got there first.
	 *
	 * @param SScribe_Schedule $schedule Schedule to claim.
	 * @return SScribe_Schedule|null The running schedule, or null when it could not be claimed.
	 */
	private function claim( SScribe_Schedule $schedule ): ?SScribe_Schedule {
		$locks = new SScribe_Export_Lock_Manager();
		$name  = 'schedule-run-' . $schedule->id;
		$token = $locks->acquire_lock( $name, self::CLAIM_TTL, self::CLAIM_TTL );
		if ( null === $token ) {
			return null;
		}

		try {
			$fresh = $this->store->get( $schedule->id );
			if ( null === $fresh || $fresh->is_running() ) {
				return null;
			}
			$running = $fresh->with(
				array(
					'last_run_status' => 'running',
					'running_session' => '',
					'run_started_at'  => time(),
				)
			);
			return $this->store->save( $running ) ? $running : null;
		} finally {
			$locks->release_lock( $name, $token );
		}
	}

	/**
	 * Try again later because the owner is exporting by hand.
	 *
	 * @param SScribe_Schedule $schedule Schedule that was due.
	 * @return SScribe_Export_Outcome
	 */
	private function postpone_for_busy_owner( SScribe_Schedule $schedule ): SScribe_Export_Outcome {
		$this->persist( $schedule->with( array( 'next_run_at' => time() + self::BUSY_RETRY_DELAY ) ) );

		return self::failure(
			'owner_busy',
			__( 'The schedule owner has an export in progress; the run will be retried in 10 minutes.', 'sscribe-export-site-pages' ),
			409
		);
	}

	/**
	 * Fail runs that have been going for far too long.
	 *
	 * @param int $now Current Unix time.
	 * @return void
	 */
	private function recover_stalled( int $now ): void {
		foreach ( $this->store->all() as $schedule ) {
			if ( ! $schedule->is_running() || ( $now - $schedule->run_started_at ) < self::STALE_AFTER ) {
				continue;
			}
			$this->finish_failed(
				$schedule,
				$schedule->running_session,
				'schedule_stalled',
				__( 'The run stopped making progress and was abandoned.', 'sscribe-export-site-pages' )
			);
		}
	}

	/**
	 * The stored schedule, if it still belongs to the given run.
	 *
	 * @param SScribe_Schedule $schedule   Schedule as the run knew it.
	 * @param string           $session_id Session of the run.
	 * @return SScribe_Schedule|null
	 */
	private function current( SScribe_Schedule $schedule, string $session_id ): ?SScribe_Schedule {
		$current = $this->store->get( $schedule->id );
		if ( null === $current ) {
			self::logger()->info( 'Schedule was deleted while it ran', array( 'schedule_id' => $schedule->id ) );
			return null;
		}
		if ( $current->running_session !== $session_id ) {
			self::logger()->warning( 'Schedule run result ignored; a newer run owns the schedule', array( 'schedule_id' => $schedule->id ) );
			return null;
		}

		return $current;
	}

	/**
	 * Save a schedule, logging when it cannot be saved.
	 *
	 * @param SScribe_Schedule $schedule Schedule to save.
	 * @return void
	 */
	private function persist( SScribe_Schedule $schedule ): void {
		if ( $this->store->save( $schedule ) ) {
			return;
		}

		self::logger()->error( 'Schedule state could not be saved', array( 'schedule_id' => $schedule->id ) );
		SScribe_Operational_Logger::record(
			SScribe_Operational_Logger::LEVEL_ERROR,
			'Schedule state could not be saved',
			array( 'schedule_id' => $schedule->id )
		);
	}

	/**
	 * Mark an archive as produced by the schedule and set how long it is kept.
	 *
	 * @param SScribe_Schedule $schedule Schedule that produced it.
	 * @param string           $basename ZIP filename.
	 * @param int              $now      Current Unix time.
	 * @return void
	 */
	private function tag_archive( SScribe_Schedule $schedule, string $basename, int $now ): void {
		$tagged = $this->zip()->tag_export_row(
			$basename,
			array(
				'schedule_id'    => $schedule->id,
				'schedule_label' => $schedule->label,
				'retain_until'   => $now + $schedule->retention_seconds( self::DEFAULT_RETENTION_DAYS ),
			)
		);
		if ( ! $tagged ) {
			self::logger()->warning(
				'Scheduled export archive could not be tagged',
				array(
					'schedule_id' => $schedule->id,
					'filename'    => $basename,
				)
			);
		}
	}

	/**
	 * Delete this schedule's archives that are too old or too many.
	 *
	 * @param SScribe_Schedule $schedule Schedule whose archives to prune.
	 * @param string           $keep     Archive that must stay.
	 * @param int              $now      Current Unix time.
	 * @return void
	 */
	private function apply_retention( SScribe_Schedule $schedule, string $keep, int $now ): void {
		$max_archives = max( 1, (int) apply_filters( 'sscribe_schedule_max_archives', self::DEFAULT_MAX_ARCHIVES, $schedule ) );
		$cutoff       = $now - $schedule->retention_seconds( self::DEFAULT_RETENTION_DAYS );
		$kept         = 0;

		foreach ( $this->zip()->exports_for_schedule( $schedule->id ) as $row ) {
			$basename = (string) ( $row['basename'] ?? '' );
			$created  = (int) ( $row['created_at'] ?? 0 );
			if ( $basename === $keep || ( $kept < $max_archives && $created >= $cutoff ) ) {
				++$kept;
				continue;
			}
			if ( ! $this->zip()->delete_export( $basename, $schedule->owner_user_id ) ) {
				self::logger()->warning(
					'Old scheduled export could not be deleted',
					array(
						'schedule_id' => $schedule->id,
						'filename'    => $basename,
					)
				);
			}
		}
	}

	/**
	 * Email the owner about a finished or failed run.
	 *
	 * @param SScribe_Schedule         $schedule Schedule after the run.
	 * @param array<string|int, mixed> $payload  Export payload, or code and message for a failure.
	 * @return void
	 */
	private function notify( SScribe_Schedule $schedule, array $payload ): void {
		if ( ! $schedule->notify ) {
			return;
		}

		$user = get_userdata( $schedule->owner_user_id );
		$mail = apply_filters(
			'sscribe_schedule_notification',
			array(
				'to'      => $user instanceof WP_User ? (string) $user->user_email : '',
				'subject' => self::notification_subject( $schedule ),
				'message' => self::notification_body( $schedule, $payload ),
			),
			$schedule,
			$payload
		);

		if ( ! is_array( $mail ) || empty( $mail['to'] ) || ! is_string( $mail['to'] ) ) {
			return;
		}

		if ( ! wp_mail( $mail['to'], (string) ( $mail['subject'] ?? '' ), (string) ( $mail['message'] ?? '' ) ) ) {
			self::logger()->warning( 'Schedule notification email was not sent', array( 'schedule_id' => $schedule->id ) );
		}
	}

	/**
	 * Subject line of the notification email.
	 *
	 * @param SScribe_Schedule $schedule Schedule after the run.
	 * @return string
	 */
	private static function notification_subject( SScribe_Schedule $schedule ): string {
		$site = html_entity_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES, 'UTF-8' );

		if ( 'success' === $schedule->last_run_status ) {
			/* translators: 1: Site name, 2: Schedule label. */
			$format = __( '[%1$s] Scheduled export "%2$s" finished', 'sscribe-export-site-pages' );
		} else {
			/* translators: 1: Site name, 2: Schedule label. */
			$format = __( '[%1$s] Scheduled export "%2$s" failed', 'sscribe-export-site-pages' );
		}

		return sprintf( $format, $site, $schedule->label );
	}

	/**
	 * Body of the notification email.
	 *
	 * @param SScribe_Schedule         $schedule Schedule after the run.
	 * @param array<string|int, mixed> $payload  Export payload, or code and message for a failure.
	 * @return string
	 */
	private static function notification_body( SScribe_Schedule $schedule, array $payload ): string {
		$errors = $payload['errors'] ?? 0;
		$lines  = array(
			/* translators: %s: Schedule label. */
			sprintf( __( 'Schedule: %s', 'sscribe-export-site-pages' ), $schedule->label ),
			/* translators: %s: Run status, success or failed. */
			sprintf( __( 'Status: %s', 'sscribe-export-site-pages' ), 'success' === $schedule->last_run_status ? __( 'success', 'sscribe-export-site-pages' ) : __( 'failed', 'sscribe-export-site-pages' ) ),
		);

		if ( 'success' === $schedule->last_run_status ) {
			/* translators: %d: Number of pages exported. */
			$lines[] = sprintf( __( 'Pages: %d', 'sscribe-export-site-pages' ), (int) ( $payload['pages'] ?? 0 ) );
			/* translators: %d: Number of pages that failed to export. */
			$lines[] = sprintf( __( 'Errors: %d', 'sscribe-export-site-pages' ), is_array( $errors ) ? count( $errors ) : (int) $errors );
			$lines[] = '' !== $schedule->last_run_file
				/* translators: %s: ZIP filename. */
				? sprintf( __( 'File: %s', 'sscribe-export-site-pages' ), $schedule->last_run_file )
				: __( 'No archive was made because nothing changed since the last run.', 'sscribe-export-site-pages' );
		} else {
			/* translators: %s: Error message. */
			$lines[] = sprintf( __( 'Error: %s', 'sscribe-export-site-pages' ), $schedule->last_error );
		}

		/* translators: %s: URL of the export history page. */
		$lines[] = sprintf( __( 'Export history: %s', 'sscribe-export-site-pages' ), admin_url( 'admin.php?page=sscribe-export&tab=history' ) );

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * Why the owner cannot run the schedule, or an empty string.
	 *
	 * @param SScribe_Schedule $schedule Schedule to check.
	 * @return string Translated reason.
	 */
	private static function owner_problem( SScribe_Schedule $schedule ): string {
		$user = get_userdata( $schedule->owner_user_id );
		if ( ! $user instanceof WP_User ) {
			return __( 'The schedule owner no longer exists.', 'sscribe-export-site-pages' );
		}
		if ( ! user_can( $user, SScribe_Capabilities::get_required() ) ) {
			return __( 'The schedule owner is no longer allowed to export.', 'sscribe-export-site-pages' );
		}

		return '';
	}

	/**
	 * Unix time the current slice must pause at, or 0 for no limit.
	 *
	 * @return float
	 */
	private function deadline(): float {
		$budget = $this->time_budget ?? (float) self::time_budget();

		return $budget > 0 ? microtime( true ) + $budget : 0.0;
	}

	/**
	 * The export pipeline.
	 *
	 * @return SScribe_Export_Pipeline_Interface|null
	 */
	private function pipeline(): ?SScribe_Export_Pipeline_Interface {
		if ( null !== $this->pipeline ) {
			return $this->pipeline;
		}

		$service = self::service( SScribe_Batch_Processor::class );

		return $service instanceof SScribe_Export_Pipeline_Interface ? $service : null;
	}

	/**
	 * The archive store.
	 *
	 * @return SScribe_Zip_Handler
	 */
	private function zip(): SScribe_Zip_Handler {
		if ( null !== $this->zip_handler ) {
			return $this->zip_handler;
		}

		$service = self::service( SScribe_Zip_Handler::class );

		return $service instanceof SScribe_Zip_Handler ? $service : new SScribe_Zip_Handler();
	}

	/**
	 * A service from the container, or null when it is not available.
	 *
	 * @param string $id Service id.
	 * @return mixed
	 */
	private static function service( string $id ): mixed {
		$container = SScribe_Container::instance();
		if ( ! $container->has( $id ) ) {
			return null;
		}

		try {
			return $container->get( $id );
		} catch ( \RuntimeException $e ) {
			self::logger()->error(
				'Scheduler could not resolve a service',
				array(
					'service' => $id,
					'error'   => $e->getMessage(),
				)
			);
			return null;
		}
	}

	/**
	 * Message from an outcome payload, or a generic one.
	 *
	 * @param SScribe_Export_Outcome $outcome Failed outcome.
	 * @return string
	 */
	private static function message_of( SScribe_Export_Outcome $outcome ): string {
		$message = $outcome->payload()['message'] ?? '';

		return is_string( $message ) && '' !== trim( $message )
			? $message
			: __( 'The export failed.', 'sscribe-export-site-pages' );
	}

	/**
	 * Replace anything that looks like a filesystem path.
	 *
	 * @param string $message Message.
	 * @return string
	 */
	private static function strip_paths( string $message ): string {
		$clean = preg_replace( '#(?:[A-Za-z]:)?(?:[\\\\/][\w.\-]+){2,}[\\\\/]?#', '[path]', $message );

		return is_string( $clean ) ? $clean : '';
	}

	/**
	 * Keep a bare ZIP filename.
	 *
	 * @param string $filename Filename from a payload.
	 * @return string
	 */
	private static function clean_basename( string $filename ): string {
		return 1 === preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]{0,199}\.zip$/D', $filename ) ? $filename : '';
	}

	/**
	 * A failed outcome.
	 *
	 * @param string $code    Machine-readable reason.
	 * @param string $message Translated explanation.
	 * @param int    $status  HTTP-style status.
	 * @return SScribe_Export_Outcome
	 */
	private static function failure( string $code, string $message, int $status ): SScribe_Export_Outcome {
		return SScribe_Export_Outcome::fail(
			array(
				'code'    => $code,
				'message' => $message,
			),
			$status
		);
	}

	/**
	 * Plugin logger.
	 *
	 * @return SScribe_Logger_Interface
	 */
	private static function logger(): SScribe_Logger_Interface {
		return SScribe_Logger::instance( SScribe_Logger::is_logging_enabled() );
	}
}
