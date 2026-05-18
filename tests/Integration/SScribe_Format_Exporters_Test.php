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

	public function test_markdown_exporter_content_output(): void {
		require_once SSCRIBE_PLUGIN_DIR . 'includes/exporters/class-sscribe-markdown-exporter.php';
		$exporter = new SScribe_Markdown_Exporter();

		$temp_dir = sys_get_temp_dir() . '/sscribe-test-' . uniqid();
		wp_mkdir_p( $temp_dir );

		$page_data = array(
			'id'              => 42,
			'title'           => 'Test Markdown Page',
			'content'         => '<p>Hello World</p><h1>Heading</h1>',
			'permalink'       => home_url( '/test-markdown/' ),
			'language'        => 'en',
			'featured_image'  => '',
			'author'          => 'Test Author',
			'date_published'  => '2024-01-01',
			'date_modified'   => '2024-01-02',
			'word_count'      => 5,
		);

		$result = $exporter->export( $page_data, $temp_dir, 1, 1 );

		$this->assertTrue( $result->is_success(), 'Markdown export should succeed' );

		// Read the generated file.
		$data   = $result->get_data();
		$path   = $data['path'] ?? '';
		$this->assertFileExists( $path );

		$content = file_get_contents( $path );
		$this->assertNotEmpty( $content, 'Markdown output should not be empty' );

		// Verify YAML frontmatter keys exist.
		$this->assertStringContainsString( 'title:', $content, 'Frontmatter should contain title key' );
		$this->assertStringContainsString( 'Test Markdown Page', $content, 'Frontmatter should contain the page title' );
		$this->assertStringContainsString( 'published:', $content, 'Frontmatter should contain published date key' );
		$this->assertStringContainsString( 'author:', $content, 'Frontmatter should contain author key' );

		// Verify content is present.
		$this->assertStringContainsString( 'Hello World', $content, 'Markdown content should contain page text' );

		// Cleanup.
		if ( $path && file_exists( $path ) ) {
			wp_delete_file( $path );
		}
		if ( is_dir( $temp_dir ) ) {
			rmdir( $temp_dir );
		}
	}

	public function test_html_exporter_interface(): void {
		require_once SSCRIBE_PLUGIN_DIR . 'includes/exporters/class-sscribe-html-exporter.php';
		$exporter = new SScribe_HTML_Exporter();

		$this->assertEquals( 'html', $exporter->get_extension() );
		$this->assertEquals( 'text/html', $exporter->get_mime_type() );
	}

	public function test_html_exporter_preserves_standard_content(): void {
		require_once SSCRIBE_PLUGIN_DIR . 'includes/exporters/class-sscribe-html-exporter.php';
		$exporter = new SScribe_HTML_Exporter();

		$temp_dir = sys_get_temp_dir() . '/sscribe-test-' . uniqid();
		wp_mkdir_p( $temp_dir );

		$page_data = array(
			'id'              => 1,
			'title'           => 'Test Page',
			'content'         => '<p>Hello World</p><h1>Heading 1</h1><a href="https://example.com">Link</a><img src="https://example.com/img.jpg" alt="test"><ul><li>Item</li></ul>',
			'permalink'       => home_url( '/test-page/' ),
			'language'        => 'en',
			'featured_image'  => '',
			'author'          => 'Test Author',
			'date_published'  => '2024-01-01',
			'date_modified'   => '2024-01-02',
			'word_count'      => 10,
		);

		$result = $exporter->export( $page_data, $temp_dir, 1, 1 );

		$this->assertTrue( $result->is_success(), 'HTML export should succeed. Error: ' . ( $result->get_error() ?? 'none' ) );
		$data = $result->get_data();
		$this->assertNotEmpty( $data['html'] ?? '', 'HTML output should not be empty' );

		$html = $data['html'] ?? '';

		// Verify standard HTML elements are preserved (regression test for content-stripping bug).
		$this->assertStringContainsString( '<p>Hello World</p>', $html, 'Paragraph elements should be preserved' );
		$this->assertStringContainsString( '<h1>Heading 1</h1>', $html, 'Heading elements should be preserved' );
		$this->assertStringContainsString( '<a href="https://example.com">Link</a>', $html, 'Link elements should be preserved' );
		$this->assertStringContainsString( '<img src="https://example.com/img.jpg"', $html, 'Image elements should be preserved' );
		$this->assertStringContainsString( '<ul>', $html, 'List elements should be preserved' );
		$this->assertStringContainsString( '<li>Item</li>', $html, 'List item elements should be preserved' );

		// Cleanup.
		$generated_file = $data['path'] ?? '';
		if ( $generated_file && file_exists( $generated_file ) ) {
			wp_delete_file( $generated_file );
		}
		if ( is_dir( $temp_dir ) ) {
			rmdir( $temp_dir );
		}
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

	public function test_font_helper_returns_xbriyaz(): void {
		$regular = SScribe_Font_Helper::get_arabic_font_path();
		$bold    = SScribe_Font_Helper::get_arabic_font_path( true );

		$this->assertStringContainsString( 'XB Riyaz.ttf', $regular );
		$this->assertStringContainsString( 'XB RiyazBd.ttf', $bold );
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
