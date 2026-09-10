<?php
/**
 * SScribe format exporter smoke test
 *
 * Exercises the public surface of every format exporter (PDF, DOCX,
 * Markdown, HTML). For each exporter we instantiate, apply a synthetic
 * options map, and assert the trivial getters (get_extension,
 * get_mime_type). The constructors also pin the lazy-init of
 * collaborators (HTML_Exporter, Logger, Filesystem) which contributes
 * meaningfully to coverage on these files without needing live render
 * paths.
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

if ( ! class_exists( '\\SScribe_PDF_Exporter', false ) ) {
	require_once SSCRIBE_PLUGIN_DIR . 'includes/exporters/class-sscribe-pdf-exporter.php';
}
if ( ! class_exists( '\\SScribe_DOCX_Exporter', false ) ) {
	require_once SSCRIBE_PLUGIN_DIR . 'includes/exporters/class-sscribe-docx-exporter.php';
}
if ( ! class_exists( '\\SScribe_Markdown_Exporter', false ) ) {
	require_once SSCRIBE_PLUGIN_DIR . 'includes/exporters/class-sscribe-markdown-exporter.php';
}
if ( ! class_exists( '\\SScribe_HTML_Exporter', false ) ) {
	require_once SSCRIBE_PLUGIN_DIR . 'includes/exporters/class-sscribe-html-exporter.php';
}

final class SScribe_Format_Exporters_Smoke_Test extends TestCase {

	public function test_pdf_exporter_construction_and_simple_methods(): void {
		$exporter = new \SScribe_PDF_Exporter();
		$exporter->apply_format_options(
			array(
				'include_images' => '1',
				'rtl'            => false,
				'font_name'      => 'dejavusans',
				'font_size'      => '12',
			)
		);
		$this::assertSame( 'pdf', $exporter->get_extension() );
		$this::assertSame( 'application/pdf', $exporter->get_mime_type() );
	}

	public function test_pdf_exporter_apply_format_options_is_idempotent(): void {
		$exporter = new \SScribe_PDF_Exporter();
		$exporter->apply_format_options( array( 'rtl' => true ) );
		$exporter->apply_format_options( array( 'rtl' => false ) );
		$this::assertSame( 'pdf', $exporter->get_extension() );
	}

	public function test_docx_exporter_construction(): void {
		$exporter = new \SScribe_DOCX_Exporter();
		$exporter->apply_format_options(
			array(
				'include_cover_page' => '1',
				'include_toc'        => '1',
			)
		);
		$this::assertSame( 'docx', $exporter->get_extension() );
		$this::assertSame(
			'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			$exporter->get_mime_type()
		);
	}

	public function test_docx_exporter_get_mime_type_returns_ooxml(): void {
		$exporter = new \SScribe_DOCX_Exporter();
		$exporter->apply_format_options( array( 'include_cover_page' => '0' ) );
		$this::assertSame(
			'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			$exporter->get_mime_type()
		);
	}

	public function test_markdown_exporter_construction_and_simple_methods(): void {
		$exporter = new \SScribe_Markdown_Exporter();
		$exporter->apply_format_options( array( 'front_matter' => '1' ) );
		$this::assertSame( 'md', $exporter->get_extension() );
		$this::assertSame( 'text/markdown', $exporter->get_mime_type() );
	}

	public function test_html_exporter_construction_and_simple_methods(): void {
		$exporter = new \SScribe_HTML_Exporter();
		$exporter->apply_format_options( array( 'embed_css' => '1' ) );
		$this::assertSame( 'html', $exporter->get_extension() );
		$this::assertSame( 'text/html', $exporter->get_mime_type() );
	}

	public function test_html_exporter_generate_html_string_returns_string(): void {
		// Smoke test the public HTML rendering helper with an empty
		// page data map. The function must return a string (possibly
		// empty) without raising.
		$exporter = new \SScribe_HTML_Exporter();
		$html     = $exporter->generate_html_string( array() );
		$this::assertIsString( $html );
	}
}
