<?php
/**
 * Test-suite manifest regenerator.
 *
 * Many integration tests read `dist/*-manifest.json` files that the
 * composer release pipeline wipes between runs. Without regeneration,
 * the test suite becomes order-dependent — whichever test class
 * happens to call its verifier first seeds the manifest, and any
 * sibling test class that runs first and tries to read the same
 * manifest fails with "Failed to open stream: No such file or directory".
 *
 * This script regenerates every test-suite manifest that is missing.
 * Idempotent: existing manifests are left alone (call `composer release`
 * or `rm dist/*-manifest.json` to force a full refresh).
 *
 * Phase 78 invariant: the test suite must be order-independent.
 *
 * Usage:
 *   php scripts/regenerate-test-manifests.php         # only missing
 *   php scripts/regenerate-test-manifests.php --all   # force all
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

if ( 'cli' !== php_sapi_name() ) {
	exit( 'This script must be run from the command line.' );
}

$root_dir = dirname( __DIR__ );
$force    = in_array( '--all', $argv, true );

/**
 * Each entry: verifier script (relative to repo root) → manifest file
 * (relative to repo root). Keep in alphabetical order by verifier for
 * predictable diffs.
 */
$pairs = array(
	'scripts/verify-acceptance-matrix.php'         => 'dist/acceptance-matrix-manifest.json',
	'scripts/verify-accessibility.php'             => 'dist/accessibility-manifest.json',
	'scripts/verify-agent-final-report.php'        => 'dist/agent-final-report-manifest.json',
	'scripts/verify-ajax-network-trace.php'        => 'dist/ajax-network-trace-manifest.json',
	'scripts/verify-ajax-security.php'             => 'dist/ajax-security-manifest.json',
	'scripts/verify-auditor-handoff.php'           => 'dist/auditor-handoff-manifest.json',
	'scripts/verify-branch-policy.php'             => 'dist/branch-policy-manifest.json',
	'scripts/verify-branch-protection.php'         => 'dist/branch-protection-manifest.json',
	'scripts/verify-build-order.php'               => 'dist/build-order-manifest.json',
	'scripts/verify-ci-docs.php'                   => 'dist/ci-docs-manifest.json',
	'scripts/verify-debug-log.php'                 => 'dist/debug-log-manifest.json',
	'scripts/verify-definition-of-done.php'         => 'dist/definition-of-done-manifest.json',
	'scripts/verify-download-security.php'         => 'dist/download-security-manifest.json',
	'scripts/verify-export-result.php'             => 'dist/export-result-manifest.json',
	'scripts/verify-format-matrix.php'             => 'dist/format-matrix-manifest.json',
	'scripts/verify-i18n.php'                      => 'dist/i18n-manifest.json',
	'scripts/verify-js-error-free.php'             => 'dist/js-error-free-manifest.json',
	'scripts/verify-lifecycle.php'                 => 'dist/lifecycle-manifest.json',
	'scripts/verify-manual-runtime-tests.php'      => 'dist/manual-runtime-tests-manifest.json',
	'scripts/verify-no-internal-details.php'       => 'dist/no-internal-details-manifest.json',
	'scripts/verify-performance-budget.php'        => 'dist/performance-budget-manifest.json',
	'scripts/verify-phpstan-ignores.php'           => 'dist/phpstan-ignore-manifest.json',
	'scripts/verify-plugin-check-triage.php'       => 'dist/plugin-check-triage-manifest.json',
	'scripts/verify-real-wp-matrix.php'            => 'dist/real-wp-matrix-manifest.json',
	'scripts/verify-release-pipeline.php'          => 'dist/release-pipeline-manifest.json',
	'scripts/verify-tag-policy.php'                => 'dist/tag-policy-manifest.json',
	'scripts/verify-ui-refactor-discipline.php'    => 'dist/ui-refactor-discipline-manifest.json',
	'scripts/verify-workflow-governance.php'       => 'dist/workflow-governance-manifest.json',
	'scripts/verify-wpml-strategy.php'             => 'dist/wpml-strategy-manifest.json',
);

$regenerated = array();
$skipped     = array();
$failed      = array();

foreach ( $pairs as $verifier_rel => $manifest_rel ) {
	$verifier = $root_dir . '/' . $verifier_rel;
	$manifest = $root_dir . '/' . $manifest_rel;

	if ( ! is_file( $verifier ) ) {
		$failed[] = sprintf( 'missing verifier %s', $verifier_rel );
		continue;
	}

	if ( ! $force && is_file( $manifest ) ) {
		$skipped[] = $manifest_rel;
		continue;
	}

	$descriptors = array(
		0 => array( 'pipe', 'r' ),
		1 => array( 'pipe', 'w' ),
		2 => array( 'pipe', 'w' ),
	);
	$process = proc_open( array( PHP_BINARY, $verifier ), $descriptors, $pipes );
	if ( ! is_resource( $process ) ) {
		$failed[] = sprintf( 'spawn failed %s', $verifier_rel );
		continue;
	}
	fclose( $pipes[0] );
	$stdout = (string) stream_get_contents( $pipes[1] );
	$stderr = (string) stream_get_contents( $pipes[2] );
	$code   = proc_close( $process );

	if ( 0 !== $code ) {
		$failed[] = sprintf( '%s exit=%d: %s%s', $verifier_rel, $code, $stdout, $stderr );
		continue;
	}
	if ( ! is_file( $manifest ) ) {
		$failed[] = sprintf( '%s exited 0 but did not write %s', $verifier_rel, $manifest_rel );
		continue;
	}
	$regenerated[] = $manifest_rel;
}

printf( "Test-suite manifest regenerator (%s)\n", $force ? 'force all' : 'only missing' );
printf( "  regenerated: %d\n", count( $regenerated ) );
foreach ( $regenerated as $m ) {
	printf( "    + %s\n", $m );
}
printf( "  skipped (already present): %d\n", count( $skipped ) );
printf( "  failed: %d\n", count( $failed ) );
foreach ( $failed as $f ) {
	fprintf( STDERR, "    ! %s\n", $f );
}

exit( 0 === count( $failed ) ? 0 : 1 );
