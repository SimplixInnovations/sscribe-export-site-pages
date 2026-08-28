<?php
/**
 * SScribe Exporter Unit Test
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SScribe_Exporter;
use SScribe_Content_Parser;

class SScribe_Exporter_Test extends TestCase {

	private ?SScribe_Exporter $exporter;
	private ?SScribe_Content_Parser $parser;
	private string $temp_dir;

	protected function setUp(): void {
		parent::setUp();
		$this->parser    = new SScribe_Content_Parser();
		$this->exporter = new SScribe_Exporter( $this->parser );
		$this->temp_dir = \SScribe_Private_Storage::get_subdirectory( 'test-exporter-' . uniqid() );
		wp_mkdir_p( $this->temp_dir );
	}

	protected function tearDown(): void {
		if ( is_dir( $this->temp_dir ) ) {
			array_map( 'unlink', glob( $this->temp_dir . '/*.docx' ) ?: array() );
			rmdir( $this->temp_dir );
		}
		$this->exporter = null;
		$this->parser   = null;
		parent::tearDown();
	}

	private function get_sample_page_data(): array {
		return array(
			'title'          => 'Test Page',
			'content'        => '<p>Hello World</p>',
			'url'            => 'https://example.com/test',
			'permalink'      => 'https://example.com/test',
			'author'         => 'Test Author',
			'date_published' => '2024-01-15',
			'date_modified'  => '2024-02-20',
			'word_count'     => 50,
		);
	}

	public function test_exporter_can_be_instantiated(): void {
		$this->assertInstanceOf( SScribe_Exporter::class, $this->exporter );
	}

	public function test_exporter_accepts_null_parser(): void {
		$exporter = new SScribe_Exporter( null );
		$this->assertInstanceOf( SScribe_Exporter::class, $exporter );
	}

	public function test_generate_docx_returns_false_for_empty_data(): void {
		$result = $this->exporter->generate_docx( array(), $this->temp_dir );
		$this->assertFalse( $result );
	}

	public function test_generate_docx_returns_false_for_invalid_dir(): void {
		$page_data = $this->get_sample_page_data();
		$result    = $this->exporter->generate_docx( $page_data, '/invalid/path/that/does/not/exist' );
		$this->assertFalse( $result );
	}

	public function test_generate_docx_succeeds_with_minimal_data(): void {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive extension not available' );
		}
		$page_data = $this->get_sample_page_data();
		$result    = $this->exporter->generate_docx( $page_data, $this->temp_dir );
		$this->assertIsString( $result );
		$this->assertFileExists( $result );
		if ( file_exists( $result ) ) {
			unlink( $result );
		}
	}

	public function test_generate_docx_normalizes_malformed_filtered_metadata(): void {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive extension not available' );
		}

		$page_data = array(
			'id'          => array( 42 ),
			'title'       => array( 'invalid' ),
			'content'     => '<p>Safe content</p>',
			'permalink'   => new \stdClass(),
			'language'    => array( 'ar' ),
			'seo'         => 'invalid',
			'breadcrumbs' => array( 'invalid', array( 'title' => array( 'bad' ) ) ),
			'children'    => array( 'invalid', array( 'title' => array(), 'url' => array() ) ),
		);

		$result = $this->exporter->generate_docx( $page_data, $this->temp_dir );

		$this->assertIsString( $result );
		$this->assertFileExists( $result );
		if ( file_exists( $result ) ) {
			unlink( $result );
		}
	}

	public function test_generate_docx_with_index_and_total(): void {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive extension not available' );
		}
		$page_data = $this->get_sample_page_data();
		$result    = $this->exporter->generate_docx( $page_data, $this->temp_dir, 1, 3 );
		$this->assertIsString( $result );
		$this->assertFileExists( $result );
		if ( file_exists( $result ) ) {
			unlink( $result );
		}
	}

	public function test_generate_docx_handles_rtl_content(): void {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive extension not available' );
		}
		$page_data = array_merge( $this->get_sample_page_data(), array(
			'title'   => 'Special & Characters < > " \'',
			'content' => '<p>Content with &amp; entities & "quotes"</p>',
			'url'     => 'https://example.com/special',
		) );
		$result    = $this->exporter->generate_docx( $page_data, $this->temp_dir );
		$this->assertIsString( $result );
		if ( file_exists( $result ) ) {
			unlink( $result );
		}
	}

	public function test_generate_docx_handles_images(): void {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive extension not available' );
		}
		$page_data = array_merge( $this->get_sample_page_data(), array(
			'title'   => 'Page with Image',
			'content' => '<p>Text <img src="https://example.com/image.jpg" alt="test" /></p>',
			'url'     => 'https://example.com/image-page',
		) );
		$result    = $this->exporter->generate_docx( $page_data, $this->temp_dir );
		$this->assertIsString( $result );
		if ( file_exists( $result ) ) {
			unlink( $result );
		}
	}

	public function test_generate_docx_handles_tables(): void {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive extension not available' );
		}
		$page_data = array_merge( $this->get_sample_page_data(), array(
			'title'   => 'Page with Table',
			'content' => '<table><tr><th>Header</th></tr><tr><td>Cell</td></tr></table>',
			'url'     => 'https://example.com/table-page',
		) );
		$result    = $this->exporter->generate_docx( $page_data, $this->temp_dir );
		$this->assertIsString( $result );
		if ( file_exists( $result ) ) {
			unlink( $result );
		}
	}

	public function test_generate_docx_handles_lists(): void {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive extension not available' );
		}
		$page_data = array_merge( $this->get_sample_page_data(), array(
			'title'   => 'Page with List',
			'content' => '<ul><li>Item 1</li><li>Item 2</li></ul>',
			'url'     => 'https://example.com/list-page',
		) );
		$result    = $this->exporter->generate_docx( $page_data, $this->temp_dir );
		$this->assertIsString( $result );
		if ( file_exists( $result ) ) {
			unlink( $result );
		}
	}

	public function test_generate_docx_with_empty_title(): void {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive extension not available' );
		}
		$page_data = array_merge( $this->get_sample_page_data(), array(
			'title'   => 'اختبار الصفحة',
			'content' => '<p>مرحبا بالعالم</p>',
			'url'     => 'https://example.com/test-ar',
		) );
		$result    = $this->exporter->generate_docx( $page_data, $this->temp_dir );
		$this->assertIsString( $result );
		if ( file_exists( $result ) ) {
			unlink( $result );
		}
	}

	public function test_generate_docx_with_special_characters(): void {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive extension not available' );
		}
		$page_data = array_merge( $this->get_sample_page_data(), array(
			'title'   => '',
			'content' => '<p>Content only</p>',
			'url'     => 'https://example.com/no-title',
		) );
		$result    = $this->exporter->generate_docx( $page_data, $this->temp_dir );
		$this->assertIsString( $result );
		if ( file_exists( $result ) ) {
			unlink( $result );
		}
	}

	public function test_generate_docx_with_long_content(): void {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive extension not available' );
		}
		$long_content = str_repeat( '<p>This is a paragraph of content.</p>', 50 );
		$page_data    = array_merge( $this->get_sample_page_data(), array(
			'title'   => 'Long Page',
			'content' => $long_content,
			'url'     => 'https://example.com/long',
		) );
		$result       = $this->exporter->generate_docx( $page_data, $this->temp_dir );
		$this->assertIsString( $result );
		if ( file_exists( $result ) ) {
			unlink( $result );
		}
	}

	public function test_generate_docx_sets_last_error_on_failure(): void {
		$result = $this->exporter->generate_docx( array(), $this->temp_dir );
		$this->assertFalse( $result );
	}

	public function test_is_rtl_document_detects_rtl(): void {
		$rtl_data   = array( 'title' => 'مرحبا' );
		$ltr_data   = array( 'title' => 'Hello' );
		$ar_data    = array( 'title' => 'Test', 'language' => 'ar' );

		$method = new \ReflectionMethod( SScribe_Exporter::class, 'is_rtl_document' );

		$this->assertTrue( $method->invoke( $this->exporter, $ar_data ) );
		$this->assertFalse( $method->invoke( $this->exporter, $ltr_data ) );
	}

	public function test_safe_text_strips_invalid_xml_chars(): void {
		$method = new \ReflectionMethod( SScribe_Exporter::class, 'safe_text' );

		$input = "Hello\x00World\x07Test";
		$result = $method->invoke( $this->exporter, $input );
		$this->assertStringNotContainsString( "\x00", $result );
		$this->assertStringNotContainsString( "\x07", $result );
		$this->assertStringContainsString( 'Hello', $result );
		$this->assertStringContainsString( 'World', $result );
	}

	public function test_safe_text_handles_bmp_chars(): void {
		$method = new \ReflectionMethod( SScribe_Exporter::class, 'safe_text' );

		$input = "Test\xFFFE\xFFFFMore";
		$result = $method->invoke( $this->exporter, $input );
		$this->assertStringNotContainsString( "\xFFFE", $result );
		$this->assertStringNotContainsString( "\xFFFF", $result );
	}

	public function test_safe_text_handles_surrogates(): void {
		$method = new \ReflectionMethod( SScribe_Exporter::class, 'safe_text' );

		$input = "Test\xED\xA0\x80More";
		$result = $method->invoke( $this->exporter, $input );
		$this->assertStringNotContainsString( "\xED", $result );
	}

	public function test_safe_text_normalizes_line_endings(): void {
		$method = new \ReflectionMethod( SScribe_Exporter::class, 'safe_text' );

		$input = "Line1\r\nLine2\rLine3\nLine4";
		$result = $method->invoke( $this->exporter, $input );
		$this->assertStringNotContainsString( "\r\n", $result );
		$this->assertStringNotContainsString( "\r", $result );
		$this->assertStringContainsString( "\nLine2", $result );
	}

	public function test_safe_text_truncates_long_strings_without_spaces(): void {
		$method = new \ReflectionMethod( SScribe_Exporter::class, 'safe_text' );

		// safe_text truncates strings > 2048 chars with no spaces.
		$long_string = str_repeat( 'A', 3000 );
		$result = $method->invoke( $this->exporter, $long_string );
		$this->assertLessThanOrEqual( 2048, mb_strlen( $result, 'UTF-8' ) );
		$this->assertGreaterThan( 0, mb_strlen( $result, 'UTF-8' ) );
	}

	/**
	 * Regression: a 3000-char Arabic word (no spaces, no ASCII) must NOT be
	 * truncated — Arabic does not use spaces and the 2048-char safety net
	 * is for machine payloads, not human non-Latin text.
	 */
	public function test_safe_text_does_not_truncate_long_arabic_strings(): void {
		$method = new \ReflectionMethod( SScribe_Exporter::class, 'safe_text' );

		// Arabic letter "ا" (U+0627), 3000 copies — no spaces, no Latin chars.
		$long_arabic = str_repeat( 'ا', 3000 );
		$result      = $method->invoke( $this->exporter, $long_arabic );

		$this->assertSame( 3000, mb_strlen( $result, 'UTF-8' ) );
	}

	/**
	 * Regression: a 3000-char CJK string (no spaces, no ASCII) must NOT be
	 * truncated.
	 */
	public function test_safe_text_does_not_truncate_long_cjk_strings(): void {
		$method = new \ReflectionMethod( SScribe_Exporter::class, 'safe_text' );

		$long_cjk = str_repeat( '中', 3000 );
		$result   = $method->invoke( $this->exporter, $long_cjk );

		$this->assertSame( 3000, mb_strlen( $result, 'UTF-8' ) );
	}

	public function test_safe_text_does_not_truncate_normal_strings(): void {
		$method = new \ReflectionMethod( SScribe_Exporter::class, 'safe_text' );

		$input = 'Normal string with spaces that should not be truncated';
		$result = $method->invoke( $this->exporter, $input );
		$this->assertEquals( $input, $result );
	}

	public function test_safe_text_handles_empty_string(): void {
		$method = new \ReflectionMethod( SScribe_Exporter::class, 'safe_text' );

		$result = $method->invoke( $this->exporter, '' );
		$this->assertEquals( '', $result );
	}

	public function test_safe_text_handles_mb_convert_encoding_failure(): void {
		$method = new \ReflectionMethod( SScribe_Exporter::class, 'safe_text' );

		$input = "Valid UTF-8 text ©®™";
		$result = $method->invoke( $this->exporter, $input );
		$this->assertStringContainsString( '©', $result );
	}

	public function test_validate_url_returns_empty_for_invalid_scheme(): void {
		$method = new \ReflectionMethod( SScribe_Exporter::class, 'validate_url' );

		$result = $method->invoke( $this->exporter, 'ftp://example.com/file' );
		$this->assertEquals( '', $result );

		$result = $method->invoke( $this->exporter, 'javascript:alert(1)' );
		$this->assertEquals( '', $result );
	}

	public function test_validate_url_accepts_http_https_mailto_tel(): void {
		$method = new \ReflectionMethod( SScribe_Exporter::class, 'validate_url' );

		$this->assertNotEmpty( $method->invoke( $this->exporter, 'https://example.com/page' ) );
		$this->assertNotEmpty( $method->invoke( $this->exporter, 'http://example.com/page' ) );
		$this->assertNotEmpty( $method->invoke( $this->exporter, 'mailto:test@example.com' ) );
		$this->assertNotEmpty( $method->invoke( $this->exporter, 'tel:+1234567890' ) );
	}

	public function test_validate_url_returns_anchor_unmodified(): void {
		$method = new \ReflectionMethod( SScribe_Exporter::class, 'validate_url' );

		$result = $method->invoke( $this->exporter, '#section' );
		$this->assertEquals( '#section', $result );
	}

	public function test_validate_url_handles_relative_paths(): void {
		$method = new \ReflectionMethod( SScribe_Exporter::class, 'validate_url' );

		$result = $method->invoke( $this->exporter, '/about-us' );
		$this->assertNotEmpty( $result );
		$this->assertStringContainsString( '/about-us', $result );
	}

	public function test_validate_url_returns_empty_on_parse_failure(): void {
		$method = new \ReflectionMethod( SScribe_Exporter::class, 'validate_url' );

		$result = $method->invoke( $this->exporter, '' );
		$this->assertEquals( '', $result );
	}

	public function test_with_complex_script_adds_rtl_props_when_rtl(): void {
		$exporter = new SScribe_Exporter( $this->parser );
		$method = new \ReflectionMethod( SScribe_Exporter::class, 'with_complex_script' );

		$reflector = new \ReflectionClass( $exporter );
		$prop = $reflector->getProperty( 'is_rtl' );

		$prop->setValue( $exporter, true );

		$font_def = array( 'name' => 'Arial', 'size' => 11 );
		$result = $method->invoke( $exporter, $font_def );

		$this->assertArrayHasKey( 'complexScript', $result );
		$this->assertArrayHasKey( 'rtl', $result );
		$this->assertTrue( $result['complexScript'] );
		$this->assertTrue( $result['rtl'] );
	}

	public function test_with_complex_script_preserves_existing_props(): void {
		$exporter = new SScribe_Exporter( $this->parser );
		$method = new \ReflectionMethod( SScribe_Exporter::class, 'with_complex_script' );

		$reflector = new \ReflectionClass( $exporter );
		$prop = $reflector->getProperty( 'is_rtl' );

		$prop->setValue( $exporter, true );

		$font_def = array( 'name' => 'Arial', 'size' => 11, 'complexScript' => false );
		$result = $method->invoke( $exporter, $font_def );

		$this->assertEquals( false, $result['complexScript'] );
		$this->assertArrayHasKey( 'rtl', $result );
	}

	public function test_get_para_style_adds_bidi_for_rtl(): void {
		$exporter = new SScribe_Exporter( $this->parser );
		$method = new \ReflectionMethod( SScribe_Exporter::class, 'get_para_style' );

		$reflector = new \ReflectionClass( $exporter );
		$prop = $reflector->getProperty( 'is_rtl' );

		$prop->setValue( $exporter, true );

		$result = $method->invoke( $exporter, array() );

		$this->assertArrayHasKey( 'bidi', $result );
		$this->assertTrue( $result['bidi'] );
	}

	public function test_get_para_style_sets_alignment_for_rtl(): void {
		$exporter = new SScribe_Exporter( $this->parser );
		$method = new \ReflectionMethod( SScribe_Exporter::class, 'get_para_style' );

		$reflector = new \ReflectionClass( $exporter );
		$prop = $reflector->getProperty( 'is_rtl' );

		$prop->setValue( $exporter, true );

		$result = $method->invoke( $exporter, array() );

		$this->assertArrayHasKey( 'alignment', $result );
	}

	public function test_get_para_style_preserves_existing_alignment(): void {
		$exporter = new SScribe_Exporter( $this->parser );
		$method = new \ReflectionMethod( SScribe_Exporter::class, 'get_para_style' );

		$reflector = new \ReflectionClass( $exporter );
		$prop = $reflector->getProperty( 'is_rtl' );

		$prop->setValue( $exporter, true );

		$base_style = array( 'alignment' => 'center' );
		$result = $method->invoke( $exporter, $base_style );

		$this->assertEquals( 'center', $result['alignment'] );
	}

	public function test_get_section_settings_returns_layout_config(): void {
		$method = new \ReflectionMethod( SScribe_Exporter::class, 'get_section_settings' );

		$result = $method->invoke( $this->exporter, false );

		$this->assertArrayHasKey( 'pageSizeW', $result );
		$this->assertArrayHasKey( 'pageSizeH', $result );
		$this->assertArrayHasKey( 'marginTop', $result );
		$this->assertArrayHasKey( 'marginBottom', $result );
		$this->assertArrayHasKey( 'marginLeft', $result );
		$this->assertArrayHasKey( 'marginRight', $result );
	}

	public function test_get_section_settings_adds_bidi_when_rtl(): void {
		$exporter = new SScribe_Exporter( $this->parser );
		$method = new \ReflectionMethod( SScribe_Exporter::class, 'get_section_settings' );

		$reflector = new \ReflectionClass( $exporter );
		$prop = $reflector->getProperty( 'is_rtl' );

		$prop->setValue( $exporter, true );

		$result = $method->invoke( $exporter, true );

		$this->assertArrayHasKey( 'bidi', $result );
		$this->assertTrue( $result['bidi'] );
	}

	public function test_set_document_properties_sets_metadata(): void {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive extension not available' );
		}

		$method = new \ReflectionMethod( SScribe_Exporter::class, 'set_document_properties' );

		$php_word = new \SScribeVendor\PhpOffice\PhpWord\PhpWord();
		$page_data = array(
			'title'    => 'Test Title',
			'author'   => 'Test Author',
			'permalink' => 'https://example.com/test',
		);

		$method->invoke( $this->exporter, $php_word, $page_data );

		$properties = $php_word->getDocInfo();
		$this->assertEquals( 'Test Author', $properties->getLastModifiedBy() );
	}

	public function test_define_styles_does_not_crash(): void {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive extension not available' );
		}

		$method = new \ReflectionMethod( SScribe_Exporter::class, 'define_styles' );

		$php_word = new \SScribeVendor\PhpOffice\PhpWord\PhpWord();
		$method->invoke( $this->exporter, $php_word );

		$this->assertTrue( true );
	}

	public function test_generate_docx_with_seo_data(): void {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive extension not available' );
		}

		$page_data = array_merge( $this->get_sample_page_data(), array(
'seo' => array(
			'meta_title'       => 'SEO Title',
			'meta_description' => 'SEO Description',
			'focus_keyword'    => 'keyword1, keyword2',
		),
		) );
		$result = $this->exporter->generate_docx( $page_data, $this->temp_dir );
		$this->assertIsString( $result );
		if ( file_exists( $result ) ) {
			unlink( $result );
		}
	}

	public function test_generate_docx_with_breadcrumbs(): void {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive extension not available' );
		}

		$page_data = array_merge( $this->get_sample_page_data(), array(
			'breadcrumbs' => array(
				array( 'title' => 'Home', 'url' => 'https://example.com/' ),
				array( 'title' => 'Category', 'url' => 'https://example.com/category' ),
				array( 'title' => 'Current', 'url' => 'https://example.com/page' ),
			),
		) );
		$result = $this->exporter->generate_docx( $page_data, $this->temp_dir );
		$this->assertIsString( $result );
		if ( file_exists( $result ) ) {
			unlink( $result );
		}
	}

	public function test_generate_docx_with_children(): void {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive extension not available' );
		}

		$page_data = array_merge( $this->get_sample_page_data(), array(
			'children' => array(
				array( 'title' => 'Child Page 1', 'url' => 'https://example.com/child1' ),
				array( 'title' => 'Child Page 2', 'url' => 'https://example.com/child2' ),
			),
		) );
		$result = $this->exporter->generate_docx( $page_data, $this->temp_dir );
		$this->assertIsString( $result );
		if ( file_exists( $result ) ) {
			unlink( $result );
		}
	}

	public function test_generate_docx_with_blockquote(): void {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive extension not available' );
		}

		$page_data = array_merge( $this->get_sample_page_data(), array(
			'content' => '<blockquote><p>Quoted text here</p></blockquote>',
		) );
		$result = $this->exporter->generate_docx( $page_data, $this->temp_dir );
		$this->assertIsString( $result );
		if ( file_exists( $result ) ) {
			unlink( $result );
		}
	}

	public function test_generate_docx_with_code_block(): void {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive extension not available' );
		}

		$page_data = array_merge( $this->get_sample_page_data(), array(
			'content' => '<pre class="wp-block-code"><code>function test() { return true; }</code></pre>',
		) );
		$result = $this->exporter->generate_docx( $page_data, $this->temp_dir );
		$this->assertIsString( $result );
		if ( file_exists( $result ) ) {
			unlink( $result );
		}
	}

	public function test_generate_docx_with_horizontal_rule(): void {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive extension not available' );
		}

		$page_data = array_merge( $this->get_sample_page_data(), array(
			'content' => '<p>Before</p><hr/><p>After</p>',
		) );
		$result = $this->exporter->generate_docx( $page_data, $this->temp_dir );
		$this->assertIsString( $result );
		if ( file_exists( $result ) ) {
			unlink( $result );
		}
	}

	public function test_generate_docx_with_nested_lists(): void {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive extension not available' );
		}

		$page_data = array_merge( $this->get_sample_page_data(), array(
			'content' => '<ul><li>Item 1<ul><li>Nested Item</li></ul></li></ul>',
		) );
		$result = $this->exporter->generate_docx( $page_data, $this->temp_dir );
		$this->assertIsString( $result );
		if ( file_exists( $result ) ) {
			unlink( $result );
		}
	}

	public function test_generate_docx_with_button_element(): void {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive extension not available' );
		}

		$page_data = array_merge( $this->get_sample_page_data(), array(
			'content' => '<a href="https://example.com/link" class="wp-block-button__link">Click Me</a>',
		) );
		$result = $this->exporter->generate_docx( $page_data, $this->temp_dir );
		$this->assertIsString( $result );
		if ( file_exists( $result ) ) {
			unlink( $result );
		}
	}

	public function test_generate_docx_with_invalid_image_url(): void {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive extension not available' );
		}

		$page_data = array_merge( $this->get_sample_page_data(), array(
			'content' => '<p>Text with broken image</p><img src="http://invalid-url-that-does-not-exist.jpg" alt="test"/>',
		) );
		$result = $this->exporter->generate_docx( $page_data, $this->temp_dir );
		$this->assertIsString( $result );
		if ( file_exists( $result ) ) {
			unlink( $result );
		}
	}

	public function test_generate_docx_with_complex_content(): void {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive extension not available' );
		}

		$complex_content = '<h1>Main Heading</h1>';
		$complex_content .= '<p>Paragraph with <strong>bold</strong> and <em>italic</em> text.</p>';
		$complex_content .= '<h2>Sub Heading</h2>';
		$complex_content .= '<ul><li>List item 1</li><li>List item 2</li></ul>';
		$complex_content .= '<blockquote><p>A blockquote</p></blockquote>';
		$complex_content .= '<pre><code>code block</code></pre>';
		$complex_content .= '<table><tr><th>Header</th><td>Data</td></tr></table>';

		$page_data = array_merge( $this->get_sample_page_data(), array(
			'title'   => 'Complex Page',
			'content' => $complex_content,
		) );
		$result = $this->exporter->generate_docx( $page_data, $this->temp_dir );
		$this->assertIsString( $result );
		if ( file_exists( $result ) ) {
			unlink( $result );
		}
	}

	public function test_get_last_error_returns_empty_string_initially(): void {
		$exporter = new SScribe_Exporter( $this->parser );
		$this->assertEquals( '', $exporter->get_last_error() );
	}

	public function test_generate_docx_clears_last_error_on_success(): void {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive extension not available' );
		}

		$exporter = new SScribe_Exporter( $this->parser );
		$page_data = $this->get_sample_page_data();
		$result = $exporter->generate_docx( $page_data, $this->temp_dir );
		$this->assertIsString( $result );
		$this->assertEquals( '', $exporter->get_last_error() );
		if ( file_exists( $result ) ) {
			unlink( $result );
		}
	}

	public function test_generate_docx_returns_false_on_empty_data_and_does_not_modify_last_error(): void {
		$exporter = new SScribe_Exporter( $this->parser );
		$exporter->generate_docx( array(), $this->temp_dir );
		$this->assertFalse( $exporter->generate_docx( array(), $this->temp_dir ) );
	}

	/**
	 * Regression test: DOCX must open in Word without a repair dialog.
	 * Exercises the full pipeline: table, nested list, and special characters
	 * including &, <, >, ", em-dash, en-dash, smart quotes, RTL Arabic.
	 */
	public function test_generate_docx_with_table_list_and_special_chars_is_valid(): void {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive extension not available' );
		}

		$exporter = new SScribe_Exporter( $this->parser );

		$page_data = array(
			'title'          => 'Test & <Page> "with quotes" — em-dash – en-dash ‘smart’ “quotes”',
			'content'        => '<h1>Heading with &amp; &lt;chars&gt;</h1>'
				. '<p>Body with <strong>bold</strong>, <em>italic</em>, and a <a href="https://example.com/?a=1&b=2">link</a>.</p>'
				. '<table><thead><tr><th>Col 1 &amp; Name</th><th>Col 2</th></tr></thead>'
				. '<tbody><tr><td>Value "A"</td><td>Value \'B\'</td></tr>'
				. '<tr><td>Long-dash — em</td><td>Short-dash – en</td></tr></tbody></table>'
				. '<ul><li>Item 1<ul><li>Nested 1.1</li><li>Nested 1.2</li></ul></li><li>Item 2</li></ul>'
				. '<blockquote>Quote with "embedded" — special — chars.</blockquote>'
				. '<pre><code>if (x &lt; 10 &amp;&amp; y &gt; 0) { return "ok"; }</code></pre>'
				. '<p dir="rtl">النص العربي مع علامات &amp; الرموز الخاصة &lt;html&gt; tags</p>',
			'url'            => 'https://example.com/test?lang=en&page=1',
			'permalink'      => 'https://example.com/test',
			'author'         => 'Author & Co.',
			'date_published' => '2024-01-15',
			'date_modified'  => '2024-02-20',
			'word_count'     => 100,
			'language'       => 'en',
			'seo'            => array(
				'meta_description' => 'A test page with &amp; special <chars> — all',
				'focus_keyword'    => 'test & keyword',
				'source'           => 'Yoast & Co.',
			),
		);

		$result = $exporter->generate_docx( $page_data, $this->temp_dir );

		$this->assertIsString( $result, 'DOCX generation should succeed' );
		$this->assertFileExists( $result );
		$this->assertGreaterThan( 0, filesize( $result ), 'DOCX should be non-empty' );

		// Verify DOCX is a valid ZIP archive.
		$zip = new \ZipArchive();
		$opened = $zip->open( $result );
		$this->assertTrue( $opened === true, 'Generated file must be a valid ZIP archive' );
		$this->assertNotFalse( $zip->locateName( 'word/document.xml' ), 'DOCX must contain word/document.xml' );
		$zip->close();

		// Extract and inspect document.xml to confirm special chars survived sanitization.
		$zip = new \ZipArchive();
		$zip->open( $result );
		$xml = $zip->getFromName( 'word/document.xml' );
		$zip->close();

		$this->assertIsString( $xml );
		$this->assertStringContainsString( '<w:document', $xml, 'document.xml must be a valid OOXML document' );
		$this->assertStringContainsString( 'Heading with', $xml, 'Heading text must be present' );
		$this->assertStringContainsString( 'bold', $xml, 'Bold text must be present' );
		$this->assertStringContainsString( 'Nested 1.1', $xml, 'Nested list items must be rendered' );
		$this->assertStringContainsString( 'Col 1', $xml, 'Table headers must be rendered' );
		$this->assertStringContainsString( '&quot;A&quot;', $xml, 'Quoted table cell must be XML-escaped' );
		$this->assertStringContainsString( '&amp;', $xml, 'Ampersands must be XML-escaped' );
		$this->assertStringContainsString( '&lt;', $xml, 'Less-than must be XML-escaped' );
		$this->assertStringContainsString( 'em', $xml, 'Em-dash content must be rendered' );
		$this->assertStringContainsString( 'return ', $xml, 'Code block content must be rendered' );
		$this->assertStringNotContainsString( "\x00", $xml, 'Null bytes must be stripped' );
		$this->assertStringNotContainsString( "\x07", $xml, 'Control chars must be stripped' );

		// Verify no broken XML (would indicate invalid encoding).
		libxml_use_internal_errors( true );
		$doc  = new \DOMDocument();
		$loaded = $doc->loadXML( $xml );
		libxml_clear_errors();
		$this->assertTrue( $loaded, 'document.xml must be valid XML' );

		if ( file_exists( $result ) ) {
			unlink( $result );
		}
	}
}
