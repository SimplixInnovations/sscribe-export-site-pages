<?php
/**
 * Phase 73 — Required agent final report format contract.
 *
 * Pins the canonical agent-final-report format spec
 * (docs/AGENT_FINAL_REPORT_v2.0.0.md) at the PHPUnit boundary.
 * The companion verifier scripts/verify-agent-final-report.php
 * walks the spec; this test re-states the same contract in
 * PHPUnit so a regression cannot slip past either guard.
 *
 * Canonical contract:
 *
 *   - Format spec doc exists.
 *   - Spec declares the canonical sections.
 *   - All 6 canonical report sections are listed.
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

final class SScribe_Agent_Final_Report_Test extends TestCase {

	/** @var string */
	private string $repo_root;

	/** @var string */
	private string $format_doc_path;

	protected function setUp(): void {
		$this->repo_root       = dirname( __DIR__, 2 );
		$this->format_doc_path = $this->repo_root . '/docs/AGENT_FINAL_REPORT_v2.0.0.md';
	}

	public function test_agent_final_report_format_doc_exists(): void {
		$this->assertFileExists(
			$this->format_doc_path,
			'docs/AGENT_FINAL_REPORT_v2.0.0.md must exist so the Phase 73 final-report format is auditable.'
		);
	}

	public function test_agent_final_report_format_doc_has_canonical_sections(): void {
		$src = (string) file_get_contents( $this->format_doc_path );

		$canonical_sections = array(
			'## Why this exists',
			'## Canonical sections',
			'## Format rules',
			'## How an independent auditor verifies this',
		);

		foreach ( $canonical_sections as $section ) {
			$this->assertStringContainsString(
				$section,
				$src,
				"Agent final report format spec is missing canonical section: {$section}"
			);
		}
	}

	public function test_agent_final_report_format_doc_lists_every_canonical_section(): void {
		$src = (string) file_get_contents( $this->format_doc_path );

		$canonical_report_sections = array(
			'## Executive summary',
			'## Release evidence',
			'## Blocker status',
			'## CI state',
			'## Open items',
			'## Verification recipe',
		);

		foreach ( $canonical_report_sections as $expected ) {
			$this->assertStringContainsString(
				$expected,
				$src,
				"Canonical report section '{$expected}' must appear in the format spec."
			);
		}
	}

	public function test_agent_final_report_verifier_script_exists(): void {
		$this->assertFileExists(
			$this->repo_root . '/scripts/verify-agent-final-report.php',
			'scripts/verify-agent-final-report.php must exist so the verifier script can be invoked by CI.'
		);
	}

	public function test_composer_test_agent_final_report_script_wired(): void {
		$composer_json_path = $this->repo_root . '/composer.json';
		$this->assertFileExists( $composer_json_path );

		$composer = json_decode( (string) file_get_contents( $composer_json_path ), true );
		$this->assertIsArray( $composer, 'composer.json must be valid JSON.' );

		$scripts = $composer['scripts'] ?? array();
		$this->assertArrayHasKey(
			'test:agent-final-report',
			$scripts,
			'composer.json must declare a test:agent-final-report script for the Phase 73 gate.'
		);
		$this->assertSame(
			'php scripts/verify-agent-final-report.php',
			$scripts['test:agent-final-report'],
			'composer.json test:agent-final-report script must invoke scripts/verify-agent-final-report.php.'
		);
	}

	public function test_composer_ci_chain_includes_agent_final_report(): void {
		$composer_json_path = $this->repo_root . '/composer.json';
		$composer           = json_decode( (string) file_get_contents( $composer_json_path ), true );

		$ci_chain = $composer['scripts']['ci'] ?? '';
		$this->assertStringContainsString(
			'composer test:agent-final-report',
			$ci_chain,
			'composer.json `ci` chain must include `composer test:agent-final-report` so Phase 73 runs in CI.'
		);
	}

	public function test_ci_yml_declares_agent_final_report_step_pair(): void {
		$ci_yml_path = $this->repo_root . '/.github/workflows/ci.yml';
		$this->assertFileExists( $ci_yml_path );

		$ci_src = (string) file_get_contents( $ci_yml_path );

		$this->assertStringContainsString(
			'composer test:agent-final-report',
			$ci_src,
			'ci.yml must declare a step that runs `composer test:agent-final-report`.'
		);
		$this->assertStringContainsString(
			'SScribe_Agent_Final_Report_Test.php',
			$ci_src,
			'ci.yml must declare a step that runs the Phase 73 PHPUnit integration test.'
		);
	}

	public function test_release_audit_sh_declares_agent_final_report_gate(): void {
		$audit_sh_path = $this->repo_root . '/bin/release-audit.sh';
		$this->assertFileExists( $audit_sh_path );

		$audit_src = (string) file_get_contents( $audit_sh_path );

		$this->assertStringContainsString(
			'Agent-Final-Report',
			$audit_src,
			'bin/release-audit.sh must declare the Phase 73 Agent-Final-Report gate.'
		);
		$this->assertStringContainsString(
			'agent-final-report',
			$audit_src,
			'bin/release-audit.sh must invoke `composer test:agent-final-report` for Phase 73.'
		);
		$this->assertStringContainsString(
			'/tmp/release-audit-agent-final-report.log',
			$audit_src,
			'bin/release-audit.sh must record the Phase 73 log path.'
		);
	}

	public function test_ci_commands_doc_documents_agent_final_report(): void {
		$ci_docs_path = $this->repo_root . '/docs/CI_COMMANDS.md';
		$this->assertFileExists( $ci_docs_path );

		$ci_docs_src = (string) file_get_contents( $ci_docs_path );

		$this->assertStringContainsString(
			'### `composer test:agent-final-report`',
			$ci_docs_src,
			'docs/CI_COMMANDS.md must document `composer test:agent-final-report` per the Phase 61 contract.'
		);
		$this->assertStringContainsString(
			'dist/agent-final-report-manifest.json',
			$ci_docs_src,
			'docs/CI_COMMANDS.md must reference the Phase 73 manifest path.'
		);
	}

	public function test_agent_final_report_manifest_canonical_path(): void {
		$manifest_path = $this->repo_root . '/dist/agent-final-report-manifest.json';
		$this->assertFileExists(
			$manifest_path,
			'dist/agent-final-report-manifest.json must exist after the verifier runs.'
		);
		$src   = (string) file_get_contents( $manifest_path );
		$json  = json_decode( $src, true );
		$this->assertIsArray( $json, 'Agent-final-report manifest must decode as JSON.' );
		$this->assertArrayHasKey( 'passes', $json, 'Agent-final-report manifest must record the passes key.' );
		$this->assertTrue(
			(bool) ( $json['passes'] ?? false ),
			'Agent-final-report manifest must record `passes: true` so the audit trail proves the gate succeeded.'
		);
		$this->assertSame(
			0,
			(int) ( $json['errors_count'] ?? 1 ),
			'Agent-final-report manifest must record `errors_count: 0`.'
		);
	}
}
