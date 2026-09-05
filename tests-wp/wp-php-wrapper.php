<?php
/**
 * WP_PHP_BINARY wrapper for the SScribe WP testbench.
 *
 * The upstream testbench invokes
 *     system( WP_PHP_BINARY . ' ' . escapeshellarg( install.php ) . … );
 * to spawn a PHP subprocess for {@see tests-wp/_wordpress-tests-lib/includes/install.php}.
 *
 * The default `WP_PHP_BINARY` value in {@see phpunit-wp.xml} was the literal
 * string `php -d extension=sqlite3 -d extension=pdo_sqlite`. On systems
 * where those extensions are already enabled in php.ini (Windows/XAMPP,
 * most shared-host dev envs), the subprocess prints
 *
 *     PHP Warning:  Module "sqlite3" is already loaded in Unknown on line 0
 *
 * every time PHPUnit runs, because PHP refuses to load an already-loaded
 * extension. The warnings are noisy and pollute test output, but the
 * underlying subprocess still runs correctly.
 *
 * This wrapper probes the parent's runtime via {@see extension_loaded()}
 * and only forwards the `-d extension=…` flags when those extensions
 * are not present. Behaviour on a clean system (no sqlite in php.ini)
 * is unchanged — the flags are still added, and the child loads them.
 *
 * Usage: `php tests-wp/wp-php-wrapper.php <install.php args…>`
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

$flags = array();

// The subprocess uses PHP_BINARY (same binary as us) with the same
// php.ini, so whatever is loaded in *this* process will also be
// loaded in the child. If `extension_loaded` returns true here, the
// child would see the same and PHP would emit the already-loaded
// warning; skip the flag in that case.
$extensions = array( 'sqlite3', 'pdo_sqlite' );
foreach ( $extensions as $ext ) {
	if ( ! extension_loaded( $ext ) ) {
		$flags[] = '-d extension=' . $ext;
	}
}

$forward_args = array_slice( $argv, 1 );
$cmd          = PHP_BINARY
	. ' ' . implode( ' ', array_map( 'escapeshellarg', $flags ) )
	. ' ' . implode( ' ', array_map( 'escapeshellarg', $forward_args ) );

passthru( $cmd, $exit_code );
exit( $exit_code );
