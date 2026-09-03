<?php
/**
 * Phase 67 — UI refactor discipline contract.
 *
 * The v2.0.0 release spec mandates:
 *
 *   "Current priority is stability. Do not split the huge admin
 *    JS/CSS/PHP files simply because they are large unless
 *    necessary to implement the fixes safely. Post-release
 *    modularization can be a separate milestone. Avoid changing
 *    UX unnecessarily."
 *
 * This verifier asserts the freeze:
 *
 *   1. docs/UI_REFACTOR_DISCIPLINE_v2.0.0.md exists.
 *   2. The doc declares the canonical sections (Why this exists,
 *      Baseline SHA, File-count lock, Rename / add / split ban,
 *      Allowed modifications, Post-release modularization backlog,
 *      How an independent auditor verifies this).
 *   3. The doc records a baseline SHA on the `BASELINE_SHA = `
 *      line, and that SHA resolves to a real commit.
 *   4. The admin surface file count at HEAD matches the v1.9.0
 *      baseline (9 files: 2 PHP + 3 CSS + 2 JS + 2 partials).
 *   5. `git diff --name-status <baseline> HEAD -- admin/` contains
 *      zero `R` (rename) lines.
 *   6. `git diff --name-status <baseline> HEAD -- admin/` contains
 *      zero `A` (added) lines for files under admin/css, admin/js,
 *      admin/partials, or admin/*.php.
 *   7. The doc declares a "Post-release modularization backlog"
 *      section with at least one deferred item.
 *   8. The integration test exists.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

if ( 'cli' !== php_sapi_name() ) {
	exit( 'This script must be run from the command line.' );
}

$root_dir      = dirname( __DIR__ );
$discipline_doc = $root_dir . '/docs/UI_REFACTOR_DISCIPLINE_v2.0.0.md';
$manifest_path = $root_dir . '/dist/ui-refactor-discipline-manifest.json';

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
 * Rule 1: discipline doc exists.
 */
$record(
	'discipline_doc_exists',
	is_file( $discipline_doc ),
	'docs/UI_REFACTOR_DISCIPLINE_v2.0.0.md must exist so the UI refactor freeze is auditable.'
);

/**
 * Rule 2-3, 7: discipline doc structure + baseline SHA.
 */
$baseline_sha = '';
$baseline_sha_valid = false;
if ( is_file( $discipline_doc ) ) {
	$doc_src = (string) file_get_contents( $discipline_doc );

	$canonical_sections = array(
		'## Why this exists',
		'## Baseline SHA',
		'## File-count lock',
		'## Rename / add / split ban',
		'## Allowed modifications to existing admin files',
		'## Post-release modularization backlog',
		'## How an independent auditor verifies this',
	);
	$missing_sections   = array();
	foreach ( $canonical_sections as $section ) {
		if ( false === strpos( $doc_src, $section ) ) {
			$missing_sections[] = $section;
		}
	}
	$record(
		'discipline_doc_has_canonical_sections',
		0 === count( $missing_sections ),
		'UI refactor discipline doc is missing canonical sections: ' . implode( ', ', $missing_sections )
	);

	if ( preg_match( '/BASELINE_SHA\s*=\s*([0-9a-f]{7,40})/', $doc_src, $m ) ) {
		$baseline_sha = $m[1];
		$baseline_sha_valid = true;
	}
	$record(
		'baseline_sha_recorded',
		$baseline_sha_valid,
		'UI refactor discipline doc must record a BASELINE_SHA on `BASELINE_SHA = <sha>` line. Found: ' . ( $baseline_sha_valid ? $baseline_sha : '<missing>' )
	);

	if ( $baseline_sha_valid ) {
		$cat = shell_exec( 'git cat-file -t ' . escapeshellarg( $baseline_sha ) . ' 2>&1' );
		$record(
			'baseline_sha_resolves',
			is_string( $cat ) && false !== strpos( $cat, 'commit' ),
			'BASELINE_SHA must resolve to a real commit. `git cat-file -t ' . $baseline_sha . '` returned: ' . trim( (string) $cat )
		);
	}
}

/**
 * Rule 4: admin surface file count at HEAD matches baseline.
 */
$collect_admin_paths = static function ( string $sha ): array {
	$out = array();
	// Walk the repo tracked file tree for admin/css/, admin/js/,
	// admin/partials/, and admin/*.php (top-level PHP classes).
	// Use `git ls-tree -r` with literal paths so we don't rely on
	// shell glob expansion behavior across platforms.
	$cmd = 'git ls-tree -r ' . escapeshellarg( $sha ) . ' -- admin/ 2>&1';
	$raw = (string) shell_exec( $cmd );
	foreach ( explode( "\n", trim( $raw ) ) as $line ) {
		if ( '' === $line ) {
			continue;
		}
		$tab = strpos( $line, "\t" );
		if ( false === $tab ) {
			continue;
		}
		$path = substr( $line, $tab + 1 );
		if ( 0 !== strpos( $path, 'admin/' ) ) {
			continue;
		}
		// Include admin/css/*, admin/js/*, admin/partials/* (any
		// extension), and admin/*.php (top-level class files).
		if ( 0 === strpos( $path, 'admin/css/' )
			|| 0 === strpos( $path, 'admin/js/' )
			|| 0 === strpos( $path, 'admin/partials/' ) ) {
			$out[] = $path;
			continue;
		}
		// For top-level admin/*.php, the relative path after
		// 'admin/' must contain no further '/'.
		$rest = substr( $path, strlen( 'admin/' ) );
		if ( false === strpos( $rest, '/' ) && '.php' === substr( $rest, -4 ) ) {
			$out[] = $path;
		}
	}
	sort( $out );
	return $out;
};

