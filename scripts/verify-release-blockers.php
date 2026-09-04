<?php
/**
 * Phase 70 — Final release blockers contract.
 *
 * Asserts the canonical release-blocker checklist
 * (docs/RELEASE_BLOCKERS_v2.0.0.md) is complete and every row is
 * in a documented state. RESOLVED is shippable; DEFERRED is an
 * explicit local/external verification requirement and therefore
 * remains a release-stop condition until converted to RESOLVED.
 *
 * Rules:
 *
 *   1. Checklist doc exists.
 *   2. Checklist declares canonical sections (Why this exists,
 *      Status convention, Canonical blockers, How an independent
 *      auditor verifies this).
 *   3. Every canonical blocker row is present.
 *   4. Every blocker has a valid status.
 *   5. No blocker remains DEFERRED / OPEN / BLOCKED for an actual release.
 *   6. Integration test exists.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

if ( 'cli' !== php_sapi_name() ) {
	exit( 'This script must be run from the command line.' );
}

$root_dir      = dirname( __DIR__ );
$checklist_doc = $root_dir . '/docs/RELEASE_BLOCKERS_v2.0.0.md';
$manifest_path = $root_dir . '/dist/release-blockers-manifest.json';
$strict_certification = '1' === (string) getenv( 'SSCRIBE_RELEASE_CERTIFICATION' );

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
	'docs/RELEASE_BLOCKERS_v2.0.0.md must exist so the Phase 70 release blockers are auditable.'
);

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
);
$valid_statuses = array( 'RESOLVED', 'DEFERRED' );

$row_statuses = array();
if ( is_file( $checklist_doc ) ) {
	$doc_src = (string) file_get_contents( $checklist_doc );

	$canonical_sections = array(
		'## Why this exists',
		'## Status convention',
		'## Canonical blockers',
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
		'Release blockers checklist is missing canonical sections: ' . implode( ', ', $missing_sections )
	);

	// Walk every markdown row in the Canonical blockers table.
	// Each row has at least 5 columns: `# | Blocker | Status | Evidence`.
	if ( preg_match_all( '/^\|\s*([0-9]+)\s*\|\s*([^|]+?)\s*\|\s*(RESOLVED|DEFERRED|OPEN|BLOCKED)\s*\|/m', $doc_src, $hits, PREG_SET_ORDER ) ) {
		foreach ( $hits as $row ) {
			$row_statuses[ (int) $row[1] ] = array(
				'blocker' => trim( $row[2] ),
				'status'  => trim( $row[3] ),
			);
		}
	}

	// Missing blockers.
	$missing_blockers = array();
	foreach ( $canonical_blockers as $expected ) {
		$found = false;
		foreach ( $row_statuses as $row ) {
			if ( 0 === strcasecmp( $expected, $row['blocker'] ) ) {
				$found = true;
				break;
			}
		}
		if ( ! $found ) {
			$missing_blockers[] = $expected;
		}
	}
	$record(
		'every_canonical_blocker_listed',
		0 === count( $missing_blockers ),
		'Every canonical blocker must appear in the checklist. Missing: ' . implode( ', ', $missing_blockers )
	);

	// Status check.
	$invalid_status = array();
	foreach ( $row_statuses as $row ) {
		if ( ! in_array( $row['status'], $valid_statuses, true ) ) {
			$invalid_status[] = $row['blocker'] . ' (' . $row['status'] . ')';
		}
	}
	$record(
		'every_blocker_is_resolved_or_deferred',
		0 === count( $invalid_status ),
		'Every blocker status must be RESOLVED or DEFERRED. Invalid: ' . implode( ', ', $invalid_status )
	);

	$deferred = array();
	foreach ( $row_statuses as $row ) {
		if ( 'DEFERRED' === $row['status'] ) {
			$deferred[] = $row['blocker'];
		}
	}
	if ( $strict_certification ) {
		$record(
			'no_deferred_blockers_for_release',
			0 === count( $deferred ),
			'Final release/tag/upload requires every blocker RESOLVED. Deferred: ' . implode( ', ', $deferred )
		);
	} else {
		$record(
			'release_state_enforced_only_in_strict_certification',
			true,
			'Normal source CI validates blocker schema/status vocabulary. SSCRIBE_RELEASE_CERTIFICATION=1 enforces that no DEFERRED blocker remains.'
		);
	}
}

$test_path = $root_dir . '/tests/Integration/SScribe_Release_Blockers_Test.php';
$record(
	'integration_test_exists',
	is_file( $test_path ),
	'tests/Integration/SScribe_Release_Blockers_Test.php must exist so the blocker contract is pinned at the PHPUnit boundary.'
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
	'deferred_count'=> isset( $deferred ) ? count( $deferred ) : 0,
	'strict_certification' => $strict_certification,
	'release_ready' => $strict_certification && 0 === count( $errors ) && ( ! isset( $deferred ) || 0 === count( $deferred ) ),
	'passes'        => 0 === count( $errors ),
	'errors'        => $errors,
	'matrix'        => $matrix,
);
file_put_contents(
	$manifest_path,
	json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
);

echo "=== SScribe Release Blockers Acceptance ===\n\n";
foreach ( $matrix as $row ) {
	$status = $row['passes'] ? '✓' : '✗';
	echo sprintf( "  %s  %s\n      %s\n", $status, $row['rule'], $row['detail'] );
}
echo "\nRow status summary:\n";
foreach ( $row_statuses as $num => $row ) {
	echo "  #{$num}  {$row['status']}  {$row['blocker']}\n";
}
echo "\nErrors: " . count( $errors ) . "\n";
foreach ( $errors as $error ) {
	echo "  ✗ {$error}\n";
}
echo "\nManifest persisted to: {$manifest_path}\n";

if ( ! empty( $errors ) ) {
	exit( 1 );
}
if ( $strict_certification ) {
	echo "✓ Release blockers checklist is fully RESOLVED and release-ready.\n";
} else {
	echo "✓ Release blockers contract structure is valid. Strict release-state enforcement was not requested.\n";
}
exit( 0 );
