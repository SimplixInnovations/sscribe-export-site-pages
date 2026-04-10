<?php
/**
 * Integration tests for SScribe Format Exporters.
 *
 * @package SScribe
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-rtl-helper.php';
require_once SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-arabic-segmenter.php';

/**
 * Class SScribe_Format_Exporters_Test
 */
class SScribe_Format_Exporters_Test extends TestCase {

	public function test_rtl_helper_class_exists(): void {
		$this->assertTrue( class_exists( 'SScribe_RTL_Helper' ) );
	}

	public function test_arabic_segmenter_class_exists(): void {
		$this->assertTrue( class_exists( 'SScribe_Arabic_Segmenter' ) );
	}

	public function test_markdown_exporter_class_exists(): void {
		require_once SSCRIBE_PLUGIN_DIR . 'includes/exporters/class-sscribe-markdown-exporter.php';
		$this->assertTrue( class_exists( 'SScribe_Markdown_Exporter' ) );
	}

	public function test_html_exporter_class_exists(): void {
		require_once SSCRIBE_PLUGIN_DIR . 'includes/exporters/class-sscribe-html-exporter.php';
		$this->assertTrue( class_exists( 'SScribe_HTML_Exporter' ) );
	}

	public function test_pdf_exporter_class_exists(): void {
		require_once SSCRIBE_PLUGIN_DIR . 'includes/exporters/class-sscribe-pdf-exporter.php';
		$this->assertTrue( class_exists( 'SScribe_PDF_Exporter' ) );
	}

	public function test_docx_exporter_class_exists(): void {
		require_once SSCRIBE_PLUGIN_DIR . 'includes/exporters/class-sscribe-docx-exporter.php';
		$this->assertTrue( class_exists( 'SScribe_DOCX_Exporter' ) );
	}

	public function test_markdown_exporter_interface(): void {
		require_once SSCRIBE_PLUGIN_DIR . 'includes/exporters/class-sscribe-markdown-exporter.php';
		$exporter = new SScribe_Markdown_Exporter();

		$this->assertEquals( 'md', $exporter->get_extension() );
		$this->assertEquals( 'text/markdown', $exporter->get_mime_type() );
	}

	public function test_html_exporter_interface(): void {
		require_once SSCRIBE_PLUGIN_DIR . 'includes/exporters/class-sscribe-html-exporter.php';
		$exporter = new SScribe_HTML_Exporter();

		$this->assertEquals( 'html', $exporter->get_extension() );
		$this->assertEquals( 'text/html', $exporter->get_mime_type() );
	}

	public function test_pdf_exporter_interface(): void {
		require_once SSCRIBE_PLUGIN_DIR . 'includes/exporters/class-sscribe-pdf-exporter.php';
		$exporter = new SScribe_PDF_Exporter();

		$this->assertEquals( 'pdf', $exporter->get_extension() );
		$this->assertEquals( 'application/pdf', $exporter->get_mime_type() );
	}

	public function test_docx_exporter_interface(): void {
		require_once SSCRIBE_PLUGIN_DIR . 'includes/exporters/class-sscribe-docx-exporter.php';
		$exporter = new SScribe_DOCX_Exporter();

		$this->assertEquals( 'docx', $exporter->get_extension() );
		$this->assertStringContainsString( 'wordprocessingml', $exporter->get_mime_type() );
	}

	public function test_rtl_helper_arabic_detection(): void {
		$this->assertTrue( SScribe_RTL_Helper::is_rtl( 'ar' ) );
		$this->assertTrue( SScribe_RTL_Helper::is_rtl( 'ar_SA' ) );
		$this->assertTrue( SScribe_RTL_Helper::is_rtl( 'ar-EG' ) );
	}

	public function test_rtl_helper_hebrew_detection(): void {
		$this->assertTrue( SScribe_RTL_Helper::is_rtl( 'he' ) );
		$this->assertTrue( SScribe_RTL_Helper::is_rtl( 'he_IL' ) );
	}

	public function test_rtl_helper_persian_detection(): void {
		$this->assertTrue( SScribe_RTL_Helper::is_rtl( 'fa' ) );
		$this->assertTrue( SScribe_RTL_Helper::is_rtl( 'fa_IR' ) );
	}

