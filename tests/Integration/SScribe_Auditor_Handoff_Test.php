<?php
/**
 * Phase 74 — Required handoff to independent auditor contract.
 *
 * Pins the canonical auditor-handoff protocol
 * (docs/AUDITOR_HANDOFF_v2.0.0.md) at the PHPUnit boundary. The
 * companion verifier scripts/verify-auditor-handoff.php walks
 * the doc; this test re-states the same contract in PHPUnit so
 * a regression cannot slip past either guard.
 *
 * Canonical contract:
 *
 *   - Handoff protocol doc exists.
 *   - Doc declares the canonical sections.
 *   - All 13 canonical handoff artifacts are listed.
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

final class SScribe_Auditor_Handoff_Test extends TestCase {

	/** @var string */
	private string $repo_root;

	/** @var string */
	private string $handoff_doc_path;

	protected function setUp(): void {
		$this->repo_root        = dirname( __DIR__, 2 );
		$this->handoff_doc_path = $this->repo_root . '/docs/AUDITOR_HANDOFF_v2.0.0.md';
	}

	public function test_auditor_handoff_doc_exists(): void {
		$this->assertFileExists(
			$this->handoff_doc_path,
			'docs/AUDITOR_HANDOFF_v2.0.0.md must exist so the Phase 74 auditor handoff protocol is auditable.'
		);
	}

	public function test_auditor_handoff_doc_has_canonical_sections(): void {
		$src = (string) file_get_contents( $this->handoff_doc_path );

		$canonical_sections = array(
			'## Why this exists',
			'## Canonical handoff artifacts',
			'## Verification recipe per artifact',
			'## How an independent auditor verifies this',
		);

		foreach ( $canonical_sections as $section ) {
			$this->assertStringContainsString(
				$section,
				$src,
				"Auditor handoff doc is missing canonical section: {$section}"
			);
		}
	}

	public function test_auditor_handoff_doc_lists_every_canonical_artifact(): void {
		$src = (string) file_get_contents( $this->handoff_doc_path );

		$canonical_handoff_artifacts = array(
			'docs/RELEASE_BLOCKERS_v2.0.0.md',
			'docs/FINAL_CI_STATE_v2.0.0.md',
			'docs/EXACT_ARTIFACT_EVIDENCE_v2.0.0.md',
			'docs/RELEASE_REPORT_v2.0.0.md',
			'docs/BRANCH_PROTECTION_v2.0.0.md',
			'docs/TAG_POLICY_v2.0.0.md',
			'docs/RELEASE_PIPELINE_v2.0.0.md',
			'docs/ACCEPTANCE_MATRIX_v2.0.0.json',
			'dist/acceptance-matrix-manifest.json',
			'docs/BUILD_TRANSFORMATIONS.md',
			'docs/SECURITY_MATRIX_v2.0.0.md',
			'docs/PLUGIN_CHECK_WARNINGS_v2.0.0.md',
			'docs/MANUAL_RUNTIME_TESTS_v2.0.0.md',
		);

		foreach ( $canonical_handoff_artifacts as $expected ) {
			$this->assertStringContainsStringIgnoringCase(
				$expected,
				$src,
				"Canonical handoff artifact '{$expected}' must appear in the handoff doc."
			);
		}
	}

	public function test_auditor_handoff_verifier_script_exists(): void {
		$this->assertFileExists(
			$this->repo_root . '/scripts/verify-auditor-handoff.php',
			'scripts/verify-auditor-handoff.php must exist so the verifier script can be invoked by CI.'
		);
	}

	public function test_composer_test_auditor_handoff_script_wired(): void {
		$composer_json_path = $this->repo_root . '/composer.json';
		$this->assertFileExists( $composer_json_path );

		$composer = json_decode( (string) file_get_contents( $composer_json_path ), true );
		$this->assertIsArray( $composer, 'composer.json must be valid JSON.' );

		$scripts = $composer['scripts'] ?? array();
		$this->assertArrayHasKey(
			'test:auditor-handoff',
			$scripts,
			'composer.json must declare a test:auditor-handoff script for the Phase 74 gate.'
		);
		$this->assertSame(
			'php scripts/verify-auditor-handoff.php',
			$scripts['test:auditor-handoff'],
			'composer.json test:auditor-handoff script must invoke scripts/verify-auditor-handoff.php.'
		);
	}

	public function test_composer_ci_chain_includes_auditor_handoff(): void {
		$composer_json_path = $this->repo_root . '/composer.json';
		$composer           = json_decode( (string) file_get_contents( $composer_json_path ), true );

		$ci_chain = $composer['scripts']['ci'] ?? '';
		$this->assertStringContainsString(
			'composer test:auditor-handoff',
			$ci_chain,
			'composer.json `ci` chain must include `composer test:auditor-handoff` so Phase 74 runs in CI.'
		);
	}

	public function test_ci_yml_declares_auditor_handoff_step_pair(): void {
		$ci_yml_path = $this->repo_root . '/.github/workflows/ci.yml';
		$this->assertFileExists( $ci_yml_path );

		$ci_src = (string) file_get_contents( $ci_yml_path );

		$this->assertStringContainsString(
			'composer test:auditor-handoff',
			$ci_src,
			'ci.yml must declare a step that runs `composer test:auditor-handoff`.'
		);
		$this->assertStringContainsString(
			'SScribe_Auditor_Handoff_Test.php',
			$ci_src,
			'ci.yml must declare a step that runs the Phase 74 PHPUnit integration test.'
		);
	}

	public function test_release_audit_sh_declares_auditor_handoff_gate(): void {
		$audit_sh_path = $this->repo_root . '/bin/release-audit.sh';
		$this->assertFileExists( $audit_sh_path );

		$audit_src = (string) file_get_contents( $audit_sh_path );

		$this->assertStringContainsString(
			'Auditor-Handoff',
			$audit_src,
			'bin/release-audit.sh must declare the Phase 74 Auditor-Handoff gate.'
		);
		$this->assertStringContainsString(
			'auditor-handoff',
			$audit_src,
			'bin/release-audit.sh must invoke `composer test:auditor-handoff` for Phase 74.'
		);
		$this->assertStringContainsString(
			'/tmp/release-audit-auditor-handoff.log',
			$audit_src,
			'bin/release-audit.sh must record the Phase 74 log path.'
		);
	}

	public function test_ci_commands_doc_documents_auditor_handoff(): void {
		$ci_docs_path = $this->repo_root . '/docs/CI_COMMANDS.md';
		$this->assertFileExists( $ci_docs_path );

		$ci_docs_src = (string) file_get_contents( $ci_docs_path );

		$this->assertStringContainsString(
			'### `composer test:auditor-handoff`',
			$ci_docs_src,
			'docs/CI_COMMANDS.md must document `composer test:auditor-handoff` per the Phase 61 contract.'
		);
		$this->assertStringContainsString(
			'dist/auditor-handoff-manifest.json',
			$ci_docs_src,
			'docs/CI_COMMANDS.md must reference the Phase 74 manifest path.'
		);
	}

	public function test_auditor_handoff_manifest_canonical_path(): void {
		// The verifier writes a manifest when it runs. The
		// presence of dist/auditor-handoff-manifest.json is
		// optional in CI; this test documents the canonical
		// path so future contributors know where to look.
		$manifest_path = $this->repo_root . '/dist/auditor-handoff-manifest.json';
		$this->assertTrue(
			true,
			"Manifest canonical path: {$manifest_path}"
		);
	}
}
