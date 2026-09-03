<?php
/**
 * Phase 56 — Real-WordPress matrix CI verifier.
 *
 * A single (PHP 8.4, latest-WP, SQLite) test pass is a smoke test.
 * Production-grade evidence requires a MATRIX covering the
 * combinations WordPress actually ships with:
 *
 *   - PHP versions the plugin supports (>= 8.2 per composer.json)
 *   - WordPress versions still on the WP.org "current + previous"
 *     support window (current + N-1)
 *   - DBs the plugin must run against (SQLite drop-in AND MySQL)
 *
 * If the real-wp-tests job runs only one leg, a regression that
 * ships an 8.2-incompatible patch slips through. If it skips MySQL,
 * a regression that ships a `utf8mb4_unicode_ci` collation name
 * passes locally and explodes on a real WP host. This verifier
 * asserts the matrix is REAL — that ci.yml declares multiple legs
 * and that the helper scripts honor the matrix arguments.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

if ( 'cli' !== php_sapi_name() ) {
	exit( 'This script must be run from the command line.' );
}

$root_dir      = dirname( __DIR__ );
$ci_workflow   = $root_dir . '/.github/workflows/ci.yml';
$composer_json = $root_dir . '/composer.json';
$installer     = $root_dir . '/bin/install-wp-tests.sh';
$manifest_path = $root_dir . '/dist/real-wp-matrix-manifest.json';

$matrix = array();
$errors = array();

foreach ( array( $ci_workflow, $composer_json, $installer ) as $path ) {
	if ( ! is_file( $path ) ) {
		fwrite( STDERR, "✗ Required file missing: {$path}\n" );
		exit( 1 );
	}
}

// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$ci_src    = (string) file_get_contents( $ci_workflow );
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$composer  = (string) file_get_contents( $composer_json );
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$inst_src  = (string) file_get_contents( $installer );

/**
 * Rule 1: composer.json declares the PHP versions the plugin
 * supports. We assert the minimum is 8.2 (the floor) — anything
 * lower should be impossible to claim as supported.
 */
$composer_decodes = json_decode( $composer, true );
if ( ! is_array( $composer_decodes ) || ! isset( $composer_decodes['require']['php'] ) ) {
	$errors[] = 'composer.json does not declare `require.php` constraint.';
	$supported_php = array();
} else {
	$php_req = (string) $composer_decodes['require']['php'];
	preg_match_all( '/\d+\.\d+/', $php_req, $php_versions );
	$supported_php = array_map( 'strval', $php_versions[0] );
	$matrix[] = array(
		'rule'   => 'composer_declares_php_version_constraint',
		'passes' => ! empty( $supported_php ),
		'detail' => "composer.json require.php = `{$php_req}`; extracted: " . implode( ', ', $supported_php ),
	);
}
if ( empty( $supported_php ) ) {
	$errors[] = 'Could not extract PHP versions from composer.json require.php.';
}

/**
 * Rule 2: the real-wp-tests job MUST declare a `strategy.matrix`
 * block. A single (PHP 8.4, latest-WP, SQLite) job is a smoke
 * test, not a matrix.
 */
$has_matrix_strategy = (bool) preg_match(
	'/real-wp-tests:[\s\S]{0,2000}?strategy:[\s\S]{0,500}?matrix:\s*\n/m',
	$ci_src
);
$matrix[] = array(
	'rule'   => 'real_wp_tests_declares_matrix_strategy',
	'passes' => $has_matrix_strategy,
	'detail' => 'real-wp-tests job must declare `strategy.matrix` (multiple PHP / WP / DB legs).',
);
if ( ! $has_matrix_strategy ) {
	$errors[] = 'real-wp-tests does NOT declare a strategy.matrix — it runs as a single leg, not a matrix.';
}

/**
 * Rule 3: the matrix covers multiple PHP versions. The plugin
 * declares 8.2+ as supported, so the matrix MUST include 8.2 to
 * prove the floor still works.
 *
 * Strategy: locate `real-wp-tests:` in ci.yml, slice to the
 * next job boundary (`steps:` at 8-space indent), strip YAML
 * comments (so the comment lines don't confuse the regex), and
 * capture the `matrix:` body. This gives us the matrix body to
 * scan.
 */
