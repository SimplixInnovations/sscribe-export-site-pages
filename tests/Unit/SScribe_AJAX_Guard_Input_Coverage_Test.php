<?php
/**
 * SScribe AJAX Guard input helpers coverage test.
 *
 * Targets the static input-parsing helpers. The send-json methods
 * (success/error) call exit, so we focus on the pure parsing surface.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;

if ( ! class_exists( '\\SScribe_AJAX_Guard', false ) ) {
	require_once SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-ajax-guard.php';
}

final class SScribe_AJAX_Guard_Input_Coverage_Test extends TestCase {

	private ReflectionClass $ref;

	protected function setUp(): void {
		parent::setUp();
		$_POST = array();
		$_GET  = array();
		$this->ref = new ReflectionClass( \SScribe_AJAX_Guard::class );
	}

	private function call( string $name, array $args = array() ): mixed {
		$m = $this->ref->getMethod( $name );
		$m->setAccessible( true );
		return $m->invokeArgs( null, $args );
	}

	public function test_post_text_returns_default_for_missing(): void {
		$result = \SScribe_AJAX_Guard::post_text( 'missing' );
		$this::assertSame( '', $result );
	}

	public function test_post_text_returns_value_when_set(): void {
		$_POST['key1'] = 'hello';
		$result = \SScribe_AJAX_Guard::post_text( 'key1' );
		$this::assertSame( 'hello', $result );
	}

	public function test_post_text_bounds_length(): void {
		$_POST['big'] = str_repeat( 'a', 500 );
		$result = \SScribe_AJAX_Guard::post_text( 'big', '', 100 );
		$this::assertSame( 100, strlen( $result ) );
	}

	public function test_post_text_handles_array_as_default(): void {
		$_POST['array'] = array( 'evil' );
		$result = \SScribe_AJAX_Guard::post_text( 'array' );
		$this::assertSame( '', $result );
	}

	public function test_post_text_handles_array_with_default(): void {
		// Array payload with non-empty default returns the default.
		$_POST['arr'] = array( 'evil' );
		$result = \SScribe_AJAX_Guard::post_text( 'arr', 'fallback' );
		$this::assertSame( 'fallback', $result );
	}

	public function test_get_text_returns_value(): void {
		$_GET['g1'] = 'gval';
		$result = \SScribe_AJAX_Guard::get_text( 'g1' );
		$this::assertSame( 'gval', $result );
	}

	public function test_post_integer_default_for_missing(): void {
		$result = \SScribe_AJAX_Guard::post_integer( 'missing', 7 );
		$this::assertSame( 7, $result );
	}

	public function test_post_integer_validates_int(): void {
		$_POST['num'] = '42';
		$result = \SScribe_AJAX_Guard::post_integer( 'num' );
		$this::assertSame( 42, $result );
	}

	public function test_post_integer_rejects_non_numeric(): void {
		$_POST['num'] = 'abc';
		$result = \SScribe_AJAX_Guard::post_integer( 'num', 5 );
		$this::assertSame( 5, $result );
	}

	public function test_post_integer_clamps_to_min_max(): void {
		$_POST['num'] = '999';
		$result = \SScribe_AJAX_Guard::post_integer( 'num', 0, 1, 100 );
		$this::assertSame( 100, $result );
		$_POST['num'] = '-5';
		$result = \SScribe_AJAX_Guard::post_integer( 'num', 0, 0, 100 );
		$this::assertSame( 0, $result );
	}

	public function test_post_integer_handles_array(): void {
		$_POST['num'] = array( 'evil' );
		$result = \SScribe_AJAX_Guard::post_integer( 'num', 3 );
		$this::assertSame( 3, $result );
	}

	public function test_post_boolean_handles_true(): void {
		$_POST['b'] = 'true';
		$result = \SScribe_AJAX_Guard::post_boolean( 'b' );
		$this::assertTrue( $result );
	}

	public function test_post_boolean_handles_false(): void {
		$_POST['b'] = 'false';
		$result = \SScribe_AJAX_Guard::post_boolean( 'b' );
		$this::assertFalse( $result );
	}

	public function test_post_boolean_default_for_invalid(): void {
		$_POST['b'] = 'not-a-bool';
		$result = \SScribe_AJAX_Guard::post_boolean( 'b', true );
		$this::assertTrue( $result );
	}

	public function test_post_array_returns_empty_for_non_array(): void {
		$_POST['a'] = 'scalar';
		$result = \SScribe_AJAX_Guard::post_array( 'a' );
		$this::assertSame( array(), $result );
	}

	public function test_post_array_bounds_item_count(): void {
		$_POST['a'] = range( 1, 200 );
		$result = \SScribe_AJAX_Guard::post_array( 'a', 10 );
		$this::assertCount( 10, $result );
	}

	public function test_post_array_returns_array_unchanged(): void {
		$_POST['a'] = array( 'x', 'y', 'z' );
		$result = \SScribe_AJAX_Guard::post_array( 'a' );
		$this::assertSame( array( 'x', 'y', 'z' ), $result );
	}

	public function test_format_bytes_helper(): void {
		$result = $this->call( 'format_bytes', array( 0 ) );
		$this::assertSame( '0 B', $result );
		$result = $this->call( 'format_bytes', array( 512 ) );
		$this::assertSame( '512 B', $result );
		// 1024 bytes — first divide yields 1.0, so unit index advances to MB[1].
		$result = $this->call( 'format_bytes', array( 1024 ) );
		$this::assertSame( '1.00 MB', $result );
		$result = $this->call( 'format_bytes', array( 1024 * 1024 ) );
		$this::assertSame( '1.00 GB', $result );
	}

	public function test_disable_if_possible_returns_bool(): void {
		$result = $this->call( 'disable_if_possible', array( 'display_errors', '0' ) );
		$this::assertIsBool( $result );
	}

	public function test_extract_message_handles_array(): void {
		$result = $this->call( 'extract_message', array( array( 'message' => 'hi' ) ) );
		$this::assertSame( 'hi', $result );
	}

	public function test_extract_message_handles_string(): void {
		$result = $this->call( 'extract_message', array( 'plain' ) );
		$this::assertSame( 'plain', $result );
	}

	public function test_extract_message_handles_object_with_tostring(): void {
		$obj = new class {
			public function __toString(): string { return 'objstr'; }
		};
		$result = $this->call( 'extract_message', array( $obj ) );
		$this::assertSame( 'objstr', $result );
	}

	public function test_extract_message_handles_unknown(): void {
		$result = $this->call( 'extract_message', array( array( 'no_message_key' => 'x' ) ) );
		$this::assertSame( 'unknown', $result );
	}

	public function test_resolve_action_name_returns_unknown_when_missing(): void {
		$result = $this->call( 'resolve_action_name' );
		$this::assertSame( 'unknown', $result );
	}

	public function test_resolve_action_name_reads_post(): void {
		$_POST['action'] = 'sscribe_test_action';
		$result = $this->call( 'resolve_action_name' );
		$this::assertSame( 'sscribe_test_action', $result );
	}

	public function test_resolve_action_name_reads_get(): void {
		$_GET['action'] = 'sscribe_get_action';
		$result = $this->call( 'resolve_action_name' );
		$this::assertSame( 'sscribe_get_action', $result );
	}

	public function test_class_has_expected_methods(): void {
		$this::assertTrue( method_exists( \SScribe_AJAX_Guard::class, 'post_text' ) );
		$this::assertTrue( method_exists( \SScribe_AJAX_Guard::class, 'get_text' ) );
		$this::assertTrue( method_exists( \SScribe_AJAX_Guard::class, 'post_integer' ) );
		$this::assertTrue( method_exists( \SScribe_AJAX_Guard::class, 'post_boolean' ) );
		$this::assertTrue( method_exists( \SScribe_AJAX_Guard::class, 'post_array' ) );
		$this::assertTrue( method_exists( \SScribe_AJAX_Guard::class, 'with_guard' ) );
	}
}
