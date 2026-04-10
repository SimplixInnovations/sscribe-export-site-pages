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

$base_dir          = __DIR__;
$vendor_autoload   = $base_dir . '/vendor/autoload.php';
$prefixed_autoload = $base_dir . '/vendor-prefixed/autoload.php';

/**
 * Prefer raw vendor autoload for PHPStan to avoid duplicate Safe\ wrappers
 * when both raw and prefixed trees are present in CI.
 */
if ( file_exists( $vendor_autoload ) ) {
	require_once $vendor_autoload;
} elseif ( file_exists( $prefixed_autoload ) ) {
	require_once $base_dir . '/includes/sscribe-prefixed-runtime-shim.php';
	require_once $prefixed_autoload;
}

/**
 * Map prefixed runtime class names used by plugin code to raw vendor classes
 * so PHPStan can resolve symbols without loading both vendor trees.
 */
$sscribe_phpstan_alias_prefixes = array(
	'SScribeVendor\\Dompdf\\'             => 'Dompdf\\',
	'SScribeVendor\\PhpOffice\\PhpWord\\' => 'PhpOffice\\PhpWord\\',
);

spl_autoload_register(
	static function ( string $fqcn ) use ( $sscribe_phpstan_alias_prefixes ): void {
		foreach ( $sscribe_phpstan_alias_prefixes as $prefixed_prefix => $raw_prefix ) {
			if ( ! str_starts_with( $fqcn, $prefixed_prefix ) ) {
				continue;
			}

			$raw_class = $raw_prefix . substr( $fqcn, strlen( $prefixed_prefix ) );

			if ( class_exists( $raw_class ) || interface_exists( $raw_class ) || trait_exists( $raw_class ) ) {
				class_alias( $raw_class, $fqcn );
			}

			return;
		}
	},
	true,
	true
);
