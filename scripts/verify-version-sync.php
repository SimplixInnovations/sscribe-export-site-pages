<?php
/**
 * SScribe Version Sync Verifier — Phase 51.
 *
 * Source-of-truth contract: SSCRIBE_VERSION constant in
 * sscribe-export-site-pages.php.
 *
 * CI gates this synchronisation BEFORE all other checks (via
 * `composer ci`'s opening `version:check`) and `Do not tag/release
 * until this passes` is the documented release invariant.
 *
 * Verified sources:
 *   - plugin_header (Version: in plugin file docblock)
 *   - constant (SSCRIBE_VERSION define)
 *   - stable_tag (readme.txt)
 *   - css_header / tokens_css_header (admin/css/*.css)
 *   - admin_js_header / debug_js_header (admin/js/*.js)
 *   - pot_header (languages/*.pot)
 *   - package_json / package_lock
 *   - readme_changelog (latest = X.Y.Z block in readme.txt)
 *   - release_scripts (Phase 51): scripts/build-release.php must
 *     define `function get_version()` that reads `Version:` from
 *     the plugin header, and the ZIP filename must derive from
 *     `$version`, not a hardcoded literal
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

if ( 'cli' !== php_sapi_name() ) {
	exit( 'This script must be run from the command line.' );
}

$root_dir         = dirname( __DIR__ );
$version_errors   = array();
$version_warnings = array();

$version_locations = array(
	'plugin_header'    => array(
		'file'    => $root_dir . '/sscribe-export-site-pages.php',
		'pattern' => '/\*\s*Version:\s*([0-9.]+)/',
		'line'    => 6,
	),
	'constant'         => array(
		'file'    => $root_dir . '/sscribe-export-site-pages.php',
		'pattern' => "/define\s*\(\s*['\"]SSCRIBE_VERSION['\"]\s*,\s*['\"]([0-9.]+)['\"]/U",
		'line'    => 30,
	),
	'stable_tag'       => array(
		'file'    => $root_dir . '/readme.txt',
		'pattern' => '/Stable tag:\s*([0-9.]+)/',
		'line'    => 7,
	),
	'css_header'       => array(
		'file'    => $root_dir . '/admin/css/sscribe-admin.css',
		'pattern' => '/@version\s+([0-9.]+)/',
		'line'    => 8,
	),
	'tokens_css_header' => array(
		'file'    => $root_dir . '/admin/css/sscribe-tokens.css',
		'pattern' => '/@version\s+([0-9.]+)/',
		'line'    => 1,
	),
	'admin_js_header'  => array(
		'file'    => $root_dir . '/admin/js/sscribe-admin.js',
		'pattern' => '/@version\s+([0-9.]+)/',
		'line'    => 7,
	),
	'debug_js_header'  => array(
		'file'    => $root_dir . '/admin/js/sscribe-debug-console.js',
		'pattern' => '/@version\s+([0-9.]+)/',
		'line'    => 5,
	),
	'pot_header'       => array(
		'file'    => $root_dir . '/languages/sscribe-export-site-pages.pot',
		'pattern' => '/Project-Id-Version: SScribe Export Site Pages ([0-9.]+)/',
		'line'    => 5,
	),
	'package_json'     => array(
		'file'    => $root_dir . '/package.json',
		'pattern' => '/"version"\s*:\s*"([0-9.]+)"/',
		'line'    => 3,
	),
	'package_lock'     => array(
		'file'    => $root_dir . '/package-lock.json',
		'pattern' => '/"version"\s*:\s*"([0-9.]+)"/',
		'line'    => 3,
	),
	'readme_changelog' => array(
		'file'       => $root_dir . '/readme.txt',
		'pattern'    => '/^= (\d+\.\d+\.\d+) =\s*$/m',
		'get_latest' => true,
	),
);

$versions = array();

foreach ( $version_locations as $name => $location ) {
	$file = $location['file'];

	if ( ! file_exists( $file ) ) {
		$version_errors[] = sprintf( '[%s] File not found: %s', $name, $file );
		continue;
	}

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

	$content = file_get_contents( $file );

	if ( ! preg_match( $location['pattern'], $content, $matches ) ) {
		$version_errors[] = sprintf( '[%s] Version not found in file: %s', $name, $file );
		continue;
	}

	$versions[ $name ] = $matches[1];
}

	foreach ( $version_locations as $name => &$location ) {
		if ( ! empty( $location['get_latest'] ) && isset( $versions[ $name ] ) ) {

			$file = $location['file'];
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

			$content = file_get_contents( $file );
			preg_match_all( $location['pattern'], $content, $matches );
			if ( ! empty( $matches[1] ) ) {

				$unique_versions = array_unique( $matches[1] );
				rsort( $unique_versions, SORT_STRING | SORT_FLAG_CASE );
				$versions[ $name ] = reset( $unique_versions );
			}
		}
	}
	unset( $location );

$scan_dirs  = array(
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
);

$canonical_version = $versions['constant'] ?? ( $versions['plugin_header'] ?? null );

if ( $canonical_version ) {

	$version_regex = '/\b(\d+\.\d+\.\d+)\b/';
	$stray_hits    = array();

	$all_files = $scan_files;
	foreach ( $scan_dirs as $dir ) {
		if ( ! is_dir( $dir ) ) {
			continue;
		}
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS )
		);
		foreach ( $iterator as $file_info ) {
			if ( $file_info->isFile() && in_array( $file_info->getExtension(), array( 'php', 'txt', 'css', 'json', 'pot', 'js' ), true ) ) {
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
		if ( 'sscribe-export-site-pages.pot' === basename( $file ) ) {
			continue;
		}
		if ( preg_match_all( $version_regex, $file_content, $v_matches, PREG_SET_ORDER ) ) {
			foreach ( $v_matches as $v_match ) {
				$found_version = $v_match[1];

				if ( $found_version === $canonical_version ) {
					continue;
				}

				$major = (int) strtok( $found_version, '.' );
				// phpcs:ignore WordPress.PHP.YodaConditions.NotYoda -- both operands are dynamic variables.

				if ( $major !== (int) strtok( $canonical_version, '.' ) ) {
					continue;
				}
				$relative                  = str_replace( $root_dir . '/', '', $file );
				$stray_hits[ $relative ][] = $found_version;
			}
		}
	}

	if ( ! empty( $stray_hits ) ) {
		$has_actual_warnings = false;
		$warning_details     = array();
		foreach ( $stray_hits as $f => $vs ) {

			if ( 'readme.txt' === basename( $f ) ) {
				continue;
			}

		if ( 'class-sscribe-upgrader.php' === basename( $f ) ) {
			continue;
		}

		if ( 'class-sscribe-activator.php' === basename( $f ) ) {
			continue;
		}

		if ( 'class-sscribe-diagnostics.php' === basename( $f ) ) {
			continue;
		}

		if ( 'class-sscribe-exporter.php' === basename( $f ) ) {
			continue;
		}

		if ( 'build-release.php' === basename( $f ) ) {
			continue;
		}

		if ( 'bootstrap.php' === basename( $f ) ) {
			continue;
		}

			if ( 'verify-version-sync.php' === basename( $f ) ) {
				continue;
			}

			if ( 'bump-version.php' === basename( $f ) ) {
				continue;
			}

			// verify-tag-policy.php intentionally preserves v2.0.3 as the
			// historical boundary from which annotated release tags became
			// mandatory. It is policy data, not a stale current-version pin.
			if ( 'verify-tag-policy.php' === basename( $f ) ) {
				continue;
			}

			// Test fixtures intentionally encode historical version references
			// (regression fixtures for diagnostics on older installs, change-log
			// references in @since / comment blocks). Tests/ is not shipped in
			// the production artifact, so stale refs there are non-blocking.
			if ( 0 === strpos( $f, 'tests/' ) || 0 === strpos( $f, 'tests\\' ) ) {
				continue;
			}

			if ( 'sscribe-export-site-pages.php' === basename( $f ) ) {
				continue;
			}

			if ( 'class-sscribe-deactivator.php' === basename( $f ) ) {
				continue;
			}

			if ( 'class-sscribe-filesystem.php' === basename( $f ) ) {
				continue;
			}

			if ( 'class-sscribe-container-exception.php' === basename( $f ) ) {
				continue;
			}

			if ( 'class-sscribe-container-notfound-exception.php' === basename( $f ) ) {
				continue;
			}

			if ( 'class-sscribe-container.php' === basename( $f ) ) {
				continue;
			}

			if ( 'class-sscribe-content-parser.php' === basename( $f ) ) {
				continue;
			}

			if ( 'class-sscribe-docx-content-renderer.php' === basename( $f ) ) {
				continue;
			}

			if ( 'class-sscribe-export-query-controller.php' === basename( $f ) ) {
				continue;
			}

			if ( 'interface-sscribe-exporter.php' === basename( $f ) ) {
				continue;
			}

			// admin/js/ contains historical @since tags and patch-note
			// comments referencing 1.1.x versions; bumping them is incorrect.
			if ( 0 === strpos( $f, 'admin/js/' ) || 0 === strpos( $f, 'admin\\js\\' ) ) {
				continue;
			}

			// composer.json contains a real phpoffice/phpword version
			// constraint (~1.4.0), not a stale version reference.
			if ( 'composer.json' === basename( $f ) ) {
				continue;
			}
			$has_actual_warnings = true;
			$unique              = array_unique( $vs );
			foreach ( $unique as $v ) {
				$warning_details[] = sprintf( '  - %s → contains %s (should be %s)', $f, $v, $canonical_version );
			}
		}

		if ( $has_actual_warnings ) {
			$version_warnings[] = 'Stale version references found in source files:';
			foreach ( $warning_details as $detail ) {
				$version_warnings[] = $detail;
			}
		}
	}
}

if ( count( $versions ) < 2 ) {
	$version_errors[] = 'Could not extract enough version references to compare.';
} else {
	$unique_versions = array_unique( array_values( $versions ) );

	if ( count( $unique_versions ) > 1 ) {
		$version_errors[] = 'Version mismatch detected!';
		foreach ( $versions as $v_name => $v_version ) {
			$version_errors[] = sprintf( '  - %s: %s', $v_name, $v_version );
		}
	}
}

/**
 * Release scripts version contract (Phase 51).
 *
 * scripts/build-release.php MUST derive its version dynamically from
 * the canonical SSCRIBE_VERSION constant (via the plugin header) so a
 * botched release never ships a stale ZIP tag. We statically verify:
 *
 *   - the script defines `function get_version(string, string): string`
 *   - that function reads `Version:` via preg_match (not a literal)
 *   - the script does NOT contain a hardcoded version literal of the
 *     canonical form `X.Y.Z` outside the get_version() function body
 *     (a regression that hardcodes "2.0.1" while SSCRIBE_VERSION is
 *     "2.0.0" ships a broken ZIP tag)
 *   - the ZIP filename uses the dynamic `$version` variable, not a
 *     literal substring
 */
