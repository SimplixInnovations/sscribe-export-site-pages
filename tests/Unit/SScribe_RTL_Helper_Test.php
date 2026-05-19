<?php

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SScribe_RTL_Helper;

class SScribe_RTL_Helper_Test extends TestCase {

	public function test_is_rtl_arabic(): void {
		$this->assertTrue( SScribe_RTL_Helper::is_rtl( 'ar' ) );
		$this->assertTrue( SScribe_RTL_Helper::is_rtl( 'ar_EG' ) );
	}

	public function test_is_rtl_hebrew(): void {
		$this->assertTrue( SScribe_RTL_Helper::is_rtl( 'he' ) );
		$this->assertTrue( SScribe_RTL_Helper::is_rtl( 'he_IL' ) );
	}

	public function test_is_rtl_farsi(): void {
		$this->assertTrue( SScribe_RTL_Helper::is_rtl( 'fa' ) );
	}

	public function test_is_rtl_urdu(): void {
		$this->assertTrue( SScribe_RTL_Helper::is_rtl( 'ur' ) );
	}

	public function test_is_rtl_kurdish(): void {
		$this->assertTrue( SScribe_RTL_Helper::is_rtl( 'ku' ) );
	}

	public function test_is_rtl_sindhi(): void {
		$this->assertTrue( SScribe_RTL_Helper::is_rtl( 'sd' ) );
	}

	public function test_is_rtl_yiddish(): void {
		$this->assertTrue( SScribe_RTL_Helper::is_rtl( 'yi' ) );
	}

	public function test_is_rtl_hebrew_old_code(): void {
		$this->assertTrue( SScribe_RTL_Helper::is_rtl( 'iw' ) );
	}

	public function test_is_rtl_japanese(): void {
		$this->assertFalse( SScribe_RTL_Helper::is_rtl( 'ja' ) );
	}

	public function test_is_rtl_english(): void {
		$this->assertFalse( SScribe_RTL_Helper::is_rtl( 'en' ) );
	}

	public function test_is_rtl_empty(): void {
		$this->assertFalse( SScribe_RTL_Helper::is_rtl( '' ) );
	}

	public function test_is_rtl_invalid(): void {
		$this->assertFalse( SScribe_RTL_Helper::is_rtl( 'xx' ) );
		$this->assertFalse( SScribe_RTL_Helper::is_rtl( 'invalid-lang' ) );
	}

	public function test_get_rtl_languages_returns_array(): void {
		$result = SScribe_RTL_Helper::get_rtl_languages();
		$this->assertIsArray( $result );
		$this->assertGreaterThan( 0, count( $result ) );
	}

	public function test_get_rtl_languages_includes_arabic(): void {
		$result = SScribe_RTL_Helper::get_rtl_languages();
		$this->assertContains( 'ar', $result );
	}

	public function test_get_direction_rtl(): void {
		$this->assertSame( 'rtl', SScribe_RTL_Helper::get_direction( 'ar' ) );
		$this->assertSame( 'rtl', SScribe_RTL_Helper::get_direction( 'he' ) );
	}

	public function test_get_direction_ltr(): void {
		$this->assertSame( 'ltr', SScribe_RTL_Helper::get_direction( 'en' ) );
		$this->assertSame( 'ltr', SScribe_RTL_Helper::get_direction( 'ja' ) );
	}

	public function test_get_alignment_rtl(): void {
		$this->assertSame( 'right', SScribe_RTL_Helper::get_alignment( 'ar' ) );
	}

	public function test_get_alignment_ltr(): void {
		$this->assertSame( 'left', SScribe_RTL_Helper::get_alignment( 'en' ) );
	}

	public function test_language_code_parsing(): void {
		$this->assertTrue( SScribe_RTL_Helper::is_rtl( 'ar_EG' ) );
		$this->assertTrue( SScribe_RTL_Helper::is_rtl( 'he_IL' ) );
		$this->assertFalse( SScribe_RTL_Helper::is_rtl( 'en_US' ) );
	}
}
