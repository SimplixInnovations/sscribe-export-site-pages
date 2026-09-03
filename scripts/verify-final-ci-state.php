<?php
/**
 * Phase 71 — Required final CI state contract.
 *
 * Asserts the canonical final-CI-state checklist
 * (docs/FINAL_CI_STATE_v2.0.0.md) is complete and every required
 * job is recorded with a shippable status (SUCCESS or SKIPPED).
 *
 * Rules:
 *
 *   1. Checklist doc exists.
 *   2. Checklist declares canonical sections (Why this exists,
 *      Status convention, Canonical required jobs, How an
 *      independent auditor verifies this).
 *   3. Every canonical required job is listed.
 *   4. Every required job has a status in {SUCCESS, SKIPPED}.
 *   5. Integration test exists.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

if ( 'cli' !== php_sapi_name() ) {
	exit( 'This script must be run from the command line.' );
}

$root_dir      = dirname( __DIR__ );
$checklist_doc = $root_dir . '/docs/FINAL_CI_STATE_v2.0.0.md';
$manifest_path = $root_dir . '/dist/final-ci-state-manifest.json';

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
	'checklist_doc_exists',
	is_file( $checklist_doc ),
	'docs/FINAL_CI_STATE_v2.0.0.md must exist so the Phase 71 final CI state is auditable.'
);

$canonical_required_jobs = array(
	'version-check',
	'lint',
	'test',
	'audit',
	'frontend-quality',
	'real-wp-tests',
	'coverage',
	'plugin-check',
);
$valid_statuses = array( 'SUCCESS', 'SKIPPED' );

$row_statuses = array();
if ( is_file( $checklist_doc ) ) {
	$doc_src = (string) file_get_contents( $checklist_doc );

	$canonical_sections = array(
		'## Why this exists',
		'## Status convention',
		'## Canonical required jobs',
		'## How an independent auditor verifies this',
	);
	$missing_sections   = array();
	foreach ( $canonical_sections as $section ) {
		if ( false === strpos( $doc_src, $section ) ) {
			$missing_sections[] = $section;
		}
	}
	$record(
		'checklist_has_canonical_sections',
		0 === count( $missing_sections ),
		'Final CI state checklist is missing canonical sections: ' . implode( ', ', $missing_sections )
	);

	// Walk every markdown row in the Canonical required jobs table.
	// Each row has at least 5 columns: `# | Job | Required status | Notes`.
	if ( preg_match_all( '/^\|\s*([0-9]+)\s*\|\s*`?([A-Za-z0-9_\-]+)`?\s*\|\s*(SUCCESS|SKIPPED|FAILED|CANCELLED|MISSING)\s*\|/m', $doc_src, $hits, PREG_SET_ORDER ) ) {
		foreach ( $hits as $row ) {
			$row_statuses[ (int) $row[1] ] = array(
				'job'    => trim( $row[2] ),
				'status' => trim( $row[3] ),
			);
		}
	}

	// Missing jobs.
	$missing_jobs = array();
	foreach ( $canonical_required_jobs as $expected ) {
		$found = false;
		foreach ( $row_statuses as $row ) {
			if ( 0 === strcasecmp( $expected, $row['job'] ) ) {
				$found = true;
				break;
			}
		}
		if ( ! $found ) {
			$missing_jobs[] = $expected;
		}
	}
	$record(
		'every_canonical_required_job_listed',
		0 === count( $missing_jobs ),
		'Every canonical required job must appear in the checklist. Missing: ' . implode( ', ', $missing_jobs )
	);

	// Status check.
	$invalid_status = array();
	foreach ( $row_statuses as $row ) {
		if ( ! in_array( $row['status'], $valid_statuses, true ) ) {
			$invalid_status[] = $row['job'] . ' (' . $row['status'] . ')';
		}
	}
	$record(
		'every_required_job_is_success_or_skipped',
		0 === count( $invalid_status ),
		'Every required job status must be SUCCESS or SKIPPED. Invalid: ' . implode( ', ', $invalid_status )
	);
}

$test_path = $root_dir . '/tests/Integration/SScribe_Final_CI_State_Test.php';
$record(
	'integration_test_exists',
	is_file( $test_path ),
	'tests/Integration/SScribe_Final_CI_State_Test.php must exist so the final CI state contract is pinned at the PHPUnit boundary.'
);

// Persist manifest.
$manifest_dir = dirname( $manifest_path );
if ( ! is_dir( $manifest_dir ) ) {
	mkdir( $manifest_dir, 0755, true );
}
$manifest = array(
	'generated_at'  => gmdate( 'c' ),
	'row_statuses'  => $row_statuses,
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

echo "=== SScribe Final CI State Acceptance ===\n\n";
foreach ( $matrix as $row ) {
	$status = $row['passes'] ? '✓' : '✗';
	echo sprintf( "  %s  %s\n      %s\n", $status, $row['rule'], $row['detail'] );
}
echo "\nRow status summary:\n";
foreach ( $row_statuses as $num => $row ) {
	echo "  #{$num}  {$row['status']}  {$row['job']}\n";
}
echo "\nErrors: " . count( $errors ) . "\n";
foreach ( $errors as $error ) {
	echo "  ✗ {$error}\n";
}
echo "\nManifest persisted to: {$manifest_path}\n";

if ( ! empty( $errors ) ) {
	exit( 1 );
}
echo "✓ Final CI state checklist contract valid.\n";
exit( 0 );
