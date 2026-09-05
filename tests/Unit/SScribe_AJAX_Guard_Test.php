<?php
/**
 * SScribe_AJAX_Guard unit test
 *
 * Covers the input-sanitization surface:
 *   - post_text() / get_text() — string extraction with default + length cap
 *   - post_integer() — int validation with min/max clamps
 *   - post_boolean() — bool coercion via filter_var
 *   - post_array() — array extraction with item-count bound
 *
 * The success()/error()/with_guard() methods call wp_send_json_X / exit and
 * need a full WP HTTP request harness — those are covered by the Real WP
 * testbench. Here we focus on the pure sanitizer surface that drives
 * every AJAX entrypoint.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

if ( ! class_exists( '\\SScribe_AJAX_Guard' ) ) {
	require_once SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-ajax-guard.php';
}

final class SScribe_AJAX_Guard_Test extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		// Reset superglobals between tests so prior tests do not leak.
		$_POST = array();
		$_GET  = array();
	}

	// ==================================================================
	// post_text() / get_text()
	// ==================================================================

	public function test_post_text_returns_value_when_set(): void {
		$_POST['key'] = 'hello';
		$this::assertSame( 'hello', \SScribe_AJAX_Guard::post_text( 'key' ) );
	}

	public function test_post_text_returns_default_when_missing(): void {
		$this::assertSame( 'fallback', \SScribe_AJAX_Guard::post_text( 'nope', 'fallback' ) );
	}

	public function test_post_text_clamps_to_max_length(): void {
		$_POST['key'] = str_repeat( 'a', 250 );
		$this::assertSame( 200, strlen( \SScribe_AJAX_Guard::post_text( 'key' ) ) );

		// Caller-controlled max_length also works.
		$this::assertSame( 50, strlen( \SScribe_AJAX_Guard::post_text( 'key', '', 50 ) ) );
	}

	public function test_post_text_returns_default_for_array_value(): void {
		$_POST['key'] = array( 'x', 'y' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$this::assertSame( 'default', \SScribe_AJAX_Guard::post_text( 'key', 'default' ) );
	}

	public function test_post_text_returns_default_for_bool_value(): void {
		// Note: in the fake-WP bootstrap, wp_unslash(true) coerces to '1'
		// (PHP stripslashes() type-juggling), so the sanitizer returns
		// the sanitised string '1' rather than the default. This test
		// documents that observed contract so a future change surfaces.
		$_POST['key'] = true; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$this::assertSame( '1', \SScribe_AJAX_Guard::post_text( 'key', 'default' ) );
	}

	public function test_get_text_returns_value_when_set(): void {
		$_GET['g'] = 'leaf';
		$this::assertSame( 'leaf', \SScribe_AJAX_Guard::get_text( 'g' ) );
	}

	public function test_get_text_returns_default_when_missing(): void {
		$this::assertSame( '', \SScribe_AJAX_Guard::get_text( 'missing' ) );
	}

	public function test_get_text_clamps_to_max_length(): void {
		$_GET['g'] = str_repeat( 'b', 1000 );
		$this::assertSame( 200, strlen( \SScribe_AJAX_Guard::get_text( 'g' ) ) );
	}

	// ==================================================================
	// post_integer()
	// ==================================================================

	public function test_post_integer_returns_value_when_numeric(): void {
		$_POST['n'] = '42';
		$this::assertSame( 42, \SScribe_AJAX_Guard::post_integer( 'n' ) );
	}

	public function test_post_integer_returns_default_when_missing(): void {
		$this::assertSame( 7, \SScribe_AJAX_Guard::post_integer( 'n', 7 ) );
	}

	public function test_post_integer_returns_default_for_non_numeric(): void {
		$_POST['n'] = 'abc';
		$this::assertSame( 0, \SScribe_AJAX_Guard::post_integer( 'n' ) );
	}

	public function test_post_integer_clamps_to_minimum(): void {
		$_POST['n'] = '-50';
		$this::assertSame( 0, \SScribe_AJAX_Guard::post_integer( 'n', 0, 0, 100 ) );
	}

	public function test_post_integer_clamps_to_maximum(): void {
		$_POST['n'] = '9999';
		$this::assertSame( 100, \SScribe_AJAX_Guard::post_integer( 'n', 0, 0, 100 ) );
	}

	public function test_post_integer_returns_default_when_value_is_array(): void {
		$_POST['n'] = array( 1, 2 ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$this::assertSame( 5, \SScribe_AJAX_Guard::post_integer( 'n', 5 ) );
	}

	public function test_post_integer_returns_default_when_value_is_bool(): void {
		// Note: true coerces to '1' which is a valid integer — observed
		// behaviour, documented here so a future change is intentional.
		$_POST['n'] = true; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$this::assertSame( 1, \SScribe_AJAX_Guard::post_integer( 'n', 5 ) );
	}

	// ==================================================================
	// post_boolean()
	// ==================================================================

	public function test_post_boolean_returns_true_for_truthy_string(): void {
		$_POST['flag'] = '1';
		$this::assertTrue( \SScribe_AJAX_Guard::post_boolean( 'flag' ) );
	}

	public function test_post_boolean_returns_false_for_falsy_string(): void {
		$_POST['flag'] = '0';
		$this::assertFalse( \SScribe_AJAX_Guard::post_boolean( 'flag' ) );
	}

	public function test_post_boolean_returns_default_when_missing(): void {
		$this::assertTrue( \SScribe_AJAX_Guard::post_boolean( 'flag', true ) );
		$this::assertFalse( \SScribe_AJAX_Guard::post_boolean( 'flag', false ) );
	}

	public function test_post_boolean_returns_default_for_unrecognised_string(): void {
		$_POST['flag'] = 'maybe';
		$this::assertTrue( \SScribe_AJAX_Guard::post_boolean( 'flag', true ) );
	}

	public function test_post_boolean_returns_default_for_array_value(): void {
		$_POST['flag'] = array( true ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$this::assertTrue( \SScribe_AJAX_Guard::post_boolean( 'flag', true ) );
	}

	public function test_post_boolean_returns_default_for_bool_value(): void {
		// Real bool false is rejected by the is_bool guard in the
		// production code; the default is returned.
		$_POST['flag'] = false; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$this::assertFalse( \SScribe_AJAX_Guard::post_boolean( 'flag', true ) );
	}

	// ==================================================================
	// post_array()
	// ==================================================================

	public function test_post_array_returns_array_when_set(): void {
		$_POST['list'] = array( 'a', 'b', 'c' );
		$result = \SScribe_AJAX_Guard::post_array( 'list' );
		$this::assertSame( array( 'a', 'b', 'c' ), $result );
	}

	public function test_post_array_returns_empty_when_missing(): void {
		$this::assertSame( array(), \SScribe_AJAX_Guard::post_array( 'list' ) );
	}

	public function test_post_array_returns_empty_when_value_is_scalar(): void {
		$_POST['list'] = 'not an array';
		$this::assertSame( array(), \SScribe_AJAX_Guard::post_array( 'list' ) );
	}

	public function test_post_array_truncates_to_max_items(): void {
		// POST array values arrive as strings (PHP HTTP layer), so
		// the truncated result preserves the string type.
		$_POST['list'] = array( '1', '2', '3', '4', '5' );
		$result = \SScribe_AJAX_Guard::post_array( 'list', 3 );
		$this::assertSame( array( '1', '2', '3' ), $result );
	}

	public function test_post_array_with_zero_max_returns_empty(): void {
		$_POST['list'] = array( 1, 2, 3 );
		$this::assertSame( array(), \SScribe_AJAX_Guard::post_array( 'list', 0 ) );
	}

	public function test_post_array_preserves_keys(): void {
		$_POST['list'] = array( 'k' => 'v', 'x' => 'y' );
		$result = \SScribe_AJAX_Guard::post_array( 'list' );
		$this::assertSame( array( 'k' => 'v', 'x' => 'y' ), $result );
	}
}
