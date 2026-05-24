<?php
/**
 * SScribe AJAX Guard Unit Test
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

class SScribe_AJAX_Guard_Test extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$_POST['action'] = 'sscribe_test_action';
	}

	protected function tearDown(): void {
		unset( $_POST['action'] );
		parent::tearDown();
	}

	public function test_success_sends_json_and_throws(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'AJAX success response sent' );
		\SScribe_AJAX_Guard::success( array( 'foo' => 'bar' ) );
	}

	public function test_success_sends_with_no_data(): void {
		$this->expectException( \RuntimeException::class );
		\SScribe_AJAX_Guard::success();
	}

	public function test_error_sends_json_and_throws(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'AJAX error response sent' );
		\SScribe_AJAX_Guard::error( array( 'message' => 'test error' ), 400 );
	}

	public function test_error_with_string_data(): void {
		$this->expectException( \RuntimeException::class );
		\SScribe_AJAX_Guard::error( 'Simple error message' );
	}

	public function test_error_includes_diagnostics(): void {
		try {
			ob_start();
			\SScribe_AJAX_Guard::error( array( 'message' => 'fail' ), 403, array( 'ctx' => 'val' ) );
		} catch ( \RuntimeException $e ) {
			$output = ob_get_clean();
			$expected = '{"success":false,"data":{"message":"fail","_diagnostics":{"php_version":"';
			$this->assertStringContainsString( $expected, $output );
		}
	}

	public function test_success_with_status_code(): void {
		$this->expectException( \RuntimeException::class );
		\SScribe_AJAX_Guard::success( 'ok', 201 );
	}
}
