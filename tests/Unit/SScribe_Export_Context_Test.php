<?php
/**
 * SScribe export context unit test
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SScribe_Export_Context_Test extends TestCase {

	private int $original_max_execution_time = 0;

	protected function setUp(): void {
		parent::setUp();
		$this->original_max_execution_time = (int) ini_get( 'max_execution_time' );
	}

	protected function tearDown(): void {
		set_time_limit( $this->original_max_execution_time );
		$GLOBALS['sscribe_test_current_user_id'] = null;
		parent::tearDown();
	}

	public function test_headless_context_reports_progress_through_callable(): void {
		$seen    = array();
		$context = new \SScribe_Headless_Export_Context(
			7,
			static function ( array $payload ) use ( &$seen ): void {
				$seen[] = $payload;
			}
		);

		$context->report_progress( array( 'processed' => 3 ) );
		$context->report_progress( array( 'processed' => 5 ) );

		$this::assertSame( array( array( 'processed' => 3 ), array( 'processed' => 5 ) ), $seen );
	}

	public function test_headless_context_without_callable_ignores_progress(): void {
		$context = new \SScribe_Headless_Export_Context( 3 );

		$this->expectOutputString( '' );
		$context->report_progress( array( 'processed' => 1 ) );
	}

	public function test_headless_context_is_not_interactive_and_keeps_owner(): void {
		$context = new \SScribe_Headless_Export_Context( 42 );

		$this::assertInstanceOf( \SScribe_Export_Context_Interface::class, $context );
		$this::assertFalse( $context->is_interactive() );
		$this::assertSame( 42, $context->owner_user_id() );
	}

	public function test_headless_prepare_long_request_removes_time_limit(): void {
		$context = new \SScribe_Headless_Export_Context( 1 );

		$context->prepare_long_request( 150 );
		$context->prepare_long_request( 300 );

		$this::assertSame( '0', (string) ini_get( 'max_execution_time' ) );
	}

	public function test_ajax_context_is_interactive_and_uses_current_user(): void {
		$GLOBALS['sscribe_test_current_user_id'] = 9;
		$context                                 = new \SScribe_Ajax_Export_Context();

		$this::assertInstanceOf( \SScribe_Export_Context_Interface::class, $context );
		$this::assertTrue( $context->is_interactive() );
		$this::assertSame( 9, $context->owner_user_id() );
	}

	public function test_ajax_context_progress_is_a_no_op(): void {
		$context = new \SScribe_Ajax_Export_Context();

		$this->expectOutputString( '' );
		$context->report_progress( array( 'processed' => 1 ) );
	}

	public function test_ajax_prepare_long_request_applies_requested_seconds(): void {
		$previous_abort = (bool) ignore_user_abort();
		$context        = new \SScribe_Ajax_Export_Context();

		try {
			$context->prepare_long_request( 150 );
			$this::assertSame( '150', (string) ini_get( 'max_execution_time' ) );
			$this::assertSame( 1, ignore_user_abort() );

			$context->prepare_long_request( 300 );
			$this::assertSame( '300', (string) ini_get( 'max_execution_time' ) );
		} finally {
			ignore_user_abort( $previous_abort );
		}
	}
}
