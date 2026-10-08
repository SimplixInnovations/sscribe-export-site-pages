<?php
/**
 * Unit tests for SScribe_Secret_Store.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SScribe_Secret_Store_Test extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		unset( $GLOBALS['sscribe_test_options'][ \SScribe_Secret_Store::KEY_OPTION ] );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['sscribe_test_options'][ \SScribe_Secret_Store::KEY_OPTION ] );
		parent::tearDown();
	}

	public function test_sealed_secret_opens_to_the_original_value(): void {
		$sealed = \SScribe_Secret_Store::seal( 'wJalrXUtnFEMI/K7MDENG' );

		$this::assertStringNotContainsString( 'wJalrXUtnFEMI', $sealed );
		$this::assertTrue( \SScribe_Secret_Store::is_sealed( $sealed ) );
		$this::assertSame( 'wJalrXUtnFEMI/K7MDENG', \SScribe_Secret_Store::open( $sealed ) );
	}

	public function test_each_seal_uses_a_fresh_nonce(): void {
		$this::assertNotSame( \SScribe_Secret_Store::seal( 'same' ), \SScribe_Secret_Store::seal( 'same' ) );
	}

	public function test_key_is_created_once_and_not_autoloaded(): void {
		\SScribe_Secret_Store::seal( 'one' );
		$key = $GLOBALS['sscribe_test_options'][ \SScribe_Secret_Store::KEY_OPTION ];
		\SScribe_Secret_Store::seal( 'two' );

		$this::assertSame( $key, $GLOBALS['sscribe_test_options'][ \SScribe_Secret_Store::KEY_OPTION ] );
		$this::assertSame( 32, strlen( (string) base64_decode( (string) $key, true ) ) );
		$this::assertSame( 'no', $GLOBALS['sscribe_test_option_autoload'][ \SScribe_Secret_Store::KEY_OPTION ] );
	}

	public function test_tampered_ciphertext_is_rejected(): void {
		$sealed   = \SScribe_Secret_Store::seal( 'secret value' );
		$at       = strlen( $sealed ) - 8;
		$current  = $sealed[ $at ];
		$tampered = substr( $sealed, 0, $at ) . ( 'A' === $current ? 'B' : 'A' ) . substr( $sealed, $at + 1 );

		$this->expectException( \RuntimeException::class );
		\SScribe_Secret_Store::open( $tampered );
	}

	public function test_secret_sealed_under_another_key_does_not_open(): void {
		$sealed = \SScribe_Secret_Store::seal( 'secret value' );
		$GLOBALS['sscribe_test_options'][ \SScribe_Secret_Store::KEY_OPTION ] = base64_encode( random_bytes( 32 ) );

		$this->expectException( \RuntimeException::class );
		\SScribe_Secret_Store::open( $sealed );
	}

	public function test_plain_text_is_not_treated_as_sealed(): void {
		$this::assertFalse( \SScribe_Secret_Store::is_sealed( 'hunter2' ) );

		$this->expectException( \RuntimeException::class );
		\SScribe_Secret_Store::open( 'hunter2' );
	}

	public function test_empty_secret_round_trips(): void {
		$this::assertSame( '', \SScribe_Secret_Store::open( \SScribe_Secret_Store::seal( '' ) ) );
	}
}
