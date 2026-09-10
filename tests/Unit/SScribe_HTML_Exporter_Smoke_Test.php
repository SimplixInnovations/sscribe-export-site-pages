<?php
/**
 * SScribe HTML Exporter smoke test
 *
 * Exercises the public surface of the HTML exporter that does not
 * require running an actual export:
 *
 *   - __construct() with default collaborators
 *   - __construct() with explicit logger + filesystem
 *   - apply_format_options() (idempotent for repeated calls)
 *   - get_extension() and get_mime_type() — simple getter pins
 *   - generate_html_string() with empty / minimal / structured data
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

if ( ! class_exists( '\\SScribe_HTML_Exporter', false ) ) {
	require_once SSCRIBE_PLUGIN_DIR . 'includes/exporters/class-sscribe-html-exporter.php';
}

final class SScribe_HTML_Exporter_Smoke_Test extends TestCase {

	public function test_construct_with_defaults(): void {
		$exporter = new \SScribe_HTML_Exporter();
		$this::assertInstanceOf( \SScribe_HTML_Exporter::class, $exporter );
		$this::assertSame( 'html', $exporter->get_extension() );
		$this::assertSame( 'text/html', $exporter->get_mime_type() );
	}

	public function test_construct_with_explicit_logger(): void {
		$logger   = \SScribe_Logger::instance( false );
		$exporter = new \SScribe_HTML_Exporter( $logger, null );
		$this::assertInstanceOf( \SScribe_HTML_Exporter::class, $exporter );
	}

	public function test_construct_with_explicit_filesystem(): void {
		$fs       = new \SScribe_Filesystem();
		$exporter = new \SScribe_HTML_Exporter( null, $fs );
		$this::assertInstanceOf( \SScribe_HTML_Exporter::class, $exporter );
	}

	public function test_construct_with_both_explicit(): void {
		$logger   = \SScribe_Logger::instance( false );
		$fs       = new \SScribe_Filesystem();
		$exporter = new \SScribe_HTML_Exporter( $logger, $fs );
		$this::assertInstanceOf( \SScribe_HTML_Exporter::class, $exporter );
	}

	public function test_apply_format_options_accepts_empty_array(): void {
		$exporter = new \SScribe_HTML_Exporter();
		$exporter->apply_format_options( array() );
		$this::assertSame( 'html', $exporter->get_extension() );
	}

	public function test_apply_format_options_accepts_synthetic_keys(): void {
		$exporter = new \SScribe_HTML_Exporter();
		$exporter->apply_format_options(
			array(
				'sscribe_html_include_css'           => '1',
				'sscribe_html_responsive_images'     => '',
			)
		);
		$this::assertSame( 'text/html', $exporter->get_mime_type() );
	}

	public function test_apply_format_options_is_idempotent(): void {
		$exporter = new \SScribe_HTML_Exporter();
		$exporter->apply_format_options( array( 'k' => 'v1' ) );
		$exporter->apply_format_options( array( 'k' => 'v2' ) );
		$this::assertSame( 'html', $exporter->get_extension() );
	}

	public function test_generate_html_string_returns_string_for_empty_data(): void {
		$exporter = new \SScribe_HTML_Exporter();
		$html     = $exporter->generate_html_string( array() );
		$this::assertIsString( $html );
	}

	public function test_generate_html_string_handles_minimal_data(): void {
		$exporter = new \SScribe_HTML_Exporter();
		$html     = $exporter->generate_html_string(
			array(
				'title'   => 'Test',
				'content' => '<p>Hello</p>',
			)
		);
		$this::assertIsString( $html );
	}

	public function test_generate_html_string_handles_structured_data(): void {
		$exporter = new \SScribe_HTML_Exporter();
		$html     = $exporter->generate_html_string(
			array(
				'title'    => 'Test Page',
				'content'  => '<p>Body content</p>',
				'url'      => 'https://example.com/test',
				'author'   => 'Test Author',
				'language' => 'en',
				'seo'      => array(
					'meta_title'       => 'SEO Title',
					'meta_description' => 'SEO Description',
				),
			)
		);
		$this::assertIsString( $html );
	}

	public function test_get_extension_is_stable_across_calls(): void {
		$exporter = new \SScribe_HTML_Exporter();
		$this::assertSame( $exporter->get_extension(), $exporter->get_extension() );
		$this::assertSame( 'html', $exporter->get_extension() );
	}

	public function test_get_mime_type_is_stable_across_calls(): void {
		$exporter = new \SScribe_HTML_Exporter();
		$this::assertSame( $exporter->get_mime_type(), $exporter->get_mime_type() );
		$this::assertSame( 'text/html', $exporter->get_mime_type() );
	}
}
