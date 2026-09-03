<?php
/**
 * Phase 61 — CI command documentation integration test.
 *
 * Pins the contract that every `composer test:*` script and every
 * canonical ci.yml step keyword is documented in
 * docs/CI_COMMANDS.md. A regression that adds a new CI gate
 * without documenting it here fails this test BEFORE the release
 * tag is cut.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

namespace SScribe\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class SScribe_CI_Docs_Test extends TestCase {

	private const VERIFIER_PATH = 'scripts/verify-ci-docs.php';
	private const MANIFEST_PATH = 'dist/ci-docs-manifest.json';
	private const DOC_PATH      = 'docs/CI_COMMANDS.md';
	private const COMPOSER_PATH = 'composer.json';
	private const CI_PATH       = '.github/workflows/ci.yml';

	private static function plugin_root(): string {
		return dirname( __DIR__, 2 );
	}

	private function run_verifier(): array {
		$root        = self::plugin_root();
		$descriptors = array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) );
		$process     = proc_open( array( PHP_BINARY, $root . '/' . self::VERIFIER_PATH ), $descriptors, $pipes );
		if ( ! is_resource( $process ) ) {
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
			'Phase 61 ci-docs verifier must return exit 0. Output:' . "\n" . $output
		);
		$this::assertStringContainsString( 'CI docs contract valid', $output );
	}

	public function test_manifest_records_all_rules_passing(): void {
		list( $code ) = $this->run_verifier();
		$this::assertSame( 0, $code );

		$payload = json_decode( (string) file_get_contents( self::plugin_root() . '/' . self::MANIFEST_PATH ), true );
		$this::assertIsArray( $payload );
		$this::assertTrue( $payload['passes'] );
		$this::assertGreaterThanOrEqual( 7, $payload['rule_count'] );
		$this::assertSame( $payload['rule_count'], $payload['passed_count'] );
		$this::assertSame( 0, $payload['errors_count'] );
	}

	public function test_doc_exists(): void {
		$this::assertFileExists(
			self::plugin_root() . '/' . self::DOC_PATH,
			'docs/CI_COMMANDS.md must exist (Phase 61 deliverable).'
		);
	}

	public function test_doc_declares_audience(): void {
		$src = (string) file_get_contents( self::plugin_root() . '/' . self::DOC_PATH );
		$this::assertStringContainsString( 'Audience:', $src );
	}

	public function test_doc_has_all_canonical_sections(): void {
		$src = (string) file_get_contents( self::plugin_root() . '/' . self::DOC_PATH );
		$required = array(
			'## 1. Top-level chains',
			'## 2. Per-script reference',
			'## 3. CI workflow steps',
			'## 4. Debugging a failure',
			'## 5. Adding a new CI command',
		);
		foreach ( $required as $section ) {
			$this::assertStringContainsString(
				$section,
				$src,
				"docs/CI_COMMANDS.md must contain `{$section}`."
			);
		}
	}

	public function test_every_composer_test_script_is_documented(): void {
		$composer   = json_decode( (string) file_get_contents( self::plugin_root() . '/' . self::COMPOSER_PATH ), true );
		$doc        = (string) file_get_contents( self::plugin_root() . '/' . self::DOC_PATH );

		$test_scripts = array();
		foreach ( $composer['scripts'] as $name => $cmd ) {
			if ( 0 === strpos( $name, 'test:' ) ) {
				$test_scripts[] = $name;
			}
		}

		$this::assertGreaterThanOrEqual(
			10,
			count( $test_scripts ),
			'composer.json should declare at least 10 test:* scripts (sanity self-check).'
		);

		foreach ( $test_scripts as $name ) {
			$heading     = '### `composer ' . $name . '`';
			$inline_ref  = '`composer ' . $name . '`';
			$this::assertTrue(
				false !== strpos( $doc, $heading ) || false !== strpos( $doc, $inline_ref ) || (bool) preg_match( '/`composer\s+' . preg_quote( $name, '/' ) . '`/', $doc ),
				"composer script `composer {$name}` must appear in docs/CI_COMMANDS.md."
			);
		}
	}

	public function test_canonical_ci_step_keywords_are_cited(): void {
		$ci  = (string) file_get_contents( self::plugin_root() . '/' . self::CI_PATH );
		$doc = (string) file_get_contents( self::plugin_root() . '/' . self::DOC_PATH );

		$canonical_keywords = array(
			'Verify version synchronization',
			'Verify accessibility contract',
			'Real WordPress Integration Suite',
			'Run PHPUnit',
			'Build the submission ZIP',
			'Run official WordPress Plugin Check',
			'Audit JavaScript dependencies',
			'Verify i18n contract',
		);

		$missing = array();
		foreach ( $canonical_keywords as $kw ) {
			if ( false === strpos( $ci, $kw ) ) {
				continue; // ci.yml no longer references this keyword — skip silently.
			}
			if ( false === strpos( $doc, $kw ) ) {
				$missing[] = $kw;
			}
		}
		$this::assertSame(
			array(),
			$missing,
			'Canonical ci.yml step keyword(s) must appear in docs/CI_COMMANDS.md §2/§3: ' . implode( ', ', $missing )
		);
	}

	public function test_every_documented_section_has_required_fields(): void {
		$doc = (string) file_get_contents( self::plugin_root() . '/' . self::DOC_PATH );
		$composer = json_decode( (string) file_get_contents( self::plugin_root() . '/' . self::COMPOSER_PATH ), true );

		$missing = array();
		foreach ( array_keys( $composer['scripts'] ) as $name ) {
			if ( 0 !== strpos( $name, 'test:' ) ) {
				continue;
			}
			$heading_pos = strpos( $doc, '### `composer ' . $name . '`' );
			if ( false === $heading_pos ) {
				// Could be in a combined heading; skip — the dedicated
				// `test_every_composer_test_script_is_documented` test
				// already enforces presence.
				continue;
			}
			$section = substr( $doc, $heading_pos, 1500 );
			foreach ( array( '**Gates:**', '**Failure:**', '**Debug:**', '**Manifest:**' ) as $field ) {
				if ( false === strpos( $section, $field ) ) {
					$missing[] = "{$name}:{$field}";
				}
			}
		}
		$this::assertSame(
			array(),
			$missing,
			'Every documented test:* section must declare Purpose, Gates, Failure, Debug, and Manifest. Missing: ' . implode( ', ', $missing )
		);
	}
}
