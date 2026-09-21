<?php
/**
 * Phase 76 — Final Definition of Done contract.
 *
 * Pins the canonical Definition of Done checklist
 * (docs/DEFINITION_OF_DONE_v2.0.0.md) at the PHPUnit boundary.
 * The companion verifier scripts/verify-definition-of-done.php
 * walks the doc; this test re-states the same contract in
 * PHPUnit so a regression cannot slip past either guard.
 *
 * Canonical contract:
 *
 *   - DoD doc exists.
 *   - Doc declares the canonical sections.
 *   - All 36 canonical DoD criteria are listed.
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
final class SScribe_Definition_Of_Done_Test extends TestCase {

	/** @var string */
	private string $repo_root;

	/** @var string */
	private string $dod_doc_path;

	protected function setUp(): void {
		$this->repo_root     = dirname( __DIR__, 2 );
		$this->dod_doc_path  = $this->repo_root . '/docs/DEFINITION_OF_DONE_v2.0.0.md';
	}

	public function test_definition_of_done_doc_exists(): void {
		$this->assertFileExists(
			$this->dod_doc_path,
			'docs/DEFINITION_OF_DONE_v2.0.0.md must exist so the Phase 76 Definition of Done is auditable.'
		);
	}

	public function test_definition_of_done_doc_has_canonical_sections(): void {
		$src = (string) file_get_contents( $this->dod_doc_path );

		$canonical_sections = array(
			'## Why this exists',
			'## Canonical Definition of Done',
			'## How an independent auditor verifies this',
		);

		foreach ( $canonical_sections as $section ) {
			$this->assertStringContainsString(
				$section,
				$src,
				"Definition of Done doc is missing canonical section: {$section}"
			);
		}
	}

	public function test_definition_of_done_doc_lists_every_canonical_criterion(): void {
		$src = (string) file_get_contents( $this->dod_doc_path );

		// Each criterion is identified by a unique fingerprint
		// phrase that survives future wording tweaks. Strip
		// markdown backticks around identifiers so the
		// fingerprint survives ``code`` formatting.
		$canonical_criteria = array(
			'All Phases 16-77 are complete',
			'SSCRIBE_VERSION equals mainfile',
			'Every required execution signal is SUCCESS',
			'Every Phase 70 blocker is RESOLVED; no DEFERRED blocker remains',
			'dist/sscribe-export-site-pages-{VERSION}.zip exists',
			'Plugin Check on the exact ZIP is PASS',
			'Real-WordPress matrix is green',
			'Coverage thresholds met',
			'Composer security audit is clean',
			'NPM audit is clean',
			'AI-artifact scan finds zero',
			'Branch protection rules documented',
			'Tag policy contract holds',
			'Release pipeline contract holds',
			'Acceptance matrix',
			'Debug log redaction contract holds',
			'No-internal-details contract holds',
			'CI command docs contract holds',
			'Build-after-test vs test-after-build',
			'Exact-package clean install holds',
			'Plugin Check warning triage',
			'JS error-free contract holds',
			'AJAX network trace covers',
			'UI refactor discipline holds',
			'Phase 68 test coverage holds',
			'Strict manual runtime evidence proves all 6 canonical environments PASS',
			'Release blockers checklist complete',
			'Final execution state holds',
			'Strict exact-artifact evidence matches',
			'Agent final report produced',
			'Auditor handoff protocol holds (14 artifacts listed',
			'Release invariants declared',
			'Branch topology policy holds',
			'Tag is cut on origin/main HEAD',
			'WP.org submission uses the exact certified ZIP',
			'Public maintained exact source/build inputs are available',
		);

		$haystack = str_replace( '`', '', $src );
		foreach ( $canonical_criteria as $expected ) {
			$needle = str_replace( '`', '', $expected );
			$this->assertStringContainsStringIgnoringCase(
				$needle,
				$haystack,
				"Canonical DoD criterion '{$expected}' must appear in the Definition of Done doc."
			);
		}
	}

	public function test_definition_of_done_has_exactly_36_numbered_criteria(): void {
		$src = (string) file_get_contents( $this->dod_doc_path );
		$rows = array();
		preg_match_all( '/^\\|\\s*([0-9]+)\\s*\\|/m', $src, $rows );

		$this->assertSame( range( 1, 36 ), array_map( 'intval', $rows[1] ?? array() ) );
	}

	public function test_definition_of_done_tag_wording_matches_canonical_tag_policy(): void {
		$src = (string) file_get_contents( $this->dod_doc_path );

		$this->assertStringNotContainsString(
			'signed via `gh release create --verify-tag`',
			$src,
			'Definition of Done must not describe --verify-tag as cryptographic signing.'
		);
		$this->assertStringContainsString(
			'cryptographic tag signing is recommended, not required',
			strtolower( $src ),
			'Definition of Done must match the canonical tag-policy distinction between annotated tags and optional cryptographic signing.'
		);
	}

	public function test_wporg_submission_uses_the_actual_certified_release_artifact(): void {
		$src     = (string) file_get_contents( $this->dod_doc_path );
		$release = (string) file_get_contents( $this->repo_root . '/.github/workflows/release.yml' );

		$this->assertStringContainsString( 'name: sscribe-release-zip', $release );
		$this->assertStringContainsString( '`sscribe-release-zip`', $src );
		$this->assertStringNotContainsString( "Plugin Check action's `release-zip`", $src );
	}

	public function test_definition_of_done_verifier_script_exists(): void {
		$this->assertFileExists(
			$this->repo_root . '/scripts/verify-definition-of-done.php',
			'scripts/verify-definition-of-done.php must exist so the verifier script can be invoked by CI.'
		);
	}

	public function test_composer_test_definition_of_done_script_wired(): void {
		$composer_json_path = $this->repo_root . '/composer.json';
		$this->assertFileExists( $composer_json_path );

		$composer = json_decode( (string) file_get_contents( $composer_json_path ), true );
		$this->assertIsArray( $composer, 'composer.json must be valid JSON.' );

		$scripts = $composer['scripts'] ?? array();
		$this->assertArrayHasKey(
			'test:definition-of-done',
			$scripts,
			'composer.json must declare a test:definition-of-done script for the Phase 76 gate.'
		);
		$this->assertSame(
			'php scripts/verify-definition-of-done.php',
			$scripts['test:definition-of-done'],
			'composer.json test:definition-of-done script must invoke scripts/verify-definition-of-done.php.'
		);
	}

	public function test_composer_ci_chain_includes_definition_of_done(): void {
		$composer_json_path = $this->repo_root . '/composer.json';
		$composer           = json_decode( (string) file_get_contents( $composer_json_path ), true );

		$ci_chain = $composer['scripts']['ci'] ?? '';
		$this->assertStringContainsString(
			'composer test:definition-of-done',
			$ci_chain,
			'composer.json `ci` chain must include `composer test:definition-of-done` so Phase 76 runs in CI.'
		);
	}

	public function test_ci_yml_declares_definition_of_done_step_pair(): void {
		$ci_yml_path = $this->repo_root . '/.github/workflows/ci.yml';
		$this->assertFileExists( $ci_yml_path );

		$ci_src = (string) file_get_contents( $ci_yml_path );

		$this->assertStringContainsString(
			'composer test:definition-of-done',
			$ci_src,
			'ci.yml must declare a step that runs `composer test:definition-of-done`.'
		);
		$this->assertStringContainsString(
			'SScribe_Definition_Of_Done_Test.php',
			$ci_src,
			'ci.yml must declare a step that runs the Phase 76 PHPUnit integration test.'
		);
	}

	public function test_release_audit_php_declares_definition_of_done_gate(): void {
		$audit_path = $this->repo_root . '/scripts/release-audit.php';
		$this->assertFileExists( $audit_path );
		$audit_src = (string) file_get_contents( $audit_path );
		$this->assertStringContainsString( 'Definition-Of-Done', $audit_src, 'scripts/release-audit.php must declare the Phase 76 Definition-Of-Done gate.' );
		$this->assertStringContainsString( 'test:definition-of-done', $audit_src, 'scripts/release-audit.php must invoke test:definition-of-done for Phase 76 Definition-Of-Done.' );
		$this->assertStringContainsString( 'release-audit-', $audit_src, 'Canonical release audit must persist per-gate logs.' );
	}

	public function test_ci_commands_doc_documents_definition_of_done(): void {
		$ci_docs_path = $this->repo_root . '/docs/CI_COMMANDS.md';
		$this->assertFileExists( $ci_docs_path );

		$ci_docs_src = (string) file_get_contents( $ci_docs_path );

		$this->assertStringContainsString(
			'### `composer test:definition-of-done`',
			$ci_docs_src,
			'docs/CI_COMMANDS.md must document `composer test:definition-of-done` per the Phase 61 contract.'
		);
		$this->assertStringContainsString(
			'dist/definition-of-done-manifest.json',
			$ci_docs_src,
			'docs/CI_COMMANDS.md must reference the Phase 76 manifest path.'
		);
	}

	public function test_definition_of_done_manifest_canonical_path(): void {
		$manifest_path = $this->repo_root . '/dist/definition-of-done-manifest.json';
		$this->assertFileExists(
			$manifest_path,
			'dist/definition-of-done-manifest.json must exist after the verifier runs.'
		);
		$src   = (string) file_get_contents( $manifest_path );
		$json  = json_decode( $src, true );
		$this->assertIsArray( $json, 'Definition-of-done manifest must decode as JSON.' );
		$this->assertArrayHasKey( 'passes', $json, 'Definition-of-done manifest must record the passes key.' );
		$this->assertTrue(
			(bool) ( $json['passes'] ?? false ),
			'Definition-of-done manifest must record `passes: true` so the audit trail proves the gate succeeded.'
		);
		$this->assertSame(
			0,
			(int) ( $json['errors_count'] ?? 1 ),
			'Definition-of-done manifest must record `errors_count: 0`.'
		);
	}
}
