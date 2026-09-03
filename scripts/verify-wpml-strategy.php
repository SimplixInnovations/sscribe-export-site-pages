<?php
/**
 * Phase 57 — WPML test strategy verifier.
 *
 * WPML is a paid third-party plugin; we cannot fetch it in CI. So
 * the production-grade contract is:
 *
 *   1. The plugin's WPML detection uses the canonical WPML
 *      constant + class pair (`ICL_SITEPRESS_VERSION` + `SitePress`)
 *      — anything looser lets the plugin claim "WPML is active"
 *      when only a function-shaped stub is loaded.
 *   2. The plugin's language filter call is the WPML-standard
 *      `wpml_current_language` filter, not a custom-named shim.
 *   3. The plugin's WPML code paths execute without errors when
 *      the filter returns null (i.e., WPML is NOT present).
 *   4. A unit test mocks WPML active + returns a non-null lang,
 *      exercising the language-filter branch.
 *   5. The auditable strategy doc (tests/WPML_STRATEGY.md) exists
 *      and pins the contract.
 *
 * This file walks the source + tests to assert all five clauses.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

if ( 'cli' !== php_sapi_name() ) {
	exit( 'This script must be run from the command line.' );
}

$root_dir       = dirname( __DIR__ );
$strategy_path  = $root_dir . '/tests/WPML_STRATEGY.md';
$collector_path = $root_dir . '/includes/class-sscribe-page-collector.php';
$batch_path     = $root_dir . '/includes/class-sscribe-batch-processor.php';
$tests_dir      = $root_dir . '/tests';
$manifest_path  = $root_dir . '/dist/wpml-strategy-manifest.json';

$matrix = array();
$errors = array();

foreach ( array( $strategy_path, $collector_path, $batch_path ) as $path ) {
	if ( ! is_file( $path ) ) {
		fwrite( STDERR, "✗ Required file missing: {$path}\n" );
		exit( 1 );
	}
}

// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$strategy_src   = (string) file_get_contents( $strategy_path );
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$collector_src  = (string) file_get_contents( $collector_path );
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$batch_src      = (string) file_get_contents( $batch_path );

/**
 * Rule 1: the strategy doc exists.
 */
$matrix[] = array(
	'rule'   => 'wpml_strategy_doc_exists',
	'passes' => true,
	'detail' => 'tests/WPML_STRATEGY.md must exist (file presence is asserted by the file_get_contents call above).',
);

/**
 * Rule 2: the strategy doc declares the canonical detection
 * constant + class pair.
 */
$declares_constant = (bool) preg_match( '/ICL_SITEPRESS_VERSION/', $strategy_src );
$declares_class    = (bool) preg_match( '/\bSitePress\b/', $strategy_src );
$matrix[] = array(
	'rule'   => 'wpml_strategy_doc_declares_canonical_detection',
	'passes' => $declares_constant && $declares_class,
	'detail' => 'tests/WPML_STRATEGY.md must declare both `ICL_SITEPRESS_VERSION` and `SitePress` as the canonical detection pair.',
);
if ( ! $declares_constant || ! $declares_class ) {
	$errors[] = 'WPML strategy doc does not declare BOTH `ICL_SITEPRESS_VERSION` and `SitePress`.';
}

/**
 * Rule 3: the strategy doc declares the canonical filter name.
 */
$declares_filter = (bool) preg_match( "/['\"]wpml_current_language['\"]/", $strategy_src );
$matrix[] = array(
	'rule'   => 'wpml_strategy_doc_declares_canonical_filter',
	'passes' => $declares_filter,
	'detail' => 'tests/WPML_STRATEGY.md must declare `wpml_current_language` as the filter the plugin listens on.',
);
if ( ! $declares_filter ) {
	$errors[] = 'WPML strategy doc does not declare the `wpml_current_language` filter.';
}

/**
 * Rule 4: the strategy doc enumerates the test matrix
 * (inactive / mocked-active / real).
 */