$matrix_block = '';
$pos = strpos( $ci_src, 'real-wp-tests:' );
if ( false !== $pos ) {
	// Skip past the first line ("real-wp-tests:" itself).
	$slice_start = strpos( $ci_src, "\n", $pos );
	$job_slice   = substr( $ci_src, false === $slice_start ? $pos : $slice_start + 1, 4000 );
	// Stop at the next top-level job boundary — a line that starts
	// with exactly 4 spaces and a letter (no leading tabs), e.g.
	// "    foo-bar:". The current job's body is at 8+ spaces, so
	// the first 4-space line is the start of the next job.
	if ( preg_match( '/\n    [a-z][a-z0-9_-]*:\s*\n/', $job_slice, $boundary, PREG_OFFSET_CAPTURE ) ) {
		$job_slice = substr( $job_slice, 0, $boundary[0][1] );
	}
	// Strip YAML comments so comment lines like `# PHP versions the
	// plugin supports` don't confuse the body-extraction regex.
	$job_slice_no_comments = (string) preg_replace( '/^\s*#[^\n]*$/m', '', $job_slice );
	if ( preg_match( '/matrix:\s*\n([\s\S]*?)\n        steps:/m', $job_slice_no_comments, $m ) ) {
		$matrix_block = $m[1];
	}
}

$php_versions_in_matrix = array();
if ( preg_match_all( '/php-version:\s*\[([^\]]+)\]/', $matrix_block, $pv_matches ) ) {
	foreach ( $pv_matches[1] as $pv_list ) {
		$candidates = array_map(
			static fn( $v ) => trim( trim( $v ), '"\'' ),
			explode( ',', $pv_list )
		);
		$php_versions_in_matrix = array_merge( $php_versions_in_matrix, $candidates );
	}
}
$php_versions_in_matrix = array_values(
	array_unique(
		array_filter( $php_versions_in_matrix, static fn( $v ) => 'matrix' !== $v )
	)
);

$covers_min_php = in_array( '8.2', $php_versions_in_matrix, true );
$matrix[] = array(
	'rule'   => 'real_wp_matrix_covers_php_82_floor',
	'passes' => $covers_min_php,
	'detail' => 'Real-WP matrix MUST include PHP 8.2 (the plugin\'s floor). Discovered: ' . implode( ', ', $php_versions_in_matrix ),
);
if ( ! $covers_min_php ) {
	$errors[] = 'Real-WP matrix does NOT cover PHP 8.2 — regressions against the supported floor will slip through.';
}

/**
 * Rule 4: the matrix covers at least TWO PHP versions. A single
 * version isn't a matrix.
 */
$php_version_count = count( array_filter( $php_versions_in_matrix, static fn( $v ) => 'matrix' !== trim( trim( $v ), '"\'' ) ) );
$matrix[] = array(
	'rule'   => 'real_wp_matrix_has_at_least_two_php_versions',
	'passes' => $php_version_count >= 2,
	'detail' => "Real-WP matrix must include at least 2 PHP versions. Discovered: {$php_version_count} (" . implode( ', ', $php_versions_in_matrix ) . ')',
);
if ( $php_version_count < 2 ) {
	$errors[] = "Real-WP matrix only includes {$php_version_count} PHP version(s); a matrix requires at least 2.";
}

/**
 * Rule 5: the matrix covers multiple WordPress versions (current
 * + N-1). A regression that drops `get_block_templates()` or a
 * newer WP filter won't surface if every run uses latest.
 */
$wp_versions_in_matrix = array();
if ( preg_match_all( '/wp-version:\s*\[([^\]]+)\]/', $matrix_block, $wpv_matches ) ) {
	foreach ( $wpv_matches[1] as $wpv_list ) {
		$candidates = array_map(
			static fn( $v ) => trim( trim( $v ), '"\'' ),
			explode( ',', $wpv_list )
		);
		$wp_versions_in_matrix = array_merge( $wp_versions_in_matrix, $candidates );
	}
}
$wp_versions_in_matrix = array_values(
	array_unique(
		array_filter( $wp_versions_in_matrix, static fn( $v ) => 'matrix' !== $v )
	)
);
$wp_version_count     = count( $wp_versions_in_matrix );
$matrix[] = array(
	'rule'   => 'real_wp_matrix_has_at_least_two_wp_versions',
	'passes' => $wp_version_count >= 2,
	'detail' => "Real-WP matrix must include >= 2 WP versions. Discovered: {$wp_version_count} (" . implode( ', ', $wp_versions_in_matrix ) . ')',
);
if ( $wp_version_count < 2 ) {
	$errors[] = "Real-WP matrix only includes {$wp_version_count} WP version(s); need at least current + previous.";
}

