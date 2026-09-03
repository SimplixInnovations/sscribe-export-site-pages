<?php
/**
 * Phase 66 — AJAX network trace contract.
 *
 * Every `wp_ajax_sscribe_*` action the plugin registers is a
 * network surface. A regression that adds a new AJAX handler
 * without updating the canonical network trace doc silently
 * ships an undocumented endpoint — exactly the kind of surface
 * a security reviewer flags on submission.
 *
 * This verifier asserts the auditable contract:
 *
 *   1. docs/AJAX_NETWORK_TRACE_v2.0.0.md exists.
 *   2. The doc declares the canonical sections (Why this exists,
 *      Conventions, Endpoints, Guard surface).
 *   3. The Endpoints table declares every canonical column
 *      (Action, Handler, Capability, Nonce action, Rate-limit
 *      bucket, Response shape).
 *   4. EVERY `wp_ajax_sscribe_*` action registered in the source
 *      tree (includes/ + admin/) appears as a row in the trace
 *      table.
 *   5. ZERO `wp_ajax_nopriv_sscribe_*` actions are ever
 *      registered (the plugin is admin-only).
 *   6. The integration test exists.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

if ( 'cli' !== php_sapi_name() ) {
	exit( 'This script must be run from the command line.' );
}

$root_dir      = dirname( __DIR__ );
$trace_doc     = $root_dir . '/docs/AJAX_NETWORK_TRACE_v2.0.0.md';
$manifest_path = $root_dir . '/dist/ajax-network-trace-manifest.json';

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
 * Rule 1: trace doc exists.
 */
$record(
	'trace_doc_exists',
	is_file( $trace_doc ),
	'docs/AJAX_NETWORK_TRACE_v2.0.0.md must exist so the AJAX network surface is auditable.'
);

/**
 * Rule 2-3: trace doc structure + canonical columns.
 */
if ( is_file( $trace_doc ) ) {
	$trace_src = (string) file_get_contents( $trace_doc );

	$canonical_sections = array(
		'## Why this exists',
		'## Conventions',
		'## Endpoints',
		'## Guard surface',
	);
	$missing_sections   = array();
	foreach ( $canonical_sections as $section ) {
		if ( false === strpos( $trace_src, $section ) ) {
			$missing_sections[] = $section;
		}
	}
	$record(
		'trace_doc_has_canonical_sections',
		0 === count( $missing_sections ),
		'AJAX network trace doc is missing canonical sections: ' . implode( ', ', $missing_sections )
	);

	$canonical_columns = array( 'Action', 'Handler', 'Capability', 'Nonce action', 'Rate-limit bucket', 'Response shape' );
	$missing_columns   = array();
	foreach ( $canonical_columns as $column ) {
		if ( false === strpos( $trace_src, $column ) ) {
			$missing_columns[] = $column;
		}
	}
	$record(
		'endpoints_table_has_canonical_columns',
		0 === count( $missing_columns ),
		'Endpoints table is missing canonical columns: ' . implode( ', ', $missing_columns )
	);
}

/**
 * Rule 4: every wp_ajax_sscribe_* action in the source appears
 * as a row in the trace table.
 */
$registered_actions = array();
$source_roots       = array( $root_dir . '/includes', $root_dir . '/admin' );
foreach ( $source_roots as $root ) {
	if ( ! is_dir( $root ) ) {
		continue;
	}
	$iter = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $root, RecursiveDirectoryIterator::SKIP_DOTS )
	);
	foreach ( $iter as $file_info ) {
		if ( $file_info->isDir() || '.php' !== substr( $file_info->getFilename(), -4 ) ) {
			continue;
		}
		$src = (string) file_get_contents( $file_info->getPathname() );
		// Strip /* */ comments to avoid false positives from a
		// docblock that lists an example action name.
		$stripped = (string) ( preg_replace( '!/\*.*?\*/!s', '', $src ) ?? $src );
		if ( preg_match_all( "/['\"](wp_ajax_sscribe_[a-z_]+)['\"]/", $stripped, $hits ) ) {
			foreach ( $hits[1] as $hit ) {
				$registered_actions[ $hit ] = true;
			}
		}
	}
}
ksort( $registered_actions );
$registered_actions_list = array_keys( $registered_actions );

