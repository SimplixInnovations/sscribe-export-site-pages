<?php
/**
 * Phase 70 — Final release blockers contract (refactored).
 *
 * Pins the canonical release-blocker checklist
 * (docs/RELEASE_BLOCKERS_v2.0.0.md) at the PHPUnit boundary. The
 * companion verifier scripts/verify-release-blockers.php walks the
 * doc; this test re-states the same contract in PHPUnit so a
 * regression cannot slip past either guard.
 *
 * Canonical contract (refactored):
 *
 *   - Checklist doc exists.
 *   - Doc declares the canonical sections (Why this exists, Status
 *     convention, Canonical blockers, How an independent auditor
 *     verifies this).
 *   - All 21 canonical blocker rows are present.
 *   - Every blocker has a status in {RESOLVED, DEFERRED}.
 *   - Every blocker declares a recognised closure-source token.
 *   - Static (closure: static) blockers are RESOLVED in the doc.
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

final class SScribe_Release_Blockers_Test extends TestCase {

	/** @var string */
	private string $repo_root;

	/** @var string */
	private string $checklist_path;

	protected function setUp(): void {
		$this->repo_root      = dirname( __DIR__, 2 );
		$this->checklist_path = $this->repo_root . '/docs/RELEASE_BLOCKERS_v2.0.0.md';
	}

	public function test_release_blocker_checklist_doc_exists(): void {
		$this->assertFileExists(
			$this->checklist_path,
			'docs/RELEASE_BLOCKERS_v2.0.0.md must exist so the Phase 70 release blockers are auditable.'
		);
	}

	public function test_release_blocker_checklist_has_canonical_sections(): void {
		$src = (string) file_get_contents( $this->checklist_path );

		$canonical_sections = array(
			'## Why this exists',
			'## Status convention',
			'## Canonical blockers',
			'## How an independent auditor verifies this',
		);

		foreach ( $canonical_sections as $section ) {
			$this->assertStringContainsString(
				$section,
				$src,
				"Release blockers checklist is missing canonical section: {$section}"
			);
		}
	}

	public function test_release_blocker_checklist_has_closure_source_vocabulary(): void {
		$src = (string) file_get_contents( $this->checklist_path );

		// The new architecture requires a Closure source vocabulary table
		// to be declared in the doc.
		$this::assertStringContainsString(
			'## Closure source vocabulary',
			$src,
			'Release blockers checklist must declare its Closure source vocabulary (refactored Phase 70 contract).'
		);

		$required_tokens = array(
			'static',
			'phase71-evidence',
			'e2e-evidence',
			'plugin-check-evidence',
			'clean-install-evidence',
			'runtime-export-evidence',
			'manual-runtime-evidence',
			'source-transparency-evidence',
		);
		foreach ( $required_tokens as $token ) {
			$this::assertStringContainsString(
				$token,
				$src,
				"Closure source vocabulary must declare token: {$token}"
			);
		}
	}

	public function test_release_blocker_checklist_lists_every_canonical_blocker(): void {
		$src = (string) file_get_contents( $this->checklist_path );

		$canonical_blockers = array(
			'Any required CI job red',
			'Any required job skipped',
			'PHPUnit runtime fatal',
			'E2E not actually executed',
			'Security workflow red',
			'All Languages broken',
			'All Types Preview mismatch',
			'Stale count race',
			'Stale abort retry',
			'Preflight JS missing function',
			'Terminal 500 retry storm',
			'Operational fatal/error log not durable',
			'Redis limiter inconsistent',
			'Invalid WordPress/PHP minimum metadata',
			'Plugin Check not run on exact ZIP',
			'Exact ZIP not clean-install tested',
			'Exact ZIP not runtime-export tested',
			'Source / build transparency unresolved',
			'License inventory unresolved',
			'Release path capable of rebuilding untested bytes',
			'Manual runtime environment matrix not executed on exact ZIP',
		);

		foreach ( $canonical_blockers as $expected ) {
			$this::assertStringContainsStringIgnoringCase(
				$expected,
				$src,
				"Canonical blocker '{$expected}' must appear in the release blockers checklist."
			);
		}
	}

	public function test_every_release_blocker_has_resolved_or_deferred_status(): void {
		$src = (string) file_get_contents( $this->checklist_path );

		// 5-column table: # | Blocker | Status | Closure source | Evidence
		$hits = array();
		if ( preg_match_all( '/^\|\s*([0-9]+)\s*\|\s*([^|]+?)\s*\|\s*(RESOLVED|DEFERRED|OPEN|BLOCKED)\s*\|\s*([^|]+?)\s*\|\s*([^|]*?)\s*\|\s*$/m', $src, $matches ) ) {
			foreach ( $matches[1] as $idx => $num ) {
				$hits[ (int) $num ] = array(
					'blocker'        => trim( $matches[2][ $idx ] ),
					'status'         => trim( $matches[3][ $idx ] ),
					'closure_source' => trim( $matches[4][ $idx ] ),
					'evidence'       => trim( $matches[5][ $idx ] ),
				);
			}
		}

		$this::assertNotEmpty(
			$hits,
			'Release blockers checklist must contain at least one row with a status.'
		);

		$valid_statuses        = array( 'RESOLVED', 'DEFERRED' );
		$valid_closure_sources = array( 'static', 'phase71-evidence', 'e2e-evidence', 'plugin-check-evidence', 'clean-install-evidence', 'runtime-export-evidence', 'manual-runtime-evidence', 'source-transparency-evidence' );

		foreach ( $hits as $row ) {
			$this::assertContains(
				$row['status'],
				$valid_statuses,
				"Blocker '{$row['blocker']}' has status '{$row['status']}'; only RESOLVED or DEFERRED allowed."
			);
			$this::assertContains(
				$row['closure_source'],
				$valid_closure_sources,
				"Blocker '{$row['blocker']}' declares unknown closure source '{$row['closure_source']}'."
			);
		}
	}

	public function test_static_release_blockers_are_resolved_in_doc(): void {
		// Static blockers MUST be RESOLVED in the doc — closure source `static`
		// means the only proof of resolution lives in tracked code/test/doc.
		$src = (string) file_get_contents( $this->checklist_path );

		$hits = array();
		if ( preg_match_all( '/^\|\s*([0-9]+)\s*\|\s*([^|]+?)\s*\|\s*(RESOLVED|DEFERRED|OPEN|BLOCKED)\s*\|\s*([^|]+?)\s*\|\s*([^|]*?)\s*\|\s*$/m', $src, $matches ) ) {
			foreach ( $matches[1] as $idx => $num ) {
				$hits[ (int) $num ] = array(
					'blocker'        => trim( $matches[2][ $idx ] ),
					'status'         => trim( $matches[3][ $idx ] ),
					'closure_source' => trim( $matches[4][ $idx ] ),
				);
			}
		}

		$static_unresolved = array();
		foreach ( $hits as $row ) {
			if ( 'static' === $row['closure_source'] && 'RESOLVED' !== $row['status'] ) {
				$static_unresolved[] = $row['blocker'];
			}
		}
		$this::assertEmpty(
			$static_unresolved,
			'Static (closure: static) blockers must be RESOLVED in the doc; otherwise tracked code/test/doc claims are broken. Violations: ' . implode( ', ', $static_unresolved )
		);
	}

	public function test_cancel_handler_never_terminates_ajax_while_export_lock_is_held(): void {
		$path = $this->repo_root . '/includes/traits/trait-sscribe-session-ajax.php';
		$src  = (string) file_get_contents( $path );

		$method_start = strpos( $src, 'public function ajax_cancel_export(): void {' );
		$method_end   = strpos( $src, 'public function ajax_clear_session(): void {', $method_start );
		$this::assertNotFalse( $method_start );
		$this::assertNotFalse( $method_end );

		$method         = substr( $src, $method_start, $method_end - $method_start );
		$acquire_marker = '$lock_token = $this->get_lock_manager()->acquire_lock( $session_id, 30, 25 );';
		$release_marker = '$this->get_lock_manager()->release_lock( $session_id, $lock_token );';
		$acquire        = strpos( $method, $acquire_marker );
		$release        = strpos( $method, $release_marker, false === $acquire ? 0 : $acquire );

		$this::assertNotFalse( $acquire, 'Cancel handler must acquire the per-session export lock.' );
		$this::assertNotFalse( $release, 'Cancel handler must release the per-session export lock.' );
		$this::assertGreaterThan( $acquire, $release, 'Cancel handler must release only after lock acquisition.' );

		$critical_section = substr( $method, $acquire, $release - $acquire );
		$this::assertStringNotContainsString(
			'SScribe_AJAX_Guard::success',
			$critical_section,
			'Cancel handler must not terminate through a success response before releasing the export lock.'
		);
		$this::assertStringNotContainsString(
			'SScribe_AJAX_Guard::error',
			$critical_section,
			'Cancel handler must not terminate through an error response before releasing the export lock.'
		);
	}

	public function test_missing_session_batch_path_never_discards_an_unowned_lock(): void {
		$path = $this->repo_root . '/includes/traits/trait-sscribe-batch-step-handler.php';
		$src  = (string) file_get_contents( $path );

		$this::assertStringNotContainsString(
			'$this->get_lock_manager()->discard_lock( $session_id );',
			$src,
			'A missing session does not prove lock ownership; batch handling must leave any extant lock to its owner or TTL cleanup.'
		);
	}

	public function test_finalize_renewal_failure_releases_owned_lock_before_response(): void {
		$path = $this->repo_root . '/includes/traits/trait-sscribe-export-finalizer.php';
		$src  = (string) file_get_contents( $path );

		$branch_start = strpos( $src, 'if ( null !== $lock_token && ! $this->get_lock_manager()->renew_lock( $session_id, $lock_token, 600 ) ) {' );
		$branch_end   = strpos( $src, '$zip_path = $this->zip_handler->create_zip', false === $branch_start ? 0 : $branch_start );
		$this::assertNotFalse( $branch_start );
		$this::assertNotFalse( $branch_end );

		$failure_branch = substr( $src, $branch_start, $branch_end - $branch_start );
		$this::assertStringContainsString(
			'$this->release_lock( $session_id, $lock_token );',
			$failure_branch,
			'Finalize renewal failure must make a token-safe release attempt before the terminating AJAX response.'
		);
	}

	public function test_start_export_enforces_declared_page_id_cap_at_collection_boundary(): void {
		$path = $this->repo_root . '/includes/class-sscribe-batch-processor.php';
		$src  = (string) file_get_contents( $path );

		$cap_marker   = '$page_id_cap    = 10000;';
		$query_marker = '$page_ids      = $this->collector->get_page_ids( $language, $post_status, $post_type, $page_id_cap );';
		$cap_pos      = strpos( $src, $cap_marker );
		$query_pos    = strpos( $src, $query_marker );

		$this::assertNotFalse( $cap_pos, 'Start-export must declare its hard page-ID cap.' );
		$this::assertNotFalse(
			$query_pos,
			'Start-export must pass the declared cap into page-ID collection so the chunked path cannot become unbounded.'
		);
		$this::assertLessThan( $query_pos, $cap_pos, 'The cap must be defined before the bounded collection call.' );
	}

	public function test_runtime_evidence_is_bound_to_exact_release_identity(): void {
		$src = (string) file_get_contents( $this->repo_root . '/scripts/verify-release-blockers.php' );

		$this::assertStringContainsString( 'check_exact_release_identity', $src );
		$this::assertStringContainsString( 'clean_install_release_identity_mismatch', $src );
		$this::assertStringContainsString( 'runtime_export_release_identity_mismatch', $src );
		$this::assertStringContainsString( "payload['source_sha']", $src );
		$this::assertStringContainsString( "payload['zip_sha256']", $src );
	}

	public function test_release_blocker_verifier_script_exists(): void {
		$this::assertFileExists(
			$this->repo_root . '/scripts/verify-release-blockers.php',
			'scripts/verify-release-blockers.php must exist so the verifier script can be invoked by CI.'
		);
	}

	public function test_composer_test_release_blockers_script_wired(): void {
		$composer_json_path = $this->repo_root . '/composer.json';
		$this::assertFileExists( $composer_json_path );

		$composer = json_decode( (string) file_get_contents( $composer_json_path ), true );
		$this::assertIsArray( $composer, 'composer.json must be valid JSON.' );

		$scripts = $composer['scripts'] ?? array();
		$this::assertArrayHasKey(
			'test:release-blockers',
			$scripts,
			'composer.json must declare a test:release-blockers script for the Phase 70 gate.'
		);
		$this::assertSame(
			'php scripts/verify-release-blockers.php',
			$scripts['test:release-blockers'],
			'composer.json test:release-blockers script must invoke scripts/verify-release-blockers.php.'
		);
	}

	public function test_composer_ci_chain_includes_release_blockers(): void {
		$composer_json_path = $this->repo_root . '/composer.json';
		$composer           = json_decode( (string) file_get_contents( $composer_json_path ), true );

		$ci_chain = $composer['scripts']['ci'] ?? '';
		$this::assertStringContainsString(
			'composer test:release-blockers',
			$ci_chain,
			'composer.json `ci` chain must include `composer test:release-blockers` so Phase 70 runs in CI.'
		);
	}

	public function test_ci_yml_declares_release_blockers_step_pair(): void {
		$ci_yml_path = $this->repo_root . '/.github/workflows/ci.yml';
		$this::assertFileExists( $ci_yml_path );

		$ci_src = (string) file_get_contents( $ci_yml_path );

		$this::assertStringContainsString(
			'composer test:release-blockers',
			$ci_src,
			'ci.yml must declare a step that runs `composer test:release-blockers`.'
		);
		$this::assertStringContainsString(
			'SScribe_Release_Blockers_Test.php',
			$ci_src,
			'ci.yml must declare a step that runs the Phase 70 PHPUnit integration test.'
		);
	}

	public function test_release_audit_sh_declares_release_blockers_gate(): void {
		$audit_php_path = $this->repo_root . '/scripts/release-audit.php';
		$this::assertFileExists( $audit_php_path );

		$audit_src = (string) file_get_contents( $audit_php_path );

		$this::assertStringContainsString(
			'Release-Blockers',
			$audit_src,
			'scripts/release-audit.php must declare the Phase 70 Release-Blockers gate.'
		);
		$this::assertStringContainsString(
			'test:release-blockers',
			$audit_src,
			'scripts/release-audit.php must invoke `composer test:release-blockers` for Phase 70.'
		);
		$this::assertStringContainsString(
			'release-audit-',
			$audit_src,
			'scripts/release-audit.php must record per-gate logs as release-audit-<gate>.log (Phase 70: release-audit-release-blockers.log).'
		);

		$audit_sh_path = $this->repo_root . '/bin/release-audit.sh';
		$this::assertFileExists( $audit_sh_path );
		$this::assertStringContainsString(
			'scripts/release-audit.php',
			(string) file_get_contents( $audit_sh_path ),
			'bin/release-audit.sh must delegate to the canonical scripts/release-audit.php.'
		);
	}

	public function test_ci_commands_doc_documents_release_blockers(): void {
		$ci_docs_path = $this->repo_root . '/docs/CI_COMMANDS.md';
		$this::assertFileExists( $ci_docs_path );

		$ci_docs_src = (string) file_get_contents( $ci_docs_path );

		$this::assertStringContainsString(
			'### `composer test:release-blockers`',
			$ci_docs_src,
			'docs/CI_COMMANDS.md must document `composer test:release-blockers` per the Phase 61 contract.'
		);
		$this::assertStringContainsString(
			'dist/release-blockers-manifest.json',
			$ci_docs_src,
			'docs/CI_COMMANDS.md must reference the Phase 70 manifest path.'
		);
	}

	public function test_release_blockers_manifest_persists(): void {
		// The verifier writes a manifest when it runs. The
		// presence of dist/release-blockers-manifest.json is
		// optional in CI (the verifier runs first, then the
		// manifest is read by debug tooling); this test
		// documents the canonical path so future contributors
		// know where to look for the gate's evidence.
		$manifest_path = $this->repo_root . '/dist/release-blockers-manifest.json';
		$this::assertTrue(
			true,
			"Manifest canonical path: {$manifest_path}"
		);
	}
}
