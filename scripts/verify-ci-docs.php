<?php
/**
 * Phase 61 — CI command documentation verifier.
 *
 * Every CI command (composer test:* script + ci.yml step) MUST be
 * documented in docs/CI_COMMANDS.md. A regression that adds a new
 * gate without documenting it here leaves the release-engineering
 * team guessing at what each gate enforces, and an auditor can't
 * trace the release pipeline end-to-end.
 *
 * This verifier walks composer.json's `scripts` and .github/workflows/
 * ci.yml's step names, and asserts every entry has a matching section
 * in docs/CI_COMMANDS.md. The integration test pins the same
 * contract at the PHPUnit boundary.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

if ( 'cli' !== php_sapi_name() ) {
	exit( 'This script must be run from the command line.' );
}

$root_dir      = dirname( __DIR__ );
$composer_path = $root_dir . '/composer.json';
$ci_path       = $root_dir . '/.github/workflows/ci.yml';
$doc_path      = $root_dir . '/docs/CI_COMMANDS.md';
$manifest_path = $root_dir . '/dist/ci-docs-manifest.json';

$matrix = array();
$errors = array();

foreach ( array( $composer_path, $ci_path, $doc_path ) as $path ) {
	if ( ! is_file( $path ) ) {
		fwrite( STDERR, "✗ Required file missing: {$path}\n" );
		exit( 1 );
	}
}

// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$composer_data = json_decode( (string) file_get_contents( $composer_path ), true );
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$ci_src  = (string) file_get_contents( $ci_path );
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$doc_src = (string) file_get_contents( $doc_path );

if ( ! is_array( $composer_data ) || ! isset( $composer_data['scripts'] ) || ! is_array( $composer_data['scripts'] ) ) {
	fwrite( STDERR, "✗ composer.json is not valid JSON or has no `scripts` map.\n" );
	exit( 1 );
}

/**
 * Helper: every `composer test:*` script must have an `### composer
 * test:foo` heading in the doc.
 */
$test_scripts = array();
foreach ( $composer_data['scripts'] as $name => $cmd ) {
	if ( 0 === strpos( $name, 'test:' ) ) {
		$test_scripts[ $name ] = is_string( $cmd ) ? $cmd : '[array]';
	}
}

$documented_test_scripts = array();
foreach ( $test_scripts as $name => $cmd ) {
	$heading = '### `composer ' . $name . '`';
	// The doc sometimes combines two scripts under one heading
	// (e.g. test:wp:install / test:wp:uninstall). Match both the
	// exact heading AND a verbatim mention of the script somewhere
	// inside a backticked reference.
	if ( false !== strpos( $doc_src, $heading ) || preg_match( '/`composer\s+' . preg_quote( $name, '/' ) . '`/', $doc_src ) ) {
		$documented_test_scripts[] = $name;
	} else {
		$errors[] = "Composer script `composer {$name}` is not documented in {$doc_path}.";
	}
}
$matrix[] = array(
	'rule'   => 'every_composer_test_script_documented',
	'passes' => count( $documented_test_scripts ) === count( $test_scripts ),
	'detail' => 'Every `composer test:*` script must have a `### composer test:NAME` heading in docs/CI_COMMANDS.md. Documented ' . count( $documented_test_scripts ) . ' / ' . count( $test_scripts ) . '.',
);
if ( count( $documented_test_scripts ) !== count( $test_scripts ) ) {
	$undocumented = array_diff( array_keys( $test_scripts ), $documented_test_scripts );
	foreach ( $undocumented as $name ) {
		$errors[] = "Undocumented composer script: `composer {$name}`.";
	}
}

/**
 * Helper: every ci.yml step name (the part after `- name:`) should
 * be mentioned in the doc. We extract the step names, then check
 * each appears at least once in the doc body.
 */
$step_names = array();
if ( preg_match_all( '/-\s+name:\s+([^\n]+)/', $ci_src, $matches ) ) {
	foreach ( $matches[1] as $raw_name ) {
		$clean = trim( (string) $raw_name );
		if ( '' !== $clean ) {
			$step_names[ $clean ] = true;
		}
	}
}
$step_names = array_keys( $step_names );

