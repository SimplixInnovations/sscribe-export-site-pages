<?php
/**
 * Phase 28: Strauss / PHPUnit vendor-prefix contract.
 *
 * SScribe ships three Composer libraries (mpdf/mpdf, phpoffice/phpword,
 * psr/container) that have to coexist with every other plugin on a
 * shared WordPress install. Without a prefixed vendor tree, the first
 * site that loads another plugin using the same library crashes with
 * a "Cannot redeclare class Mpdf\..." fatal.
 *
 * The contract is enforced via composer.json `extra.strauss`:
 *
 *   - target_directory: vendor-prefixed/
 *   - namespace_prefix: SScribeVendor\
 *   - classmap_prefix:  SScribeVendor_
 *   - packages:         [mpdf/mpdf, phpoffice/phpword, psr/container]
 *
 * and via the `composer vendor:prefix` script which runs Strauss +
 * two fixup passes (scripts/fix-prefixed-safe.php and
 * scripts/fix-phpword-style-deprecation.php).
 *
 * This verifier enforces:
 *
 *   1. composer.json declares the Strauss config block.
 *   2. Every package listed in the Strauss config is installed in
 *      vendor/ (a stale entry would make `composer vendor:prefix` a
 *      no-op and silently ship un-prefixed code).
 *   3. The composer vendor:prefix script exists and references the
 *      run-strauss + fix-prefixed-safe + fix-phpword-style-deprecation
 *      scripts.
 *   4. vendor-prefixed/autoload.php exists (Strauss wrote a fresh
 *      autoloader — otherwise the prefixed tree is dead weight).
 *   5. The prefixed tree contains at least one SScribeVendor-prefixed
 *      class file for each Strauss package.
 *   6. None of the Strauss packages leak a class with the unprefixed
 *      FQCN (Mpdf\Mpdf, PhpOffice\PhpWord\PhpWord, Psr\Container\ContainerInterface)
 *      inside the prefixed tree — a regression in Strauss config or
 *      the run-strauss wrapper would silently ship the unprefixed
 *      class into the ZIP and cause exactly the conflict this
 *      script exists to prevent.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

$root_dir = dirname( __DIR__ );

$composer_path = $root_dir . '/composer.json';
$vendor_dir    = $root_dir . '/vendor';
$prefixed_dir  = $root_dir . '/vendor-prefixed';

$errors = array();

if ( ! is_file( $composer_path ) ) {
	fwrite( STDERR, "composer.json not found at {$composer_path}\n" );
	exit( 1 );
}

$composer_raw = (string) file_get_contents( $composer_path );
$composer     = json_decode( $composer_raw, true );
if ( ! is_array( $composer ) ) {
	$errors[] = 'composer.json is not valid JSON.';
}

// 1. Strauss config block must be present and well-formed.
$strauss = isset( $composer['extra']['strauss'] ) && is_array( $composer['extra']['strauss'] )
	? $composer['extra']['strauss']
	: array();

$required_keys = array( 'target_directory', 'namespace_prefix', 'classmap_prefix', 'packages' );
foreach ( $required_keys as $key ) {
	if ( ! array_key_exists( $key, $strauss ) ) {
		$errors[] = "composer.json `extra.strauss` is missing the required `{$key}` key.";
	}
}

$target_dir = (string) ( $strauss['target_directory'] ?? '' );
$ns_prefix  = (string) ( $strauss['namespace_prefix'] ?? '' );
$cm_prefix  = (string) ( $strauss['classmap_prefix'] ?? '' );
$packages   = is_array( $strauss['packages'] ?? null ) ? $strauss['packages'] : array();

if ( '' !== $target_dir && 'vendor-prefixed' !== $target_dir ) {
	$errors[] = sprintf(
		'composer.json `extra.strauss.target_directory` is %s; expected `vendor-prefixed`. Any other path would diverge from the canonical prefixed tree that the WP plugin bootstrap autoloads.',
		$target_dir
	);
}
if ( '' !== $ns_prefix && 'SScribeVendor\\' !== $ns_prefix ) {
	$errors[] = sprintf(
		'composer.json `extra.strauss.namespace_prefix` is %s; expected `SScribeVendor\\`. Any other prefix leaves un-prefixed library classes in the bundle.',
		$ns_prefix
	);
}
if ( '' !== $cm_prefix && 'SScribeVendor_' !== $cm_prefix ) {
	$errors[] = sprintf(
		'composer.json `extra.strauss.classmap_prefix` is %s; expected `SScribeVendor_`.',
		$cm_prefix
	);
}

$expected_packages = array( 'mpdf/mpdf', 'phpoffice/phpword', 'psr/container' );
$missing_packages  = array_diff( $expected_packages, $packages );
if ( ! empty( $missing_packages ) ) {
	$errors[] = sprintf(
		'composer.json `extra.strauss.packages` is missing the canonical libraries: %s. Any of these left un-prefixed will collide with the same library shipped by another plugin.',
		implode( ', ', $missing_packages )
	);
}

// 2. Every Strauss package is installed in vendor/.
foreach ( $packages as $pkg ) {
	if ( ! is_dir( $vendor_dir . '/' . $pkg ) ) {
		$errors[] = sprintf(
			'composer.json declares `%s` in `extra.strauss.packages` but it is not installed under vendor/. Run `composer install` and `composer vendor:prefix` together.',
			$pkg
		);
	}
}

// 3. composer vendor:prefix script is wired correctly.
$scripts      = isset( $composer['scripts'] ) && is_array( $composer['scripts'] ) ? $composer['scripts'] : array();
$prefix_script = isset( $scripts['vendor:prefix'] ) ? (string) $scripts['vendor:prefix'] : '';

if ( '' === $prefix_script ) {
	$errors[] = 'composer.json is missing the `vendor:prefix` script entry. Without it CI never prefixes the vendor tree.';
} else {
	foreach (
		array( 'run-strauss.php', 'fix-prefixed-safe.php', 'fix-phpword-style-deprecation.php' ) as $script_name
	) {
		if ( ! str_contains( $prefix_script, $script_name ) ) {
			$errors[] = sprintf(
				'composer.json `vendor:prefix` script is missing the `%s` step. Without it, Strauss output is un-prefixed or the PHPWord deprecation guard is missing.',
				$script_name
			);
		}
	}
	if ( ! str_contains( $prefix_script, 'strauss' ) ) {
		$errors[] = 'composer.json `vendor:prefix` script does not invoke Strauss. The prefix step is the whole point of the script.';
	}
}

// 4-6. Prefixed tree must exist, must contain prefixed copies of every
// Strauss package, and must NOT leak unprefixed FQCNs.
if ( ! is_dir( $prefixed_dir ) ) {
	$errors[] = sprintf(
		'Prefixed vendor tree `%s` does not exist. Run `composer vendor:prefix` to generate it.',
		$prefixed_dir
	);
} else {
	if ( ! is_file( $prefixed_dir . '/autoload.php' ) ) {
		$errors[] = sprintf(
			'Prefixed vendor tree `%s` is missing `autoload.php`. Strauss did not write a fresh autoloader; the prefixed tree cannot be loaded.',
			$prefixed_dir
		);
	}

	// 5. Every Strauss package has a prefixed copy under vendor-prefixed/.
	$expected_dirs = array(
		'mpdf/mpdf'         => 'mpdf',
		'phpoffice/phpword' => 'phpoffice',
		'psr/container'     => 'psr',
	);
	foreach ( $expected_dirs as $pkg => $expected_subdir ) {
		if ( ! is_dir( $prefixed_dir . '/' . $expected_subdir ) ) {
			$errors[] = sprintf(
				'Prefixed vendor tree is missing the directory for `%s` (expected at `%s/%s`). Strauss did not copy the package into the prefixed tree.',
				$pkg,
				$prefixed_dir,
				$expected_subdir
			);
		}
	}

	// 6. No unprefixed FQCN leaks into the prefixed tree.
	$forbidden_classes = array(
		'namespace Mpdf;',
		'namespace PhpOffice\\PhpWord;',
		'namespace Psr\\Container;',
	);
	if ( is_dir( $prefixed_dir ) ) {
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator(
				$prefixed_dir,
				FilesystemIterator::SKIP_DOTS
			)
		);
		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() || 'php' !== strtolower( $file->getExtension() ) ) {
				continue;
			}
			$contents = (string) file_get_contents( $file->getPathname() );
			foreach ( $forbidden_classes as $forbidden ) {
				if ( str_contains( $contents, $forbidden ) ) {
					$errors[] = sprintf(
						'Prefixed vendor tree leaks unprefixed namespace `%s` in `%s`. The Strauss run was incomplete; re-run `composer vendor:prefix`.',
						$forbidden,
						$file->getPathname()
					);
					break 2;
				}
			}
		}
	}
}

echo "=== SScribe Strauss Prefix Verification ===\n\n";
echo 'Composer Strauss block: ' . ( empty( $strauss ) ? '(missing)' : 'present' ) . "\n";
echo 'Packages declared: ' . ( empty( $packages ) ? '(none)' : implode( ', ', $packages ) ) . "\n";
echo 'vendor:prefix script: ' . ( '' === $prefix_script ? '(missing)' : 'present' ) . "\n";
echo 'Prefixed tree: ' . ( is_dir( $prefixed_dir ) ? "{$prefixed_dir}" : '(missing)' ) . "\n\n";

if ( ! empty( $errors ) ) {
	echo "Errors:\n";
	foreach ( $errors as $error ) {
		echo "  ✗ {$error}\n";
	}
	echo "\n";
	exit( 1 );
}

echo "✓ Strauss vendor-prefix contract holds.\n";
exit( 0 );
