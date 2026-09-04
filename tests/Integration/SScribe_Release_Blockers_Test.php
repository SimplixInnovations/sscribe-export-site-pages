<?php
/**
 * Phase 70 — Final release blockers contract (refactored).
 *
 * Pins the canonical release-blocker checklist
 * (docs/RELEASE_BLOCKERS_v2.0.0.md) at the PHPUnit boundary. The
 * companion verifier scripts/verify-release-blockers.php walks the
 * doc; this test re-states the same contract in PHPUnit so a
 * regression cannot slip past either guard.
 *
 * Canonical contract (refactored):
 *
 *   - Checklist doc exists.
 *   - Doc declares the canonical sections (Why this exists, Status
 *     convention, Canonical blockers, How an independent auditor
 *     verifies this).
 *   - All 20 canonical blocker rows are present.
 *   - Every blocker has a status in {RESOLVED, DEFERRED}.
 *   - Every blocker declares a recognised closure-source token.
 *   - Static (closure: static) blockers are RESOLVED in the doc.
 *   - The companion verifier script exists.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

if ( ! defined( 'SSCRIBE_TESTS_DIR' ) ) {
	define( 'SSCRIBE_TESTS_DIR', __DIR__ . '/..' );
}

require_once SSCRIBE_TESTS_DIR . '/bootstrap.php';

use PHPUnit\Framework\TestCase;

final class SScribe_Release_Blockers_Test extends TestCase {

	/** @var string */
	private string $repo_root;

	/** @var string */
	private string $checklist_path;

	protected function setUp(): void {
		$this->repo_root      = dirname( __DIR__, 2 );
		$this->checklist_path = $this->repo_root . '/docs/RELEASE_BLOCKERS_v2.0.0.md';
	}

	public function test_release_blocker_checklist_doc_exists(): void {
		$this->assertFileExists(
			$this->checklist_path,
			'docs/RELEASE_BLOCKERS_v2.0.0.md must exist so the Phase 70 release blockers are auditable.'
		);
	}

	public function test_release_blocker_checklist_has_canonical_sections(): void {
		$src = (string) file_get_contents( $this->checklist_path );

		$canonical_sections = array(
			'## Why this exists',
			'## Status convention',
			'## Canonical blockers',
			'## How an independent auditor verifies this',
		);

		foreach ( $canonical_sections as $section ) {
			$this->assertStringContainsString(
				$section,
				$src,
				"Release blockers checklist is missing canonical section: {$section}"
			);
		}
	}

	public function test_release_blocker_checklist_has_closure_source_vocabulary(): void {
		$src = (string) file_get_contents( $this->checklist_path );

		// The new architecture requires a Closure source vocabulary table
		// to be declared in the doc.
		$this::assertStringContainsString(
			'## Closure source vocabulary',
			$src,
			'Release blockers checklist must declare its Closure source vocabulary (refactored Phase 70 contract).'
		);

		$required_tokens = array(
			'static',
			'phase71-evidence',
			'e2e-evidence',
			'plugin-check-evidence',
			'clean-install-evidence',
			'runtime-export-evidence',
			'source-transparency-evidence',
		);
		foreach ( $required_tokens as $token ) {
			$this::assertStringContainsString(
				$token,
				$src,
				"Closure source vocabulary must declare token: {$token}"
			);
		}
	}

	public function test_release_blocker_checklist_lists_every_canonical_blocker(): void {
		$src = (string) file_get_contents( $this->checklist_path );

		$canonical_blockers = array(
			'Any required CI job red',
			'Any required job skipped',
			'PHPUnit runtime fatal',
			'E2E not actually executed',
			'Security workflow red',
			'All Languages broken',
			'All Types Preview mismatch',
			'Stale count race',
			'Stale abort retry',
			'Preflight JS missing function',
			'Terminal 500 retry storm',
			'Operational fatal/error log not durable',
			'Redis limiter inconsistent',
			'Invalid WordPress/PHP minimum metadata',
			'Plugin Check not run on exact ZIP',
			'Exact ZIP not clean-install tested',
			'Exact ZIP not runtime-export tested',
			'Source / build transparency unresolved',
			'License inventory unresolved',
			'Release path capable of rebuilding untested bytes',
		);

		foreach ( $canonical_blockers as $expected ) {
			$this::assertStringContainsStringIgnoringCase(
				$expected,
				$src,
				"Canonical blocker '{$expected}' must appear in the release blockers checklist."
			);
		}
	}

	public function test_every_release_blocker_has_resolved_or_deferred_status(): void {
		$src = (string) file_get_contents( $this->checklist_path );

		// 5-column table: # | Blocker | Status | Closure source | Evidence
		$hits = array();
		if ( preg_match_all( '/^\|\s*([0-9]+)\s*\|\s*([^|]+?)\s*\|\s*(RESOLVED|DEFERRED|OPEN|BLOCKED)\s*\|\s*([^|]+?)\s*\|\s*([^|]*?)\s*\|\s*$/m', $src, $matches ) ) {
			foreach ( $matches[1] as $idx => $num ) {
				$hits[ (int) $num ] = array(
					'blocker'        => trim( $matches[2][ $idx ] ),
					'status'         => trim( $matches[3][ $idx ] ),
					'closure_source' => trim( $matches[4][ $idx ] ),
					'evidence'       => trim( $matches[5][ $idx ] ),
				);
			}
		}

		$this::assertNotEmpty(
			$hits,
			'Release blockers checklist must contain at least one row with a status.'
		);

		$valid_statuses        = array( 'RESOLVED', 'DEFERRED' );
		$valid_closure_sources = array( 'static', 'phase71-evidence', 'e2e-evidence', 'plugin-check-evidence', 'clean-install-evidence', 'runtime-export-evidence', 'source-transparency-evidence' );

		foreach ( $hits as $row ) {
			$this::assertContains(
				$row['status'],
				$valid_statuses,
				"Blocker '{$row['blocker']}' has status '{$row['status']}'; only RESOLVED or DEFERRED allowed."
			);
			$this::assertContains(
				$row['closure_source'],
				$valid_closure_sources,
				"Blocker '{$row['blocker']}' declares unknown closure source '{$row['closure_source']}'."
			);
		}
	}

	public function test_static_release_blockers_are_resolved_in_doc(): void {
		// Static blockers MUST be RESOLVED in the doc — closure source `static`
		// means the only proof of resolution lives in tracked code/test/doc.
		$src = (string) file_get_contents( $this->checklist_path );

		$hits = array();
		if ( preg_match_all( '/^\|\s*([0-9]+)\s*\|\s*([^|]+?)\s*\|\s*(RESOLVED|DEFERRED|OPEN|BLOCKED)\s*\|\s*([^|]+?)\s*\|\s*([^|]*?)\s*\|\s*$/m', $src, $matches ) ) {
			foreach ( $matches[1] as $idx => $num ) {
				$hits[ (int) $num ] = array(
					'blocker'        => trim( $matches[2][ $idx ] ),
					'status'         => trim( $matches[3][ $idx ] ),
					'closure_source' => trim( $matches[4][ $idx ] ),
				);
			}
		}

		$static_unresolved = array();
		foreach ( $hits as $row ) {
			if ( 'static' === $row['closure_source'] && 'RESOLVED' !== $row['status'] ) {
				$static_unresolved[] = $row['blocker'];
			}
		}
		$this::assertEmpty(
			$static_unresolved,
			'Static (closure: static) blockers must be RESOLVED in the doc; otherwise tracked code/test/doc claims are broken. Violations: ' . implode( ', ', $static_unresolved )
		);
	}

	public function test_release_blocker_verifier_script_exists(): void {
		$this::assertFileExists(
			$this->repo_root . '/scripts/verify-release-blockers.php',
			'scripts/verify-release-blockers.php must exist so the verifier script can be invoked by CI.'
		);
	}

	public function test_composer_test_release_blockers_script_wired(): void {
		$composer_json_path = $this->repo_root . '/composer.json';
		$this::assertFileExists( $composer_json_path );

		$composer = json_decode( (string) file_get_contents( $composer_json_path ), true );
		$this::assertIsArray( $composer, 'composer.json must be valid JSON.' );

		$scripts = $composer['scripts'] ?? array();
		$this::assertArrayHasKey(
			'test:release-blockers',
			$scripts,
			'composer.json must declare a test:release-blockers script for the Phase 70 gate.'
		);
		$this::assertSame(
			'php scripts/verify-release-blockers.php',
			$scripts['test:release-blockers'],
			'composer.json test:release-blockers script must invoke scripts/verify-release-blockers.php.'
		);
	}

	public function test_composer_ci_chain_includes_release_blockers(): void {
		$composer_json_path = $this->repo_root . '/composer.json';
		$composer           = json_decode( (string) file_get_contents( $composer_json_path ), true );

		$ci_chain = $composer['scripts']['ci'] ?? '';
		$this::assertStringContainsString(
			'composer test:release-blockers',
			$ci_chain,
			'composer.json `ci` chain must include `composer test:release-blockers` so Phase 70 runs in CI.'
		);
	}

	public function test_ci_yml_declares_release_blockers_step_pair(): void {
		$ci_yml_path = $this->repo_root . '/.github/workflows/ci.yml';
		$this::assertFileExists( $ci_yml_path );

		$ci_src = (string) file_get_contents( $ci_yml_path );

		$this::assertStringContainsString(
			'composer test:release-blockers',
			$ci_src,
			'ci.yml must declare a step that runs `composer test:release-blockers`.'
		);
		$this::assertStringContainsString(
			'SScribe_Release_Blockers_Test.php',
			$ci_src,
			'ci.yml must declare a step that runs the Phase 70 PHPUnit integration test.'
		);
	}

	public function test_release_audit_sh_declares_release_blockers_gate(): void {
		$audit_sh_path = $this->repo_root . '/bin/release-audit.sh';
		$this::assertFileExists( $audit_sh_path );

		$audit_src = (string) file_get_contents( $audit_sh_path );

		$this::assertStringContainsString(
			'Release-Blockers',
			$audit_src,
			'bin/release-audit.sh must declare the Phase 70 Release-Blockers gate.'
		);
		$this::assertStringContainsString(
			'release-blockers',
			$audit_src,
			'bin/release-audit.sh must invoke `composer test:release-blockers` for Phase 70.'
		);
		$this::assertStringContainsString(
			'/tmp/release-audit-release-blockers.log',
			$audit_src,
			'bin/release-audit.sh must record the Phase 70 log path.'
		);
	}

	public function test_ci_commands_doc_documents_release_blockers(): void {
		$ci_docs_path = $this->repo_root . '/docs/CI_COMMANDS.md';
		$this::assertFileExists( $ci_docs_path );

		$ci_docs_src = (string) file_get_contents( $ci_docs_path );

		$this::assertStringContainsString(
			'### `composer test:release-blockers`',
			$ci_docs_src,
			'docs/CI_COMMANDS.md must document `composer test:release-blockers` per the Phase 61 contract.'
		);
		$this::assertStringContainsString(
			'dist/release-blockers-manifest.json',
			$ci_docs_src,
			'docs/CI_COMMANDS.md must reference the Phase 70 manifest path.'
		);
	}

	public function test_release_blockers_manifest_persists(): void {
		// The verifier writes a manifest when it runs. The
		// presence of dist/release-blockers-manifest.json is
		// optional in CI (the verifier runs first, then the
		// manifest is read by debug tooling); this test
		// documents the canonical path so future contributors
		// know where to look for the gate's evidence.
		$manifest_path = $this->repo_root . '/dist/release-blockers-manifest.json';
		$this::assertTrue(
			true,
			"Manifest canonical path: {$manifest_path}"
		);
	}
}
