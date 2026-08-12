<?php
/**
 * SScribe Version Bumper
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

if ('cli' !== php_sapi_name()) {
	exit('This script must be run from the command line.' . PHP_EOL);
}

if ($argc < 2) {
	echo "Usage: php scripts/bump-version.php <new-version>\n";
	echo "Example: php scripts/bump-version.php 1.1.0\n";
	exit(1);
}

$new_version = $argv[1];

if (!preg_match('/^\d+\.\d+\.\d+$/', $new_version)) {
	echo "Error: Version must be in format major.minor.patch (e.g., 1.1.0)\n";
	exit(1);
}

$root_dir = dirname(__DIR__);

$plugin_file = $root_dir . '/sscribe-export-site-pages.php';
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

$plugin_content = file_get_contents($plugin_file);
if (!preg_match("/define\s*\(\s*['\"]SSCRIBE_VERSION['\"]\s*,\s*['\"]([0-9.]+)['\"]/U", $plugin_content, $matches)) {
	echo "Error: Could not find SSCRIBE_VERSION constant.\n";
	exit(1);
}
$old_version = $matches[1];

if ($old_version === $new_version) {
	echo "Already at version {$new_version}.\n";
	echo "Re-scanning for stray references anyway...\n\n";
	$rescan_only = true;
} else {
	$rescan_only = false;
}

if (! $rescan_only) {
	echo "Bumping version: {$old_version} → {$new_version}\n\n";
}

$locations = array(

	array(
		'file' => $root_dir . '/sscribe-export-site-pages.php',
		'pattern' => '/(\*\s*Version:\s*)' . preg_quote($old_version, '/') . '/',
		'replace' => '${1}' . $new_version,
		'label' => 'Plugin header Version',
	),

	array(
		'file' => $root_dir . '/sscribe-export-site-pages.php',
		'pattern' => "/(define\s*\(\s*['\"]SSCRIBE_VERSION['\"]\s*,\s*['\"]){$old_version}(['\"])/U",
		'replace' => '${1}' . $new_version . '${2}',
		'label' => 'SSCRIBE_VERSION constant',
	),

	array(
		'file' => $root_dir . '/readme.txt',
		'pattern' => '/(Stable tag:\s*)' . preg_quote($old_version, '/') . '/',
		'replace' => '${1}' . $new_version,
		'label' => 'Readme Stable tag',
	),

	array(
		'file' => $root_dir . '/readme.txt',
		'pattern' => '/(= )' . preg_quote($old_version, '/') . '( =)/',
		'replace' => '${1}' . $new_version . '${2}',
		'label' => 'Readme Upgrade notice / Changelog headings',
	),

	array(
		'file' => $root_dir . '/admin/css/sscribe-admin.css',
		'pattern' => '/(\*\s*@version\s+)' . preg_quote($old_version, '/') . '/',
		'replace' => '${1}' . $new_version,
		'label' => 'CSS @version header',
	),

	array(
		'file' => $root_dir . '/languages/sscribe-export-site-pages.pot',
		'pattern' => '/(Project-Id-Version: SScribe Export Site Pages )' . preg_quote($old_version, '/') . '/',
		'replace' => '${1}' . $new_version,
		'label' => 'POT Project-Id-Version',
	),

	array(
		'file' => $root_dir . '/composer.json',
		'pattern' => '/("version":\s*")' . preg_quote($old_version, '/') . '(")/',
		'replace' => '${1}' . $new_version . '${2}',
		'label' => 'composer.json version',
		'optional' => true,
	),

	array(
		'file' => $root_dir . '/package.json',
		'pattern' => '/("version":\s*")' . preg_quote($old_version, '/') . '(")/',
		'replace' => '${1}' . $new_version . '${2}',
		'label' => 'package.json version',
		'optional' => true,
	),

	array(
		'file' => $root_dir . '/package-lock.json',
		'pattern' => '/("name":\s*"sscribe-export-site-pages",\s*"version":\s*")' . preg_quote($old_version, '/') . '(")/',
		'replace' => '${1}' . $new_version . '${2}',
		'label' => 'package-lock.json project versions',
		'optional' => true,
	),
);

$updated_count = 0;
$errors = array();

if ($rescan_only) {
	echo "Skipping canonical location updates (no version transition).\n\n";
}

foreach ($locations as $loc) {
	if ($rescan_only) {
		break;
	}
	$file = $loc['file'];

	if (!file_exists($file)) {
		if (!empty($loc['optional'])) {
			echo "  ⚠  {$loc['label']}: file not found (optional, skipped)\n";
			continue;
		}
		$errors[] = "  ✗  {$loc['label']}: file not found: {$file}";
		continue;
	}

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

	$content = file_get_contents($file);

	if (!preg_match(str_replace($old_version, '(' . preg_quote($old_version, '/') . ')', $loc['pattern']), $content)) {
		if (!empty($loc['optional'])) {
			continue;
		}
		$errors[] = "  ✗  {$loc['label']}: version {$old_version} not found.";
		continue;
	}

	$new_content = preg_replace($loc['pattern'], $loc['replace'], $content, -1, $count);

	if ($new_content === $content) {
		$errors[] = "  ✗  {$loc['label']}: no replacements made.";
		continue;
	}

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

	file_put_contents($file, $new_content);
	echo "  ✓  {$loc['label']}: {$file}\n";
	$updated_count += $count;
}

echo "\n";

if ($rescan_only) {
	echo "Scanning for stray references that don't match {$new_version}...\n";
	// In rescan-only mode, $old_version === $new_version, so a literal
	// regex/str_replace would be a no-op. Instead, scan for ANY
	// \d+\.\d+\.\d+ pattern that doesn't equal $new_version and report it.
	$scan_target_regex = '/\b(\d+\.\d+\.\d+)\b/';
	$scan_target_desc  = "non-{$new_version}";
} else {
	echo "Deep scanning for stray {$old_version} references...\n";
	$scan_target_regex = '/\b' . preg_quote($old_version, '/') . '\b/';
	$scan_target_desc  = "{$old_version}";
}

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
$scan_extensions = array('php', 'txt', 'css', 'json', 'pot', 'js', 'yml', 'yaml', 'neon', 'xml');

$version_regex = $scan_target_regex;
$stray_found = array();
$skip_patterns = array(
	'readme.txt' => 'Changelog historical entries are intentional.',
	'class-sscribe-upgrader.php' => 'Historical migration version_compare() calls.',
	'class-sscribe-activator.php' => 'Legacy cleanup version references.',
	'class-sscribe-container-exception.php' => 'Historical @since tags.',
	'class-sscribe-container-notfound-exception.php' => 'Historical @since tags.',
	'class-sscribe-container.php' => 'Historical @since tags.',
	'class-sscribe-content-parser.php' => 'Historical @since tags.',
	'class-sscribe-docx-content-renderer.php' => 'Historical @since tags.',
	'class-sscribe-export-query-controller.php' => 'Historical @since tags.',
	'class-sscribe-exporter.php' => 'Historical @since tags.',
	'interface-sscribe-exporter.php' => 'Historical @since tags.',
	'class-sscribe-diagnostics.php' => 'Plugin diagnostic self-version report.',
	'class-sscribe-filesystem.php' => 'Operational changelog comments.',
	'class-sscribe-deactivator.php' => 'Deactivation housekeeping comments.',
	'sscribe-admin.js' => 'Historical @since docblock tags and patch-note comments.',
	'sscribe-debug-console.js' => 'Historical @since docblock tags and patch-note comments.',
	'composer.json' => 'Third-party dependency version constraint (phpoffice/phpword).',
	'build-release.php' => 'Example version literals in CLI usage strings.',
	'verify-version-sync.php' => 'Example version literals in CLI usage strings and regex constants.',
	'bump-version.php' => 'Example version literals in CLI usage strings and regex constants.',
	'bootstrap.php' => 'Test fixture fallback version literal.',
	'CHANGELOG.md' => 'Changelog entries.',
	'.pot' => 'Translation file headers (handled above).',
);

$all_files = $scan_files;
foreach ($scan_dirs as $dir) {
	if (!is_dir($dir)) {
		continue;
	}
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
	);
	foreach ($iterator as $file_info) {
		if ($file_info->isFile() && in_array($file_info->getExtension(), $scan_extensions, true)) {
			$all_files[] = $file_info->getPathname();
		}
	}
}

foreach ($all_files as $file) {
	if (!file_exists($file)) {
		continue;
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

	$file_content = file_get_contents($file);
	if (preg_match_all($version_regex, $file_content, $v_matches)) {
		$canonical_major = (int) strtok($new_version, '.');
		// In rescan mode, filter out matches that already equal $new_version
		// (they're canonical and correct) AND matches whose major version
		// differs (those are IP addresses, PHPUnit versions, etc., not
		// SScribe version references — same logic verify-version-sync.php
		// uses). In transition mode, all matches are strays of $old_version
		// (which has the same major as $new_version), so they all qualify.
		if ($rescan_only) {
			$noncanonical = array_filter(
				$v_matches[0],
				static function ($m) use ($new_version, $canonical_major) {
					if ($m === $new_version) {
						return false;
					}
					$maj = (int) strtok($m, '.');
					return $maj === $canonical_major;
				}
			);
			if (empty($noncanonical)) {
				continue;
			}
			$stray_count = count($noncanonical);
		} else {
			$stray_count = count($v_matches[0]);
		}
		$basename = basename($file);
		$skip = false;
		// Test fixtures intentionally encode historical version references
		// (regression fixtures for diagnostics on older installs, changelog
		// references in @since / comment blocks). Tests/ is not shipped in
		// the production artifact, so stale refs there are non-blocking.
		$relative_path = str_replace($root_dir . '/', '', $file);
		if ( 0 === strpos( $relative_path, 'tests/' ) || 0 === strpos( $relative_path, 'tests\\' ) ) {
			continue;
		}
		foreach ($skip_patterns as $pattern => $reason) {

			$match = str_starts_with($pattern, '.')
				? str_ends_with($basename, $pattern)
				: ($basename === $pattern);
			if ($match || str_contains($basename, $pattern)) {
				$skip = true;
				break;
			}
		}
		if ($skip) {
			continue;
		}
		$relative = str_replace($root_dir . '/', '', $file);

		if ($rescan_only) {
			$stray_found[] = sprintf(
				'  ↳ %s: %d reference(s) (need manual review): %s',
				$relative,
				$stray_count,
				implode(', ', array_values(array_unique($noncanonical)))
			);
		} else {
			$replaced = str_replace($old_version, $new_version, $file_content);
			$stray_found[] = sprintf('  ↳ %s: %d reference(s) → auto-updated', $relative, $stray_count);
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

			file_put_contents($file, $replaced);
			$updated_count += $stray_count;
		}
	}
}

if (!empty($stray_found)) {
	echo "\n";
	if ($rescan_only) {
		echo "Stray references that don't match {$new_version} (manual review required):\n";
	} else {
		echo "Auto-updated stray references:\n";
	}
	foreach ($stray_found as $sf) {
		echo $sf . "\n";
	}
} else {
	echo "  ✓  No stray references found.\n";
}

echo "\n";

echo "Verifying version sync...\n";

$verify_cmd = sprintf('php %s/scripts/verify-version-sync.php', $root_dir);
exec($verify_cmd, $verify_output, $verify_exit);

echo implode("\n", $verify_output) . "\n\n";

if (0 !== $verify_exit) {
	echo "⚠  Version sync check reported warnings or errors (see above).\n";
	echo "   Manual review may be needed for changelog entries.\n";
}

if (!empty($errors)) {
	echo "Errors:\n";
	foreach ($errors as $e) {
		echo $e . "\n";
	}
	exit(1);
}

echo "═══════════════════════════════════════\n";
if ($rescan_only) {
	echo "✓ Rescan complete at version {$new_version}\n";
	if (! empty($stray_found)) {
		echo "✗ Stray references found — manual review needed above.\n";
		exit(1);
	}
	echo "✓ No stray references found.\n";
} else {
	echo "✓ Version bumped: {$old_version} → {$new_version}\n";
	echo "✓ Files updated: {$updated_count} reference(s)\n";
	echo "✓ Don't forget to:\n";
	echo "    1. Update CHANGELOG.md (if exists)\n";
	echo "    2. Add changelog entry in readme.txt\n";
	echo "    3. Run: composer build && composer test\n";
	echo "    4. Commit: git commit -am 'chore: bump version to v{$new_version}'\n";
}
echo "═══════════════════════════════════════\n";

exit(0);
