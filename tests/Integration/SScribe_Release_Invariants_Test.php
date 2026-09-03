<?php
/**
 * Phase 75 — Release invariants contract.
 *
 * Pins the canonical release-invariants checklist
 * (docs/RELEASE_INVARIANTS_v2.0.0.md) at the PHPUnit boundary.
 * The companion verifier scripts/verify-release-invariants.php
 * walks the doc; this test re-states the same contract in
 * PHPUnit so a regression cannot slip past either guard.
 *
 * Canonical contract:
 *
 *   - Invariants doc exists.
 *   - Doc declares the canonical sections.
 *   - All 32 canonical invariants are listed.
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

final class SScribe_Release_Invariants_Test extends TestCase {

	/** @var string */
	private string $repo_root;

	/** @var string */
	private string $invariants_doc_path;

	protected function setUp(): void {
		$this->repo_root            = dirname( __DIR__, 2 );
		$this->invariants_doc_path  = $this->repo_root . '/docs/RELEASE_INVARIANTS_v2.0.0.md';
	}

	public function test_release_invariants_doc_exists(): void {
		$this->assertFileExists(
			$this->invariants_doc_path,
			'docs/RELEASE_INVARIANTS_v2.0.0.md must exist so the Phase 75 release invariants are auditable.'
		);
	}

	public function test_release_invariants_doc_has_canonical_sections(): void {
		$src = (string) file_get_contents( $this->invariants_doc_path );

		$canonical_sections = array(
			'## Why this exists',
			'## Canonical invariants',
			'## How an independent auditor verifies this',
		);

		foreach ( $canonical_sections as $section ) {
			$this->assertStringContainsString(
				$section,
				$src,
				"Release invariants doc is missing canonical section: {$section}"
			);
		}
	}

	public function test_release_invariants_doc_lists_every_canonical_invariant(): void {
		$src = (string) file_get_contents( $this->invariants_doc_path );

		// Each invariant is identified by a unique fingerprint
		// phrase that survives future wording tweaks.
		$canonical_invariants = array(
			'SSCRIBE_VERSION is the single source of truth',
			'readme.txt Stable tag matches',
			'package.json version matches',
			'Mainfile Version: header matches',
			'ZIP SHA-256 is reproducible',
			'ZIP contains zero comments',
			'ZIP excludes every dev-only path',
			'ZIP mainfile Version: matches',
			'every shipped PHP file parses',
			'Composer security audit is clean',
			'NPM audit is clean',
			'AI-artifact scan finds zero',
			'Plugin Check on the exact ZIP is PASS',
			'Real-WordPress testbench matrix is green',
			'Coverage thresholds met',
			'Zero wp_ajax_nopriv_sscribe_*',
			'Zero eval(',
			'Composer autoload points at',
			'Translation POT declares',
			'every composer test:* script is documented',
			'every verify-*.php script has a matching',
			'SHA-pinned actions',
			'continue-on-error: true is banned',
			'All shipped PHP files use SSCRIBE prefix',
			'No raw internal details leak',
			'every shipped JS file is runtime-clean',
			'UI refactor discipline holds',
			'every required new test',
			'Manual runtime tests runbook covers',
			'every Phase 70 blocker is RESOLVED',
			'every Phase 71 required CI job is SUCCESS',
			'every Phase 72 artifact evidence field',
		);

		foreach ( $canonical_invariants as $expected ) {
			// Strip markdown backticks around identifiers so the
			// fingerprint survives the doc's ``code`` formatting.
			$needle    = str_replace( '`', '', $expected );
			$haystack  = str_replace( '`', '', $src );
			$this->assertStringContainsStringIgnoringCase(
				$needle,
				$haystack,
				"Canonical invariant '{$expected}' must appear in the invariants doc."
			);
		}
	}

	public function test_release_invariants_verifier_script_exists(): void {
		$this->assertFileExists(
			$this->repo_root . '/scripts/verify-release-invariants.php',
			'scripts/verify-release-invariants.php must exist so the verifier script can be invoked by CI.'
		);
	}

	public function test_composer_test_release_invariants_script_wired(): void {
		$composer_json_path = $this->repo_root . '/composer.json';
		$this->assertFileExists( $composer_json_path );

		$composer = json_decode( (string) file_get_contents( $composer_json_path ), true );
		$this->assertIsArray( $composer, 'composer.json must be valid JSON.' );

		$scripts = $composer['scripts'] ?? array();
		$this->assertArrayHasKey(
			'test:release-invariants',
			$scripts,
			'composer.json must declare a test:release-invariants script for the Phase 75 gate.'
		);
		$this->assertSame(
			'php scripts/verify-release-invariants.php',
			$scripts['test:release-invariants'],
			'composer.json test:release-invariants script must invoke scripts/verify-release-invariants.php.'
		);
	}

	public function test_composer_ci_chain_includes_release_invariants(): void {
		$composer_json_path = $this->repo_root . '/composer.json';
		$composer           = json_decode( (string) file_get_contents( $composer_json_path ), true );

		$ci_chain = $composer['scripts']['ci'] ?? '';
		$this->assertStringContainsString(
			'composer test:release-invariants',
			$ci_chain,
			'composer.json `ci` chain must include `composer test:release-invariants` so Phase 75 runs in CI.'
		);
	}

	public function test_ci_yml_declares_release_invariants_step_pair(): void {
		$ci_yml_path = $this->repo_root . '/.github/workflows/ci.yml';
		$this->assertFileExists( $ci_yml_path );

		$ci_src = (string) file_get_contents( $ci_yml_path );

		$this->assertStringContainsString(
			'composer test:release-invariants',
			$ci_src,
			'ci.yml must declare a step that runs `composer test:release-invariants`.'
		);
		$this->assertStringContainsString(
			'SScribe_Release_Invariants_Test.php',
			$ci_src,
			'ci.yml must declare a step that runs the Phase 75 PHPUnit integration test.'
		);
	}

	public function test_release_audit_sh_declares_release_invariants_gate(): void {
		$audit_sh_path = $this->repo_root . '/bin/release-audit.sh';
		$this->assertFileExists( $audit_sh_path );

		$audit_src = (string) file_get_contents( $audit_sh_path );

		$this->assertStringContainsString(
			'Release-Invariants',
			$audit_src,
			'bin/release-audit.sh must declare the Phase 75 Release-Invariants gate.'
		);
		$this->assertStringContainsString(
			'release-invariants',
			$audit_src,
			'bin/release-audit.sh must invoke `composer test:release-invariants` for Phase 75.'
		);
		$this->assertStringContainsString(
			'/tmp/release-audit-release-invariants.log',
			$audit_src,
			'bin/release-audit.sh must record the Phase 75 log path.'
		);
	}

	public function test_ci_commands_doc_documents_release_invariants(): void {
		$ci_docs_path = $this->repo_root . '/docs/CI_COMMANDS.md';
		$this->assertFileExists( $ci_docs_path );

		$ci_docs_src = (string) file_get_contents( $ci_docs_path );

		$this->assertStringContainsString(
			'### `composer test:release-invariants`',
			$ci_docs_src,
			'docs/CI_COMMANDS.md must document `composer test:release-invariants` per the Phase 61 contract.'
		);
		$this->assertStringContainsString(
			'dist/release-invariants-manifest.json',
			$ci_docs_src,
			'docs/CI_COMMANDS.md must reference the Phase 75 manifest path.'
		);
	}

	public function test_release_invariants_manifest_canonical_path(): void {
		$manifest_path = $this->repo_root . '/dist/release-invariants-manifest.json';
		$this->assertFileExists(
			$manifest_path,
			'dist/release-invariants-manifest.json must exist after the verifier runs.'
		);
		$src   = (string) file_get_contents( $manifest_path );
		$json  = json_decode( $src, true );
		$this->assertIsArray( $json, 'Release-invariants manifest must decode as JSON.' );
		$this->assertArrayHasKey( 'passes', $json, 'Release-invariants manifest must record the passes key.' );
		$this->assertTrue(
			(bool) ( $json['passes'] ?? false ),
			'Release-invariants manifest must record `passes: true` so the audit trail proves the gate succeeded.'
		);
		$this->assertSame(
			0,
			(int) ( $json['errors_count'] ?? 1 ),
			'Release-invariants manifest must record `errors_count: 0`.'
		);
	}
}
