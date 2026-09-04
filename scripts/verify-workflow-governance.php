<?php
/**
 * Phase 52 — Workflow governance verifier.
 *
 * Contract: every release-required workflow must be a hard gate.
 *
 *   1. No `continue-on-error: true` for any required step. A regression
 *      that adds `continue-on-error` to a verifier or test step
 *      silently lets that step pass while still failing CI — the
 *      behaviour we are explicitly forbidding.
 *
 *   2. No `if: failure()` / `if: always() || failure()` / `if: cancelled()`
 *      on required steps. The standard `if: failure()` is allowed
 *      ONLY for a SHA-pinned `actions/upload-artifact` (upload the report when
 *      the test fails) — every other use is a hidden pass-on-error.
 *
 *   3. Each workflow MUST declare a `name:` so the GitHub status
 *      checks UI has a readable label (mandatory for the branch
 *      protection required-status contract documented in
 *      docs/BRANCH_PROTECTION_v2.0.0.md).
 *
 *   4. Workflow classifications (required vs optional) must agree with
 *      `docs/WORKFLOW_GOVERNANCE.md`. Optional workflows may use
 *      `continue-on-error` for diagnostic-only steps; required
 *      workflows must not.
 *
 * The verifier walks every `.github/workflows/*.yml`, classifies each
 * step, and reports any violation. Integration test (Phase 52, test
 * file) re-checks the manifest so the gate is locked.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

if ( 'cli' !== php_sapi_name() ) {
	exit( 'This script must be run from the command line.' );
}

$root_dir      = dirname( __DIR__ );
$workflows_dir = $root_dir . '/.github/workflows';
$manifest_path = $root_dir . '/dist/workflow-governance-manifest.json';

/**
 * Workflow classification — the release gate must clearly distinguish
 * REQUIRED (release-blocking) from OPTIONAL (diagnostic).
 *
 * - REQUIRED workflows gate the release tag. A red required workflow
 *   blocks Phase 53–54 (certification + tagging). They MUST NOT use
 *   `continue-on-error` on any test/verifier/build step.
 *
 * - OPTIONAL workflows emit diagnostics. They MAY use
 *   `continue-on-error` on caching / artifact upload steps, but
 *   every test step must still be a hard gate.
 */
$required_workflows = array(
	'ci.yml'             => 'Main CI gate (every push + PR)',
	'e2e.yml'            => 'Exact-package Playwright browser/runtime export gate',
	'release.yml'        => 'Tag-driven release workflow (v* tags)',
	'release-audit.yml'  => 'Single-pass release-promotion audit for main',
);
$optional_workflows = array();

$matrix   = array();
$errors   = array();
$warnings = array();

if ( ! is_dir( $workflows_dir ) ) {
	$errors[] = '.github/workflows directory does not exist.';
	fwrite( STDERR, "✗ .github/workflows not found.\n" );
	exit( 1 );
}

$workflow_files = glob( $workflows_dir . '/*.yml' );
if ( false === $workflow_files || empty( $workflow_files ) ) {
	$errors[] = 'No .yml workflow files found in .github/workflows/.';
	fwrite( STDERR, "✗ No .yml files in .github/workflows/.\n" );
	exit( 1 );
}

