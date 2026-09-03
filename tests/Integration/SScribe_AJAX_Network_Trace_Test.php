<?php
/**
 * Phase 66 — AJAX network trace integration test.
 *
 * Pins the canonical AJAX surface at the PHPUnit boundary so a
 * regression that adds a new wp_ajax_sscribe_* action without
 * updating the trace doc fails locally before it ships.
 *
 * The authoritative audit doc lives at
 * docs/AJAX_NETWORK_TRACE_v2.0.0.md (Phase 66 evidence). The
 * runtime guard contract lives in SScribe_Loader::add_guarded_ajax_action
 * and verify_request_authorization() (Phase 49 contract).
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

namespace SScribe\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class SScribe_AJAX_Network_Trace_Test extends TestCase {

	private const VERIFIER_PATH = 'scripts/verify-ajax-network-trace.php';
	private const MANIFEST_PATH = 'dist/ajax-network-trace-manifest.json';
	private const TRACE_DOC     = 'docs/AJAX_NETWORK_TRACE_v2.0.0.md';

	private static function plugin_root(): string {
		return dirname( __DIR__, 2 );
	}

	private function run_verifier(): array {
		$root        = self::plugin_root();
		$descriptors = array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) );
		$process     = proc_open( array( PHP_BINARY, $root . '/' . self::VERIFIER_PATH ), $descriptors, $pipes );
		if ( ! \is_resource( $process ) ) {
			throw new \RuntimeException( 'Could not spawn verifier subprocess.' );
		}
		$stdout = (string) stream_get_contents( $pipes[1] );
		$stderr = (string) stream_get_contents( $pipes[2] );
		$code   = proc_close( $process );
		return array( (int) $code, $stdout . $stderr );
	}

	public function test_live_manifest_passes_verifier(): void {
		list( $code, $output ) = $this->run_verifier();
		$this::assertSame(
			0,
			$code,
			'Phase 66 verifier must return exit 0. Output:' . "\n" . $output
		);
		$this::assertStringContainsString( 'AJAX network trace contract valid', $output );
	}

	public function test_manifest_records_all_rules_passing(): void {
		list( $code ) = $this->run_verifier();
		$this::assertSame( 0, $code );

		$payload = json_decode( (string) file_get_contents( self::plugin_root() . '/' . self::MANIFEST_PATH ), true );
		$this::assertIsArray( $payload );
		$this::assertTrue( $payload['passes'] );
		$this::assertGreaterThanOrEqual( 5, $payload['rule_count'] );
		$this::assertSame( $payload['rule_count'], $payload['passed_count'] );
		$this::assertSame( 0, $payload['errors_count'] );
		$this::assertGreaterThan( 10, count( $payload['real_actions'] ) );
	}

	public function test_trace_doc_exists(): void {
		$this::assertFileExists(
			self::plugin_root() . '/' . self::TRACE_DOC,
			'AJAX network trace doc must exist at docs/AJAX_NETWORK_TRACE_v2.0.0.md.'
		);
	}

	public function test_trace_doc_has_canonical_sections(): void {
		$src = (string) file_get_contents( self::plugin_root() . '/' . self::TRACE_DOC );
		foreach ( array(
			'## Why this exists',
			'## Conventions',
			'## Endpoints',
			'## Guard surface',
		) as $section ) {
			$this::assertStringContainsString(
				$section,
				$src,
				'AJAX trace doc must contain canonical section: ' . $section
			);
		}
	}

	public function test_endpoints_table_has_canonical_columns(): void {
		$src = (string) file_get_contents( self::plugin_root() . '/' . self::TRACE_DOC );
		foreach ( array(
			'Action',
			'Handler',
			'Capability',
			'Nonce action',
			'Rate-limit bucket',
			'Response shape',
		) as $column ) {
			$this::assertStringContainsString(
				$column,
				$src,
				'Endpoints table must declare column: ' . $column
			);
		}
	}

	public function test_no_public_ajax_actions_registered(): void {
		$root  = self::plugin_root();
		$nopriv_actions = array();
		foreach ( array( $root . '/includes', $root . '/admin' ) as $dir ) {
			if ( ! is_dir( $dir ) ) {
				continue;
			}
			$iter = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $dir, \RecursiveDirectoryIterator::SKIP_DOTS )
			);
			foreach ( $iter as $file_info ) {
				if ( $file_info->isDir() || '.php' !== substr( $file_info->getFilename(), -4 ) ) {
					continue;
				}
				$src = (string) file_get_contents( $file_info->getPathname() );
				$stripped = (string) ( preg_replace( '!/\*.*?\*/!s', '', $src ) ?? $src );
				if ( preg_match_all( "/['\"](wp_ajax_nopriv_sscribe_[a-z_]+)['\"]/", $stripped, $hits ) ) {
					foreach ( $hits[1] as $hit ) {
						$nopriv_actions[] = $hit;
					}
				}
			}
		}
		$this::assertSame(
			array(),
			$nopriv_actions,
			'Plugin must not register any wp_ajax_nopriv_sscribe_* action (admin-only AJAX surface). Found: ' . implode( ', ', $nopriv_actions )
		);
	}

	public function test_real_ajax_actions_match_manifest(): void {
		$payload = json_decode( (string) file_get_contents( self::plugin_root() . '/' . self::MANIFEST_PATH ), true );
		$this::assertGreaterThanOrEqual( 15, count( $payload['real_actions'] ) );
		// Every real action must be a known handler category.
		foreach ( $payload['real_actions'] as $action ) {
			$this::assertStringStartsWith( 'wp_ajax_sscribe_', $action, 'Real AJAX action must follow wp_ajax_sscribe_* naming convention: ' . $action );
		}
	}
}
