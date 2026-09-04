<?php
/**
 * Phase 72 - Exact artifact evidence contract.
 *
 * Normal source CI validates the evidence schema. Strict release
 * certification is enabled with SSCRIBE_RELEASE_CERTIFICATION=1 and then
 * recomputes the exact ZIP identity, checksum sidecar, source SHA, and
 * recorded evidence instead of trusting non-empty documentation fields.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

if ( 'cli' !== php_sapi_name() ) {
	exit( 'This script must be run from the command line.' );
}

$root_dir             = dirname( __DIR__ );
$evidence_doc         = $root_dir . '/docs/EXACT_ARTIFACT_EVIDENCE_v2.0.0.md';
$main_file            = $root_dir . '/sscribe-export-site-pages.php';
$manifest_path        = $root_dir . '/dist/exact-artifact-evidence-manifest.json';
$dist_dir             = $root_dir . '/dist';
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
	'evidence_doc_exists',
	is_file( $evidence_doc ),
	'docs/EXACT_ARTIFACT_EVIDENCE_v2.0.0.md must exist so exact artifact evidence is auditable.'
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

$recorded_values = array();
$current_version = '';
$current_sha     = '';
$actual          = array();

if ( is_file( $evidence_doc ) ) {
	$doc_src = (string) file_get_contents( $evidence_doc );

	$canonical_sections = array(
		'## Why this exists',
		'## Canonical evidence fields',
		'## Recorded evidence',
		'## Strict certification rules',
		'## How an independent auditor verifies this',
	);
	$missing_sections = array();
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

	$recorded_start = strpos( $doc_src, '## Recorded evidence' );
	$recorded_block = '';
	if ( false !== $recorded_start ) {
		$recorded_block = substr( $doc_src, $recorded_start );
		$next_section    = strpos( $recorded_block, "\n## ", strlen( '## Recorded evidence' ) );
		if ( false !== $next_section ) {
			$recorded_block = substr( $recorded_block, 0, $next_section );
		}
	}

	if ( preg_match_all(
		'/^\\|\\s*([0-9]+)\\s*\\|\\s*([a-z_][a-z0-9_]*)\\s*\\|\\s*(.*?)\\s*\\|\\s*$/mi',
		$recorded_block,
		$rows,
		PREG_SET_ORDER
	) ) {
		foreach ( $rows as $row ) {
			$field = trim( (string) $row[2] );
			if ( 'field' === strtolower( $field ) ) {
				continue;
			}
			$recorded_values[ $field ] = trim( (string) $row[3] );
		}
	}

	$missing_recorded_fields = array();
	foreach ( $canonical_fields as $field ) {
		if ( ! array_key_exists( $field, $recorded_values ) ) {
			$missing_recorded_fields[] = $field;
		}
	}
	$record(
		'every_canonical_field_has_recorded_row',
		0 === count( $missing_recorded_fields ),
		'Every canonical evidence field must have a Recorded evidence row. Missing: ' . implode( ', ', $missing_recorded_fields )
	);
}

$test_path = $root_dir . '/tests/Integration/SScribe_Exact_Artifact_Evidence_Test.php';
$record(
	'integration_test_exists',
	is_file( $test_path ),
	'tests/Integration/SScribe_Exact_Artifact_Evidence_Test.php must exist so the Phase 72 contract is pinned at the PHPUnit boundary.'
);

if ( $strict_certification ) {
	$placeholder_fields = array();
	foreach ( $canonical_fields as $field ) {
		$value = trim( (string) ( $recorded_values[ $field ] ?? '' ) );
		if (
			'' === $value
			|| 0 === stripos( $value, 'PENDING' )
			|| 0 === stripos( $value, 'TBD' )
			|| 0 === stripos( $value, 'N/A' )
			|| false !== stripos( $value, 'filled at certify' )
		) {
			$placeholder_fields[] = $field;
		}
	}
	$record(
		'no_placeholder_evidence_values',
		0 === count( $placeholder_fields ),
		'Strict certification requires concrete evidence values. Placeholder/blank: ' . implode( ', ', $placeholder_fields )
	);

	if ( is_file( $main_file ) ) {
		$main_src = (string) file_get_contents( $main_file );
		if ( preg_match( "/define\\(\\s*'SSCRIBE_VERSION'\\s*,\\s*'([^']+)'\\s*\\)/", $main_src, $version_match ) ) {
			$current_version = trim( (string) $version_match[1] );
		}
	}
	$record(
		'current_plugin_version_resolved',
		(bool) preg_match( '/^[0-9]+\\.[0-9]+\\.[0-9]+(?:[-+][A-Za-z0-9.\\-]+)?$/', $current_version ),
		'Strict certification must resolve SSCRIBE_VERSION from the main plugin file.'
	);
	$record(
		'recorded_version_matches_source',
		$current_version === (string) ( $recorded_values['version'] ?? '' ),
		'Recorded version must exactly match SSCRIBE_VERSION.'
	);

	$expected_relative_zip = 'dist/sscribe-export-site-pages-' . $current_version . '.zip';
	$expected_zip          = $root_dir . '/' . $expected_relative_zip;
	$expected_sidecar      = $dist_dir . '/sscribe-export-site-pages-' . $current_version . '.sha256';

	$versioned_zips = array();
	if ( is_dir( $dist_dir ) ) {
		foreach ( glob( $dist_dir . '/sscribe-export-site-pages-*.zip' ) as $candidate ) {
			if ( is_file( $candidate ) ) {
				$versioned_zips[] = realpath( $candidate ) ?: $candidate;
			}
		}
	}

	$record(
		'exact_current_version_zip_exists',
		is_file( $expected_zip ),
		'Strict certification requires the exact current-version ZIP: ' . $expected_relative_zip
	);
	$record(
		'only_one_versioned_release_zip_present',
		1 === count( $versioned_zips ) && is_file( $expected_zip ),
		'dist/ must contain exactly one versioned SScribe release ZIP during final certification.'
	);
	$record(
		'recorded_zip_filename_matches_expected',
		$expected_relative_zip === (string) ( $recorded_values['zip_filename'] ?? '' ),
		'Recorded zip_filename must equal the exact current-version artifact path.'
	);

	if ( is_file( $expected_zip ) ) {
		$actual_sha  = hash_file( 'sha256', $expected_zip );
		$actual_size = filesize( $expected_zip );
		$actual_count = null;

		if ( class_exists( 'ZipArchive' ) ) {
			$zip = new ZipArchive();
			if ( true === $zip->open( $expected_zip ) ) {
				$actual_count = $zip->numFiles;
				$zip->close();
			}
		}

		$actual['zip_sha256']     = is_string( $actual_sha ) ? $actual_sha : '';
		$actual['zip_byte_size']  = false === $actual_size ? null : (int) $actual_size;
		$actual['zip_file_count'] = $actual_count;

		$record(
			'recorded_zip_sha256_matches_actual',
			is_string( $actual_sha )
				&& preg_match( '/^[a-f0-9]{64}$/', (string) ( $recorded_values['zip_sha256'] ?? '' ) )
				&& hash_equals( $actual_sha, strtolower( (string) $recorded_values['zip_sha256'] ) ),
			'Recorded zip_sha256 must equal a fresh SHA-256 of the exact ZIP.'
		);
		$record(
			'recorded_zip_byte_size_matches_actual',
			false !== $actual_size
				&& ctype_digit( (string) ( $recorded_values['zip_byte_size'] ?? '' ) )
				&& (int) $recorded_values['zip_byte_size'] === (int) $actual_size,
			'Recorded zip_byte_size must equal filesize() for the exact ZIP.'
		);
		$record(
			'recorded_zip_file_count_matches_actual',
			is_int( $actual_count )
				&& ctype_digit( (string) ( $recorded_values['zip_file_count'] ?? '' ) )
				&& (int) $recorded_values['zip_file_count'] === $actual_count,
			'Recorded zip_file_count must equal ZipArchive::numFiles for the exact ZIP.'
		);

		$sidecar_value = is_file( $expected_sidecar )
			? strtolower( trim( (string) file_get_contents( $expected_sidecar ) ) )
			: '';
		$record(
			'sha256_sidecar_matches_actual_zip',
			is_string( $actual_sha )
				&& preg_match( '/^[a-f0-9]{64}$/', $sidecar_value )
				&& hash_equals( $actual_sha, $sidecar_value ),
			'The current-version .sha256 sidecar must exist and equal the actual ZIP digest.'
		);
	}

	$git_output = array();
	$git_exit   = 1;
	exec( 'git rev-parse HEAD', $git_output, $git_exit );
	if ( 0 === $git_exit && ! empty( $git_output ) ) {
		$current_sha = strtolower( trim( (string) end( $git_output ) ) );
	}
	$record(
		'current_source_sha_resolved',
		(bool) preg_match( '/^[a-f0-9]{40}$/', $current_sha ),
		'Strict certification must resolve the current 40-hex git HEAD.'
	);
	$record(
		'recorded_source_sha_matches_head',
		(bool) preg_match( '/^[a-f0-9]{40}$/i', (string) ( $recorded_values['source_sha'] ?? '' ) )
			&& strtolower( (string) $recorded_values['source_sha'] ) === $current_sha,
		'Recorded source_sha must exactly match git HEAD.'
	);
	$record(
		'recorded_short_sha_matches_head',
		'' !== $current_sha
			&& strtolower( (string) ( $recorded_values['source_short_sha'] ?? '' ) ) === substr( $current_sha, 0, 8 ),
		'Recorded source_short_sha must equal the first eight characters of git HEAD.'
	);

	$clean_install_doc = (string) ( $recorded_values['clean_install_doc'] ?? '' );
	$clean_install_abs = $root_dir . '/' . ltrim( str_replace( '\\\\', '/', $clean_install_doc ), '/' );
	$record(
		'clean_install_evidence_doc_exists',
		'' !== $clean_install_doc && is_file( $clean_install_abs ),
		'Recorded clean_install_doc must point to an existing repository file.'
	);

	$timestamp = (string) ( $recorded_values['build_timestamp'] ?? '' );
	$parsed_ts = strtotime( $timestamp );
	$record(
		'build_timestamp_is_valid_iso8601',
		false !== $parsed_ts && (bool) preg_match( '/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}(?:Z|[+\\-]\\d{2}:\\d{2})$/', $timestamp ),
		'Recorded build_timestamp must be an ISO 8601 timestamp with timezone.'
	);
} else {
	$record(
		'release_artifact_identity_enforced_only_in_strict_certification',
		true,
		'Normal source CI validates artifact-evidence schema only. Strict mode recomputes ZIP/source identity and rejects stale evidence.'
	);
}

$manifest_dir = dirname( $manifest_path );
if ( ! is_dir( $manifest_dir ) ) {
	mkdir( $manifest_dir, 0755, true );
}

$manifest = array(
	'generated_at'         => gmdate( 'c' ),
	'strict_certification' => $strict_certification,
	'current_version'      => $current_version,
	'current_source_sha'   => $current_sha,
	'recorded_values'      => $recorded_values,
	'actual'               => $actual,
	'rule_count'           => count( $matrix ),
	'passed_count'         => count( array_filter( $matrix, static fn( $row ) => $row['passes'] ) ),
	'errors_count'         => count( $errors ),
	'release_ready'        => $strict_certification && 0 === count( $errors ),
	'passes'               => 0 === count( $errors ),
	'errors'               => $errors,
	'matrix'               => $matrix,
);

file_put_contents(
	$manifest_path,
	json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
);

echo "=== SScribe Exact Artifact Evidence Acceptance ===\n\n";
foreach ( $matrix as $row ) {
	$status = $row['passes'] ? 'PASS' : 'FAIL';
	echo sprintf( "  %s  %s\n      %s\n", $status, $row['rule'], $row['detail'] );
}

echo "\nErrors: " . count( $errors ) . "\n";
foreach ( $errors as $error ) {
	echo "  FAIL: {$error}\n";
}
echo "\nManifest persisted to: {$manifest_path}\n";

if ( ! empty( $errors ) ) {
	exit( 1 );
}

if ( $strict_certification ) {
	echo "PASS: Exact artifact evidence matches the current ZIP and source checkout.\n";
} else {
	echo "PASS: Exact artifact evidence contract structure is valid. Strict artifact comparison was not requested.\n";
}
exit( 0 );
