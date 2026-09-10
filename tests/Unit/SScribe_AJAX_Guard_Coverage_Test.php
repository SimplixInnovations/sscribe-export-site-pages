<?php
/**
 * SScribe AJAX Guard coverage test
 *
 * Exercises the public static input readers and helpers of SScribe_AJAX_Guard.
 * These read from $_POST / $_GET, so each test seeds the relevant super-global
 * with controlled input and asserts the sanitized return value.
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

if ( ! class_exists( '\\SScribe_AJAX_Guard', false ) ) {
	require_once SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-ajax-guard.php';
}

final class SScribe_AJAX_Guard_Coverage_Test extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$_POST = array();
		$_GET  = array();
	}

	protected function tearDown(): void {
		$_POST = array();
		$_GET  = array();
		parent::tearDown();
	}

	public function test_post_text_returns_default_when_missing(): void {
		$this::assertSame( 'default', \SScribe_AJAX_Guard::post_text( 'missing', 'default' ) );
	}

	public function test_post_text_returns_text_when_present(): void {
		$_POST['key'] = 'hello';
		$this::assertSame( 'hello', \SScribe_AJAX_Guard::post_text( 'key', 'default' ) );
	}

	public function test_post_text_truncates_to_max_length(): void {
		$_POST['key'] = str_repeat( 'x', 500 );
		$result       = \SScribe_AJAX_Guard::post_text( 'key', '', 10 );
		$this::assertSame( 10, strlen( $result ) );
	}

	public function test_post_text_converts_non_scalar_to_default(): void {
		$_POST['key'] = array( 'a', 'b' );
		$this::assertSame( 'default', \SScribe_AJAX_Guard::post_text( 'key', 'default' ) );
	}

	public function test_get_text_returns_default_when_missing(): void {
		$this::assertSame( 'default', \SScribe_AJAX_Guard::get_text( 'missing', 'default' ) );
	}

	public function test_get_text_returns_text_when_present(): void {
		$_GET['key'] = 'value';
		$this::assertSame( 'value', \SScribe_AJAX_Guard::get_text( 'key', 'default' ) );
	}

	public function test_get_text_truncates_to_max_length(): void {
		$_GET['key'] = str_repeat( 'y', 500 );
		$result      = \SScribe_AJAX_Guard::get_text( 'key', '', 20 );
		$this::assertSame( 20, strlen( $result ) );
	}

	public function test_post_integer_returns_default_when_missing(): void {
		$this::assertSame( 42, \SScribe_AJAX_Guard::post_integer( 'missing', 42 ) );
	}

	public function test_post_integer_returns_int_when_valid(): void {
		$_POST['n'] = '123';
		$this::assertSame( 123, \SScribe_AJAX_Guard::post_integer( 'n', 0 ) );
	}

	public function test_post_integer_clamps_to_min_max(): void {
		$_POST['n'] = '999999';
		$this::assertSame( 100, \SScribe_AJAX_Guard::post_integer( 'n', 0, 0, 100 ) );

		$_POST['n'] = '-999';
		$this::assertSame( 0, \SScribe_AJAX_Guard::post_integer( 'n', 0, 0, 100 ) );
	}

	public function test_post_integer_returns_default_for_invalid_string(): void {
		$_POST['n'] = 'not-a-number';
		$this::assertSame( 7, \SScribe_AJAX_Guard::post_integer( 'n', 7 ) );
	}

	public function test_post_integer_returns_default_for_non_scalar(): void {
		$_POST['n'] = array( 1, 2 );
		$this::assertSame( 5, \SScribe_AJAX_Guard::post_integer( 'n', 5 ) );
	}

	public function test_post_integer_treats_bool_as_one_or_zero(): void {
		// PHP bool values are scalar so the function casts to string and
		// validates as int: true → '1', false → '' → default.
		$_POST['n'] = true;
		$result     = \SScribe_AJAX_Guard::post_integer( 'n', 0 );
		$this::assertContains( $result, array( 0, 1 ) );
	}

	public function test_post_boolean_returns_default_when_missing(): void {
		$this::assertFalse( \SScribe_AJAX_Guard::post_boolean( 'missing' ) );
		$this::assertTrue( \SScribe_AJAX_Guard::post_boolean( 'missing', true ) );
	}

	public function test_post_boolean_interprets_truthy_strings(): void {
		$_POST['flag'] = '1';
		$this::assertTrue( \SScribe_AJAX_Guard::post_boolean( 'flag' ) );

		$_POST['flag'] = 'true';
		$this::assertTrue( \SScribe_AJAX_Guard::post_boolean( 'flag' ) );

		$_POST['flag'] = 'yes';
		$this::assertTrue( \SScribe_AJAX_Guard::post_boolean( 'flag' ) );
	}

	public function test_post_boolean_interprets_falsy_strings(): void {
		$_POST['flag'] = '0';
		$this::assertFalse( \SScribe_AJAX_Guard::post_boolean( 'flag' ) );

		$_POST['flag'] = 'false';
		$this::assertFalse( \SScribe_AJAX_Guard::post_boolean( 'flag' ) );
	}

	public function test_post_boolean_returns_default_for_non_scalar(): void {
		$_POST['flag'] = array();
		$this::assertTrue( \SScribe_AJAX_Guard::post_boolean( 'flag', true ) );
	}

	public function test_post_array_returns_empty_for_missing(): void {
		$this::assertSame( array(), \SScribe_AJAX_Guard::post_array( 'missing' ) );
	}

	public function test_post_array_returns_array_when_present(): void {
		$_POST['items'] = array( 'a', 'b', 'c' );
		$this::assertSame( array( 'a', 'b', 'c' ), \SScribe_AJAX_Guard::post_array( 'items' ) );
	}

	public function test_post_array_truncates_to_max_items(): void {
		$_POST['items'] = array( 'a', 'b', 'c', 'd', 'e' );
		$result         = \SScribe_AJAX_Guard::post_array( 'items', 3 );
		$this::assertCount( 3, $result );
	}

	public function test_post_array_returns_default_when_not_array(): void {
		$_POST['items'] = 'a-string';
		$result         = \SScribe_AJAX_Guard::post_array( 'items' );
		$this::assertSame( array(), $result );
	}

	public function test_class_has_expected_public_methods(): void {
		$this::assertTrue( method_exists( \SScribe_AJAX_Guard::class, 'post_text' ) );
		$this::assertTrue( method_exists( \SScribe_AJAX_Guard::class, 'get_text' ) );
		$this::assertTrue( method_exists( \SScribe_AJAX_Guard::class, 'post_integer' ) );
		$this::assertTrue( method_exists( \SScribe_AJAX_Guard::class, 'post_boolean' ) );
		$this::assertTrue( method_exists( \SScribe_AJAX_Guard::class, 'post_array' ) );
		$this::assertTrue( method_exists( \SScribe_AJAX_Guard::class, 'success' ) );
		$this::assertTrue( method_exists( \SScribe_AJAX_Guard::class, 'error' ) );
		$this::assertTrue( method_exists( \SScribe_AJAX_Guard::class, 'with_guard' ) );
	}
}
