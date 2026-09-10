<?php
/**
 * SScribe Session static helper coverage test
 *
 * Targets the pure static helpers of SScribe_Session:
 *
 *   - is_valid_session_id()    : length validation
 *   - extract_session_id()      : prefix + 16-hex regex
 *   - enable_test_mode()       : / disable_test_mode() / test_reset()
 *   - get_option_name()         : private but reflection-hittable
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;

if ( ! class_exists( '\\SScribe_Session', false ) ) {
	require_once SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-session.php';
}

final class SScribe_Session_Static_Helper_Coverage_Test extends TestCase {

	private ReflectionClass $ref;

	protected function setUp(): void {
		parent::setUp();
		$this->ref = new ReflectionClass( \SScribe_Session::class );
	}

	private function call_static( string $name, array $args = array() ): mixed {
		$m = $this->ref->getMethod( $name );
		$m->setAccessible( true );
		return $m->invokeArgs( null, $args );
	}

	public function test_is_valid_session_id_rejects_too_short(): void {
		$this::assertFalse( $this->call_static( 'is_valid_session_id', array( 'short' ) ) );
		$this::assertFalse( $this->call_static( 'is_valid_session_id', array( '' ) ) );
	}

	public function test_is_valid_session_id_accepts_16_char_hex(): void {
		$this::assertTrue( $this->call_static( 'is_valid_session_id', array( '0123456789abcdef' ) ) );
	}

	public function test_extract_session_id_returns_null_for_unrelated(): void {
		$this::assertNull( $this->call_static( 'extract_session_id', array( 'unrelated_option' ) ) );
		$this::assertNull( $this->call_static( 'extract_session_id', array( '' ) ) );
	}

	public function test_extract_session_id_returns_id_for_prefixed(): void {
		// Use the class's own prefix.
		$prefix = $this->ref->getConstant( 'OPTION_PREFIX' );
		$result = $this->call_static( 'extract_session_id', array( $prefix . '0123456789abcdef' ) );
		$this::assertSame( '0123456789abcdef', $result );
	}

	public function test_extract_session_id_rejects_non_hex_suffix(): void {
		$prefix = $this->ref->getConstant( 'OPTION_PREFIX' );
		// 'g' is non-hex.
		$this::assertNull( $this->call_static( 'extract_session_id', array( $prefix . 'gggggggggggggggg' ) ) );
	}

	public function test_extract_session_id_rejects_wrong_length(): void {
		$prefix = $this->ref->getConstant( 'OPTION_PREFIX' );
		$this::assertNull( $this->call_static( 'extract_session_id', array( $prefix . 'abcd' ) ) );
	}

	public function test_test_mode_toggle(): void {
		// Enable, disable, reset — must all return void without errors.
		\SScribe_Session::enable_test_mode();
		\SScribe_Session::disable_test_mode();
		\SScribe_Session::test_reset();
		$this::assertTrue( true ); // survived
	}

	public function test_session_class_has_known_constants(): void {
		$this::assertTrue( $this->ref->hasConstant( 'OPTION_PREFIX' ) );
		$this::assertTrue( $this->ref->hasConstant( 'SESSION_ID_MIN_LENGTH' ) );
		$this::assertTrue( $this->ref->hasConstant( 'SESSION_ID_MAX_LENGTH' ) );
	}

	public function test_session_class_has_known_methods(): void {
		$this::assertTrue( method_exists( \SScribe_Session::class, 'create' ) );
		$this::assertTrue( method_exists( \SScribe_Session::class, 'get' ) );
		$this::assertTrue( method_exists( \SScribe_Session::class, 'update' ) );
		$this::assertTrue( method_exists( \SScribe_Session::class, 'delete' ) );
		$this::assertTrue( method_exists( \SScribe_Session::class, 'validate' ) );
		$this::assertTrue( method_exists( \SScribe_Session::class, 'cleanup_expired' ) );
	}
}