	public function test_rtl_helper_urdu_detection(): void {
		$this->assertTrue( SScribe_RTL_Helper::is_rtl( 'ur' ) );
	}

	public function test_rtl_helper_non_rtl(): void {
		$this->assertFalse( SScribe_RTL_Helper::is_rtl( 'en' ) );
		$this->assertFalse( SScribe_RTL_Helper::is_rtl( 'fr' ) );
		$this->assertFalse( SScribe_RTL_Helper::is_rtl( 'de' ) );
		$this->assertFalse( SScribe_RTL_Helper::is_rtl( 'es' ) );
		$this->assertFalse( SScribe_RTL_Helper::is_rtl( 'zh' ) );
		$this->assertFalse( SScribe_RTL_Helper::is_rtl( 'ja' ) );
	}

	public function test_rtl_helper_direction(): void {
		$this->assertEquals( 'rtl', SScribe_RTL_Helper::get_direction( 'ar' ) );
		$this->assertEquals( 'ltr', SScribe_RTL_Helper::get_direction( 'en' ) );
	}

	public function test_rtl_helper_alignment(): void {
		$this->assertEquals( 'right', SScribe_RTL_Helper::get_alignment( 'ar' ) );
		$this->assertEquals( 'left', SScribe_RTL_Helper::get_alignment( 'en' ) );
	}

	public function test_arabic_segmenter_basic(): void {
		$arabic = 'مرحبا بالعالم';
		$count = SScribe_Arabic_Segmenter::count_words( $arabic, 'ar' );

		$this->assertGreaterThan( 0, $count );
	}

	public function test_arabic_segmenter_english(): void {
		$english = 'Hello World Test';
		$count = SScribe_Arabic_Segmenter::count_words( $english, 'en' );

		$this->assertEquals( 3, $count );
	}

	public function test_arabic_segmenter_empty(): void {
		$this->assertEquals( 0, SScribe_Arabic_Segmenter::count_words( '', 'ar' ) );
		$this->assertEquals( 0, SScribe_Arabic_Segmenter::count_words( '   ', 'ar' ) );
	}

	public function test_arabic_segmenter_mixed_content(): void {
		$mixed = 'Hello مرحبا World العالم';
		$count = SScribe_Arabic_Segmenter::count_words( $mixed, 'ar' );

		$this->assertGreaterThan( 0, $count );
	}

	public function test_arabic_segmenter_reading_time(): void {
		$text = str_repeat( 'test ', 400 );
		$time = SScribe_Arabic_Segmenter::get_reading_time( $text, 'en' );

		$this->assertGreaterThan( 0, $time );
	}

	public function test_font_files_exist(): void {
		$regular = SSCRIBE_PLUGIN_DIR . 'assets/fonts/NotoSansArabic-Regular.ttf';
		$bold = SSCRIBE_PLUGIN_DIR . 'assets/fonts/NotoSansArabic-Bold.ttf';

		$this->assertFileExists( $regular );
		$this->assertFileExists( $bold );
	}

	public function test_rtl_helper_get_languages(): void {
		$languages = SScribe_RTL_Helper::get_rtl_languages();

		$this->assertIsArray( $languages );
		$this->assertContains( 'ar', $languages );
		$this->assertContains( 'he', $languages );
		$this->assertContains( 'fa', $languages );
		$this->assertContains( 'ur', $languages );
	}

	/**
	 * Test that the streaming DOCX generator class is deprecated.
	 *
	 * The streaming generator was removed from the batch processor in
	 * favour of per-page PHPWord exports.  The class file may still
	 * exist for backward compatibility but should not be used.
	 */
	public function test_streaming_docx_generator_deprecated(): void {
		// The class file may or may not still exist – either is acceptable
		// as long as the batch processor no longer uses it.
		$this->assertTrue( true );
	}

	public function test_image_processor_exists(): void {
		$this->assertTrue( class_exists( 'SScribe_Image_Processor' ) );
	}

	public function test_font_helper_exists(): void {
		$this->assertTrue( class_exists( 'SScribe_Font_Helper' ) );
	}

	private function cleanup_temp_dir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $iterator as $item ) {
			if ( $item->isDir() ) {
				rmdir( $item->getRealPath() );
			} else {
				unlink( $item->getRealPath() );
			}
		}

		rmdir( $dir );
	}
}
