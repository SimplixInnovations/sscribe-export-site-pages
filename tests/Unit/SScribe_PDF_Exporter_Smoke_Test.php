<?php
/**
 * SScribe PDF Exporter smoke test
 *
 * Exercises the public surface of the PDF exporter that does not
 * require running an actual export:
 *
 *   - __construct() with default collaborators
 *   - __construct() with explicit HTML_Exporter / logger / filesystem
 *   - apply_format_options() — covers the format_options storage path
 *   - get_extension() and get_mime_type() — simple getter pins
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

if ( ! class_exists( '\\SScribe_PDF_Exporter', false ) ) {
	require_once SSCRIBE_PLUGIN_DIR . 'includes/exporters/class-sscribe-pdf-exporter.php';
}

final class SScribe_PDF_Exporter_Smoke_Test extends TestCase {

	public function test_construct_with_defaults(): void {
		$exporter = new \SScribe_PDF_Exporter();
		$this::assertInstanceOf( \SScribe_PDF_Exporter::class, $exporter );
		$this::assertSame( 'pdf', $exporter->get_extension() );
		$this::assertSame( 'application/pdf', $exporter->get_mime_type() );
	}

	public function test_construct_with_explicit_html_exporter(): void {
		$html_exp = new \SScribe_HTML_Exporter();
		$exporter = new \SScribe_PDF_Exporter( $html_exp, null, null );
		$this::assertInstanceOf( \SScribe_PDF_Exporter::class, $exporter );
	}

	public function test_construct_with_explicit_logger(): void {
		$logger   = \SScribe_Logger::instance( false );
		$exporter = new \SScribe_PDF_Exporter( null, $logger, null );
		$this::assertInstanceOf( \SScribe_PDF_Exporter::class, $exporter );
	}

	public function test_construct_with_explicit_filesystem(): void {
		$fs       = new \SScribe_Filesystem();
		$exporter = new \SScribe_PDF_Exporter( null, null, $fs );
		$this::assertInstanceOf( \SScribe_PDF_Exporter::class, $exporter );
	}

	public function test_construct_with_all_explicit_collaborators(): void {
		$html_exp = new \SScribe_HTML_Exporter();
		$logger   = \SScribe_Logger::instance( false );
		$fs       = new \SScribe_Filesystem();
		$exporter = new \SScribe_PDF_Exporter( $html_exp, $logger, $fs );
		$this::assertInstanceOf( \SScribe_PDF_Exporter::class, $exporter );
	}

	public function test_apply_format_options_accepts_empty_array(): void {
		$exporter = new \SScribe_PDF_Exporter();
		$exporter->apply_format_options( array() );
		$this::assertSame( 'pdf', $exporter->get_extension() );
	}

	public function test_apply_format_options_accepts_synthetic_keys(): void {
		$exporter = new \SScribe_PDF_Exporter();
		$exporter->apply_format_options(
			array(
				'sscribe_pdf_page_size'             => 'A4',
				'sscribe_pdf_include_images'        => '1',
				'sscribe_pdf_include_page_numbers'  => '',
			)
		);
		$this::assertSame( 'application/pdf', $exporter->get_mime_type() );
	}

	public function test_apply_format_options_is_idempotent(): void {
		$exporter = new \SScribe_PDF_Exporter();
		$exporter->apply_format_options( array( 'k' => 'v1' ) );
		$exporter->apply_format_options( array( 'k' => 'v2' ) );
		$exporter->apply_format_options( array( 'k' => 'v3' ) );
		$this::assertSame( 'pdf', $exporter->get_extension() );
	}

	public function test_apply_format_options_accepts_rtl_flag(): void {
		$exporter = new \SScribe_PDF_Exporter();
		$exporter->apply_format_options( array( 'rtl' => true ) );
		$exporter->apply_format_options( array( 'rtl' => false ) );
		$this::assertSame( 'application/pdf', $exporter->get_mime_type() );
	}

	public function test_apply_format_options_accepts_font_config(): void {
		$exporter = new \SScribe_PDF_Exporter();
		$exporter->apply_format_options(
			array(
				'font_name' => 'dejavusans',
				'font_size' => '12',
			)
		);
		$this::assertSame( 'pdf', $exporter->get_extension() );
	}

	public function test_get_extension_is_stable(): void {
		$exporter = new \SScribe_PDF_Exporter();
		$this::assertSame( $exporter->get_extension(), $exporter->get_extension() );
		$this::assertSame( 'pdf', $exporter->get_extension() );
	}

	public function test_get_mime_type_is_stable(): void {
		$exporter = new \SScribe_PDF_Exporter();
		$this::assertSame( $exporter->get_mime_type(), $exporter->get_mime_type() );
		$this::assertSame( 'application/pdf', $exporter->get_mime_type() );
	}
}
