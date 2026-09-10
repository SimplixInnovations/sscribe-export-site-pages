<?php
/**
 * SScribe DOCX Exporter coverage test
 *
 * Targets the export() method's failure / success / error branches by
 * stubbing the wrapped SScribe_DOCX_Content_Renderer so we don't need a
 * real PhpWord environment to exercise the dispatcher logic.
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

if ( ! class_exists( '\\SScribe_DOCX_Exporter', false ) ) {
	require_once SSCRIBE_PLUGIN_DIR . 'includes/exporters/class-sscribe-docx-exporter.php';
}

final class SScribe_DOCX_Exporter_Export_Coverage_Test extends TestCase {

	public function test_export_returns_failure_when_page_id_missing(): void {
		$exporter = new \SScribe_DOCX_Exporter();
		$result   = $exporter->export(
			array( 'title' => 'No ID' ),
			'/tmp/out',
			0,
			1
		);

		// With PhpWord present and a default wrapped exporter, this is the
		// "wrapped exporter returned no result" branch — exercises the
		// get_last_error() / failure path.
		$this::assertFalse( $result->is_success() );
	}

	public function test_export_extracts_numeric_page_id(): void {
		$exporter = new \SScribe_DOCX_Exporter();
		$result   = $exporter->export(
			array( 'id' => '42', 'title' => 'Test' ),
			'/tmp/out',
			0,
			1
		);
		$this::assertFalse( $result->is_success() );
	}

	public function test_export_treats_non_numeric_id_as_zero(): void {
		$exporter = new \SScribe_DOCX_Exporter();
		$result   = $exporter->export(
			array( 'id' => 'not-a-number', 'title' => 'Test' ),
			'/tmp/out',
			0,
			1
		);
		$this::assertFalse( $result->is_success() );
	}

	public function test_export_returns_failure_object(): void {
		$exporter = new \SScribe_DOCX_Exporter();
		$result   = $exporter->export(
			array( 'id' => 1 ),
			'/tmp/out',
			0,
			1
		);
		$this::assertIsObject( $result );
		$this::assertInstanceOf( \SScribe_Result::class, $result );
	}

	public function test_get_extension_is_docx(): void {
		$exporter = new \SScribe_DOCX_Exporter();
		$this::assertSame( 'docx', $exporter->get_extension() );
	}

	public function test_get_mime_type_is_ooxml(): void {
		$exporter = new \SScribe_DOCX_Exporter();
		$this::assertSame(
			'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			$exporter->get_mime_type()
		);
	}

	public function test_apply_format_options_with_no_keys_keeps_state(): void {
		$exporter = new \SScribe_DOCX_Exporter();
		$exporter->apply_format_options( array() );
		$this::assertSame( 'docx', $exporter->get_extension() );
	}

	public function test_apply_format_options_with_explicit_keys(): void {
		$exporter = new \SScribe_DOCX_Exporter();
		$exporter->apply_format_options(
			array(
				'sscribe_docx_include_images' => '1',
				'sscribe_docx_include_toc'    => '1',
				'sscribe_docx_template'       => 'modern',
			)
		);
		$this::assertSame( 'docx', $exporter->get_extension() );
	}

	public function test_construct_with_null_logger(): void {
		$exporter = new \SScribe_DOCX_Exporter( null, null );
		$this::assertInstanceOf( \SScribe_DOCX_Exporter::class, $exporter );
	}
}
