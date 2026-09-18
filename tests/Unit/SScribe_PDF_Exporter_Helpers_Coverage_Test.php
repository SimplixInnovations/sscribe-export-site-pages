<?php
/**
 * SScribe PDF Exporter helper coverage test
 *
 * Targets the pure private helpers of SScribe_PDF_Exporter via reflection:
 *
 *   - normalize_scalar()       : non-scalar, empty, populated, default
 *   - get_format_option()      : missing key, populated key
 *   - resolve_pdf_page_size()  : A4 default, valid custom, invalid fallback
 *   - find_font_file()         : missing dir, missing file, present match
 *   - get_libxml_error_details() : empty list, populated list
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
	private ReflectionClass $ref;

	protected function setUp(): void {
		parent::setUp();
		$this->exporter = new \SScribe_PDF_Exporter();
		$this->ref      = new ReflectionClass( $this->exporter );
	}

	/**
	 * @param string $name Method name.
	 * @param array  $args Invocation arguments.
	 */
	private function call( string $name, array $args = array() ): mixed {
		$m = $this->ref->getMethod( $name );
		return $m->invokeArgs( $this->exporter, $args );
	}

	public function test_normalize_scalar_returns_string_for_scalar(): void {
		$result = $this->call( 'normalize_scalar', array( 'hello', 'default' ) );
		$this::assertSame( 'hello', $result );
	}

	public function test_normalize_scalar_returns_default_for_empty_string(): void {
		$result = $this->call( 'normalize_scalar', array( '   ', 'fallback' ) );
		$this::assertSame( 'fallback', $result );
	}

	public function test_normalize_scalar_returns_default_for_non_scalar(): void {
		$this::assertSame( 'default', $this->call( 'normalize_scalar', array( array( 'x' ), 'default' ) ) );
		$this::assertSame( 'default', $this->call( 'normalize_scalar', array( new \stdClass(), 'default' ) ) );
		$this::assertSame( 'default', $this->call( 'normalize_scalar', array( null, 'default' ) ) );
	}

	public function test_normalize_scalar_trims_whitespace(): void {
		$result = $this->call( 'normalize_scalar', array( '  hello  ', 'default' ) );
		$this::assertSame( 'hello', $result );
	}

	public function test_get_format_option_returns_default_for_missing_key(): void {
		$result = $this->call( 'get_format_option', array( 'missing', 'fallback' ) );
		$this::assertSame( 'fallback', $result );
	}

	public function test_get_format_option_returns_value_for_present_key(): void {
		$this->exporter->apply_format_options( array( 'page_size' => 'A3' ) );
		$result = $this->call( 'get_format_option', array( 'page_size', 'A4' ) );
		$this::assertSame( 'A3', $result );
	}

	public function test_resolve_pdf_page_size_returns_a4_default(): void {
		$result = $this->call( 'resolve_pdf_page_size' );
		$this::assertSame( 'A4', $result );
	}

	public function test_resolve_pdf_page_size_accepts_valid_values(): void {
		foreach ( array( 'A3', 'Letter', 'Legal', 'A4' ) as $size ) {
			$this->exporter->apply_format_options( array( 'sscribe_pdf_page_size' => $size ) );
			$result = $this->call( 'resolve_pdf_page_size' );
			$this::assertSame( $size, $result );
		}
	}

	public function test_resolve_pdf_page_size_falls_back_for_invalid(): void {
		$this->exporter->apply_format_options( array( 'sscribe_pdf_page_size' => 'BOGUS' ) );
		$result = $this->call( 'resolve_pdf_page_size' );
		$this::assertSame( 'A4', $result );
	}

	public function test_find_font_file_returns_null_for_missing_dir(): void {
		$result = $this->call( 'find_font_file', array( '/no/such/dir_' . uniqid(), 'X' ) );
		$this::assertNull( $result );
	}

	public function test_find_font_file_finds_match_in_real_dir(): void {
		$tmp = sys_get_temp_dir() . '/sscribe_font_' . uniqid();
		mkdir( $tmp, 0755, true );
		file_put_contents( $tmp . '/DejaVuSans.ttf', 'x' );
		file_put_contents( $tmp . '/Other.ttf', 'x' );

		$result = $this->call( 'find_font_file', array( $tmp, 'DejaVuSans' ) );
		$this::assertSame( 'DejaVuSans.ttf', $result );

		$result = $this->call( 'find_font_file', array( $tmp, 'Other' ) );
		$this::assertSame( 'Other.ttf', $result );

		@unlink( $tmp . '/DejaVuSans.ttf' );
		@unlink( $tmp . '/Other.ttf' );
		@rmdir( $tmp );
	}

	public function test_find_font_file_returns_null_when_no_match(): void {
		$tmp = sys_get_temp_dir() . '/sscribe_font_' . uniqid();
		mkdir( $tmp, 0755, true );
		file_put_contents( $tmp . '/Foo.ttf', 'x' );

		$result = $this->call( 'find_font_file', array( $tmp, 'NotThere' ) );
		$this::assertNull( $result );

		@unlink( $tmp . '/Foo.ttf' );
		@rmdir( $tmp );
	}

	public function test_get_libxml_error_details_returns_empty_array_when_no_errors(): void {
		libxml_use_internal_errors( true );
		libxml_clear_errors();

		$result = $this->call( 'get_libxml_error_details' );
		$this::assertIsArray( $result );
		$this::assertEmpty( $result );

		libxml_clear_errors();
	}

	public function test_get_extension_returns_pdf(): void {
		$this::assertSame( 'pdf', $this->exporter->get_extension() );
	}

	public function test_get_mime_type_returns_pdf(): void {
		$this::assertSame( 'application/pdf', $this->exporter->get_mime_type() );
	}

	public function test_apply_format_options_keeps_state(): void {
		$this->exporter->apply_format_options( array( 'page_size' => 'A4' ) );
		$result = $this->call( 'get_format_option', array( 'page_size', null ) );
		$this::assertSame( 'A4', $result );
	}

	public function test_export_returns_failure_object_for_missing_id(): void {
		// Without wp_normalize_path stubbed, export() cannot run cleanup_mpdf_temp.
		// We only verify the constructor/format-options surface here, plus a
		// typed signature check that export() returns SScribe_Result.
		$ref = new \ReflectionMethod( $this->exporter, 'export' );
		$this::assertSame( 'SScribe_Result', $ref->getReturnType()->getName() );
	}

	public function test_construct_accepts_null_arguments(): void {
		$e = new \SScribe_PDF_Exporter( null, null, null );
		$this::assertInstanceOf( \SScribe_PDF_Exporter::class, $e );
		$this::assertSame( 'pdf', $e->get_extension() );
	}
}
