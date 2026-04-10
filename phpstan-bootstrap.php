<?php
/**
 * PHPStan bootstrap for SScribe plugin analysis.
 *
 * Ensures prefixed vendor classes are discoverable during static analysis in
 * both CI (vendor-prefixed generated) and local development (raw vendor).
 *
 * @package SScribe
 */

declare(strict_types=1);

$baseDir         = __DIR__;
$vendorAutoload  = $baseDir . '/vendor/autoload.php';
$prefixedAutoload = $baseDir . '/vendor-prefixed/autoload.php';

/**
 * Prefer raw vendor autoload for PHPStan to avoid duplicate Safe\ wrappers
 * when both raw and prefixed trees are present in CI.
 */
if ( file_exists( $vendorAutoload ) ) {
	require_once $vendorAutoload;
} elseif ( file_exists( $prefixedAutoload ) ) {
	require_once $baseDir . '/includes/sscribe-prefixed-runtime-shim.php';
	require_once $prefixedAutoload;
}

/**
 * Map prefixed runtime class names used by plugin code to raw vendor classes
 * so PHPStan can resolve symbols without loading both vendor trees.
 */
$sscribePhpstanAliasPrefixes = array(
	'SScribeVendor\\Dompdf\\'              => 'Dompdf\\',
	'SScribeVendor\\PhpOffice\\PhpWord\\' => 'PhpOffice\\PhpWord\\',
);

spl_autoload_register(
	static function ( string $class ) use ( $sscribePhpstanAliasPrefixes ): void {
		foreach ( $sscribePhpstanAliasPrefixes as $prefixedPrefix => $rawPrefix ) {
			if ( ! str_starts_with( $class, $prefixedPrefix ) ) {
				continue;
			}

			$rawClass = $rawPrefix . substr( $class, strlen( $prefixedPrefix ) );

			if ( class_exists( $rawClass ) || interface_exists( $rawClass ) || trait_exists( $rawClass ) ) {
				class_alias( $rawClass, $class );
			}

			return;
		}
	},
	true,
	true
);