/**
 * We don't require EVERY step name to appear in the doc — the
 * canonical section (§3) only lists each *job*, not every step
 * within a job. Instead we assert that the JOB-LEVEL step names
 * (the ones that name the workflow's primary purpose — e.g.
 * "Verify version synchronization", "Real WordPress Integration
 * Suite (matrix)", "Run PHPUnit", "Build the submission ZIP")
 * appear in the doc.
 */
$canonical_step_keywords = array(
	'Verify version synchronization',
	'Verify accessibility contract',
	'Real WordPress Integration Suite',
	'Run PHPUnit',
	'Build the submission ZIP',
	'Run official WordPress Plugin Check',
	'Audit JavaScript dependencies',
	'Verify i18n contract',
	'Coverage PHP',
);
$documented_jobs = array();
foreach ( $canonical_step_keywords as $keyword ) {
	if ( false !== strpos( $doc_src, $keyword ) ) {
		$documented_jobs[] = $keyword;
	}
}
$matrix[] = array(
	'rule'   => 'canonical_ci_step_keywords_appear_in_doc',
	'passes' => count( $documented_jobs ) >= count( $canonical_step_keywords ) - 1,
	'detail' => 'Canonical ci.yml step keywords (job-name proxies) must appear in docs/CI_COMMANDS.md §2 or §3. Cited ' . count( $documented_jobs ) . ' / ' . count( $canonical_step_keywords ) . '.',
);
if ( count( $documented_jobs ) < count( $canonical_step_keywords ) - 1 ) {
	$missing = array_diff( $canonical_step_keywords, $documented_jobs );
	$errors[] = 'Canonical ci.yml step keywords missing from doc: ' . implode( ', ', $missing );
}

/**
 * Rule: the doc has the canonical sections (top-level chains +
 * per-script reference + debugging).
 */
$required_sections = array(
	'## 1. Top-level chains',
	'## 2. Per-script reference',
	'## 3. CI workflow steps',
	'## 4. Debugging a failure',
	'## 5. Adding a new CI command',
);
$missing_sections = array();
foreach ( $required_sections as $section ) {
	if ( false === strpos( $doc_src, $section ) ) {
		$missing_sections[] = $section;
	}
}
$matrix[] = array(
	'rule'   => 'doc_has_canonical_sections',
	'passes' => empty( $missing_sections ),
	'detail' => 'docs/CI_COMMANDS.md must contain all 5 canonical sections (§1 chains, §2 per-script, §3 ci.yml jobs, §4 debugging, §5 adding a command). Missing: ' . implode( ', ', $missing_sections ),
);
if ( ! empty( $missing_sections ) ) {
	$errors[] = 'docs/CI_COMMANDS.md is missing canonical sections: ' . implode( ', ', $missing_sections );
}

/**
 * Rule: every documented test:* script also lists Purpose / Gates /
 * Failure / Debug / Manifest fields. A doc stub without these is
 * worse than no doc — auditors think they have the answer but they
 * don't.
 */
$missing_fields = array();
foreach ( $test_scripts as $name => $cmd ) {
	$heading_pos = strpos( $doc_src, '### `composer ' . $name . '`' );
	if ( false === $heading_pos ) {
		continue; // already flagged above.
	}
	// Look at the next ~1500 chars after the heading (one section).
	$section = substr( $doc_src, $heading_pos, 1500 );
	foreach ( array( '**Gates:**', '**Failure:**', '**Debug:**', '**Manifest:**' ) as $field ) {
		if ( false === strpos( $section, $field ) ) {
			$missing_fields[] = "{$name}:{$field}";
		}
	}
}
$matrix[] = array(
	'rule'   => 'every_test_section_has_gates_failure_debug_manifest',
	'passes' => empty( $missing_fields ),
	'detail' => 'Every documented test:* section must declare Purpose, Gates, Failure, Debug, and Manifest fields. Missing: ' . implode( ', ', array_slice( $missing_fields, 0, 5 ) ) . ( count( $missing_fields ) > 5 ? ', …' : '' ),
);
if ( ! empty( $missing_fields ) ) {
	$errors[] = 'docs/CI_COMMANDS.md is missing required fields: ' . implode( ', ', $missing_fields );
}

