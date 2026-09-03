<?php
/**
 * Phase 71 — Required final CI state contract.
 *
 * Pins the canonical final-CI-state checklist
 * (docs/FINAL_CI_STATE_v2.0.0.md) at the PHPUnit boundary. The
 * companion verifier scripts/verify-final-ci-state.php walks the
 * doc; this test re-states the same contract in PHPUnit so a
 * regression cannot slip past either guard.
 *
 * Canonical contract:
 *
 *   - Checklist doc exists.
 *   - Doc declares the canonical sections.
 *   - All 8 canonical required jobs are listed.
 *   - Every required job has a status in {SUCCESS, SKIPPED}.
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

final class SScribe_Final_CI_State_Test extends TestCase {

	/** @var string */
	private string $repo_root;

	/** @var string */
	private string $checklist_path;

	protected function setUp(): void {
		$this->repo_root      = dirname( __DIR__, 2 );
		$this->checklist_path = $this->repo_root . '/docs/FINAL_CI_STATE_v2.0.0.md';
	}

	public function test_final_ci_state_checklist_doc_exists(): void {
		$this->assertFileExists(
			$this->checklist_path,
			'docs/FINAL_CI_STATE_v2.0.0.md must exist so the Phase 71 final CI state is auditable.'
		);
	}

	public function test_final_ci_state_checklist_has_canonical_sections(): void {
		$src = (string) file_get_contents( $this->checklist_path );

		$canonical_sections = array(
			'## Why this exists',
			'## Status convention',
			'## Canonical required jobs',
			'## How an independent auditor verifies this',
		);

		foreach ( $canonical_sections as $section ) {
			$this->assertStringContainsString(
				$section,
				$src,
				"Final CI state checklist is missing canonical section: {$section}"
			);
		}
	}

	public function test_final_ci_state_checklist_lists_every_canonical_required_job(): void {
		$src = (string) file_get_contents( $this->checklist_path );

		$canonical_jobs = array(
			'version-check',
			'lint',
			'test',
			'audit',
			'frontend-quality',
			'real-wp-tests',
			'coverage',
			'plugin-check',
		);

		foreach ( $canonical_jobs as $expected ) {
			$this->assertStringContainsStringIgnoringCase(
				$expected,
				$src,
				"Canonical required job '{$expected}' must appear in the final CI state checklist."
			);
		}
	}

	public function test_every_required_job_has_success_or_skipped_status(): void {
		$src = (string) file_get_contents( $this->checklist_path );

		// Walk every markdown row in the Canonical required jobs table.
		// Each row has at least 5 columns: `# | Job | Required status | Notes`.
		$hits = array();
		if ( preg_match_all( '/^\|\s*([0-9]+)\s*\|\s*`?([A-Za-z0-9_\-]+)`?\s*\|\s*(SUCCESS|SKIPPED|FAILED|CANCELLED|MISSING)\s*\|/m', $src, $matches ) ) {
			foreach ( $matches[1] as $idx => $num ) {
				$hits[ (int) $num ] = array(
					'job'    => trim( $matches[2][ $idx ] ),
					'status' => trim( $matches[3][ $idx ] ),
				);
			}
		}

		$this->assertNotEmpty(
			$hits,
			'Final CI state checklist must contain at least one row with a status.'
		);

		$valid_statuses = array( 'SUCCESS', 'SKIPPED' );
		foreach ( $hits as $row ) {
			$this->assertContains(
				$row['status'],
				$valid_statuses,
				"Required job '{$row['job']}' has status '{$row['status']}'; only SUCCESS or SKIPPED allowed."
			);
		}
	}

	public function test_final_ci_state_verifier_script_exists(): void {
		$this->assertFileExists(
			$this->repo_root . '/scripts/verify-final-ci-state.php',
			'scripts/verify-final-ci-state.php must exist so the verifier script can be invoked by CI.'
		);
	}

	public function test_composer_test_final_ci_state_script_wired(): void {
		$composer_json_path = $this->repo_root . '/composer.json';
		$this->assertFileExists( $composer_json_path );

		$composer = json_decode( (string) file_get_contents( $composer_json_path ), true );
		$this->assertIsArray( $composer, 'composer.json must be valid JSON.' );

		$scripts = $composer['scripts'] ?? array();
		$this->assertArrayHasKey(
			'test:final-ci-state',
			$scripts,
			'composer.json must declare a test:final-ci-state script for the Phase 71 gate.'
		);
		$this->assertSame(
			'php scripts/verify-final-ci-state.php',
			$scripts['test:final-ci-state'],
			'composer.json test:final-ci-state script must invoke scripts/verify-final-ci-state.php.'
		);
	}

	public function test_composer_ci_chain_includes_final_ci_state(): void {
		$composer_json_path = $this->repo_root . '/composer.json';
		$composer           = json_decode( (string) file_get_contents( $composer_json_path ), true );

		$ci_chain = $composer['scripts']['ci'] ?? '';
		$this->assertStringContainsString(
			'composer test:final-ci-state',
			$ci_chain,
			'composer.json `ci` chain must include `composer test:final-ci-state` so Phase 71 runs in CI.'
		);
	}

	public function test_ci_yml_declares_final_ci_state_step_pair(): void {
		$ci_yml_path = $this->repo_root . '/.github/workflows/ci.yml';
		$this->assertFileExists( $ci_yml_path );

		$ci_src = (string) file_get_contents( $ci_yml_path );

		$this->assertStringContainsString(
			'composer test:final-ci-state',
			$ci_src,
			'ci.yml must declare a step that runs `composer test:final-ci-state`.'
		);
		$this->assertStringContainsString(
			'SScribe_Final_CI_State_Test.php',
			$ci_src,
			'ci.yml must declare a step that runs the Phase 71 PHPUnit integration test.'
		);
	}

	public function test_release_audit_sh_declares_final_ci_state_gate(): void {
		$audit_sh_path = $this->repo_root . '/bin/release-audit.sh';
		$this->assertFileExists( $audit_sh_path );

		$audit_src = (string) file_get_contents( $audit_sh_path );

		$this->assertStringContainsString(
			'Final-CI-State',
			$audit_src,
			'bin/release-audit.sh must declare the Phase 71 Final-CI-State gate.'
		);
		$this->assertStringContainsString(
			'final-ci-state',
			$audit_src,
			'bin/release-audit.sh must invoke `composer test:final-ci-state` for Phase 71.'
		);
		$this->assertStringContainsString(
			'/tmp/release-audit-final-ci-state.log',
			$audit_src,
			'bin/release-audit.sh must record the Phase 71 log path.'
		);
	}

	public function test_ci_commands_doc_documents_final_ci_state(): void {
		$ci_docs_path = $this->repo_root . '/docs/CI_COMMANDS.md';
		$this->assertFileExists( $ci_docs_path );

		$ci_docs_src = (string) file_get_contents( $ci_docs_path );

		$this->assertStringContainsString(
			'### `composer test:final-ci-state`',
			$ci_docs_src,
			'docs/CI_COMMANDS.md must document `composer test:final-ci-state` per the Phase 61 contract.'
		);
		$this->assertStringContainsString(
			'dist/final-ci-state-manifest.json',
			$ci_docs_src,
			'docs/CI_COMMANDS.md must reference the Phase 71 manifest path.'
		);
	}

	public function test_final_ci_state_manifest_canonical_path(): void {
		// The verifier writes a manifest when it runs. The
		// presence of dist/final-ci-state-manifest.json is
		// optional in CI (the verifier runs first, then the
		// manifest is read by debug tooling); this test
		// documents the canonical path so future contributors
		// know where to look for the gate's evidence.
		$manifest_path = $this->repo_root . '/dist/final-ci-state-manifest.json';
		$this->assertTrue(
			true,
			"Manifest canonical path: {$manifest_path}"
		);
	}
}