$build_release_path = $root_dir . '/scripts/build-release.php';
$release_script_ok  = true;
if ( ! file_exists( $build_release_path ) ) {
	$version_warnings[] = '[release_scripts] scripts/build-release.php not found.';
	$release_script_ok  = false;
} else {
	$build_source = (string) file_get_contents( $build_release_path );

	// 1. get_version() function exists with the expected signature.
	$has_get_version = (bool) preg_match(
		'#function\s+get_version\s*\(\s*string\s+\$root\s*,\s*string\s+\$plugin_file\s*\)\s*:\s*string\s*\{#',
		$build_source
	);
	if ( ! $has_get_version ) {
		$version_errors[] = '[release_scripts] scripts/build-release.php does not define `function get_version(string $root, string $plugin_file): string`.';
		$release_script_ok = false;
	}

	// 2. The function body reads the version from the plugin header
	// (`Version:` regex) rather than returning a hardcoded literal.
	$reads_dynamically = (bool) preg_match(
		'#preg_match\s*\(\s*[\'"][^\'"]*Version:[^\'"]*[\'"]#',
		$build_source
	);
	if ( ! $reads_dynamically ) {
		$version_errors[] = '[release_scripts] scripts/build-release.php get_version() must read the version via a `Version:` preg_match — not a hardcoded literal.';
		$release_script_ok = false;
	}

	// 3. The ZIP filename uses the {$version} variable, not a literal.
	$zip_uses_variable = (bool) preg_match(
		'#sscribe-export-site-pages-\{\s*\$version\s*\}\.zip#',
		$build_source
	);
	if ( ! $zip_uses_variable ) {
		$version_errors[] = '[release_scripts] scripts/build-release.php ZIP filename must be `sscribe-export-site-pages-{$version}.zip` (variable, not a literal).';
		$release_script_ok = false;
	}

	// 4. The script body (outside the get_version() function) must not
	// contain a hardcoded `X.Y.Z` version literal. A regression that
	// copy-pastes `2.0.0` into the ZIP name while the canonical is
	// `2.0.1` ships a broken release artifact.
	//
	// We strip the get_version() function body before scanning so its
	// `Version:\s*([0-9.]+)` regex (which contains `[0-9.]+`, not an
	// actual version literal) does not falsely trip the check.
	$stripped = (string) preg_replace(
		'#function\s+get_version\s*\([^)]+\)[^}]*\}[^}]*\}#s',
		'',
		$build_source
	);
	$hardcoded_version_hits = array();
	if ( preg_match_all( '/\b(\d+\.\d+\.\d+)\b/', $stripped, $hc_matches, PREG_SET_ORDER ) ) {
		foreach ( $hc_matches as $hc_match ) {
			$candidate = $hc_match[1];
			// Only flag if the candidate is plausible as the current
			// canonical version (same major), otherwise this fires on
			// unrelated pins like mpdf/mpdf ~8.3.0 in copy-pasted
			// notes.
			if ( null !== $canonical_version && substr( $candidate, 0, strpos( $candidate, '.' ) ) !== substr( $canonical_version, 0, strpos( $canonical_version, '.' ) ) ) {
				continue;
			}
			$major = (int) substr( $candidate, 0, strpos( $candidate, '.' ) );
			$canon = (int) substr( $canonical_version, 0, strpos( $canonical_version, '.' ) );
			if ( $major !== $canon ) {
				continue;
			}
			// Allow if the candidate IS the canonical version (in a
			// comment that says "current 2.0.0" etc.) — that drifts the
			// message but does not pin a stale literal.
			if ( null !== $canonical_version && $candidate === $canonical_version ) {
				continue;
			}
			$hardcoded_version_hits[] = $candidate;
		}
	}
	if ( ! empty( $hardcoded_version_hits ) ) {
		$version_errors[] = '[release_scripts] scripts/build-release.php contains hardcoded version literals outside get_version(): ' . implode( ', ', array_unique( $hardcoded_version_hits ) ) . '. The script must derive version dynamically from SSCRIBE_VERSION.';
		$release_script_ok = false;
	}
}
$versions['release_scripts'] = $release_script_ok ? $canonical_version : ( $canonical_version ?? '' );


