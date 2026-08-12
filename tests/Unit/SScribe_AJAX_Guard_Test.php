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
		$_POST = array( 'action' => 'sscribe_test_action' );
		$_GET  = array();
	}

	protected function tearDown(): void {
		$_POST = array();
		$_GET  = array();
		parent::tearDown();
	}

	public function test_text_helpers_reject_non_scalar_values_and_bound_output(): void {
		$_POST['name'] = array( 'unexpected' );
		$_GET['name']  = '<b>abcdef</b>';

		$this->assertSame( 'fallback', \SScribe_AJAX_Guard::post_text( 'name', 'fallback', 20 ) );
		$this->assertSame( 'abc', \SScribe_AJAX_Guard::get_text( 'name', '', 3 ) );
	}

	public function test_integer_helper_validates_defaults_and_bounds(): void {
		$_POST['valid']   = '250';
		$_POST['invalid'] = array( '250' );

		$this->assertSame( 100, \SScribe_AJAX_Guard::post_integer( 'valid', 10, 1, 100 ) );
		$this->assertSame( 10, \SScribe_AJAX_Guard::post_integer( 'invalid', 10, 1, 100 ) );
		$this->assertSame( 1, \SScribe_AJAX_Guard::post_integer( 'missing', -5, 1, 100 ) );
	}

	public function test_boolean_helper_rejects_arrays_and_invalid_tokens(): void {
		$_POST['enabled'] = 'true';
		$_POST['array']   = array( 'true' );
		$_POST['invalid'] = 'sometimes';

		$this->assertTrue( \SScribe_AJAX_Guard::post_boolean( 'enabled' ) );
		$this->assertFalse( \SScribe_AJAX_Guard::post_boolean( 'array' ) );
		$this->assertTrue( \SScribe_AJAX_Guard::post_boolean( 'invalid', true ) );
	}

	public function test_array_helper_rejects_scalars_and_limits_items(): void {
		$_POST['items']  = array( 'one', 'two', 'three' );
		$_POST['scalar'] = 'one';

		$this->assertSame( array( 'one', 'two' ), \SScribe_AJAX_Guard::post_array( 'items', 2 ) );
		$this->assertSame( array(), \SScribe_AJAX_Guard::post_array( 'scalar' ) );
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

	public function test_error_omits_diagnostics_in_production(): void {
		if ( defined( 'SSCRIBE_DEBUG' ) && SSCRIBE_DEBUG ) {
			$this->markTestSkipped( 'SSCRIBE_DEBUG is enabled; this test verifies diagnostics are omitted in production responses.' );
		}
		try {
			ob_start();
			\SScribe_AJAX_Guard::error( array( 'message' => 'fail' ), 403, array( 'ctx' => 'val' ) );
		} catch ( \RuntimeException $e ) {
			$output = ob_get_clean();
			$this->assertStringNotContainsString( '_diagnostics', $output );
			$this->assertStringNotContainsString( 'php_version', $output );
			$this->assertStringNotContainsString( 'memory_limit', $output );
		}
	}

	public function test_success_with_status_code(): void {
		$this->expectException( \RuntimeException::class );
		\SScribe_AJAX_Guard::success( 'ok', 201 );
	}
}
