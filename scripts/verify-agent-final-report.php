<?php
/**
 * Phase 73 — Required agent final report format contract.
 *
 * Asserts the canonical agent-final-report format spec
 * (docs/AGENT_FINAL_REPORT_v2.0.0.md) is complete and every
 * canonical section is declared. The release agent (or human
 * release engineer) follows this format to produce the actual
 * release report at docs/RELEASE_REPORT_v2.0.0.md.
 *
 * Rules:
 *
 *   1. Format spec doc exists.
 *   2. Format spec declares canonical sections (Why this exists,
 *      Canonical sections, Format rules, How an independent
 *      auditor verifies this).
 *   3. Every canonical report section is listed (Executive
 *      summary, Release evidence, Blocker status, CI state,
 *      Open items, Verification recipe).
 *   4. Integration test exists.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

if ( 'cli' !== php_sapi_name() ) {
	exit( 'This script must be run from the command line.' );
}

$root_dir      = dirname( __DIR__ );
$format_doc    = $root_dir . '/docs/AGENT_FINAL_REPORT_v2.0.0.md';
$manifest_path = $root_dir . '/dist/agent-final-report-manifest.json';

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
	'format_doc_exists',
	is_file( $format_doc ),
	'docs/AGENT_FINAL_REPORT_v2.0.0.md must exist so the Phase 73 final-report format is auditable.'
);

$canonical_report_sections = array(
	'## Executive summary',
	'## Release evidence',
	'## Blocker status',
	'## CI state',
	'## Open items',
	'## Verification recipe',
);

if ( is_file( $format_doc ) ) {
	$doc_src = (string) file_get_contents( $format_doc );

	$canonical_sections = array(
		'## Why this exists',
		'## Canonical sections',
		'## Format rules',
		'## How an independent auditor verifies this',
	);
	$missing_sections   = array();
	foreach ( $canonical_sections as $section ) {
		if ( false === strpos( $doc_src, $section ) ) {
			$missing_sections[] = $section;
		}
	}
	$record(
		'format_doc_has_canonical_sections',
		0 === count( $missing_sections ),
		'Agent final report format spec is missing canonical sections: ' . implode( ', ', $missing_sections )
	);

	// Every canonical report section must appear in the spec.
	$missing_report_sections = array();
	foreach ( $canonical_report_sections as $expected ) {
		if ( false === strpos( $doc_src, $expected ) ) {
			$missing_report_sections[] = $expected;
		}
	}
	$record(
		'every_canonical_report_section_listed',
		0 === count( $missing_report_sections ),
		'Every canonical report section must appear in the format spec. Missing: ' . implode( ', ', $missing_report_sections )
	);
}

$test_path = $root_dir . '/tests/Integration/SScribe_Agent_Final_Report_Test.php';
$record(
	'integration_test_exists',
	is_file( $test_path ),
	'tests/Integration/SScribe_Agent_Final_Report_Test.php must exist so the agent-final-report contract is pinned at the PHPUnit boundary.'
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

echo "=== SScribe Agent Final Report Acceptance ===\n\n";
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
echo "✓ Agent final report format contract valid.\n";
exit( 0 );
