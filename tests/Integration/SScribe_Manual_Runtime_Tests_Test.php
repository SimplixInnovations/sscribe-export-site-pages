<?php
/**
 * Phase 69 — Manual / runtime tests integration test.
 *
 * Pins the Phase 69 manual runbook at the PHPUnit boundary so a
 * regression that drops the runbook or strips required canonical
 * sections fails locally before it ships.
 *
 * The authoritative runbook lives at
 * docs/MANUAL_RUNTIME_TESTS_v2.0.0.md (Phase 69 evidence). The
 * runtime guard contract lives in
 * scripts/verify-manual-runtime-tests.php.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

namespace SScribe\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class SScribe_Manual_Runtime_Tests_Test extends TestCase {

	private const VERIFIER_PATH = 'scripts/verify-manual-runtime-tests.php';
	private const MANIFEST_PATH = 'dist/manual-runtime-tests-manifest.json';
	private const RUNBOOK_DOC   = 'docs/MANUAL_RUNTIME_TESTS_v2.0.0.md';

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
			'Phase 69 verifier must return exit 0. Output:' . "\n" . $output
		);
		$this::assertStringContainsString( 'Manual runtime tests runbook contract valid', $output );
	}

	public function test_manifest_records_all_rules_passing(): void {
		list( $code ) = $this->run_verifier();
		$this::assertSame( 0, $code );

		$payload = json_decode( (string) file_get_contents( self::plugin_root() . '/' . self::MANIFEST_PATH ), true );
		$this::assertIsArray( $payload );
		$this::assertTrue( $payload['passes'] );
		$this::assertGreaterThanOrEqual( 4, $payload['rule_count'] );
		$this::assertSame( $payload['rule_count'], $payload['passed_count'] );
		$this::assertSame( 0, $payload['errors_count'] );
	}

	public function test_runbook_doc_exists(): void {
		$this::assertFileExists(
			self::plugin_root() . '/' . self::RUNBOOK_DOC,
			'Manual runtime test runbook must exist at docs/MANUAL_RUNTIME_TESTS_v2.0.0.md.'
		);
	}

	public function test_runbook_has_canonical_sections(): void {
		$src = (string) file_get_contents( self::plugin_root() . '/' . self::RUNBOOK_DOC );
		foreach ( array(
			'## Why this exists',
			'## Canonical scenarios',
			'## Per-scenario acceptance criteria',
			'## What "exact ZIP" means',
			'## Evidence recording',
			'## How an independent auditor verifies this',
		) as $section ) {
			$this::assertStringContainsString(
				$section,
				$src,
				'Manual runtime runbook must contain canonical section: ' . $section
			);
		}
	}

	public function test_runbook_uses_current_versioned_release_artifact_contract(): void {
		$src = (string) file_get_contents( self::plugin_root() . '/' . self::RUNBOOK_DOC );

		$this::assertStringContainsString( 'sscribe-export-site-pages-{VERSION}.zip', $src );
		$this::assertStringNotContainsString( 'dist/sscribe-export-site-pages.zip', $src );
		$this::assertStringContainsString( 'SSCRIBE_VERSION', $src );
	}

	public function test_runbook_covers_six_required_environments(): void {
		$src = (string) file_get_contents( self::plugin_root() . '/' . self::RUNBOOK_DOC );
		foreach ( array(
			'Standard WordPress',
			'WordPress + WPML',
			'Redis object cache ON',
			'Redis object cache OFF',
			'OpenLiteSpeed',
			'Cloudflare',
		) as $env ) {
			$this::assertStringContainsString(
				$env,
				$src,
				'Manual runtime runbook must cover required environment: ' . $env
			);
		}
	}
}
