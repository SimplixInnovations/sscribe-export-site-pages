<?php
/**
 * Phase 62 — Build-after-test vs test-after-build verifier.
 *
 * The release pipeline MUST run in this order:
 *
 *   1. Tests (PHPUnit, every verifier, plugin-check warnings,
 *      accessibility, security, etc.).
 *   2. THEN build the ZIP (composer release / build-release.php).
 *   3. THEN run WordPress Plugin Check on the ZIP.
 *
 * A regression that builds the ZIP BEFORE tests run (test-after-
 * build) silently ships a broken build — the tests never block
 * the artifact. Conversely, a regression that runs Plugin Check
 * BEFORE the build is meaningless (there's nothing to check yet).
 *
 * The verifier walks:
 *   - .github/workflows/ci.yml (the plugin-check job)
 *   - .github/workflows/release.yml (the certify job)
 *   - bin/release-audit.sh (the local pre-tag gate)
 *
 * and asserts the canonical order in each.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

if ( 'cli' !== php_sapi_name() ) {
	exit( 'This script must be run from the command line.' );
}

$root_dir      = dirname( __DIR__ );
$ci_path       = $root_dir . '/.github/workflows/ci.yml';
$release_path  = $root_dir . '/.github/workflows/release.yml';
$audit_script  = $root_dir . '/scripts/release-audit.php';
$manifest_path = $root_dir . '/dist/build-order-manifest.json';

$matrix = array();
$errors = array();

foreach ( array( $ci_path, $release_path, $audit_script ) as $path ) {
	if ( ! is_file( $path ) ) {
		fwrite( STDERR, "✗ Required file missing: {$path}\n" );
		exit( 1 );
	}
}

// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$ci_src      = (string) file_get_contents( $ci_path );
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$release_src = (string) file_get_contents( $release_path );
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$audit_src   = (string) file_get_contents( $audit_script );

/**
 * Helper: extract every distinct step from a YAML job's
 * `steps:` block. We work on the raw YAML — comments stripped,
 * step name + run command captured in order.
 */
$extract_steps = static function ( string $yaml ): array {
	$lines  = explode( "\n", $yaml );
	$steps  = array();
	$in_job = false;
	$cur    = array( 'name' => '', 'run' => '', 'uses' => '' );
	foreach ( $lines as $line ) {
		// Strip # comments.
		$clean = preg_replace( '/^\s*#[^\n]*/', '', $line );
		if ( false !== strpos( $clean, 'plugin-check' ) || false !== strpos( $clean, 'certify' ) ) {
			$in_job = true;
		}
		if ( ! $in_job ) {
			continue;
		}
		if ( preg_match( '/^\s*- name:\s+(.+)$/', $clean, $m ) ) {
			if ( '' !== $cur['name'] ) {
				$steps[] = $cur;
			}
			$cur = array( 'name' => trim( $m[1] ), 'run' => '', 'uses' => '' );
		} elseif ( preg_match( '/^\s+run:\s+(.+)$/', $clean, $m ) && '' !== $cur['name'] ) {
			$cur['run'] = trim( $m[1] );
		} elseif ( preg_match( '/^\s+uses:\s+(.+)$/', $clean, $m ) && '' !== $cur['name'] ) {
			$cur['uses'] = trim( $m[1] );
		}
	}
	if ( '' !== $cur['name'] ) {
		$steps[] = $cur;
	}
	return $steps;
};

/**
 * Rule 1: the ci.yml plugin-check job depends on `test` (so
 * PHPUnit passes first). The build step runs AFTER that dependency
 * is satisfied.
 */
