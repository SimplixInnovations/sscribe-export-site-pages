<?php

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
			if ( $file_info->isFile() && in_array( $file_info->getExtension(), array( 'php', 'txt', 'css', 'json', 'pot' ), true ) ) {
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

			if ( 'sscribe-export-site-pages.php' === basename( $f ) ) {
				continue;
			}

			if ( 'class-sscribe-deactivator.php' === basename( $f ) ) {
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

if ( ! empty( $versions['constant'] ) ) {
	$readme_file = $root_dir . '/readme.txt';
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

	$readme_content    = file_get_contents( $readme_file );
	$changelog_pattern = '/= ' . preg_quote( $versions['constant'], '/' ) . ' =/';

	if ( ! preg_match( $changelog_pattern, $readme_content ) ) {
		$version_warnings[] = sprintf( 'Changelog entry not found for version %s', $versions['constant'] );
	}

	$upgrade_pattern = '/= ' . preg_quote( $versions['constant'], '/' ) . ' =[\s\S]*?(?== [0-9]|\z)/';
	if ( preg_match( $upgrade_pattern, $readme_content, $upgrade_section ) ) {
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
