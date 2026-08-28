<?php
/**
 * Regression tests for the session-id length envelope introduced in
 * sScribe 2.0.0.
 *
 * The pre-2.0.0 strict 16-character check rejected any session
 * identifier issued by a third-party extension that used a different
 * generator (UUIDs, hashes, prefixed ids). The bounds check accepts
 * 8..128 characters and rejects shorter or longer identifiers, so
 * upstream code paths can keep their existing ids.
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SScribe_Session;

final class SScribe_Session_Length_Bounds_Test extends TestCase {

	/**
	 * Invoke the private SScribe_Session::is_valid_session_id() helper
	 * so we can verify the exact bounds independently of any session
	 * existence side effect.
	 */
	private static function is_valid( string $id ): bool {
		$method = new \ReflectionMethod( SScribe_Session::class, 'is_valid_session_id' );
		return (bool) $method->invoke( null, $id );
	}

	public function test_empty_session_id_is_rejected(): void {
		$this->assertFalse( self::is_valid( '' ) );
	}

	public function test_session_shorter_than_eight_chars_is_rejected(): void {
		$this->assertFalse( self::is_valid( '1' ) );
		$this->assertFalse( self::is_valid( '1234567' ) );
	}

	public function test_session_at_minimum_length_is_accepted(): void {
		$this->assertTrue( self::is_valid( '12345678' ) );
	}

	public function test_native_sixteen_char_session_is_accepted(): void {
		$this->assertTrue( self::is_valid( str_repeat( 'a', 16 ) ) );
	}

	public function test_third_party_thirty_two_char_hex_is_accepted(): void {
		$this->assertTrue( self::is_valid( str_repeat( 'a', 32 ) ) );
	}

	public function test_third_party_dashed_uuid_is_accepted(): void {
		$this->assertTrue( self::is_valid( '12345678-90ab-cdef-1234-567890abcdef' ) );
	}

	public function test_session_at_maximum_length_is_accepted(): void {
		$this->assertTrue( self::is_valid( str_repeat( 'a', 128 ) ) );
	}

	public function test_session_longer_than_one_twenty_eight_chars_is_rejected(): void {
		$this->assertFalse( self::is_valid( str_repeat( 'a', 129 ) ) );
		$this->assertFalse( self::is_valid( str_repeat( 'a', 256 ) ) );
	}

	/**
	 * The full get() path still rejects identifiers outside the bounds,
	 * returning null instead of attempting to look up an option that
	 * could collide with a future non-SScribe key.
	 */
	public function test_get_returns_null_for_short_id(): void {
		$session = new SScribe_Session();
		$this->assertNull( $session->get( '1234567' ) );
	}

	public function test_get_returns_null_for_too_long_id(): void {
		$session = new SScribe_Session();
		$this->assertNull( $session->get( str_repeat( 'a', 200 ) ) );
	}
}