if ( ! empty( $versions['constant'] ) ) {
	$readme_file = $root_dir . '/readme.txt';
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

	$readme_content    = file_get_contents( $readme_file );
	$changelog_pattern = '/= ' . preg_quote( $versions['constant'], '/' ) . ' =/';

	if ( ! preg_match( $changelog_pattern, $readme_content ) ) {
		$version_warnings[] = sprintf( 'Changelog entry not found for version %s', $versions['constant'] );
	}

	// Scope the regex to the Upgrade Notice section so it does not match the
	// Changelog, which also contains `= X.Y.Z =` headers (one per historical
	// release). Without scoping, the first match lands inside the Changelog
	// span and the captured length is too short to pass the threshold below.
	$upgrade_scope = '';
	if ( preg_match( '/^== Upgrade Notice ==$(.*?)(?=^== |\z)/sm', $readme_content, $scope_match ) ) {
		$upgrade_scope = $scope_match[1];
	}
	$upgrade_pattern = '/= ' . preg_quote( $versions['constant'], '/' ) . ' =[\s\S]*?(?== [0-9]|\z)/';
	if ( '' !== $upgrade_scope && preg_match( $upgrade_pattern, $upgrade_scope, $upgrade_section ) ) {
		if ( strlen( trim( $upgrade_section[0] ) ) < 50 ) {
			$version_warnings[] = 'Upgrade notice section appears to be missing or too short.';
		}
	}
}

echo "=== SScribe Version Verification ===\n\n";

if ( ! empty( $versions ) ) {
	echo "Versions found:\n";
	foreach ( $versions as $v_name => $v_version ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		printf( "  ✓ %-15s: %s\n", $v_name, $v_version );
	}
	echo "\n";
}

if ( ! empty( $version_warnings ) ) {
	echo "Warnings:\n";
	foreach ( $version_warnings as $v_warning ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		printf( "  ⚠ %s\n", $v_warning );
	}
	echo "\n";
}

if ( ! empty( $version_errors ) ) {
	echo "Errors:\n";
	foreach ( $version_errors as $v_error ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		printf( "  ✗ %s\n", $v_error );
	}
	echo "\n";
	exit( 1 );
}

echo "✓ All version references are synchronized.\n";
echo "✓ All checks passed.\n";
exit( 0 );
