<?php
/**
 * Strauss vendor-prefix contract verifier.
 *
 * Default mode validates the source/build configuration and may run on a
 * clean checkout before vendor-prefixed/ has been generated.
 *
 * Pass --built after `composer vendor:prefix` to additionally certify the
 * generated vendor-prefixed tree that will be packaged and exercised by E2E.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

$root_dir     = dirname( __DIR__ );
$composer_path = $root_dir . '/composer.json';
$vendor_dir    = $root_dir . '/vendor';
$prefixed_dir  = $root_dir . '/vendor-prefixed';
$verify_built  = in_array( '--built', $argv ?? array(), true );

$errors = array();

if ( ! is_file( $composer_path ) ) {
	fwrite( STDERR, "composer.json not found at {$composer_path}\n" );
	exit( 1 );
}

$composer_raw = (string) file_get_contents( $composer_path );
$composer     = json_decode( $composer_raw, true );
if ( ! is_array( $composer ) ) {
	$errors[] = 'composer.json is not valid JSON.';
	$composer = array();
}

// 1. Source configuration must be present and canonical.
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
		'composer.json `extra.strauss.target_directory` is %s; expected `vendor-prefixed`.',
		$target_dir
	);
}
if ( '' !== $ns_prefix && 'SScribeVendor\\' !== $ns_prefix ) {
	$errors[] = sprintf(
		'composer.json `extra.strauss.namespace_prefix` is %s; expected `SScribeVendor\\`.',
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
		'composer.json `extra.strauss.packages` is missing the canonical libraries: %s.',
		implode( ', ', $missing_packages )
	);
}

// 2. A normal Composer install must contain every package Strauss is asked to
// transform. This catches stale configuration before the expensive build step.
foreach ( $packages as $pkg ) {
	if ( ! is_dir( $vendor_dir . '/' . $pkg ) ) {
		$errors[] = sprintf(
			'composer.json declares `%s` in `extra.strauss.packages` but it is not installed under vendor/. Run `composer install` first.',
			$pkg
		);
	}
}

// 3. The canonical prefix pipeline must remain wired.
$scripts       = isset( $composer['scripts'] ) && is_array( $composer['scripts'] ) ? $composer['scripts'] : array();
$prefix_script = isset( $scripts['vendor:prefix'] ) ? (string) $scripts['vendor:prefix'] : '';

if ( '' === $prefix_script ) {
	$errors[] = 'composer.json is missing the `vendor:prefix` script entry.';
} else {
	foreach ( array( 'run-strauss.php', 'fix-prefixed-safe.php', 'fix-phpword-style-deprecation.php' ) as $script_name ) {
		if ( ! str_contains( $prefix_script, $script_name ) ) {
			$errors[] = sprintf(
				'composer.json `vendor:prefix` script is missing the `%s` step.',
				$script_name
			);
		}
	}
	if ( ! str_contains( $prefix_script, 'strauss' ) ) {
		$errors[] = 'composer.json `vendor:prefix` script does not invoke Strauss.';
	}
}

// 4. Generated-output certification is intentionally a post-build contract.
// A clean source checkout is not required to commit generated vendor-prefixed
// contents; --built must be invoked immediately after composer vendor:prefix
// anywhere a release fixture or ZIP is produced.
if ( $verify_built ) {
	if ( ! is_dir( $prefixed_dir ) ) {
		$errors[] = sprintf(
			'Built verification requested but prefixed vendor tree `%s` does not exist. Run `composer vendor:prefix` first.',
			$prefixed_dir
		);
	} else {
		if ( ! is_file( $prefixed_dir . '/autoload.php' ) ) {
			$errors[] = sprintf(
				'Prefixed vendor tree `%s` is missing `autoload.php`.',
				$prefixed_dir
			);
		}

		$expected_dirs = array(
			'mpdf/mpdf'         => 'mpdf',
			'phpoffice/phpword' => 'phpoffice',
			'psr/container'     => 'psr',
		);
		foreach ( $expected_dirs as $pkg => $expected_subdir ) {
			if ( ! is_dir( $prefixed_dir . '/' . $expected_subdir ) ) {
				$errors[] = sprintf(
					'Prefixed vendor tree is missing `%s` (expected `%s/%s`).',
					$pkg,
					$prefixed_dir,
					$expected_subdir
				);
			}
		}

		$forbidden_namespaces = array(
			'namespace Mpdf;',
			'namespace PhpOffice\\PhpWord;',
			'namespace Psr\\Container;',
		);
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $prefixed_dir, FilesystemIterator::SKIP_DOTS )
		);
		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() || 'php' !== strtolower( $file->getExtension() ) ) {
				continue;
			}
			$contents = (string) file_get_contents( $file->getPathname() );
			foreach ( $forbidden_namespaces as $forbidden ) {
				if ( str_contains( $contents, $forbidden ) ) {
					$errors[] = sprintf(
						'Prefixed vendor tree leaks unprefixed namespace `%s` in `%s`.',
						$forbidden,
						$file->getPathname()
					);
					break 2;
				}
		}
	}
}

echo "=== SScribe Strauss Prefix Verification ===\n\n";
echo 'Mode: ' . ( $verify_built ? 'configuration + built tree' : 'configuration only' ) . "\n";
echo 'Composer Strauss block: ' . ( empty( $strauss ) ? '(missing)' : 'present' ) . "\n";
echo 'Packages declared: ' . ( empty( $packages ) ? '(none)' : implode( ', ', $packages ) ) . "\n";
echo 'vendor:prefix script: ' . ( '' === $prefix_script ? '(missing)' : 'present' ) . "\n";
if ( $verify_built ) {
	echo 'Prefixed tree: ' . ( is_dir( $prefixed_dir ) ? $prefixed_dir : '(missing)' ) . "\n";
}
echo "\n";

if ( ! empty( $errors ) ) {
	echo "Errors:\n";
	foreach ( $errors as $error ) {
		echo "  ✗ {$error}\n";
	}
	echo "\n";
	exit( 1 );
}

echo $verify_built
	? "✓ Strauss configuration and generated vendor-prefix tree hold.\n"
	: "✓ Strauss configuration contract holds.\n";
exit( 0 );
