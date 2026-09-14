<?php
/**
 * Phase 29: E2E dev-dependency contract.
 *
 * The Playwright E2E suite (tests-e2e/) is the integration-tier
 * smoke for everything the JS layer touches: the export wizard,
 * the download-token authentication flow, the dark-mode contrast
 * matrix, and the WCAG 2.2 AA accessibility regressions. A broken
 * E2E contract means CI runs silently ship a wizard that no human
 * has exercised, an a11y regression that fails on production
 * readers, or a download endpoint that no test has authenticated
 * against.
 *
 * The contract has three surfaces:
 *
 *   - package.json declares every dev dependency the Playwright
 *     projects need (and the suite scripts CI invokes).
 *   - playwright.config.ts declares the browser projects (e2e, a11y)
 *     so a project dropped during a refactor cannot silently skip
 *     its slice of the suite. Performance is enforced by the PHP
 *     integration benchmark (composer test:perf), not a browser
 *     timing project.
 *   - composer.json declares the release:prepare script (which
 *     bakes a stable ZIP for the E2E server fixture) and the
 *     release script (which the e2e.yml workflow invokes).
 *
 * This verifier enforces:
 *
 *   1. package.json devDependencies contains @playwright/test and
 *      @axe-core/playwright.
 *   2. package.json scripts contains test:e2e, test:e2e:smoke,
 *      test:e2e:full, test:e2e:e2e, test:e2e:a11y.
 *   3. package-lock.json is present (npm ci must be deterministic).
 *   4. playwright.config.ts exists and declares the e2e and a11y
 *      projects.
 *   5. tests-e2e/ has at least one spec file under each project
 *      subdirectory.
 *   6. composer.json declares release:prepare and release.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

$root_dir = dirname( __DIR__ );

$composer_path = $root_dir . '/composer.json';
$package_path  = $root_dir . '/package.json';
$lock_path     = $root_dir . '/package-lock.json';
$pw_config     = $root_dir . '/playwright.config.ts';

$errors = array();

if ( ! is_file( $package_path ) ) {
	$errors[] = "package.json not found at {$package_path}.";
}
if ( ! is_file( $composer_path ) ) {
	$errors[] = "composer.json not found at {$composer_path}.";
}

$package  = $package_path && is_file( $package_path ) ? (array) json_decode( (string) file_get_contents( $package_path ), true ) : array();
$composer = $composer_path && is_file( $composer_path ) ? (array) json_decode( (string) file_get_contents( $composer_path ), true ) : array();

// 1. devDependencies.
$dev_deps = isset( $package['devDependencies'] ) && is_array( $package['devDependencies'] ) ? $package['devDependencies'] : array();
$required_dev_deps = array(
	'@playwright/test'      => 'Playwright test runner',
	'@axe-core/playwright'  => 'axe-core Playwright integration (a11y project)',
);
foreach ( $required_dev_deps as $dep => $purpose ) {
	if ( ! array_key_exists( $dep, $dev_deps ) ) {
		$errors[] = sprintf(
			'package.json devDependencies is missing `%s` (%s).',
			$dep,
			$purpose
		);
	}
}

// 2. test:e2e scripts.
$npm_scripts = isset( $package['scripts'] ) && is_array( $package['scripts'] ) ? $package['scripts'] : array();
$required_scripts = array(
	'test:e2e',
	'test:e2e:smoke',
	'test:e2e:full',
	'test:e2e:e2e',
	'test:e2e:a11y',
);
foreach ( $required_scripts as $script ) {
	if ( ! array_key_exists( $script, $npm_scripts ) ) {
		$errors[] = sprintf(
			'package.json scripts is missing `%s`. CI invokes it by name; a missing script means the workflow fails with `npm ERR! missing script`.',
			$script
		);
	}
}

// 3. Lock file presence.
if ( ! is_file( $lock_path ) ) {
	$errors[] = 'package-lock.json is missing. CI uses `npm ci` which requires a lockfile; without it npm install produces non-reproducible deps.';
}

// 4. playwright.config.ts + projects.
if ( ! is_file( $pw_config ) ) {
	$errors[] = 'playwright.config.ts is missing. The Playwright CLI cannot discover specs without it.';
} else {
	$pw_contents = (string) file_get_contents( $pw_config );
	foreach ( array( 'e2e', 'a11y' ) as $project ) {
		// Match the project name as either a top-level `name: 'e2e'`
		// or as a quoted string. We intentionally use a simple match
		// so renaming the projects list cannot silently slip past.
		if ( ! preg_match( '/name\s*:\s*[\'"]' . preg_quote( $project, '/' ) . '[\'"]/', $pw_contents ) ) {
			$errors[] = sprintf(
				'playwright.config.ts does not declare the `%s` project. A project dropped during refactor silently skips its slice of the suite.',
				$project
			);
		}
	}
}

// 5. tests-e2e/ subdirectories have at least one spec.
$tests_e2e = $root_dir . '/tests-e2e';
$expected_subdirs = array( 'e2e', 'a11y' ); // perf specs may live at root.
foreach ( $expected_subdirs as $subdir ) {
	$path = $tests_e2e . '/' . $subdir;
	if ( ! is_dir( $path ) ) {
		$errors[] = sprintf(
			'tests-e2e/%s/ directory is missing. The Playwright %s project has no specs to run.',
			$subdir,
			$subdir
		);
		continue;
	}
	$has_spec = false;
	foreach ( new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS )
	) as $file ) {
		if ( $file->isFile() && preg_match( '/\.spec\.ts$|\.spec\.js$/', (string) $file->getFilename() ) ) {
			$has_spec = true;
			break;
		}
	}
	if ( ! $has_spec ) {
		$errors[] = sprintf(
			'tests-e2e/%s/ has no *.spec.ts/.spec.js files. The Playwright %s project runs an empty suite.',
			$subdir,
			$subdir
		);
	}
}

// 6. composer release scripts.
$composer_scripts = isset( $composer['scripts'] ) && is_array( $composer['scripts'] ) ? $composer['scripts'] : array();
foreach ( array( 'release:prepare', 'release' ) as $cs ) {
	if ( ! array_key_exists( $cs, $composer_scripts ) ) {
		$errors[] = sprintf(
			'composer.json scripts is missing `%s`. CI bakes the E2E fixture from this script.',
			$cs
		);
	}
}

echo "=== SScribe E2E Dev-Deps Verification ===\n\n";
echo 'package.json devDependencies: ' . ( empty( $dev_deps ) ? '(none)' : 'present' ) . "\n";
echo 'package-lock.json: ' . ( is_file( $lock_path ) ? 'present' : 'missing' ) . "\n";
echo 'playwright.config.ts: ' . ( is_file( $pw_config ) ? 'present' : 'missing' ) . "\n";
echo 'tests-e2e/: ' . ( is_dir( $tests_e2e ) ? 'present' : 'missing' ) . "\n\n";

if ( ! empty( $errors ) ) {
	echo "Errors:\n";
	foreach ( $errors as $error ) {
		echo "  ✗ {$error}\n";
	}
	echo "\n";
	exit( 1 );
}

echo "✓ E2E dev-dependency contract holds.\n";
exit( 0 );
