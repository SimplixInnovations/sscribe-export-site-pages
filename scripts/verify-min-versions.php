<?php
/**
 * Phase 25: WP/PHP minimum contract.
 *
 * The plugin declares its minimum supported versions in three places
 * that MUST stay synchronized:
 *
 *   1. sscribe-export-site-pages.php plugin header
 *        - `* Requires at least: X.Y`
 *        - `* Requires PHP:      X.Y`
 *
 *   2. readme.txt header
 *        - `Requires at least: X.Y`
 *        - `Requires PHP: X.Y`
 *
 *   3. The runtime version_compare() guard inside the plugin bootstrap:
 *        `version_compare( PHP_VERSION, 'X.Y', '<' )`
 *
 * Drift between these surfaces is a release blocker:
 *
 *   - If readme.txt says PHP 8.2 but the bootstrap guard checks 8.1,
 *     a site on 8.1.x passes the WP.org install check (no fatal), then
 *     hits a fatal inside the plugin on first admin page load.
 *
 *   - If the mainfile header says WP 6.5 but readme.txt says 6.0, WP.org
 *     displays the lower minimum and operators on 6.0..6.4 install a
 *     build that requires 6.5. The upgrade banner shows the lower value
 *     and the install fails with "Plugin does not have a valid header".
 *
 * This verifier enforces:
 *
 *   - All four locations are present and parse as X.Y (or X.Y.Z) semver.
 *   - The four values match pairwise.
 *   - The runtime version_compare() guard uses the same version string
 *     as the PHP minimum.
 *   - The PHP minimum is at least 8.2 (current LTS contract).
 *   - The WP minimum is at least 6.1, the first WordPress release whose
 *     official compatibility matrix supports PHP 8.2.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

$root_dir = dirname( __DIR__ );

$mainfile_path = $root_dir . '/sscribe-export-site-pages.php';
$readme_path   = $root_dir . '/readme.txt';

foreach ( array( $mainfile_path, $readme_path ) as $path ) {
	if ( ! is_file( $path ) ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		fwrite( STDERR, "Missing required file: {$path}\n" );
		exit( 1 );
	}
}

// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$mainfile = (string) file_get_contents( $mainfile_path );
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$readme = (string) file_get_contents( $readme_path );

$errors = array();

/**
 * Extract the first match of /Key:\s*VALUE/ from a blob.
 */
$extract = static function ( string $blob, string $key ): string {
	if ( preg_match( '/^\s*\*?\s*' . preg_quote( $key, '/' ) . ':\s*([0-9]+(?:\.[0-9]+){1,3})\s*$/m', $blob, $m ) ) {
		return trim( (string) $m[1] );
	}
	return '';
};

$mainfile_wp_min  = $extract( $mainfile, 'Requires at least' );
$mainfile_php_min = $extract( $mainfile, 'Requires PHP' );
$readme_wp_min    = $extract( $readme, 'Requires at least' );
$readme_php_min   = $extract( $readme, 'Requires PHP' );

// 1. Presence and format.
if ( '' === $mainfile_wp_min ) {
	$errors[] = 'sscribe-export-site-pages.php is missing the `Requires at least:` header line.';
}
if ( '' === $mainfile_php_min ) {
	$errors[] = 'sscribe-export-site-pages.php is missing the `Requires PHP:` header line.';
}
if ( '' === $readme_wp_min ) {
	$errors[] = 'readme.txt is missing the `Requires at least:` line.';
}
if ( '' === $readme_php_min ) {
	$errors[] = 'readme.txt is missing the `Requires PHP:` line.';
}

// 2. Pairwise equality.
if ( '' !== $mainfile_wp_min && '' !== $readme_wp_min && $mainfile_wp_min !== $readme_wp_min ) {
	$errors[] = sprintf(
		'WordPress minimum drift: mainfile header says %s, readme.txt says %s. Operators see the lower value and install on incompatible sites.',
		$mainfile_wp_min,
		$readme_wp_min
	);
}
if ( '' !== $mainfile_php_min && '' !== $readme_php_min && $mainfile_php_min !== $readme_php_min ) {
	$errors[] = sprintf(
		'PHP minimum drift: mainfile header says %s, readme.txt says %s. Pick one canonical minimum.',
		$mainfile_php_min,
		$readme_php_min
	);
}

// 3. Runtime version_compare guard uses the same PHP minimum.
if ( '' !== $mainfile_php_min ) {
	if ( ! preg_match( '/version_compare\(\s*PHP_VERSION\s*,\s*[\'"]' . preg_quote( $mainfile_php_min, '/' ) . '[\'"]/', $mainfile ) ) {
		$errors[] = sprintf(
			'PHP runtime guard does not match header: expected `version_compare( PHP_VERSION, \'%s\', \'<\' )` somewhere in the bootstrap. Without it, a site on PHP %s-0.1 passes WP.org install and hits a fatal inside the plugin.',
			$mainfile_php_min,
			$mainfile_php_min
		);
	}
}

// 4. Minimum floors (canonical contract).
$PHP_FLOOR = '8.2';
$WP_FLOOR  = '6.1';
if ( '' !== $mainfile_php_min && version_compare( $mainfile_php_min, $PHP_FLOOR, '<' ) ) {
	$errors[] = sprintf(
		'PHP minimum %s is below the canonical floor %s. Update the header and the runtime guard together.',
		$mainfile_php_min,
		$PHP_FLOOR
	);
}
if ( '' !== $mainfile_wp_min && version_compare( $mainfile_wp_min, $WP_FLOOR, '<' ) ) {
	$errors[] = sprintf(
		'WordPress minimum %s is below the canonical floor %s. Update the header and the readme together.',
		$mainfile_wp_min,
		$WP_FLOOR
	);
}

echo "=== SScribe WP/PHP Minimum Verification ===\n\n";
echo "Mainfile: WP {$mainfile_wp_min} / PHP {$mainfile_php_min}\n";
echo "Readme:   WP {$readme_wp_min} / PHP {$readme_php_min}\n\n";

if ( ! empty( $errors ) ) {
	echo "Errors:\n";
	foreach ( $errors as $error ) {
		echo "  ✗ {$error}\n";
	}
	echo "\n";
	exit( 1 );
}

echo "✓ WP/PHP minimum contract holds.\n";
exit( 0 );
