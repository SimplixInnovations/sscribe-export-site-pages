<?php
/**
 * SScribe Request Id Unit Test
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

class SScribe_Request_Id_Test extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		\SScribe_Request_Id::reset();
		unset( $_SERVER['HTTP_X_SSCRIBE_REQUEST_ID'], $_SERVER['X_SSCRIBE_REQUEST_ID'] );
	}

	protected function tearDown(): void {
		\SScribe_Request_Id::reset();
		unset( $_SERVER['HTTP_X_SSCRIBE_REQUEST_ID'], $_SERVER['X_SSCRIBE_REQUEST_ID'] );
		parent::tearDown();
	}

	public function test_generated_id_matches_pattern(): void {
		$id = \SScribe_Request_Id::current();
		$this->assertMatchesRegularExpression( \SScribe_Request_Id::PATTERN, $id );
	}

	public function test_generated_ids_are_unique_per_request(): void {
		\SScribe_Request_Id::reset();
		$a = \SScribe_Request_Id::current();
		\SScribe_Request_Id::reset();
		$b = \SScribe_Request_Id::current();
		$this->assertNotSame( $a, $b );
	}

	public function test_same_call_returns_same_id(): void {
		$a = \SScribe_Request_Id::current();
		$b = \SScribe_Request_Id::current();
		$this->assertSame( $a, $b );
	}

	public function test_supplied_header_is_honored_when_valid(): void {
		$_SERVER['HTTP_X_SSCRIBE_REQUEST_ID'] = 'ssr_0123456789abcdef';
		$this->assertSame( 'ssr_0123456789abcdef', \SScribe_Request_Id::current() );
	}

	public function test_supplied_header_is_rejected_when_malformed(): void {
		$_SERVER['HTTP_X_SSCRIBE_REQUEST_ID'] = 'not-a-valid-id';
		$id = \SScribe_Request_Id::current();
		$this->assertMatchesRegularExpression( \SScribe_Request_Id::PATTERN, $id );
		$this->assertNotSame( 'not-a-valid-id', $id );
	}

	public function test_supplied_header_is_rejected_when_oversized(): void {
		$_SERVER['HTTP_X_SSCRIBE_REQUEST_ID'] = 'ssr_' . str_repeat( 'a', 200 );
		$id = \SScribe_Request_Id::current();
		$this->assertMatchesRegularExpression( \SScribe_Request_Id::PATTERN, $id );
	}

	public function test_supplied_header_uppercase_is_normalised_to_lowercase(): void {
		$_SERVER['HTTP_X_SSCRIBE_REQUEST_ID'] = 'SSR_0123456789ABCDEF';
		$this->assertSame( 'ssr_0123456789abcdef', \SScribe_Request_Id::current() );
	}

	public function test_sanitize_rejects_non_string(): void {
		$this->assertNull( \SScribe_Request_Id::sanitize( array() ) );
		$this->assertNull( \SScribe_Request_Id::sanitize( null ) );
		$this->assertNull( \SScribe_Request_Id::sanitize( true ) );
	}

	public function test_sanitize_rejects_wrong_prefix(): void {
		$this->assertNull( \SScribe_Request_Id::sanitize( 'abc_0123456789abcdef' ) );
	}

	public function test_sanitize_rejects_wrong_hex_length(): void {
		$this->assertNull( \SScribe_Request_Id::sanitize( 'ssr_12345' ) );
		$this->assertNull( \SScribe_Request_Id::sanitize( 'ssr_0123456789abcdef00' ) );
	}
}
