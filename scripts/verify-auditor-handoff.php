<?php
/**
 * Phase 74 — Required handoff to independent auditor contract.
 *
 * Asserts the canonical auditor-handoff protocol
 * (docs/AUDITOR_HANDOFF_v2.0.0.md) is complete and declares
 * every canonical handoff artifact + verification recipe.
 *
 * Rules:
 *
 *   1. Handoff protocol doc exists.
 *   2. Handoff doc declares canonical sections (Why this exists,
 *      Canonical handoff artifacts, Verification recipe per
 *      artifact, How an independent auditor verifies this).
 *   3. Every canonical handoff artifact is listed.
 *   4. Every canonical handoff artifact exists on disk and is
 *      non-empty (so the auditor can actually open them).
 *   5. The exact release ZIP at `dist/{name}-{VERSION}.zip` has
 *      a matching SHA-256 sidecar at `dist/{name}-{VERSION}.sha256`
 *      (the canonical `dist/{name}-{version}.{ext}` convention —
 *      not the broken `dist/{name}-{version}.zip.sha256` form).
 *   6. Integration test exists.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

if ( 'cli' !== php_sapi_name() ) {
	exit( 'This script must be run from the command line.' );
}

$root_dir      = dirname( __DIR__ );
$handoff_doc   = $root_dir . '/docs/AUDITOR_HANDOFF_v2.0.0.md';
$manifest_path = $root_dir . '/dist/auditor-handoff-manifest.json';

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
	'handoff_doc_exists',
	is_file( $handoff_doc ),
	'docs/AUDITOR_HANDOFF_v2.0.0.md must exist so the Phase 74 auditor handoff protocol is auditable.'
);

$canonical_handoff_artifacts = array(
	'docs/RELEASE_BLOCKERS_v2.0.0.md',
	'docs/FINAL_CI_STATE_v2.0.0.md',
	'docs/EXACT_ARTIFACT_EVIDENCE_v2.0.0.md',
	'docs/RELEASE_REPORT_v2.0.0.md',
	'docs/BRANCH_PROTECTION_v2.0.0.md',
	'docs/TAG_POLICY_v2.0.0.md',
	'docs/RELEASE_PIPELINE_v2.0.0.md',
	'docs/ACCEPTANCE_MATRIX_v2.0.0.json',
	'dist/acceptance-matrix-manifest.json',
	'docs/BUILD_TRANSFORMATIONS.md',
	'docs/SECURITY_MATRIX_v2.0.0.md',
	'docs/PLUGIN_CHECK_WARNINGS_v2.0.0.md',
	'docs/MANUAL_RUNTIME_TESTS_v2.0.0.md',
	'docs/BRANCH_POLICY_v2.0.0.md',
	'dist/branch-policy-manifest.json',
);

if ( is_file( $handoff_doc ) ) {
	$doc_src = (string) file_get_contents( $handoff_doc );

	$canonical_sections = array(
		'## Why this exists',
		'## Canonical handoff artifacts',
		'## Verification recipe per artifact',
		'## How an independent auditor verifies this',
	);
	$missing_sections   = array();
	foreach ( $canonical_sections as $section ) {
		if ( false === strpos( $doc_src, $section ) ) {
			$missing_sections[] = $section;
		}
	}
	$record(
		'handoff_doc_has_canonical_sections',
		0 === count( $missing_sections ),
		'Auditor handoff doc is missing canonical sections: ' . implode( ', ', $missing_sections )
	);

	// Every canonical handoff artifact must appear (case-insensitive substring match).
	$missing_artifacts = array();
	foreach ( $canonical_handoff_artifacts as $expected ) {
		if ( false === stripos( $doc_src, $expected ) ) {
			$missing_artifacts[] = $expected;
		}
	}
	$record(
		'every_canonical_handoff_artifact_listed',
		0 === count( $missing_artifacts ),
		'Every canonical handoff artifact must appear in the handoff doc. Missing: ' . implode( ', ', $missing_artifacts )
	);

	// Rule 4 (new): every canonical handoff artifact must EXIST on
	// disk AND be non-empty. A doc that lists artifacts but ships
	// empty placeholders defeats the audit-trail claim.
	$missing_on_disk = array();
	foreach ( $canonical_handoff_artifacts as $rel ) {
		$abs = $root_dir . '/' . $rel;
		if ( ! is_file( $abs ) || 0 === filesize( $abs ) ) {
			$missing_on_disk[] = $rel;
		}
	}
	$record(
		'every_canonical_handoff_artifact_exists_and_nonempty',
		0 === count( $missing_on_disk ),
		'Every canonical handoff artifact must exist on disk and be non-empty. Empty: ' . implode( ', ', $missing_on_disk )
	);

	// Rule 5 (new): the exact release ZIP must have a matching
	// SHA-256 sidecar using the canonical `dist/{name}-{version}.sha256`
	// convention (NOT the broken `dist/{name}-{version}.zip.sha256`
	// form, which double-exts the archive). We resolve {name} and
	// {version} from the main plugin file's Plugin Name + Version
	// headers so the contract pins to the shipped package, not to
	// whatever happens to live in dist/.
	$main_file = $root_dir . '/sscribe-export-site-pages.php';
	if ( ! is_file( $main_file ) ) {
		$record(
			'exact_zip_sha256_sidecar_present',
			false,
			'sscribe-export-site-pages.php must exist so the ZIP+SHA pairing rule can resolve the canonical artifact name.'
		);
	} else {
		$main_src    = (string) file_get_contents( $main_file );
		$name_match  = array();
		$ver_match   = array();
		$dom_match   = array();
		preg_match( '/^\s*\*?\s*Plugin Name:\s*(.+)$/m', $main_src, $name_match );
		preg_match( '/^\s*\*?\s*Version:\s*(.+)$/m', $main_src, $ver_match );
		preg_match( '/^\s*\*?\s*Text Domain:\s*([^\s*]+)/m', $main_src, $dom_match );
		$plugin_name = isset( $name_match[1] ) ? trim( $name_match[1] ) : '';
		$plugin_ver  = isset( $ver_match[1] ) ? trim( $ver_match[1] ) : '';
		// The canonical slug is the Text Domain header — the displayed
		// Plugin Name may contain spaces / capitals, but the ZIP is
		// always named after the slug, not the display name.
		$plugin_slug = isset( $dom_match[1] ) ? trim( $dom_match[1] ) : '';

		if ( '' === $plugin_slug || '' === $plugin_ver ) {
			$record(
				'exact_zip_sha256_sidecar_present',
				false,
				'Text Domain + Version headers must be declared in the mainfile so the ZIP+SHA pairing rule can resolve the canonical artifact slug.'
			);
		} else {
			$zip_path  = $root_dir . '/dist/' . $plugin_slug . '-' . $plugin_ver . '.zip';
			$sha_path  = $root_dir . '/dist/' . $plugin_slug . '-' . $plugin_ver . '.sha256';
			$broken_a  = $root_dir . '/dist/' . $plugin_slug . '-' . $plugin_ver . '.zip.sha256';
			$broken_b  = $root_dir . '/dist/' . $plugin_slug . '-' . $plugin_ver . '.zip.sha';

			$zip_exists = is_file( $zip_path );
			$sha_exists = is_file( $sha_path );
			$has_broken = is_file( $broken_a ) || is_file( $broken_b );

			$record(
				'exact_zip_sha256_sidecar_present',
				$zip_exists && $sha_exists && ! $has_broken,
				sprintf(
					'Expected dist/%s-%s.zip + dist/%s-%s.sha256 (canonical sidecar convention, derived from Text Domain). Found: zip=%s, sha256=%s, broken_double_ext=%s',
					$plugin_slug,
					$plugin_ver,
					$plugin_slug,
					$plugin_ver,
					$zip_exists ? 'YES' : 'NO',
					$sha_exists ? 'YES' : 'NO',
					$has_broken ? 'YES (forbidden)' : 'NO'
				)
			);
		}
	}
}

$test_path = $root_dir . '/tests/Integration/SScribe_Auditor_Handoff_Test.php';
$record(
	'integration_test_exists',
	is_file( $test_path ),
	'tests/Integration/SScribe_Auditor_Handoff_Test.php must exist so the auditor-handoff contract is pinned at the PHPUnit boundary.'
);

// Rule 6 (new): a unit-style integration test must also pin the
// ZIP+SHA sidecar pair so the canonical naming convention is
// enforced at the PHPUnit boundary, not just at the verifier.
$zip_sha_pair_test = $root_dir . '/tests/Integration/SScribe_Exact_Artifact_Evidence_Test.php';
$record(
	'zip_sha_pair_test_pins_canonical_naming',
	is_file( $zip_sha_pair_test ),
	'tests/Integration/SScribe_Exact_Artifact_Evidence_Test.php must exist so the canonical dist/{name}-{version}.{ext} naming convention is pinned at the PHPUnit boundary.'
);

// Persist manifest.
$manifest_dir = dirname( $manifest_path );
if ( ! is_dir( $manifest_dir ) ) {
	mkdir( $manifest_dir, 0755, true );
}
$manifest = array(
	'generated_at'  => gmdate( 'c' ),
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

echo "=== SScribe Auditor Handoff Acceptance ===\n\n";
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
echo "✓ Auditor handoff protocol contract valid.\n";
exit( 0 );
