<?php
/**
 * Phase 74 — Required handoff to independent auditor contract.
 *
 * Asserts the canonical auditor-handoff protocol
 * (docs/AUDITOR_HANDOFF_v2.0.0.md) is complete and declares
 * every canonical handoff artifact + verification recipe.
 *
 * Rules:
 *
 *   1. Handoff protocol doc exists.
 *   2. Handoff doc declares canonical sections (Why this exists,
 *      Canonical handoff artifacts, Verification recipe per
 *      artifact, How an independent auditor verifies this).
 *   3. Every canonical handoff artifact is listed.
 *   4. Integration test exists.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

if ( 'cli' !== php_sapi_name() ) {
	exit( 'This script must be run from the command line.' );
}

$root_dir      = dirname( __DIR__ );
$handoff_doc   = $root_dir . '/docs/AUDITOR_HANDOFF_v2.0.0.md';
$manifest_path = $root_dir . '/dist/auditor-handoff-manifest.json';

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
	'handoff_doc_exists',
	is_file( $handoff_doc ),
	'docs/AUDITOR_HANDOFF_v2.0.0.md must exist so the Phase 74 auditor handoff protocol is auditable.'
);

$canonical_handoff_artifacts = array(
	'docs/RELEASE_BLOCKERS_v2.0.0.md',
	'docs/FINAL_CI_STATE_v2.0.0.md',
	'docs/EXACT_ARTIFACT_EVIDENCE_v2.0.0.md',
	'docs/RELEASE_REPORT_v2.0.0.md',
	'docs/BRANCH_PROTECTION_v2.0.0.md',
	'docs/TAG_POLICY_v2.0.0.md',
	'docs/RELEASE_PIPELINE_v2.0.0.md',
	'docs/ACCEPTANCE_MATRIX_v2.0.0.json',
	'dist/acceptance-matrix-manifest.json',
	'docs/BUILD_TRANSFORMATIONS.md',
	'docs/SECURITY_MATRIX_v2.0.0.md',
	'docs/PLUGIN_CHECK_WARNINGS_v2.0.0.md',
	'docs/MANUAL_RUNTIME_TESTS_v2.0.0.md',
);

if ( is_file( $handoff_doc ) ) {
	$doc_src = (string) file_get_contents( $handoff_doc );

	$canonical_sections = array(
		'## Why this exists',
		'## Canonical handoff artifacts',
		'## Verification recipe per artifact',
		'## How an independent auditor verifies this',
	);
	$missing_sections   = array();
	foreach ( $canonical_sections as $section ) {
		if ( false === strpos( $doc_src, $section ) ) {
			$missing_sections[] = $section;
		}
	}
	$record(
		'handoff_doc_has_canonical_sections',
		0 === count( $missing_sections ),
		'Auditor handoff doc is missing canonical sections: ' . implode( ', ', $missing_sections )
	);

	// Every canonical handoff artifact must appear (case-insensitive substring match).
	$missing_artifacts = array();
	foreach ( $canonical_handoff_artifacts as $expected ) {
		if ( false === stripos( $doc_src, $expected ) ) {
			$missing_artifacts[] = $expected;
		}
	}
	$record(
		'every_canonical_handoff_artifact_listed',
		0 === count( $missing_artifacts ),
		'Every canonical handoff artifact must appear in the handoff doc. Missing: ' . implode( ', ', $missing_artifacts )
	);
}

$test_path = $root_dir . '/tests/Integration/SScribe_Auditor_Handoff_Test.php';
$record(
	'integration_test_exists',
	is_file( $test_path ),
	'tests/Integration/SScribe_Auditor_Handoff_Test.php must exist so the auditor-handoff contract is pinned at the PHPUnit boundary.'
);

// Persist manifest.
$manifest_dir = dirname( $manifest_path );
if ( ! is_dir( $manifest_dir ) ) {
	mkdir( $manifest_dir, 0755, true );
}
$manifest = array(
	'generated_at'  => gmdate( 'c' ),
	'rule_count'    => count( $matrix ),
	'passed_count'  => count( array_filter( $matrix, static fn( $r ) => $r['passes'] ) ),
	'errors_count'  => count( $errors ),
	'passes'        => 0 === count( $errors ),
	'errors'        => $errors,
	'matrix'        => $matrix,
);
file_put_contents(
	$manifest_path,
	json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
);

echo "=== SScribe Auditor Handoff Acceptance ===\n\n";
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
echo "✓ Auditor handoff protocol contract valid.\n";
exit( 0 );
