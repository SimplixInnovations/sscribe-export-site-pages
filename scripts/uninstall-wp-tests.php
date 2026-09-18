<?php
/**
 * Remove the WordPress testbench scaffolded by scripts/install-wp-tests.php.
 *
 * Canonical cross-platform implementation. bin/uninstall-wp-tests.sh is a
 * thin wrapper around this file.
 *
 * Usage:
 *   php scripts/uninstall-wp-tests.php
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

require_once __DIR__ . '/lib/cross-platform.php';

$test_dir = sscribe_repo_root() . '/tests-wp';

sscribe_rmdir( $test_dir . '/_wordpress' );
sscribe_rmdir( $test_dir . '/_wordpress-tests-lib' );
sscribe_rmdir( $test_dir . '/.cache' );

sscribe_log( 'uninstall-wp-tests', "Removed WordPress core, test suite, and download cache under {$test_dir}." );
sscribe_log( 'uninstall-wp-tests', 'Bootstrap, config, and test files were left in place.' );
