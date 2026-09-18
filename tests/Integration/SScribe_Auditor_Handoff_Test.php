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
 *   - All 15 canonical handoff artifacts are listed
 *     (13 originals + Phase 77 branch-policy pair).
 *   - Every listed artifact exists on disk and is non-empty.
 *   - The exact release ZIP at dist/{slug}-{VERSION}.zip has
 *     a matching .sha256 sidecar (canonical sidecar naming).
 *   - The companion verifier script exists and exits 0.
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
			'docs/BRANCH_POLICY_v2.0.0.md',
			'dist/branch-policy-manifest.json',
		);

		foreach ( $canonical_handoff_artifacts as $expected ) {
			$this->assertStringContainsStringIgnoringCase(
				$expected,
				$src,
				"Canonical handoff artifact '{$expected}' must appear in the handoff doc."
			);
		}
	}

	public function test_auditor_handoff_documents_generated_exact_sha_evidence(): void {
		$src = (string) file_get_contents( $this->handoff_doc_path );

		foreach ( array(
			'dist/final-execution-evidence.json',
			'dist/final-ci-state-manifest.json',
			'dist/release-certification-evidence.json',
			'dist/exact-artifact-evidence-manifest.json',
		) as $expected ) {
			$this->assertStringContainsString(
				$expected,
				$src,
				"Auditor handoff must document generated exact-SHA evidence: {$expected}"
			);
		}
	}

	public function test_auditor_handoff_every_listed_artifact_exists_and_nonempty(): void {
		// Mirror the verifier's on-disk check at the PHPUnit boundary.
		// A handoff table that lists artifacts but ships empty
		// placeholders fails the audit-trail contract.
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
			'docs/BRANCH_POLICY_v2.0.0.md',
			'dist/branch-policy-manifest.json',
		);

		$empty = array();
		foreach ( $canonical_handoff_artifacts as $rel ) {
			$abs = $this->repo_root . '/' . $rel;
			if ( ! is_file( $abs ) || 0 === filesize( $abs ) ) {
				$empty[] = $rel;
			}
		}
		$this->assertSame(
			array(),
			$empty,
			'Every canonical handoff artifact must exist and be non-empty. Empty: ' . implode( ', ', $empty )
		);
	}

	public function test_auditor_handoff_exact_zip_sha256_pair_present(): void {
		// Mirror the verifier's ZIP+SHA sidecar check at the
		// PHPUnit boundary. The pair must use the canonical
		// `dist/{slug}-{version}.{ext}` form, NOT
		// `dist/{slug}-{version}.zip.sha256` (double-ext).
		$main_src    = (string) file_get_contents( $this->repo_root . '/sscribe-export-site-pages.php' );
		$dom         = array();
		$ver         = array();
		preg_match( '/^\s*\*?\s*Text Domain:\s*([^\s*]+)/m', $main_src, $dom );
		preg_match( '/^\s*\*?\s*Version:\s*(.+)$/m', $main_src, $ver );
		$slug = isset( $dom[1] ) ? trim( $dom[1] ) : '';
		$verv = isset( $ver[1] ) ? trim( $ver[1] ) : '';

		$this->assertNotSame( '', $slug, 'Text Domain header is missing from main plugin file.' );
		$this->assertNotSame( '', $verv, 'Version header is missing from main plugin file.' );

		$expected_zip = $this->repo_root . '/dist/' . $slug . '-' . $verv . '.zip';
		$expected_sha = $this->repo_root . '/dist/' . $slug . '-' . $verv . '.sha256';
		$broken       = $this->repo_root . '/dist/' . $slug . '-' . $verv . '.zip.sha256';

		$this->assertFileExists( $expected_zip, "Canonical ZIP missing: {$expected_zip}" );
		$this->assertFileExists( $expected_sha, "Canonical SHA sidecar missing: {$expected_sha}" );
		$this->assertFileDoesNotExist( $broken, "Broken double-ext sidecar present (use .sha256 not .zip.sha256): {$broken}" );
	}

	public function test_auditor_handoff_verifier_script_exists(): void {
		$this->assertFileExists(
			$this->repo_root . '/scripts/verify-auditor-handoff.php',
			'scripts/verify-auditor-handoff.php must exist so the verifier script can be invoked by CI.'
		);
	}

	public function test_auditor_handoff_verifier_executes_clean(): void {
		// Run the verifier end-to-end via subprocess so the test
		// actually exercises every rule (rather than re-declaring
		// them as a no-op). RC must be 0.
		$verifier = $this->repo_root . '/scripts/verify-auditor-handoff.php';
		$this->assertFileExists( $verifier );
		$cmd    = escapeshellcmd( PHP_BINARY ) . ' ' . escapeshellarg( $verifier ) . ' 2>&1';
		$output = array();
		$rc     = 0;
		exec( $cmd, $output, $rc );
		$this->assertSame(
			0,
			$rc,
			"scripts/verify-auditor-handoff.php must exit 0. Output:\n" . implode( "\n", $output )
		);
		$this->assertStringContainsString(
			'Auditor handoff protocol contract valid',
			implode( "\n", $output ),
			'verifier output must end with the success marker so CI logs prove the pass.'
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

	public function test_branch_policy_evidence_is_generated_before_auditor_handoff(): void {
		$ci_src = (string) file_get_contents( $this->repo_root . '/.github/workflows/ci.yml' );
		$ci_branch = strpos( $ci_src, 'run: composer test:branch-policy' );
		$ci_handoff = strpos( $ci_src, 'run: composer test:auditor-handoff' );
		$this->assertIsInt( $ci_branch );
		$this->assertIsInt( $ci_handoff );
		$this->assertLessThan( $ci_handoff, $ci_branch, 'CI must generate branch-policy evidence before the auditor-handoff consumer runs.' );

		$audit_src = (string) file_get_contents( $this->repo_root . '/scripts/release-audit.php' );
		$audit_branch = strpos( $audit_src, "'Branch-Policy'      => 'test:branch-policy'" );
		$audit_handoff = strpos( $audit_src, "'Auditor-Handoff'    => 'test:auditor-handoff'" );
		$this->assertIsInt( $audit_branch );
		$this->assertIsInt( $audit_handoff );
		$this->assertLessThan( $audit_handoff, $audit_branch, 'Canonical release audit must run Branch-Policy before Auditor-Handoff.' );
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

	public function test_release_audit_php_declares_auditor_handoff_gate(): void {
		$audit_path = $this->repo_root . '/scripts/release-audit.php';
		$this->assertFileExists( $audit_path );
		$audit_src = (string) file_get_contents( $audit_path );
		$this->assertStringContainsString( 'Auditor-Handoff', $audit_src );
		$this->assertStringContainsString( 'test:auditor-handoff', $audit_src );
		$this->assertStringContainsString( 'release-audit-', $audit_src );
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
		// The verifier always writes the manifest. The presence
		// of dist/auditor-handoff-manifest.json with `passes: true`
		// proves the gate just succeeded end-to-end.
		$manifest_path = $this->repo_root . '/dist/auditor-handoff-manifest.json';
		$this->assertFileExists(
			$manifest_path,
			'dist/auditor-handoff-manifest.json must exist after the verifier runs.'
		);
		$src   = (string) file_get_contents( $manifest_path );
		$json  = json_decode( $src, true );
		$this->assertIsArray( $json, 'Auditor-handoff manifest must decode as JSON.' );
		$this->assertArrayHasKey( 'passes', $json, 'Auditor-handoff manifest must record the passes key.' );
		$this->assertTrue(
			(bool) ( $json['passes'] ?? false ),
			'Auditor-handoff manifest must record `passes: true` so the audit trail proves the gate succeeded.'
		);
		$this->assertSame(
			0,
			(int) ( $json['errors_count'] ?? 1 ),
			'Auditor-handoff manifest must record `errors_count: 0`.'
		);
	}
}
