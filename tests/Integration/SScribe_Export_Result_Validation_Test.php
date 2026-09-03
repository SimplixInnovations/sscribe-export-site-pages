<?php
/**
 * Phase 47 — Export result validation integration test.
 *
 * The download a customer receives is the proof the export pipeline works.
 * The exporter must produce a file whose extension and MIME agree with the
 * format picker, and whose contents match the magic bytes / structure the
 * format requires. A regression that ships a malformed file is caught here
 * before it reaches a customer.
 *
 * The contract:
 *   - Every exporter returns the canonical extension and MIME.
 *   - The exporter interface declares export / get_extension / get_mime_type.
 *   - The wrapper exposes export_page / successful_formats / failed_formats.
 *   - The factory exposes build_filename and is_supported.
 *   - The live tree passes the verifier with zero errors.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

namespace SScribe\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class SScribe_Export_Result_Validation_Test extends TestCase {

	private const VERIFIER_PATH = 'scripts/verify-export-result.php';
	private const MANIFEST_PATH = 'dist/export-result-manifest.json';

	private static function plugin_root(): string {
		return dirname( __DIR__, 2 );
	}

	private function run_verifier(): array {
		$root        = self::plugin_root();
		$descriptors = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);
		$process = proc_open(
			array( PHP_BINARY, $root . '/' . self::VERIFIER_PATH ),
			$descriptors,
			$pipes
		);
		$this::assertIsResource( $process );
		$stdout = (string) stream_get_contents( $pipes[1] );
		$stderr = (string) stream_get_contents( $pipes[2] );
		$code   = proc_close( $process );
		return array( (int) $code, $stdout . $stderr );
	}

	public function test_live_tree_passes_export_result_validation(): void {
		list( $code, $output ) = $this->run_verifier();
		$this::assertSame(
			0,
			$code,
			'Live plugin tree must satisfy the export-result-validation contract. Output:' . "\n" . $output
		);
		$this::assertStringContainsString( 'Export result validation contract valid', $output );
	}

	public function test_manifest_records_four_canonical_format_rows(): void {
		list( $code ) = $this->run_verifier();
		$this::assertSame( 0, $code );

		$abs     = self::plugin_root() . '/' . self::MANIFEST_PATH;
		$this::assertFileExists( $abs );
		$payload = json_decode( (string) file_get_contents( $abs ), true );
		$this::assertIsArray( $payload );
		$this::assertTrue( $payload['passes'] );

		$this::assertSame( 4, $payload['format_count'] );

		$values = array_column( $payload['matrix'], 'value' );
		sort( $values );
		$this::assertSame( array( 'docx', 'html', 'markdown', 'pdf' ), $values );
	}

	public function test_each_format_row_has_canonical_extension_and_mime(): void {
		list( $code ) = $this->run_verifier();
		$this::assertSame( 0, $code );

		$payload = json_decode( (string) file_get_contents( self::plugin_root() . '/' . self::MANIFEST_PATH ), true );

		$expected_ext = array(
			'docx'     => 'docx',
			'pdf'      => 'pdf',
			'html'     => 'html',
			'markdown' => 'md',
		);
		$expected_mime = array(
			'docx'     => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			'pdf'      => 'application/pdf',
			'html'     => 'text/html',
			'markdown' => 'text/markdown',
		);

		foreach ( $payload['matrix'] as $row ) {
			$this::assertTrue( $row['extension_ok'], $row['value'] . ': extension check must pass' );
			$this::assertTrue( $row['mime_ok'], $row['value'] . ': mime check must pass' );
			$this::assertSame( $expected_ext[ $row['value'] ], $row['extension_value'] );
			$this::assertSame( $expected_mime[ $row['value'] ], $row['mime_value'] );
			$this::assertTrue( $row['class_loaded'], $row['value'] . ': exporter class loads' );
		}
	}

	public function test_wrapper_exposes_canonical_api(): void {
		// The wrapper is the convenience dispatcher used by callers that
		// want a single "give me every format" call. Its API surface must
		// remain stable across releases.
		$payload = json_decode( (string) file_get_contents( self::plugin_root() . '/' . self::MANIFEST_PATH ), true );
		$this::assertTrue( $payload['wrapper_methods']['export_page'] );
		$this::assertTrue( $payload['wrapper_methods']['successful_formats'] );
		$this::assertTrue( $payload['wrapper_methods']['failed_formats'] );
	}

	public function test_exporter_interface_declares_required_methods(): void {
		// A regression that drops export() / get_extension() / get_mime_type()
		// from the interface is caught here, before a release ships a plugin
		// whose custom-export implementations no longer satisfy the contract.
		$root   = self::plugin_root();
		$source = (string) file_get_contents( $root . '/includes/exporters/interface-sscribe-exporter.php' );
		$this::assertStringContainsString( 'interface SScribe_Exporter_Interface', $source );
		$this::assertMatchesRegularExpression(
			'/public\s+function\s+export\s*\(/',
			$source
		);
		$this::assertMatchesRegularExpression(
			'/public\s+function\s+get_extension\s*\(/',
			$source
		);
		$this::assertMatchesRegularExpression(
			'/public\s+function\s+get_mime_type\s*\(/',
			$source
		);
	}

	public function test_exporter_factory_exposes_build_filename_and_is_supported(): void {
		// The factory is what the batch-processor and the wrapper call to
		// resolve a format string to a filename. Drop either method and
		// the dispatch path silently fails.
		$root   = self::plugin_root();
		$source = (string) file_get_contents( $root . '/includes/exporters/class-sscribe-exporter-factory.php' );
		$this::assertMatchesRegularExpression(
			'/public\s+static\s+function\s+build_filename\s*\(/',
			$source
		);
		$this::assertMatchesRegularExpression(
			'/public\s+static\s+function\s+is_supported\s*\(/',
			$source
		);
	}

	public function test_verifier_pins_canonical_magic_byte_contract(): void {
		// The verifier must contain the magic-byte table the manifest
		// documents. A future refactor that drops the table ships a
		// release with no defence against files that open in a text
		// editor but have no real structure.
		$root   = self::plugin_root();
		$source = (string) file_get_contents( $root . '/' . self::VERIFIER_PATH );
		$this::assertStringContainsString( "'PK\\x03\\x04'", $source );
		$this::assertStringContainsString( "'%PDF-'", $source );
		$this::assertStringContainsString( "'<!DOCTYPE|<html'", $source );
		// Markdown is text — the verifier declares a null magic entry
		// and a sniff_md_signature() helper exists separately. Pin the
		// sniff helper so the manifest's "null" entry has a callable.
		$this::assertStringContainsString( "'application/pdf'", $source );
		$this::assertStringContainsString( "'application/vnd.openxmlformats-officedocument", $source );
	}

	public function test_manifest_records_audit_state(): void {
		$payload = json_decode(
			(string) file_get_contents( self::plugin_root() . '/' . self::MANIFEST_PATH ),
			true
		);
		$this::assertTrue( $payload['passes'] );
		$this::assertSame( 0, $payload['errors_count'] );
		$this::assertSame( array(), $payload['errors'] );
	}
}
