<?php
/**
 * Automated version bump script.
 *
 * Updates all version references across the entire plugin in a single atomic operation.
 * Run: php scripts/bump-version.php 3.50.0
 *
 * @package SScribe
 */

declare(strict_types=1);

if ( 'cli' !== php_sapi_name() ) {
	exit( 'This script must be run from the command line.' . PHP_EOL );
}

if ( $argc < 2 ) {
	echo "Usage: php scripts/bump-version.php <new-version>\n";
	echo "Example: php scripts/bump-version.php 3.50.0\n";
	exit( 1 );
}

$new_version = $argv[1];

// Validate version format (semver-like: major.minor.patch).
if ( ! preg_match( '/^\d+\.\d+\.\d+$/', $new_version ) ) {
	echo "Error: Version must be in format major.minor.patch (e.g., 3.50.0)\n";
	exit( 1 );
}

$root_dir = dirname( __DIR__ );

// Detect current version from the constant.
$plugin_file = $root_dir . '/sscribe-export-site-pages.php';
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$plugin_content = file_get_contents( $plugin_file );
if ( ! preg_match( "/define\s*\(\s*['\"]SSCRIBE_VERSION['\"]\s*,\s*['\"]([0-9.]+)['\"]/U", $plugin_content, $matches ) ) {
	echo "Error: Could not find SSCRIBE_VERSION constant.\n";
	exit( 1 );
}
$old_version = $matches[1];

if ( $old_version === $new_version ) {
	echo "Already at version {$new_version}. Nothing to do.\n";
	exit( 0 );
}

echo "Bumping version: {$old_version} → {$new_version}\n\n";

// ============================================================================
// 1. DEFINE ALL VERSION LOCATIONS
// ============================================================================
// Each entry: file path, search pattern (with capture group for version), replacement callback or pattern.
$locations = array(
	// Core plugin file — Version header.
	array(
		'file'    => $root_dir . '/sscribe-export-site-pages.php',
		'pattern' => '/(\*\s*Version:\s*)' . preg_quote( $old_version, '/' ) . '/',
		'replace' => '${1}' . $new_version,
		'label'   => 'Plugin header Version',
	),
	// Core plugin file — SSCRIBE_VERSION constant.
	array(
		'file'    => $root_dir . '/sscribe-export-site-pages.php',
		'pattern' => "/(define\s*\(\s*['\"]SSCRIBE_VERSION['\"]\s*,\s*['\"]){$old_version}(['\"])/U",
		'replace' => '${1}' . $new_version . '${2}',
		'label'   => 'SSCRIBE_VERSION constant',
	),
	// Readme — Stable tag.
	array(
		'file'    => $root_dir . '/readme.txt',
		'pattern' => '/(Stable tag:\s*)' . preg_quote( $old_version, '/' ) . '/',
		'replace' => '${1}' . $new_version,
		'label'   => 'Readme Stable tag',
	),
	// Readme — Upgrade notice heading.
	array(
		'file'    => $root_dir . '/readme.txt',
		'pattern' => '/(= )' . preg_quote( $old_version, '/' ) . '( =)/',
		'replace' => '${1}' . $new_version . '${2}',
		'label'   => 'Readme Upgrade notice / Changelog headings',
	),
	// CSS — @version.
	array(
		'file'    => $root_dir . '/admin/css/sscribe-admin.css',
		'pattern' => '/(\*\s*@version\s+)' . preg_quote( $old_version, '/' ) . '/',
		'replace' => '${1}' . $new_version,
		'label'   => 'CSS @version header',
	),
	// POT — Project-Id-Version.
	array(
		'file'    => $root_dir . '/languages/sscribe-export-site-pages.pot',
		'pattern' => '/(Project-Id-Version: SScribe Export Site Pages )' . preg_quote( $old_version, '/' ) . '/',
		'replace' => '${1}' . $new_version,
		'label'   => 'POT Project-Id-Version',
	),
	// Composer.json version (if present).
	array(
		'file'    => $root_dir . '/composer.json',
		'pattern' => '/("version":\s*")' . preg_quote( $old_version, '/' ) . '(")/',
		'replace' => '${1}' . $new_version . '${2}',
		'label'   => 'composer.json version',
		'optional' => true,
	),
	// Package.json version (if present).
	array(
		'file'    => $root_dir . '/package.json',
		'pattern' => '/("version":\s*")' . preg_quote( $old_version, '/' ) . '(")/',
		'replace' => '${1}' . $new_version . '${2}',
		'label'   => 'package.json version',
		'optional' => true,
	),
);

// ============================================================================
// 2. UPDATE ALL DEFINED LOCATIONS
// ============================================================================
$updated_count = 0;
$errors        = array();

