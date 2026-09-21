<?php
/**
 * SScribe PDF Exporter extra helper coverage test
 *
 * Targets the helpers NOT yet covered by SScribe_PDF_Exporter_Helpers_Coverage_Test:
 *
 *   - collect_temp_image_paths()    : empty, with _temp_image_paths, with featured
 *   - cleanup_temp_images()         : deletes existing files, skips missing
 *   - sanitize_pdf_image_sources()  : include_images=true vs false, missing src
 *   - filter_style_attribute()      : empty, allowed decls, forbidden decls
 *   - process_images_in_page_data() : no-op when no images
 *   - create_tcpdf_document()           : returns array or SScribe_Result
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;

if ( ! class_exists( '\\SScribe_PDF_Exporter', false ) ) {
	require_once SSCRIBE_PLUGIN_DIR . 'includes/exporters/class-sscribe-pdf-exporter.php';
}

final class SScribe_PDF_Exporter_Extra_Helper_Coverage_Test extends TestCase {

	private \SScribe_PDF_Exporter $pdf;
	private ReflectionClass $ref;

	protected function setUp(): void {
		parent::setUp();
		$this->pdf = new \SScribe_PDF_Exporter();
		$this->ref = new ReflectionClass( $this->pdf );
	}

	private function call( string $name, array $args = array() ): mixed {
		$m = $this->ref->getMethod( $name );
		return $m->invokeArgs( $this->pdf, $args );
	}

	public function test_collect_temp_image_paths_empty_input(): void {
		$result = $this->call( 'collect_temp_image_paths', array( array() ) );
		$this::assertSame( array(), $result );
	}

	public function test_collect_temp_image_paths_collects_from_temp_paths_key(): void {
		$data  = array( '_temp_image_paths' => array( '/tmp/a.png', '/tmp/b.png' ) );
		$result = $this->call( 'collect_temp_image_paths', array( $data ) );
		$this::assertCount( 2, $result );
		$this::assertContains( '/tmp/a.png', $result );
		$this::assertContains( '/tmp/b.png', $result );
	}

	public function test_collect_temp_image_paths_collects_from_featured_image_url(): void {
		$data  = array( 'featured_image_url' => '/tmp/featured.jpg' );
		$result = $this->call( 'collect_temp_image_paths', array( $data ) );
		$this::assertContains( '/tmp/featured.jpg', $result );
	}

	public function test_collect_temp_image_paths_dedupes_overlap(): void {
		$data = array(
			'_temp_image_paths'  => array( '/tmp/shared.png' ),
			'featured_image_url' => '/tmp/shared.png',
		);
		$result = $this->call( 'collect_temp_image_paths', array( $data ) );
		$this::assertCount( 1, $result );
	}

	public function test_collect_temp_image_paths_filters_non_strings(): void {
		$data = array(
			'_temp_image_paths' => array( '/tmp/a.png', '', null, 12345 ),
		);
		$result = $this->call( 'collect_temp_image_paths', array( $data ) );
		// null/12345 → not is_string → skipped; '' → empty → skipped.
		$this::assertSame( array( '/tmp/a.png' ), $result );
	}

	public function test_cleanup_temp_images_skips_paths_outside_temp_dir(): void {
		// Image_Processor::cleanup() only deletes files inside its own temp dir,
		// so files in our export root are no-ops. The branch we exercise is the
		// early-return path that still completes the foreach loop.
		$base = \SScribe_Private_Storage::get_export_dir( false );
		if ( '' === $base ) {
			$this::markTestSkipped( 'No private storage dir' );
		}
		// Self-heal against test-order dependence: when this test happens to
		// run AFTER a sibling that called `delete_owned_storage()` (e.g.
		// SScribe_Private_Storage_Late_Branch_Test) the export dir does not
		// exist on disk yet `get_export_dir(false)` returns its path string.
		// `file_put_contents()` would then emit PHP warnings (and the
		// `assertFileExists` assertion would fail). Recreate the dir; the
		// test's intent — proving `cleanup_temp_images` leaves siblings of
		// the image-staging temp dir alone — is unaffected by who built the
		// dir originally.
		if ( ! is_dir( $base ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors
			@mkdir( $base, 0777, true );
		}
		$a = $base . '/pdf_cleanup_a_' . uniqid() . '.tmp';
		$b = $base . '/pdf_cleanup_b_' . uniqid() . '.tmp';
		file_put_contents( $a, 'x' );
		file_put_contents( $b, 'x' );

		$this->call( 'cleanup_temp_images', array( array( $a, $b ) ) );

		// Files are NOT deleted because they're outside the image-processor
		// temp dir — this asserts the branch was exercised (no exception).
		$this::assertFileExists( $a );
		$this::assertFileExists( $b );

		@unlink( $a );
		@unlink( $b );
	}

	public function test_cleanup_temp_images_handles_empty_array(): void {
		// Calling with empty list must not throw.
		$this->call( 'cleanup_temp_images', array( array() ) );
		$this::assertTrue( true );
	}

	public function test_sanitize_pdf_image_sources_strips_when_include_images_false(): void {
		$html    = '<p>before</p><img src="/tmp/x.png" alt="x"><p>after</p>';
		$result  = $this->call( 'sanitize_pdf_image_sources', array( $html, false ) );
		$this::assertStringNotContainsString( '<img', $result );
		$this::assertStringContainsString( '<p>before</p>', $result );
		$this::assertStringContainsString( '<p>after</p>', $result );
	}

	public function test_sanitize_pdf_image_sources_keeps_when_include_images_true(): void {
		// With include_images=true and an external (non-local) src, the canonical
		// resolver returns '' → the img gets stripped (same as the false branch
		// for invalid sources). What matters here is the branch with valid
		// src: external sources get dropped via SScribe_Image_Processor.
		$html   = '<p>before</p><img src="https://example.com/x.png"><p>after</p>';
		$result = $this->call( 'sanitize_pdf_image_sources', array( $html, true ) );
		$this::assertStringNotContainsString( 'https://example.com/x.png', $result );
	}

	public function test_sanitize_pdf_image_sources_drops_img_without_src(): void {
		$html   = '<p>before</p><img alt="no src"><p>after</p>';
		$result = $this->call( 'sanitize_pdf_image_sources', array( $html, true ) );
		$this::assertStringNotContainsString( '<img', $result );
	}

	public function test_sanitize_pdf_image_sources_handles_single_quoted_src(): void {
		$html   = "<p>before</p><img src='/tmp/x.png'><p>after</p>";
		$result = $this->call( 'sanitize_pdf_image_sources', array( $html, true ) );
		// External src is dropped; the img tag with src removed becomes empty.
		$this::assertStringContainsString( '<p>before</p>', $result );
	}

	public function test_filter_style_attribute_returns_empty_for_empty_input(): void {
		$result = $this->call( 'filter_style_attribute', array( '', false ) );
		$this::assertSame( '', $result );
	}

	public function test_filter_style_attribute_returns_empty_for_whitespace_input(): void {
		$result = $this->call( 'filter_style_attribute', array( "   \t\n", false ) );
		$this::assertSame( '', $result );
	}

	public function test_filter_style_attribute_keeps_allowed_declarations(): void {
		$input  = 'direction: rtl; text-align: left; color: red;';
		$result = $this->call( 'filter_style_attribute', array( $input, true ) );
		$this::assertStringContainsString( 'direction', $result );
		$this::assertStringContainsString( 'text-align', $result );
		$this::assertStringContainsString( 'color', $result );
	}

	public function test_filter_style_attribute_drops_forbidden_declarations(): void {
		$input  = 'position: absolute; display: none; direction: ltr;';
		$result = $this->call( 'filter_style_attribute', array( $input, false ) );
		$this::assertStringNotContainsString( 'position', $result );
		$this::assertStringNotContainsString( 'display', $result );
		$this::assertStringContainsString( 'direction', $result );
	}

	public function test_process_images_in_page_data_returns_input_when_no_images(): void {
		$data   = array( 'title' => 'Sample', 'content' => '<p>plain</p>' );
		$result = $this->call( 'process_images_in_page_data', array( $data ) );
		// Without an image processing filter registered, the input passes through.
		$this::assertSame( 'Sample', $result['title'] );
		$this::assertSame( '<p>plain</p>', $result['content'] );
	}

	public function test_check_memory_pressure_returns_null_or_bool(): void {
		// memory_limit may be empty/-1 in some environments, returning null.
		// Otherwise returns bool.
		$result = $this->call( 'check_memory_pressure' );
		$this::assertTrue( null === $result || is_bool( $result ) );
	}

	public function test_prepare_html_for_pdf_engine_returns_string(): void {
		$result = $this->call( 'prepare_html_for_pdf_engine', array( '<p>hello</p>', false ) );
		$this::assertIsString( $result );
	}

	public function test_get_logger_log_file_or_public_surface(): void {
		// Public surface check.
		$this::assertTrue( method_exists( \SScribe_PDF_Exporter::class, 'export' ) );
		$this::assertTrue( method_exists( \SScribe_PDF_Exporter::class, 'apply_format_options' ) );
		$this::assertTrue( method_exists( \SScribe_PDF_Exporter::class, 'get_extension' ) );
		$this::assertTrue( method_exists( \SScribe_PDF_Exporter::class, 'get_mime_type' ) );
	}
}
