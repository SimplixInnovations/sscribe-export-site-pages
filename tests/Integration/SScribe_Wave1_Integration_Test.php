<?php
/**
 * SScribe Wave1 Integration Test
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-rtl-helper.php';
require_once SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-arabic-segmenter.php';
require_once SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-image-processor.php';

class SScribe_Wave1_Integration_Test extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['sscribe_test_http_response'] = array();
	}

	protected function tearDown(): void {
		$GLOBALS['sscribe_test_http_response'] = array();
		parent::tearDown();
	}

	public function test_font_helper_exists(): void {
		$this->assertTrue( class_exists( 'SScribe_Font_Helper' ), 'SScribe_Font_Helper class should exist' );
	}

	public function test_font_helper_returns_xbriyaz(): void {
		$path = SScribe_Font_Helper::get_arabic_font_path();
		$this->assertStringContainsString( 'XB Riyaz.ttf', $path, 'Font helper should return XB Riyaz' );
	}

	public function test_rtl_helper_arabic(): void {
		$this->assertTrue( SScribe_RTL_Helper::is_rtl( 'ar' ), 'Arabic should be RTL' );
		$this->assertEquals( 'rtl', SScribe_RTL_Helper::get_direction( 'ar' ) );
		$this->assertEquals( 'right', SScribe_RTL_Helper::get_alignment( 'ar' ) );
	}

	public function test_rtl_helper_hebrew(): void {
		$this->assertTrue( SScribe_RTL_Helper::is_rtl( 'he' ), 'Hebrew should be RTL' );
		$this->assertTrue( SScribe_RTL_Helper::is_rtl( 'he_IL' ), 'Hebrew locale should be RTL' );
	}

	public function test_rtl_helper_english(): void {
		$this->assertFalse( SScribe_RTL_Helper::is_rtl( 'en' ), 'English should not be RTL' );
		$this->assertEquals( 'ltr', SScribe_RTL_Helper::get_direction( 'en' ) );
		$this->assertEquals( 'left', SScribe_RTL_Helper::get_alignment( 'en' ) );
	}

	public function test_rtl_helper_get_languages(): void {
		$languages = SScribe_RTL_Helper::get_rtl_languages();
		$this->assertContains( 'ar', $languages );
		$this->assertContains( 'he', $languages );
		$this->assertContains( 'fa', $languages );
	}

	public function test_arabic_segmenter_count(): void {
		$arabic_text = 'مرحبا بالعالم هذا نص عربي';
		$count = SScribe_Arabic_Segmenter::count_words( $arabic_text, 'ar' );
		$this->assertGreaterThan( 0, $count, 'Arabic word count should be positive' );
	}

	public function test_arabic_segmenter_english(): void {
		$english_text = 'Hello world this is test';
		$count = SScribe_Arabic_Segmenter::count_words( $english_text, 'en' );
		$this->assertEquals( 5, $count, 'English word count should be 5' );
	}

	public function test_arabic_segmenter_empty(): void {
		$this->assertEquals( 0, SScribe_Arabic_Segmenter::count_words( '', 'ar' ) );
		$this->assertEquals( 0, SScribe_Arabic_Segmenter::count_words( '   ', 'ar' ) );
	}

	public function test_arabic_segmenter_reading_time(): void {
		$text = str_repeat( 'test ', 200 );
		$time = SScribe_Arabic_Segmenter::get_reading_time( $text, 'en' );
		$this->assertGreaterThanOrEqual( 1, $time, 'Reading time should be at least 1 minute' );
	}

	public function test_image_processor_invalid_url(): void {
		$result = SScribe_Image_Processor::download_and_optimize( 'invalid-url' );
		$this->assertFalse( $result, 'Invalid URL should return false' );
	}

	public function test_image_processor_empty_url(): void {
		$result = SScribe_Image_Processor::download_and_optimize( '' );
		$this->assertFalse( $result, 'Empty URL should return false' );
	}

	public function test_image_processor_rejects_offsite_urls(): void {
		$result = SScribe_Image_Processor::download_and_optimize( 'https://evil.example/image.jpg' );
		$this->assertFalse( $result );
	}

	public function test_image_processor_rejects_invalid_content_type(): void {
		$GLOBALS['sscribe_test_http_response'] = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'text/html; charset=utf-8' ),
			'body'     => '<html>not an image</html>',
		);

		$result = SScribe_Image_Processor::download_and_optimize( 'https://example.org/wp-content/uploads/test.jpg' );

		$this->assertFalse( $result );
	}

	public function test_image_processor_downloads_same_host_image_with_safe_http_options(): void {
		$image_data = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wn8M6kAAAAASUVORK5CYII=' );
		$this->assertNotFalse( $image_data );

		$GLOBALS['sscribe_test_http_response'] = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'image/png' ),
			'body'     => $image_data,
		);

		$result = SScribe_Image_Processor::download_and_optimize( 'https://example.org/wp-content/uploads/test-image' );

		$this->assertIsString( $result );
		$this->assertFileExists( $result );
		$this->assertSame( $image_data, file_get_contents( $result ) );
		$this->assertSame( 'https://example.org/wp-content/uploads/test-image', $GLOBALS['sscribe_test_http_response']['requested_url'] );
		$this->assertTrue( $GLOBALS['sscribe_test_http_response']['request_args']['reject_unsafe_urls'] );
		$this->assertArrayNotHasKey( 'sslverify', $GLOBALS['sscribe_test_http_response']['request_args'] );

		SScribe_Image_Processor::cleanup( $result );
	}

	public function test_file_naming_requires_wordpress(): void {
		if ( ! function_exists( 'get_bloginfo' ) ) {
			$this->markTestSkipped( 'WordPress not loaded - skipping file naming test' );
		}

		$page_data = array(
			'id'       => 123,
			'title'    => 'Test Page',
			'language' => 'ar',
		);

		$filename = SScribe_Exporter_Factory::build_filename( $page_data, 1, 10, 'docx' );

		$this->assertMatchesRegularExpression(
			'/^P\d{3}-.+?-AR\.docx$/',
			$filename,
			'Filename should match format: P{number}-{title}-{lang}.docx'
		);
		$this->assertStringContainsString( '-AR.', $filename );
		$this->assertStringEndsWith( '.docx', $filename );
	}
}