$ci_plugin_check_depends_on_test = (bool) preg_match(
	"/plugin-check:\s*\n\s*name:\s+Submission Package Check\s*\n\s*runs-on:\s+ubuntu-latest\s*\n\s*needs:\s*\[[^\]]*\btest\b[^\]]*\]/",
	$ci_src
);
$matrix[] = array(
	'rule'   => 'ci_plugin_check_depends_on_test',
	'passes' => $ci_plugin_check_depends_on_test,
	'detail' => '.github/workflows/ci.yml plugin-check job must list `test` in its `needs:` (so PHPUnit runs first).',
);
if ( ! $ci_plugin_check_depends_on_test ) {
	$errors[] = '.github/workflows/ci.yml plugin-check job does NOT depend on `test`.';
}

/**
 * Rule 2: in the plugin-check job, the build step runs BEFORE the
 * plugin-check step. A regression that puts plugin-check-action
 * before build-release.php is meaningless (no ZIP to check).
 */
$ci_steps = $extract_steps( $ci_src );
$build_idx = -1;
$check_idx = -1;
foreach ( $ci_steps as $i => $step ) {
	if ( false !== strpos( $step['run'], 'build-release.php' ) && -1 === $build_idx ) {
		$build_idx = $i;
	}
	if ( false !== strpos( $step['uses'], 'wordpress/plugin-check-action' ) && -1 === $check_idx ) {
		$check_idx = $i;
	}
}
$matrix[] = array(
	'rule'   => 'ci_plugin_check_build_before_plugin_check',
	'passes' => -1 !== $build_idx && -1 !== $check_idx && $build_idx < $check_idx,
	'detail' => '.github/workflows/ci.yml plugin-check job must run build-release.php BEFORE wordpress/plugin-check-action. Build idx=' . $build_idx . ', plugin-check idx=' . $check_idx . '.',
);
if ( -1 === $build_idx || -1 === $check_idx || $build_idx >= $check_idx ) {
	$errors[] = '.github/workflows/ci.yml plugin-check ordering is broken (build must run before plugin-check).';
}

/**
 * Rule 3: the release.yml certify job depends on `test` so
 * the build can only proceed after tests are green.
 */
$release_certify_depends_on_test = (bool) preg_match(
	'/\bcertify:\s*\n(?:\s+name:\s+[^\n]+\n|\s+runs-on:\s+[^\n]+\n)+\s+needs:\s+(?:\[[^\]]*\]|\S+)/',
	$release_src
) && (bool) preg_match( '/\bcertify:\s*\n(?:\s+[a-z-]+:\s+[^\n]+\n)*\s+needs:\s+(?:\[[^\]]*\btest\b[^\]]*\]|\btest\b)/', $release_src );
$matrix[] = array(
	'rule'   => 'release_certify_depends_on_test',
	'passes' => $release_certify_depends_on_test,
	'detail' => '.github/workflows/release.yml certify job must list `test` in its `needs:` (so PHPUnit runs first).',
);
if ( ! $release_certify_depends_on_test ) {
	$errors[] = '.github/workflows/release.yml certify job does NOT depend on `test`.';
}

/**
 * Rule 4: in release.yml, the build step runs BEFORE the
 * plugin-check step.
 */
$release_steps = $extract_steps( $release_src );
$release_build_idx = -1;
$release_check_idx = -1;
foreach ( $release_steps as $i => $step ) {
	if ( false !== strpos( $step['run'], 'build-release.php' ) && -1 === $release_build_idx ) {
		$release_build_idx = $i;
	}
	if ( false !== strpos( $step['uses'], 'wordpress/plugin-check-action' ) && -1 === $release_check_idx ) {
		$release_check_idx = $i;
	}
}
$matrix[] = array(
	'rule'   => 'release_certify_build_before_plugin_check',
	'passes' => -1 !== $release_build_idx && -1 !== $release_check_idx && $release_build_idx < $release_check_idx,
	'detail' => '.github/workflows/release.yml certify job must run build-release.php BEFORE plugin-check-action. Build idx=' . $release_build_idx . ', plugin-check idx=' . $release_check_idx . '.',
);
if ( -1 === $release_build_idx || -1 === $release_check_idx || $release_build_idx >= $release_check_idx ) {
	$errors[] = '.github/workflows/release.yml certify ordering is broken.';
}

