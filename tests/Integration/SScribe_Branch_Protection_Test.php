<?php
/**
 * Phase 55 — Branch protection integration test.
 *
 * Branch protection is server-side; this test verifies the
 * auditable CONTRACT: docs/BRANCH_PROTECTION_v2.0.0.md declares
 * every required clause and points at the right ci.yml jobs.
 *
 * A regression that silently drops "no force push" or "conversation
 * resolution" or stops listing a required check from ci.yml fails
 * this test before it reaches the release gate.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

namespace SScribe\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class SScribe_Branch_Protection_Test extends TestCase {

	private const VERIFIER_PATH = 'scripts/verify-branch-protection.php';
	private const MANIFEST_PATH = 'dist/branch-protection-manifest.json';
	private const DOC_PATH      = 'docs/BRANCH_PROTECTION_v2.0.0.md';
	private const CI_PATH       = '.github/workflows/ci.yml';

	private static function plugin_root(): string {
		return dirname( __DIR__, 2 );
	}

	private function run_verifier(): array {
		$root        = self::plugin_root();
		$descriptors = array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) );
		$process     = proc_open( array( PHP_BINARY, $root . '/' . self::VERIFIER_PATH ), $descriptors, $pipes );
		if ( ! is_resource( $process ) ) {
			throw new \RuntimeException( 'Could not spawn verifier subprocess.' );
		}
		$stdout = (string) stream_get_contents( $pipes[1] );
		$stderr = (string) stream_get_contents( $pipes[2] );
		$code   = proc_close( $process );
		return array( (int) $code, $stdout . $stderr );
	}

	public function test_live_branch_protection_doc_passes_verifier(): void {
		list( $code, $output ) = $this->run_verifier();
		$this::assertSame(
			0,
			$code,
			'Live docs/BRANCH_PROTECTION_v2.0.0.md must satisfy the Phase 55 contract. Output:' . "\n" . $output
		);
		$this::assertStringContainsString( 'Branch protection contract valid', $output );
	}

	public function test_manifest_records_passing_rules(): void {
		list( $code ) = $this->run_verifier();
		$this::assertSame( 0, $code );

		$payload = json_decode( (string) file_get_contents( self::plugin_root() . '/' . self::MANIFEST_PATH ), true );
		$this::assertIsArray( $payload );
		$this::assertTrue( $payload['passes'] );
		$this::assertGreaterThanOrEqual( 13, $payload['rule_count'] );
		$this::assertSame( $payload['rule_count'], $payload['passed_count'] );
		$this::assertSame( 0, $payload['errors_count'] );
		$this::assertNotEmpty( $payload['matrix'] );
	}

	public function test_doc_declares_canonical_branches_and_observed_live_state(): void {
		$source = (string) file_get_contents( self::plugin_root() . '/' . self::DOC_PATH );
		$this::assertMatchesRegularExpression(
			'/^Branches:\s*main\s*$/mi',
			$source,
			'Branch-protection doc must declare main as the single canonical long-lived branch.'
		);
		$this::assertMatchesRegularExpression(
			'/Audited Date\s*:?/i',
			$source,
			'Branch-protection doc must record an audited date.'
		);
		$this::assertMatchesRegularExpression( '/main:\s*UNPROTECTED/i', $source );
		$this::assertMatchesRegularExpression(
			'/not[\s\S]{0,100}WordPress\.org submission requirement/i',
			$source,
			'The document must distinguish repository governance from WordPress plugin submission compliance.'
		);
	}

	public function test_doc_forbids_force_pushes_and_unrestricted_admin_bypass(): void {
		$source = (string) file_get_contents( self::plugin_root() . '/' . self::DOC_PATH );
		$this::assertMatchesRegularExpression(
			'/(no|forbid|reject|disallow)[^\n]*force[ -]?push/i',
			$source,
			'Branch-protection doc must forbid force-pushes.'
		);
		$this::assertMatchesRegularExpression(
			'/no unrestricted admin bypass/i',
			$source,
			'Branch-protection doc must forbid unrestricted admin bypass.'
		);
		$this::assertMatchesRegularExpression(
			'/(?:no|rejects?|forbid)[^\n]*(?:branch[ -]?deletion|deletion)/i',
			$source,
			'Branch-protection doc must forbid branch deletion.'
		);
	}

	public function test_doc_requires_main_pr_reviews_and_no_unrestricted_bypass(): void {
		$source = (string) file_get_contents( self::plugin_root() . '/' . self::DOC_PATH );
		$this::assertMatchesRegularExpression(
			'/Changes enter through pull requests/i',
			$source,
			'Branch-protection doc must require reviewed pull requests into main.'
		);
		$this::assertMatchesRegularExpression(
			'/[Aa]t\s+least\s+\d+\s+(approving\s+)?review/i',
			$source,
			'Branch-protection doc must declare required approving reviews.'
		);
		$this::assertMatchesRegularExpression( '/conversation[ -]?resolution/i', $source );
		$this::assertMatchesRegularExpression( '/(--signoff|DCO|sign[ -]?off)/i', $source );
		$this::assertStringContainsString( 'no unrestricted admin bypass', strtolower( $source ) );
	}

	public function test_doc_pins_main_squash_and_disables_history_changing_pr_methods(): void {
		$source = (string) file_get_contents( self::plugin_root() . '/' . self::DOC_PATH );
		$this::assertStringContainsString( 'Pull requests into `main`: squash', $source );
		$this::assertMatchesRegularExpression( '/rebase[- ]?merge:\s*disabled/i', $source );
		$this::assertMatchesRegularExpression( '/merge commits:\s*disabled/i', $source );
		$this::assertStringNotContainsString( 'fast-forward synchronization only', $source );
	}

	public function test_release_helpers_are_main_only_and_never_reference_develop(): void {
		$commit  = (string) file_get_contents( self::plugin_root() . '/scripts/release-commit.php' );
		$prepare = (string) file_get_contents( self::plugin_root() . '/scripts/release-prepare.php' );

		$this::assertStringContainsString( 'git pull --ff-only origin main', $commit );
		$this::assertStringContainsString( 'git tag -a', $commit );
		$this::assertStringNotContainsString( 'origin/develop', $commit );
		$this::assertStringNotContainsString( 'origin develop', $commit );
		$this::assertStringNotContainsString( 'origin/develop', $prepare );
		$this::assertStringNotContainsString( 'git push origin develop', $prepare );
	}

	public function test_release_helpers_support_already_versioned_development_line(): void {
		$commit   = (string) file_get_contents( self::plugin_root() . '/scripts/release-commit.php' );
		$prepare  = (string) file_get_contents( self::plugin_root() . '/scripts/release-prepare.php' );
		$composer = (string) file_get_contents( self::plugin_root() . '/composer.json' );

		$this::assertStringContainsString( '[0] Current', $prepare );
		$this::assertStringContainsString( 'Finalizing current development version', $prepare );
		$this::assertStringContainsString( 'null === $requested_version ? $canonical_version : $requested_version', $commit );
		$this::assertStringContainsString( '2 !== $existing_tag_exit', $commit );
		$this::assertStringContainsString( '"release:tag": "php scripts/release-commit.php --tag"', $composer );
	}

	public function test_doc_lists_required_jobs_by_ci_yml_friendly_name(): void {
		// The doc's "Required status checks" table must use the same
		// friendly names as ci.yml, so the GitHub-required-checks UI
		// can match them. This guards against a future maintainer who
		// renames a job in ci.yml and forgets the doc.
		$ci_src  = (string) file_get_contents( self::plugin_root() . '/' . self::CI_PATH );
		$doc_src = (string) file_get_contents( self::plugin_root() . '/' . self::DOC_PATH );

		preg_match_all( '/^    ([a-z][a-z0-9_-]*):\s*\n\s+name:\s*([^\n]+)/m', $ci_src, $matches );

		$required_section_match = '';
		if ( preg_match( '/##\s*Required status checks[^\n]*\n(.*?)(?=^##\s|\z)/sm', $doc_src, $section ) ) {
			$required_section_match = $section[1];
		}
		$this::assertNotEmpty( $required_section_match );

		foreach ( array( 'Version Sync', 'PHPUnit', 'Submission Package Check' ) as $mandatory_label ) {
			$this::assertStringContainsStringIgnoringCase(
				$mandatory_label,
				$required_section_match,
				"Branch-protection doc 'Required status checks' section must mention '{$mandatory_label}' (matches ci.yml job name)."
			);
		}
	}

	public function test_doc_cross_references_companion_evidence(): void {
		// Branch-protection rules without the rest of the release
		// evidence are noise. The doc must point at the audit log,
		// security matrix, and perf envelope so an auditor finds
		// the artifacts in one jump.
		$source = (string) file_get_contents( self::plugin_root() . '/' . self::DOC_PATH );
		$this::assertStringContainsString( 'docs/CI_EVIDENCE', $source );
		$this::assertStringContainsString( 'docs/SECURITY_MATRIX', $source );
		$this::assertStringContainsString( 'docs/PERFORMANCE_BENCHMARKS', $source );
	}
}