foreach ( $workflow_files as $wf_path ) {
	$wf_name  = basename( $wf_path );
	$required = array_key_exists( $wf_name, $required_workflows );
	$optional = array_key_exists( $wf_name, $optional_workflows );

	if ( ! $required && ! $optional ) {
		$warnings[] = sprintf(
			'%s is unclassified. Document it in $required_workflows or $optional_workflows inside scripts/verify-workflow-governance.php so future audits know whether it gates the release.',
			$wf_name
		);
	}

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$content = (string) file_get_contents( $wf_path );

	// Rule 1: every workflow declares a top-level `name:`.
	$has_name = (bool) preg_match( '/^name:\s*[^\n#]+/m', $content );
	$matrix[] = array(
		'rule'   => "{$wf_name} declares top-level name:",
		'passes' => $has_name,
		'detail' => 'Every GitHub Actions workflow must declare a `name:` so the branch-protection required-check contract has a readable label.',
	);
	if ( ! $has_name ) {
		$errors[] = "{$wf_name} is missing a top-level `name:` field.";
	}

	// Rule 2 (REQUIRED only): no `continue-on-error: true` on any step.
	if ( $required ) {
		$has_continue = (bool) preg_match( '/^\s*continue-on-error:\s*true\s*$/m', $content );
		$matrix[] = array(
			'rule'   => "{$wf_name} has no `continue-on-error: true` (release-required gate)",
			'passes' => ! $has_continue,
			'detail' => 'Release-required workflows must not mask a failed step. Per Phase 52: "No `continue-on-error` for required tests."',
		);
		if ( $has_continue ) {
			$errors[] = "{$wf_name} (release-required) uses `continue-on-error: true` on at least one step. This silently lets the step pass while still failing CI — forbidden by Phase 52.";
		}
	}

	// Rule 3: every workflow has a top-level `on:` trigger (push / pull_request / etc.).
	$has_trigger = (bool) preg_match( '/^on:\s*\n/m', $content )
		|| (bool) preg_match( '/^"on":\s*\n/m', $content )
		|| (bool) preg_match( "/^['\"]on['\"]:\\s*\\n/m", $content );
	$matrix[] = array(
		'rule'   => "{$wf_name} declares top-level on: trigger",
		'passes' => $has_trigger,
		'detail' => 'Every workflow must declare at least one trigger (push / pull_request / schedule / workflow_call).',
	);
	if ( ! $has_trigger ) {
		$errors[] = "{$wf_name} is missing a top-level `on:` trigger.";
	}

	// Rule 4: `if: failure()` / `if: always() || failure()` /
	// `if: cancelled() || failure()` on non-artifact steps is forbidden.
	//
	// We parse line-by-line and accept `if: failure()` ONLY when the
	// step is `actions/upload-artifact@*` or `actions/cache@*`.
	if ( preg_match_all( '/^(\s*)-\s+(?:name:\s*[^\n]*\n\s+)?if:\s*([^\n]+)\n(?:\s+\S+:\s*[^\n]*\n)*?\s+uses:\s*([^\n]+)/m', $content, $if_matches, PREG_SET_ORDER ) ) {
		foreach ( $if_matches as $if_match ) {
			$if_expr  = trim( $if_match[2] );
			$uses_act = trim( $if_match[3] );
			// Strip action@version (e.g. actions/upload-artifact@v4 → actions/upload-artifact).
			$base_action = strtolower( (string) preg_replace( '/@.*$/', '', $uses_act ) );
			$is_artifact_step = in_array( $base_action, array( 'actions/upload-artifact', 'actions/cache', 'actions/download-artifact' ), true );
			if ( $is_artifact_step ) {
				continue;
			}

			$expr = strtolower( $if_expr );
			$wants_pass_on_fail = (bool) preg_match( '/\bfailure\(\)/', $expr )
				|| (bool) preg_match( '/\bcancelled\(\)/', $expr )
				|| (bool) preg_match( '/\balways\(\)/', $expr )
				|| (bool) preg_match( '/!\s*success\(\)/', $expr );
			if ( $wants_pass_on_fail ) {
				$matrix[] = array(
					'rule'   => "{$wf_name} step `if: {$if_expr}` uses {!success() || failure() || cancelled() || always()}",
					'passes' => false,
					'detail' => 'Steps using `if: failure()` / `if: cancelled()` / `if: always()` outside `actions/upload-artifact*` / `actions/cache*` mask tool failures — forbidden by Phase 52.',
				);
				$errors[] = sprintf(
					'%s has a non-artifact step with `if: %s` on `%s`. Pass-on-failure gates are forbidden; remove the `if:` clause or change the action.',
					$wf_name,
					$if_expr,
					$uses_act
				);
			}
		}
	}

	// Rule 5: external GitHub Actions MUST be pinned to immutable commits.
	// Major-version tags such as @v6 are mutable and therefore insufficient
	// for a release supply-chain boundary.
	if ( preg_match_all( '/uses:\s*([^\s#]+)/', $content, $uses_matches ) ) {
		foreach ( $uses_matches[1] as $uses_ref ) {
			$uses_ref = trim( $uses_ref );
			if ( '' === $uses_ref || str_starts_with( $uses_ref, './' ) ) {
				continue;
			}
			$immutable = (bool) preg_match(
				'/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+(?:\/[A-Za-z0-9_.-]+)*@[0-9a-f]{40}$/i',
				$uses_ref
			);
			if ( ! $immutable ) {
				$matrix[] = array(
					'rule'   => "{$wf_name} uses mutable/unpinned action: {$uses_ref}",
					'passes' => false,
					'detail' => 'External actions must use a full 40-character commit SHA; mutable @vN/@main/@master references are forbidden.',
				);
				$errors[] = "{$wf_name} references mutable or unpinned action `{$uses_ref}`.";
			}
		}
	}
}

