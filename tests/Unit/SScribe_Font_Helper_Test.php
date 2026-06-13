<?php
/**
 * SScribe Font Helper Unit Test
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

class SScribe_Font_Helper_Test extends TestCase {

	public function test_get_arabic_font_path_regular(): void {
		$path = \SScribe_Font_Helper::get_arabic_font_path();
		$this->assertStringContainsString( 'Amiri-Regular.ttf', $path );
		$this->assertStringContainsString( 'assets/fonts/amiri/', $path );
	}

	public function test_get_arabic_font_path_bold(): void {
		$path = \SScribe_Font_Helper::get_arabic_font_path( true );
		$this->assertStringContainsString( 'Amiri-Bold.ttf', $path );
	}

	public function test_get_arabic_font_path_with_plugin_dir(): void {
		$dir = defined( 'SSCRIBE_PLUGIN_DIR' ) ? SSCRIBE_PLUGIN_DIR : '';
		$path = \SScribe_Font_Helper::get_arabic_font_path();
		$this->assertStringStartsWith( $dir, $path );
	}

	public function test_font_exists_returns_bool(): void {
		$result = \SScribe_Font_Helper::font_exists();
		$this->assertIsBool( $result );
	}

	public function test_font_exists_bold_returns_bool(): void {
		$result = \SScribe_Font_Helper::font_exists( true );
		$this->assertIsBool( $result );
	}

	public function test_get_arabic_font_url_returns_empty(): void {
		$url = \SScribe_Font_Helper::get_arabic_font_url();
		$this->assertEquals( '', $url );
	}

	public function test_get_arabic_font_url_bold_returns_empty(): void {
		$url = \SScribe_Font_Helper::get_arabic_font_url( true );
		$this->assertEquals( '', $url );
	}
}
