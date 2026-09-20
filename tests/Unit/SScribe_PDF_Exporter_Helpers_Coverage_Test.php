<?php
/**
 * SScribe PDF exporter helper coverage tests.
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

final class SScribe_PDF_Exporter_Helpers_Coverage_Test extends TestCase {

	private \SScribe_PDF_Exporter $exporter;
	private ReflectionClass $reflection;

	protected function setUp(): void {
		parent::setUp();
		$this->exporter   = new \SScribe_PDF_Exporter();
		$this->reflection = new ReflectionClass( $this->exporter );
	}

	private function call( string $name, array $args = array() ): mixed {
		return $this->reflection->getMethod( $name )->invokeArgs( $this->exporter, $args );
	}

	public function test_normalize_scalar_returns_string_for_scalar(): void {
		$this::assertSame( 'hello', $this->call( 'normalize_scalar', array( 'hello', 'default' ) ) );
	}

	public function test_normalize_scalar_returns_default_for_empty_and_non_scalar_values(): void {
		$this::assertSame( 'fallback', $this->call( 'normalize_scalar', array( '   ', 'fallback' ) ) );
		$this::assertSame( 'fallback', $this->call( 'normalize_scalar', array( array( 'x' ), 'fallback' ) ) );
		$this::assertSame( 'fallback', $this->call( 'normalize_scalar', array( new \stdClass(), 'fallback' ) ) );
		$this::assertSame( 'fallback', $this->call( 'normalize_scalar', array( null, 'fallback' ) ) );
	}

	public function test_normalize_scalar_trims_whitespace(): void {
		$this::assertSame( 'hello', $this->call( 'normalize_scalar', array( '  hello  ', 'default' ) ) );
	}

	public function test_get_format_option_returns_default_and_present_value(): void {
		$this::assertSame( 'fallback', $this->call( 'get_format_option', array( 'missing', 'fallback' ) ) );
		$this->exporter->apply_format_options( array( 'page_size' => 'A3' ) );
		$this::assertSame( 'A3', $this->call( 'get_format_option', array( 'page_size', 'A4' ) ) );
	}

	public function test_resolve_pdf_page_size_defaults_and_accepts_supported_values(): void {
		$this::assertSame( 'A4', $this->call( 'resolve_pdf_page_size' ) );
		foreach ( array( 'A3', 'Letter', 'Legal', 'A4' ) as $size ) {
			$this->exporter->apply_format_options( array( 'sscribe_pdf_page_size' => $size ) );
			$this::assertSame( $size, $this->call( 'resolve_pdf_page_size' ) );
		}
	}

	public function test_resolve_pdf_page_size_falls_back_for_invalid_value(): void {
		$this->exporter->apply_format_options( array( 'sscribe_pdf_page_size' => 'BOGUS' ) );
		$this::assertSame( 'A4', $this->call( 'resolve_pdf_page_size' ) );
	}

	public function test_prepare_html_for_pdf_engine_keeps_plain_markup(): void {
		$html = '<p>hello</p>';
		$this::assertSame( $html, $this->call( 'prepare_html_for_pdf_engine', array( $html, false ) ) );
	}

	public function test_prepare_html_for_pdf_engine_strips_font_and_remote_resource_controls(): void {
		$html = '<style>@import "https://bad.example/x.css";'
			. '@font-face{font-family:x;src:url(https://bad.example/x.woff2);}'
			. '.x{font-family:Georgia;color:red;background:url(https://bad.example/a.png);}'
			. '</style><p>hello</p>';

		$result = $this->call( 'prepare_html_for_pdf_engine', array( $html, false ) );

		$this::assertStringNotContainsString( 'bad.example', $result );
		$this::assertStringNotContainsString( '@font-face', strtolower( $result ) );
		$this::assertStringNotContainsString( '@import', strtolower( $result ) );
		$this::assertStringNotContainsString( 'font-family', strtolower( $result ) );
		$this::assertStringContainsString( 'color:red', str_replace( ' ', '', $result ) );
	}

	public function test_public_pdf_contract_is_stable(): void {
		$this::assertSame( 'pdf', $this->exporter->get_extension() );
		$this::assertSame( 'application/pdf', $this->exporter->get_mime_type() );
		$this::assertTrue( method_exists( $this->exporter, 'export' ) );
		$this::assertTrue( method_exists( $this->exporter, 'apply_format_options' ) );
	}

	public function test_export_return_type_is_result(): void {
		$ref = new \ReflectionMethod( $this->exporter, 'export' );
		$this::assertSame( 'SScribe_Result', $ref->getReturnType()->getName() );
	}

	public function test_construct_accepts_null_arguments(): void {
		$exporter = new \SScribe_PDF_Exporter( null, null, null );
		$this::assertInstanceOf( \SScribe_PDF_Exporter::class, $exporter );
	}
}
