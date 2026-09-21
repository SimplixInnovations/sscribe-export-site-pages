<?php
/**
 * Phase 70 — Final release blockers contract (refactored).
 *
 * Asserts the canonical release-blocker checklist
 * (docs/RELEASE_BLOCKERS_v2.0.0.md) is complete and every row is
 * effectively RESOLVED at certification time.
 *
 * Two kinds of blockers:
 *
 *   - **Static** (closure source: `static`): the `Status` cell in
 *     the doc is the only thing the cert gate needs. The doc says
 *     `RESOLVED` → it's resolved.
 *
 *   - **Dynamic** (closure source: phase71-evidence, e2e-evidence,
 *     plugin-check-evidence, clean-install-evidence,
 *     runtime-export-evidence, source-transparency-evidence): the
 *     `Status` cell stays `DEFERRED` in the tracked registry, but
 *     the strict cert gate reads the referenced ignored evidence
 *     file under `dist/` and upgrades the row to RESOLVED at runtime
 *     when the evidence proves it.
 *
 * That separation means a certification run can close dynamic
 * blockers without re-committing the registry. No SHA circularity.
 *
 * Rules:
 *
 *   1. Checklist doc exists.
 *   2. Checklist declares canonical sections (Why this exists,
 *      Status convention, Canonical blockers, How an independent
 *      auditor verifies this).
 *   3. Every canonical blocker row is present.
 *   4. Every blocker has a valid status (RESOLVED | DEFERRED).
 *   5. Every blocker has a recognised closure-source token.
 *   6. Static rows are RESOLVED in the doc.
 *   7. Dynamic DEFERRED rows resolve from their closure-source
 *      evidence under strict certification.
 *   8. Integration test exists.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

if ( 'cli' !== php_sapi_name() ) {
	exit( 'This script must be run from the command line.' );
}

$root_dir            = dirname( __DIR__ );
$checklist_doc       = $root_dir . '/docs/RELEASE_BLOCKERS_v2.0.0.md';
$manifest_path       = $root_dir . '/dist/release-blockers-manifest.json';
$strict_certification = '1' === (string) getenv( 'SSCRIBE_RELEASE_CERTIFICATION' );

/**
 * Closure-source vocabulary.
 *
 *   `static`                 — proof is in the tracked doc.
 *   `phase71-evidence`       — dist/final-execution-evidence.json.
 *   `e2e-evidence`           — dist/final-execution-evidence.json e2e signal.
 *   `plugin-check-evidence`  — dist/release-certification-evidence.json plugin_check_url.
 *   `clean-install-evidence` — dist/clean-install-evidence.json + dist/evidence/clean-install.md.
 *   `runtime-export-evidence`— dist/runtime-export-evidence.json + dist/evidence/runtime-exports.log.
 *   `manual-runtime-evidence` — dist/manual-runtime-evidence.json + dist/evidence/manual-runtime.log.
 *   `source-transparency-evidence` — dist/source-transparency-evidence.json.
 */
$valid_closure_sources = array(
	'static',
	'phase71-evidence',
	'e2e-evidence',
	'plugin-check-evidence',
	'clean-install-evidence',
	'runtime-export-evidence',
	'manual-runtime-evidence',
	'source-transparency-evidence',
);

$matrix  = array();
$errors  = array();
$row_statuses = array();
$effective    = array(); // final resolution per row at cert time.

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
	'Manual runtime environment matrix not executed on exact ZIP',
);

