<?php
/**
 * Branch protection contract verifier.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

if ( 'cli' !== php_sapi_name() ) {
	exit( 'This script must be run from the command line.' );
}

$root_dir      = dirname( __DIR__ );
$doc_path      = $root_dir . '/docs/BRANCH_PROTECTION_v2.0.0.md';
$ci_workflow   = $root_dir . '/.github/workflows/ci.yml';
$manifest_path = $root_dir . '/dist/branch-protection-manifest.json';
$matrix        = array();
$errors        = array();

if ( ! is_file( $doc_path ) || ! is_file( $ci_workflow ) ) {
	fwrite( STDERR, "Required branch-protection source files are missing.\n" );
	exit( 1 );
}

$doc_src = (string) file_get_contents( $doc_path );
$ci_src  = (string) file_get_contents( $ci_workflow );

function sscribe_branch_protection_rule( array &$matrix, array &$errors, string $rule, bool $passes, string $detail ): void {
	$matrix[] = array(
		'rule'   => $rule,
		'passes' => $passes,
		'detail' => $detail,
	);
	if ( ! $passes ) {
		$errors[] = $detail;
	}
}

sscribe_branch_protection_rule(
	$matrix,
	$errors,
	'branch_protection_doc_declares_protected_branch',
	(bool) preg_match( '/^Branches:\s*main\s*$/mi', $doc_src ),
	'docs/BRANCH_PROTECTION_v2.0.0.md must declare main as the single canonical long-lived branch.'
);

preg_match_all( '/^    ([a-z][a-z0-9_-]*):\s*\n\s+name:\s*([^\n]+)/m', $ci_src, $job_matches );
$ci_jobs = array();
foreach ( $job_matches as $row ) {
	$ci_jobs[ trim( $row[1] ) ] = trim( $row[2] );
}

$mandatory_jobs = array(
	'version-check' => 'Version Sync',
	'test'          => 'PHPUnit',
	'plugin-check'  => 'Submission Package Check',
);
$required_section = '';
if ( preg_match( '/##\s*Required status checks[^\n]*\n(.*?)(?=^##\s|\z)/sm', $doc_src, $section ) ) {
	$required_section = $section[1];
}
foreach ( $mandatory_jobs as $job_id => $label ) {
	sscribe_branch_protection_rule(
		$matrix,
		$errors,
		"branch_protection_doc_lists_{$job_id}_as_required_check",
		false !== stripos( $required_section, $label ) && isset( $ci_jobs[ $job_id ] ),
		"Required status checks must include {$label} and ci.yml must declare job {$job_id}."
	);
}

sscribe_branch_protection_rule( $matrix, $errors, 'branch_protection_doc_forbids_force_pushes', (bool) preg_match( '/(no|forbid|reject|disallow)[^\n]*force[ -]?push/i', $doc_src ), 'Branch protection must forbid force-pushes.' );
sscribe_branch_protection_rule( $matrix, $errors, 'branch_protection_doc_forbids_branch_deletion', (bool) preg_match( '/(?:no|rejects?|forbid)[^\n]*(?:branch[ -]?deletion|deletion)/i', $doc_src ), 'Branch protection must forbid deletion of main.' );
sscribe_branch_protection_rule( $matrix, $errors, 'branch_protection_doc_forbids_unrestricted_bypass', false !== stripos( $doc_src, 'no unrestricted admin bypass' ), 'Branch protection must forbid unrestricted admin bypass.' );
sscribe_branch_protection_rule( $matrix, $errors, 'branch_protection_doc_requires_reviewed_main_prs', false !== stripos( $doc_src, 'Changes enter through pull requests' ), 'Changes to main must enter through reviewed pull requests.' );
sscribe_branch_protection_rule( $matrix, $errors, 'branch_protection_doc_requires_conversation_resolution', (bool) preg_match( '/conversation[ -]?resolution/i', $doc_src ), 'Conversation resolution must be required before merge.' );
sscribe_branch_protection_rule( $matrix, $errors, 'branch_protection_doc_requires_signed_commits', (bool) preg_match( '/(--signoff|DCO|signed\s+commit|sign[ -]?off)/i', $doc_src ), 'Signed commits or DCO signoff must be required for release promotion.' );

$merge_methods = false !== stripos( $doc_src, 'Pull requests into `main`: squash' )
	&& (bool) preg_match( '/rebase[- ]?merge:\s*disabled/i', $doc_src )
	&& (bool) preg_match( '/merge commits:\s*disabled/i', $doc_src );
sscribe_branch_protection_rule( $matrix, $errors, 'branch_protection_doc_pins_allowed_merge_methods', $merge_methods, 'main must use squash PR integration with rebase-merge and normal merge commits disabled.' );

$reviews = (bool) preg_match( '/At\s+least\s+\d+\s+(approving\s+)?review/i', $doc_src );
sscribe_branch_protection_rule( $matrix, $errors, 'branch_protection_doc_declares_required_reviews', $reviews, 'Branch protection must declare required approving reviews.' );

$audit_state = (bool) preg_match( '/Audited\s+Date\s*:/i', $doc_src ) && (bool) preg_match( '/main:\s*UNPROTECTED/i', $doc_src );
sscribe_branch_protection_rule( $matrix, $errors, 'branch_protection_doc_records_audited_date_and_live_state', $audit_state, 'The document must record an audited date and observed main protection state.' );
sscribe_branch_protection_rule( $matrix, $errors, 'branch_protection_doc_marks_itself_as_living_document', (bool) preg_match( '/[Ll]iving\s+document/', $doc_src ), 'The document must identify itself as a living document.' );

$cross_refs = false !== stripos( $doc_src, 'docs/CI_EVIDENCE' ) && false !== stripos( $doc_src, 'docs/SECURITY_MATRIX' );
sscribe_branch_protection_rule( $matrix, $errors, 'branch_protection_doc_cross_references_companion_evidence', $cross_refs, 'The document must cross-reference CI and security evidence.' );
sscribe_branch_protection_rule( $matrix, $errors, 'branch_protection_doc_declares_compensating_controls', false !== stripos( $doc_src, 'Compensating controls' ), 'The document must declare compensating controls while live protection is absent.' );

$manifest_dir = dirname( $manifest_path );
if ( ! is_dir( $manifest_dir ) ) {
	mkdir( $manifest_dir, 0755, true );
}
$manifest = array(
	'generated_at'       => gmdate( 'c' ),
	'rule_count'         => count( $matrix ),
	'passed_count'       => count( array_filter( $matrix, static fn( $row ) => $row['passes'] ) ),
	'matrix'             => $matrix,
	'errors_count'       => count( $errors ),
	'mandatory_jobs'     => array_keys( $mandatory_jobs ),
	'ci_jobs_discovered' => $ci_jobs,
	'passes'             => empty( $errors ),
	'errors'             => $errors,
);
file_put_contents( $manifest_path, json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );

echo "=== SScribe Branch Protection ===\n\n";
foreach ( $matrix as $row ) {
	echo sprintf( "  %s  %s\n      %s\n", $row['passes'] ? '✓' : '✗', $row['rule'], $row['detail'] );
}
echo "\nErrors: " . count( $errors ) . "\n";
echo "\nManifest persisted to: {$manifest_path}\n";
if ( ! empty( $errors ) ) {
	exit( 1 );
}
echo "✓ Branch protection contract valid.\n";