foreach ( $locations as $loc ) {
	$file = $loc['file'];

	if ( ! file_exists( $file ) ) {
		if ( ! empty( $loc['optional'] ) ) {
			echo "  ⚠  {$loc['label']}: file not found (optional, skipped)\n";
			continue;
		}
		$errors[] = "  ✗  {$loc['label']}: file not found: {$file}";
		continue;
	}

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$content = file_get_contents( $file );

	if ( ! preg_match( str_replace( $old_version, '(' . preg_quote( $old_version, '/' ) . ')', $loc['pattern'] ), $content ) ) {
		if ( ! empty( $loc['optional'] ) ) {
			continue;
		}
		$errors[] = "  ✗  {$loc['label']}: version {$old_version} not found.";
		continue;
	}

	$new_content = preg_replace( $loc['pattern'], $loc['replace'], $content, -1, $count );

	if ( $new_content === $content ) {
		$errors[] = "  ✗  {$loc['label']}: no replacements made.";
		continue;
	}

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	file_put_contents( $file, $new_content );
	echo "  ✓  {$loc['label']}: {$file}\n";
	$updated_count += $count;
}

echo "\n";

// ============================================================================
// 3. DEEP SCAN — find ALL remaining stray version references in source files
// ============================================================================
echo "Deep scanning for stray {$old_version} references...\n";

$scan_dirs = array(
	$root_dir . '/includes/',
	$root_dir . '/admin/',
	$root_dir . '/scripts/',
	$root_dir . '/tests/',
	$root_dir . '/languages/',
);
$scan_files = array(
	$root_dir . '/sscribe-export-site-pages.php',
	$root_dir . '/readme.txt',
	$root_dir . '/uninstall.php',
	$root_dir . '/composer.json',
	$root_dir . '/package.json',
);
$scan_extensions = array( 'php', 'txt', 'css', 'json', 'pot', 'js', 'yml', 'yaml', 'neon', 'xml' );

$version_regex = '/\b' . preg_quote( $old_version, '/' ) . '\b/';
$stray_found   = array();
$skip_patterns = array(
	'readme.txt'           => 'Changelog historical entries are intentional.',
	'class-sscribe-upgrader.php' => 'Historical migration version_compare() calls.',
	'class-sscribe-activator.php' => 'Legacy cleanup version references.',
	'CHANGELOG.md'          => 'Changelog entries.',
	'.pot'                  => 'Translation file headers (handled above).',
);

$all_files = $scan_files;
foreach ( $scan_dirs as $dir ) {
	if ( ! is_dir( $dir ) ) {
		continue;
	}
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS )
	);
	foreach ( $iterator as $file_info ) {
		if ( $file_info->isFile() && in_array( $file_info->getExtension(), $scan_extensions, true ) ) {
			$all_files[] = $file_info->getPathname();
		}
	}
}

foreach ( $all_files as $file ) {
	if ( ! file_exists( $file ) ) {
		continue;
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$file_content = file_get_contents( $file );
	if ( preg_match_all( $version_regex, $file_content, $v_matches ) ) {
		$basename = basename( $file );
		$skip     = false;
		foreach ( $skip_patterns as $pattern => $reason ) {
			if ( fnmatch( $pattern, $basename ) || str_contains( $basename, $pattern ) ) {
				$skip = true;
				break;
			}
		}
		if ( $skip ) {
			continue;
		}
		$relative = str_replace( $root_dir . '/', '', $file );
		// Auto-update: replace all remaining stray references.
		$replaced      = str_replace( $old_version, $new_version, $file_content );
		$stray_count   = count( $v_matches[0] );
		$stray_found[] = sprintf( '  ↳ %s: %d reference(s) → auto-updated', $relative, $stray_count );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $file, $replaced );
		$updated_count += $stray_count;
	}
}

if ( ! empty( $stray_found ) ) {
	echo "\nAuto-updated stray references:\n";
	foreach ( $stray_found as $sf ) {
		echo $sf . "\n";
	}
} else {
	echo "  ✓  No stray references found.\n";
}

echo "\n";

// ============================================================================
// 4. VERIFY
// ============================================================================
echo "Verifying version sync...\n";

$verify_cmd = sprintf( 'php %s/scripts/verify-version-sync.php', $root_dir );
exec( $verify_cmd, $verify_output, $verify_exit );

echo implode( "\n", $verify_output ) . "\n\n";

if ( 0 !== $verify_exit ) {
	echo "⚠  Version sync check reported warnings or errors (see above).\n";
	echo "   Manual review may be needed for changelog entries.\n";
}

// ============================================================================
// 5. SUMMARY
// ============================================================================
if ( ! empty( $errors ) ) {
	echo "Errors:\n";
	foreach ( $errors as $e ) {
		echo $e . "\n";
	}
	exit( 1 );
}

echo "═══════════════════════════════════════\n";
echo "✓ Version bumped: {$old_version} → {$new_version}\n";
echo "✓ Files updated: {$updated_count} reference(s)\n";
echo "✓ Don't forget to:\n";
echo "    1. Update CHANGELOG.md (if exists)\n";
echo "    2. Add changelog entry in readme.txt\n";
echo "    3. Run: composer build && composer test\n";
echo "    4. Commit: git commit -am 'chore: bump version to v{$new_version}'\n";
echo "═══════════════════════════════════════\n";

exit( 0 );









