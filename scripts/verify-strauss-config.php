<?php
/**
 * Strauss vendor-prefix contract verifier.
 *
 * Default mode validates source/build configuration on a clean checkout.
 * Pass --built immediately after `composer vendor:prefix` to certify the
 * generated tree that is exercised by E2E and packaged for release.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

$root_dir      = dirname( __DIR__ );
$composer_path = $root_dir . '/composer.json';
$vendor_dir    = $root_dir . '/vendor';
$prefixed_dir  = $root_dir . '/vendor-prefixed';
$verify_built  = in_array( '--built', $argv ?? array(), true );
$errors        = array();

if ( ! is_file( $composer_path ) ) {
	fwrite( STDERR, "composer.json not found at {$composer_path}\n" );
	exit( 1 );
}

$composer = json_decode( (string) file_get_contents( $composer_path ), true );
if ( ! is_array( $composer ) ) {
	$errors[] = 'composer.json is not valid JSON.';
	$composer = array();
}

$strauss = isset( $composer['extra']['strauss'] ) && is_array( $composer['extra']['strauss'] )
	? $composer['extra']['strauss']
	: array();

foreach ( array( 'target_directory', 'namespace_prefix', 'classmap_prefix', 'packages' ) as $key ) {
	if ( ! array_key_exists( $key, $strauss ) ) {
		$errors[] = "composer.json `extra.strauss` is missing the required `{$key}` key.";
	}
}

$target_dir = (string) ( $strauss['target_directory'] ?? '' );
$ns_prefix  = (string) ( $strauss['namespace_prefix'] ?? '' );
$cm_prefix  = (string) ( $strauss['classmap_prefix'] ?? '' );
$packages   = is_array( $strauss['packages'] ?? null ) ? $strauss['packages'] : array();

if ( '' !== $target_dir && 'vendor-prefixed' !== $target_dir ) {
	$errors[] = sprintf( 'composer.json `extra.strauss.target_directory` is %s; expected `vendor-prefixed`.', $target_dir );
}
if ( '' !== $ns_prefix && 'SScribeVendor\\' !== $ns_prefix ) {
	$errors[] = sprintf( 'composer.json `extra.strauss.namespace_prefix` is %s; expected `SScribeVendor\\`.', $ns_prefix );
}
if ( '' !== $cm_prefix && 'SScribeVendor_' !== $cm_prefix ) {
	$errors[] = sprintf( 'composer.json `extra.strauss.classmap_prefix` is %s; expected `SScribeVendor_`.', $cm_prefix );
}

$expected_packages = array( 'tecnickcom/tcpdf', 'phpoffice/phpword', 'psr/container' );
$missing_packages  = array_diff( $expected_packages, $packages );
if ( ! empty( $missing_packages ) ) {
	$errors[] = sprintf( 'composer.json `extra.strauss.packages` is missing the canonical libraries: %s.', implode( ', ', $missing_packages ) );
}

foreach ( $packages as $pkg ) {
	if ( ! is_dir( $vendor_dir . '/' . $pkg ) ) {
		$errors[] = sprintf( 'composer.json declares `%s` in `extra.strauss.packages` but it is not installed under vendor/. Run `composer install` first.', $pkg );
	}
}

$scripts       = isset( $composer['scripts'] ) && is_array( $composer['scripts'] ) ? $composer['scripts'] : array();
$prefix_script = isset( $scripts['vendor:prefix'] ) ? (string) $scripts['vendor:prefix'] : '';
if ( '' === $prefix_script ) {
	$errors[] = 'composer.json is missing the `vendor:prefix` script entry.';
} else {
	foreach ( array( 'prune-tcpdf-for-strauss.php', 'run-strauss.php', 'fix-prefixed-safe.php', 'fix-phpword-style-deprecation.php' ) as $script_name ) {
		if ( ! str_contains( $prefix_script, $script_name ) ) {
			$errors[] = sprintf( 'composer.json `vendor:prefix` script is missing the `%s` step.', $script_name );
		}
	}
	if ( ! str_contains( $prefix_script, 'strauss' ) ) {
		$errors[] = 'composer.json `vendor:prefix` script does not invoke Strauss.';
	}
}

if ( $verify_built ) {
	if ( ! is_dir( $prefixed_dir ) ) {
		$errors[] = sprintf( 'Built verification requested but prefixed vendor tree `%s` does not exist. Run `composer vendor:prefix` first.', $prefixed_dir );
	} else {
		if ( ! is_file( $prefixed_dir . '/autoload.php' ) ) {
			$errors[] = sprintf( 'Prefixed vendor tree `%s` is missing `autoload.php`.', $prefixed_dir );
		}

		$expected_dirs = array(
			'tecnickcom/tcpdf'    => 'tecnickcom',
			'phpoffice/phpword' => 'phpoffice',
			'psr/container'     => 'psr',
		);
		foreach ( $expected_dirs as $pkg => $expected_subdir ) {
			if ( ! is_dir( $prefixed_dir . '/' . $expected_subdir ) ) {
				$errors[] = sprintf( 'Prefixed vendor tree is missing `%s` (expected `%s/%s`).', $pkg, $prefixed_dir, $expected_subdir );
			}
		}

		// TCPDF itself defines a global class named TCPDF. Strauss must apply
		// the configured classmap prefix to that exact entrypoint. Do not scan
		// for the raw substring "class TCPDF" globally: PHPWord legitimately
		// defines a namespaced writer class with that short name.
		$tcpdf_entry = $prefixed_dir . '/tecnickcom/tcpdf/tcpdf.php';
		if ( ! is_file( $tcpdf_entry ) ) {
			$errors[] = sprintf( 'Prefixed TCPDF entrypoint is missing: %s.', $tcpdf_entry );
		} else {
			$tcpdf_source = (string) file_get_contents( $tcpdf_entry );
			if ( preg_match( '/^\\s*class\\s+TCPDF\\b/m', $tcpdf_source ) ) {
				$errors[] = sprintf( 'Prefixed TCPDF entrypoint still declares the unprefixed global class in %s.', $tcpdf_entry );
			}
			if ( ! preg_match( '/^\\s*class\\s+SScribeVendor_TCPDF\\b/m', $tcpdf_source ) ) {
				$errors[] = sprintf( 'Prefixed TCPDF entrypoint does not declare the expected SScribeVendor_TCPDF class in %s.', $tcpdf_entry );
			}
		}

		$forbidden_namespaces = array(
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
					$errors[] = sprintf( 'Prefixed vendor tree leaks unprefixed namespace `%s` in `%s`.', $forbidden, $file->getPathname() );
					break 2;
				}
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