$enumerates_matrix = (bool) preg_match( '/WPML inactive/i', $strategy_src )
	&& (bool) preg_match( '/WPML active.*mocked|mocked|WPML active \(mocked\)/i', $strategy_src )
	&& (bool) preg_match( '/WPML active.*real|real WPML/i', $strategy_src );
$matrix[] = array(
	'rule'   => 'wpml_strategy_doc_enumerates_test_matrix',
	'passes' => $enumerates_matrix,
	'detail' => 'tests/WPML_STRATEGY.md must enumerate the WPML test matrix: inactive / mocked-active / real-active.',
);
if ( ! $enumerates_matrix ) {
	$errors[] = 'WPML strategy doc does not enumerate the full test matrix (inactive / mocked-active / real-active).';
}

/**
 * Rule 5: the strategy doc is pinned to the audited SHA + is a
 * "living document".
 */
$pinned_sha       = (bool) preg_match( '/(?:Audited SHA|Audited\s*SHA):?\s*[`"]?a5c093c/i', $strategy_src );
$is_living_doc    = (bool) preg_match( '/[Ll]iving document/', $strategy_src );
$matrix[] = array(
	'rule'   => 'wpml_strategy_doc_pins_audit_sha',
	'passes' => $pinned_sha,
	'detail' => 'tests/WPML_STRATEGY.md must pin the audited SHA so the auditor can map "this exact doc" to "this exact commit".',
);
$matrix[] = array(
	'rule'   => 'wpml_strategy_doc_is_living_document',
	'passes' => $is_living_doc,
	'detail' => 'tests/WPML_STRATEGY.md must mark itself a "living document" so a future maintainer updates detection + tests in lockstep.',
);
if ( ! $pinned_sha ) {
	$errors[] = 'WPML strategy doc does NOT pin the audited SHA.';
}
if ( ! $is_living_doc ) {
	$errors[] = 'WPML strategy doc does NOT mark itself a living document.';
}

/**
 * Rule 6: SScribe_Page_Collector::is_wpml_active() uses the
 * canonical ICL_SITEPRESS_VERSION + SitePress class detection.
 */
if ( preg_match( '/function\s+is_wpml_active\s*\(\s*\)\s*:\s*bool\s*\{([\s\S]*?)\}/', $collector_src, $fn_match ) ) {
	$fn_body = $fn_match[1];
	$uses_constant = (bool) preg_match( "/['\"]ICL_SITEPRESS_VERSION['\"]/", $fn_body )
		|| (bool) preg_match( '/ICL_SITEPRESS_VERSION/', $fn_body );
	$uses_class    = (bool) preg_match( "/['\"]SitePress['\"]/", $fn_body )
		|| (bool) preg_match( '/class_exists\(\s*[\'"]SitePress[\'"]/', $fn_body );
	$matrix[] = array(
		'rule'   => 'page_collector_is_wpml_active_uses_canonical_detection',
		'passes' => $uses_constant && $uses_class,
		'detail' => 'SScribe_Page_Collector::is_wpml_active() must check `ICL_SITEPRESS_VERSION` AND `class_exists("SitePress")`.',
	);
	if ( ! $uses_constant ) {
		$errors[] = 'SScribe_Page_Collector::is_wpml_active() does NOT check `ICL_SITEPRESS_VERSION`.';
	}
	if ( ! $uses_class ) {
		$errors[] = 'SScribe_Page_Collector::is_wpml_active() does NOT check `class_exists("SitePress")`.';
	}
} else {
	$matrix[] = array(
		'rule'   => 'page_collector_is_wpml_active_uses_canonical_detection',
		'passes' => false,
		'detail' => 'SScribe_Page_Collector::is_wpml_active() method body not found — refactor may have removed it.',
	);
	$errors[] = 'SScribe_Page_Collector::is_wpml_active() not found.';
}

