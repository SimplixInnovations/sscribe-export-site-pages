<?php
/**
 * Phase 40 — PHPStan ignore-rule audit integration test.
 *
 * The verifier (scripts/verify-phpstan-ignores.php) classifies every
 * `ignoreErrors:` entry in phpstan.neon as either:
 *
 *   - `unavoidable_third_party` — WordPress / mPDF / DOM stubs are
 *     missing; the rule suppresses a real PHPStan error that we
 *     cannot fix without re-stubs.
 *   - `technical_debt` — known PHPStan false positives that the
 *     team has explicitly accepted.
 *
 * Anything else is a release blocker:
 *
 *   - A `broad_pattern` (e.g. `admin/*` or `vendor/*`) silently
 *     disables analysis for an entire subtree — the spec explicitly
 *     forbids "ignore everything in admin/*" or equivalent.
 *   - An `unknown` pattern is unclassified; either it is stale or
 *     the classification table is missing a case.
 *
 * The audit also re-runs PHPStan at Level 7 as a smoke gate so a
 * broken ignore rule that produces no errors but disables a real
 * check still surfaces.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

namespace SScribe\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class SScribe_PHPStan_Ignores_Test extends TestCase {

	private const SCRIPT_PATH    = 'scripts/verify-phpstan-ignores.php';
	private const MANIFEST_PATH  = 'dist/phpstan-ignore-manifest.json';

	private static function plugin_root(): string {
		return dirname( __DIR__, 2 );
	}

	private function run_verifier(): array {
		$root = self::plugin_root();
		$descriptors = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);
		$process = proc_open(
			array( PHP_BINARY, $root . '/' . self::SCRIPT_PATH ),
			$descriptors,
			$pipes
		);
		$this::assertIsResource( $process );
		$stdout = (string) stream_get_contents( $pipes[1] );
		$stderr = (string) stream_get_contents( $pipes[2] );
		$code   = proc_close( $process );
		return array( (int) $code, $stdout . $stderr );
	}

	public function test_well_formed_phpstan_neon_passes(): void {
		list( $code, $output ) = $this->run_verifier();
		$this::assertSame(
			0,
			$code,
			'Live phpstan.neon must satisfy the audit. Output:' . "\n" . $output
		);
		$this::assertStringContainsString( 'audit holds', $output );
	}

	public function test_manifest_is_persisted_with_classification(): void {
		$paths = array( self::MANIFEST_PATH );
		list( $code ) = $this->run_verifier();
		$this::assertSame( 0, $code );

		$abs     = self::plugin_root() . '/' . self::MANIFEST_PATH;
		$payload = (string) file_get_contents( $abs );
		$decoded = json_decode( $payload, true );
		$this::assertIsArray( $decoded );
		$this::assertSame( 7, $decoded['level'] );
		$this::assertGreaterThan( 0, $decoded['total_rules'] );

		// Every rule must carry a non-empty classification.
		foreach ( $decoded['rules'] as $rule ) {
			$this::assertNotEmpty( $rule['classification'] );
			$this::assertNotSame(
				'broad_pattern',
				$rule['classification'],
				'Broad patterns are a release blocker: ' . $rule['pattern']
			);
			$this::assertNotSame(
				'unknown',
				$rule['classification'],
				'Unclassified pattern must be classified or removed: ' . $rule['pattern']
			);
		}

		// At least one rule per accepted bucket — proves the
		// classification table is in active use.
		$counts = $decoded['counts'];
		$this::assertGreaterThan( 0, $counts['unavoidable_third_party'] );
		$this::assertGreaterThan( 0, $counts['technical_debt'] );
	}

	public function test_no_broad_pattern_present(): void {
		// Defense in depth: even if the verifier's broad-pattern
		// detector regresses, scanning phpstan.neon directly for
		// `admin/*` / `vendor/*` ignore patterns catches it.
		$root    = self::plugin_root();
		$config  = (string) file_get_contents( $root . '/phpstan.neon' );
		$this::assertStringNotContainsString( "'\#admin/*\#'", $config, 'admin/* ignore pattern is a release blocker' );
		$this::assertStringNotContainsString( "'\#vendor/*\#'", $config, 'vendor/* ignore pattern is a release blocker' );
	}

	public function test_phpstan_level7_passes(): void {
		$root       = self::plugin_root();
		$descriptors = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);
		$process = proc_open(
			array( PHP_BINARY, $root . '/vendor/bin/phpstan', 'analyse', '--memory-limit=512M', '--no-progress' ),
			$descriptors,
			$pipes
		);
		$this::assertIsResource( $process );
		$stdout = (string) stream_get_contents( $pipes[1] );
		$stderr = (string) stream_get_contents( $pipes[2] );
		$code   = proc_close( $process );
		$this::assertSame(
			0,
			$code,
			'PHPStan Level 7 must remain green. Output:' . "\n" . $stdout . $stderr
		);
	}
}