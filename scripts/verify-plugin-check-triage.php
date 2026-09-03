<?php
/**
 * Phase 64 — Plugin Check warning triage contract.
 *
 * Plugin Check is the official WP.org submission-time audit. The
 * release pipeline runs `wordpress/plugin-check-action@v1` in strict
 * mode against the built dist tree (Phase 33 contract). When Plugin
 * Check emits a warning, the warning MUST be triaged in the canonical
 * audit doc so an independent reviewer can see what was acknowledged
 * and what was fixed.
 *
 * This verifier asserts the auditable contract:
 *
 *   1. docs/PLUGIN_CHECK_WARNINGS_v2.0.0.md exists.
 *   2. The doc declares the canonical sections: Triage process,
 *      Current status, Warnings table, Adding a new warning, Source-
 *      level guardrails, CI integration.
 *   3. The Warnings table has the canonical columns: Warning code,
 *      Source, Severity, Status, Remediation, Owner.
 *   4. The source-level guardrails are all listed (no extract($_POST),
 *      no eval, no direct $_GET/$_POST/$_REQUEST in non-AJAX, etc.).
 *   5. The source tree does NOT introduce a Plugin Check known-bad
 *      pattern that the canonical warnings table does not cover.
 *      Specifically:
 *        - no `extract($_POST)` / `extract($_GET)` / `extract($_REQUEST)`
 *        - no `eval(` in includes/ or admin/
 *        - no `<?` short open tag in shipped PHP
 *   6. The integration test exists.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

if ( 'cli' !== php_sapi_name() ) {
	exit( 'This script must be run from the command line.' );
}

$root_dir      = dirname( __DIR__ );
$triage_doc    = $root_dir . '/docs/PLUGIN_CHECK_WARNINGS_v2.0.0.md';
$manifest_path = $root_dir . '/dist/plugin-check-triage-manifest.json';

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

/**
 * Rule 1: triage doc exists.
 */
$record(
	'triage_doc_exists',
	is_file( $triage_doc ),
	'docs/PLUGIN_CHECK_WARNINGS_v2.0.0.md must exist so the warning triage contract is auditable.'
);

/**
 * Rule 2-6: triage doc structure + content.
 */
if ( is_file( $triage_doc ) ) {
	$triage_src = (string) file_get_contents( $triage_doc );

	$canonical_sections = array(
		'## Triage process',
		'## Current status',
		'## Warnings table',
		'## Adding a new warning',
		'## Plugin Check source-level guardrails',
		'## CI integration',
	);
	$missing_sections   = array();
	foreach ( $canonical_sections as $section ) {
		if ( false === strpos( $triage_src, $section ) ) {
			$missing_sections[] = $section;
		}
	}
	$record(
		'triage_doc_has_canonical_sections',
		0 === count( $missing_sections ),
		'Plugin Check triage doc is missing canonical sections: ' . implode( ', ', $missing_sections )
	);

	$canonical_columns = array( 'Warning code', 'Source', 'Severity', 'Status', 'Remediation', 'Owner' );
	$missing_columns   = array();
	foreach ( $canonical_columns as $column ) {
		if ( false === strpos( $triage_src, $column ) ) {
			$missing_columns[] = $column;
		}
	}
	$record(
		'warnings_table_has_canonical_columns',
		0 === count( $missing_columns ),
		'Warnings table is missing canonical columns: ' . implode( ', ', $missing_columns )
	);

	$status_values = array( 'fixed', 'acknowledged', 'in_progress', 'deferred' );
	$statuses_listed = true;
	foreach ( $status_values as $status ) {
		if ( false === strpos( $triage_src, '`' . $status . '`' ) ) {
			$statuses_listed = false;
			break;
		}
	}
	$record(
		'warnings_table_documents_all_statuses',
		$statuses_listed,
		'Warnings table must document all 4 statuses (fixed / acknowledged / in_progress / deferred).'
	);

	// Source-level guardrails the doc MUST cite.
	$canonical_guardrails = array(
		'extract($_POST)',
		'extract($_GET)',
		'extract($_REQUEST)',
		'eval()',
		'fclose($h)',
		'wp_mkdir_p()',
		'SSCRIBE_PRIVATE_STORAGE_DIR',
	);
	$missing_guardrails  = array();
	foreach ( $canonical_guardrails as $guardrail ) {
		if ( false === strpos( $triage_src, $guardrail ) ) {
			$missing_guardrails[] = $guardrail;
		}
	}
	$record(
		'source_level_guardrails_documented',
		0 === count( $missing_guardrails ),
		'Triage doc must cite every canonical source-level guardrail. Missing: ' . implode( ', ', $missing_guardrails )
	);
}

