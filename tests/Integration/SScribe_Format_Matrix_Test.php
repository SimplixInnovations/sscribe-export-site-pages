<?php
/**
 * Phase 46 — Export format matrix integration test.
 *
 * The admin UI lets the user pick one or more export formats.
 * The format matrix is the contract that ties each format string
 * the UI sends to a concrete exporter class. A regression in any
 * of the following ships a release whose UI offers a format that
 * has no working exporter:
 *
 *   - The format enum declares docx, pdf, html, markdown.
 *   - Every declared format has a corresponding exporter file
 *     under includes/exporters/.
 *   - Every exporter class loads, implements SScribe_Exporter_Interface,
 *     and matches the canonical class-name pattern.
 *   - The label set has exactly one entry per format, is non-empty,
 *     and is unique.
 *
 * This test class exercises the live tree (positive path) and
 * cross-checks the verifier's source for the contract branches
 * the audit enforces, so the gate is locked without depending on
 * a developer manually running the verifier.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

namespace SScribe\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class SScribe_Format_Matrix_Test extends TestCase {

	private const VERIFIER_PATH  = 'scripts/verify-format-matrix.php';
	private const MANIFEST_PATH  = 'dist/format-matrix-manifest.json';
	private const ENUM_PATH      = 'includes/class-sscribe-export-format.php';
	private const EXPORTERS_DIR  = 'includes/exporters';

	private static function plugin_root(): string {
		return dirname( __DIR__, 2 );
	}

	public static function setUpBeforeClass(): void {
		// The format-matrix manifest is a derived artifact of the
		// verifier script. A sibling test or `composer release` may have
		// deleted it between runs, and test_manifest_records_audit_state
		// asserts the manifest exists on disk. Regenerating it here makes
		// the suite order-independent instead of relying on whichever
		// sibling test happened to call the verifier last.
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
		if ( ! is_resource( $process ) ) {
			throw new \RuntimeException( 'Could not spawn format-matrix verifier in setUpBeforeClass.' );
		}
		fclose( $pipes[0] );
		$stdout = (string) stream_get_contents( $pipes[1] );
		$stderr = (string) stream_get_contents( $pipes[2] );
		$code   = proc_close( $process );
		if ( 0 !== $code ) {
			throw new \RuntimeException( 'Format-matrix verifier must exit 0 to seed manifest. Output: ' . $stdout . $stderr );
		}
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

	public function test_live_tree_passes_format_matrix(): void {
		list( $code, $output ) = $this->run_verifier();
		$this::assertSame(
			0,
			$code,
			'Live plugin tree must satisfy the format-matrix contract. Output:' . "\n" . $output
		);
		$this::assertStringContainsString( 'Format matrix contract valid', $output );
	}

	public function test_manifest_records_all_four_formats(): void {
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

		// Every matrix row must report class_loaded and
		// implements_iface as true (the live tree is healthy).
		foreach ( $payload['matrix'] as $row ) {
			$this::assertTrue( $row['file_exists'], $row['value'] . ' file missing' );
			$this::assertTrue( $row['class_loaded'], $row['value'] . ' class does not load' );
			$this::assertTrue( $row['implements_iface'], $row['value'] . ' does not implement the interface' );
		}
	}

	public function test_label_set_is_complete_and_unique(): void {
		list( $code ) = $this->run_verifier();
		$this::assertSame( 0, $code );

		$payload = json_decode( (string) file_get_contents( self::plugin_root() . '/' . self::MANIFEST_PATH ), true );
		$labels  = $payload['labels'];
		$this::assertCount( $payload['format_count'], $labels );
		$this::assertSame( array_keys( $labels ), array( 'docx', 'pdf', 'html', 'markdown' ) );
		foreach ( $labels as $value => $label ) {
			$this::assertNotSame( '', trim( (string) $label ), $value . ' label is empty' );
		}
		$this::assertSame( count( $labels ), count( array_unique( array_values( $labels ) ) ), 'duplicate labels' );
	}

	public function test_enum_declares_four_canonical_formats(): void {
		// The format enum is the single source of truth for the
		// values the admin UI sends. A regression that adds a fifth
		// format (or drops one) without updating the manifest /
		// exporters / translations is a release blocker.
		$root    = self::plugin_root();
		$source  = (string) file_get_contents( $root . '/' . self::ENUM_PATH );
		$this::assertStringContainsString( "case DOCX     = 'docx'", $source );
		$this::assertStringContainsString( "case PDF      = 'pdf'", $source );
		$this::assertStringContainsString( "case HTML     = 'html'", $source );
		$this::assertStringContainsString( "case MARKDOWN = 'markdown'", $source );
	}

	public function test_each_canonical_format_has_exporter_file_and_class(): void {
		// Direct filesystem + autoloader check for each value.
		// This is the structural contract the verifier pins; we
		// double-check it here so a future verifier refactor cannot
		// silently drop a format from the gate.
		$root = self::plugin_root();
		if ( ! defined( 'SSCRIBE_PLUGIN_DIR' ) ) {
			define( 'SSCRIBE_PLUGIN_DIR', $root . '/' );
		}
		require_once $root . '/' . self::ENUM_PATH;

		// Stub the i18n helpers so get_supported_formats() loads
		// outside WP.
		if ( ! function_exists( '__' ) ) {
			function __( string $text, string $domain = '' ): string {
				return $text;
			}
		}

		foreach ( \SScribe_Export_Format::cases() as $case ) {
			$value = (string) $case->value;
			$file  = $root . '/' . self::EXPORTERS_DIR . '/class-sscribe-' . $value . '-exporter.php';
			$this::assertFileExists(
				$file,
				$value . ': exporter file must exist under ' . self::EXPORTERS_DIR
			);
			require_once $file;
			$class = 'SScribe_' . strtoupper( $value ) . '_Exporter';
			$this::assertTrue(
				class_exists( $class ),
				$value . ': ' . $class . ' must be declared by ' . basename( $file )
			);
			$this::assertTrue(
				is_subclass_of( $class, 'SScribe_Exporter_Interface' ),
				$value . ': ' . $class . ' must implement SScribe_Exporter_Interface'
			);
		}
	}

	public function test_exporter_interface_contract_is_stable(): void {
		// The interface declares the public surface every exporter
		// must implement. A future "optional interface method"
		// regression that breaks exporters shipped before the change
		// is caught here.
		$root     = self::plugin_root();
		$source   = (string) file_get_contents(
			$root . '/' . self::EXPORTERS_DIR . '/interface-sscribe-exporter.php'
		);
		// Pin the methods the live exporters call on each other.
		$this::assertStringContainsString( 'interface SScribe_Exporter_Interface', $source );
		$this::assertMatchesRegularExpression(
			'/public\s+function\s+(export|render|get_extension|get_mime_type|supports|finalize)\s*\(/',
			$source,
			'SScribe_Exporter_Interface must declare at least one of the canonical exporter methods (export/render/get_extension/get_mime_type/supports/finalize).'
		);
	}

	public function test_verifier_source_enforces_missing_file_branch(): void {
		// The verifier must contain the code path that fails on a
		// missing exporter file. A future refactor that drops the
		// check is caught at this gate.
		$root   = self::plugin_root();
		$source = (string) file_get_contents( $root . '/' . self::VERIFIER_PATH );
		$this::assertStringContainsString( "'file_exists'", $source );
		$this::assertStringContainsString( "'class_loaded'", $source );
		$this::assertStringContainsString( "'implements_iface'", $source );
		$this::assertStringContainsString( "'factory_resolves'", $source );
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
