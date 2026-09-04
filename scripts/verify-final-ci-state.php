<?php
/**
 * Phase 71 - Final execution-evidence contract.
 *
 * Normal source CI validates the tracked evidence schema/template. Strict
 * release certification is enabled with SSCRIBE_RELEASE_CERTIFICATION=1 and
 * reads untracked execution proof from dist/final-execution-evidence.json.
 * This avoids a self-referential commit SHA while still binding every release
 * signal to the exact current git HEAD.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

if ( 'cli' !== php_sapi_name() ) {
	exit( 'This script must be run from the command line.' );
}

$root_dir             = dirname( __DIR__ );
$checklist_doc        = $root_dir . '/docs/FINAL_CI_STATE_v2.0.0.md';
$evidence_path        = $root_dir . '/dist/final-execution-evidence.json';
$manifest_path        = $root_dir . '/dist/final-ci-state-manifest.json';
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
	'checklist_doc_exists',
	is_file( $checklist_doc ),
	'docs/FINAL_CI_STATE_v2.0.0.md must exist so final execution state is auditable.'
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
	'e2e',
);
$known_statuses     = array( 'SUCCESS', 'LOCAL_PASS', 'UNAVAILABLE', 'FAILED', 'CANCELLED', 'SKIPPED', 'MISSING' );
$shippable_statuses = array( 'SUCCESS', 'LOCAL_PASS' );
$row_statuses       = array();
$current_source_sha = '';
$evidence_payload   = array();

if ( is_file( $checklist_doc ) ) {
	$doc_src = (string) file_get_contents( $checklist_doc );

	$canonical_sections = array(
		'## Why this exists',
		'## Status convention',
		'## Canonical required jobs',
		'## Recorded final state',
		'## Strict evidence file',
		'## How an independent auditor verifies this',
	);
	$missing_sections = array();
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

	$missing_policy_jobs = array();
	foreach ( $canonical_required_jobs as $expected ) {
		if ( false === stripos( $doc_src, $expected ) ) {
			$missing_policy_jobs[] = $expected;
		}
	}
	$record(
		'every_canonical_required_job_listed',
		0 === count( $missing_policy_jobs ),
		'Every canonical required job must appear in the policy/template. Missing: ' . implode( ', ', $missing_policy_jobs )
	);

	$record(
		'untracked_evidence_path_documented',
		false !== strpos( $doc_src, 'dist/final-execution-evidence.json' ),
		'Final CI state contract must document dist/final-execution-evidence.json as the strict untracked evidence input.'
	);

	$recorded_start = strpos( $doc_src, '## Recorded final state' );
	$recorded_block = '';
	if ( false !== $recorded_start ) {
		$recorded_block = substr( $doc_src, $recorded_start );
		$next_section    = strpos( $recorded_block, "\n## ", strlen( '## Recorded final state' ) );
		if ( false !== $next_section ) {
			$recorded_block = substr( $recorded_block, 0, $next_section );
		}
	}

	if ( preg_match_all(
		'/^\\|\\s*([0-9]+)\\s*\\|\\s*([^|]+?)\\s*\\|\\s*([A-Z_]+)\\s*\\|\\s*(.*?)\\s*\\|\\s*$/m',
		$recorded_block,
		$hits,
		PREG_SET_ORDER
	) ) {
		foreach ( $hits as $row ) {
			$job = trim( str_replace( chr( 96 ), '', (string) $row[2] ) );
			if ( 'Job' === $job ) {
				continue;
			}
			$row_statuses[ $job ] = array(
				'status'   => trim( (string) $row[3] ),
				'evidence' => trim( (string) $row[4] ),
			);
		}
	}

	$missing_template_jobs = array();
	foreach ( $canonical_required_jobs as $expected ) {
		if ( ! isset( $row_statuses[ $expected ] ) ) {
			$missing_template_jobs[] = $expected;
		}
	}
	$record(
		'every_canonical_job_has_template_row',
		0 === count( $missing_template_jobs ),
		'Every canonical required job must have a tracked template row. Missing: ' . implode( ', ', $missing_template_jobs )
	);

	$unknown_template_statuses = array();
	foreach ( $row_statuses as $job => $row ) {
		if ( ! in_array( $row['status'], $known_statuses, true ) ) {
			$unknown_template_statuses[] = $job . ' (' . $row['status'] . ')';
		}
	}
	$record(
		'template_status_vocabulary_is_known',
		0 === count( $unknown_template_statuses ),
		'Tracked template rows contain unknown statuses: ' . implode( ', ', $unknown_template_statuses )
	);
}

if ( $strict_certification ) {
	$git_output = array();
	$git_exit   = 1;
	exec( 'git rev-parse HEAD', $git_output, $git_exit );
	if ( 0 === $git_exit && ! empty( $git_output ) ) {
		$current_source_sha = strtolower( trim( (string) end( $git_output ) ) );
	}
	$record(
		'current_source_sha_resolved',
		(bool) preg_match( '/^[a-f0-9]{40}$/', $current_source_sha ),
		'Strict certification must resolve the current 40-hex git HEAD.'
	);

	$status_output = array();
	$status_exit   = 1;
	exec( 'git status --porcelain --untracked-files=all', $status_output, $status_exit );
	$record(
		'tracked_working_tree_is_clean',
		0 === $status_exit && empty( $status_output ),
		'Strict certification requires a clean working tree (including non-ignored untracked files). Commit or discard tracked changes before generating evidence.'
	);

	$record(
		'strict_evidence_file_exists',
		is_file( $evidence_path ),
		'Strict certification requires untracked dist/final-execution-evidence.json.'
	);

	if ( is_file( $evidence_path ) ) {
		$decoded = json_decode( (string) file_get_contents( $evidence_path ), true );
		$record(
			'strict_evidence_file_is_valid_json',
			is_array( $decoded ),
			'dist/final-execution-evidence.json must contain a valid JSON object.'
		);
		if ( is_array( $decoded ) ) {
			$evidence_payload = $decoded;
		}
	}

	$recorded_source_sha = strtolower( trim( (string) ( $evidence_payload['source_sha'] ?? '' ) ) );
	$record(
		'evidence_source_sha_matches_head',
		(bool) preg_match( '/^[a-f0-9]{40}$/', $recorded_source_sha )
			&& $recorded_source_sha === $current_source_sha,
		'Untracked execution evidence source_sha must exactly match git HEAD.'
	);

	$generated_at = trim( (string) ( $evidence_payload['generated_at'] ?? '' ) );
	$record(
		'evidence_generated_at_is_iso8601',
		false !== strtotime( $generated_at )
			&& (bool) preg_match( '/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}(?:Z|[+\\-]\\d{2}:\\d{2})$/', $generated_at ),
		'Untracked execution evidence generated_at must be an ISO 8601 timestamp with timezone.'
	);

	$signals = $evidence_payload['signals'] ?? array();
	$record(
		'evidence_signals_object_exists',
		is_array( $signals ),
		'Untracked execution evidence must contain a signals object.'
	);

	$strict_rows            = array();
	$missing_jobs           = array();
	$non_shippable          = array();
	$missing_evidence       = array();
	$invalid_evidence_refs  = array();

	foreach ( $canonical_required_jobs as $job ) {
		$row = is_array( $signals ) && isset( $signals[ $job ] ) && is_array( $signals[ $job ] )
			? $signals[ $job ]
			: null;

		if ( null === $row ) {
			$missing_jobs[] = $job;
			$strict_rows[ $job ] = array( 'status' => 'MISSING', 'evidence' => '' );
			continue;
		}

		$status   = strtoupper( trim( (string) ( $row['status'] ?? '' ) ) );
		$evidence = trim( (string) ( $row['evidence'] ?? '' ) );
		$strict_rows[ $job ] = array(
			'status'   => $status,
			'evidence' => $evidence,
		);

		if ( ! in_array( $status, $shippable_statuses, true ) ) {
			$non_shippable[] = $job . ' (' . ( '' === $status ? 'MISSING' : $status ) . ')';
		}
		if ( $is_placeholder( $evidence ) ) {
			$missing_evidence[] = $job;
			continue;
		}

		if ( 'LOCAL_PASS' === $status ) {
			if ( 0 !== stripos( $evidence, 'local:' ) ) {
				$invalid_evidence_refs[] = $job . ' (LOCAL_PASS must use local:<path>)';
				continue;
			}
			$relative_path = ltrim( str_replace( '\\', '/', substr( $evidence, strlen( 'local:' ) ) ), '/' );
			$absolute_path = $root_dir . '/' . $relative_path;
			if ( '' === $relative_path || ! is_file( $absolute_path ) || 0 === filesize( $absolute_path ) ) {
				$invalid_evidence_refs[] = $job . ' (' . $evidence . ')';
			}
		} elseif ( 'SUCCESS' === $status && ! preg_match( '#^https://#i', $evidence ) ) {
			$invalid_evidence_refs[] = $job . ' (SUCCESS must reference an https:// execution URL)';
		}
	}

	$row_statuses = $strict_rows;

	$record(
		'every_required_job_present_in_evidence',
		0 === count( $missing_jobs ),
		'Strict execution evidence is missing required jobs: ' . implode( ', ', $missing_jobs )
	);
	$record(
		'every_required_job_has_shippable_recorded_status',
		0 === count( $non_shippable ),
		'Strict release certification requires SUCCESS or LOCAL_PASS for every required job. Non-shippable: ' . implode( ', ', $non_shippable )
	);
	$record(
		'every_required_job_has_concrete_evidence',
		0 === count( $missing_evidence ),
		'Every strict execution row must name concrete evidence. Missing: ' . implode( ', ', $missing_evidence )
	);
	$record(
		'every_evidence_reference_is_verifiable',
		0 === count( $invalid_evidence_refs ),
		'Strict evidence references must be verifiable. Invalid: ' . implode( ', ', $invalid_evidence_refs )
	);
} else {
	$record(
		'release_state_enforced_only_in_strict_certification',
		true,
		'Normal source CI validates the tracked schema/template only. Strict mode reads untracked exact-SHA execution evidence.'
	);
}

$test_path = $root_dir . '/tests/Integration/SScribe_Final_CI_State_Test.php';
$record(
	'integration_test_exists',
	is_file( $test_path ),
	'tests/Integration/SScribe_Final_CI_State_Test.php must exist so the Phase 71 contract is pinned at the PHPUnit boundary.'
);

$manifest_dir = dirname( $manifest_path );
if ( ! is_dir( $manifest_dir ) ) {
	mkdir( $manifest_dir, 0755, true );
}

$manifest = array(
	'generated_at'         => gmdate( 'c' ),
	'strict_certification' => $strict_certification,
	'evidence_input'       => $strict_certification ? 'dist/final-execution-evidence.json' : null,
	'current_source_sha'   => $current_source_sha,
	'row_statuses'         => $row_statuses,
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

echo "=== SScribe Final CI State Acceptance ===\n\n";
foreach ( $matrix as $row ) {
	$status = $row['passes'] ? 'PASS' : 'FAIL';
	echo sprintf( "  %s  %s\n      %s\n", $status, $row['rule'], $row['detail'] );
}

echo "\nExecution state summary:\n";
foreach ( $canonical_required_jobs as $job ) {
	$row = $row_statuses[ $job ] ?? array( 'status' => 'MISSING', 'evidence' => '' );
	echo $job . ': ' . $row['status'] . ' - ' . $row['evidence'] . "\n";
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
	echo "PASS: Final execution evidence is release-ready for the exact current SHA.\n";
} else {
	echo "PASS: Final CI state contract structure is valid. Strict release-state enforcement was not requested.\n";
}
exit( 0 );
