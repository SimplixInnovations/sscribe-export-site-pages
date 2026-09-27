<?php
/**
 * Plugin Check live-report parser and validator.
 *
 * Isolated from the triage verifier so unit tests can feed fixtures
 * (missing / empty / stub / malformed / errors / unknown codes /
 * fixed-warning recurrence / wrong source / stale identity) without
 * requiring a live WordPress Plugin Check run.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

/**
 * Parse a Plugin Check report (TSV, JSON, or plain-text CLI log).
 *
 * @param string $path Report file path.
 * @return array{
 *   exists:bool,
 *   empty:bool,
 *   parseable:bool,
 *   is_stub:bool,
 *   source:string,
 *   codes:string[],
 *   error_count:int,
 *   warning_count:int,
 *   has_errors:bool,
 *   success_marker:bool,
 *   sha256:string,
 *   raw_preview:string
 * }
 */
function sscribe_parse_plugin_check_report( string $path ): array {
	$result = array(
		'exists'         => is_file( $path ),
		'empty'          => true,
		'parseable'      => false,
		'is_stub'        => false,
		'source'         => '',
		'codes'          => array(),
		'error_count'    => 0,
		'warning_count'  => 0,
		'has_errors'     => false,
		'success_marker' => false,
		'sha256'         => '',
		'raw_preview'    => '',
	);

	if ( ! $result['exists'] ) {
		return $result;
	}

	$raw = (string) file_get_contents( $path );
	$result['raw_preview'] = substr( $raw, 0, 400 );
	$result['sha256']      = hash( 'sha256', $raw );

	$trimmed = trim( $raw );
	if ( '' === $trimmed ) {
		return $result;
	}
	$result['empty'] = false;

	// Stub / placeholder markers must never count as a real pass.
	$stub_markers = array( 'STUB', 'TODO', 'PLACEHOLDER', 'NOT_A_REAL_REPORT', 'example report' );
	foreach ( $stub_markers as $marker ) {
		if ( stripos( $trimmed, $marker ) !== false ) {
			$result['is_stub'] = true;
			return $result;
		}
	}

	// JSON shape from plugin-check-action / wp plugin check --format=json.
	$json = json_decode( $raw, true );
	if ( is_array( $json ) ) {
		$result['parseable'] = true;
		if ( isset( $json['source'] ) && is_string( $json['source'] ) ) {
			$result['source'] = $json['source'];
		}
		if ( isset( $json['sha256'] ) && is_string( $json['sha256'] ) ) {
			$result['sha256'] = $json['sha256'];
		}
		$files = $json['files'] ?? $json['results'] ?? array();
		if ( is_array( $files ) ) {
			foreach ( $files as $file_rows ) {
				if ( ! is_array( $file_rows ) ) {
					continue;
				}
				foreach ( $file_rows as $row ) {
					if ( ! is_array( $row ) ) {
						continue;
					}
					$code = (string) ( $row['code'] ?? $row['sniff'] ?? '' );
					if ( '' !== $code ) {
						$result['codes'][] = $code;
					}
					$type = strtolower( (string) ( $row['type'] ?? $row['severity'] ?? '' ) );
					if ( 'error' === $type ) {
						++$result['error_count'];
					} elseif ( 'warning' === $type ) {
						++$result['warning_count'];
					}
				}
			}
		}
		if ( ! empty( $json['errors'] ) && is_array( $json['errors'] ) ) {
			$result['error_count'] += count( $json['errors'] );
		}
		$result['codes'] = array_values( array_unique( $result['codes'] ) );
		$result['has_errors'] = $result['error_count'] > 0;
		$result['success_marker'] = (bool) ( $json['success'] ?? false ) || 0 === $result['error_count'];
		return $result;
	}

	// Plain-text / TSV CLI log from `wp plugin check`.
	if ( preg_match_all( '/\b((?:PluginCheck|WordPress|PHPCompatibility|Generic|Squiz)\.[A-Za-z0-9_.]+)\b/', $raw, $code_hits ) ) {
		$result['codes'] = array_values( array_unique( $code_hits[1] ) );
	}
	$looks_like_plugin_check = (bool) preg_match(
		'/\b(FILE:|WARNING|ERROR|Success:\s*Checks complete|Plugin Check|line\s+column\s+type\s+code)/i',
		$raw
	);
	if ( ! $looks_like_plugin_check && 0 === count( $result['codes'] ) ) {
		$result['parseable'] = false;
		return $result;
	}
	$result['parseable'] = true;

	if ( preg_match_all( '/\bERROR\b/i', $raw, $err_hits ) ) {
		$result['error_count'] = count( $err_hits[0] );
	}
	if ( preg_match_all( '/\bWARNING\b/i', $raw, $warn_hits ) ) {
		$result['warning_count'] = count( $warn_hits[0] );
	}
	$result['has_errors']     = $result['error_count'] > 0
		|| (bool) preg_match( '/\bno errors found\b/i', $raw ) === false && (bool) preg_match( '/\btype\b.*\berror\b/i', $raw );
	// Prefer explicit success marker when present.
	if ( preg_match( '/Success:\s*Checks complete\.\s*No errors found\./i', $raw )
		|| preg_match( '/\bno errors found\b/i', $raw )
	) {
		$result['success_marker'] = true;
		$result['has_errors']     = $result['error_count'] > 0;
	}
	if ( preg_match( '/\bFILE:\s*(\S+)/', $raw, $file_m ) ) {
		$result['source'] = $file_m[1];
	}
	if ( preg_match( '/\bsource_sha["\s:]+([0-9a-f]{40})/i', $raw, $sha_m ) ) {
		$result['source'] = $result['source'] !== '' ? $result['source'] : $sha_m[1];
	}

	return $result;
}