$valid_statuses = array( 'RESOLVED', 'DEFERRED' );

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
	// Each row has 5 pipe-delimited columns: `| # | Blocker | Status | Closure source | Evidence |`.
	$row_pattern = '/^\|\s*([0-9]+)\s*\|\s*([^|]+?)\s*\|\s*(RESOLVED|DEFERRED|OPEN|BLOCKED)\s*\|\s*([^|]+?)\s*\|\s*([^|]*?)\s*\|\s*$/m';
	if ( preg_match_all( $row_pattern, $doc_src, $hits, PREG_SET_ORDER ) ) {
		foreach ( $hits as $row ) {
			$num = (int) $row[1];
			$row_statuses[ $num ] = array(
				'blocker'        => trim( $row[2] ),
				'status'         => trim( $row[3] ),
				'closure_source' => trim( $row[4] ),
				'evidence'       => trim( $row[5] ),
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

	// Status vocabulary.
	$invalid_status = array();
	foreach ( $row_statuses as $num => $row ) {
		if ( ! in_array( $row['status'], $valid_statuses, true ) ) {
			$invalid_status[] = "#{$num} {$row['blocker']} ({$row['status']})";
		}
	}
	$record(
		'every_blocker_is_resolved_or_deferred',
		0 === count( $invalid_status ),
		'Every blocker status must be RESOLVED or DEFERRED. Invalid: ' . implode( ', ', $invalid_status )
	);

	// Closure-source vocabulary.
	$invalid_closure = array();
	foreach ( $row_statuses as $num => $row ) {
		if ( ! in_array( $row['closure_source'], $valid_closure_sources, true ) ) {
			$invalid_closure[] = "#{$num} {$row['blocker']} ({$row['closure_source']})";
		}
	}
	$record(
		'every_blocker_has_recognised_closure_source',
		0 === count( $invalid_closure ),
		'Every blocker must declare a recognised Closure source token. Invalid: ' . implode( ', ', $invalid_closure )
	);

	// Static rows MUST be RESOLVED; otherwise the tracked code/test/doc claim is broken.
	$static_unresolved = array();
	foreach ( $row_statuses as $num => $row ) {
		if ( 'static' === $row['closure_source'] && 'RESOLVED' !== $row['status'] ) {
			$static_unresolved[] = "#{$num} {$row['blocker']} ({$row['status']})";
		}
	}
	$record(
		'static_rows_are_resolved',
		0 === count( $static_unresolved ),
		'Static blockers (closure source: static) must be RESOLVED in the doc. Violations: ' . implode( ', ', $static_unresolved )
	);

	// For each row, compute the *effective* resolution.
	$deferred = array();
	foreach ( $row_statuses as $num => $row ) {
		$effective[ $num ] = array(
			'blocker'        => $row['blocker'],
			'status'         => $row['status'],
			'closure_source' => $row['closure_source'],
			'effective'      => 'RESOLVED', // optimistic until evidence check proves otherwise
			'evidence_check' => 'not_required',
			'evidence_detail'=> '',
		);

		if ( 'static' === $row['closure_source'] ) {
			// Already RESOLVED in doc (validated above).
			continue;
		}

		// Dynamic row. In normal mode we accept DEFERRED; in strict mode
		// we consult the closure-source evidence.
		if ( ! $strict_certification ) {
			$effective[ $num ]['effective']       = 'DEFERRED';
			$effective[ $num ]['evidence_check'] = 'strict_only';
			continue;
		}

		$check = check_closure_evidence( $root_dir, $row['closure_source'] );
		$effective[ $num ]['evidence_check']  = $check['verdict'];
		$effective[ $num ]['evidence_detail'] = $check['detail'];

		if ( $check['passes'] ) {
			$effective[ $num ]['effective'] = 'RESOLVED';
		} else {
			$effective[ $num ]['effective'] = 'DEFERRED';
			$deferred[] = "#{$num} {$row['blocker']} (closure: {$row['closure_source']}; {$check['detail']})";
		}
	}

	if ( $strict_certification ) {
		$record(
			'no_deferred_blockers_for_release',
			0 === count( $deferred ),
			'Final release/tag/upload requires every blocker effectively RESOLVED. Deferred: ' . implode( ', ', $deferred )
		);
	} else {
		$record(
			'release_state_enforced_only_in_strict_certification',
			true,
			'Normal source CI validates blocker schema / status / closure-source vocabulary. SSCRIBE_RELEASE_CERTIFICATION=1 enforces that no DEFERRED blocker remains effective.'
		);
	}
}

/**
 * Verify a closure-source evidence file under dist/.
 *
 * @return array{passes: bool, verdict: string, detail: string}
 */
function check_closure_evidence( string $root_dir, string $closure_source ): array {
	switch ( $closure_source ) {
		case 'phase71-evidence':
			$path = $root_dir . '/dist/final-execution-evidence.json';
			if ( ! is_file( $path ) ) {
				return array(
					'passes'  => false,
					'verdict' => 'missing_evidence',
					'detail'  => 'dist/final-execution-evidence.json does not exist',
				);
			}
			$payload = json_decode( (string) file_get_contents( $path ), true );
			if ( ! is_array( $payload ) || ! isset( $payload['signals'] ) || ! is_array( $payload['signals'] ) ) {
				return array(
					'passes'  => false,
					'verdict' => 'malformed_evidence',
					'detail'  => 'dist/final-execution-evidence.json does not contain a `signals` object',
				);
			}
			$bad = array();
			$required = array( 'version-check', 'lint', 'test', 'audit', 'frontend-quality', 'real-wp-tests', 'coverage', 'plugin-check', 'e2e' );
			foreach ( $required as $job ) {
				$status = $payload['signals'][ $job ]['status'] ?? null;
				if ( 'SUCCESS' !== $status && 'LOCAL_PASS' !== $status ) {
					$bad[] = $job . '=' . ( is_string( $status ) ? $status : 'MISSING' );
				}
			}
			if ( $bad ) {
				return array(
					'passes'  => false,
					'verdict' => 'signals_not_all_pass',
					'detail'  => 'signals not all SUCCESS/LOCAL_PASS: ' . implode( ', ', $bad ),
				);
			}
			return array(
				'passes'  => true,
				'verdict' => 'phase71_signals_all_pass',
				'detail'  => 'all 9 Phase 71 signals SUCCESS or LOCAL_PASS',
			);

		case 'e2e-evidence':
			$path = $root_dir . '/dist/final-execution-evidence.json';
			if ( ! is_file( $path ) ) {
				return array(
					'passes'  => false,
					'verdict' => 'missing_evidence',
					'detail'  => 'dist/final-execution-evidence.json does not exist',
				);
			}
			$payload = json_decode( (string) file_get_contents( $path ), true );
			$status  = $payload['signals']['e2e']['status'] ?? null;
			if ( 'SUCCESS' !== $status && 'LOCAL_PASS' !== $status ) {
				return array(
					'passes'  => false,
					'verdict' => 'e2e_signal_not_pass',
					'detail'  => 'signals.e2e.status=' . ( is_string( $status ) ? $status : 'MISSING' ),
				);
			}
			$evidence_path = $payload['signals']['e2e']['evidence'] ?? '';
			if ( '' === $evidence_path ) {
				return array(
					'passes'  => false,
					'verdict' => 'e2e_evidence_path_empty',
					'detail'  => 'signals.e2e.evidence is empty',
				);
			}
			// Strip the `local:` prefix if present before checking on disk.
			$local_path = preg_replace( '#^local:#', '', $evidence_path );
			$local_path = $root_dir . '/' . ltrim( $local_path, '/' );
			if ( ! is_file( $local_path ) || 0 === filesize( $local_path ) ) {
				return array(
					'passes'  => false,
					'verdict' => 'e2e_evidence_log_missing_or_empty',
					'detail'  => 'evidence log ' . $evidence_path . ' missing or empty',
				);
			}
			return array(
				'passes'  => true,
				'verdict' => 'e2e_signal_pass_with_log',
				'detail'  => 'signals.e2e.status=' . $status . ' and evidence log exists and is non-empty',
			);

		case 'plugin-check-evidence':
			$path = $root_dir . '/dist/release-certification-evidence.json';
			if ( ! is_file( $path ) ) {
				return array(
					'passes'  => false,
					'verdict' => 'missing_evidence',
					'detail'  => 'dist/release-certification-evidence.json does not exist',
				);
			}
			$payload = json_decode( (string) file_get_contents( $path ), true );
			$log_ref = $payload['plugin_check_url'] ?? '';
			if ( '' === $log_ref ) {
				return array(
					'passes'  => false,
					'verdict' => 'plugin_check_url_empty',
					'detail'  => 'release-certification-evidence.json plugin_check_url is empty',
				);
			}
			$local = preg_replace( '#^local:#', '', $log_ref );
			$local = $root_dir . '/' . ltrim( $local, '/' );
			if ( ! is_file( $local ) || 0 === filesize( $local ) ) {
				return array(
					'passes'  => false,
					'verdict' => 'plugin_check_log_missing_or_empty',
					'detail'  => 'plugin_check_url log ' . $log_ref . ' missing or empty',
				);
			}
			$log_src = (string) file_get_contents( $local );
			// Heuristic: a passing Plugin Check run records explicit pass markers.
			if ( false === stripos( $log_src, 'no errors found' )
				&& false === stripos( $log_src, 'success' )
				&& false === stripos( $log_src, '0 errors' )
				&& false === stripos( $log_src, 'pass' )
			) {
				return array(
					'passes'  => false,
					'verdict' => 'plugin_check_log_no_pass_marker',
					'detail'  => 'plugin_check_url log does not contain a recognised PASS marker',
				);
			}
			return array(
				'passes'  => true,
				'verdict' => 'plugin_check_log_pass',
				'detail'  => 'release-certification-evidence.json plugin_check_url points at a non-empty pass-marked log',
			);

		case 'clean-install-evidence':
			$json_path = $root_dir . '/dist/clean-install-evidence.json';
			$md_path   = $root_dir . '/dist/evidence/clean-install.md';
			if ( ! is_file( $json_path ) ) {
				return array(
					'passes'  => false,
					'verdict' => 'missing_evidence',
					'detail'  => 'dist/clean-install-evidence.json does not exist',
				);
			}
			if ( ! is_file( $md_path ) || 0 === filesize( $md_path ) ) {
				return array(
					'passes'  => false,
					'verdict' => 'clean_install_doc_missing_or_empty',
					'detail'  => 'dist/evidence/clean-install.md missing or empty',
				);
			}
			$payload = json_decode( (string) file_get_contents( $json_path ), true );
			if ( ! is_array( $payload ) ) {
				return array(
					'passes'  => false,
					'verdict' => 'malformed_clean_install_evidence',
					'detail'  => 'dist/clean-install-evidence.json is not valid JSON',
				);
			}
			$required = array( 'activation', 'deactivation', 'no_fatal', 'no_warning_attributable', 'db_tables_present', 'capabilities_present', 'cron_hooks_present', 'admin_ui_loads', 'export_basic', 'reactivate_no_duplicates', 'uninstall_cleanup' );
			$bad = array();
			foreach ( $required as $key ) {
				if ( ! isset( $payload['checks'][ $key ] ) || 'PASS' !== $payload['checks'][ $key ] ) {
					$bad[] = $key . '=' . ( $payload['checks'][ $key ] ?? 'MISSING' );
				}
			}
			if ( $bad ) {
				return array(
					'passes'  => false,
					'verdict' => 'clean_install_checks_not_all_pass',
					'detail'  => 'checks not all PASS: ' . implode( ', ', $bad ),
				);
			}
			return array(
				'passes'  => true,
				'verdict' => 'clean_install_all_pass',
				'detail'  => 'all required clean-install checks PASS',
			);

		case 'runtime-export-evidence':
			$json_path = $root_dir . '/dist/runtime-export-evidence.json';
			$log_path  = $root_dir . '/dist/evidence/runtime-exports.log';
			if ( ! is_file( $json_path ) ) {
				return array(
					'passes'  => false,
					'verdict' => 'missing_evidence',
					'detail'  => 'dist/runtime-export-evidence.json does not exist',
				);
			}
			if ( ! is_file( $log_path ) || 0 === filesize( $log_path ) ) {
				return array(
					'passes'  => false,
					'verdict' => 'runtime_export_log_missing_or_empty',
					'detail'  => 'dist/evidence/runtime-exports.log missing or empty',
				);
			}
			$payload = json_decode( (string) file_get_contents( $json_path ), true );
			if ( ! is_array( $payload ) ) {
				return array(
					'passes'  => false,
					'verdict' => 'malformed_runtime_export_evidence',
					'detail'  => 'dist/runtime-export-evidence.json is not valid JSON',
				);
			}
			$required = array( 'activation', 'docx', 'pdf', 'html', 'markdown', 'all_formats', 'all_languages', 'arabic_rtl', 'retry_behavior', 'finalize', 'single_use_download', 'unauthorized_download_rejected' );
			$bad = array();
			foreach ( $required as $key ) {
				if ( ! isset( $payload['checks'][ $key ] ) || 'PASS' !== $payload['checks'][ $key ] ) {
					$bad[] = $key . '=' . ( $payload['checks'][ $key ] ?? 'MISSING' );
				}
			}
			if ( $bad ) {
				return array(
					'passes'  => false,
					'verdict' => 'runtime_export_checks_not_all_pass',
					'detail'  => 'checks not all PASS: ' . implode( ', ', $bad ),
				);
			}
			return array(
				'passes'  => true,
				'verdict' => 'runtime_export_all_pass',
				'detail'  => 'all required runtime-export checks PASS',
			);


		case 'manual-runtime-evidence':
			$json_path = $root_dir . '/dist/manual-runtime-evidence.json';
			$log_path  = $root_dir . '/dist/evidence/manual-runtime.log';
			if ( ! is_file( $json_path ) ) {
				return array(
					'passes'  => false,
					'verdict' => 'missing_evidence',
					'detail'  => 'dist/manual-runtime-evidence.json does not exist',
				);
			}
			if ( ! is_file( $log_path ) || 0 === filesize( $log_path ) ) {
				return array(
					'passes'  => false,
					'verdict' => 'manual_runtime_log_missing_or_empty',
					'detail'  => 'dist/evidence/manual-runtime.log missing or empty',
				);
			}
			$payload = json_decode( (string) file_get_contents( $json_path ), true );
			if ( ! is_array( $payload ) ) {
				return array(
					'passes'  => false,
					'verdict' => 'malformed_manual_runtime_evidence',
					'detail'  => 'dist/manual-runtime-evidence.json is not valid JSON',
				);
			}

			$current_sha = trim( (string) shell_exec( 'git rev-parse HEAD 2> ' . ( '\\' === DIRECTORY_SEPARATOR ? 'NUL' : '/dev/null' ) ) );
			if ( ! preg_match( '/^[a-f0-9]{40}$/', $current_sha )
				|| ! hash_equals( $current_sha, (string) ( $payload['source_sha'] ?? '' ) )
			) {
				return array(
					'passes'  => false,
					'verdict' => 'manual_runtime_source_sha_mismatch',
					'detail'  => 'manual runtime evidence source_sha does not match current git HEAD',
				);
			}

			$mainfile = $root_dir . '/sscribe-export-site-pages.php';
			$version  = '';
			if ( is_file( $mainfile ) ) {
				$main_src = (string) file_get_contents( $mainfile );
				if ( preg_match( "/define\\s*\\(\\s*['\"]SSCRIBE_VERSION['\"]\\s*,\\s*['\"]([^'\"]+)['\"]/", $main_src, $match ) ) {
					$version = (string) $match[1];
				}
			}
			$zip_path = '' !== $version ? $root_dir . '/dist/sscribe-export-site-pages-' . $version . '.zip' : '';
			$zip_sha  = '' !== $zip_path && is_file( $zip_path ) ? hash_file( 'sha256', $zip_path ) : false;
			if ( ! is_string( $zip_sha )
				|| ! preg_match( '/^[a-f0-9]{64}$/', $zip_sha )
				|| ! hash_equals( $zip_sha, (string) ( $payload['zip_sha256'] ?? '' ) )
			) {
				return array(
					'passes'  => false,
					'verdict' => 'manual_runtime_zip_sha_mismatch',
					'detail'  => 'manual runtime evidence zip_sha256 does not match the exact current-version ZIP',
				);
			}

			$required = array( 'standard_wordpress', 'wpml', 'redis_on', 'redis_off', 'openlitespeed', 'cloudflare_proxy' );
			$bad = array();
			foreach ( $required as $key ) {
				$status = (string) ( $payload['environments'][ $key ]['status'] ?? '' );
				$proof  = trim( (string) ( $payload['environments'][ $key ]['evidence'] ?? '' ) );
				if ( 'PASS' !== $status || '' === $proof ) {
					$bad[] = $key . '=' . ( '' !== $status ? $status : 'MISSING' );
				}
			}
			if ( $bad ) {
				return array(
					'passes'  => false,
					'verdict' => 'manual_runtime_environments_not_all_pass',
					'detail'  => 'manual runtime environments not all PASS with evidence: ' . implode( ', ', $bad ),
				);
			}
			return array(
				'passes'  => true,
				'verdict' => 'manual_runtime_all_pass',
				'detail'  => 'all six Phase 69 manual runtime environments PASS on the exact current source SHA and ZIP',
			);

		case 'source-transparency-evidence':
			$json_path = $root_dir . '/dist/source-transparency-evidence.json';
			if ( ! is_file( $json_path ) ) {
				return array(
					'passes'  => false,
					'verdict' => 'missing_evidence',
					'detail'  => 'dist/source-transparency-evidence.json does not exist',
				);
			}
			$payload = json_decode( (string) file_get_contents( $json_path ), true );
			if ( ! is_array( $payload ) ) {
				return array(
					'passes'  => false,
					'verdict' => 'malformed_source_transparency_evidence',
					'detail'  => 'dist/source-transparency-evidence.json is not valid JSON',
				);
			}
			$public_url = $payload['public_url'] ?? '';
			if ( '' === $public_url ) {
				return array(
					'passes'  => false,
					'verdict' => 'public_url_empty',
					'detail'  => 'public_url missing',
				);
			}
			$required_keys = array( 'composer_json_url', 'build_script_url', 'build_doc_url' );
			$missing_keys  = array();
			foreach ( $required_keys as $k ) {
				if ( empty( $payload[ $k ] ) ) {
					$missing_keys[] = $k;
				}
			}
			if ( $missing_keys ) {
				return array(
					'passes'  => false,
					'verdict' => 'source_transparency_keys_missing',
					'detail'  => 'missing keys: ' . implode( ', ', $missing_keys ),
				);
			}
			$checks = $payload['checks'] ?? array();
			$bad    = array();
			foreach ( array( 'public_url', 'composer_json', 'build_script', 'build_doc' ) as $k ) {
				if ( ! isset( $checks[ $k ] ) || 'PASS' !== $checks[ $k ] ) {
					$bad[] = $k . '=' . ( $checks[ $k ] ?? 'MISSING' );
				}
			}
			if ( $bad ) {
				return array(
					'passes'  => false,
					'verdict' => 'source_transparency_checks_not_pass',
					'detail'  => 'checks not all PASS: ' . implode( ', ', $bad ),
				);
			}
			return array(
				'passes'  => true,
				'verdict' => 'source_transparency_all_pass',
				'detail'  => 'public source / build tooling reachable and verified',
			);
	}
	return array(
		'passes'  => false,
		'verdict' => 'unknown_closure_source',
		'detail'  => 'closure source ' . $closure_source . ' is not implemented',
	);
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
$deferred_count = 0;
foreach ( $effective as $row ) {
	if ( 'RESOLVED' !== $row['effective'] ) {
		++$deferred_count;
	}
}
$manifest = array(
	'generated_at'        => gmdate( 'c' ),
	'row_statuses'        => $row_statuses,
	'effective'           => $effective,
	'rule_count'          => count( $matrix ),
	'passed_count'        => count( array_filter( $matrix, static fn( $r ) => $r['passes'] ) ),
	'errors_count'        => count( $errors ),
	'deferred_count'      => $deferred_count,
	'strict_certification'=> $strict_certification,
	'release_ready'       => $strict_certification && 0 === count( $errors ) && 0 === $deferred_count,
	'passes'              => 0 === count( $errors ),
	'errors'              => $errors,
	'matrix'              => $matrix,
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
echo "\nRow status (registry / effective):\n";
foreach ( $effective as $num => $row ) {
	echo sprintf(
		"  #%-3d registry=%-8s effective=%-8s closure=%-26s verdict=%-32s %s\n",
		$num,
		$row['status'],
		$row['effective'],
		$row['closure_source'],
		$row['evidence_check'],
		$row['blocker']
	);
	if ( '' !== $row['evidence_detail'] ) {
		echo "        └─ {$row['evidence_detail']}\n";
	}
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
