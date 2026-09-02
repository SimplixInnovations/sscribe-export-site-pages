<?php
/**
 * Phase 33: WordPress Plugin Check CI contract.
 *
 * Plugin Check is the official WP.org submission-time audit. The
 * CI integration in `.github/workflows/ci.yml` runs it on every
 * PR that targets main / develop, so a regression in Plugin Check
 * configuration silently ships a build that the WP.org review
 * team will reject on submission.
 *
 * The contract is enforced across four surfaces:
 *
 *   1. .github/workflows/ci.yml declares a `plugin-check` job that
 *      runs `wordpress/plugin-check-action@v1` against the build
 *      directory the release script produces.
 *   2. The plugin-check job uses strict mode (Plugin Check exits
 *      non-zero on warning + error) and include-experimental: true
 *      so we catch the experimental checks WP.org runs in review.
 *   3. The plugin-check job depends on `[test, frontend-quality,
 *      audit, real-wp-tests]` — it never runs on a build that
 *      failed PHPUnit, PHPStan, PHPCS, npm audit, or the real-WP
 *      integration suite.
 *   4. scripts/build-release.php produces a directory at
 *      `./dist/sscribe-export-site-pages/` so the action's
 *      `build-dir` input points at a real directory.
 *
 * Each rule emits a single line on a match so the failure output
 * is stable and PHPUnit can grep for the violation keyword.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

$root_dir = dirname( __DIR__ );

$ci_path      = $root_dir . '/.github/workflows/ci.yml';
$build_script = $root_dir . '/scripts/build-release.php';
$build_dir    = $root_dir . '/dist/sscribe-export-site-pages';

$errors = array();

if ( ! is_file( $ci_path ) ) {
	echo "=== SScribe Plugin Check Verification ===\n\n";
	echo "  ✗ Missing .github/workflows/ci.yml\n\n";
	exit( 1 );
}
if ( ! is_file( $build_script ) ) {
	$errors[] = 'scripts/build-release.php is missing. Plugin Check CI depends on the release script to produce dist/sscribe-export-site-pages/.';
}

$ci = (string) file_get_contents( $ci_path );

// 1. plugin-check job exists.
if ( ! preg_match( '/^    plugin-check:\s*$/m', $ci ) ) {
	$errors[] = '.github/workflows/ci.yml does not declare a `plugin-check:` job.';
}

// 2. Uses the official wordpress/plugin-check-action.
if ( ! preg_match( '/uses:\s*wordpress\/plugin-check-action@v1/', $ci ) ) {
	$errors[] = 'plugin-check job does not use `wordpress/plugin-check-action@v1`. Substituting an unofficial action silently skips the checks WP.org enforces.';
}

// 3. strict: true.
if ( ! preg_match( '/strict:\s*true/', $ci ) ) {
	$errors[] = 'plugin-check job does not set `strict: true`. Without strict mode, Plugin Check only fails on errors, not warnings — exactly the false-positive lane WP.org reviewers reject submissions for.';
}

// 4. include-experimental: true.
if ( ! preg_match( '/include-experimental:\s*true/', $ci ) ) {
	$errors[] = 'plugin-check job does not set `include-experimental: true`. WP.org review runs the experimental checks; CI must match.';
}

// 5. needs: [test, frontend-quality, audit, real-wp-tests].
$needs_pattern = '/needs:\s*\[[^\]]+\]/';
$needs_match   = '';
if ( preg_match( $needs_pattern, $ci, $needs_hit ) ) {
	$needs_match = $needs_hit[0];
}
foreach ( array( 'test', 'frontend-quality', 'audit', 'real-wp-tests' ) as $required_dep ) {
	if ( false === strpos( $needs_match, $required_dep ) ) {
		$errors[] = sprintf(
			'plugin-check job does not depend on `%s`. Plugin Check must not run on a build that failed any earlier gate. (needs line found: %s)',
			$required_dep,
			'' !== $needs_match ? $needs_match : '(none)'
		);
	}
}

// 6. build-dir matches the release script output.
if ( ! preg_match( '/build-dir:\s*\.?\/?dist\/sscribe-export-site-pages/', $ci ) ) {
	$errors[] = 'plugin-check job `build-dir` is not `./dist/sscribe-export-site-pages`. The release script writes there; a different path means Plugin Check audits a stale or wrong tree.';
}

// 7. The build script declares the canonical dist directory.
//    scripts/build-release.php uses `$dist_dir = __DIR__ . '/../dist/sscribe-export-site-pages'`
//    or similar — match either form.
$build_src = is_file( $build_script ) ? (string) file_get_contents( $build_script ) : '';
if ( '' !== $build_src && ! preg_match( "/dist_dir\s*=\s*[^;]+dist[\/\\\\]+sscribe-export-site-pages/", $build_src ) ) {
	// Allow the build to use any helper function as long as the directory
	// string appears in the source.
	if ( ! preg_match( "/dist[\\/]+sscribe-export-site-pages/", $build_src ) ) {
		$errors[] = 'scripts/build-release.php does not reference the canonical dist/sscribe-export-site-pages path. Plugin Check would audit the wrong tree.';
	}
}

// 8. The plugin-check job runs vendor:prefix so the build is the same
//    shape that ships to WP.org.
if ( ! preg_match( "/composer\\s+install[^\n]*--no-progress/", $ci ) ) {
	$errors[] = 'plugin-check job does not run `composer install --no-progress` before the action.';
}
if ( ! preg_match( "/composer\\s+vendor:prefix/", $ci ) ) {
	$errors[] = 'plugin-check job does not run `composer vendor:prefix`. Without it, the audited build is un-prefixed and would ship runtime library collisions.';
}

// 9. The plugin-check job calls scripts/build-release.php before the action.
if ( ! preg_match( "/php\\s+scripts\\/build-release\\.php/", $ci ) ) {
	$errors[] = 'plugin-check job does not run `php scripts/build-release.php` before the action. Plugin Check must audit the actual dist tree, not the working tree.';
}

// 10. The action block lives inside the plugin-check job.
//     Extract everything from `    plugin-check:` until the next
//     top-level job (4-space indent + colon at column 5).
$action_block = '';
if ( preg_match(
	'/^    plugin-check:[\s\S]*?(?=^    [a-z][a-z0-9-]*:\s*$|\Z)/m',
	$ci,
	$matches
) ) {
	$action_block = $matches[0];
}
if ( '' !== $action_block && ! preg_match( '/wordpress\/plugin-check-action/', $action_block ) ) {
	$errors[] = 'plugin-check job block does not contain the wordpress/plugin-check-action step. The job name `plugin-check` exists but the action is wired elsewhere.';
}

echo "=== SScribe Plugin Check CI Verification ===\n\n";
echo 'CI workflow:           ' . ( is_file( $ci_path ) ? 'present' : 'missing' ) . "\n";
echo 'Build script:          ' . ( is_file( $build_script ) ? 'present' : 'missing' ) . "\n";
echo 'Build directory:       ' . ( is_dir( $build_dir ) ? 'present (run `php scripts/build-release.php` first)' : 'absent (will be produced by CI)' ) . "\n\n";

if ( ! empty( $errors ) ) {
	echo "Errors:\n";
	foreach ( $errors as $error ) {
		echo "  ✗ {$error}\n";
	}
	echo "\n";
	exit( 1 );
}

echo "✓ Plugin Check CI contract holds.\n";
exit( 0 );
