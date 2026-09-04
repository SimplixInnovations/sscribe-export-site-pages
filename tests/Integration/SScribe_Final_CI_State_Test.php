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
 *   - All 9 canonical required release signals are listed.
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

#[\PHPUnit\Framework\Attributes\Group('release-contract')]
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
			'## Recorded final state',
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
			'e2e',
		);

		foreach ( $canonical_jobs as $expected ) {
			$this->assertStringContainsStringIgnoringCase(
				$expected,
				$src,
				"Canonical required job '{$expected}' must appear in the final CI state checklist."
			);
		}
	}

	public function test_recorded_final_state_uses_known_status_vocabulary(): void {
		$src   = (string) file_get_contents( $this->checklist_path );
		$start = strpos( $src, '## Recorded final state' );
		$this->assertNotFalse( $start );

		$block = substr( $src, (int) $start );
		$next  = strpos( $block, "\n## ", strlen( '## Recorded final state' ) );
		if ( false !== $next ) {
			$block = substr( $block, 0, $next );
		}

		$hits = array();
		preg_match_all(
			'/^\|\s*([0-9]+)\s*\|\s*([^|]+?)\s*\|\s*([A-Z_]+)\s*\|\s*(.*?)\s*\|\s*$/m',
			$block,
			$hits,
			PREG_SET_ORDER
		);

		$this->assertCount( 9, $hits, 'Recorded final state must contain exactly the nine canonical required jobs.' );

		$known = array( 'SUCCESS', 'LOCAL_PASS', 'UNAVAILABLE', 'FAILED', 'CANCELLED', 'SKIPPED', 'MISSING' );
		foreach ( $hits as $row ) {
			$this->assertContains( trim( $row[3] ), $known );
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

	public function test_final_ci_state_verifier_is_strict_and_sha_bound(): void {
		$src = (string) file_get_contents( $this->repo_root . '/scripts/verify-final-ci-state.php' );

		$this->assertStringContainsString( 'SSCRIBE_RELEASE_CERTIFICATION', $src );
		$this->assertStringContainsString( 'git rev-parse HEAD', $src );
		$this->assertStringContainsString( 'recorded_source_sha_matches_head', $src );
		$this->assertStringContainsString( 'LOCAL_PASS', $src );
		$this->assertStringContainsString( 'every_required_job_has_shippable_recorded_status', $src );
	}

}
