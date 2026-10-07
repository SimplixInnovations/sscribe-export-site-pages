<?php
/**
 * Unit tests for SScribe_Export_Runner.
 *
 * A scripted pipeline stands in for the batch processor so every branch of
 * the run loop can be reached without touching the database or filesystem.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SScribe_Export_Runner_Test extends TestCase {

	private const SID = 'a1b2c3d4e5f60718';

	public function test_start_failure_is_returned_without_stepping(): void {
		$start    = \SScribe_Export_Outcome::fail( array( 'code' => 'no_pages_selected' ), 400 );
		$pipeline = new Scripted_Export_Pipeline( $start, array() );
		$runner   = new \SScribe_Export_Runner( $pipeline, 100, 0 );

		$outcome = $runner->run( new \SScribe_Export_Job(), self::context() );

		$this::assertSame( $start, $outcome );
		$this::assertSame( 0, $pipeline->steps );
		$this::assertSame( '', $runner->last_session_id() );
	}

	public function test_processing_then_finalizing_calls_finalize(): void {
		$complete = \SScribe_Export_Outcome::ok( array( 'status' => 'complete', 'filename' => 'x.zip' ) );
		$pipeline = new Scripted_Export_Pipeline(
			self::started(),
			array(
				self::processing( 2 ),
				self::processing( 4 ),
				\SScribe_Export_Outcome::ok( array( 'status' => 'finalizing', 'processed' => 4 ) ),
			),
			$complete
		);
		$runner   = new \SScribe_Export_Runner( $pipeline, 100, 0 );

		$outcome = $runner->run( new \SScribe_Export_Job(), self::context() );

		$this::assertSame( $complete, $outcome );
		$this::assertSame( 3, $pipeline->steps );
		$this::assertSame( array( self::SID ), $pipeline->finalized );
		$this::assertSame( self::SID, $runner->last_session_id() );
	}

	public function test_complete_from_a_step_is_returned_without_finalize(): void {
		$complete = \SScribe_Export_Outcome::ok( array( 'status' => 'complete' ) );
		$pipeline = new Scripted_Export_Pipeline( self::started(), array( self::processing( 1 ), $complete ) );
		$runner   = new \SScribe_Export_Runner( $pipeline, 100, 0 );

		$outcome = $runner->run( new \SScribe_Export_Job(), self::context() );

		$this::assertSame( $complete, $outcome );
		$this::assertSame( array(), $pipeline->finalized );
	}

	public function test_cancelled_mid_batch_becomes_a_cancelled_failure(): void {
		$pipeline = new Scripted_Export_Pipeline(
			self::started(),
			array(
				\SScribe_Export_Outcome::ok(
					array(
						'status'    => 'processing',
						'processed' => 1,
						'cancelled' => true,
					)
				),
			)
		);
		$runner   = new \SScribe_Export_Runner( $pipeline, 100, 0 );

		$outcome = $runner->run( new \SScribe_Export_Job(), self::context() );

		$this::assertFalse( $outcome->is_success() );
		$this::assertSame( 'cancelled', $outcome->code() );
		$this::assertSame( 200, $outcome->http_status() );
		$this::assertTrue( $outcome->payload()['cancelled'] );
	}

	public function test_step_failure_is_returned(): void {
		$failure  = \SScribe_Export_Outcome::fail( array( 'code' => 'session_expired' ), 404 );
		$pipeline = new Scripted_Export_Pipeline( self::started(), array( $failure ) );
		$runner   = new \SScribe_Export_Runner( $pipeline, 100, 0 );

		$this::assertSame( $failure, $runner->run( new \SScribe_Export_Job(), self::context() ) );
	}

	public function test_three_steps_without_progress_report_a_stall(): void {
		$pipeline = new Scripted_Export_Pipeline(
			self::started(),
			array(
				self::processing( 2 ),
				self::processing( 2 ),
				self::processing( 2 ),
				self::processing( 2 ),
			)
		);
		$runner   = new \SScribe_Export_Runner( $pipeline, 100, 0 );

		$outcome = $runner->run( new \SScribe_Export_Job(), self::context() );

		$this::assertSame( 'stalled', $outcome->code() );
		$this::assertSame( 500, $outcome->http_status() );
		$this::assertSame( 4, $pipeline->steps );
	}

	public function test_progress_resets_the_stall_counter(): void {
		$complete = \SScribe_Export_Outcome::ok( array( 'status' => 'complete' ) );
		$pipeline = new Scripted_Export_Pipeline(
			self::started(),
			array(
				self::processing( 0 ),
				self::processing( 0 ),
				self::processing( 3 ),
				self::processing( 3 ),
				self::processing( 3 ),
				$complete,
			)
		);
		$runner   = new \SScribe_Export_Runner( $pipeline, 100, 0 );

		$this::assertSame( $complete, $runner->run( new \SScribe_Export_Job(), self::context() ) );
	}

	public function test_lock_conflict_is_retried_then_succeeds(): void {
		$complete = \SScribe_Export_Outcome::ok( array( 'status' => 'complete' ) );
		$pipeline = new Scripted_Export_Pipeline(
			self::started(),
			array(
				\SScribe_Export_Outcome::lock_conflict( self::SID ),
				\SScribe_Export_Outcome::lock_conflict( self::SID ),
				$complete,
			)
		);
		$runner   = new \SScribe_Export_Runner( $pipeline, 100, 0, 5 );

		$this::assertSame( $complete, $runner->run( new \SScribe_Export_Job(), self::context() ) );
		$this::assertSame( 3, $pipeline->steps );
	}

	public function test_lock_conflict_beyond_the_retry_budget_is_returned(): void {
		$conflict = \SScribe_Export_Outcome::lock_conflict( self::SID );
		$pipeline = new Scripted_Export_Pipeline( self::started(), array_fill( 0, 10, $conflict ) );
		$runner   = new \SScribe_Export_Runner( $pipeline, 100, 0, 2 );

		$outcome = $runner->run( new \SScribe_Export_Job(), self::context() );

		$this::assertSame( \SScribe_Export_Outcome::KIND_LOCK_CONFLICT, $outcome->kind() );
		$this::assertSame( 3, $pipeline->steps );
	}

	public function test_step_limit_stops_a_run_that_never_finishes(): void {
		$steps = array();
		for ( $i = 1; $i <= 10; $i++ ) {
			$steps[] = self::processing( $i );
		}
		$pipeline = new Scripted_Export_Pipeline( self::started(), $steps );
		$runner   = new \SScribe_Export_Runner( $pipeline, 4, 0 );

		$outcome = $runner->run( new \SScribe_Export_Job(), self::context() );

		$this::assertSame( 'step_limit', $outcome->code() );
		$this::assertSame( 4, $pipeline->steps );
	}

	public function test_job_and_context_are_passed_to_start(): void {
		$pipeline = new Scripted_Export_Pipeline( \SScribe_Export_Outcome::fail( array(), 400 ), array() );
		$runner   = new \SScribe_Export_Runner( $pipeline, 100, 0 );
		$job      = new \SScribe_Export_Job( '', 'publish', 'page', array( 'docx' ) );
		$context  = self::context();

		$runner->run( $job, $context );

		$this::assertSame( $job, $pipeline->started_job );
		$this::assertSame( $context, $pipeline->started_context );
	}

	public function test_passed_deadline_pauses_after_one_step(): void {
		$pipeline = new Scripted_Export_Pipeline( self::started(), array( self::processing( 2 ), self::processing( 4 ) ) );
		$runner   = new \SScribe_Export_Runner( $pipeline, 100, 0 );

		$outcome = $runner->advance( self::SID, self::context(), microtime( true ) - 1 );

		$this::assertTrue( $outcome->is_success() );
		$this::assertSame( 'paused', $outcome->payload()['status'] );
		$this::assertSame( self::SID, $outcome->payload()['session_id'] );
		$this::assertSame( 2, $outcome->payload()['processed'] );
		$this::assertSame( 1, $pipeline->steps );
		$this::assertSame( self::SID, $runner->last_session_id() );
	}

	public function test_paused_session_can_be_advanced_to_completion(): void {
		$complete = \SScribe_Export_Outcome::ok( array( 'status' => 'complete' ) );
		$pipeline = new Scripted_Export_Pipeline( self::started(), array( self::processing( 2 ), self::processing( 4 ), $complete ) );
		$runner   = new \SScribe_Export_Runner( $pipeline, 100, 0 );

		$runner->advance( self::SID, self::context(), microtime( true ) - 1 );
		$outcome = $runner->advance( self::SID, self::context() );

		$this::assertSame( $complete, $outcome );
		$this::assertSame( 3, $pipeline->steps );
	}

	public function test_future_deadline_does_not_interrupt_a_short_run(): void {
		$complete = \SScribe_Export_Outcome::ok( array( 'status' => 'complete' ) );
		$pipeline = new Scripted_Export_Pipeline( self::started(), array( self::processing( 1 ), self::processing( 2 ), $complete ) );
		$runner   = new \SScribe_Export_Runner( $pipeline, 100, 0 );

		$this::assertSame( $complete, $runner->advance( self::SID, self::context(), microtime( true ) + 3600 ) );
	}

	public function test_deadline_is_not_checked_when_the_first_step_finishes_the_export(): void {
		$complete = \SScribe_Export_Outcome::ok( array( 'status' => 'complete' ) );
		$pipeline = new Scripted_Export_Pipeline( self::started(), array( $complete ) );
		$runner   = new \SScribe_Export_Runner( $pipeline, 100, 0 );

		$this::assertSame( $complete, $runner->advance( self::SID, self::context(), microtime( true ) - 1 ) );
	}

	public function test_run_never_pauses(): void {
		$complete = \SScribe_Export_Outcome::ok( array( 'status' => 'complete' ) );
		$steps    = array();
		for ( $i = 1; $i <= 5; $i++ ) {
			$steps[] = self::processing( $i );
		}
		$steps[]  = $complete;
		$pipeline = new Scripted_Export_Pipeline( self::started(), $steps );

		$this::assertSame( $complete, ( new \SScribe_Export_Runner( $pipeline, 100, 0 ) )->run( new \SScribe_Export_Job(), self::context() ) );
		$this::assertSame( 6, $pipeline->steps );
	}

	private static function context(): \SScribe_Headless_Export_Context {
		return new \SScribe_Headless_Export_Context( 1 );
	}

	private static function started(): \SScribe_Export_Outcome {
		return \SScribe_Export_Outcome::ok(
			array(
				'session_id' => self::SID,
				'total'      => 10,
			)
		);
	}

	private static function processing( int $processed ): \SScribe_Export_Outcome {
		return \SScribe_Export_Outcome::ok(
			array(
				'status'    => 'processing',
				'processed' => $processed,
				'total'     => 10,
			)
		);
	}
}

final class Scripted_Export_Pipeline implements \SScribe_Export_Pipeline_Interface {

	public int $steps = 0;

	/** @var list<string> */
	public array $finalized = array();

	public ?\SScribe_Export_Job $started_job = null;

	public ?\SScribe_Export_Context_Interface $started_context = null;

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
		$this->started_job     = $job;
		$this->started_context = $context;
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
		$this->finalized[] = $session_id;
		return $this->finalize ?? \SScribe_Export_Outcome::fail( array( 'code' => 'unexpected_finalize' ), 500 );
	}
}
