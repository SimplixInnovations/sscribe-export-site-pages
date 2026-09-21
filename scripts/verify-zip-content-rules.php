<?php
/**
 * Phase 35: ZIP content rules contract.
 *
 * Phase 34 certifies that the submission ZIP is well-formed and
 * points at the canonical directory layout. Phase 35 enforces
 * the deeper content rules: every PHP file in the dist tree must
 * parse cleanly, the shipped composer.json must point at the
 * prefixed vendor tree, readme.txt must fit WP.org's 10 KiB cap,
 * languages/ must ship at least one translation file, no entry
 * is empty, no entry is a Windows/macOS metadata file (.DS_Store,
 * Thumbs.db, desktop.ini), and the mainfile Version header must
 * match the version in the ZIP filename.
 *
 * A regression that ships a parse-broken .php, an empty stub, an
 * oversized readme, or a composer.json that still points at
 * `vendor/` instead of `vendor-prefixed/` is rejected at
 * submission and bricks every install the moment WP.org pushes
 * the update.
 *
 * The verifier walks the live dist/ tree (the same tree the ZIP
 * was packaged from) and emits one line per violation so PHPUnit
 * can grep for the rule keyword.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

$root_dir = dirname( __DIR__ );

// 0. Resolve version + dist directory.
//    The canonical version is read from the SHIPPED mainfile
//    (dist/sscribe-export-site-pages/sscribe-export-site-pages.php)
//    because that is what WP.org sees at submission. The repo-root
//    mainfile is the working source; the dist mainfile is what the
//    ZIP filename is supposed to mirror.
$dist_dir = $root_dir . '/dist/sscribe-export-site-pages';
$mainfile_path = $dist_dir . '/sscribe-export-site-pages.php';
if ( ! is_dir( $dist_dir ) ) {
	fwrite( STDERR, "Dist tree {$dist_dir} is missing. Run `php scripts/build-release.php` first.\n" );
	exit( 1 );
}
if ( ! is_file( $mainfile_path ) ) {
	fwrite( STDERR, "Dist mainfile missing: {$mainfile_path}\n" );
	exit( 1 );
}
$mainfile_src = (string) file_get_contents( $mainfile_path );
if ( ! preg_match( '/Version:\s*([0-9][0-9.]*[0-9])/', $mainfile_src, $v_match ) ) {
	fwrite( STDERR, "Cannot read version from dist mainfile header\n" );
	exit( 1 );
}
$version  = $v_match[1];
$zip_path = $root_dir . '/dist/sscribe-export-site-pages-' . $version . '.zip';

$errors = array();
$counts = array(
	'php_files_checked'  => 0,
	'php_parse_errors'   => 0,
	'js_files_checked'   => 0,
	'empty_files'        => 0,
	'os_metadata_files'  => 0,
	'total_files'        => 0,
	'file_count'         => 0,
);

$os_metadata_basenames = array( '.ds_store', 'thumbs.db', 'desktop.ini' );
$forbidden_empty_max   = 0; // bytes — anything at or below is empty.
$max_file_count        = 10000;
$min_file_count        = 5;

echo "=== SScribe ZIP Content Rules ===\n\n";
echo "Version:    {$version}\n";
echo "Dist tree:  {$dist_dir}\n";
echo "ZIP:        {$zip_path}\n\n";

if ( ! is_dir( $dist_dir ) ) {
	$errors[] = "Dist tree {$dist_dir} is missing. Run `php scripts/build-release.php` first.";
} else {
	// 1. Every shipped .php file must parse.
	// 2. Every shipped .js file must be readable text and have non-zero size.
	// 3. No entry is empty.
	// 4. No OS metadata files at any depth.
	$iterator = new \RecursiveIteratorIterator(
		new \RecursiveDirectoryIterator( $dist_dir, \FilesystemIterator::SKIP_DOTS ),
		\RecursiveIteratorIterator::LEAVES_ONLY
	);

	$file_count = 0;
	foreach ( $iterator as $file ) {
		if ( ! $file->isFile() ) {
			continue;
		}
		++$file_count;
		$counts['total_files']++;
		$abs_path    = $file->getPathname();
		$rel_path    = str_replace( '\\', '/', substr( $abs_path, strlen( $dist_dir ) + 1 ) );
		$size        = (int) $file->getSize();
		$basename    = strtolower( (string) $file->getBasename() );
		$ext         = strtolower( pathinfo( $rel_path, PATHINFO_EXTENSION ) );

		// 3. Empty file.
		if ( $size <= $forbidden_empty_max ) {
			++$counts['empty_files'];
			$errors[] = "[{$rel_path}] empty file shipped in dist tree (0 bytes)";
		}

		// 4. OS metadata.
		if ( in_array( $basename, $os_metadata_basenames, true ) ) {
			++$counts['os_metadata_files'];
			$errors[] = "[{$rel_path}] OS metadata file shipped in dist tree ({$basename})";
		}

		// 1. PHP parse.
		if ( 'php' === $ext ) {
			++$counts['php_files_checked'];
			$tmpfile = tempnam( sys_get_temp_dir(), 'ss-parse-' );
			if ( false === $tmpfile ) {
				$errors[] = "[{$rel_path}] could not allocate temp file for php -l";
				continue;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $tmpfile, (string) file_get_contents( $abs_path ) );
			$output = array();
			$rc     = 0;
			exec( escapeshellarg( PHP_BINARY ) . ' -l ' . escapeshellarg( $tmpfile ) . ' 2>&1', $output, $rc );
			@unlink( $tmpfile );
			if ( 0 !== $rc ) {
				++$counts['php_parse_errors'];
				$snippet = implode( ' ', array_slice( $output, 0, 2 ) );
				$errors[] = "[{$rel_path}] PHP parse error: {$snippet}";
			}
		}

		// 2. JS — sanity-check that it's readable text. We don't shell out
		// to `node -c` because Node is not part of the production PHP
		// runtime and a failed install should not depend on a node binary.
		// Instead we verify every .js file is non-empty UTF-8 text.
		if ( 'js' === $ext ) {
			++$counts['js_files_checked'];
			$content = (string) file_get_contents( $abs_path );
			if ( '' === trim( $content ) ) {
				$errors[] = "[{$rel_path}] empty JavaScript file shipped in dist tree";
			} elseif ( ! mb_check_encoding( $content, 'UTF-8' ) ) {
				$errors[] = "[{$rel_path}] JavaScript file is not valid UTF-8";
			}
		}
	}

	$counts['file_count'] = $file_count;

	// 5. File count sanity.
	if ( $file_count < $min_file_count ) {
		$errors[] = "Only {$file_count} files in dist tree; expected at least {$min_file_count}.";
	}
	if ( $file_count > $max_file_count ) {
		$errors[] = "{$file_count} files in dist tree; expected at most {$max_file_count}.";
	}

	// 6. composer.json must point at vendor-prefixed, not vendor.
	$composer_path = $dist_dir . '/composer.json';
	if ( ! is_file( $composer_path ) ) {
		$errors[] = "composer.json missing from dist tree.";
	} else {
		$composer = (string) file_get_contents( $composer_path );
		$decoded  = json_decode( $composer, true );
		if ( ! is_array( $decoded ) ) {
			$errors[] = "composer.json is not valid JSON.";
		} else {
			// Walk only the autoload-LOADING keys (psr-4, psr-0,
			// classmap, files) for any path that starts with
			// `vendor/` and is not `vendor-prefixed/`. We cannot rely
			// on a string check of the json_encode output because PHP
			// escapes `/` to `\/` by default, so a literal
			// `strpos( ..., '"vendor/' )` will not match `vendor/sscribe/`.
			//
			// We deliberately skip `exclude-from-classmap` (a list of
			// paths Composer's classmap generator must NOT scan), which
			// commonly references upstream `vendor/...` paths to
			// prevent double-scanning a dev-only library that the
			// runtime prefixer already covers. Flagging those would
			// be a false positive — the SHIPPED code never autoloads
			// them.
			$loading_keys = array( 'psr-4', 'psr-0', 'classmap', 'files' );
			$bad_refs     = array();
			$find_vendor_refs = static function ( $node, string $key_path ) use ( &$find_vendor_refs, &$bad_refs ): void {
				if ( is_array( $node ) ) {
					foreach ( $node as $k => $v ) {
						$find_vendor_refs( $v, $key_path . '.' . $k );
					}
					return;
				}
				if ( is_string( $node ) ) {
					$trim = ltrim( $node, '/' );
					if ( 0 === strpos( $trim, 'vendor/' )
						&& 0 !== strpos( $trim, 'vendor-prefixed/' )
					) {
						$bad_refs[] = $key_path . ' = ' . $node;
					}
				}
			};
			foreach ( $loading_keys as $lk ) {
				if ( isset( $decoded['autoload'][ $lk ] ) ) {
					$find_vendor_refs( $decoded['autoload'][ $lk ], 'autoload.' . $lk );
				}
			}
			if ( ! empty( $bad_refs ) ) {
				$errors[] = "composer.json autoload still references `vendor/` instead of `vendor-prefixed/`: " . implode( ', ', $bad_refs ) . '. The shipped code must load the prefixed vendor tree, not the upstream one.';
			}
		}
	}

	// 7. readme.txt must fit WP.org's 10 KiB cap.
	$readme_path = $dist_dir . '/readme.txt';
	if ( ! is_file( $readme_path ) ) {
		$errors[] = "readme.txt missing from dist tree.";
	} else {
		$readme_size = (int) filesize( $readme_path );
		if ( $readme_size >= 10240 ) {
			$errors[] = "readme.txt is {$readme_size} bytes; WP.org rejects readmes >= 10240 bytes.";
		}
	}

	// 8. languages/ must contain at least one .pot/.po/.mo file.
	$lang_dir = $dist_dir . '/languages';
	if ( ! is_dir( $lang_dir ) ) {
		$errors[] = "languages/ directory missing from dist tree.";
	} else {
		$lang_glob = glob( $lang_dir . '/*.{pot,po,mo}', GLOB_BRACE );
		if ( empty( $lang_glob ) ) {
			$errors[] = "languages/ directory has no .pot/.po/.mo file. WP.org requires at least one translation template.";
		}
	}

	// 9. Mainfile Version: header matches the ZIP filename version.
	if ( is_file( $mainfile_path ) ) {
		$expected = $version;
		$shipped  = (string) file_get_contents( $mainfile_path );
		$grep_via = (string) ( preg_match( '/Version:\s*([0-9][0-9.]*[0-9])/', $shipped, $m ) ? $m[1] : '' );
		if ( '' === $grep_via ) {
			$errors[] = "Mainfile has no `Version:` header in the dist tree.";
		} elseif ( $grep_via !== $expected ) {
			$errors[] = "Mainfile Version ({$grep_via}) does not match the ZIP filename version ({$expected}).";
		}
	}

	// 10. ZIP filename version must match the dist directory version.
	//    Scan dist/ for every sscribe-export-site-pages-*.zip file and
	//    ensure exactly one exists whose version segment equals the
	//    dist mainfile's version. The build script writes a single
	//    canonical ZIP at the well-known path; a mismatch means the
	//    build is stale or a stray archive from a previous run is
	//    sitting in dist/.
	$zip_glob = glob( $root_dir . '/dist/sscribe-export-site-pages-*.zip' );
	if ( ! is_array( $zip_glob ) || empty( $zip_glob ) ) {
		$errors[] = "No submission ZIP found at dist/sscribe-export-site-pages-*.zip. Run `php scripts/build-release.php` to produce the canonical archive.";
	} else {
		$expected_zip = 'sscribe-export-site-pages-' . $version . '.zip';
		$matches      = 0;
		$others       = array();
		foreach ( $zip_glob as $zp ) {
			$bn = basename( $zp );
			if ( $bn === $expected_zip ) {
				++$matches;
			} else {
				$others[] = $bn;
			}
		}
		if ( 0 === $matches ) {
			$errors[] = "ZIP filename does not match the dist mainfile version (expected {$expected_zip}, found: " . implode( ', ', $others ) . ').';
		} elseif ( $matches > 1 ) {
			$errors[] = "Multiple ZIPs with the expected name exist in dist/ ({$matches} copies of {$expected_zip}).";
		}
		foreach ( $others as $extra ) {
			$errors[] = "Stray ZIP in dist/ that does not match the dist mainfile version: {$extra}.";
		}
	}

	// 11. No dev-only top-level directory may appear anywhere in the
	//     dist tree at any depth. WP.org reviewers must not see a
	//     `.git/`, `.github/`, `tests/`, `tests-js/`, `tests-e2e/`, `coverage/`,
	//     `node_modules/`, `scripts/`, `.audit/`, `.idea/`,
	//     `.vscode/`, or any other development-only directory in the
	//     submitted ZIP. We check the FIRST segment of every relative
	//     path so an accidental `node_modules/foo` inside an
	//     otherwise-clean tree still fails. `vendor/` is also
	//     forbidden (only `vendor-prefixed/` may ship).
	$forbidden_top_segments = array(
		'.git',
		'.github',
		'.idea',
		'.vscode',
		'.audit',
		'.cursor',
		'.claude',
		'.vs',
		'.fleet',
		'.phpunit.cache',
		'.phpunit.result.cache',
		'node_modules',
		'tests',
		'tests-js',
		'tests-e2e',
		'tests-wp',
		'coverage',
		'build',
		'scripts',
		'docs',
		'.distignore',
		'.editorconfig',
		'.gitignore',
		'.gitattributes',
		'phpunit.xml.dist',
		'phpunit-wp.xml',
		'playwright.config.ts',
		'composer.lock',
		'package.json',
		'package-lock.json',
		'vendor', // plain upstream vendor/ must NEVER ship; only vendor-prefixed/
	);
	$seen_top_violations = array();
	foreach ( $iterator as $file ) {
		if ( ! $file->isFile() ) {
			continue;
		}
		$abs_path = $file->getPathname();
		$rel_path = str_replace( '\\', '/', substr( $abs_path, strlen( $dist_dir ) + 1 ) );
		$first    = strtolower( (string) ( explode( '/', $rel_path, 2 )[0] ?? '' ) );
		if ( '' === $first ) {
			continue;
		}
		if ( in_array( $first, $forbidden_top_segments, true )
			&& ! isset( $seen_top_violations[ $first ] )
		) {
			$seen_top_violations[ $first ] = true;
			$errors[] = "[{$rel_path}] dev-only top-level entry `{$first}/` leaked into the dist tree. Only `vendor-prefixed/` may ship; every other development path must be stripped at build time.";
		}
	}
	if ( ! empty( $seen_top_violations ) ) {
		$counts['forbidden_top_segments'] = count( $seen_top_violations );
	}

	// 12. No IDE / temp / environment / log file patterns may ship.
	//     These are markers a developer left behind on their machine:
	//     Vim swap files, editor backups, .env files (which often
	//     contain secrets), local log files, etc. WP.org rejects a
	//     submission that ships an `.env` or `.log` because reviewers
	//     have been bitten by leaked credentials in the past.
	$forbidden_basename_patterns = array(
		'/\.env$/i',              // .env
		'/\.env\..+$/i',          // .env.local, .env.production
		'/\.log$/i',              // *.log
		'/\.swp$/i',              // Vim swap
		'/\.swo$/i',              // Vim swap
		'/\.bak$/i',              // editor backup
		'/\.tmp$/i',              // temp file
		'/\.orig$/i',             // merge conflict backup
		'/~$/',                   // editor backup
		'/\.phpstorm\..+$/i',     // JetBrains metadata
		'/\.iml$/i',              // JetBrains module file
		'/\.sublime-.+$/i',       // Sublime metadata
		'/\.code-workspace$/i',   // VS Code workspace
		'/\.project$/i',          // Eclipse metadata
		'/\.classpath$/i',        // Eclipse classpath
		'/\.settings$/i',         // Eclipse settings dir marker
	);
	$seen_basename_violations = array();
	foreach ( $iterator as $file ) {
		if ( ! $file->isFile() ) {
			continue;
		}
		$abs_path = $file->getPathname();
		$rel_path = str_replace( '\\', '/', substr( $abs_path, strlen( $dist_dir ) + 1 ) );
		$bn       = (string) $file->getBasename();
		foreach ( $forbidden_basename_patterns as $pat ) {
			if ( preg_match( $pat, $bn ) ) {
				$key = $pat . '::' . $bn;
				if ( ! isset( $seen_basename_violations[ $key ] ) ) {
					$seen_basename_violations[ $key ] = true;
					$errors[] = "[{$rel_path}] IDE/temp/env/log file shipped in dist tree (matches {$pat}). These are markers a developer left behind and must not be in the submission ZIP.";
				}
				break;
			}
		}
	}
	if ( ! empty( $seen_basename_violations ) ) {
		$counts['forbidden_basenames'] = count( $seen_basename_violations );
	}

	// 13. Required runtime files and directories must be present.
	//     The shipped ZIP must contain vendor-prefixed/ (the prefixed
	//     dependency tree Strauss produced), assets/ (fonts and icons
	//     referenced by the admin UI), license.txt (the canonical GPL
	//     text — every WP.org plugin must ship this verbatim), and the
	//     uninstall.php cleanup hook (WP.org requires it for any
	//     plugin that creates tables/options on activation).
	$required_paths = array(
		'vendor-prefixed'       => 'directory',
		'vendor-prefixed/autoload.php' => 'file',
		'assets'                => 'directory',
		'license.txt'           => 'file',
		'uninstall.php'         => 'file',
		'composer.json'         => 'file',
	);
	foreach ( $required_paths as $rel => $kind ) {
		$abs = $dist_dir . '/' . $rel;
		if ( 'directory' === $kind ) {
			if ( ! is_dir( $abs ) ) {
				$errors[] = "Required runtime directory `{$rel}/` missing from dist tree.";
				continue;
			}
			// Must be non-empty: a placeholder dir is as good as missing.
			$it = new \FilesystemIterator( $abs, \FilesystemIterator::SKIP_DOTS );
			if ( ! $it->valid() ) {
				$errors[] = "Required runtime directory `{$rel}/` is empty in dist tree.";
			}
		} else {
			if ( ! is_file( $abs ) ) {
				$errors[] = "Required runtime file `{$rel}` missing from dist tree.";
			}
		}
	}
}

echo "Files checked:       {$counts['total_files']}\n";
echo "PHP files:           {$counts['php_files_checked']} (parse errors: {$counts['php_parse_errors']})\n";
echo "JS files:            {$counts['js_files_checked']}\n";
echo "Empty files:         {$counts['empty_files']}\n";
echo "OS metadata files:   {$counts['os_metadata_files']}\n";
echo "Forbidden top segs:  " . ( isset( $counts['forbidden_top_segments'] ) ? (int) $counts['forbidden_top_segments'] : 0 ) . "\n";
echo "Forbidden basenames: " . ( isset( $counts['forbidden_basenames'] ) ? (int) $counts['forbidden_basenames'] : 0 ) . "\n\n";

if ( ! empty( $errors ) ) {
	echo "Errors:\n";
	foreach ( $errors as $error ) {
		echo "  ✗ {$error}\n";
	}
	echo "\n";
	exit( 1 );
}

echo "✓ ZIP content rules hold.\n";
exit( 0 );
