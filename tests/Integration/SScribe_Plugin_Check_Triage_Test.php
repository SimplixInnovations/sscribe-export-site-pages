<?php
/**
 * Phase 64 — Plugin Check warning triage integration test.
 *
 * Pins the canonical Plugin Check triage contract at the PHPUnit
 * boundary so a regression that drops the triage doc, removes a
 * canonical section, or introduces a Plugin Check known-bad
 * pattern fails locally before the release tag is cut.
 *
 * The authoritative audit doc lives at
 * docs/PLUGIN_CHECK_WARNINGS_v2.0.0.md (Phase 64 evidence).
 * The live Plugin Check run lives in
 * .github/workflows/ci.yml `plugin-check:` job (Phase 33 contract).
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

namespace SScribe\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class SScribe_Plugin_Check_Triage_Test extends TestCase {

	private const VERIFIER_PATH = 'scripts/verify-plugin-check-triage.php';
	private const MANIFEST_PATH = 'dist/plugin-check-triage-manifest.json';
	private const TRIAGE_DOC    = 'docs/PLUGIN_CHECK_WARNINGS_v2.0.0.md';

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
			'Phase 64 verifier must return exit 0. Output:' . "\n" . $output
		);
		$this::assertStringContainsString( 'Plugin-check triage contract valid', $output );
	}

	public function test_manifest_records_all_rules_passing(): void {
		list( $code ) = $this->run_verifier();
		$this::assertSame( 0, $code );

		$payload = json_decode( (string) file_get_contents( self::plugin_root() . '/' . self::MANIFEST_PATH ), true );
		$this::assertIsArray( $payload );
		$this::assertTrue( $payload['passes'] );
		$this::assertGreaterThanOrEqual( 8, $payload['rule_count'] );
		$this::assertSame( $payload['rule_count'], $payload['passed_count'] );
		$this::assertSame( 0, $payload['errors_count'] );
	}

	public function test_triage_doc_exists(): void {
		$this::assertFileExists(
			self::plugin_root() . '/' . self::TRIAGE_DOC,
			'Plugin Check triage doc must exist at docs/PLUGIN_CHECK_WARNINGS_v2.0.0.md.'
		);
	}

	public function test_triage_doc_has_canonical_sections(): void {
		$src = (string) file_get_contents( self::plugin_root() . '/' . self::TRIAGE_DOC );
		foreach ( array(
			'## Triage process',
			'## Current status',
			'## Warnings table',
			'## Adding a new warning',
			'## Plugin Check source-level guardrails',
			'## CI integration',
		) as $section ) {
			$this::assertStringContainsString(
				$section,
				$src,
				'Triage doc must contain canonical section: ' . $section
			);
		}
	}

	public function test_warnings_table_has_canonical_columns(): void {
		$src = (string) file_get_contents( self::plugin_root() . '/' . self::TRIAGE_DOC );
		foreach ( array( 'Warning code', 'Source', 'Severity', 'Status', 'Remediation', 'Owner' ) as $column ) {
			$this::assertStringContainsString(
				$column,
				$src,
				'Warnings table must declare column: ' . $column
			);
		}
	}

	public function test_warnings_table_documents_all_statuses(): void {
		$src = (string) file_get_contents( self::plugin_root() . '/' . self::TRIAGE_DOC );
		foreach ( array( 'fixed', 'acknowledged', 'in_progress', 'deferred' ) as $status ) {
			$this::assertStringContainsString(
				'`' . $status . '`',
				$src,
				'Warnings table must document status: ' . $status
			);
		}
	}

	public function test_source_level_guardrails_are_listed(): void {
		$src = (string) file_get_contents( self::plugin_root() . '/' . self::TRIAGE_DOC );
		foreach ( array(
			'extract($_POST)',
			'extract($_GET)',
			'extract($_REQUEST)',
			'eval()',
			'wp_mkdir_p()',
			'SSCRIBE_PRIVATE_STORAGE_DIR',
		) as $guardrail ) {
			$this::assertStringContainsString(
				$guardrail,
				$src,
				'Triage doc must cite source-level guardrail: ' . $guardrail
			);
		}
	}

	public function test_source_tree_has_no_short_open_tag(): void {
		$root = self::plugin_root();
		$violations = array();
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
				// Strip line + block comments to avoid false positives.
				$stripped = (string) ( preg_replace( '!/\*.*?\*/!s', '', $src ) ?? $src );
				$stripped = (string) ( preg_replace( '/^\s*#[^\n]*/m', '', $stripped ) ?? $stripped );
				if ( preg_match( '/<\?(?!php\b|xml\b|=)/', $stripped ) ) {
					$violations[] = $file_info->getPathname();
				}
			}
		}
		$this::assertSame( array(), $violations, 'Source tree must not use PHP short open tags: ' . implode( ', ', $violations ) );
	}

	public function test_source_tree_has_no_eval_call(): void {
		$root = self::plugin_root();
		$violations = array();
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
				if ( preg_match( '/\beval\s*\(/', $src ) ) {
					$violations[] = $file_info->getPathname();
				}
			}
		}
		$this::assertSame( array(), $violations, 'Source tree must not call eval(): ' . implode( ', ', $violations ) );
	}

	public function test_source_tree_has_no_extract_superglobal_spread(): void {
		$root = self::plugin_root();
		$violations = array();
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
				if ( preg_match( "/\bextract\s*\(\s*\\\$_(POST|GET|REQUEST|GLOBALS|SERVER|COOKIE)\b/", $src ) ) {
					$violations[] = $file_info->getPathname();
				}
			}
		}
		$this::assertSame( array(), $violations, 'Source tree must not extract() superglobals: ' . implode( ', ', $violations ) );
	}
}
