<?php
/**
 * Phase 72 - Exact artifact evidence contract.
 *
 * Normal source CI validates the tracked evidence schema/template. Strict
 * release certification is enabled with SSCRIBE_RELEASE_CERTIFICATION=1 and
 * reads untracked release metadata from
 * dist/release-certification-evidence.json. Artifact identity is recomputed
 * from the current checkout and written to the generated manifest, avoiding
 * any self-referential tracked source SHA.
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
$metadata_path        = $root_dir . '/dist/release-certification-evidence.json';
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

$is_placeholder = static function ( string $value ): bool {
	$value = trim( $value );
	return '' === $value
		|| 0 === stripos( $value, 'PENDING' )
		|| 0 === stripos( $value, 'TBD' )
		|| 0 === stripos( $value, 'N/A' );
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

$template_values = array();
$current_version = '';
$current_sha     = '';
$metadata        = array();
$actual          = array();

if ( is_file( $evidence_doc ) ) {
	$doc_src = (string) file_get_contents( $evidence_doc );

	$canonical_sections = array(
		'## Why this exists',
		'## Canonical evidence fields',
		'## Recorded evidence',
		'## Strict evidence file',
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

	$record(
		'untracked_metadata_path_documented',
		false !== strpos( $doc_src, 'dist/release-certification-evidence.json' ),
		'Artifact evidence contract must document dist/release-certification-evidence.json as the strict untracked metadata input.'
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
			$template_values[ $field ] = trim( (string) $row[3] );
		}
	}

	$missing_template_fields = array();
	foreach ( $canonical_fields as $field ) {
		if ( ! array_key_exists( $field, $template_values ) ) {
			$missing_template_fields[] = $field;
		}
	}
	$record(
		'every_canonical_field_has_template_row',
		0 === count( $missing_template_fields ),
		'Every canonical evidence field must have a tracked template row. Missing: ' . implode( ', ', $missing_template_fields )
	);
}

$test_path = $root_dir . '/tests/Integration/SScribe_Exact_Artifact_Evidence_Test.php';
$record(
	'integration_test_exists',
	is_file( $test_path ),
	'tests/Integration/SScribe_Exact_Artifact_Evidence_Test.php must exist so the Phase 72 contract is pinned at the PHPUnit boundary.'
);

if ( $strict_certification ) {
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

	$status_output = array();
	$status_exit   = 1;
	exec( 'git status --porcelain --untracked-files=no', $status_output, $status_exit );
	$record(
		'tracked_working_tree_is_clean',
		0 === $status_exit && empty( $status_output ),
		'Strict certification requires a clean tracked working tree. Commit or discard tracked changes before building release evidence.'
	);

	$record(
		'strict_metadata_file_exists',
		is_file( $metadata_path ),
		'Strict certification requires untracked dist/release-certification-evidence.json.'
	);

	if ( is_file( $metadata_path ) ) {
		$decoded = json_decode( (string) file_get_contents( $metadata_path ), true );
		$record(
			'strict_metadata_file_is_valid_json',
			is_array( $decoded ),
			'dist/release-certification-evidence.json must contain a valid JSON object.'
		);
		if ( is_array( $decoded ) ) {
			$metadata = $decoded;
		}
	}

	$required_metadata_fields = array(
		'source_sha',
		'builder_run_id',
		'builder_workflow',
		'plugin_check_url',
		'clean_install_doc',
		'build_timestamp',
	);
	$missing_metadata_fields = array();
	foreach ( $required_metadata_fields as $field ) {
		$value = trim( (string) ( $metadata[ $field ] ?? '' ) );
		if ( $is_placeholder( $value ) ) {
			$missing_metadata_fields[] = $field;
		}
	}
	$record(
		'all_strict_metadata_fields_are_concrete',
		0 === count( $missing_metadata_fields ),
		'Strict certification metadata has blank/placeholder fields: ' . implode( ', ', $missing_metadata_fields )
	);

	$metadata_source_sha = strtolower( trim( (string) ( $metadata['source_sha'] ?? '' ) ) );
	$record(
		'metadata_source_sha_matches_head',
		(bool) preg_match( '/^[a-f0-9]{40}$/', $metadata_source_sha )
			&& $metadata_source_sha === $current_sha,
		'Untracked release metadata source_sha must exactly match git HEAD.'
	);

	$plugin_check_ref = trim( (string) ( $metadata['plugin_check_url'] ?? '' ) );
	$plugin_check_ok  = false;
	if ( preg_match( '#^https://#i', $plugin_check_ref ) ) {
		$plugin_check_ok = true;
	} elseif ( 0 === stripos( $plugin_check_ref, 'local:' ) ) {
		$relative_path   = ltrim( str_replace( '\\', '/', substr( $plugin_check_ref, strlen( 'local:' ) ) ), '/' );
		$absolute_path   = $root_dir . '/' . $relative_path;
		$plugin_check_ok = '' !== $relative_path && is_file( $absolute_path ) && 0 < filesize( $absolute_path );
	}
	$record(
		'plugin_check_evidence_is_verifiable',
		$plugin_check_ok,
		'plugin_check_url must be an https:// execution URL or local:<non-empty evidence path>.'
	);

	$clean_install_doc = trim( (string) ( $metadata['clean_install_doc'] ?? '' ) );
	$clean_install_rel = ltrim( str_replace( '\\', '/', $clean_install_doc ), '/' );
	$clean_install_abs = $root_dir . '/' . $clean_install_rel;
	$record(
		'clean_install_evidence_doc_exists',
		0 === strpos( $clean_install_rel, 'dist/' )
			&& is_file( $clean_install_abs )
			&& 0 < filesize( $clean_install_abs ),
		'clean_install_doc must point to a non-empty untracked evidence file under dist/.'
	);

	$timestamp = trim( (string) ( $metadata['build_timestamp'] ?? '' ) );
	$record(
		'build_timestamp_is_valid_iso8601',
		false !== strtotime( $timestamp )
			&& (bool) preg_match( '/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}(?:Z|[+\\-]\\d{2}:\\d{2})$/', $timestamp ),
		'build_timestamp must be an ISO 8601 timestamp with timezone.'
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

	$expected_relative_zip = 'dist/sscribe-export-site-pages-' . $current_version . '.zip';
	$expected_zip          = $root_dir . '/' . $expected_relative_zip;
	$expected_sidecar      = $dist_dir . '/sscribe-export-site-pages-' . $current_version . '.sha256';

	$versioned_zips = array();
	if ( is_dir( $dist_dir ) ) {
		$glob = glob( $dist_dir . '/sscribe-export-site-pages-*.zip' );
		if ( is_array( $glob ) ) {
			foreach ( $glob as $candidate ) {
				if ( is_file( $candidate ) ) {
					$versioned_zips[] = realpath( $candidate ) ?: $candidate;
				}
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

	$actual_sha   = '';
	$actual_size  = null;
	$actual_count = null;

	if ( is_file( $expected_zip ) ) {
		$hash_result = hash_file( 'sha256', $expected_zip );
		$size_result = filesize( $expected_zip );
		$actual_sha  = is_string( $hash_result ) ? strtolower( $hash_result ) : '';
		$actual_size = false === $size_result ? null : (int) $size_result;

		$record(
			'ziparchive_extension_available',
			class_exists( 'ZipArchive' ),
			'Strict certification requires the PHP ZipArchive extension to count archive entries.'
		);
		if ( class_exists( 'ZipArchive' ) ) {
			$zip = new ZipArchive();
			if ( true === $zip->open( $expected_zip ) ) {
				$actual_count = $zip->numFiles;
				$zip->close();
			}
		}
		$record(
			'zip_entry_count_resolved',
			is_int( $actual_count ),
			'Strict certification must open the exact ZIP and resolve ZipArchive::numFiles.'
		);

		$sidecar_value = is_file( $expected_sidecar )
			? strtolower( trim( (string) file_get_contents( $expected_sidecar ) ) )
			: '';
		$record(
			'sha256_sidecar_matches_actual_zip',
			(bool) preg_match( '/^[a-f0-9]{64}$/', $actual_sha )
				&& (bool) preg_match( '/^[a-f0-9]{64}$/', $sidecar_value )
				&& hash_equals( $actual_sha, $sidecar_value ),
			'The current-version .sha256 sidecar must exist and equal a fresh SHA-256 of the exact ZIP.'
		);
	}

	$actual = array(
		'version'          => $current_version,
		'zip_filename'     => $expected_relative_zip,
		'zip_sha256'       => $actual_sha,
		'zip_byte_size'    => $actual_size,
		'zip_file_count'   => $actual_count,
		'source_sha'       => $current_sha,
		'source_short_sha' => '' !== $current_sha ? substr( $current_sha, 0, 8 ) : '',
		'builder_run_id'   => trim( (string) ( $metadata['builder_run_id'] ?? '' ) ),
		'builder_workflow' => trim( (string) ( $metadata['builder_workflow'] ?? '' ) ),
		'plugin_check_url' => $plugin_check_ref,
		'clean_install_doc'=> $clean_install_rel,
		'build_timestamp'  => $timestamp,
	);

	$record(
		'actual_zip_sha256_resolved',
		(bool) preg_match( '/^[a-f0-9]{64}$/', (string) $actual['zip_sha256'] ),
		'Strict certification must resolve a 64-hex SHA-256 for the exact ZIP.'
	);
	$record(
		'actual_zip_byte_size_resolved',
		is_int( $actual['zip_byte_size'] ) && 0 < $actual['zip_byte_size'],
		'Strict certification must resolve a positive exact ZIP byte size.'
	);
	$record(
		'actual_zip_file_count_resolved',
		is_int( $actual['zip_file_count'] ) && 0 < $actual['zip_file_count'],
		'Strict certification must resolve a positive exact ZIP entry count.'
	);
} else {
	$record(
		'release_artifact_identity_enforced_only_in_strict_certification',
		true,
		'Normal source CI validates the tracked schema/template only. Strict mode recomputes exact artifact/source identity from untracked release metadata.'
	);
}

$manifest_dir = dirname( $manifest_path );
if ( ! is_dir( $manifest_dir ) ) {
	mkdir( $manifest_dir, 0755, true );
}

$manifest = array(
	'generated_at'         => gmdate( 'c' ),
	'strict_certification' => $strict_certification,
	'metadata_input'       => $strict_certification ? 'dist/release-certification-evidence.json' : null,
	'current_version'      => $current_version,
	'current_source_sha'   => $current_sha,
	'template_values'      => $template_values,
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
	echo "PASS: Exact artifact evidence was recomputed for the current source checkout and exact ZIP.\n";
} else {
	echo "PASS: Exact artifact evidence contract structure is valid. Strict artifact comparison was not requested.\n";
}
exit( 0 );
