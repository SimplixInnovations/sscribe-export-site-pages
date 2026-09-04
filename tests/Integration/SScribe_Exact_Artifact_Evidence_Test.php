<?php
/**
 * Phase 72 — Required exact artifact evidence contract.
 *
 * Pins the canonical artifact-evidence checklist
 * (docs/EXACT_ARTIFACT_EVIDENCE_v2.0.0.md) at the PHPUnit
 * boundary. The companion verifier
 * scripts/verify-exact-artifact-evidence.php walks the doc;
 * this test re-states the same contract in PHPUnit so a
 * regression cannot slip past either guard.
 *
 * Canonical contract:
 *
 *   - Evidence doc exists.
 *   - Doc declares the canonical sections.
 *   - All 12 canonical evidence fields are listed.
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
final class SScribe_Exact_Artifact_Evidence_Test extends TestCase {

	/** @var string */
	private string $repo_root;

	/** @var string */
	private string $evidence_doc_path;

	protected function setUp(): void {
		$this->repo_root         = dirname( __DIR__, 2 );
		$this->evidence_doc_path = $this->repo_root . '/docs/EXACT_ARTIFACT_EVIDENCE_v2.0.0.md';
	}

	public function test_exact_artifact_evidence_doc_exists(): void {
		$this->assertFileExists(
			$this->evidence_doc_path,
			'docs/EXACT_ARTIFACT_EVIDENCE_v2.0.0.md must exist so the Phase 72 artifact evidence is auditable.'
		);
	}

	public function test_exact_artifact_evidence_doc_has_canonical_sections(): void {
		$src = (string) file_get_contents( $this->evidence_doc_path );

		$canonical_sections = array(
			'## Why this exists',
			'## Canonical evidence fields',
			'## Recorded evidence',
			'## How an independent auditor verifies this',
		);

		foreach ( $canonical_sections as $section ) {
			$this->assertStringContainsString(
				$section,
				$src,
				"Exact artifact evidence doc is missing canonical section: {$section}"
			);
		}
	}

	public function test_exact_artifact_evidence_doc_lists_every_canonical_field(): void {
		$src = (string) file_get_contents( $this->evidence_doc_path );

		$canonical_fields = array(
			'version',
			'zip_filename',
			'zip_sha256',
			'zip_byte_size',
			'zip_file_count',
			'source_sha',
			'source_short_sha',
			'builder_run_id',
			'builder_workflow',
			'plugin_check_url',
			'clean_install_doc',
			'build_timestamp',
		);

		foreach ( $canonical_fields as $expected ) {
			$this->assertStringContainsStringIgnoringCase(
				$expected,
				$src,
				"Canonical evidence field '{$expected}' must appear in the artifact evidence doc."
			);
		}
	}

	public function test_exact_artifact_evidence_verifier_script_exists(): void {
		$this->assertFileExists(
			$this->repo_root . '/scripts/verify-exact-artifact-evidence.php',
			'scripts/verify-exact-artifact-evidence.php must exist so the verifier script can be invoked by CI.'
		);
	}

	public function test_composer_test_exact_artifact_evidence_script_wired(): void {
		$composer_json_path = $this->repo_root . '/composer.json';
		$this->assertFileExists( $composer_json_path );

		$composer = json_decode( (string) file_get_contents( $composer_json_path ), true );
		$this->assertIsArray( $composer, 'composer.json must be valid JSON.' );

		$scripts = $composer['scripts'] ?? array();
		$this->assertArrayHasKey(
			'test:exact-artifact-evidence',
			$scripts,
			'composer.json must declare a test:exact-artifact-evidence script for the Phase 72 gate.'
		);
		$this->assertSame(
			'php scripts/verify-exact-artifact-evidence.php',
			$scripts['test:exact-artifact-evidence'],
			'composer.json test:exact-artifact-evidence script must invoke scripts/verify-exact-artifact-evidence.php.'
		);
	}

	public function test_composer_ci_chain_includes_exact_artifact_evidence(): void {
		$composer_json_path = $this->repo_root . '/composer.json';
		$composer           = json_decode( (string) file_get_contents( $composer_json_path ), true );

		$ci_chain = $composer['scripts']['ci'] ?? '';
		$this->assertStringContainsString(
			'composer test:exact-artifact-evidence',
			$ci_chain,
			'composer.json `ci` chain must include `composer test:exact-artifact-evidence` so Phase 72 runs in CI.'
		);
	}

	public function test_ci_yml_declares_exact_artifact_evidence_step_pair(): void {
		$ci_yml_path = $this->repo_root . '/.github/workflows/ci.yml';
		$this->assertFileExists( $ci_yml_path );

		$ci_src = (string) file_get_contents( $ci_yml_path );

		$this->assertStringContainsString(
			'composer test:exact-artifact-evidence',
			$ci_src,
			'ci.yml must declare a step that runs `composer test:exact-artifact-evidence`.'
		);
		$this->assertStringContainsString(
			'SScribe_Exact_Artifact_Evidence_Test.php',
			$ci_src,
			'ci.yml must declare a step that runs the Phase 72 PHPUnit integration test.'
		);
	}

	public function test_release_audit_sh_declares_exact_artifact_evidence_gate(): void {
		$audit_sh_path = $this->repo_root . '/bin/release-audit.sh';
		$this->assertFileExists( $audit_sh_path );

		$audit_src = (string) file_get_contents( $audit_sh_path );

		$this->assertStringContainsString(
			'Exact-Artifact-Evidence',
			$audit_src,
			'bin/release-audit.sh must declare the Phase 72 Exact-Artifact-Evidence gate.'
		);
		$this->assertStringContainsString(
			'exact-artifact-evidence',
			$audit_src,
			'bin/release-audit.sh must invoke `composer test:exact-artifact-evidence` for Phase 72.'
		);
		$this->assertStringContainsString(
			'/tmp/release-audit-exact-artifact-evidence.log',
			$audit_src,
			'bin/release-audit.sh must record the Phase 72 log path.'
		);
	}

	public function test_ci_commands_doc_documents_exact_artifact_evidence(): void {
		$ci_docs_path = $this->repo_root . '/docs/CI_COMMANDS.md';
		$this->assertFileExists( $ci_docs_path );

		$ci_docs_src = (string) file_get_contents( $ci_docs_path );

		$this->assertStringContainsString(
			'### `composer test:exact-artifact-evidence`',
			$ci_docs_src,
			'docs/CI_COMMANDS.md must document `composer test:exact-artifact-evidence` per the Phase 61 contract.'
		);
		$this->assertStringContainsString(
			'dist/exact-artifact-evidence-manifest.json',
			$ci_docs_src,
			'docs/CI_COMMANDS.md must reference the Phase 72 manifest path.'
		);
	}

	public function test_exact_artifact_evidence_every_field_has_recorded_value(): void {
		// Mirror the verifier's non-blank recorded-value check at
		// the PHPUnit boundary. The "Recorded evidence" table is
		// the audit-trail; any blank cell is a gap.
		$src = (string) file_get_contents( $this->evidence_doc_path );

		$canonical_fields = array(
			'version',
			'zip_filename',
			'zip_sha256',
			'zip_byte_size',
			'zip_file_count',
			'source_sha',
			'source_short_sha',
			'builder_run_id',
			'builder_workflow',
			'plugin_check_url',
			'clean_install_doc',
			'build_timestamp',
		);

		// Walk the doc, accumulating rows from the "Recorded
		// evidence" table only (defined by the "Recorded value"
		// header column).
		$recorded = array();
		$in_recorded_table = false;
		foreach ( explode( "\n", $src ) as $line ) {
			if ( false !== stripos( $line, 'Recorded value' ) ) {
				$in_recorded_table = true;
				continue;
			}
			if ( ! $in_recorded_table ) {
				continue;
			}
			if ( preg_match( '/^\s*\|[\s:|]+\|\s*$/', $line ) ) {
				continue;
			}
			if ( preg_match( '/^\s*\|\s*#\s*\|\s*Field\s*\|/i', $line ) ) {
				continue;
			}
			if ( preg_match( '/^\s*\|\s*\d+\s*\|\s*([a-z_][a-z0-9_]*)\s*\|\s*(.+?)\s*\|/i', $line, $m ) ) {
				$recorded[ trim( $m[1] ) ] = trim( preg_replace( '/^_+(.+)_+$/', '$1', trim( $m[2] ) ) );
			}
		}

		$blank = array();
		foreach ( $canonical_fields as $field ) {
			$val = isset( $recorded[ $field ] ) ? $recorded[ $field ] : '';
			if ( '' === $val || stripos( $val, 'filled at certify' ) !== false ) {
				$blank[] = $field;
			}
		}
		$this->assertSame(
			array(),
			$blank,
			'Every canonical evidence field must have a non-blank recorded value. Blank: ' . implode( ', ', $blank )
		);
	}

	public function test_exact_artifact_evidence_manifest_canonical_path(): void {
		$manifest_path = $this->repo_root . '/dist/exact-artifact-evidence-manifest.json';
		$this->assertFileExists(
			$manifest_path,
			'dist/exact-artifact-evidence-manifest.json must exist after the verifier runs.'
		);
		$src   = (string) file_get_contents( $manifest_path );
		$json  = json_decode( $src, true );
		$this->assertIsArray( $json, 'Exact-artifact-evidence manifest must decode as JSON.' );
		$this->assertArrayHasKey( 'passes', $json, 'Exact-artifact-evidence manifest must record the passes key.' );
		$this->assertTrue(
			(bool) ( $json['passes'] ?? false ),
			'Exact-artifact-evidence manifest must record `passes: true` so the audit trail proves the gate succeeded.'
		);
		$this->assertSame(
			0,
			(int) ( $json['errors_count'] ?? 1 ),
			'Exact-artifact-evidence manifest must record `errors_count: 0`.'
		);
	}
}