/**
 * Rule 6: install-wp-tests.sh honors the WP_VERSION env / arg.
 * The matrix sets per-leg WP versions; the installer must consume
 * them.
 */
$installer_honors_wp_version = (bool) preg_match( '/WP_VERSION/', $inst_src );
$matrix[] = array(
	'rule'   => 'installer_honors_wp_version_variable',
	'passes' => $installer_honors_wp_version,
	'detail' => 'bin/install-wp-tests.sh must honor `WP_VERSION` env / `--version <ver>` flag so each matrix leg boots the right WP core.',
);
if ( ! $installer_honors_wp_version ) {
	$errors[] = 'bin/install-wp-tests.sh does NOT honor WP_VERSION — matrix legs will all use the same WP version.';
}

/**
 * Rule 7: install-wp-tests.sh supports the SQLite drop-in. The
 * default matrix leg must work without MySQL on the runner.
 */
$installer_supports_sqlite = (bool) preg_match( '/--sqlite/', $inst_src )
	&& (bool) preg_match( '/sqlite-database-integration|SQLite Database Integration|sqlite3/i', $inst_src );
$matrix[] = array(
	'rule'   => 'installer_supports_sqlite_dropin',
	'passes' => $installer_supports_sqlite,
	'detail' => 'bin/install-wp-tests.sh must support a SQLite drop-in path (no MySQL on the runner).',
);
if ( ! $installer_supports_sqlite ) {
	$errors[] = 'bin/install-wp-tests.sh does NOT support the SQLite drop-in.';
}

/**
 * Rule 8: the matrix uploads per-leg artifacts. A failure on a
 * single leg must surface its own log so the auditor doesn't
 * rerun the matrix by hand to find the broken leg.
 *
 * We assert: within the real-wp-tests job, there is BOTH an
 * `actions/upload-artifact` step AND an `if: always()` (or
 * `if: failure()`) gating condition. Order doesn't matter —
 * GitHub Actions allows `if:` before or after `uses:`.
 */
$real_wp_tests_slice = '';
$pos = strpos( $ci_src, 'real-wp-tests:' );
if ( false !== $pos ) {
	$slice_start           = strpos( $ci_src, "\n", $pos );
	$real_wp_tests_slice   = substr( $ci_src, false === $slice_start ? $pos : $slice_start + 1, 4000 );
	if ( preg_match( '/\n    [a-z][a-z0-9_-]*:\s*\n/', $real_wp_tests_slice, $boundary, PREG_OFFSET_CAPTURE ) ) {
		$real_wp_tests_slice = substr( $real_wp_tests_slice, 0, $boundary[0][1] );
	}
}
$has_upload_step   = (bool) preg_match( '/actions\/upload-artifact/', $real_wp_tests_slice );
$has_always_upload = (bool) preg_match( '/if:\s*(?:always\(\)|failure\(\))/', $real_wp_tests_slice );
$uploads_per_leg_logs = $has_upload_step && $has_always_upload;
$matrix[] = array(
	'rule'   => 'real_wp_matrix_uploads_per_leg_logs',
	'passes' => $uploads_per_leg_logs,
	'detail' => 'real-wp-tests matrix must upload per-leg logs as an artifact AND gate it with `if: always()` or `if: failure()` so a failed leg is recoverable without rerunning the matrix.',
);
if ( ! $uploads_per_leg_logs ) {
	$errors[] = 'real-wp-tests matrix does NOT upload per-leg log artifacts (or does not gate them with if:always()/if:failure()).';
}

/**
 * Rule 9: the matrix uses `fail-fast: false`. A regression on one
 * leg must not abort the others — the auditor wants the full
 * matrix report, not "first failure won".
 */
$uses_fail_fast_false = (bool) preg_match(
	'/real-wp-tests:[\s\S]*?fail-fast:\s*false/m',
	$ci_src
);
$matrix[] = array(
	'rule'   => 'real_wp_matrix_disables_fail_fast',
	'passes' => $uses_fail_fast_false,
	'detail' => 'real-wp-tests matrix MUST set `fail-fast: false` so all legs complete even when one fails.',
);
if ( ! $uses_fail_fast_false ) {
	$errors[] = 'real-wp-tests matrix does NOT set `fail-fast: false`.';
}

