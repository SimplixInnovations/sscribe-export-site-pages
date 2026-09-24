<?php
/**
 * SScribe Batch Step Handler Trait unit test
 *
 * Covers the two private helpers that drive every AJAX response:
 * build_batch_response() (10-arg, percentage/pause/message/error/
 * resume-guidance/debug-info assembly) and restore_ob_level()
 * (the output-buffer trap used by ajax_process_batch() to clean up
 * any stray content the exporters may have written).
 *
 * The huge ajax_process_batch() entrypoint itself is intentionally
 * NOT tested here — its many collaborators (session, lock, rate
 * limiter, page collector, exporter, diagnostics) require the
 * integration harness, not a unit stub. We exercise the helpers
 * directly so a future refactor of the response shape or the
 * buffer-restoration guard is caught immediately.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

if ( ! trait_exists( '\\SScribe_Batch_Step_Handler', false ) ) {
	require_once SSCRIBE_PLUGIN_DIR . 'includes/traits/trait-sscribe-batch-step-handler.php';
}

final class SScribe_Batch_Step_Handler_Trait_Test extends TestCase {

	private \ReflectionClass $ref;

	protected function setUp(): void {
		parent::setUp();
		$this->ref = new \ReflectionClass( SScribe_Batch_Step_Handler_Stub::class );
	}

	private function call( string $method, ...$args ) {
		$m = $this->ref->getMethod( $method );
		return $m->invoke( $this->new_stub(), ...$args );
	}

	private function new_stub(): SScribe_Batch_Step_Handler_Stub {
		return new SScribe_Batch_Step_Handler_Stub();
	}

	// ==================================================================
	// build_batch_response() — happy path + paused + structured errors
	// + debug-info branches.
	// ==================================================================

	public function test_build_batch_response_returns_processing_status_with_percentage(): void {
		$response = $this->call(
			'build_batch_response',
			5,
			20,
			'My Page',
			0.5,
			false,
			false,
			array(),
			array(),
			2.5,
			microtime( true ) - 5.0
		);

		$this::assertSame( 'processing', $response['status'] );
		$this::assertSame( 5, $response['processed'] );
		$this::assertSame( 20, $response['total'] );
		$this::assertEquals( 25, $response['percentage'] );
		$this::assertSame( 'My Page', $response['current_page'] );
		$this::assertEquals( 8, $response['time_remaining'] ); // 0.5 * 15.
		$this::assertFalse( $response['memory_paused'] );
		$this::assertFalse( $response['timeout_paused'] );
		$this::assertSame( '', $response['paused_reason'] );
	}

	public function test_build_batch_response_clamps_percentage_when_no_progress(): void {
		$response = $this->call(
			'build_batch_response',
			0,
			10,
			'',
			1.0,
			false,
			false,
			array(),
			array(),
			0.0,
			microtime( true )
		);

		$this::assertEquals( 0, $response['percentage'] );
		$this::assertEquals( 10, $response['time_remaining'] );
	}

	public function test_build_batch_response_sets_memory_pause_branch(): void {
		$response = $this->call(
			'build_batch_response',
			3,
			10,
			'Page',
			1.0,
			true,
			false,
			array(),
			array(),
			1.0,
			microtime( true )
		);

		$this::assertSame( 'memory', $response['paused_reason'] );
		$this::assertTrue( $response['memory_paused'] );
		$this::assertStringContainsString( 'memory', $response['message'] );
		$this::assertArrayHasKey( 'resume_guidance', $response );
		$this::assertStringContainsString( 'memory', $response['resume_guidance'] );
	}

	public function test_build_batch_response_sets_timeout_pause_branch(): void {
		$response = $this->call(
			'build_batch_response',
			3,
			10,
			'Page',
			1.0,
			false,
			true,
			array(),
			array(),
			1.0,
			microtime( true )
		);

		$this::assertSame( 'timeout', $response['paused_reason'] );
		$this::assertStringContainsString( 'timeout', $response['message'] );
		$this::assertArrayHasKey( 'resume_guidance', $response );
		$this::assertStringContainsString( 'timeout', $response['resume_guidance'] );
	}

	public function test_build_batch_response_includes_structured_errors_diagnostics(): void {
		$structured = array(
			array(
				'code'    => 'X',
				'message' => 'y',
				'page'    => 'P',
			),
		);

		$response = $this->call(
			'build_batch_response',
			2,
			10,
			'',
			1.0,
			false,
			false,
			$structured,
			array( 'stringified error' ),
			1.0,
			microtime( true )
		);

		$this::assertArrayHasKey( 'error_diagnostics', $response );
		$this::assertIsArray( $response['error_diagnostics'] );
	}

	public function test_build_batch_response_skips_debug_info_when_sscribe_debug_false(): void {
		if ( defined( 'SSCRIBE_DEBUG' ) && SSCRIBE_DEBUG ) {
			$this::markTestSkipped( 'SSCRIBE_DEBUG is enabled in this environment.' );
		}
		$response = $this->call(
			'build_batch_response',
			1,
			2,
			'',
			1.0,
			false,
			false,
			array(),
			array(),
			1.0,
			microtime( true )
		);

		$this::assertArrayNotHasKey( 'debug_info', $response );
	}

	// ==================================================================
	// restore_ob_level() — output-buffer cleanup branch.
	// ==================================================================

	public function test_restore_ob_level_closes_extra_buffers(): void {
		// PHPUnit detects stray ob_start/ob_end_clean in test bodies and
		// marks the test risky. Instead, exercise the method via a stub
		// wrapper that opens/closes its own buffer pair so the assertion
		// stays inside the test's responsibility.
		$start_level = ob_get_level();
		ob_start();
		ob_start();
		$this::assertGreaterThanOrEqual( $start_level + 2, ob_get_level() );

		$this->call( 'restore_ob_level', $start_level );

		// Method should have drained every level above the target.
		$this::assertLessThanOrEqual( $start_level, ob_get_level() );
		// Drain anything the method left behind so PHPUnit sees a clean
		// teardown.
		while ( ob_get_level() > $start_level ) {
			ob_end_clean();
		}
	}


	public function test_ajax_batch_primes_child_metadata_for_the_session_post_type(): void {
		$source = (string) file_get_contents(
			SSCRIBE_PLUGIN_DIR . 'includes/traits/trait-sscribe-batch-step-handler.php'
		);

		$this::assertStringContainsString(
			"\$post_type         = isset( \$session['post_type'] )",
			$source,
			'Batch processing must resolve the selected post type from the persisted export session.'
		);
		$this::assertStringContainsString(
			"\$this->collector->get_child_pages_batch( \$batch, \$post_type );",
			$source,
			'Child metadata priming must use the session post type instead of the get_child_pages_batch() page default.'
		);
	}

	public function test_restore_ob_level_is_noop_when_at_target(): void {
		$start_level = ob_get_level();
		$this->call( 'restore_ob_level', $start_level );
		$this::assertSame( $start_level, ob_get_level() );
	}
}

/**
 * Minimal stub for the trait test. The trait calls
 * $this->build_error_diagnostics_payload() and reads $this->batch_size
 * — the stub provides both so build_batch_response() can run in
 * isolation. The huge ajax_process_batch() entrypoint is not tested
 * here because it requires session/lock/exporter collaborators.
 */
class SScribe_Batch_Step_Handler_Stub {
	use \SScribe_Batch_Step_Handler;

	public int $batch_size = 5;

	public function build_error_diagnostics_payload( array $structured_errors, array $string_errors = array() ): array {
		return array(
			'count' => count( $structured_errors ),
			'string_count' => count( $string_errors ),
			'first_structured' => $structured_errors[0] ?? null,
		);
	}
}
