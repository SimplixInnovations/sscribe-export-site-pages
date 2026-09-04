<?php
/**
 * Phase 52 — Workflow governance integration test.
 *
 * Pin the rule that every release-required GitHub Actions workflow is
 * a hard gate. A regression that adds `continue-on-error: true` (or
 * wraps a verifier/test step in `if: failure()`) silently lets the
 * step pass while still failing CI, defeating the gate. The test
 * fires the verifier, asserts a clean manifest, and confirms the
 * classification table inside the verifier matches what the runtime
 * sees.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

namespace SScribe\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class SScribe_Workflow_Governance_Test extends TestCase {

	private const VERIFIER_PATH = 'scripts/verify-workflow-governance.php';
	private const MANIFEST_PATH = 'dist/workflow-governance-manifest.json';
	private const WORKFLOWS_DIR = '.github/workflows';

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

	public function test_live_tree_passes_workflow_governance_verifier(): void {
		list( $code, $output ) = $this->run_verifier();
		$this::assertSame(
			0,
			$code,
			'Live .github/workflows tree must satisfy the governance contract. Output:' . "\n" . $output
		);
		$this::assertStringContainsString( 'Workflow governance contract valid', $output );
	}

	public function test_manifest_records_no_errors(): void {
		list( $code ) = $this->run_verifier();
		$this::assertSame( 0, $code );

		$payload = json_decode( (string) file_get_contents( self::plugin_root() . '/' . self::MANIFEST_PATH ), true );
		$this::assertIsArray( $payload );
		$this::assertTrue( $payload['passes'], 'workflow-governance manifest reports errors: ' . wp_json_encode( $payload['errors'] ?? array() ) );
		$this::assertSame( 0, $payload['errors_count'] );
		$this::assertGreaterThanOrEqual( 12, $payload['rule_count'] );
		$this::assertSame( $payload['rule_count'], $payload['passed_count'] );
	}

	public function test_classification_documents_required_and_optional(): void {
		// Required workflows gate Phase 53–54 release certification +
		// tagging. Optional workflows are diagnostic. The verifier must
		// keep both lists current so a new workflow file forces a
		// classification decision.
		list( $code ) = $this->run_verifier();
		$this::assertSame( 0, $code );

		$payload = json_decode( (string) file_get_contents( self::plugin_root() . '/' . self::MANIFEST_PATH ), true );
		$this::assertArrayHasKey( 'required_workflows', $payload );
		$this::assertArrayHasKey( 'optional_workflows', $payload );

		$required = $payload['required_workflows'];
		$this::assertContains( 'ci.yml', $required, 'ci.yml is the main release gate and must be classified as required.' );
		$this::assertContains( 'release.yml', $required, 'release.yml drives WP.org releases and must be required.' );
		$this::assertContains( 'release-audit.yml', $required, 'release-audit.yml is the release-branch gate and must be required.' );

		$optional = $payload['optional_workflows'];
		$this::assertContains( 'e2e.yml', $optional, 'e2e.yml is browser diagnostics and must be classified as optional.' );

		// No workflow may be in BOTH lists — the contract demands a
		// unambiguous classification.
		$overlap = array_intersect( $required, $optional );
		$this::assertEmpty( $overlap, 'A workflow cannot be both required and optional: ' . implode( ', ', $overlap ) );
	}

	public function test_ci_and_e2e_cancel_superseded_candidate_runs(): void {
		$root = self::plugin_root() . '/' . self::WORKFLOWS_DIR;

		foreach ( array( 'ci.yml', 'e2e.yml' ) as $workflow ) {
			$path = $root . '/' . $workflow;
			$this::assertFileExists( $path );
			$source = (string) file_get_contents( $path );

			$this::assertStringContainsString(
				'concurrency:',
				$source,
				"{$workflow} must declare candidate-scoped concurrency."
			);
			$this::assertStringContainsString(
				'github.event.pull_request.number || github.ref',
				$source,
				"{$workflow} concurrency must isolate each PR/branch candidate."
			);
			$this::assertMatchesRegularExpression(
				'/cancel-in-progress:\s*true/',
				$source,
				"{$workflow} must cancel superseded runs so stale candidates cannot starve release validation."
			);
		}
	}

	public function test_no_release_required_workflow_uses_continue_on_error(): void {
		// Direct grep on each required workflow to lock the contract at
		// the source level — even if a future verifier release misses a
		// case, this assertion catches the regression.
		$root = self::plugin_root() . '/' . self::WORKFLOWS_DIR;
		$this::assertDirectoryExists( $root );

		$required = array( 'ci.yml', 'release.yml', 'release-audit.yml' );
		foreach ( $required as $wf ) {
			$path   = $root . '/' . $wf;
			$this::assertFileExists( $path, "required workflow {$wf} must exist" );
			$source = (string) file_get_contents( $path );
			$this::assertDoesNotMatchRegularExpression(
				'/^\s*continue-on-error:\s*true\s*$/m',
				$source,
				"Phase 52 forbids `continue-on-error: true` in {$wf}. Required workflows are release gates."
			);
		}
	}

	public function test_verifier_distinguishes_artifact_if_failure_from_test_if_failure(): void {
		// The verifier must allow `if: failure()` ONLY on
		// actions/upload-artifact@*, actions/cache@*, and
		// actions/download-artifact@* — every other use is a hidden
		// pass-on-error and is a release blocker.
		$source = (string) file_get_contents( self::plugin_root() . '/' . self::VERIFIER_PATH );
		$this::assertStringContainsString( 'actions/upload-artifact', $source );
		$this::assertStringContainsString( 'actions/cache', $source );
		$this::assertStringContainsString( 'actions/download-artifact', $source );
		$this::assertStringContainsString( 'continue-on-error', $source );
		// Should also pin `if: failure()` / `if: always()` / `if: cancelled()` checks.
		$this::assertStringContainsString( 'failure()', $source );
		$this::assertStringContainsString( 'always()', $source );
	}

	public function test_verifier_pins_required_workflow_set(): void {
		// The verifier must declare its own classification table. A
		// future refactor that drops the inline classification forces
		// the next maintainer to think through which workflows gate the
		// release.
		$source = (string) file_get_contents( self::plugin_root() . '/' . self::VERIFIER_PATH );
		$this::assertStringContainsString( "required_workflows", $source );
		$this::assertStringContainsString( "optional_workflows", $source );
		$this::assertStringContainsString( "'ci.yml'", $source );
		$this::assertStringContainsString( "'release.yml'", $source );
		$this::assertStringContainsString( "'release-audit.yml'", $source );
		$this::assertStringContainsString( "'e2e.yml'", $source );
	}
}
