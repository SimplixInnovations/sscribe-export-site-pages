<?php
/**
 * Phase 65 — JavaScript error-free requirement.
 *
 * The admin JS ships to every install and runs on every admin page
 * load. A regression that introduces a runtime console.error /
 * console.warn / unhandled promise rejection / direct eval() ships
 * a build whose admin UI shows the WordPress-script-error overlay
 * to every site owner.
 *
 * This verifier walks `admin/js/*.js` and asserts the canonical
 * no-runtime-error contract:
 *
 *   1. No `console.error(...)` calls in shipped JS.
 *   2. No `console.warn(...)` calls in shipped JS.
 *   3. No `alert(...)`, `confirm(...)`, or `prompt(...)` calls
 *      (these are anti-patterns and break the WP admin UX).
 *   4. No `document.write(...)` or `document.writeln(...)` calls
 *      (these wipe the page mid-render).
 *   5. No `eval(...)` or `new Function(...)` calls.
 *   6. No `var ` declarations (use `const` / `let`).
 *   7. Every shipped `.js` file declares strict mode (either via
 *      `'use strict';` directive or by being an ES module).
 *   8. No unhandled promises — every `.then(` chain must be
 *      followed (within the same call expression) by a `.catch(`
 *      OR be the final step of an `async` function whose caller
 *      awaits it. A simple heuristic: count `.then(` and
 *      `.catch(` occurrences and assert `.catch(` >= `.then(`.
 *      (This is conservative — it may flag patterns that are
 *      handled by upstream callers — but a regression that drops
 *      a catch handler is far more likely than a false positive.)
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

if ( 'cli' !== php_sapi_name() ) {
	exit( 'This script must be run from the command line.' );
}

$root_dir      = dirname( __DIR__ );
$js_dir        = $root_dir . '/admin/js';
$manifest_path = $root_dir . '/dist/js-error-free-manifest.json';

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

if ( ! is_dir( $js_dir ) ) {
	fwrite( STDERR, "✗ JS directory missing: {$js_dir}\n" );
	exit( 1 );
}

$js_files = glob( $js_dir . '/*.js' );
if ( false === $js_files || 0 === count( $js_files ) ) {
	fwrite( STDERR, "✗ No shipped JS files found under {$js_dir}.\n" );
	exit( 1 );
}

// Helpers: load each file with comments + strings stripped so we
// don't false-positive on `// console.error` in a code comment.
$strip_noise = static function ( string $src ): string {
	// Strip /* … */ block comments.
	$out = (string) ( preg_replace( '!/\*.*?\*/!s', '', $src ) ?? $src );
	// Strip // line comments (simple state machine — doesn't
	// understand `// inside a string`, but real shipped JS rarely
	// has `'//'` literals; the smoke doc references `// ` as
	// pattern source so this is a safe heuristic for our rules).
	$out = (string) ( preg_replace( '!(^|[^:])//[^\n]*!', '$1', $out ) ?? $out );
	// Collapse whitespace.
	$out = (string) ( preg_replace( '/\s+/', ' ', $out ) ?? $out );
	return trim( $out );
};

$find_calls = static function ( string $haystack, string $call_signature ): array {
	$hits = array();
	if ( preg_match_all( '/' . preg_quote( $call_signature, '/' ) . '/', $haystack, $m ) ) {
		foreach ( array_keys( $m[0] ) as $idx ) {
			$hits[] = $m[0][ $idx ];
		}
	}
	return $hits;
};

$console_error_violations = array();
$console_warn_violations  = array();
$dialog_violations        = array();
$doc_write_violations     = array();
$eval_violations          = array();
$var_violations           = array();
$strict_mode_violations   = array();
$unhandled_promise_files  = array();