/**
 * Rule 5: scripts/release-audit.php is a verification gate, NOT a build
 * step. It must run the canonical test order (PHPUnit → every
 * verifier → audit → artifact cert) and MUST NOT call
 * composer release / build-release.php (those run later in the
 * ci.yml plugin-check job). A regression that builds from inside
 * the audit means a local tag-cut could skip the real CI build.
 */
$audit_lines   = explode( "\n", $audit_src );
$audit_runs_phpunit = false;
$audit_runs_build   = false;
foreach ( $audit_lines as $i => $line ) {
	$clean = ltrim( $line );
	if ( 0 === strpos( $clean, '#' ) || 0 === strpos( $clean, '//' ) || 0 === strpos( $clean, '*' ) || 0 === strpos( $clean, '/*' ) || '' === $clean ) {
		continue;
	}
	if ( false === $audit_runs_phpunit && ( false !== strpos( $clean, 'vendor/bin/phpunit' ) || false !== strpos( $clean, 'composer test' ) ) ) {
		$audit_runs_phpunit = true;
	}
	if (
		false === $audit_runs_build &&
		( false !== strpos( $clean, 'build-release.php' ) || ( false !== strpos( $clean, 'composer release' ) && false === strpos( $clean, 'release:audit' ) ) )
	) {
		$audit_runs_build = true;
	}
}
$matrix[] = array(
	'rule'   => 'release_audit_runs_phpunit',
	'passes' => $audit_runs_phpunit,
	'detail' => 'scripts/release-audit.php must run vendor/bin/phpunit (or composer test) as part of its verification gate.',
);
if ( ! $audit_runs_phpunit ) {
	$errors[] = 'scripts/release-audit.php does NOT run PHPUnit — release gate cannot enforce the test contract.';
}
$matrix[] = array(
	'rule'   => 'release_audit_does_not_build',
	'passes' => ! $audit_runs_build,
	'detail' => 'scripts/release-audit.php must NOT call build-release.php / composer release — that\'s the ci.yml plugin-check job\'s responsibility. Building inside the audit would skip the real CI build.',
);
if ( $audit_runs_build ) {
	$errors[] = 'scripts/release-audit.php MUST NOT run build-release.php / composer release.';
}

/**
 * Rule 6: the certifier job in ci.yml that owns plugin-check
 * has the literal name `Submission Package Check` (matches
 * branch-protection doc and CI docs §3).
 */
$matrix[] = array(
	'rule'   => 'plugin_check_job_is_submission_package_check',
	'passes' => (bool) preg_match( '/plugin-check:\s*\n\s*name:\s+Submission Package Check/', $ci_src ),
	'detail' => '.github/workflows/ci.yml plugin-check job must be named `Submission Package Check` (matches branch-protection + CI docs).',
);
if ( ! (bool) preg_match( '/plugin-check:\s*\n\s*name:\s+Submission Package Check/', $ci_src ) ) {
	$errors[] = '.github/workflows/ci.yml plugin-check job name is not `Submission Package Check`.';
}

/**
 * Rule 7: integration test exists.
 */
$test_path = $root_dir . '/tests/Integration/SScribe_Build_Order_Test.php';
$matrix[] = array(
	'rule'   => 'integration_test_exists',
	'passes' => is_file( $test_path ),
	'detail' => 'tests/Integration/SScribe_Build_Order_Test.php must exist so the contract is pinned at the PHPUnit boundary.',
);
if ( ! is_file( $test_path ) ) {
	$errors[] = 'tests/Integration/SScribe_Build_Order_Test.php is missing.';
}

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

echo "=== SScribe Build-Order Acceptance ===\n\n";
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
echo "✓ Build-order contract valid.\n";
exit( 0 );
