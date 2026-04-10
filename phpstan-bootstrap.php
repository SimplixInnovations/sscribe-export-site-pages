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

$baseDir = __DIR__;

if ( file_exists( $baseDir . '/vendor-prefixed/autoload.php' ) ) {
	require_once $baseDir . '/includes/sscribe-prefixed-runtime-shim.php';
	require_once $baseDir . '/vendor-prefixed/autoload.php';
} elseif ( file_exists( $baseDir . '/vendor/autoload.php' ) ) {
	require_once $baseDir . '/vendor/autoload.php';
	if ( file_exists( $baseDir . '/includes/sscribe-vendor-compat.php' ) ) {
		require_once $baseDir . '/includes/sscribe-vendor-compat.php';
	}
}