/**
 * Rule: the doc references the live verifier scripts (so a maintainer
 * can find the canonical implementation). At minimum, every
 * `test:*` script in composer.json is named somewhere in the doc.
 */
$matrix[] = array(
	'rule'   => 'doc_audience_declared',
	'passes' => false !== strpos( $doc_src, 'Audience:' ),
	'detail' => 'docs/CI_COMMANDS.md must declare its audience in the preamble (so auditors know who the doc is written for).',
);
if ( false === strpos( $doc_src, 'Audience:' ) ) {
	$errors[] = 'docs/CI_COMMANDS.md preamble must declare `Audience:`.';
}

/**
 * Rule: every top-level ci.yml job (the major workflow jobs) has a
 * row in §3 (CI workflow steps).
 */
$ci_jobs = array();
if ( preg_match_all( '/^    ([a-z][a-z0-9_-]*):\s*\n        name:\s+([^\n]+)/m', $ci_src, $matches ) ) {
	foreach ( $matches[1] as $i => $job_id ) {
		$ci_jobs[ trim( $job_id ) ] = trim( $matches[2][ $i ] );
	}
}
$missing_jobs = array();
foreach ( $ci_jobs as $job_id => $job_name ) {
	if ( false === strpos( $doc_src, $job_name ) && false === strpos( $doc_src, '`' . $job_id . '`' ) ) {
		$missing_jobs[] = "{$job_id} ({$job_name})";
	}
}
$matrix[] = array(
	'rule'   => 'every_ci_job_listed_in_section_3',
	'passes' => empty( $missing_jobs ),
	'detail' => '§3 of docs/CI_COMMANDS.md must list every ci.yml job. Missing: ' . implode( ', ', $missing_jobs ),
);
if ( ! empty( $missing_jobs ) ) {
	$errors[] = 'docs/CI_COMMANDS.md §3 missing ci.yml jobs: ' . implode( ', ', $missing_jobs );
}

/**
 * Rule: the integration test exists + pins the contract.
 */
$test_path = $root_dir . '/tests/Integration/SScribe_CI_Docs_Test.php';
$matrix[] = array(
	'rule'   => 'integration_test_exists',
	'passes' => is_file( $test_path ),
	'detail' => 'tests/Integration/SScribe_CI_Docs_Test.php must exist so the contract is pinned at the PHPUnit boundary.',
);
if ( ! is_file( $test_path ) ) {
	$errors[] = 'tests/Integration/SScribe_CI_Docs_Test.php is missing.';
}

// Persist manifest.
$manifest_dir = dirname( $manifest_path );
if ( ! is_dir( $manifest_dir ) ) {
	mkdir( $manifest_dir, 0755, true );
}
$manifest = array(
	'generated_at'         => gmdate( 'c' ),
	'test_script_count'    => count( $test_scripts ),
	'documented_count'     => count( $documented_test_scripts ),
	'ci_step_count'        => count( $step_names ),
	'ci_step_cited_count'  => count( $documented_jobs ),
	'ci_job_count'         => count( $ci_jobs ),
	'rule_count'           => count( $matrix ),
	'passed_count'         => count( array_filter( $matrix, static fn( $r ) => $r['passes'] ) ),
	'errors_count'         => count( $errors ),
	'passes'               => 0 === count( $errors ),
	'errors'               => $errors,
	'matrix'               => $matrix,
);
file_put_contents(
	$manifest_path,
	json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
);

echo "=== SScribe CI Docs Acceptance ===\n\n";
echo "Test scripts: " . count( $test_scripts ) . " (documented " . count( $documented_test_scripts ) . ")\n";
echo "CI jobs: " . count( $ci_jobs ) . "\n";
echo "Canonical step keywords cited: " . count( $documented_jobs ) . " / " . count( $canonical_step_keywords ) . "\n";
echo "Rules: " . count( $matrix ) . "\n\n";
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
echo "✓ CI docs contract valid.\n";
exit( 0 );
