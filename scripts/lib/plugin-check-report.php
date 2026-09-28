<?php
/** Fail-closed reader for the upstream FILE + JSON CLI format. */
declare( strict_types=1 );

/** Normalize only plugin-relative paths; never discard an arbitrary prefix. */
function sscribe_plugin_check_source( string $source ): string {
	$source = str_replace( '\\', '/', $source );
	if ( str_starts_with( $source, './' ) ) {
		$source = substr( $source, 2 );
	}
	if ( '' === $source || preg_match( '~(^/|:|[\x00-\x1f]|(^|/)\.\.?(/|$)|//)~', $source ) ) {
		return '';
	}
	return $source;
}

function sscribe_parse_plugin_check_report( string $path ): array {
	$result = array( 'exists' => is_file( $path ), 'empty' => true, 'parseable' => false, 'is_stub' => false, 'codes' => array(), 'findings' => array(), 'error_count' => 0, 'warning_count' => 0, 'has_errors' => false, 'success_marker' => false, 'sha256' => '' );
	if ( ! $result['exists'] ) {
		return $result;
	}
	$raw = file_get_contents( $path );
	if ( false === $raw ) {
		return $result;
	}
	$result['sha256'] = hash( 'sha256', $raw );
	$remaining = trim( $raw );
	$result['empty'] = '' === $remaining;
	if ( 'Success: Checks complete. No errors found.' === $remaining ) {
		$result['parseable'] = true;
		$result['success_marker'] = true;
		return $result;
	}
	// Upstream --format=json prints FILE: <relative path>, then one JSON array.
	// Consume every byte: headers, arbitrary success prose and trailing logs fail.
	while ( '' !== $remaining ) {
		if ( ! preg_match( '/\AFILE: ([^\r\n]+)\r?\n(\[.*?\])(?:\r?\n|\z)/s', $remaining, $match ) ) {
			return $result;
		}
		$source = sscribe_plugin_check_source( $match[1] );
		$rows = json_decode( $match[2], true );
		if ( '' === $source || ! is_array( $rows ) || array() === $rows || array_keys( $rows ) !== range( 0, count( $rows ) - 1 ) ) {
			return $result;
		}
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				return $result;
			}
			$keys = array_keys( $row );
			sort( $keys );
			if ( array( 'code', 'column', 'docs', 'line', 'message', 'type' ) !== $keys
				|| ! is_int( $row['line'] ) || $row['line'] < 0 || ! is_int( $row['column'] ) || $row['column'] < 0
				|| ! is_string( $row['code'] ) || '' === $row['code'] || ! is_string( $row['message'] ) || ! is_string( $row['docs'] )
				|| ! in_array( $row['type'], array( 'ERROR', 'WARNING' ), true ) ) {
				return $result;
			}
			$type = strtolower( $row['type'] );
			$result['findings'][] = array( 'code' => $row['code'], 'source' => $source, 'severity' => $type );
			$result['codes'][] = $row['code'];
			++$result[ $type . '_count' ];
		}
		$remaining = ltrim( substr( $remaining, strlen( $match[0] ) ), "\r\n" );
	}
	$result['parseable'] = count( $result['findings'] ) > 0;
	$result['has_errors'] = $result['error_count'] > 0;
	return $result;
}

function sscribe_validate_plugin_check_report( array $parsed, array $triage_rows, array $expected = array() ): array {
	$violations = array();
	if ( ! $parsed['exists'] || $parsed['empty'] ) {
		return array( 'ok' => false, 'blocked' => true, 'violations' => array( $parsed['exists'] ? 'empty_report' : 'missing_report' ), 'notes' => array() );
	}
	if ( ! $parsed['parseable'] ) {
		$violations[] = 'malformed_report';
	}
	if ( $parsed['error_count'] > 0 ) {
		$violations[] = 'report_contains_errors';
	}
	foreach ( $parsed['findings'] as $finding ) {
		$allowed = false;
		foreach ( $triage_rows as $row ) {
			if ( $finding['code'] === ( $row['code'] ?? '' ) && $finding['source'] === ( $row['source'] ?? '' )
				&& 'warning' === $finding['severity'] && 'warning' === ( $row['severity'] ?? '' ) && 'acknowledged' === ( $row['status'] ?? '' ) ) {
				$allowed = true;
			}
		}
		if ( ! $allowed ) {
			$violations[] = 'unacknowledged_finding:' . $finding['code'] . ':' . $finding['source'];
		}
	}
	$evidence = $expected['evidence'] ?? array();
	foreach ( array( 'source_sha' => 40, 'zip_sha256' => 64, 'report_sha256' => 64 ) as $key => $length ) {
		$want = $expected[ $key ] ?? '';
		$got = $evidence[ $key ] ?? '';
		if ( ! is_string( $want ) || ! is_string( $got ) || ! preg_match( '/\A[0-9a-f]{' . $length . '}\z/', $got ) || $want !== $got ) {
			$violations[] = 'invalid_or_stale_' . $key;
		}
	}
	if ( ( $expected['report_sha256'] ?? '' ) !== $parsed['sha256'] ) {
		$violations[] = 'report_hash_mismatch';
	}
	if ( 0 !== ( $evidence['exit_code'] ?? null ) ) {
		$violations[] = 'missing_or_failed_command';
	}
	return array( 'ok' => array() === $violations, 'blocked' => false, 'violations' => $violations, 'notes' => array() );
}

function sscribe_load_triage_rows( string $triage_doc ): array {
	$rows = array();
	if ( ! is_file( $triage_doc ) ) {
		return $rows;
	}
	foreach ( file( $triage_doc, FILE_IGNORE_NEW_LINES ) as $line ) {
		if ( preg_match( '/^\|\s*`([^`]+)`\s*\|\s*`([^`]+)`\s*\|\s*(warning|error)\s*\|\s*`([a-z_]+)`\s*\|/', $line, $hit ) ) {
			$rows[] = array( 'code' => $hit[1], 'source' => sscribe_plugin_check_source( $hit[2] ), 'severity' => $hit[3], 'status' => $hit[4] );
		}
	}
	return $rows;
}
