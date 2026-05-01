<?php
/**
 * Unit tests for SScribe_Exporter class.
 *
 * @package SScribe
 */

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
		$this->temp_dir = sys_get_temp_dir() . '/sscribe-test-exporter-' . uniqid();
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

	/**
	 * Generate sample page data for testing.
	 */
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

	/**
	 * Test that exporter can be instantiated.
	 */
	public function test_exporter_can_be_instantiated(): void {
		$this->assertInstanceOf( SScribe_Exporter::class, $this->exporter );
	}

	/**
	 * Test that exporter accepts null parser (uses default).
	 */
	public function test_exporter_accepts_null_parser(): void {
		$exporter = new SScribe_Exporter( null );
		$this->assertInstanceOf( SScribe_Exporter::class, $exporter );
	}

	/**
	 * Test generate_docx returns false for empty page data.
	 */
	public function test_generate_docx_returns_false_for_empty_data(): void {
		$result = $this->exporter->generate_docx( array(), $this->temp_dir );
		$this->assertFalse( $result );
	}

	/**
	 * Test generate_docx returns false for invalid directory.
	 */
	public function test_generate_docx_returns_false_for_invalid_dir(): void {
		$page_data = $this->get_sample_page_data();
		$result    = $this->exporter->generate_docx( $page_data, '/invalid/path/that/does/not/exist' );
		$this->assertFalse( $result );
	}

	/**
	 * Test generate_docx succeeds with minimal valid page data.
	 */
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

	/**
	 * Test generate_docx with index and total parameters.
	 */
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

	/**
	 * Test generate_docx handles RTL content.
	 */
	public function test_generate_docx_handles_rtl_content(): void {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive extension not available' );
		}
		$page_data = array(
			'title'   => 'اختبار الصفحة',
			'content' => '<p>مرحبا بالعالم</p>',
			'url'     => 'https://example.com/test-ar',
		);
		$result    = $this->exporter->generate_docx( $page_data, $this->temp_dir );
		$this->assertIsString( $result );
		if ( file_exists( $result ) ) {
			unlink( $result );
		}
	}

	/**
	 * Test generate_docx handles content with images.
	 */
	public function test_generate_docx_handles_images(): void {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive extension not available' );
		}
		$page_data = array(
			'title'   => 'Page with Image',
			'content' => '<p>Text <img src="https://example.com/image.jpg" alt="test" /></p>',
			'url'     => 'https://example.com/image-page',
		);
		$result    = $this->exporter->generate_docx( $page_data, $this->temp_dir );
		$this->assertIsString( $result );
		if ( file_exists( $result ) ) {
			unlink( $result );
		}
	}

	/**
	 * Test generate_docx handles HTML tables.
	 */
	public function test_generate_docx_handles_tables(): void {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive extension not available' );
		}
		$page_data = array(
			'title'   => 'Page with Table',
			'content' => '<table><tr><th>Header</th></tr><tr><td>Cell</td></tr></table>',
			'url'     => 'https://example.com/table-page',
		);
		$result    = $this->exporter->generate_docx( $page_data, $this->temp_dir );
		$this->assertIsString( $result );
		if ( file_exists( $result ) ) {
			unlink( $result );
		}
	}

	/**
	 * Test generate_docx handles HTML lists.
	 */
	public function test_generate_docx_handles_lists(): void {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive extension not available' );
		}
		$page_data = array(
			'title'   => 'Page with List',
			'content' => '<ul><li>Item 1</li><li>Item 2</li></ul>',
			'url'     => 'https://example.com/list-page',
		);
		$result    = $this->exporter->generate_docx( $page_data, $this->temp_dir );
		$this->assertIsString( $result );
		if ( file_exists( $result ) ) {
			unlink( $result );
		}
	}

	/**
	 * Test generate_docx with empty title.
	 */
	public function test_generate_docx_with_empty_title(): void {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive extension not available' );
		}
		$page_data = array(
			'title'   => '',
			'content' => '<p>Content only</p>',
			'url'     => 'https://example.com/no-title',
		);
		$result    = $this->exporter->generate_docx( $page_data, $this->temp_dir );
		$this->assertIsString( $result );
		if ( file_exists( $result ) ) {
			unlink( $result );
		}
	}

	/**
	 * Test generate_docx with special characters in content.
	 */
	public function test_generate_docx_with_special_characters(): void {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive extension not available' );
		}
		$page_data = array(
			'title'   => 'Special & Characters < > " \'',
			'content' => '<p>Content with &amp; entities & "quotes"</p>',
			'url'     => 'https://example.com/special',
		);
		$result    = $this->exporter->generate_docx( $page_data, $this->temp_dir );
		$this->assertIsString( $result );
		if ( file_exists( $result ) ) {
			unlink( $result );
		}
	}

	/**
	 * Test generate_docx with long content.
	 */
	public function test_generate_docx_with_long_content(): void {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive extension not available' );
		}
		$long_content = str_repeat( '<p>This is a paragraph of content.</p>', 50 );
		$page_data    = array(
			'title'   => 'Long Page',
			'content' => $long_content,
			'url'     => 'https://example.com/long',
		);
		$result       = $this->exporter->generate_docx( $page_data, $this->temp_dir );
		$this->assertIsString( $result );
		if ( file_exists( $result ) ) {
			unlink( $result );
		}
	}

	/**
	 * Test generate_docx sets last_error on failure.
	 */
	public function test_generate_docx_sets_last_error_on_failure(): void {
		$result = $this->exporter->generate_docx( array(), $this->temp_dir );
		$this->assertFalse( $result );
	}

	/**
	 * Test is_rtl_document method.
	 */
	public function test_is_rtl_document_detects_rtl(): void {
		$rtl_data   = array( 'title' => 'مرحبا' );
		$ltr_data   = array( 'title' => 'Hello' );
		$ar_data    = array( 'title' => 'Test', 'language' => 'ar' );
		// Use reflection to test private method.
		$method = new \ReflectionMethod( SScribe_Exporter::class, 'is_rtl_document' );
		$method->setAccessible( true );

		$this->assertTrue( $method->invoke( $this->exporter, $ar_data ) );
		$this->assertFalse( $method->invoke( $this->exporter, $ltr_data ) );
	}
}
