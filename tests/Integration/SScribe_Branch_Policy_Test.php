<?php
/**
 * Phase 77 — Branch topology policy contract.
 *
 * Pins the canonical branch set declared in docs/BRANCH_POLICY_v2.0.0.md
 * at the PHPUnit boundary. The companion verifier
 * scripts/verify-branch-policy.php walks the doc and the git refs;
 * this test re-states the same contract in PHPUnit so a regression
 * cannot slip past either guard.
 *
 * Canonical contract:
 *
 *   - Policy doc exists.
 *   - Doc declares the canonical sections.
 *   - Verifier script exists.
 *   - composer.json declares the test:branch-policy script.
 *   - composer.json `ci` chain includes the gate.
 *   - ci.yml declares a step that runs the verifier and the test.
 *   - bin/release-audit.sh declares the Branch-Policy gate.
 *   - docs/CI_COMMANDS.md documents the gate.
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
final class SScribe_Branch_Policy_Test extends TestCase {

	/** @var string */
	private string $repo_root;

	/** @var string */
	private string $policy_doc_path;

	protected function setUp(): void {
		$this->repo_root       = dirname( __DIR__, 2 );
		$this->policy_doc_path = $this->repo_root . '/docs/BRANCH_POLICY_v2.0.0.md';
	}

	public function test_branch_policy_doc_exists(): void {
		$this->assertFileExists(
			$this->policy_doc_path,
			'docs/BRANCH_POLICY_v2.0.0.md must exist so the Phase 77 branch topology policy is auditable.'
		);
	}

	public function test_branch_policy_doc_has_canonical_sections(): void {
		$this->assertFileExists( $this->policy_doc_path );
		$src = (string) file_get_contents( $this->policy_doc_path );

		$canonical_sections = array(
			'## Why this exists',
			'## Canonical long-lived branches',
			'## Forbidden patterns',
			'## Promotion rules',
			'## How an independent auditor verifies this',
		);

		foreach ( $canonical_sections as $section ) {
			$this->assertStringContainsString(
				$section,
				$src,
				"Branch-policy doc is missing canonical section: {$section}"
			);
		}
	}

	public function test_branch_policy_doc_lists_only_canonical_branches(): void {
		$this->assertFileExists( $this->policy_doc_path );
		$src = (string) file_get_contents( $this->policy_doc_path );

		// The doc must call out only `main` and `develop` as the
		// canonical long-lived branches. A table row with both
		// branch names is sufficient.
		$this->assertMatchesRegularExpression(
			'/\|\s*`?main`?\s*\|.*\|\s*`?develop`?\s*\|/s',
			$src,
			'Branch-policy doc must declare `main` and `develop` as the canonical long-lived branches.'
		);
	}

	public function test_branch_policy_verifier_script_exists(): void {
		$this->assertFileExists(
			$this->repo_root . '/scripts/verify-branch-policy.php',
			'scripts/verify-branch-policy.php must exist so the verifier script can be invoked by CI.'
		);
	}

	public function test_ci_branch_policy_uses_authenticated_checkout_refs_not_late_ls_remote(): void {
		$src = (string) file_get_contents( $this->repo_root . '/scripts/verify-branch-policy.php' );

		$this->assertStringContainsString(
			'refs/remotes/origin/',
			$src,
			'CI branch verification must consume remote-tracking refs fetched by actions/checkout.'
		);
		$this->assertStringContainsString(
			'show-ref --verify --hash',
			$src,
			'Branch existence must use an exit-status-safe ref lookup rather than rev-parse of an arbitrary token.'
		);
		$this->assertStringContainsString(
			'if ( $is_ci )',
			$src,
			'Private-repository CI must have an explicit offline branch-verification path after checkout removes credentials.'
		);
	}

	public function test_branch_policy_verifier_executes_clean(): void {
		$verifier = $this->repo_root . '/scripts/verify-branch-policy.php';
		$this->assertFileExists( $verifier );

		// Run the verifier as a subprocess so any side-effects
		// (ref scans, network calls to origin) cannot perturb
		// the PHPUnit state.
		$cmd    = escapeshellcmd( PHP_BINARY ) . ' ' . escapeshellarg( $verifier ) . ' 2>&1';
		$output = array();
		$rc     = 0;
		exec( $cmd, $output, $rc );

		$this->assertSame(
			0,
			$rc,
			"scripts/verify-branch-policy.php must exit 0. Output:\n" . implode( "\n", $output )
		);
		$this->assertStringContainsString(
			'Branch-Policy contract valid',
			implode( "\n", $output ),
			'verifier output must end with the success marker so CI logs prove the pass.'
		);
	}

	public function test_composer_test_branch_policy_script_wired(): void {
		$composer_json_path = $this->repo_root . '/composer.json';
		$this->assertFileExists( $composer_json_path );

		$composer = json_decode( (string) file_get_contents( $composer_json_path ), true );
		$this->assertIsArray( $composer, 'composer.json must be valid JSON.' );

		$scripts = $composer['scripts'] ?? array();
		$this->assertArrayHasKey(
			'test:branch-policy',
			$scripts,
			'composer.json must declare a test:branch-policy script for the Phase 77 gate.'
		);
		$this->assertSame(
			'php scripts/verify-branch-policy.php',
			$scripts['test:branch-policy'],
			'composer.json test:branch-policy script must invoke scripts/verify-branch-policy.php.'
		);
	}

	public function test_composer_ci_chain_includes_branch_policy(): void {
		$composer_json_path = $this->repo_root . '/composer.json';
		$composer           = json_decode( (string) file_get_contents( $composer_json_path ), true );

		$ci_chain = $composer['scripts']['ci'] ?? '';
		$this->assertStringContainsString(
			'composer test:branch-policy',
			$ci_chain,
			'composer.json `ci` chain must include `composer test:branch-policy` so Phase 77 runs in CI.'
		);
	}

	public function test_ci_yml_declares_branch_policy_step_pair(): void {
		$ci_yml_path = $this->repo_root . '/.github/workflows/ci.yml';
		$this->assertFileExists( $ci_yml_path );

		$ci_src = (string) file_get_contents( $ci_yml_path );

		$this->assertStringContainsString(
			'composer test:branch-policy',
			$ci_src,
			'ci.yml must declare a step that runs `composer test:branch-policy`.'
		);
		$this->assertStringContainsString(
			'SScribe_Branch_Policy_Test.php',
			$ci_src,
			'ci.yml must declare a step that runs the Phase 77 PHPUnit integration test.'
		);
	}

	public function test_release_audit_sh_declares_branch_policy_gate(): void {
		$audit_sh_path = $this->repo_root . '/bin/release-audit.sh';
		$this->assertFileExists( $audit_sh_path );

		$audit_src = (string) file_get_contents( $audit_sh_path );

		$this->assertStringContainsString(
			'Branch-Policy',
			$audit_src,
			'bin/release-audit.sh must declare the Phase 77 Branch-Policy gate.'
		);
		$this->assertStringContainsString(
			'test:branch-policy',
			$audit_src,
			'bin/release-audit.sh must invoke `composer test:branch-policy` for Phase 77.'
		);
		$this->assertStringContainsString(
			'/tmp/release-audit-branch-policy.log',
			$audit_src,
			'bin/release-audit.sh must record the Phase 77 log path.'
		);
	}

	public function test_ci_commands_doc_documents_branch_policy(): void {
		$ci_docs_path = $this->repo_root . '/docs/CI_COMMANDS.md';
		$this->assertFileExists( $ci_docs_path );

		$ci_docs_src = (string) file_get_contents( $ci_docs_path );

		$this->assertStringContainsString(
			'### `composer test:branch-policy`',
			$ci_docs_src,
			'docs/CI_COMMANDS.md must document `composer test:branch-policy` per the Phase 61 contract.'
		);
		$this->assertStringContainsString(
			'dist/branch-policy-manifest.json',
			$ci_docs_src,
			'docs/CI_COMMANDS.md must reference the Phase 77 manifest path.'
		);
	}

	public function test_only_main_and_develop_branches_locally(): void {
		// This is the most important rule: there must be no third
		// long-lived branch checked out locally. Run git from the
		// repo root via subprocess so PHPUnit does not need a
		// working-tree-relative shell.
		$cmd    = 'git -C ' . escapeshellarg( $this->repo_root ) . ' for-each-ref --format="%(refname:short)" refs/heads/';
		$output = array();
		exec( $cmd . ' 2>&1', $output );

		$branches = array_values(
			array_filter(
				array_map( 'trim', $output ),
				static function ( $b ) { return '' !== $b; }
			)
		);

		sort( $branches );

		// CI mode (GITHUB_ACTIONS=true) intentionally checks out
		// only the trigger branch. The origin-side rules
		// enforced by the verifier are the real contract in CI.
		// In local dev we still demand exactly {main, develop}.
		$is_ci = ( getenv( 'GITHUB_ACTIONS' ) === 'true' );
		if ( $is_ci ) {
			// Pull-request workflows are checked out at GitHub's synthetic
			// refs/pull/<n>/merge in detached-HEAD mode. In that valid state
			// refs/heads/ is empty; the verifier's authenticated
			// refs/remotes/origin/{main,develop} checks are the authoritative
			// CI contract. If any local named branches are present, however,
			// they must still be canonical.
			$disallowed = array_diff( $branches, array( 'main', 'develop' ) );
			$this->assertSame(
				array(),
				array_values( $disallowed ),
				'CI checkout must not contain forbidden long-lived branches. Found: ' . implode( ', ', $disallowed )
			);
			return;
		}

		$this->assertSame(
			array( 'develop', 'main' ),
			$branches,
			'Only `main` and `develop` are allowed as local long-lived branches. Found: ' . implode( ', ', $branches )
		);
	}
}