$head_admin_files     = $collect_admin_paths( 'HEAD' );
$baseline_admin_files = '' !== $baseline_sha
	? $collect_admin_paths( $baseline_sha )
	: array();

$only_in_head     = array_values( array_diff( $head_admin_files, $baseline_admin_files ) );
$only_in_baseline = array_values( array_diff( $baseline_admin_files, $head_admin_files ) );
$record(
	'admin_file_count_matches_baseline',
	0 === count( $only_in_head ) && 0 === count( $only_in_baseline ),
	'Admin surface file count at HEAD must match the BASELINE_SHA exactly. Added: ' . implode( ', ', $only_in_head ) . '. Removed: ' . implode( ', ', $only_in_baseline )
);

/**
 * Rule 5-6: no renames (R) or added files (A) under admin/.
 */
$rename_lines = array();
$added_lines  = array();
if ( '' !== $baseline_sha ) {
	$diff = shell_exec( 'git diff --name-status ' . escapeshellarg( $baseline_sha ) . ' HEAD -- admin/ 2>&1' );
	if ( is_string( $diff ) ) {
		foreach ( explode( "\n", trim( $diff ) ) as $line ) {
			if ( '' === $line ) {
				continue;
			}
			// Format: "R100\told\tnew" or "A\tpath" etc.
			$fields = explode( "\t", $line );
			$op     = substr( $fields[0], 0, 1 );
			if ( 'R' === $op ) {
				$rename_lines[] = $line;
			} elseif ( 'A' === $op ) {
				// Only flag additions under the protected dirs.
				$added_path = $fields[1] ?? '';
				if ( 0 === strpos( $added_path, 'admin/css/' )
					|| 0 === strpos( $added_path, 'admin/js/' )
					|| 0 === strpos( $added_path, 'admin/partials/' )
					|| ( 0 === strpos( $added_path, 'admin/' ) && '.php' === substr( $added_path, -4 ) ) ) {
					$added_lines[] = $line;
				}
			}
		}
	}
}
$record(
	'no_admin_renames_since_baseline',
	0 === count( $rename_lines ),
	'Admin files MUST NOT be renamed between BASELINE_SHA and HEAD. Found renames: ' . implode( ' | ', $rename_lines )
);
$record(
	'no_admin_files_added_since_baseline',
	0 === count( $added_lines ),
	'Admin surface files MUST NOT be added between BASELINE_SHA and HEAD. Found additions: ' . implode( ' | ', $added_lines )
);

/**
 * Rule 7: post-release modularization backlog exists.
 */
if ( is_file( $discipline_doc ) ) {
	$doc_src = (string) file_get_contents( $discipline_doc );
	$has_backlog_section = false !== strpos( $doc_src, '## Post-release modularization backlog' );
	// Backlog section must declare at least one markdown-table row
	// (`| <col> | <col> | ...`) so the deferred items are
	// scannable.
	$backlog_block = '';
	if ( $has_backlog_section ) {
		$start = strpos( $doc_src, '## Post-release modularization backlog' );
		$backlog_block = substr( $doc_src, $start );
	}
	$backlog_rows = preg_match_all( '/^\|\s+\S.+\|\s*\S.*$/m', $backlog_block );
	$record(
		'post_release_backlog_declared',
		$has_backlog_section && $backlog_rows >= 3,
		'Post-release modularization backlog must be declared with at least 3 markdown rows. Found: ' . $backlog_rows . ' rows.'
	);
}

/**
 * Rule 8: integration test exists.
 */
$test_path = $root_dir . '/tests/Integration/SScribe_UI_Refactor_Discipline_Test.php';
$record(
	'integration_test_exists',
	is_file( $test_path ),
	'tests/Integration/SScribe_UI_Refactor_Discipline_Test.php must exist so the freeze is pinned at the PHPUnit boundary.'
);

// Persist manifest.
$manifest_dir = dirname( $manifest_path );
if ( ! is_dir( $manifest_dir ) ) {
	mkdir( $manifest_dir, 0755, true );
}
$manifest = array(
	'generated_at'      => gmdate( 'c' ),
	'baseline_sha'      => $baseline_sha,
	'head_admin_files'  => $head_admin_files,
	'baseline_admin_files' => $baseline_admin_files,
	'rename_lines'      => $rename_lines,
	'added_lines'       => $added_lines,
	'rule_count'        => count( $matrix ),
	'passed_count'      => count( array_filter( $matrix, static fn( $r ) => $r['passes'] ) ),
	'errors_count'      => count( $errors ),
	'passes'            => 0 === count( $errors ),
	'errors'            => $errors,
	'matrix'            => $matrix,
);
file_put_contents(
	$manifest_path,
	json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
);

echo "=== SScribe UI Refactor Discipline Acceptance ===\n\n";
echo "BASELINE_SHA:    {$baseline_sha}\n";
echo "Head admin files:    " . count( $head_admin_files ) . "\n";
echo "Baseline admin files: " . count( $baseline_admin_files ) . "\n";
echo "Renames since baseline: " . count( $rename_lines ) . "\n";
echo "Additions since baseline: " . count( $added_lines ) . "\n\n";
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
echo "✓ UI refactor discipline contract valid.\n";
exit( 0 );
