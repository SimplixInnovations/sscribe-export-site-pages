<?php
/**
 * SScribe HTML Exporter Unit Test
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class SScribe_HTML_Exporter_Test extends TestCase {

	private string $export_root;

	protected function setUp(): void {
		parent::setUp();
		$this->export_root = \SScribe_Private_Storage::get_subdirectory( 'sscribe-html-test-' . uniqid() );
	}

	protected function tearDown(): void {
		if ( is_dir( $this->export_root ) ) {
			$files = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $this->export_root, \RecursiveDirectoryIterator::SKIP_DOTS ),
				\RecursiveIteratorIterator::CHILD_FIRST
			);
			foreach ( $files as $file ) {
				if ( $file->isDir() ) {
					rmdir( $file->getRealPath() );
				} else {
					unlink( $file->getRealPath() );
				}
			}
			rmdir( $this->export_root );
		}
		parent::tearDown();
	}

	private function build_exporter(): \SScribe_HTML_Exporter {
		return new \SScribe_HTML_Exporter();
	}

	private function sample_page_data( array $overrides = array() ): array {
		return array_merge(
			array(
				'id'             => 42,
				'title'          => 'Sample Page',
				'content'        => '<p>Hello world.</p>',
				'permalink'      => 'https://example.com/sample/',
				'language'       => 'en',
				'author'         => 'admin',
				'date_published' => '2024-01-15',
				'date_modified'  => '2024-02-20',
				'word_count'     => 120,
			),
			$overrides
		);
	}

	public function test_can_instantiate_with_no_args(): void {
		$exporter = new \SScribe_HTML_Exporter();
		$this->assertInstanceOf( \SScribe_HTML_Exporter::class, $exporter );
		$this->assertSame( 'html', $exporter->get_extension() );
		$this->assertSame( 'text/html', $exporter->get_mime_type() );
	}

	public function test_can_instantiate_with_logger(): void {
		$logger   = $this->createMock( \SScribe_Logger_Interface::class );
		$exporter = new \SScribe_HTML_Exporter( $logger );
		$this->assertInstanceOf( \SScribe_HTML_Exporter::class, $exporter );
	}

	public function test_can_instantiate_with_filesystem(): void {
		$logger     = $this->createMock( \SScribe_Logger_Interface::class );
		$filesystem = $this->createMock( \SScribe_Filesystem::class );
		$exporter   = new \SScribe_HTML_Exporter( $logger, $filesystem );
		$this->assertInstanceOf( \SScribe_HTML_Exporter::class, $exporter );
	}

	public function test_apply_format_options_stores_options(): void {
		$exporter = $this->build_exporter();
		$exporter->apply_format_options(
			array(
				'sscribe_html_include_css'         => '0',
				'sscribe_html_responsive_images'   => '0',
				'sscribe_html_unused_setting'      => 'whatever',
			)
		);

		$result = $exporter->generate_html_string( $this->sample_page_data() );

		$this->assertStringNotContainsString( '<style>', $result );
		$this->assertStringContainsString( '<!DOCTYPE html>', $result );
	}

	public function test_export_writes_file_and_returns_success(): void {
		$exporter = $this->build_exporter();
		$page     = $this->sample_page_data();
		$result   = $exporter->export( $page, $this->export_root, 1, 5 );

		$this->assertInstanceOf( \SScribe_Result::class, $result );
		$this->assertTrue( $result->is_success() );
		$this->assertFileExists( $result->get_data()['path'] );
	}

	public function test_export_with_empty_content_returns_untitled_html(): void {
		$exporter = $this->build_exporter();
		$page     = array(
			'id'       => 99,
			'title'    => '',
			'content'  => '',
			'language' => 'en',
		);

		$result = $exporter->export( $page, $this->export_root, 1, 1 );
		$this->assertTrue( $result->is_success() );

		$html = $result->get_data()['html'];
		$this->assertStringContainsString( '<!DOCTYPE html>', $html );
		$this->assertStringContainsString( 'Untitled', $html );
	}

	public function test_export_with_missing_output_dir_creates_dir(): void {
		$exporter = $this->build_exporter();
		$nested   = $this->export_root . '/nested/sub';

		$result = $exporter->export( $this->sample_page_data(), $nested, 1, 1 );

		$this->assertTrue( $result->is_success() );
		$this->assertFileExists( $result->get_data()['path'] );
		$this->assertDirectoryExists( $nested );
	}

	public function test_export_returns_size_matching_html_length(): void {
		$exporter = $this->build_exporter();
		$result   = $exporter->export( $this->sample_page_data(), $this->export_root, 1, 1 );

		$this->assertTrue( $result->is_success() );
		$this->assertSame( strlen( $result->get_data()['html'] ), $result->get_data()['size'] );
	}

	public function test_export_rtl_language_emits_rtl_dir(): void {
		$exporter = $this->build_exporter();
		$page     = $this->sample_page_data( array( 'language' => 'ar' ) );

		$result = $exporter->export( $page, $this->export_root, 1, 1 );
		$html   = $result->get_data()['html'];

		$this->assertTrue( $result->is_success() );
		$this->assertStringContainsString( 'dir="rtl"', $html );
		$this->assertStringContainsString( 'direction: rtl;', $html );
	}

	public function test_export_with_index_total_formats_filename(): void {
		$exporter = $this->build_exporter();
		$page     = $this->sample_page_data( array( 'title' => 'Index Test' ) );

		$result = $exporter->export( $page, $this->export_root, 3, 7 );

		$this->assertTrue( $result->is_success() );
		$this->assertStringContainsString( 'P003-Index Test-42', $result->get_data()['path'] );
		$this->assertStringEndsWith( '.html', $result->get_data()['path'] );
	}

	public function test_generate_html_string_includes_title_and_meta(): void {
		$exporter = $this->build_exporter();
		$html     = $exporter->generate_html_string( $this->sample_page_data() );

		$this->assertStringContainsString( 'Sample Page', $html );
		$this->assertStringContainsString( 'lang="en"', $html );
		$this->assertStringContainsString( 'Hello world.', $html );
		$this->assertStringContainsString( '<dl class="meta">', $html );
		$this->assertStringContainsString( 'Author', $html );
		$this->assertStringContainsString( '120', $html );
	}
}
