<?php
/**
 * Phase 75 — Release invariants contract.
 *
 * Asserts the canonical release-invariants checklist
 * (docs/RELEASE_INVARIANTS_v2.0.0.md) is complete and declares
 * every invariant + the enforcing Phase gate.
 *
 * Rules:
 *
 *   1. Invariants doc exists.
 *   2. Invariants doc declares canonical sections (Why this
 *      exists, Canonical invariants, How an independent
 *      auditor verifies this).
 *   3. Every canonical invariant is listed.
 *   4. Integration test exists.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

if ( 'cli' !== php_sapi_name() ) {
	exit( 'This script must be run from the command line.' );
}

$root_dir      = dirname( __DIR__ );
$invariants_doc = $root_dir . '/docs/RELEASE_INVARIANTS_v2.0.0.md';
$manifest_path = $root_dir . '/dist/release-invariants-manifest.json';

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
	'invariants_doc_exists',
	is_file( $invariants_doc ),
	'docs/RELEASE_INVARIANTS_v2.0.0.md must exist so the Phase 75 release invariants are auditable.'
);

// Each invariant is identified by a unique fingerprint phrase
// that appears in the doc. The fingerprint is a distinctive
// substring that survives future wording tweaks (e.g. "SSCRIBE_VERSION
// is the single source of truth").
$canonical_invariants = array(
	'SSCRIBE_VERSION is the single source of truth',
	'readme.txt Stable tag matches',
	'package.json version matches',
	'Mainfile Version: header matches',
	'ZIP SHA-256 is reproducible',
	'ZIP contains zero comments',
	'ZIP excludes every dev-only path',
	'ZIP mainfile Version: matches',
	'every shipped PHP file parses',
	'Composer security audit is clean',
	'NPM audit is clean',
	'AI-artifact scan finds zero',
	'Plugin Check on the exact ZIP is PASS',
	'Real-WordPress testbench matrix is green',
	'Coverage thresholds met',
	'Zero wp_ajax_nopriv_sscribe_*',
	'Zero eval(',
	'Composer autoload points at',
	'Translation POT declares',
	'every composer test:* script is documented',
	'every verify-*.php script has a matching',
	'SHA-pinned actions',
	'continue-on-error: true is banned',
	'All shipped PHP files use SSCRIBE prefix',
	'No raw internal details leak',
	'every shipped JS file is runtime-clean',
	'UI refactor discipline holds',
	'every required new test',
	'Manual runtime tests runbook covers',
	'every Phase 70 blocker is RESOLVED',
	'every Phase 71 required execution signal is SUCCESS or documented LOCAL_PASS',
	'Phase 72 evidence matches the actual ZIP',
	'Public maintained exact source/build inputs',
);

if ( is_file( $invariants_doc ) ) {
	$doc_src = (string) file_get_contents( $invariants_doc );

	$canonical_sections = array(
		'## Why this exists',
		'## Canonical invariants',
		'## How an independent auditor verifies this',
	);
	$missing_sections   = array();
	foreach ( $canonical_sections as $section ) {
		if ( false === strpos( $doc_src, $section ) ) {
			$missing_sections[] = $section;
		}
	}
	$record(
		'invariants_doc_has_canonical_sections',
		0 === count( $missing_sections ),
		'Release invariants doc is missing canonical sections: ' . implode( ', ', $missing_sections )
	);

	// Every canonical invariant must appear (case-insensitive substring match).
	// Strip markdown backticks around identifiers so the
	// fingerprint survives the doc's ``code`` formatting.
	$haystack = str_replace( '`', '', $doc_src );
	$missing_invariants = array();
	foreach ( $canonical_invariants as $expected ) {
		$needle = str_replace( '`', '', $expected );
		if ( false === stripos( $haystack, $needle ) ) {
			$missing_invariants[] = $expected;
		}
	}
	$record(
		'every_canonical_invariant_listed',
		0 === count( $missing_invariants ),
		'Every canonical invariant must appear in the doc. Missing: ' . implode( ', ', $missing_invariants )
	);
}

$test_path = $root_dir . '/tests/Integration/SScribe_Release_Invariants_Test.php';
$record(
	'integration_test_exists',
	is_file( $test_path ),
	'tests/Integration/SScribe_Release_Invariants_Test.php must exist so the release-invariants contract is pinned at the PHPUnit boundary.'
);

$determinism_script = $root_dir . '/scripts/verify-build-determinism.php';
$normalizer_script  = $root_dir . '/scripts/normalize-prefixed-autoloader.php';
$composer_path       = $root_dir . '/composer.json';
$release_audit_yml   = $root_dir . '/.github/workflows/release-audit.yml';
$composer_src        = is_file( $composer_path ) ? (string) file_get_contents( $composer_path ) : '';
$release_audit_src   = is_file( $release_audit_yml ) ? (string) file_get_contents( $release_audit_yml ) : '';
$record(
	'clean_build_determinism_gate_wired',
	is_file( $determinism_script )
		&& is_file( $normalizer_script )
		&& false !== strpos( $composer_src, '"release:determinism": "php scripts/verify-build-determinism.php"' )
		&& false !== strpos( $composer_src, 'scripts/run-strauss.php && php scripts/normalize-prefixed-autoloader.php' )
		&& false !== strpos( $release_audit_src, 'composer release:determinism' ),
	'Release invariant #5 must be enforced by clean-build determinism plus deterministic Strauss generated-autoloader normalization.'
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

echo "=== SScribe Release Invariants Acceptance ===\n\n";
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
echo "✓ Release invariants contract valid.\n";
exit( 0 );
