<?php
/**
 * SScribe Markdown Exporter smoke test
 *
 * Exercises the public surface of the Markdown exporter that does
 * not require running an actual export:
 *
 *   - __construct() with default collaborators
 *   - __construct() with explicit logger + filesystem
 *   - apply_format_options() (idempotent for repeated calls)
 *   - get_extension() and get_mime_type() — simple getter pins
 *
 * The exporter() method body is exercised via the integration suite;
 * this file pins the deterministic code paths and the public surface
 * that the batch processor and admin UI rely on.
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

if ( ! class_exists( '\\SScribe_Markdown_Exporter', false ) ) {
	require_once SSCRIBE_PLUGIN_DIR . 'includes/exporters/class-sscribe-markdown-exporter.php';
}

final class SScribe_Markdown_Exporter_Smoke_Test extends TestCase {

	public function test_construct_with_defaults(): void {
		$exporter = new \SScribe_Markdown_Exporter();
		$this::assertInstanceOf( \SScribe_Markdown_Exporter::class, $exporter );
		$this::assertSame( 'md', $exporter->get_extension() );
		$this::assertSame( 'text/markdown', $exporter->get_mime_type() );
	}

	public function test_construct_with_explicit_logger(): void {
		$logger   = \SScribe_Logger::instance( false );
		$exporter = new \SScribe_Markdown_Exporter( $logger, null );
		$this::assertInstanceOf( \SScribe_Markdown_Exporter::class, $exporter );
	}

	public function test_construct_with_explicit_filesystem(): void {
		$fs       = new \SScribe_Filesystem();
		$exporter = new \SScribe_Markdown_Exporter( null, $fs );
		$this::assertInstanceOf( \SScribe_Markdown_Exporter::class, $exporter );
	}

	public function test_construct_with_both_explicit(): void {
		$logger   = \SScribe_Logger::instance( false );
		$fs       = new \SScribe_Filesystem();
		$exporter = new \SScribe_Markdown_Exporter( $logger, $fs );
		$this::assertInstanceOf( \SScribe_Markdown_Exporter::class, $exporter );
	}

	public function test_apply_format_options_accepts_empty_array(): void {
		$exporter = new \SScribe_Markdown_Exporter();
		$exporter->apply_format_options( array() );
		$this::assertSame( 'md', $exporter->get_extension() );
	}

	public function test_apply_format_options_accepts_synthetic_keys(): void {
		$exporter = new \SScribe_Markdown_Exporter();
		$exporter->apply_format_options(
			array(
				'sscribe_md_absolute_urls'          => '1',
				'sscribe_md_include_frontmatter'    => '',
				'sscribe_md_include_featured_image' => '1',
			)
		);
		$this::assertSame( 'text/markdown', $exporter->get_mime_type() );
	}

	public function test_apply_format_options_is_idempotent(): void {
		$exporter = new \SScribe_Markdown_Exporter();
		$exporter->apply_format_options( array( 'k' => 'v1' ) );
		$exporter->apply_format_options( array( 'k' => 'v2' ) );
		$exporter->apply_format_options( array( 'k' => 'v3' ) );
		$this::assertSame( 'md', $exporter->get_extension() );
	}

	public function test_get_extension_is_consistent(): void {
		$exporter = new \SScribe_Markdown_Exporter();
		$this::assertSame( $exporter->get_extension(), $exporter->get_extension() );
	}

	public function test_get_mime_type_is_consistent(): void {
		$exporter = new \SScribe_Markdown_Exporter();
		$this::assertSame( $exporter->get_mime_type(), $exporter->get_mime_type() );
	}
}
