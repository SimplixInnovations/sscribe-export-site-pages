<?php
/**
 * Phase 72 — Required exact artifact evidence contract.
 *
 * Asserts the canonical artifact-evidence checklist
 * (docs/EXACT_ARTIFACT_EVIDENCE_v2.0.0.md) is complete and every
 * required field has a recorded value (not blank). The ZIP
 * artifact + SHA-256 + byte size + file count + source SHA
 * + builder identity form the bit-level audit trail.
 *
 * Rules:
 *
 *   1. Evidence doc exists.
 *   2. Evidence doc declares canonical sections (Why this exists,
 *      Canonical evidence fields, Recorded evidence, How an
 *      independent auditor verifies this).
 *   3. Every canonical evidence field is listed.
 *   4. Every canonical evidence field has a recorded value
 *      (non-blank cell in the "Recorded evidence" table — so
 *      a doc that defines fields but never records values
 *      fails the gate).
 *   5. The dist/ ZIP artifact exists and has a SHA-256 sidecar.
 *   6. Integration test exists.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

if ( 'cli' !== php_sapi_name() ) {
	exit( 'This script must be run from the command line.' );
}

$root_dir      = dirname( __DIR__ );
$evidence_doc  = $root_dir . '/docs/EXACT_ARTIFACT_EVIDENCE_v2.0.0.md';
$manifest_path = $root_dir . '/dist/exact-artifact-evidence-manifest.json';
$dist_dir      = $root_dir . '/dist';

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
	'evidence_doc_exists',
	is_file( $evidence_doc ),
	'docs/EXACT_ARTIFACT_EVIDENCE_v2.0.0.md must exist so the Phase 72 artifact evidence is auditable.'
);

$canonical_fields = array(
	'version',
	'zip_filename',
	'zip_sha256',
	'zip_byte_size',
	'zip_file_count',
	'source_sha',
	'source_short_sha',
	'builder_run_id',
	'builder_workflow',
	'plugin_check_url',
	'clean_install_doc',
	'build_timestamp',
);

if ( is_file( $evidence_doc ) ) {
	$doc_src = (string) file_get_contents( $evidence_doc );

	$canonical_sections = array(
		'## Why this exists',
		'## Canonical evidence fields',
		'## Recorded evidence',
		'## How an independent auditor verifies this',
	);
	$missing_sections   = array();
	foreach ( $canonical_sections as $section ) {
		if ( false === strpos( $doc_src, $section ) ) {
			$missing_sections[] = $section;
		}
	}
	$record(
		'evidence_doc_has_canonical_sections',
		0 === count( $missing_sections ),
		'Artifact evidence doc is missing canonical sections: ' . implode( ', ', $missing_sections )
	);

	// Every canonical field must appear (case-insensitive substring match).
	$missing_fields = array();
	foreach ( $canonical_fields as $expected ) {
		if ( false === stripos( $doc_src, $expected ) ) {
			$missing_fields[] = $expected;
		}
	}
	$record(
		'every_canonical_evidence_field_listed',
		0 === count( $missing_fields ),
		'Every canonical evidence field must appear in the doc. Missing: ' . implode( ', ', $missing_fields )
	);

	// Rule 4 (new): every canonical field must have a NON-BLANK
	// recorded value in the "Recorded evidence" table. This stops
	// shipping an empty placeholder doc that lists field names
	// but never actually records the artifact state.
	//
	// The canonical recorded-value table format is:
	//
	//   | #  | Field       | Recorded value |
	//   |----|-------------|----------------|
	//   | 1  | version     | 2.0.0          |
	//
	// The earlier field-definition table (field name + source +
	// purpose) is NOT the recorded evidence — we explicitly look
	// for the "Recorded value" header column so we don't mistake
	// the definition-table purpose column for a recorded value.
	$blank_fields = array();
	$recorded_values = array();
	$recorded_table_found = false;
	$lines = explode( "\n", $doc_src );
	foreach ( $lines as $line ) {
		// Detect the start of the recorded evidence table.
		if ( false !== strpos( $line, 'Recorded value' ) || false !== strpos( $line, 'Recorded Value' ) ) {
			$recorded_table_found = true;
			continue;
		}
		if ( ! $recorded_table_found ) {
			continue;
		}
		// Skip the separator row (e.g. "|----|---|").
		if ( preg_match( '/^\s*\|[\s:|]+\|\s*$/', $line ) ) {
			continue;
		}
		// Skip the header row itself (it was already matched above).
		if ( preg_match( '/^\s*\|\s*#\s*\|\s*Field\s*\|/i', $line ) ) {
			continue;
		}
		// Data rows: `| 12 | build_timestamp | <value> | ...`.
		if ( preg_match( '/^\s*\|\s*\d+\s*\|\s*([a-z_][a-z0-9_]*)\s*\|\s*(.+?)\s*\|/i', $line, $m ) ) {
			$fname = trim( $m[1] );
			$fval  = trim( $m[2] );
			// Strip surrounding emphasis + drop placeholders.
			$fval_clean = trim( preg_replace( '/^_+(.+)_+$/', '$1', $fval ) );
			$recorded_values[ $fname ] = $fval_clean;
		}
	}
	foreach ( $canonical_fields as $field ) {
		$val = isset( $recorded_values[ $field ] ) ? $recorded_values[ $field ] : '';
		if ( '' === $val || '_filled at certify_' === $val || '_filled at certify' === $val ) {
			$blank_fields[] = $field;
		}
	}
	$record(
		'every_canonical_evidence_field_has_recorded_value',
		0 === count( $blank_fields ),
		'Every canonical evidence field must have a non-blank recorded value in the "Recorded evidence" table. Blank: ' . implode( ', ', $blank_fields )
	);
}

// Locate the exact artifact ZIP in dist/.
$zip_files = array();
if ( is_dir( $dist_dir ) ) {
	foreach ( glob( $dist_dir . '/sscribe-export-site-pages-*.zip' ) as $candidate ) {
		if ( is_file( $candidate ) ) {
			$zip_files[] = $candidate;
		}
	}
}
$record(
	'dist_zip_artifact_exists',
	count( $zip_files ) > 0,
	'dist/ must contain the exact release ZIP (sscribe-export-site-pages-{VERSION}.zip) for the Phase 72 gate.'
);

// Locate the SHA-256 sidecar. The build emits the sidecar as
// a sibling of the ZIP (e.g. dist/sscribe-export-site-pages-2.0.0.sha256),
// not as ZIP.sha256 — check both forms.
$sha_sidecar_present = false;
foreach ( $zip_files as $zip ) {
	$base = preg_replace( '/\.zip$/', '', $zip );
	if ( is_file( $base . '.sha256' ) || is_file( $zip . '.sha256' ) ) {
		$sha_sidecar_present = true;
		break;
	}
}
$record(
	'sha256_sidecar_present',
	$sha_sidecar_present,
	'dist/ must contain a .sha256 sidecar next to the exact release ZIP so the artifact is bit-level reproducible.'
);

$test_path = $root_dir . '/tests/Integration/SScribe_Exact_Artifact_Evidence_Test.php';
$record(
	'integration_test_exists',
	is_file( $test_path ),
	'tests/Integration/SScribe_Exact_Artifact_Evidence_Test.php must exist so the artifact evidence contract is pinned at the PHPUnit boundary.'
);

// Persist manifest.
$manifest_dir = dirname( $manifest_path );
if ( ! is_dir( $manifest_dir ) ) {
	mkdir( $manifest_dir, 0755, true );
}
$manifest = array(
	'generated_at'  => gmdate( 'c' ),
	'zip_files'     => array_map( 'basename', $zip_files ),
	'sha_sidecar'   => $sha_sidecar_present,
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

echo "=== SScribe Exact Artifact Evidence Acceptance ===\n\n";
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
echo "✓ Exact artifact evidence contract valid.\n";
exit( 0 );
