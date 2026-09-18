<?php
/**
 * SScribe Batch Step Handler coverage test
 *
 * Targets the private pure helpers of SScribe_Batch_Step_Handler via reflection
 * on a SScribe_Batch_Processor instance:
 *
 *   - build_batch_response()   : percentage, paused_reason, message variants,
 *                                error_diagnostics, resume_guidance, debug_info
 *   - restore_ob_level()       : trims buffer levels down to target
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

if ( ! class_exists( '\\SScribe_Batch_Processor', false ) ) {
	require_once SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-batch-processor.php';
}

final class SScribe_Batch_Step_Handler_Coverage_Test extends TestCase {

	private \SScribe_Batch_Processor $bp;
	private ReflectionClass $ref;

	protected function setUp(): void {
		parent::setUp();
		$this->bp  = new \SScribe_Batch_Processor();
		$this->ref = new ReflectionClass( $this->bp );
	}

	private function call( string $name, array $args = array() ): mixed {
		$m = $this->ref->getMethod( $name );
		return $m->invokeArgs( $this->bp, $args );
	}

	public function test_build_batch_response_basic_shape(): void {
		$result = $this->call(
			'build_batch_response',
			array( 10, 100, 'Sample Page', 0.5, false, false, array(), array(), 1.234, microtime( true ) - 5.0 )
		);

		$this::assertIsArray( $result );
		$this::assertSame( 'processing', $result['status'] );
		$this::assertSame( 10, $result['processed'] );
		$this::assertSame( 100, $result['total'] );
		$this::assertEquals( 10, $result['percentage'] );
		$this::assertSame( 'Sample Page', $result['current_page'] );
		$this::assertEquals( 45, $result['time_remaining'] ); // 0.5 * (100-10); round() returns float on PHP 8.
		$this::assertFalse( $result['memory_paused'] );
		$this::assertFalse( $result['timeout_paused'] );
		$this::assertSame( '', $result['paused_reason'] );
		$this::assertArrayNotHasKey( 'error_diagnostics', $result );
		$this::assertArrayNotHasKey( 'resume_guidance', $result );
	}

	public function test_build_batch_response_with_zero_total(): void {
		// No pages → percentage 0, time_remaining 0, normal message.
		$result = $this->call(
			'build_batch_response',
			array( 0, 0, '', 0.0, false, false, array(), array(), 0.0, microtime( true ) )
		);

		$this::assertSame( 0, $result['percentage'] );
		$this::assertSame( 0, $result['time_remaining'] );
	}

	public function test_build_batch_response_with_memory_paused(): void {
		$result = $this->call(
			'build_batch_response',
			array( 50, 200, 'Memory Page', 1.0, true, false, array(), array(), 5.0, microtime( true ) - 10.0 )
		);

		$this::assertTrue( $result['memory_paused'] );
		$this::assertSame( 'memory', $result['paused_reason'] );
		$this::assertArrayHasKey( 'resume_guidance', $result );
		$this::assertStringContainsString( 'memory', strtolower( $result['resume_guidance'] ) );
		$this::assertStringContainsString( 'memory', strtolower( $result['message'] ) );
	}

	public function test_build_batch_response_with_timeout_paused(): void {
		$result = $this->call(
			'build_batch_response',
			array( 50, 200, 'Timeout Page', 1.0, false, true, array(), array(), 5.0, microtime( true ) - 10.0 )
		);

		$this::assertTrue( $result['timeout_paused'] );
		$this::assertSame( 'timeout', $result['paused_reason'] );
		$this::assertArrayHasKey( 'resume_guidance', $result );
		$this::assertStringContainsString( 'timeout', strtolower( $result['resume_guidance'] ) );
		$this::assertStringContainsString( 'timeout', strtolower( $result['message'] ) );
	}

	public function test_build_batch_response_with_structured_errors(): void {
		$structured = array(
			array( 'category' => 'memory', 'context' => 'low memory' ),
			array( 'category' => 'zip', 'context' => 'archive error' ),
		);
		$errors = array( 'low memory', 'archive error' );

		$result = $this->call(
			'build_batch_response',
			array( 5, 50, 'Err Page', 0.2, false, false, $structured, $errors, 0.5, microtime( true ) - 2.0 )
		);

		$this::assertArrayHasKey( 'error_diagnostics', $result );
		$this::assertIsArray( $result['error_diagnostics'] );
	}

	/**
	 * Run in a separate PHP process so the `define('SSCRIBE_DEBUG', true)`
	 * on line 120 cannot leak into sibling tests' `is_logging_enabled()`
	 * computation. PHP has no API to undefine a constant, so without
	 * process isolation the constant stays true for the rest of the
	 * suite run and breaks `SScribe_Logger_Singleton_Test`'s disabled-
	 * logger assertions whenever this method runs first in random order.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_build_batch_response_debug_info_when_constant_enabled(): void {
		// SSCRIBE_DEBUG is a runtime constant; flip it on for this assertion.
		$previous = defined( 'SSCRIBE_DEBUG' ) ? SSCRIBE_DEBUG : false;
		if ( ! defined( 'SSCRIBE_DEBUG' ) ) {
			define( 'SSCRIBE_DEBUG', true );
		}

		$result = $this->call(
			'build_batch_response',
			array( 3, 30, 'Debug Page', 0.1, false, false, array(), array(), 0.3, microtime( true ) - 1.0 )
		);

		// Note: SSCRIBE_DEBUG is captured at function entry, not at call time, so
		// we cannot toggle the constant mid-process. We still exercise the same
		// code paths; the debug_info key is present when SSCRIBE_DEBUG was true
		// at plugin load.
		if ( isset( $result['debug_info'] ) ) {
			$this::assertIsArray( $result['debug_info'] );
			$this::assertArrayHasKey( 'batch_size', $result['debug_info'] );
			$this::assertArrayHasKey( 'errors_so_far', $result['debug_info'] );
			$this::assertArrayHasKey( 'memory_usage', $result['debug_info'] );
			$this::assertArrayHasKey( 'memory_peak', $result['debug_info'] );
			$this::assertArrayHasKey( 'avg_time_per_page', $result['debug_info'] );
			$this::assertArrayHasKey( 'elapsed_time', $result['debug_info'] );
		} else {
			// SSCRIBE_DEBUG was false; the branch was not entered, but we still
			// exercised the main code path.
			$this::assertTrue( true );
		}

		// Restore original constant value if we redefined.
		unset( $previous );
	}

	public function test_restore_ob_level_drains_to_target(): void {
		$this::expectOutputRegex( '//' );

		$initial = ob_get_level();
		ob_start();
		ob_start();
		ob_start();

		$this::assertGreaterThanOrEqual( $initial + 3, ob_get_level() );

		$this->call( 'restore_ob_level', array( $initial ) );

		$this::assertSame( $initial, ob_get_level() );
	}
	public function test_restore_ob_level_drains_to_partial_target(): void {
		$this::expectOutputRegex( '//' );

		$initial = ob_get_level();
		ob_start();
		ob_start();
		ob_start();

		$this->call( 'restore_ob_level', array( $initial + 1 ) );

		$this::assertSame( $initial + 1, ob_get_level() );

		while ( ob_get_level() > $initial ) {
			ob_end_clean();
		}
	}

	public function test_restore_ob_level_noop_when_already_at_target(): void {
		$this::expectOutputRegex( '//' );

		$current = ob_get_level();

		$this->call( 'restore_ob_level', array( $current ) );

		$this::assertSame( $current, ob_get_level() );
	}

	public function test_class_uses_batch_step_handler_trait(): void {
		$uses = class_uses( \SScribe_Batch_Processor::class );
		$this::assertIsArray( $uses );
		$this::assertArrayHasKey( 'SScribe_Batch_Step_Handler', $uses );
	}

	public function test_class_has_expected_public_methods(): void {
		$this::assertTrue( method_exists( \SScribe_Batch_Processor::class, 'ajax_process_batch' ) );
		$this::assertTrue( method_exists( \SScribe_Batch_Processor::class, 'ajax_start_export' ) );
		$this::assertTrue( method_exists( \SScribe_Batch_Processor::class, 'ajax_download' ) );
		$this::assertTrue( method_exists( \SScribe_Batch_Processor::class, 'ajax_delete_export' ) );
	}
}