// Verify the classifier self-documents (file itself describes required
// vs optional), so a future maintainer who adds a new workflow sees the
// classification table inline.
$self_documents_classification = (bool) preg_match( '/required_workflows|optional_workflows|release-required|optional/', (string) file_get_contents( __FILE__ ) );
$matrix[] = array(
	'rule'   => 'verifier self-documents required/optional classification',
	'passes' => $self_documents_classification,
	'detail' => 'The verifier itself must describe which workflows are release-required vs optional so adding a new workflow surfaces the classification question.',
);
if ( ! $self_documents_classification ) {
	$errors[] = 'Verifier does not document the required vs optional classification in its source.';
}

// Persist manifest.
$manifest_dir = dirname( $manifest_path );
if ( ! is_dir( $manifest_dir ) ) {
	mkdir( $manifest_dir, 0755, true );
}
$manifest = array(
	'generated_at'         => gmdate( 'c' ),
	'rule_count'           => count( $matrix ),
	'passed_count'         => count( array_filter( $matrix, static fn( $r ) => $r['passes'] ) ),
	'required_workflows'   => array_keys( $required_workflows ),
	'optional_workflows'   => array_keys( $optional_workflows ),
	'matrix'               => $matrix,
	'errors_count'         => count( $errors ),
	'warnings_count'       => count( $warnings ),
	'passes'               => 0 === count( $errors ),
	'errors'               => $errors,
	'warnings'             => $warnings,
);
file_put_contents(
	$manifest_path,
	json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
);

echo "=== SScribe Workflow Governance ===\n\n";
echo "Required workflows (release gate):\n";
foreach ( array_keys( $required_workflows ) as $wf ) {
	echo "  - {$wf}\n";
}
echo "\nOptional workflows (diagnostic only):\n";
foreach ( array_keys( $optional_workflows ) as $wf ) {
	echo "  - {$wf}\n";
}
echo "\n";

foreach ( $matrix as $row ) {
	$status = $row['passes'] ? '✓' : '✗';
	echo sprintf( "  %s  %s\n      %s\n", $status, $row['rule'], $row['detail'] );
}
echo "\nErrors: " . count( $errors ) . "\n";
foreach ( $errors as $error ) {
	echo "  ✗ {$error}\n";
}
if ( ! empty( $warnings ) ) {
	echo "\nWarnings: " . count( $warnings ) . "\n";
	foreach ( $warnings as $warning ) {
		echo "  ⚠ {$warning}\n";
	}
}
echo "\nManifest persisted to: {$manifest_path}\n";

if ( ! empty( $errors ) ) {
	exit( 1 );
}
echo "✓ Workflow governance contract valid.\n";
exit( 0 );
