<?php
/**
 * Phase 76 — Final Definition of Done contract.
 *
 * Asserts the canonical Definition of Done checklist
 * (docs/DEFINITION_OF_DONE_v2.0.0.md) is complete and declares
 * every criterion + the enforcing Phase gate.
 *
 * Rules:
 *
 *   1. DoD doc exists.
 *   2. DoD doc declares canonical sections (Why this exists,
 *      Canonical Definition of Done, How an independent
 *      auditor verifies this).
 *   3. Every canonical DoD criterion is listed.
 *   4. Integration test exists.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

if ( 'cli' !== php_sapi_name() ) {
	exit( 'This script must be run from the command line.' );
}

$root_dir      = dirname( __DIR__ );
$dod_doc       = $root_dir . '/docs/DEFINITION_OF_DONE_v2.0.0.md';
$manifest_path = $root_dir . '/dist/definition-of-done-manifest.json';

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
	'dod_doc_exists',
	is_file( $dod_doc ),
	'docs/DEFINITION_OF_DONE_v2.0.0.md must exist so the Phase 76 Definition of Done is auditable.'
);

// Each criterion is identified by a unique fingerprint phrase
// that survives future wording tweaks.
$canonical_criteria = array(
	'All Phases 16-77 are complete',
	'SSCRIBE_VERSION equals mainfile',
	'Every required execution signal is SUCCESS or documented LOCAL_PASS',
	'Every Phase 70 blocker is RESOLVED',
	'dist/sscribe-export-site-pages-{VERSION}.zip exists',
	'Plugin Check on the exact ZIP is PASS',
	'Real-WordPress matrix is green',
	'Coverage thresholds met',
	'Composer security audit is clean',
	'NPM audit is clean',
	'AI-artifact scan finds zero',
	'Branch protection rules documented',
	'Tag policy contract holds',
	'Release pipeline contract holds',
	'Acceptance matrix',
	'Debug log redaction contract holds',
	'No-internal-details contract holds',
	'CI command docs contract holds',
	'Build-after-test vs test-after-build',
	'Exact-package clean install holds',
	'Plugin Check warning triage',
	'JS error-free contract holds',
	'AJAX network trace covers',
	'UI refactor discipline holds',
	'Phase 68 test coverage holds',
	'Manual runtime tests runbook covers',
	'Release blockers checklist complete',
	'Final execution state holds',
	'Strict exact-artifact evidence matches the actual ZIP',
	'Agent final report produced',
	'Auditor handoff protocol holds (14 artifacts listed',
	'Release invariants declared',
	'Branch topology policy holds',
	'Tag is cut on origin/main HEAD',
	'WP.org submission is made',
	'Public maintained exact source/build inputs are available',
);

if ( is_file( $dod_doc ) ) {
	$doc_src = (string) file_get_contents( $dod_doc );

	$canonical_sections = array(
		'## Why this exists',
		'## Canonical Definition of Done',
		'## How an independent auditor verifies this',
	);
	$missing_sections   = array();
	foreach ( $canonical_sections as $section ) {
		if ( false === strpos( $doc_src, $section ) ) {
			$missing_sections[] = $section;
		}
	}
	$record(
		'dod_doc_has_canonical_sections',
		0 === count( $missing_sections ),
		'Definition of Done doc is missing canonical sections: ' . implode( ', ', $missing_sections )
	);

	// Every canonical criterion must appear (case-insensitive substring
	// match, with markdown backticks stripped for robustness).
	$haystack = str_replace( '`', '', $doc_src );
	$missing_criteria = array();
	foreach ( $canonical_criteria as $expected ) {
		$needle = str_replace( '`', '', $expected );
		if ( false === stripos( $haystack, $needle ) ) {
			$missing_criteria[] = $expected;
		}
	}
	$criterion_rows = array();
	preg_match_all( '/^\\|\\s*([0-9]+)\\s*\\|/m', $doc_src, $criterion_rows );
	$criterion_numbers = array_map( 'intval', $criterion_rows[1] ?? array() );
	$record(
		'dod_has_exactly_36_numbered_criteria',
		range( 1, 36 ) === $criterion_numbers,
		'Definition of Done must contain exactly 36 consecutively numbered criteria.'
	);

	$record(
		'every_canonical_dod_criterion_listed',
		0 === count( $missing_criteria ),
		'Every canonical DoD criterion must appear in the doc. Missing: ' . implode( ', ', $missing_criteria )
	);
}

$test_path = $root_dir . '/tests/Integration/SScribe_Definition_Of_Done_Test.php';
$record(
	'integration_test_exists',
	is_file( $test_path ),
	'tests/Integration/SScribe_Definition_Of_Done_Test.php must exist so the Definition-of-Done contract is pinned at the PHPUnit boundary.'
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

echo "=== SScribe Definition of Done Acceptance ===\n\n";
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
echo "✓ Definition of Done contract valid.\n";
exit( 0 );
