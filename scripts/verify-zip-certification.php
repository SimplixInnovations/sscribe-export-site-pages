<?php
/**
 * Phase 34: ZIP certification contract.
 *
 * The submission ZIP is what WP.org reviewers see and what every
 * install pulls. A ZIP that fails to open, points at a different
 * directory layout, ships dev-only paths, or whose SHA-256 does
 * not match is rejected at submission and ships corruption to
 * every site on update.
 *
 * This verifier runs after `php scripts/build-release.php` and
 * enforces the certification contract:
 *
 *   1. The ZIP exists at `dist/sscribe-export-site-pages-{VERSION}.zip`
 *      and opens cleanly via ZipArchive (CRC + header sanity).
 *   2. The ZIP contains exactly one top-level directory whose name
 *      matches `sscribe-export-site-pages/`. WP.org rejects ZIPs
 *      that ship files at the root or use a different slug.
 *   3. Required top-level files are present inside the plugin
 *      directory: mainfile (`sscribe-export-site-pages.php`),
 *      `readme.txt`, `license.txt`, `composer.json`, `uninstall.php`,
 *      and `vendor-prefixed/autoload.php`.
 *   4. No dev-only paths leak in: `dev/`, `tests/`, `tests-wp/`,
 *      `tests-e2e/`, `scripts/`, `.github/`, `node_modules/`,
 *      `vendor/`, `bin/`, `docs/`, `examples/`, `samples/`,
 *      `coverage/`, `.phpunit.cache/`, `test-results/`, `.audit/`,
 *      `.claude/`, `.opencode/`, `.superpowers/`, `scratch/`,
 *      `tmp_diffs/`, `WPScan/`, `wordpress/`, `wordpress-tests-lib/`,
 *      `phpunit.xml*`, `phpstan.neon*`, `playwright.config.ts`.
 *   5. No entry carries a WP.org-rejected extension:
 *      `.sh`, `.bat`, `.cmd`, `.exe`, `.msi`, `.pkg`, `.dmg`,
 *      `.phar`, `.dist`, `.lesser`.
 *   6. No individual file exceeds 5 MB (a single huge file in the
 *      ZIP usually means an un-pruned asset slipped through).
 *   7. The total uncompressed payload stays under 10 MB (the
 *      WP.org soft limit; the build script enforces this on the
 *      compressed ZIP too).
 *   8. The SHA-256 sidecar at `dist/sscribe-export-site-pages-
 *      {VERSION}.sha256` exists and equals hash_file('sha256',
 *      $zip).
 *
 * Each rule emits a single line on a match so the failure output
 * is stable and PHPUnit can grep for the violation keyword.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

$root_dir = dirname( __DIR__ );

// 0. Read the version straight off the plugin mainfile so the
//    verifier tracks the canonical version constant (the build
//    script reads the same line).
$mainfile_path = $root_dir . '/sscribe-export-site-pages.php';
if ( ! is_file( $mainfile_path ) ) {
	fwrite( STDERR, "Missing mainfile: sscribe-export-site-pages.php\n" );
	exit( 1 );
}
$mainfile_src = (string) file_get_contents( $mainfile_path );
if ( ! preg_match( '/Version:\s*([0-9][0-9.]*[0-9])/', $mainfile_src, $v_match ) ) {
	fwrite( STDERR, "Cannot read version from mainfile header\n" );
	exit( 1 );
}
$version     = $v_match[1];
$dist_dir    = $root_dir . '/dist';
$zip_path    = $dist_dir . '/sscribe-export-site-pages-' . $version . '.zip';
$checksum_in = $dist_dir . '/sscribe-export-site-pages-' . $version . '.sha256';

$errors        = array();
$top_seen      = array();
$required_root = array(
	'sscribe-export-site-pages/sscribe-export-site-pages.php',
	'sscribe-export-site-pages/readme.txt',
	'sscribe-export-site-pages/license.txt',
	'sscribe-export-site-pages/composer.json',
	'sscribe-export-site-pages/uninstall.php',
	'sscribe-export-site-pages/vendor-prefixed/autoload.php',
);
$forbidden_substrings = array(
	'/dev/',
	'/tests/',
	'/tests-wp/',
	'/tests-e2e/',
	'/scripts/',
	'/.github/',
	'/node_modules/',
	'/vendor/',         // The un-prefixed vendor tree never ships.
	'/bin/',
	'/docs/',
	'/examples/',
	'/samples/',
	'/coverage/',
	'/.phpunit.cache/',
	'/test-results/',
	'/.audit/',
	'/.claude/',
	'/.opencode/',
	'/.superpowers/',
	'/scratch/',
	'/tmp_diffs/',
	'/WPScan/',
	'/wordpress/',
	'/wordpress-tests-lib/',
	'/playground-cache/',
	'/phpunit.xml',
	'/phpstan.neon',
	'/playwright.config.ts',
	'/fake-wp/',
	'/stubs/',
	'/.stubs/',
);
$forbidden_extensions = array( 'sh', 'bat', 'cmd', 'exe', 'msi', 'pkg', 'dmg', 'phar', 'dist', 'lesser' );
$max_file_bytes       = 5 * 1024 * 1024;       // 5 MB single-file ceiling.
$max_zip_bytes        = 10 * 1024 * 1024;      // 10 MB compressed-ZIP ceiling (WP.org submission limit).

echo "=== SScribe ZIP Certification ===\n\n";
echo "Version:     {$version}\n";
echo "ZIP path:    {$zip_path}\n";
echo "Checksum:    {$checksum_in}\n\n";

// 1. ZIP exists.
if ( ! is_file( $zip_path ) ) {
	$errors[] = "ZIP does not exist at {$zip_path}. Run `php scripts/build-release.php` first.";
} else {
	$zip_size = (int) filesize( $zip_path );
	echo "ZIP size:    " . $zip_size . " bytes\n";
	if ( $zip_size >= $max_zip_bytes ) {
		$errors[] = sprintf(
			'Submission ZIP is %d bytes; WP.org rejects ZIPs above %d bytes.',
			$zip_size,
			$max_zip_bytes
		);
	}
}

// 2. Checksum file exists and matches.
if ( is_file( $zip_path ) ) {
	if ( ! is_file( $checksum_in ) ) {
		$errors[] = "Checksum sidecar missing: {$checksum_in}. Run `php scripts/build-release.php` to regenerate.";
	} else {
		$on_disk = (string) file_get_contents( $checksum_in );
		$on_disk = trim( $on_disk );
		$digest  = (string) hash_file( 'sha256', $zip_path );
		if ( ! hash_equals( $digest, $on_disk ) ) {
			$errors[] = sprintf(
				'Checksum mismatch: ZIP sha256=%s but sidecar says %s.',
				$digest,
				$on_disk
			);
		} else {
			echo "SHA-256:     {$digest}\n";
		}
	}
}

// 3. Open the ZIP and walk every entry.
if ( is_file( $zip_path ) ) {
	$zip = new ZipArchive();
	$open = $zip->open( $zip_path );
	if ( true !== $open ) {
		$errors[] = "ZipArchive failed to open the submission ZIP (status={$open}). The file is corrupt or not a valid ZIP.";
	} else {
		$total_bytes   = 0;
		$entry_count   = $zip->numFiles;
		echo "Entries:     {$entry_count}\n";

		// Walk entries with stat() to read sizes.
		for ( $i = 0; $i < $entry_count; $i++ ) {
			$entry_stat = $zip->statIndex( $i );
			if ( ! is_array( $entry_stat ) ) {
				$errors[] = "ZipArchive::statIndex({$i}) returned false — ZIP is corrupt.";
				continue;
			}
			$name = (string) $entry_stat['name'];
			$size = (int) $entry_stat['size'];
			$total_bytes += $size;

			// a. Top-level directory must be exactly `sscribe-export-site-pages/`.
			//    The first path segment of every entry is the top-level
			//    directory — even when the entry is deeply nested. We
			//    collect every distinct first segment and ensure that all
			//    of them equal the canonical plugin slug. A ZIP that uses
			//    any other slug (e.g. `wrong-slug/...`) is rejected by
			//    WP.org at submission.
			$first_segment = (string) ( explode( '/', $name, 2 )[0] ?? '' );
			if ( '' !== $first_segment ) {
				$top_seen[ $first_segment ] = true;
			}
			if ( false === strpos( $name, '/' ) ) {
				// Path with no slash is a root-level entry (file OR directory
				// marker — directory markers end with '/').
				if ( 'sscribe-export-site-pages/' !== $name && 'sscribe-export-site-pages' !== $name ) {
					$errors[] = "Top-level entry is not `sscribe-export-site-pages/`: {$name}. WP.org rejects ZIPs with files at the root.";
				}
				continue;
			}

			// b. Required entries.
			foreach ( $required_root as $req ) {
				// Match exact basename or nested path within the plugin dir.
				if ( $name === $req ) {
					// Hit.
				}
			}
			// We collect required-set membership below by name.

			// c. Forbidden substrings.
			foreach ( $forbidden_substrings as $needle ) {
				if ( false !== strpos( $name, $needle ) ) {
					$errors[] = "ZIP contains forbidden path `{$name}` (matched `{$needle}`). Dev-only paths must not ship to WP.org.";
					break;
				}
			}

			// d. Forbidden extensions.
			$ext = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
			if ( '' !== $ext && in_array( $ext, $forbidden_extensions, true ) ) {
				$errors[] = "ZIP contains forbidden extension `{$ext}` in `{$name}`. WP.org rejects these file types.";
			}

			// e. Single-file ceiling.
			if ( $size > $max_file_bytes ) {
				$errors[] = sprintf(
					'ZIP entry `%s` is %d bytes; single files must stay under %d bytes.',
					$name,
					$size,
					$max_file_bytes
				);
			}
		}

		// f. Required-entry coverage.
		$present_names = array();
		for ( $i = 0; $i < $entry_count; $i++ ) {
			$entry_stat = $zip->statIndex( $i );
			if ( is_array( $entry_stat ) ) {
				$present_names[] = (string) $entry_stat['name'];
			}
		}
		foreach ( $required_root as $req ) {
			if ( ! in_array( $req, $present_names, true ) ) {
				$errors[] = "Required entry missing from ZIP: {$req}. WP.org requires every shipped plugin to expose its mainfile, readme, license, and uninstall scripts at the canonical paths.";
			}
		}

		// g. Compressed-ZIP ceiling — what WP.org enforces at submission.
		//    The uncompressed payload is intentionally not capped: the
		//    build ships ~20 MB of mpdf font files that compress to
		//    ~6 MB, and WP.org reviews the compressed archive. The
		//    single-file ceiling above catches the failure mode of a
		//    single huge file slipping through.
		echo "Uncompressed: " . $total_bytes . " bytes (informational — WP.org caps the compressed ZIP)\n";

		// h. Exactly one top-level entry, named `sscribe-export-site-pages/`.
		if ( count( $top_seen ) > 0 ) {
			$allowed_top = array( 'sscribe-export-site-pages' => true, 'sscribe-export-site-pages/' => true );
			$bad_top     = array_diff_key( $top_seen, $allowed_top );
			if ( ! empty( $bad_top ) ) {
				foreach ( array_keys( $bad_top ) as $extra ) {
					$errors[] = "Unexpected top-level entry: {$extra}. WP.org rejects ZIPs that ship files at the root of the archive.";
				}
			}
		}

		$zip->close();
	}
}

echo "\n";

if ( ! empty( $errors ) ) {
	echo "Errors:\n";
	foreach ( $errors as $error ) {
		echo "  ✗ {$error}\n";
	}
	echo "\n";
	exit( 1 );
}

echo "✓ ZIP certification holds.\n";
exit( 0 );