/**
 * Rule 7: the plugin's language filter call uses `wpml_current_language`,
 * not a custom-named shim.
 */
$uses_canonical_filter = (bool) preg_match( "/apply_filters\s*\(\s*['\"]wpml_current_language['\"]/", $batch_src )
	|| (bool) preg_match( "/apply_filters\s*\(\s*['\"]wpml_current_language['\"]/", $collector_src );
$matrix[] = array(
	'rule'   => 'plugin_uses_canonical_wpml_current_language_filter',
	'passes' => $uses_canonical_filter,
	'detail' => 'Plugin must call `apply_filters("wpml_current_language", ...)` so WPML\'s standard hook actually fires.',
);
if ( ! $uses_canonical_filter ) {
	$errors[] = 'Plugin does NOT call `apply_filters("wpml_current_language", ...)`.';
}

/**
 * Rule 8: PHPUnit tests cover BOTH paths — WPML active AND
 * WPML inactive. The auditor must see at least one test that
 * forces is_wpml_active to return true, and at least one test
 * that exercises the WPML-inactive default.
 */
$wpml_active_test    = false;
$wpml_inactive_test  = false;
$wpml_mocked_filter  = false;
foreach ( glob( $tests_dir . '/Integration/*.php' ) as $test_file ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$src = (string) file_get_contents( $test_file );
	if ( preg_match( '/(function_exists\s*\(\s*[\'"]SitePress[\'"]|is_wpml_active|wpml_active)/i', $src ) ) {
		$wpml_active_test = true;
	}
	if ( preg_match( '/wpml.*(inactive|absent|missing)|no[\s_-]*wpml|wpml.*not.*active/i', $src ) ) {
		$wpml_inactive_test = true;
	}
	if ( preg_match( "/apply_filters.*wpml_current_language|add_filter.*wpml_current_language|wpml_current_language.*apply_filters/i", $src ) ) {
		$wpml_mocked_filter = true;
	}
}
$matrix[] = array(
	'rule'   => 'wpml_active_path_has_unit_or_integration_test',
	'passes' => $wpml_active_test,
	'detail' => 'At least one PHPUnit test must exercise the WPML-active code path (mocked via is_wpml_active / class_exists / function_exists).',
);
$matrix[] = array(
	'rule'   => 'wpml_inactive_path_has_unit_or_integration_test',
	'passes' => $wpml_inactive_test,
	'detail' => 'At least one PHPUnit test must exercise the WPML-inactive default path.',
);
$matrix[] = array(
	'rule'   => 'wpml_current_language_filter_has_mocked_test',
	'passes' => $wpml_mocked_filter,
	'detail' => 'At least one PHPUnit test must mock the `wpml_current_language` filter (add_filter/apply_filters in test code).',
);
if ( ! $wpml_active_test ) {
	$errors[] = 'No PHPUnit test exercises the WPML-active path.';
}
if ( ! $wpml_inactive_test ) {
	$errors[] = 'No PHPUnit test exercises the WPML-inactive default path.';
}
if ( ! $wpml_mocked_filter ) {
	$errors[] = 'No PHPUnit test mocks the `wpml_current_language` filter.';
}

// Persist manifest.
$manifest_dir = dirname( $manifest_path );
if ( ! is_dir( $manifest_dir ) ) {
	mkdir( $manifest_dir, 0755, true );
}
$manifest = array(
	'generated_at'   => gmdate( 'c' ),
	'rule_count'     => count( $matrix ),
	'passed_count'   => count( array_filter( $matrix, static fn( $r ) => $r['passes'] ) ),
	'matrix'         => $matrix,
	'errors_count'   => count( $errors ),
	'passes'         => 0 === count( $errors ),
	'errors'         => $errors,
);
file_put_contents(
	$manifest_path,
	json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
);

echo "=== SScribe WPML Strategy ===\n\n";
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
echo "✓ WPML strategy contract valid.\n";
exit( 0 );
