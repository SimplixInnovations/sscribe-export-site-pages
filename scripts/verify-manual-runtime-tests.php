<?php
/**
 * Phase 69 — Manual / runtime tests contract.
 *
 * Normal source CI validates the immutable runbook schema. Strict release
 * certification additionally requires ignored exact-release evidence proving
 * that all six canonical environments were exercised against the exact ZIP
 * produced from the current git HEAD.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

if ( 'cli' !== php_sapi_name() ) {
	exit( 'This script must be run from the command line.' );
}

$root_dir             = dirname( __DIR__ );
$runbook_doc          = $root_dir . '/docs/MANUAL_RUNTIME_TESTS_v2.0.0.md';
$manifest_path        = $root_dir . '/dist/manual-runtime-tests-manifest.json';
$evidence_path        = $root_dir . '/dist/manual-runtime-evidence.json';
$evidence_log_path    = $root_dir . '/dist/evidence/manual-runtime.log';
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
	'runbook_doc_exists',
	is_file( $runbook_doc ),
	'docs/MANUAL_RUNTIME_TESTS_v2.0.0.md must exist so the Phase 69 manual runbook is auditable.'
);

$required_environments = array(
	'standard_wordpress' => 'Standard WordPress',
	'wpml'               => 'WordPress + WPML',
	'redis_on'           => 'Redis object cache ON',
	'redis_off'          => 'Redis object cache OFF',
	'openlitespeed'      => 'OpenLiteSpeed',
	'cloudflare_proxy'   => 'Cloudflare',
);

$current_version = '';
if ( is_file( $root_dir . '/sscribe-export-site-pages.php' ) ) {
	$main_src = (string) file_get_contents( $root_dir . '/sscribe-export-site-pages.php' );
	if ( preg_match( "/define\\s*\\(\\s*['\"]SSCRIBE_VERSION['\"]\\s*,\\s*['\"]([^'\"]+)['\"]/", $main_src, $version_match ) ) {
		$current_version = (string) $version_match[1];
	}
}

if ( is_file( $runbook_doc ) ) {
	$doc_src = (string) file_get_contents( $runbook_doc );

	$canonical_sections = array(
		'## Why this exists',
		'## Canonical scenarios',
		'## Per-scenario acceptance criteria',
		'## What "exact ZIP" means',
		'## Evidence recording',
		'## How an independent auditor verifies this',
	);
	$missing_sections = array();
	foreach ( $canonical_sections as $section ) {
		if ( false === strpos( $doc_src, $section ) ) {
			$missing_sections[] = $section;
		}
	}
	$record(
		'runbook_has_canonical_sections',
		0 === count( $missing_sections ),
		'Manual runtime test runbook is missing canonical sections: ' . implode( ', ', $missing_sections )
	);

	$missing_envs = array();
	foreach ( $required_environments as $environment ) {
		if ( false === strpos( $doc_src, $environment ) ) {
			$missing_envs[] = $environment;
		}
	}
	$record(
		'runbook_covers_six_environments',
		0 === count( $missing_envs ),
		'Manual runtime test runbook must cover 6 canonical environments. Missing: ' . implode( ', ', $missing_envs )
	);

	$uses_versioned_artifact = false !== strpos( $doc_src, 'sscribe-export-site-pages-{VERSION}.zip' )
		&& false === strpos( $doc_src, 'dist/sscribe-export-site-pages.zip' )
		&& false !== strpos( $doc_src, 'SSCRIBE_VERSION' );
	$record(
		'runbook_uses_current_versioned_release_artifact',
		$uses_versioned_artifact,
		'Manual runtime runbook must resolve the current SSCRIBE_VERSION release ZIP and must not reference the obsolete unversioned dist/sscribe-export-site-pages.zip path.'
	);

	$record(
		'runbook_uses_untracked_exact_release_evidence',
		false !== strpos( $doc_src, 'dist/manual-runtime-evidence.json' )
			&& false !== strpos( $doc_src, 'dist/evidence/manual-runtime.log' )
			&& false === strpos( $doc_src, 'Evidence is captured in `docs/CI_EVIDENCE_v2.0.0.md`' ),
		'Manual runtime proof must live in ignored dist evidence, not tracked historical CI evidence that would change the certified source SHA.'
	);
}

$test_path = $root_dir . '/tests/Integration/SScribe_Manual_Runtime_Tests_Test.php';
$record(
	'integration_test_exists',
	is_file( $test_path ),
	'tests/Integration/SScribe_Manual_Runtime_Tests_Test.php must exist so the runbook is pinned at the PHPUnit boundary.'
);

if ( $strict_certification ) {
	$current_sha = trim( (string) shell_exec( 'git rev-parse HEAD 2> ' . ( '\\' === DIRECTORY_SEPARATOR ? 'NUL' : '/dev/null' ) ) );
	$zip_path    = '' !== $current_version
		? $root_dir . '/dist/sscribe-export-site-pages-' . $current_version . '.zip'
		: '';
	$zip_sha     = '' !== $zip_path && is_file( $zip_path ) ? hash_file( 'sha256', $zip_path ) : false;

	$evidence = array();
	if ( is_file( $evidence_path ) ) {
		$decoded = json_decode( (string) file_get_contents( $evidence_path ), true );
		if ( is_array( $decoded ) ) {
			$evidence = $decoded;
		}
	}

	$record(
		'strict_manual_runtime_evidence_present',
		! empty( $evidence ),
		'Strict release certification requires valid JSON at dist/manual-runtime-evidence.json.'
	);
	$record(
		'strict_manual_runtime_log_present',
		is_file( $evidence_log_path ) && 0 < filesize( $evidence_log_path ),
		'Strict release certification requires a non-empty dist/evidence/manual-runtime.log.'
	);
	$record(
		'strict_manual_runtime_source_sha_matches_head',
		(bool) preg_match( '/^[a-f0-9]{40}$/', $current_sha )
			&& hash_equals( $current_sha, (string) ( $evidence['source_sha'] ?? '' ) ),
		'Manual runtime evidence source_sha must equal the exact current git HEAD.'
	);
	$record(
		'strict_manual_runtime_zip_sha_matches_exact_zip',
		is_string( $zip_sha )
			&& (bool) preg_match( '/^[a-f0-9]{64}$/', $zip_sha )
			&& hash_equals( $zip_sha, (string) ( $evidence['zip_sha256'] ?? '' ) ),
		'Manual runtime evidence zip_sha256 must equal a fresh SHA-256 of the exact current-version ZIP.'
	);

	$bad_environments = array();
	foreach ( $required_environments as $key => $label ) {
		$status   = (string) ( $evidence['environments'][ $key ]['status'] ?? '' );
		$proof    = (string) ( $evidence['environments'][ $key ]['evidence'] ?? '' );
		if ( 'PASS' !== $status || '' === trim( $proof ) ) {
			$bad_environments[] = $label . '=' . ( '' !== $status ? $status : 'MISSING' );
		}
	}
	$record(
		'strict_six_manual_runtime_environments_pass',
		0 === count( $bad_environments ),
		'All six canonical manual runtime environments must be PASS with non-empty evidence references. Invalid: ' . implode( ', ', $bad_environments )
	);
} else {
	$record(
		'exact_release_execution_required_only_in_strict_certification',
		true,
		'Normal source CI validates the immutable Phase 69 runbook. SSCRIBE_RELEASE_CERTIFICATION=1 requires exact-SHA, exact-ZIP execution evidence for all six environments.'
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

echo "=== SScribe Manual Runtime Tests Acceptance ===\n\n";
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
	echo "PASS: All six manual runtime environments are certified for the exact current source SHA and ZIP.\n";
} else {
	echo "PASS: Manual runtime runbook contract valid; exact-release execution is enforced in strict certification.\n";
}
exit( 0 );
