<?php
/**
 * Cross-platform POT generator.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

require_once __DIR__ . '/lib/cross-platform.php';

$wp = sscribe_which( 'wp' );
if ( 'wp' === $wp ) {
	sscribe_fail( 'WP-CLI is required for i18n:make-pot. Install WP-CLI and ensure `wp` is on PATH.' );
}

$result = sscribe_run_argv(
	array(
		$wp,
		'i18n',
		'make-pot',
		'.',
		'languages/sscribe-export-site-pages.pot',
		'--domain=sscribe-export-site-pages',
		'--exclude=vendor,vendor-prefixed,node_modules,tests,tests-wp,tests-e2e,stubs,scripts,.github,coverage,dist,build,.cache',
	),
	sscribe_repo_root(),
	array( 'WP_CLI_PHP_ARGS' => '-d memory_limit=1G' )
);

if ( 0 !== $result['code'] ) {
	sscribe_fail( 'WP-CLI i18n make-pot failed with exit code ' . $result['code'] . '.' );
}

sscribe_log( 'i18n', 'POT generation complete.' );