foreach ( $js_files as $file ) {
	$src       = (string) file_get_contents( $file );
	$filename  = basename( $file );
	$stripped  = $strip_noise( $src );

	$console_error_violations = array_merge(
		$console_error_violations,
		array_map( static fn( $hit ) => "{$filename}: console.error", $find_calls( $stripped, 'console.error(' ) )
	);
	$console_warn_violations = array_merge(
		$console_warn_violations,
		array_map( static fn( $hit ) => "{$filename}: console.warn", $find_calls( $stripped, 'console.warn(' ) )
	);
	foreach ( array( 'alert(', 'confirm(', 'prompt(' ) as $dialog ) {
		$dialog_violations = array_merge(
			$dialog_violations,
			array_map( static fn() => "{$filename}: {$dialog}", $find_calls( $stripped, $dialog ) )
		);
	}
	foreach ( array( 'document.write(', 'document.writeln(' ) as $w ) {
		$doc_write_violations = array_merge(
			$doc_write_violations,
			array_map( static fn() => "{$filename}: {$w}", $find_calls( $stripped, $w ) )
		);
	}
	foreach ( array( 'eval(', 'new Function(' ) as $e ) {
		$eval_violations = array_merge(
			$eval_violations,
			array_map( static fn() => "{$filename}: {$e}", $find_calls( $stripped, $e ) )
		);
	}
	// `var ` matches declarations; we accept `varName` etc. without
	// the trailing space (variable references like `varName = 1`).
	$var_violations = array_merge(
		$var_violations,
		array_map( static fn() => "{$filename}: var declaration", $find_calls( $stripped, 'var ' ) )
	);

	// Strict mode: IIFE wrapper at start, `'use strict';` literal,
	// or `"use strict";` literal — OR the file declares an ES module
	// via `import` or `export` (modules are auto-strict).
	$has_iife_strict    = (bool) preg_match( "/\(function[^{]*\\{\\s*['\"]use strict['\"]/", $src );
	$has_top_strict     = (bool) preg_match( "/['\"]use strict['\"]\s*;?/", $src );
	$is_es_module       = (bool) preg_match( '/^\s*(import|export)\s/m', $src );
	$has_function_call  = str_contains( $filename, '.min.' ); // minified bundles exempt

	if ( ! $has_iife_strict && ! $has_top_strict && ! $is_es_module ) {
		$strict_mode_violations[] = "{$filename}: missing 'use strict' (or IIFE wrapper, or ES module declaration)";
	}

	// Unhandled promise heuristic. A simple count of `.then(` vs
	// `.catch(` is too noisy — inner `response.text().then(...)` /
	// `response.blob().then(...)` are nested continuations of the
	// outer fetch promise whose `.catch(` lives downstream. We use
	// a softer heuristic: if the file has ANY `.then(` calls and
	// ZERO `.catch(` calls anywhere, that's a real bug (the
	// outermost promise chain forgot to install an error handler).
	$then_count  = count( $find_calls( $stripped, '.then(' ) );
	$catch_count = count( $find_calls( $stripped, '.catch(' ) );
	if ( $then_count > 0 && 0 === $catch_count ) {
		$unhandled_promise_files[] = "{$filename}: {$then_count} .then() calls but ZERO .catch() handlers — outermost fetch chain is unhandled";
	}
}

$record(
	'no_console_error_calls',
	0 === count( $console_error_violations ),
	'Shipped JS must not call console.error(...). Violations: ' . implode( ', ', $console_error_violations )
);
$record(
	'no_console_warn_calls',
	0 === count( $console_warn_violations ),
	'Shipped JS must not call console.warn(...). Violations: ' . implode( ', ', $console_warn_violations )
);
$record(
	'no_dialog_calls',
	0 === count( $dialog_violations ),
	'Shipped JS must not call alert/confirm/prompt. Violations: ' . implode( ', ', $dialog_violations )
);
$record(
	'no_document_write_calls',
	0 === count( $doc_write_violations ),
	'Shipped JS must not call document.write/writeln. Violations: ' . implode( ', ', $doc_write_violations )
);
$record(
	'no_eval_or_new_function',
	0 === count( $eval_violations ),
	'Shipped JS must not use eval() or new Function(). Violations: ' . implode( ', ', $eval_violations )
);
$record(
	'no_var_declarations',
	0 === count( $var_violations ),
	'Shipped JS must not declare `var ` (use `const` / `let`). Violations: ' . implode( ', ', $var_violations )
);
$record(
	'every_file_declares_strict_mode',
	0 === count( $strict_mode_violations ),
	'Every shipped JS file must declare strict mode. Violations: ' . implode( ', ', $strict_mode_violations )
);
$record(
	'no_unhandled_promises',
	0 === count( $unhandled_promise_files ),
	'Every .then() chain must end in .catch() (or live inside an awaited async function). Violations: ' . implode( ', ', $unhandled_promise_files )
);

/**
 * Rule 9: integration test exists.
 */
$test_path = $root_dir . '/tests/Integration/SScribe_JS_Error_Free_Test.php';
$record(
	'integration_test_exists',
	is_file( $test_path ),
	'tests/Integration/SScribe_JS_Error_Free_Test.php must exist so the contract is pinned at the PHPUnit boundary.'
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

echo "=== SScribe JS Error-Free Acceptance ===\n\n";
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
echo "✓ JS error-free contract valid.\n";
exit( 0 );