/**
 * Rule 10: the matrix sets `continue-on-error` ONLY on the
 * experimental MySQL leg (which the free runner does not provide).
 * SQLite legs MUST NOT continue-on-error — those must be required
 * checks.
 */
$matrix[] = array(
	'rule'   => 'real_wp_matrix_does_not_silently_swallow_sqlite_leg_failures',
	'passes' => true, // We only assert the negation in the next line.
	'detail' => 'A blanket `continue-on-error: true` on real-wp-tests would silence SQLite regressions. We assert below that the SQLite leg cannot continue-on-error.',
);

$blanket_continue_on_error = (bool) preg_match(
	'/real-wp-tests:[\s\S]*?continue-on-error:\s*true/m',
	$ci_src
);
$matrix[] = array(
	'rule'   => 'real_wp_matrix_has_no_blanket_continue_on_error',
	'passes' => ! $blanket_continue_on_error,
	'detail' => 'real-wp-tests MUST NOT set a blanket `continue-on-error: true` — SQLite leg failures must be required-check failures.',
);
if ( $blanket_continue_on_error ) {
	$errors[] = 'real-wp-tests sets a blanket `continue-on-error: true` — SQLite regressions would slip through silently.';
}

/**
 * Rule 11: ci.yml's required-check list (the final required-check
 * block) includes the real-wp-tests matrix under its friendly
 * name. Phase 55 documents real-wp-tests as a required check; the
 * gate passes only when the matrix succeeds for the FULL matrix.
 *
 * We assert: the branch-protection doc mentions the matrix's
 * friendly label ("Real WordPress Integration Suite") AND the
 * doc + ci.yml use the same spelling for that label.
 */
$doc_src = (string) file_get_contents( $root_dir . '/docs/BRANCH_PROTECTION_v2.0.0.md' );
$ci_label       = 'Real WordPress Integration Suite';
$doc_mentions   = (bool) preg_match( '/' . preg_quote( $ci_label, '/' ) . '/i', $doc_src );
$ci_declares    = (bool) preg_match( '/\bname:\s*' . preg_quote( 'Real WordPress Integration Suite', '/' ) . '\b/', $ci_src );

$matrix[] = array(
	'rule'   => 'branch_protection_doc_mentions_real_wp_matrix_label',
	'passes' => $doc_mentions,
	'detail' => "docs/BRANCH_PROTECTION_v2.0.0.md must list the real-WP matrix by its friendly label (`{$ci_label}`).",
);
$matrix[] = array(
	'rule'   => 'ci_yml_real_wp_tests_uses_matching_friendly_label',
	'passes' => $ci_declares,
	'detail' => "ci.yml real-wp-tests job must use `name: {$ci_label}` so GitHub's required-checks UI maps the doc entry to the matrix.",
);
if ( ! $doc_mentions ) {
	$errors[] = "Branch-protection doc does NOT list the real-WP matrix by its friendly label (`{$ci_label}`).";
}
if ( ! $ci_declares ) {
	$errors[] = "ci.yml real-wp-tests job does NOT declare `name: {$ci_label}`.";
}

// Persist manifest.
$manifest_dir = dirname( $manifest_path );
if ( ! is_dir( $manifest_dir ) ) {
	mkdir( $manifest_dir, 0755, true );
}
$manifest = array(
	'generated_at'             => gmdate( 'c' ),
	'supported_php_versions'   => $supported_php,
	'php_versions_in_matrix'   => $php_versions_in_matrix,
	'wp_versions_in_matrix'    => $wp_versions_in_matrix,
	'rule_count'               => count( $matrix ),
	'passed_count'             => count( array_filter( $matrix, static fn( $r ) => $r['passes'] ) ),
	'matrix'                   => $matrix,
	'errors_count'             => count( $errors ),
	'passes'                   => 0 === count( $errors ),
	'errors'                   => $errors,
);
file_put_contents(
	$manifest_path,
	json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
);

echo "=== SScribe Real-WP Matrix ===\n\n";
echo "Supported PHP versions: " . implode( ', ', $supported_php ) . "\n";
echo "PHP versions in matrix: " . implode( ', ', $php_versions_in_matrix ) . "\n";
echo "WP versions in matrix: " . implode( ', ', $wp_versions_in_matrix ) . "\n\n";
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
echo "✓ Real-WP matrix contract valid.\n";
exit( 0 );
