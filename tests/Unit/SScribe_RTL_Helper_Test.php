<?php
/**
 * Unit tests for SScribe_RTL_Helper class.
 *
 * @package SScribe
 */

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SScribe_RTL_Helper;

class SScribe_RTL_Helper_Test extends TestCase {

	/**
	 * Test is_rtl with Arabic.
	 */
	public function test_is_rtl_arabic(): void {
		$this->assertTrue( SScribe_RTL_Helper::is_rtl( 'ar' ) );
		$this->assertTrue( SScribe_RTL_Helper::is_rtl( 'ar_EG' ) );
	}

	/**
	 * Test is_rtl with Hebrew.
	 */
	public function test_is_rtl_hebrew(): void {
		$this->assertTrue( SScribe_RTL_Helper::is_rtl( 'he' ) );
		$this->assertTrue( SScribe_RTL_Helper::is_rtl( 'he_IL' ) );
	}

	/**
	 * Test is_rtl with Farsi.
	 */
	public function test_is_rtl_farsi(): void {
		$this->assertTrue( SScribe_RTL_Helper::is_rtl( 'fa' ) );
	}

	/**
	 * Test is_rtl with Urdu.
	 */
	public function test_is_rtl_urdu(): void {
		$this->assertTrue( SScribe_RTL_Helper::is_rtl( 'ur' ) );
	}

	/**
	 * Test is_rtl with Kurdish.
	 */
	public function test_is_rtl_kurdish(): void {
		$this->assertTrue( SScribe_RTL_Helper::is_rtl( 'ku' ) );
	}

	/**
	 * Test is_rtl with Sindhi.
	 */
	public function test_is_rtl_sindhi(): void {
		$this->assertTrue( SScribe_RTL_Helper::is_rtl( 'sd' ) );
	}

	/**
	 * Test is_rtl with Yiddish.
	 */
	public function test_is_rtl_yiddish(): void {
		$this->assertTrue( SScribe_RTL_Helper::is_rtl( 'yi' ) );
	}

	/**
	 * Test is_rtl with Hebrew (old code).
	 */
	public function test_is_rtl_hebrew_old_code(): void {
		$this->assertTrue( SScribe_RTL_Helper::is_rtl( 'iw' ) );
	}

	/**
	 * Test is_rtl with Japanese (LTR).
	 */
	public function test_is_rtl_japanese(): void {
		$this->assertFalse( SScribe_RTL_Helper::is_rtl( 'ja' ) );
	}

	/**
	 * Test is_rtl with English (LTR).
	 */
	public function test_is_rtl_english(): void {
		$this->assertFalse( SScribe_RTL_Helper::is_rtl( 'en' ) );
	}

	/**
	 * Test is_rtl with empty string.
	 */
	public function test_is_rtl_empty(): void {
		$this->assertFalse( SScribe_RTL_Helper::is_rtl( '' ) );
	}

	/**
	 * Test is_rtl with invalid code.
	 */
	public function test_is_rtl_invalid(): void {
		$this->assertFalse( SScribe_RTL_Helper::is_rtl( 'xx' ) );
		$this->assertFalse( SScribe_RTL_Helper::is_rtl( 'invalid-lang' ) );
	}

	/**
	 * Test get_rtl_languages returns array.
	 */
	public function test_get_rtl_languages_returns_array(): void {
		$result = SScribe_RTL_Helper::get_rtl_languages();
		$this->assertIsArray( $result );
		$this->assertGreaterThan( 0, count( $result ) );
	}

	/**
	 * Test get_rtl_languages includes Arabic.
	 */
	public function test_get_rtl_languages_includes_arabic(): void {
		$result = SScribe_RTL_Helper::get_rtl_languages();
		$this->assertContains( 'ar', $result );
	}

	/**
	 * Test get_direction for RTL language.
	 */
	public function test_get_direction_rtl(): void {
		$this->assertSame( 'rtl', SScribe_RTL_Helper::get_direction( 'ar' ) );
		$this->assertSame( 'rtl', SScribe_RTL_Helper::get_direction( 'he' ) );
	}

	/**
	 * Test get_direction for LTR language.
	 */
	public function test_get_direction_ltr(): void {
		$this->assertSame( 'ltr', SScribe_RTL_Helper::get_direction( 'en' ) );
		$this->assertSame( 'ltr', SScribe_RTL_Helper::get_direction( 'ja' ) );
	}

	/**
	 * Test get_alignment for RTL language.
	 */
	public function test_get_alignment_rtl(): void {
		$this->assertSame( 'right', SScribe_RTL_Helper::get_alignment( 'ar' ) );
	}

	/**
	 * Test get_alignment for LTR language.
	 */
	public function test_get_alignment_ltr(): void {
		$this->assertSame( 'left', SScribe_RTL_Helper::get_alignment( 'en' ) );
	}

	/**
	 * Test language code parsing (first 2 chars).
	 */
	public function test_language_code_parsing(): void {
		$this->assertTrue( SScribe_RTL_Helper::is_rtl( 'ar_EG' ) );
		$this->assertTrue( SScribe_RTL_Helper::is_rtl( 'he_IL' ) );
		$this->assertFalse( SScribe_RTL_Helper::is_rtl( 'en_US' ) );
	}
}
