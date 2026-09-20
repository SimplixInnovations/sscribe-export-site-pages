<?php
/**
 * Final release-report contract and strict exact-SHA report generator.
 *
 * Normal source CI validates the tracked report format/template. Strict release
 * certification reads the already-generated Phase 70/71/72 manifests, binds
 * them to the current git HEAD, generates dist/final-release-report.md, and
 * validates that concrete report before the auditor handoff.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

if ( 'cli' !== php_sapi_name() ) {
	exit( 'This script must be run from the command line.' );
}

$root_dir             = dirname( __DIR__ );
$format_doc           = $root_dir . '/docs/AGENT_FINAL_REPORT_v2.0.0.md';
$template_doc         = $root_dir . '/docs/RELEASE_REPORT_TEMPLATE_v2.0.0.md';
$historical_doc       = $root_dir . '/docs/RELEASE_REPORT_v2.0.0.md';
$final_report_path    = $root_dir . '/dist/final-release-report.md';
$blockers_manifest    = $root_dir . '/dist/release-blockers-manifest.json';
$ci_manifest          = $root_dir . '/dist/final-ci-state-manifest.json';
$artifact_manifest    = $root_dir . '/dist/exact-artifact-evidence-manifest.json';
$manifest_path        = $root_dir . '/dist/agent-final-report-manifest.json';
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

$canonical_report_sections = array(
	'## Executive summary',
	'## Release evidence',
	'## Blocker status',
	'## CI state',
	'## Open items',
	'## Verification recipe',
);

$record(
	'format_doc_exists',
	is_file( $format_doc ),
	'docs/AGENT_FINAL_REPORT_v2.0.0.md must exist so the final-report format is auditable.'
);
$record(
	'tracked_report_template_exists',
	is_file( $template_doc ),
	'docs/RELEASE_REPORT_TEMPLATE_v2.0.0.md must exist as the tracked non-authoritative report template.'
);
$record(
	'historical_closeout_is_explicitly_historical',
	is_file( $historical_doc )
		&& false !== strpos( (string) file_get_contents( $historical_doc ), 'HISTORICAL' ),
	'docs/RELEASE_REPORT_v2.0.0.md must remain explicitly historical and must not masquerade as the current final report.'
);

if ( is_file( $format_doc ) ) {
	$doc_src = (string) file_get_contents( $format_doc );
	$canonical_sections = array(
		'## Why this exists',
		'## Canonical sections',
		'## Format rules',
		'## How an independent auditor verifies this',
	);
	$missing_sections = array();
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
	$record(
		'format_doc_documents_strict_generated_report',
		false !== strpos( $doc_src, 'dist/final-release-report.md' )
			&& false !== strpos( $doc_src, 'docs/RELEASE_REPORT_TEMPLATE_v2.0.0.md' ),
		'Agent final report format spec must distinguish the tracked template from dist/final-release-report.md strict proof.'
	);
}

if ( is_file( $template_doc ) ) {
	$template_src = (string) file_get_contents( $template_doc );
	$missing_template_sections = array();
	foreach ( $canonical_report_sections as $section ) {
		if ( false === strpos( $template_src, $section ) ) {
			$missing_template_sections[] = $section;
		}
	}
	$record(
		'tracked_template_has_every_canonical_report_section',
		0 === count( $missing_template_sections ),
		'Tracked release-report template is missing sections: ' . implode( ', ', $missing_template_sections )
	);
	$record(
		'tracked_template_declares_non_authoritative_status',
		false !== strpos( $template_src, 'not release proof' )
			&& false !== strpos( $template_src, 'dist/final-release-report.md' ),
		'Tracked template must state that exact-SHA release proof lives in dist/final-release-report.md.'
	);
}

$current_sha      = '';
$report_generated = false;
$report_version   = '';
$report_zip_sha   = '';

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
		'Strict final-report certification must resolve the current 40-hex git HEAD.'
	);

	$load_manifest = static function ( string $path ): array {
		if ( ! is_file( $path ) || 0 === filesize( $path ) ) {
			return array();
		}
		$decoded = json_decode( (string) file_get_contents( $path ), true );
		return is_array( $decoded ) ? $decoded : array();
	};

	$blockers = $load_manifest( $blockers_manifest );
	$ci_state = $load_manifest( $ci_manifest );
	$artifact = $load_manifest( $artifact_manifest );

	$record(
		'strict_prerequisite_manifests_exist',
		! empty( $blockers ) && ! empty( $ci_state ) && ! empty( $artifact ),
		'Strict final report requires non-empty Phase 70, Phase 71, and Phase 72 generated manifests.'
	);
	$record(
		'strict_blockers_are_release_ready',
		true === ( $blockers['release_ready'] ?? false )
			&& 0 === (int) ( $blockers['deferred_count'] ?? -1 )
			&& 0 === (int) ( $blockers['errors_count'] ?? -1 ),
		'Phase 70 manifest must be strict, release-ready, and have zero effective deferred blockers/errors.'
	);
	$record(
		'strict_ci_state_is_release_ready_for_head',
		true === ( $ci_state['release_ready'] ?? false )
			&& $current_sha === strtolower( trim( (string) ( $ci_state['current_source_sha'] ?? '' ) ) )
			&& 0 === (int) ( $ci_state['errors_count'] ?? -1 ),
		'Phase 71 manifest must be release-ready and bound to the exact current git HEAD.'
	);
	$record(
		'strict_artifact_is_release_ready_for_head',
		true === ( $artifact['release_ready'] ?? false )
			&& $current_sha === strtolower( trim( (string) ( $artifact['current_source_sha'] ?? '' ) ) )
			&& 0 === (int) ( $artifact['errors_count'] ?? -1 ),
		'Phase 72 manifest must be release-ready and bound to the exact current git HEAD.'
	);

	$actual = isset( $artifact['actual'] ) && is_array( $artifact['actual'] ) ? $artifact['actual'] : array();
	$report_version = trim( (string) ( $actual['version'] ?? '' ) );
	$report_zip_sha = strtolower( trim( (string) ( $actual['zip_sha256'] ?? '' ) ) );
	$zip_filename   = trim( (string) ( $actual['zip_filename'] ?? '' ) );
	$zip_size       = (int) ( $actual['zip_byte_size'] ?? 0 );
	$zip_count      = (int) ( $actual['zip_file_count'] ?? 0 );
	$builder_ref    = trim( (string) ( $actual['builder_run_id'] ?? '' ) );
	$plugin_ref     = trim( (string) ( $actual['plugin_check_url'] ?? '' ) );

	$record(
		'strict_artifact_fields_are_concrete',
		(bool) preg_match( '/^\d+\.\d+\.\d+$/', $report_version )
			&& (bool) preg_match( '/^[a-f0-9]{64}$/', $report_zip_sha )
			&& '' !== $zip_filename
			&& 0 < $zip_size
			&& 0 < $zip_count
			&& '' !== $builder_ref
			&& '' !== $plugin_ref,
		'Strict final report requires concrete version, ZIP identity, builder, and Plugin Check evidence from Phase 72.'
	);

	$ci_rows = isset( $ci_state['row_statuses'] ) && is_array( $ci_state['row_statuses'] )
		? $ci_state['row_statuses']
		: array();
	$ci_lines = array();
	foreach ( $ci_rows as $job => $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$ci_lines[] = '- `' . (string) $job . '`: '
			. strtoupper( trim( (string) ( $row['status'] ?? '' ) ) )
			. ' — ' . trim( (string) ( $row['evidence'] ?? '' ) );
	}

	$effective = isset( $blockers['effective'] ) && is_array( $blockers['effective'] )
		? $blockers['effective']
		: array();
	$resolved_count = 0;
	foreach ( $effective as $row ) {
		if ( is_array( $row ) && 'RESOLVED' === ( $row['effective'] ?? '' ) ) {
			++$resolved_count;
		}
	}

	if ( empty( $errors ) ) {
		$generated_at = gmdate( 'c' );
		$report = '# SScribe v' . $report_version . " — Final Release Report\n\n";
		$report .= 'Generated: ' . $generated_at . "\n\n";
		$report .= "## Executive summary\n\n";
		$report .= 'SScribe v' . $report_version . ' is certified for the planned immutable tag `v'
			. $report_version . '` from source SHA `' . $current_sha
			. '`. Phase 70 blockers, Phase 71 execution evidence, and Phase 72 exact-artifact evidence are all strict and release-ready for this same source identity.'
			. "\n\n";
		$report .= "## Release evidence\n\n";
		$report .= '- Version: ' . $report_version . "\n";
		$report .= '- Planned tag: `v' . $report_version . "`\n";
		$report .= '- Source SHA: `' . $current_sha . "`\n";
		$report .= '- ZIP: `' . $zip_filename . "`\n";
		$report .= '- ZIP SHA-256: `' . $report_zip_sha . "`\n";
		$report .= '- ZIP byte size: ' . $zip_size . "\n";
		$report .= '- ZIP file count: ' . $zip_count . "\n";
		$report .= '- Builder evidence: ' . $builder_ref . "\n";
		$report .= '- Plugin Check evidence: ' . $plugin_ref . "\n";
		$report .= "- Exact artifact manifest: `dist/exact-artifact-evidence-manifest.json`\n\n";
		$report .= "## Blocker status\n\n";
		$report .= "- Phase 70 release-ready: yes\n";
		$report .= '- Effective blockers resolved: ' . $resolved_count . '/' . count( $effective ) . "\n";
		$report .= "- Evidence: `dist/release-blockers-manifest.json`\n\n";
		$report .= "## CI state\n\n";
		$report .= implode( "\n", $ci_lines ) . "\n";
		$report .= "- Evidence: `dist/final-ci-state-manifest.json`\n\n";
		$report .= "## Open items\n\n";
		$report .= "- None in the canonical strict release gates. Repository-administration or publication actions that occur after this certification remain separately verifiable operational steps.\n\n";
		$report .= "## Verification recipe\n\n";
		$report .= "```bash\n";
		$report .= "SSCRIBE_RELEASE_CERTIFICATION=1 composer test:release-blockers\n";
		$report .= "SSCRIBE_RELEASE_CERTIFICATION=1 composer test:final-ci-state\n";
		$report .= "SSCRIBE_RELEASE_CERTIFICATION=1 composer test:exact-artifact-evidence\n";
		$report .= "SSCRIBE_RELEASE_CERTIFICATION=1 composer test:agent-final-report\n";
		$report .= 'sha256sum ' . $zip_filename . "\n";
		$report .= "git rev-parse HEAD\n";
		$report .= "git status --short\n";
		$report .= "```\n";

		$report_dir = dirname( $final_report_path );
		if ( ! is_dir( $report_dir ) ) {
			mkdir( $report_dir, 0755, true );
		}
		$report_generated = false !== file_put_contents( $final_report_path, $report );
		$record(
			'strict_final_report_generated',
			$report_generated && is_file( $final_report_path ) && 0 < filesize( $final_report_path ),
			'Strict certification must generate non-empty dist/final-release-report.md from Phase 70/71/72 evidence.'
		);
	}

	if ( is_file( $final_report_path ) ) {
		$final_src = (string) file_get_contents( $final_report_path );
		$missing_final_sections = array();
		foreach ( $canonical_report_sections as $section ) {
			if ( false === strpos( $final_src, $section ) ) {
				$missing_final_sections[] = $section;
			}
		}
		$record(
			'strict_final_report_has_canonical_sections',
			0 === count( $missing_final_sections ),
			'Strict final report is missing canonical sections: ' . implode( ', ', $missing_final_sections )
		);
		$record(
			'strict_final_report_has_no_placeholders',
			! preg_match( '/\b(?:PENDING|TBD|UNAVAILABLE|MISSING)\b/i', $final_src ),
			'Strict final report must not contain placeholder or unavailable-state markers.'
		);
		$record(
			'strict_final_report_matches_current_identity',
			'' !== $report_version
				&& false !== strpos( $final_src, 'v' . $report_version )
				&& false !== strpos( $final_src, $current_sha )
				&& '' !== $report_zip_sha
				&& false !== strpos( $final_src, $report_zip_sha ),
			'Strict final report must contain the current version, exact source SHA, and exact ZIP SHA-256.'
		);
	}
} else {
	$record(
		'strict_final_report_required_only_for_release_certification',
		true,
		'Normal source CI validates the tracked format/template only; exact-SHA report generation is strict-certification-only.'
	);
}

$test_path = $root_dir . '/tests/Integration/SScribe_Agent_Final_Report_Test.php';
$record(
	'integration_test_exists',
	is_file( $test_path ),
	'tests/Integration/SScribe_Agent_Final_Report_Test.php must exist so the final-report contract is pinned at the PHPUnit boundary.'
);

$manifest_dir = dirname( $manifest_path );
if ( ! is_dir( $manifest_dir ) ) {
	mkdir( $manifest_dir, 0755, true );
}
$manifest = array(
	'generated_at'           => gmdate( 'c' ),
	'strict_certification'   => $strict_certification,
	'current_source_sha'     => $current_sha,
	'current_version'        => $report_version,
	'final_report_path'      => $strict_certification ? 'dist/final-release-report.md' : null,
	'final_report_generated' => $report_generated,
	'rule_count'             => count( $matrix ),
	'passed_count'           => count( array_filter( $matrix, static fn( $row ) => $row['passes'] ) ),
	'errors_count'           => count( $errors ),
	'release_ready'          => $strict_certification && 0 === count( $errors ),
	'passes'                 => 0 === count( $errors ),
	'errors'                 => $errors,
	'matrix'                 => $matrix,
);
file_put_contents(
	$manifest_path,
	json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n"
);

echo "=== SScribe Agent Final Report Acceptance ===\n\n";
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
	echo "PASS: Exact-SHA final release report generated and validated.\n";
} else {
	echo "PASS: Final release-report format/template contract is valid. Strict report generation was not requested.\n";
}
exit( 0 );
