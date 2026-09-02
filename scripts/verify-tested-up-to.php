<?php
/**
 * Phase 26: "Tested up to" contract.
 *
 * The `Tested up to:` header in readme.txt tells WP.org operators the
 * latest WordPress version the plugin has been tested on. A stale
 * value is a release-time hazard:
 *
 *   - A plugin claiming `Tested up to: 5.9` while shipping on a
 *     WordPress 6.4 codebase trips Plugin Check's "tested header
 *     mismatch" warning at submission.
 *
 *   - A plugin claiming `Tested up to: 9.9` (a future version that
 *     does not exist) fails Plugin Check's basic format validation
 *     and is rejected outright.
 *
 *   - A plugin with no `Tested up to:` header is auto-flagged by
 *     WP.org as untested and gets deprioritized in search.
 *
 * This verifier enforces:
 *
 *   1. readme.txt MUST contain a `Tested up to: X.Y.Z` header.
 *   2. The value MUST parse as X.Y semver (X.Y.Z also accepted).
 *   3. The value MUST be >= the canonical WordPress minimum (6.0)
 *      the plugin itself declares.
 *   4. The value MUST be <= the canonical freshness ceiling (8.0).
 *      A value above the ceiling is almost certainly a typo or a
 *      speculative claim. The ceiling is updated on each WP major
 *      release; bump it from a single, explicit constant.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

$root_dir = dirname( __DIR__ );

$readme_path = $root_dir . '/readme.txt';
if ( ! is_file( $readme_path ) ) {
	fwrite( STDERR, "readme.txt not found at {$readme_path}\n" );
	exit( 1 );
}

// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$readme = (string) file_get_contents( $readme_path );

$WP_MIN     = '6.0';
$WP_CEILING = '8.0';

$errors = array();

$tested_up_to = '';
if ( preg_match( '/^Tested up to:\s*([0-9]+(?:\.[0-9]+){1,3})\s*$/m', $readme, $m ) ) {
	$tested_up_to = trim( (string) $m[1] );
}

if ( '' === $tested_up_to ) {
	$errors[] = 'readme.txt is missing the `Tested up to: X.Y.Z` header. WP.org auto-flags plugins without this header as untested.';
}

if ( '' !== $tested_up_to ) {
	// Strip the trailing .0 if present so version_compare works.
	$normalized = $tested_up_to;

	if ( version_compare( $normalized, $WP_MIN, '<' ) ) {
		$errors[] = sprintf(
			'`Tested up to: %s` is below the canonical WordPress minimum %s the plugin declares. Bump it to at least the current release.',
			$tested_up_to,
			$WP_MIN
		);
	}

	if ( version_compare( $normalized, $WP_CEILING, '>' ) ) {
		$errors[] = sprintf(
			'`Tested up to: %s` is above the canonical freshness ceiling %s. Almost certainly a typo or speculative claim. Either correct the version or bump scripts/verify-tested-up-to.php::$WP_CEILING to acknowledge a new WP major release.',
			$tested_up_to,
			$WP_CEILING
		);
	}
}

echo "=== SScribe Tested-up-to Verification ===\n\n";
echo "Tested up to: " . ( '' === $tested_up_to ? '(missing)' : $tested_up_to ) . "\n";
echo "Canonical range: {$WP_MIN} .. {$WP_CEILING}\n\n";

if ( ! empty( $errors ) ) {
	echo "Errors:\n";
	foreach ( $errors as $error ) {
		echo "  ✗ {$error}\n";
	}
	echo "\n";
	exit( 1 );
}

echo "✓ Tested-up-to contract holds.\n";
exit( 0 );