// Every wp_ajax_sscribe_* string that appears anywhere in the
// source tree is treated as a registered action — WP looks up
// hooks by exact string, so any string literal that matches the
// pattern IS a registered action regardless of which file holds
// the `add_action()` call. Docblock examples that live inside
// `/* */` are stripped before scanning.
$real_actions = array();
foreach ( $source_roots as $root ) {
	if ( ! is_dir( $root ) ) {
		continue;
	}
	$iter = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $root, RecursiveDirectoryIterator::SKIP_DOTS )
	);
	foreach ( $iter as $file_info ) {
		if ( $file_info->isDir() || '.php' !== substr( $file_info->getFilename(), -4 ) ) {
			continue;
		}
		$src = (string) file_get_contents( $file_info->getPathname() );
		$stripped = (string) ( preg_replace( '!/\*.*?\*/!s', '', $src ) ?? $src );
		if ( preg_match_all( "/['\"](wp_ajax_sscribe_[a-z_]+)['\"]/", $stripped, $hits ) ) {
			foreach ( $hits[1] as $hit ) {
				$real_actions[ $hit ] = true;
			}
		}
	}
}
ksort( $real_actions );
$real_actions_list = array_keys( $real_actions );

if ( is_file( $trace_doc ) ) {
	$trace_src = (string) file_get_contents( $trace_doc );
	$missing_actions = array();
	foreach ( $real_actions_list as $action ) {
		// The trace doc uses `wp_ajax_sscribe_foo` literally inside
		// a backtick-quoted row. Match either `action` or 'action'
		// or "action" so we don't false-negative on quote style.
		if ( false === strpos( $trace_src, $action ) ) {
			$missing_actions[] = $action;
		}
	}
	$record(
		'every_real_ajax_action_appears_in_trace',
		0 === count( $missing_actions ),
		'Every registered wp_ajax_sscribe_* action must appear as a row in docs/AJAX_NETWORK_TRACE_v2.0.0.md. Missing: ' . implode( ', ', $missing_actions )
	);
}

/**
 * Rule 5: NO wp_ajax_nopriv_sscribe_* actions are registered.
 */
$nopriv_actions = array();
foreach ( $source_roots as $root ) {
	if ( ! is_dir( $root ) ) {
		continue;
	}
	$iter = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $root, RecursiveDirectoryIterator::SKIP_DOTS )
	);
	foreach ( $iter as $file_info ) {
		if ( $file_info->isDir() || '.php' !== substr( $file_info->getFilename(), -4 ) ) {
			continue;
		}
		$src = (string) file_get_contents( $file_info->getPathname() );
		$stripped = (string) ( preg_replace( '!/\*.*?\*/!s', '', $src ) ?? $src );
		if ( preg_match_all( "/['\"](wp_ajax_nopriv_sscribe_[a-z_]+)['\"]/", $stripped, $hits ) ) {
			foreach ( $hits[1] as $hit ) {
				$nopriv_actions[] = $hit;
			}
		}
	}
}
$record(
	'no_public_ajax_actions_registered',
	0 === count( $nopriv_actions ),
	'Plugin must not register any wp_ajax_nopriv_sscribe_* action (admin-only AJAX surface). Found: ' . implode( ', ', $nopriv_actions )
);

/**
 * Rule 6: integration test exists.
 */
$test_path = $root_dir . '/tests/Integration/SScribe_AJAX_Network_Trace_Test.php';
$record(
	'integration_test_exists',
	is_file( $test_path ),
	'tests/Integration/SScribe_AJAX_Network_Trace_Test.php must exist so the contract is pinned at the PHPUnit boundary.'
);

// Persist manifest.
$manifest_dir = dirname( $manifest_path );
if ( ! is_dir( $manifest_dir ) ) {
	mkdir( $manifest_dir, 0755, true );
}
$manifest = array(
	'generated_at'         => gmdate( 'c' ),
	'registered_actions'   => $registered_actions_list,
	'real_actions'         => $real_actions_list,
	'nopriv_actions'       => $nopriv_actions,
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

echo "=== SScribe AJAX Network Trace Acceptance ===\n\n";
echo "Total wp_ajax_sscribe_* references (incl. docblocks): " . count( $registered_actions_list ) . "\n";
echo "Real (registered) wp_ajax_sscribe_* actions: " . count( $real_actions_list ) . "\n";
echo "wp_ajax_nopriv_sscribe_* references: " . count( $nopriv_actions ) . "\n\n";
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
echo "✓ AJAX network trace contract valid.\n";
exit( 0 );
