<?php
/**
 * SScribe DOCX Exporter smoke test
 *
 * Exercises the public surface of the DOCX exporter that does not
 * require running an actual export:
 *
 *   - __construct() with default collaborators
 *   - __construct() with explicit base Exporter / logger
 *   - apply_format_options() forwards to the wrapped exporter
 *   - get_extension() and get_mime_type() — simple getter pins
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

if ( ! class_exists( '\\SScribe_DOCX_Exporter', false ) ) {
	require_once SSCRIBE_PLUGIN_DIR . 'includes/exporters/class-sscribe-docx-exporter.php';
}

final class SScribe_DOCX_Exporter_Smoke_Test extends TestCase {

	public function test_construct_with_defaults(): void {
		$exporter = new \SScribe_DOCX_Exporter();
		$this::assertInstanceOf( \SScribe_DOCX_Exporter::class, $exporter );
		$this::assertSame( 'docx', $exporter->get_extension() );
		$this::assertSame(
			'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			$exporter->get_mime_type()
		);
	}

	public function test_construct_with_explicit_base_exporter(): void {
		$base     = new \SScribe_Exporter();
		$exporter = new \SScribe_DOCX_Exporter( $base, null );
		$this::assertInstanceOf( \SScribe_DOCX_Exporter::class, $exporter );
	}

	public function test_construct_with_explicit_logger(): void {
		$logger   = \SScribe_Logger::instance( false );
		$exporter = new \SScribe_DOCX_Exporter( null, $logger );
		$this::assertInstanceOf( \SScribe_DOCX_Exporter::class, $exporter );
	}

	public function test_construct_with_both_explicit(): void {
		$base     = new \SScribe_Exporter();
		$logger   = \SScribe_Logger::instance( false );
		$exporter = new \SScribe_DOCX_Exporter( $base, $logger );
		$this::assertInstanceOf( \SScribe_DOCX_Exporter::class, $exporter );
	}

	public function test_apply_format_options_accepts_empty_array(): void {
		$exporter = new \SScribe_DOCX_Exporter();
		$exporter->apply_format_options( array() );
		$this::assertSame( 'docx', $exporter->get_extension() );
	}

	public function test_apply_format_options_accepts_synthetic_keys(): void {
		$exporter = new \SScribe_DOCX_Exporter();
		$exporter->apply_format_options(
			array(
				'sscribe_docx_include_images' => '1',
				'sscribe_docx_include_toc'    => '',
				'sscribe_docx_template'       => 'modern',
			)
		);
		$this::assertSame(
			'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			$exporter->get_mime_type()
		);
	}

	public function test_apply_format_options_is_idempotent(): void {
		$exporter = new \SScribe_DOCX_Exporter();
		$exporter->apply_format_options( array( 'k' => 'v1' ) );
		$exporter->apply_format_options( array( 'k' => 'v2' ) );
		$this::assertSame( 'docx', $exporter->get_extension() );
	}

	public function test_get_extension_is_stable(): void {
		$exporter = new \SScribe_DOCX_Exporter();
		$this::assertSame( $exporter->get_extension(), $exporter->get_extension() );
		$this::assertSame( 'docx', $exporter->get_extension() );
	}

	public function test_get_mime_type_is_stable(): void {
		$exporter = new \SScribe_DOCX_Exporter();
		$this::assertSame( $exporter->get_mime_type(), $exporter->get_mime_type() );
	}
}
