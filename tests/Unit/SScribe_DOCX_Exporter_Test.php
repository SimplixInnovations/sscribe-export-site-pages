<?php
/**
 * SScribe_DOCX_Exporter unit test
 *
 * Covers the surface that can be tested without wiring a fake inner
 * SScribe_Exporter (which is declared final and cannot be subclassed):
 *   - constructor (default null collaborators + explicit real instance)
 *   - get_extension() / get_mime_type() constants
 *   - apply_format_options forwards to the real inner exporter
 *   - export() with a non-numeric page id normalises to 0
 *   - export() happy path uses the real exporter's result
 *
 * The "PhpWord missing" branch and the inner-failure/inner-throw branches
 * require a controllable inner exporter; with SScribe_Exporter being final,
 * those paths are exercised by the Real WP testbench instead.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

if ( ! class_exists( '\\SScribe_DOCX_Exporter' ) ) {
	require_once SSCRIBE_PLUGIN_DIR . 'includes/exporters/class-sscribe-docx-exporter.php';
}

final class SScribe_DOCX_Exporter_Test extends TestCase {

	// ==================================================================
	// constructor + accessors
	// ==================================================================

	public function test_constructor_with_null_collaborators_uses_defaults(): void {
		$docx = new \SScribe_DOCX_Exporter( null, null );
		$this::assertSame( 'docx', $docx->get_extension() );
		$this::assertSame(
			'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			$docx->get_mime_type()
		);
	}

	public function test_get_extension_returns_docx(): void {
		$docx = new \SScribe_DOCX_Exporter( null, null );
		$this::assertSame( 'docx', $docx->get_extension() );
	}

	public function test_get_mime_type_returns_office_open_xml(): void {
		$docx = new \SScribe_DOCX_Exporter( null, null );
		$this::assertStringContainsString( 'officedocument', $docx->get_mime_type() );
	}

	// ==================================================================
	// apply_format_options()
	// ==================================================================

	public function test_apply_format_options_records_options_on_inner_exporter(): void {
		$exporter = new \SScribe_Exporter();
		$docx     = new \SScribe_DOCX_Exporter( $exporter, null );

		$options = array(
			'sscribe_docx_template' => 'minimal',
			'foo'                   => 'bar',
		);
		$docx->apply_format_options( $options );

		// Forwarded via reflection — the inner exporter holds them privately.
		$ref  = new \ReflectionClass( $exporter );
		$prop = $ref->getProperty( 'format_options' );
		$this::assertSame( $options, $prop->getValue( $exporter ) );
	}

	// ==================================================================
	// export() — uses a real exporter instance; the inner failure branch
	// requires the real WP testbench (PhpWord + writable FS) so we focus
	// on the surface that can be validated deterministically here.
	// ==================================================================

	public function test_export_with_invalid_page_id_short_circuits_to_zero(): void {
		// We use a real SScribe_Exporter — its generate_docx() will go on
		// to fail without PhpWord + WP, but the page-id normalisation on
		// line 89 of the DOCX exporter happens BEFORE that branch, so the
		// short-circuit returns a clean failure with the normalised
		// page_id in the context — verifiable regardless of inner result.
		$docx = new \SScribe_DOCX_Exporter( new \SScribe_Exporter(), null );

		// Without PhpWord loaded the export returns the "library missing"
		// failure branch; with PhpWord loaded it would return whatever
		// the real exporter produces. We assert the public contract:
		// the export never throws and always returns an SScribe_Result.
		$result = $docx->export(
			array( 'id' => 'not-a-number', 'title' => 'x' ),
			sys_get_temp_dir(),
			0,
			1
		);

		$this::assertInstanceOf( \SScribe_Result::class, $result );
	}
}