/**
 * Validate a live report against the triage doc and expected identity.
 *
 * @param array  $parsed        Result of sscribe_parse_plugin_check_report().
 * @param string[] $triage_codes Codes listed as fixed/acknowledged/in_progress/deferred.
 * @param array  $expected      Optional identity: source_sha, zip_sha256, expected_source.
 * @return array{ok:bool,blocked:bool,violations:string[],notes:string[]}
 */
function sscribe_validate_plugin_check_report(
	array $parsed,
	array $triage_codes,
	array $expected = array()
): array {
	$violations = array();
	$notes      = array();
	$blocked    = false;

	if ( ! $parsed['exists'] ) {
		return array(
			'ok'         => false,
			'blocked'    => true,
			'violations' => array( 'missing_report' ),
			'notes'      => array( 'BLOCKED: no live Plugin Check report.' ),
		);
	}
	if ( $parsed['empty'] ) {
		return array(
			'ok'         => false,
			'blocked'    => true,
			'violations' => array( 'empty_report' ),
			'notes'      => array( 'BLOCKED: Plugin Check report is empty.' ),
		);
	}
	if ( $parsed['is_stub'] ) {
		return array(
			'ok'         => false,
			'blocked'    => false,
			'violations' => array( 'stub_report' ),
			'notes'      => array( 'Report looks like a stub/placeholder; never PASS.' ),
		);
	}
	if ( ! $parsed['parseable'] ) {
		return array(
			'ok'         => false,
			'blocked'    => false,
			'violations' => array( 'malformed_report' ),
			'notes'      => array( 'Report could not be parsed as JSON or Plugin Check TSV/log.' ),
		);
	}

	if ( $parsed['has_errors'] ) {
		$violations[] = 'report_contains_errors';
	}
	if ( ! $parsed['success_marker'] && $parsed['error_count'] > 0 ) {
		$violations[] = 'errors_without_success_marker';
	}

	// Fixed-warning recurrence: a code marked `fixed` in triage must not reappear.
	$fixed_codes = array();
	foreach ( $triage_codes as $entry ) {
		if ( is_array( $entry ) ) {
			$code   = (string) ( $entry['code'] ?? '' );
			$status = (string) ( $entry['status'] ?? '' );
			if ( '' !== $code && 'fixed' === $status ) {
				$fixed_codes[] = $code;
			}
		}
	}
	foreach ( $parsed['codes'] as $code ) {
		if ( in_array( $code, $fixed_codes, true ) ) {
			$violations[] = 'fixed_warning_recurrence:' . $code;
		}
	}

	// Unknown codes must be triaged (code-only list or code/status maps).
	$known = array();
	foreach ( $triage_codes as $entry ) {
		if ( is_array( $entry ) ) {
			$known[] = (string) ( $entry['code'] ?? '' );
		} else {
			$known[] = (string) $entry;
		}
	}
	$known      = array_values( array_filter( $known, static fn( $c ): bool => '' !== $c ) );
	$untriaged  = array_values( array_diff( $parsed['codes'], $known ) );
	foreach ( $untriaged as $code ) {
		$violations[] = 'unknown_code:' . $code;
	}

	// Wrong source: report must describe the expected plugin, not another.
	if ( isset( $expected['expected_source'] ) && '' !== (string) $expected['expected_source'] && '' !== $parsed['source'] ) {
		$want = (string) $expected['expected_source'];
		$got  = $parsed['source'];
		if ( stripos( $got, $want ) === false && stripos( $want, $got ) === false ) {
			$violations[] = 'wrong_source:' . $got;
		}
	}

	// Stale SHA/hash evidence: if expected identity is provided and the report
	// embeds a source/zip hash, they must match the frozen candidate.
	foreach ( array( 'source_sha', 'zip_sha256' ) as $key ) {
		if ( ! isset( $expected[ $key ] ) || '' === (string) $expected[ $key ] ) {
			continue;
		}
		$want_id = strtolower( (string) $expected[ $key ] );
		if ( preg_match( '/' . preg_quote( $key, '/' ) . '["\s:]+([0-9a-f]{32,64})/i', $parsed['raw_preview'] . ' ' . ( $parsed['sha256'] ?? '' ), $id_m ) ) {
			$got_id = strtolower( $id_m[1] );
			if ( $got_id !== $want_id && ! str_starts_with( $want_id, $got_id ) && ! str_starts_with( $got_id, $want_id ) ) {
				$violations[] = 'stale_' . $key;
			}
		}
	}

	$ok = 0 === count( $violations );
	return array(
		'ok'         => $ok,
		'blocked'    => $blocked,
		'violations' => $violations,
		'notes'      => $notes,
	);
}

/**
 * Extract triage table rows (code + status) from the triage Markdown doc.
 *
 * @param string $triage_doc Path to PLUGIN_CHECK_WARNINGS doc.
 * @return array<int, array{code:string,status:string}>
 */
function sscribe_load_triage_rows( string $triage_doc ): array {
	if ( ! is_file( $triage_doc ) ) {
		return array();
	}
	$src  = (string) file_get_contents( $triage_doc );
	$rows = array();
	if ( preg_match_all( '/\|\s*`([^`]+)`\s*\|[^|]*\|[^|]*\|\s*`([a-z_]+)`\s*\|/i', $src, $m, PREG_SET_ORDER ) ) {
		foreach ( $m as $hit ) {
			$rows[] = array(
				'code'   => $hit[1],
				'status' => strtolower( $hit[2] ),
			);
		}
	}
	return $rows;
}
