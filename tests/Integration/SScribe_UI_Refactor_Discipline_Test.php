<?php
/**
 * Phase 67 — UI refactor discipline integration test.
 *
 * Pins the UI refactor freeze at the PHPUnit boundary so a
 * regression that splits / renames / adds an admin surface file
 * after the v1.9.0 baseline fails locally before it ships.
 *
 * The authoritative discipline doc lives at
 * docs/UI_REFACTOR_DISCIPLINE_v2.0.0.md (Phase 67 evidence). The
 * runtime contract is enforced by
 * scripts/verify-ui-refactor-discipline.php.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

namespace SScribe\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class SScribe_UI_Refactor_Discipline_Test extends TestCase {

	private const VERIFIER_PATH = 'scripts/verify-ui-refactor-discipline.php';
	private const MANIFEST_PATH = 'dist/ui-refactor-discipline-manifest.json';
	private const DISCIPLINE_DOC = 'docs/UI_REFACTOR_DISCIPLINE_v2.0.0.md';

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
			'Phase 67 verifier must return exit 0. Output:' . "\n" . $output
		);
		$this::assertStringContainsString( 'UI refactor discipline contract valid', $output );
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

	public function test_discipline_doc_exists(): void {
		$this::assertFileExists(
			self::plugin_root() . '/' . self::DISCIPLINE_DOC,
			'UI refactor discipline doc must exist at docs/UI_REFACTOR_DISCIPLINE_v2.0.0.md.'
		);
	}

	public function test_discipline_doc_has_canonical_sections(): void {
		$src = (string) file_get_contents( self::plugin_root() . '/' . self::DISCIPLINE_DOC );
		foreach ( array(
			'## Why this exists',
			'## Baseline SHA',
			'## File-count lock',
			'## Rename / add / split ban',
			'## Allowed modifications to existing admin files',
			'## Post-release modularization backlog',
			'## How an independent auditor verifies this',
		) as $section ) {
			$this::assertStringContainsString(
				$section,
				$src,
				'UI refactor discipline doc must contain canonical section: ' . $section
			);
		}
	}

	public function test_version_check_ci_fetches_history_for_baseline_diff(): void {
		$ci = (string) file_get_contents( self::plugin_root() . '/.github/workflows/ci.yml' );
		$job_pos = strpos( $ci, "    version-check:\n" );
		$this::assertNotFalse( $job_pos, 'CI must declare the version-check job.' );
		$next_job = strpos( $ci, "\n    ", $job_pos + 5 );
		$job = false === $next_job ? substr( $ci, $job_pos ) : substr( $ci, $job_pos, $next_job - $job_pos );
		$this::assertStringContainsString(
			'fetch-depth: 0',
			$job,
			'version-check must fetch full history because the UI discipline gate diffs against a historical BASELINE_SHA.'
		);
	}

	public function test_baseline_sha_is_recorded_and_resolves(): void {
		$src = (string) file_get_contents( self::plugin_root() . '/' . self::DISCIPLINE_DOC );
		$this::assertMatchesRegularExpression(
			'/BASELINE_SHA\s*=\s*([0-9a-f]{7,40})/',
			$src,
			'UI refactor discipline doc must record a BASELINE_SHA on a `BASELINE_SHA = <sha>` line.'
		);
		preg_match( '/BASELINE_SHA\s*=\s*([0-9a-f]{7,40})/', $src, $m );
		$baseline_sha = $m[1];
		$cat = (string) shell_exec( 'git cat-file -t ' . escapeshellarg( $baseline_sha ) . ' 2>&1' );
		$this::assertStringContainsString( 'commit', $cat, 'BASELINE_SHA must resolve to a real commit.' );
	}

	public function test_admin_file_count_matches_baseline(): void {
		$payload = json_decode( (string) file_get_contents( self::plugin_root() . '/' . self::MANIFEST_PATH ), true );
		$head_count     = count( $payload['head_admin_files'] );
		$baseline_count = count( $payload['baseline_admin_files'] );
		$this::assertSame(
			$baseline_count,
			$head_count,
			'Admin surface file count at HEAD must equal baseline count. HEAD: ' . $head_count . ' Baseline: ' . $baseline_count
		);
		// v1.9.0 baseline ships the full admin surface (PHP
		// classes + CSS + JS + partials + index.php stubs).
		// Asserting the count is non-zero and reasonable
		// (≤ 30 files) catches "surface was emptied by accident"
		// without locking an exact number that can drift with
		// future index.php stubs.
		$this::assertGreaterThan( 5, $head_count, 'Admin surface must contain real files (more than the 5 index.php stubs alone).' );
		$this::assertLessThanOrEqual( 30, $head_count, 'Admin surface must remain bounded (≤ 30 files including index.php stubs).' );
	}

	public function test_no_admin_renames_or_additions_since_baseline(): void {
		$payload = json_decode( (string) file_get_contents( self::plugin_root() . '/' . self::MANIFEST_PATH ), true );
		$this::assertSame(
			array(),
			$payload['rename_lines'],
			'Admin files MUST NOT be renamed between BASELINE_SHA and HEAD. Found: ' . implode( ' | ', $payload['rename_lines'] )
		);
		$this::assertSame(
			array(),
			$payload['added_lines'],
			'Admin surface files MUST NOT be added between BASELINE_SHA and HEAD. Found: ' . implode( ' | ', $payload['added_lines'] )
		);
	}
}
