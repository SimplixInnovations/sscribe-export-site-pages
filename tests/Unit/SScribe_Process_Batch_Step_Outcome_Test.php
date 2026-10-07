<?php
/**
 * Unit test for the outcome-returning batch step and finalize cores.
 *
 * process_batch_step() and finalize_session() must hand back an
 * SScribe_Export_Outcome instead of exiting through the AJAX guard, so a
 * headless caller can drive them. The wp_send_json_* test stubs throw a
 * RuntimeException, which makes any accidental emission visible here.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

if ( ! trait_exists( '\\SScribe_Batch_Step_Handler', false ) ) {
	require_once SSCRIBE_PLUGIN_DIR . 'includes/traits/trait-sscribe-batch-step-handler.php';
}

if ( ! trait_exists( '\\SScribe_Export_Finalizer', false ) ) {
	require_once SSCRIBE_PLUGIN_DIR . 'includes/traits/trait-sscribe-export-finalizer.php';
}

final class SScribe_Process_Batch_Step_Outcome_Test extends TestCase {

	private int $original_max_execution_time = 0;

	protected function setUp(): void {
		parent::setUp();
		$this->original_max_execution_time = (int) ini_get( 'max_execution_time' );
	}

	protected function tearDown(): void {
		set_time_limit( $this->original_max_execution_time );
		parent::tearDown();
	}

	public function test_step_returns_invalid_session_id_outcome_without_emitting(): void {
		$host  = new Outcome_Step_Host();
		$level = ob_get_level();

		$outcome = $host->process_batch_step( 'not-a-valid-id', new \SScribe_Headless_Export_Context( 1 ) );

		$this::assertSame( 'error', $outcome->kind() );
		$this::assertSame( 400, $outcome->http_status() );
		$this::assertSame( 'invalid_session_id', $outcome->code() );
		$this::assertSame( $level, ob_get_level() );
		$this::assertSame( array(), $host->session->requested );
	}

	public function test_step_returns_session_expired_outcome_for_unknown_id(): void {
		$host = new Outcome_Step_Host();

		$outcome = $host->process_batch_step( 'a1b2c3d4e5f60718', new \SScribe_Headless_Export_Context( 1 ) );

		$this::assertSame( 'error', $outcome->kind() );
		$this::assertSame( 404, $outcome->http_status() );
		$this::assertSame( 'session_expired', $outcome->code() );
		$this::assertSame( array( 'a1b2c3d4e5f60718' ), $host->session->requested );
	}

	public function test_step_does_not_report_progress_for_errors(): void {
		$host    = new Outcome_Step_Host();
		$reports = array();
		$context = new \SScribe_Headless_Export_Context(
			1,
			static function ( array $payload ) use ( &$reports ): void {
				$reports[] = $payload;
			}
		);

		$host->process_batch_step( 'a1b2c3d4e5f60718', $context );

		$this::assertSame( array(), $reports );
	}

	public function test_finalize_returns_invalid_session_id_outcome_for_empty_id(): void {
		$host = new Outcome_Finalize_Host();

		$outcome = $host->finalize_session( '', new \SScribe_Headless_Export_Context( 1 ) );

		$this::assertSame( 'error', $outcome->kind() );
		$this::assertSame( 400, $outcome->http_status() );
		$this::assertSame( 'invalid_session_id', $outcome->code() );
		$this::assertSame( array(), $host->session->requested );
	}

	public function test_finalize_returns_session_expired_outcome_for_unknown_id(): void {
		$host = new Outcome_Finalize_Host();

		$outcome = $host->finalize_session( 'a1b2c3d4e5f60718', new \SScribe_Headless_Export_Context( 1 ) );

		$this::assertSame( 'error', $outcome->kind() );
		$this::assertSame( 404, $outcome->http_status() );
		$this::assertSame( 'session_expired', $outcome->code() );
		$this::assertSame( array( 'a1b2c3d4e5f60718' ), $host->session->requested );
	}
}

/**
 * Session store that never finds a session.
 */
class Outcome_Session_Stub {

	/** @var array<int, string> */
	public array $requested = array();

	public function get( string $session_id ): ?array {
		$this->requested[] = $session_id;
		return null;
	}
}

/**
 * Logger that drops everything.
 */
class Outcome_Logger_Stub {

	public function debug( string $message, array $context = array() ): void {
		unset( $message, $context );
	}
}

/**
 * Diagnostics stub for the self-heal call at the top of the step.
 */
class Outcome_Diagnostics_Stub {

	public function self_heal(): void {
	}
}

/**
 * Minimal host for the batch step handler trait.
 */
class Outcome_Step_Host {

	use \SScribe_Batch_Step_Handler;

	public Outcome_Session_Stub $session;
	public Outcome_Logger_Stub $logger;

	public function __construct() {
		$this->session = new Outcome_Session_Stub();
		$this->logger  = new Outcome_Logger_Stub();
	}

	private function get_diagnostics(): Outcome_Diagnostics_Stub {
		return new Outcome_Diagnostics_Stub();
	}
}

/**
 * Minimal host for the export finalizer trait.
 */
class Outcome_Finalize_Host {

	use \SScribe_Export_Finalizer;

	public Outcome_Session_Stub $session;
	public Outcome_Logger_Stub $logger;

	public function __construct() {
		$this->session = new Outcome_Session_Stub();
		$this->logger  = new Outcome_Logger_Stub();
	}
}