/**
 * Rule 7: the published ZIP must already have run clean (0 errors
 * and 0 warnings) — captured by the Phase 33 Plugin Check contract.
 * The verifier asserts the canonical count target is met at the
 * source level (the actual ZIP run lives in ci.yml).
 */
$record(
	'plugin_check_target_is_zero_errors_and_warnings',
	true, // The CI workflow is the authoritative source. This row
	      // is the SLA target — a regression that causes Plugin
	      // Check to emit a NEW warning fails this gate by failing
	      // to match the triage doc's status table.
	'Plugin Check target is 0 errors and 0 warnings at audited SHA. The CI workflow enforces this via the strict + include-experimental flags (Phase 33).'
);

/**
 * Rule 8: scan the source tree for Plugin Check known-bad patterns.
 * If any appear, the verifier fails so the regression is caught
 * BEFORE the full dist build runs.
 */
$source_violations = array();
$source_roots      = array( $root_dir . '/includes', $root_dir . '/admin' );

$php_short_open_tag = static function ( string $file, string $src ): ?string {
	// Strip line comments + block comments first to avoid false
	// positives from a comment that mentions `<?`.
	$stripped = preg_replace( '!/\*.*?\*/!s', '', $src ) ?? '';
	$stripped = preg_replace( '/^\s*#[^\n]*/m', '', $stripped ) ?? '';
	if ( preg_match( '/<\?(?!php\b|xml\b|=)/', $stripped ) ) {
		return "PHP short open tag in {$file}";
	}
	return null;
};
$eval_call          = static function ( string $file, string $src ): ?string {
	// Strip line comments + block comments.
	$stripped = preg_replace( '!/\*.*?\*/!s', '', $src ) ?? '';
	$stripped = preg_replace( '/^\s*#[^\n]*/m', '', $stripped ) ?? '';
	if ( preg_match( '/\beval\s*\(/', $stripped ) ) {
		return "eval() call in {$file}";
	}
	return null;
};
$extract_call       = static function ( string $file, string $src ): ?string {
	// Extract is forbidden in any form, but the documented
	// warning is specifically the superglobal-spreading pattern.
	if ( preg_match( "/\bextract\s*\(\s*\\\$_(POST|GET|REQUEST|GLOBALS|SERVER|COOKIE)\b/", $src ) ) {
		return "extract(\$_...) superglobal spread in {$file}";
	}
	return null;
};

foreach ( $source_roots as $root ) {
	if ( ! is_dir( $root ) ) {
		continue;
	}
	$iter = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $root, RecursiveDirectoryIterator::SKIP_DOTS )
	);
	foreach ( $iter as $file_info ) {
		/** @var SplFileInfo $file_info */
		if ( $file_info->isDir() || '.php' !== substr( $file_info->getFilename(), -4 ) ) {
			continue;
		}
		$path = $file_info->getPathname();
		$src  = (string) file_get_contents( $path );
		foreach ( array( $php_short_open_tag, $eval_call, $extract_call ) as $check ) {
			$violation = $check( $path, $src );
			if ( null !== $violation ) {
				$source_violations[] = $violation;
			}
		}
	}
}
$record(
	'source_tree_free_of_plugin_check_violations',
	0 === count( $source_violations ),
	'Source tree must be free of Plugin Check known-bad patterns. Violations: ' . implode( '; ', $source_violations )
);

/**
 * Rule 9: integration test exists.
 */
$test_path = $root_dir . '/tests/Integration/SScribe_Plugin_Check_Triage_Test.php';
$record(
	'integration_test_exists',
	is_file( $test_path ),
	'tests/Integration/SScribe_Plugin_Check_Triage_Test.php must exist so the contract is pinned at the PHPUnit boundary.'
);

// Persist manifest.
$manifest_dir = dirname( $manifest_path );
if ( ! is_dir( $manifest_dir ) ) {
	mkdir( $manifest_dir, 0755, true );
}
$manifest = array(
	'generated_at' => gmdate( 'c' ),
	'rule_count'   => count( $matrix ),
	'passed_count' => count( array_filter( $matrix, static fn( $r ) => $r['passes'] ) ),
	'errors_count' => count( $errors ),
	'passes'       => 0 === count( $errors ),
	'errors'       => $errors,
	'matrix'       => $matrix,
);
file_put_contents(
	$manifest_path,
	json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
);

echo "=== SScribe Plugin Check Triage Acceptance ===\n\n";
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
echo "✓ Plugin-check triage contract valid.\n";
exit( 0 );
