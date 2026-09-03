<?php
/**
 * Phase 69 — Manual / runtime tests contract.
 *
 * Automated tests are necessary but not sufficient — every release
 * must also be exercised on the EXACT release ZIP in realistic
 * environments. This verifier asserts the runbook
 * (docs/MANUAL_RUNTIME_TESTS_v2.0.0.md) exists with canonical
 * sections + at least 6 rows covering the required environments.
 *
 * Rules:
 *
 *   1. Runbook doc exists.
 *   2. Runbook declares canonical sections (Why this exists,
 *      Canonical scenarios, Per-scenario acceptance criteria,
 *      What "exact ZIP" means, Evidence recording, How an
 *      independent auditor verifies this).
 *   3. Each of the 6 required environment rows is present
 *      (Standard WordPress, WordPress + WPML, Redis ON, Redis
 *      OFF, OpenLiteSpeed, Cloudflare / proxy).
 *   4. Integration test exists.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

if ( 'cli' !== php_sapi_name() ) {
	exit( 'This script must be run from the command line.' );
}

$root_dir      = dirname( __DIR__ );
$runbook_doc   = $root_dir . '/docs/MANUAL_RUNTIME_TESTS_v2.0.0.md';
$manifest_path = $root_dir . '/dist/manual-runtime-tests-manifest.json';

$matrix = array();
$errors = array();

$record = static function ( string $rule, bool $passes, string $detail ) use ( &$matrix, &$errors ): void {
	$matrix[] = array(
		'rule'   => $rule,
		'passes' => $passes,
		'detail' => $detail,
	);
	if ( ! $passes ) {
		$errors[] = $detail;
	}
};

$record(
	'runbook_doc_exists',
	is_file( $runbook_doc ),
	'docs/MANUAL_RUNTIME_TESTS_v2.0.0.md must exist so the Phase 69 manual runbook is auditable.'
);

if ( is_file( $runbook_doc ) ) {
	$doc_src = (string) file_get_contents( $runbook_doc );

	$canonical_sections = array(
		'## Why this exists',
		'## Canonical scenarios',
		'## Per-scenario acceptance criteria',
		'## What "exact ZIP" means',
		'## Evidence recording',
		'## How an independent auditor verifies this',
	);
	$missing_sections   = array();
	foreach ( $canonical_sections as $section ) {
		if ( false === strpos( $doc_src, $section ) ) {
			$missing_sections[] = $section;
		}
	}
	$record(
		'runbook_has_canonical_sections',
		0 === count( $missing_sections ),
		'Manual runtime test runbook is missing canonical sections: ' . implode( ', ', $missing_sections )
	);

	$required_envs = array(
		'Standard WordPress',
		'WordPress + WPML',
		'Redis object cache ON',
		'Redis object cache OFF',
		'OpenLiteSpeed',
		'Cloudflare',
	);
	$missing_envs  = array();
	foreach ( $required_envs as $env ) {
		if ( false === strpos( $doc_src, $env ) ) {
			$missing_envs[] = $env;
		}
	}
	$record(
		'runbook_covers_six_environments',
		0 === count( $missing_envs ),
		'Manual runtime test runbook must cover 6 canonical environments. Missing: ' . implode( ', ', $missing_envs )
	);
}

$test_path = $root_dir . '/tests/Integration/SScribe_Manual_Runtime_Tests_Test.php';
$record(
	'integration_test_exists',
	is_file( $test_path ),
	'tests/Integration/SScribe_Manual_Runtime_Tests_Test.php must exist so the runbook is pinned at the PHPUnit boundary.'
);

// Persist manifest.
$manifest_dir = dirname( $manifest_path );
if ( ! is_dir( $manifest_dir ) ) {
	mkdir( $manifest_dir, 0755, true );
}
$manifest = array(
	'generated_at' => gmdate( 'c' ),
	'rule_count'   => count( $matrix ),
	'passed_count' => count( array_filter( $matrix, static fn( $r ) => $r['passes'] ) ),
	'errors_count' => count( $errors ),
	'passes'       => 0 === count( $errors ),
	'errors'       => $errors,
	'matrix'       => $matrix,
);
file_put_contents(
	$manifest_path,
	json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
);

echo "=== SScribe Manual Runtime Tests Acceptance ===\n\n";
foreach ( $matrix as $row ) {
	$status = $row['passes'] ? '✓' : '✗';
	echo sprintf( "  %s  %s\n      %s\n", $status, $row['rule'], $row['detail'] );
}
echo "\nErrors: " . count( $errors ) . "\n";
foreach ( $errors as $error ) {
	echo "  ✗ {$error}\n";
}
echo "\nManifest persisted to: {$manifest_path}\n";

if ( ! empty( $errors ) ) {
	exit( 1 );
}
echo "✓ Manual runtime tests runbook contract valid.\n";
exit( 0 );
