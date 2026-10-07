<?php
/**
 * Unit tests for SScribe_Scheduler.
 *
 * A scripted pipeline and a recording archive store stand in for the batch
 * processor and the ZIP handler, so every state change of a run can be
 * checked without exporting anything.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SScribe_Scheduler_Test extends TestCase {

	private const OWNER = 7;
	private const SID   = 'b1c2d3e4f5a60718';
	private const ZIP   = 'site-export-2026.zip';

	private \SScribe_Schedule_Store $store;

	private Recording_Zip_Handler $zip;

	/** @var array<string, mixed> */
	private array $saved_globals = array();

	/** @var list<string> */
	private array $cleanup = array();

	protected function setUp(): void {
		parent::setUp();
		foreach ( array( 'sscribe_test_actions', 'sscribe_test_filters', 'sscribe_test_scheduled_events', 'sscribe_test_current_user_id', 'sscribe_test_current_user' ) as $key ) {
			$this->saved_globals[ $key ] = $GLOBALS[ $key ] ?? null;
		}
		$GLOBALS['sscribe_test_actions'] = (array) ( $GLOBALS['sscribe_test_actions'] ?? array() );
		$GLOBALS['sscribe_test_filters'] = (array) ( $GLOBALS['sscribe_test_filters'] ?? array() );
		unset( $GLOBALS['sscribe_test_options'][ \SScribe_Schedule_Store::OPTION ] );
		$GLOBALS['sscribe_test_single_events'] = array();
		$GLOBALS['sscribe_test_mail']          = array();
		$GLOBALS['sscribe_test_scheduled_events'] = array();

		$owner                                       = new \WP_User( self::OWNER );
		$owner->user_email                           = 'owner@example.test';
		$GLOBALS['sscribe_test_users']               = array( self::OWNER => $owner );
		$GLOBALS['sscribe_test_user_caps']           = array( self::OWNER => array( 'sscribe_export' ) );

		$this->store = new \SScribe_Schedule_Store();
		$this->zip   = new Recording_Zip_Handler();
	}

	protected function tearDown(): void {
		foreach ( $this->cleanup as $dir ) {
			array_map( 'unlink', (array) glob( $dir . DIRECTORY_SEPARATOR . '*' ) );
			@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		foreach ( $this->saved_globals as $key => $value ) {
			if ( null === $value && ! in_array( $key, array( 'sscribe_test_actions', 'sscribe_test_filters' ), true ) ) {
				unset( $GLOBALS[ $key ] );
				continue;
			}
			$GLOBALS[ $key ] = $value ?? array();
		}
		unset(
			$GLOBALS['sscribe_test_options'][ \SScribe_Schedule_Store::OPTION ],
			$GLOBALS['sscribe_test_single_events'],
			$GLOBALS['sscribe_test_mail'],
			$GLOBALS['sscribe_test_users'],
			$GLOBALS['sscribe_test_user_caps']
		);
		parent::tearDown();
	}

	public function test_unknown_schedule_is_reported(): void {
		$outcome = $this->scheduler( new Fake_Schedule_Pipeline( self::started(), array() ) )->run( 'sch_000000000000' );

		$this::assertSame( 'schedule_not_found', $outcome->code() );
	}

	public function test_disabled_schedule_does_not_run_unless_forced(): void {
		$schedule = $this->add( array( 'enabled' => false ) );
		$pipeline = new Fake_Schedule_Pipeline( self::started(), array( self::complete() ) );

		$outcome = $this->scheduler( $pipeline )->run( $schedule->id );

		$this::assertSame( 'schedule_disabled', $outcome->code() );
		$this::assertNull( $pipeline->started_job );

		$forced = $this->scheduler( $pipeline )->run( $schedule->id, true );

		$this::assertTrue( $forced->is_success() );
	}

	public function test_running_schedule_is_not_started_twice(): void {
		$schedule = $this->add( array( 'running_session' => 'abc123' ) );
		$pipeline = new Fake_Schedule_Pipeline( self::started(), array() );

		$this::assertSame( 'schedule_running', $this->scheduler( $pipeline )->run( $schedule->id )->code() );
		$this::assertNull( $pipeline->started_job );
	}

	public function test_missing_owner_fails_the_run_and_keeps_the_schedule_going(): void {
		$schedule = $this->add( array( 'owner_user_id' => 99 ) );
		$pipeline = new Fake_Schedule_Pipeline( self::started(), array() );

		$outcome = $this->scheduler( $pipeline )->run( $schedule->id );
		$stored  = $this->store->get( $schedule->id );

		$this::assertSame( 'schedule_owner', $outcome->code() );
		$this::assertNull( $pipeline->started_job );
		$this::assertSame( 'failed', $stored?->last_run_status );
		$this::assertNotSame( '', $stored?->last_error );
		$this::assertGreaterThan( time(), $stored?->next_run_at );
		$this::assertTrue( $stored?->enabled );
	}

	public function test_owner_without_export_capability_fails_the_run(): void {
		$GLOBALS['sscribe_test_user_caps'] = array();
		$schedule                          = $this->add();

		$outcome = $this->scheduler( new Fake_Schedule_Pipeline( self::started(), array() ) )->run( $schedule->id );

		$this::assertSame( 'schedule_owner', $outcome->code() );
	}

	public function test_busy_owner_postpones_the_run_by_ten_minutes(): void {
		$schedule = $this->add( array( 'next_run_at' => time() - 5 ) );
		$pipeline = new Fake_Schedule_Pipeline( self::started(), array() );
		$before   = time();

		$outcome = $this->scheduler( $pipeline, static fn(): bool => true )->run( $schedule->id );
		$stored  = $this->store->get( $schedule->id );

		$this::assertSame( 'owner_busy', $outcome->code() );
		$this::assertNull( $pipeline->started_job );
		$this::assertGreaterThanOrEqual( $before + 600, $stored?->next_run_at );
		$this::assertSame( '', $stored?->last_run_status );
		$this::assertTrue( $stored?->enabled );
	}

	public function test_successful_run_tags_the_archive_and_moves_the_watermark(): void {
		$schedule  = $this->add(
			array(
				'notify'         => true,
				'retention_days' => 5,
			)
		);
		$pipeline  = new Fake_Schedule_Pipeline(
			self::started(),
			array(
				self::processing( 1 ),
				\SScribe_Export_Outcome::ok(
					array(
						'status'    => 'finalizing',
						'processed' => 2,
					)
				),
			),
			self::complete()
		);
		$completed = array();
		add_action(
			'sscribe_schedule_completed',
			static function ( \SScribe_Schedule $done ) use ( &$completed ): void {
				$completed[] = $done->id;
			}
		);
		$before = time();

		$outcome = $this->scheduler( $pipeline )->run( $schedule->id );
		$stored  = $this->store->get( $schedule->id );

		$this::assertTrue( $outcome->is_success() );
		$this::assertSame( self::OWNER, get_current_user_id() );
		$this::assertSame( self::ZIP, $this->zip->tagged[0]['file'] );
		$this::assertSame( $schedule->id, $this->zip->tagged[0]['meta']['schedule_id'] );
		$this::assertSame( 'Nightly', $this->zip->tagged[0]['meta']['schedule_label'] );
		$this::assertGreaterThanOrEqual( $before + 5 * DAY_IN_SECONDS, $this->zip->tagged[0]['meta']['retain_until'] );
		$this::assertSame( 'success', $stored?->last_run_status );
		$this::assertSame( self::ZIP, $stored?->last_run_file );
		$this::assertGreaterThanOrEqual( $before, $stored?->last_run_at );
		$this::assertLessThanOrEqual( time(), $stored?->last_run_at );
		$this::assertSame( '', $stored?->running_session );
		$this::assertFalse( $stored?->is_running() );
		$this::assertGreaterThan( time(), $stored?->next_run_at );
		$this::assertSame( array( $schedule->id ), $completed );
		$this::assertCount( 1, $GLOBALS['sscribe_test_mail'] );
		$this::assertSame( 'owner@example.test', $GLOBALS['sscribe_test_mail'][0]['to'] );
		$this::assertStringContainsString( 'finished', $GLOBALS['sscribe_test_mail'][0]['subject'] );
		$this::assertStringContainsString( self::ZIP, $GLOBALS['sscribe_test_mail'][0]['message'] );
		$this::assertStringContainsString( 'tab=history', $GLOBALS['sscribe_test_mail'][0]['message'] );
	}

	public function test_destinations_receive_the_archive_with_secrets_opened(): void {
		$archive_dir = $this->archive_dir();
		add_filter( 'sscribe_destinations', static fn( array $classes ): array => array_merge( $classes, array( Scheduler_Recording_Destination::class ) ) );
		Scheduler_Recording_Destination::$calls  = array();
		Scheduler_Recording_Destination::$result = \SScribe_Result::success( 'recorded://ok' );
		$schedule                                = $this->add(
			array(
				'notify'       => true,
				'destinations' => array(
					array(
						'id'       => 'recording',
						'settings' => array( 'token' => 'plain-token' ),
					),
				),
			)
		);
		$raw = (string) wp_json_encode( get_option( \SScribe_Schedule_Store::OPTION ) );

		$outcome = $this->scheduler( new Fake_Schedule_Pipeline( self::started(), array( self::complete() ) ) )->run( $schedule->id );
		$stored  = $this->store->get( $schedule->id );

		$this::assertStringNotContainsString( 'plain-token', $raw );
		$this::assertTrue( $outcome->is_success() );
		$this::assertCount( 1, Scheduler_Recording_Destination::$calls );
		$this::assertSame( 'plain-token', Scheduler_Recording_Destination::$calls[0]['settings']['token'] );
		$this::assertSame( realpath( $archive_dir . '/' . self::ZIP ), Scheduler_Recording_Destination::$calls[0]['path'] );
		$this::assertSame( $schedule->id, Scheduler_Recording_Destination::$calls[0]['context']['schedule_id'] );
		$this::assertSame( 'success', $stored?->last_run_status );
		$this::assertSame( '', $stored?->last_error );
		$this::assertSame( 'recording: delivered', $stored?->last_delivery );
		$this::assertStringContainsString( 'Delivered to recording', $GLOBALS['sscribe_test_mail'][0]['message'] );
	}

	public function test_run_stays_successful_when_every_destination_fails(): void {
		$this->archive_dir();
		add_filter( 'sscribe_destinations', static fn( array $classes ): array => array_merge( $classes, array( Scheduler_Recording_Destination::class ) ) );
		Scheduler_Recording_Destination::$calls  = array();
		Scheduler_Recording_Destination::$result = \SScribe_Result::failure( 'Bucket is gone.' );
		$schedule                                = $this->add(
			array(
				'notify'       => true,
				'destinations' => array( array( 'id' => 'recording' ) ),
			)
		);

		$this->scheduler( new Fake_Schedule_Pipeline( self::started(), array( self::complete() ) ) )->run( $schedule->id );
		$stored = $this->store->get( $schedule->id );

		$this::assertSame( 'success', $stored?->last_run_status );
		$this::assertSame( self::ZIP, $stored?->last_run_file );
		$this::assertStringContainsString( 'could not be delivered', (string) $stored?->last_error );
		$this::assertStringContainsString( 'Bucket is gone.', (string) $stored?->last_error );
		$this::assertSame( 'recording: failed (Bucket is gone.)', $stored?->last_delivery );
		$this::assertStringContainsString( 'Delivery to recording failed: Bucket is gone.', $GLOBALS['sscribe_test_mail'][0]['message'] );
	}

	public function test_missing_archive_is_reported_for_each_destination(): void {
		$this->zip->archive_dir = sys_get_temp_dir();
		add_filter( 'sscribe_destinations', static fn( array $classes ): array => array_merge( $classes, array( Scheduler_Recording_Destination::class ) ) );
		Scheduler_Recording_Destination::$calls = array();
		$schedule                               = $this->add( array( 'destinations' => array( array( 'id' => 'recording' ) ) ) );

		$this->scheduler( new Fake_Schedule_Pipeline( self::started(), array( self::complete() ) ) )->run( $schedule->id );
		$stored = $this->store->get( $schedule->id );

		$this::assertSame( array(), Scheduler_Recording_Destination::$calls );
		$this::assertSame( 'success', $stored?->last_run_status );
		$this::assertStringContainsString( 'could not be found', (string) $stored?->last_delivery );
	}

	public function test_notification_filter_can_suppress_the_email(): void {
		$schedule = $this->add( array( 'notify' => true ) );
		add_filter(
			'sscribe_schedule_notification',
			static fn( array $mail ): array => array_merge( $mail, array( 'to' => '' ) )
		);

		$this->scheduler( new Fake_Schedule_Pipeline( self::started(), array( self::complete() ) ) )->run( $schedule->id );

		$this::assertSame( array(), $GLOBALS['sscribe_test_mail'] );
	}

	public function test_incremental_schedule_passes_its_watermark_to_the_job(): void {
		$schedule = $this->add(
			array(
				'incremental' => true,
				'last_run_at' => 1700000000,
			)
		);
		$pipeline = new Fake_Schedule_Pipeline( self::started(), array( self::complete() ) );

		$this->scheduler( $pipeline )->run( $schedule->id );

		$this::assertSame( 1700000000, $pipeline->started_job?->modified_since );
		$this::assertSame( $schedule->id, $pipeline->started_job?->schedule_id );
	}

	public function test_incremental_run_without_changes_succeeds_without_an_archive(): void {
		$schedule = $this->add(
			array(
				'incremental' => true,
				'last_run_at' => 1700000000,
			)
		);
		$pipeline = new Fake_Schedule_Pipeline( \SScribe_Export_Outcome::fail( array( 'code' => 'no_pages_selected' ), 400 ), array() );

		$outcome = $this->scheduler( $pipeline )->run( $schedule->id );
		$stored  = $this->store->get( $schedule->id );

		$this::assertTrue( $outcome->is_success() );
		$this::assertSame( 0, $outcome->payload()['pages'] );
		$this::assertSame( 'success', $stored?->last_run_status );
		$this::assertSame( '', $stored?->last_run_file );
		$this::assertGreaterThan( 1700000000, $stored?->last_run_at );
		$this::assertSame( array(), $this->zip->tagged );
	}

	public function test_full_run_without_pages_is_a_failure(): void {
		$schedule = $this->add();
		$pipeline = new Fake_Schedule_Pipeline(
			\SScribe_Export_Outcome::fail(
				array(
					'code'    => 'no_pages_selected',
					'message' => 'No pages.',
				),
				400
			),
			array()
		);

		$outcome = $this->scheduler( $pipeline )->run( $schedule->id );

		$this::assertSame( 'no_pages_selected', $outcome->code() );
		$this::assertSame( 'failed', $this->store->get( $schedule->id )?->last_run_status );
	}

	public function test_deadline_pauses_the_run_and_queues_a_continuation(): void {
		$schedule = $this->add();
		$pipeline = new Fake_Schedule_Pipeline( self::started(), array( self::processing( 1 ), self::processing( 2 ) ) );

		$outcome = $this->scheduler( $pipeline, null, 0.000001 )->run( $schedule->id );
		$stored  = $this->store->get( $schedule->id );

		$this::assertTrue( $outcome->is_success() );
		$this::assertSame( 'paused', $outcome->payload()['status'] );
		$this::assertSame( 1, $pipeline->steps );
		$this::assertSame( 'running', $stored?->last_run_status );
		$this::assertSame( self::SID, $stored?->running_session );
		$this::assertCount( 1, $GLOBALS['sscribe_test_single_events'] );
		$this::assertSame( \SScribe_Scheduler::CONTINUE_HOOK, $GLOBALS['sscribe_test_single_events'][0]['hook'] );
		$this::assertSame( array( $schedule->id, self::SID ), $GLOBALS['sscribe_test_single_events'][0]['args'] );
		$this::assertGreaterThan( time(), $GLOBALS['sscribe_test_single_events'][0]['timestamp'] );
		$this::assertSame( array(), $this->store->due( time() + 365 * DAY_IN_SECONDS ) );
	}

	public function test_continuation_resumes_the_paused_session_and_completes(): void {
		$schedule = $this->add();
		$pipeline = new Fake_Schedule_Pipeline(
			self::started(),
			array( self::processing( 1 ), self::processing( 2 ), self::complete() )
		);
		$this->scheduler( $pipeline, null, 0.000001 )->run( $schedule->id );
		$started_at = $this->store->get( $schedule->id )?->run_started_at;
		wp_set_current_user( 0 );

		$outcome = $this->scheduler( $pipeline )->continue_run( $schedule->id, self::SID );
		$stored  = $this->store->get( $schedule->id );

		$this::assertTrue( $outcome->is_success() );
		$this::assertSame( self::OWNER, get_current_user_id() );
		$this::assertSame( 3, $pipeline->steps );
		$this::assertSame( 'success', $stored?->last_run_status );
		$this::assertSame( $started_at, $stored?->last_run_at );
		$this::assertSame( '', $stored?->running_session );
	}

	public function test_continuation_for_another_session_is_ignored(): void {
		$schedule = $this->add( array( 'running_session' => self::SID ) );
		$pipeline = new Fake_Schedule_Pipeline( self::started(), array() );

		$outcome = $this->scheduler( $pipeline )->continue_run( $schedule->id, 'ffffffffffffffff' );

		$this::assertSame( 'schedule_not_running', $outcome->code() );
		$this::assertSame( 0, $pipeline->steps );
		$this::assertSame( self::SID, $this->store->get( $schedule->id )?->running_session );
	}

	public function test_failed_step_records_the_error_without_paths_and_reschedules(): void {
		$schedule = $this->add( array( 'notify' => true ) );
		$failed   = array();
		add_action(
			'sscribe_schedule_failed',
			static function ( \SScribe_Schedule $done, string $code ) use ( &$failed ): void {
				$failed[] = $code;
			},
			10,
			2
		);
		$pipeline = new Fake_Schedule_Pipeline(
			self::started(),
			array(
				\SScribe_Export_Outcome::fail(
					array(
						'code'    => 'session_expired',
						'message' => 'Session file /var/www/wp-content/uploads/x.json is gone.',
					),
					404
				),
			)
		);

		$outcome = $this->scheduler( $pipeline )->run( $schedule->id );
		$stored  = $this->store->get( $schedule->id );

		$this::assertSame( 'session_expired', $outcome->code() );
		$this::assertSame( 'failed', $stored?->last_run_status );
		$this::assertStringNotContainsString( '/var/www', (string) $stored?->last_error );
		$this::assertStringContainsString( 'is gone', (string) $stored?->last_error );
		$this::assertSame( '', $stored?->running_session );
		$this::assertGreaterThan( time(), $stored?->next_run_at );
		$this::assertSame( array( 'session_expired' ), $failed );
		$this::assertStringContainsString( 'failed', $GLOBALS['sscribe_test_mail'][0]['subject'] );
		$this::assertStringNotContainsString( '/var/www', $GLOBALS['sscribe_test_mail'][0]['message'] );
	}

	public function test_retention_deletes_archives_that_are_too_old(): void {
		$schedule              = $this->add( array( 'retention_days' => 7 ) );
		$this->zip->for_schedule = array(
			self::row( self::ZIP, time() ),
			self::row( 'recent.zip', time() - DAY_IN_SECONDS ),
			self::row( 'old.zip', time() - 8 * DAY_IN_SECONDS ),
		);

		$this->scheduler( new Fake_Schedule_Pipeline( self::started(), array( self::complete() ) ) )->run( $schedule->id );

		$this::assertSame( array( array( 'old.zip', self::OWNER ) ), $this->zip->deleted );
	}

	public function test_retention_keeps_at_most_the_filtered_number_of_archives(): void {
		$schedule              = $this->add();
		$this->zip->for_schedule = array(
			self::row( self::ZIP, time() ),
			self::row( 'second.zip', time() - 60 ),
			self::row( 'third.zip', time() - 120 ),
		);
		add_filter( 'sscribe_schedule_max_archives', static fn(): int => 2 );

		$this->scheduler( new Fake_Schedule_Pipeline( self::started(), array( self::complete() ) ) )->run( $schedule->id );

		$this::assertSame( array( array( 'third.zip', self::OWNER ) ), $this->zip->deleted );
	}

	public function test_tick_runs_only_due_schedules(): void {
		$due      = $this->add( array( 'next_run_at' => time() - 60 ) );
		$this->add( array( 'next_run_at' => time() + HOUR_IN_SECONDS ) );
		$pipeline = new Fake_Schedule_Pipeline( self::started(), array( self::complete() ) );

		$outcomes = $this->scheduler( $pipeline )->tick();

		$this::assertSame( array( $due->id ), array_keys( $outcomes ) );
		$this::assertTrue( $outcomes[ $due->id ]->is_success() );
	}

	public function test_tick_abandons_runs_that_stalled(): void {
		$stalled = $this->add(
			array(
				'last_run_status' => 'running',
				'running_session' => self::SID,
				'run_started_at'  => time() - DAY_IN_SECONDS,
				'next_run_at'     => time() + HOUR_IN_SECONDS,
			)
		);

		$this->scheduler( new Fake_Schedule_Pipeline( self::started(), array() ) )->tick();
		$stored = $this->store->get( $stalled->id );

		$this::assertSame( 'failed', $stored?->last_run_status );
		$this::assertSame( '', $stored?->running_session );
	}

	public function test_ensure_scheduled_queues_the_hourly_tick_once(): void {
		$this::assertTrue( \SScribe_Scheduler::ensure_scheduled() );
		$first = $GLOBALS['sscribe_test_scheduled_events'][ \SScribe_Scheduler::TICK_HOOK ];

		$this::assertTrue( \SScribe_Scheduler::ensure_scheduled() );

		$this::assertSame( 'hourly', $first['recurrence'] );
		$this::assertSame( $first, $GLOBALS['sscribe_test_scheduled_events'][ \SScribe_Scheduler::TICK_HOOK ] );
	}

	public function test_unschedule_all_removes_tick_and_continuations(): void {
		\SScribe_Scheduler::ensure_scheduled();
		wp_schedule_single_event( time() + 30, \SScribe_Scheduler::CONTINUE_HOOK, array( 'sch_000000000000', self::SID ) );

		\SScribe_Scheduler::unschedule_all();

		$this::assertFalse( wp_next_scheduled( \SScribe_Scheduler::TICK_HOOK ) );
		$this::assertSame( array(), $GLOBALS['sscribe_test_single_events'] );
	}

	public function test_time_budget_is_unbounded_without_an_execution_limit_and_filterable(): void {
		$this::assertSame( '0', ini_get( 'max_execution_time' ) );
		$this::assertSame( 0, \SScribe_Scheduler::time_budget() );

		add_filter( 'sscribe_schedule_time_budget', static fn(): int => 20 );

		$this::assertSame( 20, \SScribe_Scheduler::time_budget() );
	}

	private function archive_dir(): string {
		$dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sscribe-sched-' . bin2hex( random_bytes( 4 ) );
		mkdir( $dir, 0700, true );
		file_put_contents( $dir . DIRECTORY_SEPARATOR . self::ZIP, 'zip bytes' );
		$this->zip->archive_dir = $dir;
		$this->cleanup[]        = $dir;

		return $dir;
	}

	/**
	 * @param array<string, mixed> $overrides Fields to change.
	 */
	private function add( array $overrides = array() ): \SScribe_Schedule {
		$schedule = \SScribe_Schedule::from_array(
			array_merge(
				array(
					'label'         => 'Nightly',
					'frequency'     => 'daily',
					'hour'          => 3,
					'formats'       => 'docx,markdown',
					'owner_user_id' => self::OWNER,
					'next_run_at'   => time() - 1,
				),
				$overrides
			)
		);
		$this::assertTrue( $this->store->save( $schedule ) );

		return $schedule;
	}

	private function scheduler( Fake_Schedule_Pipeline $pipeline, ?callable $busy = null, ?float $budget = 0.0 ): \SScribe_Scheduler {
		return new \SScribe_Scheduler(
			$this->store,
			$pipeline,
			$this->zip,
			$busy ?? static fn(): bool => false,
			$budget,
			0
		);
	}

	private static function started(): \SScribe_Export_Outcome {
		return \SScribe_Export_Outcome::ok(
			array(
				'session_id' => self::SID,
				'total'      => 3,
			)
		);
	}

	private static function processing( int $processed ): \SScribe_Export_Outcome {
		return \SScribe_Export_Outcome::ok(
			array(
				'status'    => 'processing',
				'processed' => $processed,
				'total'     => 3,
			)
		);
	}

	private static function complete(): \SScribe_Export_Outcome {
		return \SScribe_Export_Outcome::ok(
			array(
				'status'   => 'complete',
				'filename' => self::ZIP,
				'pages'    => 2,
				'errors'   => array(),
			)
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function row( string $basename, int $created_at ): array {
		return array(
			'basename'   => $basename,
			'created_at' => $created_at,
			'user_id'    => self::OWNER,
		);
	}
}

final class Fake_Schedule_Pipeline implements \SScribe_Export_Pipeline_Interface {

	public int $steps = 0;

	public ?\SScribe_Export_Job $started_job = null;

	/**
	 * @param list<\SScribe_Export_Outcome> $step_outcomes
	 */
	public function __construct(
		private readonly \SScribe_Export_Outcome $start,
		private array $step_outcomes,
		private readonly ?\SScribe_Export_Outcome $finalize = null
	) {
	}

	public function start_export_job( \SScribe_Export_Job $job, \SScribe_Export_Context_Interface $context ): \SScribe_Export_Outcome {
		$this->started_job = $job;
		return $this->start;
	}

	public function process_batch_step( string $session_id, \SScribe_Export_Context_Interface $context ): \SScribe_Export_Outcome {
		++$this->steps;
		$next = array_shift( $this->step_outcomes );
		if ( null === $next ) {
			throw new \LogicException( 'Script ran out of step outcomes.' );
		}
		return $next;
	}

	public function finalize_session( string $session_id, \SScribe_Export_Context_Interface $context ): \SScribe_Export_Outcome {
		return $this->finalize ?? \SScribe_Export_Outcome::fail( array( 'code' => 'unexpected_finalize' ), 500 );
	}
}

class Recording_Zip_Handler extends \SScribe_Zip_Handler {

	/** @var list<array{file: string, meta: array<string, mixed>}> */
	public array $tagged = array();

	/** @var list<array{0: string, 1: int}> */
	public array $deleted = array();

	/** @var list<array<string, mixed>> */
	public array $for_schedule = array();

	public string $archive_dir = '';

	public function get_export_dir(): string {
		return '' !== $this->archive_dir ? $this->archive_dir : parent::get_export_dir();
	}

	public function tag_export_row( string $zip_filename, array $meta ): bool {
		$this->tagged[] = array(
			'file' => $zip_filename,
			'meta' => $meta,
		);
		return true;
	}

	public function exports_for_schedule( string $schedule_id ): array {
		return $this->for_schedule;
	}

	public function delete_export( string $zip_filename, int $user_id ): bool {
		$this->deleted[] = array( $zip_filename, $user_id );
		return true;
	}
}

final class Scheduler_Recording_Destination implements \SScribe_Destination_Interface {

	/** @var list<array{path: string, context: array<string, mixed>, settings: array<string, mixed>}> */
	public static array $calls = array();

	public static ?\SScribe_Result $result = null;

	public static function id(): string {
		return 'recording';
	}

	public static function label(): string {
		return 'Recording';
	}

	public static function settings_schema(): array {
		return array(
			'token' => array(
				'type'     => 'password',
				'label'    => 'Token',
				'required' => false,
			),
		);
	}

	public function validate( array $settings ): \SScribe_Result {
		return \SScribe_Result::success( $settings );
	}

	public function deliver( string $zip_path, array $context, array $settings ): \SScribe_Result {
		self::$calls[] = array(
			'path'     => $zip_path,
			'context'  => $context,
			'settings' => $settings,
		);
		return self::$result ?? \SScribe_Result::success( 'recorded' );
	}
}
