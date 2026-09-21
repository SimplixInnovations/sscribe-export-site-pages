<?php
/**
 * Install a real WordPress testbench for SScribe plugin tests.
 *
 * Canonical cross-platform implementation. bin/install-wp-tests.sh is a
 * thin wrapper around this file; both entry points share behavior:
 *
 * Downloads WordPress core + the WordPress PHPUnit test suite, configures
 * them under tests-wp/_wordpress/ and tests-wp/_wordpress-tests-lib/, and
 * drops in the SQLite Database Integration plugin so the test DB runs
 * without MySQL/MariaDB.
 *
 * Usage:
 *   php scripts/install-wp-tests.php [--sqlite] [--version <wp-version>]
 *
 * Env vars:
 *   WP_TESTS_DIR    Override tests-wp/_wordpress-tests-lib path
 *   WP_CORE_DIR     Override tests-wp/_wordpress path
 *   WP_VERSION      WordPress version (default: latest)
 *   SKIP_NETWORK    Accepted for interface parity; reserved for future use.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

require_once __DIR__ . '/lib/cross-platform.php';

const SSCRIBE_INSTALL_TAG = 'install-wp-tests';
const SSCRIBE_SQLITE_INTEGRATION_VERSION = '3.0.2';

function sscribe_install_usage(): void {
	fwrite( STDOUT, "Usage: php scripts/install-wp-tests.php [--sqlite] [--version <version>]\n" );
}

/**
 * Parse CLI arguments.
 *
 * @param array $argv Argument vector.
 * @return array{sqlite:bool,version:string}
 */
function sscribe_install_parse_args( array $argv ): array {
	$sqlite  = false;
	$version = getenv( 'WP_VERSION' );
	if ( ! is_string( $version ) || '' === $version ) {
		$version = 'latest';
	}
	$count = count( $argv );
	for ( $i = 1; $i < $count; $i++ ) {
		$arg = $argv[ $i ];
		if ( '--sqlite' === $arg ) {
			$sqlite = true;
		} elseif ( '--version' === $arg ) {
			$version = ( $i + 1 < $count ) ? $argv[ $i + 1 ] : 'latest';
			$i++;
		} elseif ( str_starts_with( $arg, '--version=' ) ) {
			$version = substr( $arg, strlen( '--version=' ) );
			if ( '' === $version ) {
				$version = 'latest';
			}
		} elseif ( '--help' === $arg || '-h' === $arg ) {
			sscribe_install_usage();
			exit( 0 );
		}
	}
	return array(
		'sqlite'  => $sqlite,
		'version' => $version,
	);
}

/**
 * Resolve the latest stable WordPress version via wordpress.org.
 *
 * @return string Version number.
 * @throws RuntimeException When resolution fails.
 */
function sscribe_resolve_latest_wp(): string {
	sscribe_log( SSCRIBE_INSTALL_TAG, 'Resolving latest WordPress version from wordpress.org...' );
	$body = sscribe_http_get( 'https://api.wordpress.org/core/version-check/1.7/' );
	if ( ! preg_match( '/"current":"([^"]+)"/', $body, $m ) || '' === $m[1] || 'latest' === $m[1] ) {
		throw new RuntimeException( 'could not resolve latest WordPress version from wordpress.org API' );
	}
	return $m[1];
}

/**
 * Resolve the previous stable WordPress version via wordpress.org.
 *
 * Mirrors the shell implementation: the second "version" occurrence in
 * the version-check payload, falling back to latest-minus-0.1.
 *
 * @return string Version number.
 * @throws RuntimeException When resolution fails.
 */
function sscribe_resolve_previous_wp(): string {
	sscribe_log( SSCRIBE_INSTALL_TAG, 'Resolving previous WordPress release line from wordpress.org...' );
	$body    = sscribe_http_get( 'https://api.wordpress.org/core/version-check/1.7/' );
	$payload = json_decode( $body, true );
	$offers  = is_array( $payload ) && isset( $payload['offers'] ) && is_array( $payload['offers'] ) ? $payload['offers'] : array();

	$latest_release_line = '';
	foreach ( $offers as $offer ) {
		if ( ! is_array( $offer ) ) {
			continue;
		}
		$candidate = isset( $offer['version'] ) && is_string( $offer['version'] ) ? trim( $offer['version'] ) : '';
		if ( ! preg_match( '/^(\\d+)\\.(\\d+)(?:\\.\\d+)?(?:[-+].*)?$/', $candidate, $matches ) ) {
			continue;
		}
		$candidate_release_line = $matches[1] . '.' . $matches[2];
		if ( '' === $latest_release_line ) {
			$latest_release_line = $candidate_release_line;
			continue;
		}
		if ( $candidate_release_line !== $latest_release_line ) {
			return $candidate;
		}
	}

	throw new RuntimeException( 'could not resolve a distinct previous WordPress release line from wordpress.org API' );
}

/**
 * Resolve the wp-phpunit branch matching a WordPress release line.
 *
 * wp-phpunit mirrors WordPress core test libraries on tree-X.Y branches.
 * Mixing the repository's master branch with an older WordPress core tree
 * can make the testbench fail before plugin tests even start.
 *
 * @param string $wp_version Resolved WordPress version.
 * @return string Version-matched wp-phpunit branch.
 * @throws RuntimeException When the version cannot be mapped safely.
 */
function sscribe_wp_phpunit_branch( string $wp_version ): string {
	if ( ! preg_match( '/^(\\d+)\\.(\\d+)(?:\\.\\d+)?(?:[-+].*)?$/', $wp_version, $matches ) ) {
		throw new RuntimeException( "cannot map WordPress version {$wp_version} to a wp-phpunit release tree" );
	}

	return 'tree-' . $matches[1] . '.' . $matches[2];
}

/**
 * Extract a .tar.gz archive using only ext-zlib (no phar.readonly dependency).
 *
 * Handles regular files, directories, and GNU longname/longlink headers.
 * Symlinks, hardlinks, and device nodes are skipped with a notice so
 * extraction stays safe on Windows.
 *
 * @param string $archive Path to the .tar.gz file.
 * @param string $dest    Destination directory (created as needed).
 * @throws RuntimeException On any failure.
 */
function sscribe_extract_tar_gz( string $archive, string $dest ): void {
	if ( ! function_exists( 'gzopen' ) ) {
		throw new RuntimeException( 'PHP zlib extension is required to extract WordPress (gzopen unavailable).' );
	}
	$gz = gzopen( $archive, 'rb' );
	if ( false === $gz ) {
		throw new RuntimeException( "Cannot open archive {$archive}" );
	}
	$data = '';
	while ( ! gzeof( $gz ) ) {
		$chunk = gzread( $gz, 1048576 );
		if ( false === $chunk ) {
			break;
		}
		$data .= $chunk;
	}
	gzclose( $gz );
	if ( '' === $data ) {
		throw new RuntimeException( "Archive {$archive} decompressed to empty content" );
	}
	if ( ! is_dir( $dest ) && ! mkdir( $dest, 0777, true ) && ! is_dir( $dest ) ) {
		throw new RuntimeException( "Cannot create directory {$dest}" );
	}
	$offset   = 0;
	$len      = strlen( $data );
	$longname = null;
	$longlink = null;
	while ( $offset + 512 <= $len ) {
		$header = substr( $data, $offset, 512 );
		if ( '' === trim( $header, "\0" ) ) {
			break;
		}
		$name     = rtrim( substr( $header, 0, 100 ), "\0" );
		$mode     = octdec( trim( rtrim( substr( $header, 100, 8 ), "\0" ) . '0' ) );
		$size_oct = trim( rtrim( substr( $header, 124, 12 ), "\0" ) . ' ' );
		$size     = '' === $size_oct ? 0 : octdec( $size_oct );
		$type     = substr( $header, 156, 1 );
		$prefix   = rtrim( substr( $header, 345, 155 ), "\0" );
		if ( '' !== $prefix ) {
			$name = $prefix . '/' . $name;
		}
		$offset += 512;
		$body    = substr( $data, $offset, $size );
		$offset += (int) ( ceil( $size / 512 ) * 512 );
		if ( 'L' === $type ) {
			$longname = rtrim( $body, "\0" );
			continue;
		}
		if ( 'K' === $type ) {
			$longlink = rtrim( $body, "\0" );
			continue;
		}
		if ( 'x' === $type ) {
			// PAX extended header: the `path=` record renames the entry
			// that follows (wordpress.org tarballs use these for names
			// longer than the 100-byte ustar field). Honor it via the
			// same slot as GNU longname records.
			foreach ( explode( "\n", $body ) as $record ) {
				$space = strpos( $record, ' ' );
				if ( false === $space ) {
					continue;
				}
				$kv = substr( $record, $space + 1 );
				if ( str_starts_with( $kv, 'path=' ) ) {
					$longname = substr( $kv, strlen( 'path=' ) );
				}
			}
			continue;
		}
		if ( null !== $longname ) {
			$name     = $longname;
			$longname = null;
		}
		$longlink = null;
		$name     = ltrim( str_replace( '\\', '/', $name ), '/' );
		if ( '' === $name || str_contains( $name, '..' ) ) {
			continue;
		}
		$target = $dest . '/' . $name;
		if ( '5' === $type ) {
			if ( ! is_dir( $target ) && ! mkdir( $target, 0777, true ) && ! is_dir( $target ) ) {
				throw new RuntimeException( "Cannot create directory {$target}" );
			}
			continue;
		}
		if ( '0' === $type || "\0" === $type || '' === $type ) {
			$parent = dirname( $target );
			if ( ! is_dir( $parent ) && ! mkdir( $parent, 0777, true ) && ! is_dir( $parent ) ) {
				throw new RuntimeException( "Cannot create directory {$parent}" );
			}
			if ( false === file_put_contents( $target, $body ) ) {
				throw new RuntimeException( "Cannot write {$target}" );
			}
			if ( $mode > 0 ) {
				@chmod( $target, ( $mode & 0111 ) ? 0700 : 0600 );
			}
			continue;
		}
		sscribe_log( SSCRIBE_INSTALL_TAG, "Skipping non-regular tar entry: {$name} (type {$type})" );
	}
}

/**
 * Find db.copy inside the SQLite release extraction at depth 2-3.
 *
 * @param string $root Extraction root.
 * @return string Directory containing db.copy.
 * @throws RuntimeException When not found.
 */
function sscribe_find_sqlite_plugin_dir( string $root ): string {
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::SELF_FIRST
	);
	foreach ( $iterator as $file ) {
		if ( ! $file->isFile() || 'db.copy' !== $file->getFilename() ) {
			continue;
		}
		$rel   = str_replace( '\\', '/', substr( $file->getPath(), strlen( $root ) ) );
		$depth = count( array_filter( explode( '/', trim( $rel, '/' ) ) ) ) + 1;
		if ( $depth >= 2 && $depth <= 3 ) {
			return $file->getPath();
		}
	}
	throw new RuntimeException( 'could not find db.copy in SQLite Database Integration release zip' );
}

/**
 * Run the install.
 *
 * @param array $argv Argument vector.
 */
function sscribe_install_wp_tests( array $argv ): void {
	$args       = sscribe_install_parse_args( $argv );
	$plugin_dir = sscribe_repo_root();
	$test_dir   = $plugin_dir . '/tests-wp';
	$core_dir   = getenv( 'WP_CORE_DIR' );
	$tests_dir  = getenv( 'WP_TESTS_DIR' );
	if ( ! is_string( $core_dir ) || '' === $core_dir ) {
		$core_dir = $test_dir . '/_wordpress';
	}
	if ( ! is_string( $tests_dir ) || '' === $tests_dir ) {
		$tests_dir = $test_dir . '/_wordpress-tests-lib';
	}
	$cache_dir  = $test_dir . '/.cache';
	$wp_version = $args['version'];
	foreach ( array( $test_dir, $cache_dir, $core_dir, $tests_dir ) as $dir ) {
		if ( ! is_dir( $dir ) && ! mkdir( $dir, 0777, true ) && ! is_dir( $dir ) ) {
			sscribe_fail( "Cannot create directory {$dir}" );
		}
	}

	try {
		if ( 'latest' === $wp_version || '' === $wp_version ) {
			$wp_version = sscribe_resolve_latest_wp();
		}
		if ( 'previous' === $wp_version ) {
			$wp_version = sscribe_resolve_previous_wp();
		}

		// 1. WordPress core.
		sscribe_log( SSCRIBE_INSTALL_TAG, "Downloading WordPress {$wp_version}..." );
		$tarball = $cache_dir . "/wordpress-{$wp_version}.tar.gz";
		if ( ! is_file( $tarball ) ) {
			sscribe_download_file( "https://wordpress.org/wordpress-{$wp_version}.tar.gz", $tarball );
		}
		sscribe_log( SSCRIBE_INSTALL_TAG, "Extracting WordPress into {$core_dir}..." );
		$src_dir = $cache_dir . '/wordpress';
		if ( is_dir( $src_dir ) ) {
			// Leftover from an interrupted run; start clean so partial
			// entries can never merge with a fresh extraction.
			sscribe_rmdir( $src_dir );
		}
		sscribe_extract_tar_gz( $tarball, $cache_dir );
		if ( ! is_dir( $src_dir ) ) {
			throw new RuntimeException( "extracted archive does not contain 'wordpress' directory" );
		}
		foreach ( scandir( $src_dir ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			if ( file_exists( $core_dir . '/' . $entry ) ) {
				continue;
			}
			$from = $src_dir . '/' . $entry;
			$to   = $core_dir . '/' . $entry;
			if ( ! @rename( $from, $to ) ) {
				if ( is_dir( $from ) ) {
					sscribe_copy_tree( $from, $to );
					sscribe_rmdir( $from );
				} else {
					if ( ! copy( $from, $to ) ) {
						throw new RuntimeException( "Cannot move {$from} to {$to}" );
					}
					@unlink( $from );
				}
			}
		}
		sscribe_rmdir( $src_dir );

		// 2. WordPress test suite.
		$wp_phpunit_branch = sscribe_wp_phpunit_branch( $wp_version );
		sscribe_log(
			SSCRIBE_INSTALL_TAG,
			"Downloading WordPress test suite from wp-phpunit/wp-phpunit ({$wp_phpunit_branch})..."
		);
		$suite_cache = $cache_dir . '/wp-phpunit-' . str_replace( '.', '-', $wp_phpunit_branch );
		if ( ! is_dir( $suite_cache . '/includes' ) ) {
			sscribe_rmdir( $suite_cache );
			$git = sscribe_which( 'git' );
			$res = sscribe_run_argv(
				array(
					$git,
					'clone',
					'--depth',
					'1',
					'--branch',
					$wp_phpunit_branch,
					'--single-branch',
					'https://github.com/wp-phpunit/wp-phpunit.git',
					$suite_cache,
				)
			);
			if ( 0 !== $res['code'] || ! is_dir( $suite_cache . '/includes' ) ) {
				throw new RuntimeException( "wp-phpunit {$wp_phpunit_branch} clone has no includes/ directory" );
			}
		}
		if ( ! is_dir( $suite_cache . '/includes' ) ) {
			throw new RuntimeException( "wp-phpunit {$wp_phpunit_branch} clone has no includes/ directory" );
		}
		sscribe_log( SSCRIBE_INSTALL_TAG, "Copying test suite into {$tests_dir}..." );
		sscribe_rmdir( $tests_dir );
		sscribe_copy_tree( $suite_cache, $tests_dir );
		@unlink( $tests_dir . '/composer.json' );
		@unlink( $tests_dir . '/README.md' );
		sscribe_rmdir( $suite_cache . '/.git' );

		// 3. wp-tests-config.php.
		$config_template = $plugin_dir . '/tests-wp/wp-tests-config.php';
		if ( ! is_file( $config_template ) ) {
			throw new RuntimeException( "missing {$config_template}" );
		}
		$config_target = $tests_dir . '/wp-tests-config.php';
		sscribe_log( SSCRIBE_INSTALL_TAG, 'Writing wp-tests-config.php...' );
		if ( ! copy( $config_template, $config_target ) ) {
			throw new RuntimeException( "Cannot write {$config_target}" );
		}

		// 4. SQLite drop-in.
		if ( $args['sqlite'] ) {
			sscribe_log( SSCRIBE_INSTALL_TAG, 'Installing SQLite Database Integration drop-in...' );
			$sqlite_dir = $core_dir . '/wp-content/plugins/sqlite-database-integration';

			// Maintained SQLite Database Integration releases require WordPress 6.4+.
			// Do not pin removed or unsupported historical plugin archives merely to
			// keep the declared WordPress 6.1 floor green. Older supported WordPress
			// versions are exercised against the real MySQL service in CI instead.
			if ( version_compare( $wp_version, '6.4', '<' ) ) {
				throw new RuntimeException( 'SQLite Database Integration requires WordPress 6.4 or newer; use MySQL for older supported WordPress versions.' );
			}

			$sqlite_cache_suffix = SSCRIBE_SQLITE_INTEGRATION_VERSION;

			if ( ! is_dir( $sqlite_dir ) ) {
				$sqlite_zip = $cache_dir . '/sqlite-database-integration-' . $sqlite_cache_suffix . '.zip';
				if ( ! is_file( $sqlite_zip ) ) {
					$sqlite_url = 'https://downloads.wordpress.org/plugin/sqlite-database-integration.' . SSCRIBE_SQLITE_INTEGRATION_VERSION . '.zip';
					sscribe_log( SSCRIBE_INSTALL_TAG, 'Downloading SQLite Database Integration ' . SSCRIBE_SQLITE_INTEGRATION_VERSION . ' from WordPress.org...' );
					sscribe_download_file( $sqlite_url, $sqlite_zip );
				}
				if ( ! class_exists( 'ZipArchive' ) ) {
					throw new RuntimeException( 'PHP zip extension is required to install the SQLite drop-in (ZipArchive unavailable).' );
				}
				$zip = new ZipArchive();
				if ( true !== $zip->open( $sqlite_zip ) ) {
					throw new RuntimeException( "Cannot open {$sqlite_zip}" );
				}
				$extract_dir = $cache_dir . '/sqlite-extract';
				sscribe_rmdir( $extract_dir );
				mkdir( $extract_dir, 0777, true );
				if ( ! $zip->extractTo( $extract_dir ) ) {
					$zip->close();
					throw new RuntimeException( "Cannot extract {$sqlite_zip}" );
				}
				$zip->close();
				$extracted  = sscribe_find_sqlite_plugin_dir( $extract_dir );
				$plugins_dir = $core_dir . '/wp-content/plugins';
				if ( ! is_dir( $plugins_dir ) ) {
					mkdir( $plugins_dir, 0777, true );
				}
				if ( ! @rename( $extracted, $sqlite_dir ) ) {
					sscribe_copy_tree( $extracted, $sqlite_dir );
				}
			}
			if ( is_file( $sqlite_dir . '/db.copy' ) ) {
				copy( $sqlite_dir . '/db.copy', $core_dir . '/wp-content/db.php' );
			} elseif ( is_file( $sqlite_dir . '/db.php' ) ) {
				copy( $sqlite_dir . '/db.php', $core_dir . '/wp-content/db.php' );
			} else {
				throw new RuntimeException( 'SQLite Database Integration plugin does not ship db.copy or db.php' );
			}
			sscribe_log( SSCRIBE_INSTALL_TAG, 'SQLite drop-in installed at wp-content/db.php' );
		}
	} catch ( RuntimeException $e ) {
		sscribe_fail( $e->getMessage() );
	}

	// 5. Summary.
	sscribe_log( SSCRIBE_INSTALL_TAG, 'WordPress testbench installed.' );
	sscribe_log( SSCRIBE_INSTALL_TAG, "  WP core:       {$core_dir}" );
	sscribe_log( SSCRIBE_INSTALL_TAG, "  WP tests lib:  {$tests_dir}" );
	sscribe_log( SSCRIBE_INSTALL_TAG, "  Test config:   {$config_target}" );
	if ( $args['sqlite'] ) {
		sscribe_log( SSCRIBE_INSTALL_TAG, "  SQLite drop-in: {$core_dir}/wp-content/db.php" );
		sscribe_log( SSCRIBE_INSTALL_TAG, '  Run tests with: php -d extension=sqlite3 -d extension=pdo_sqlite vendor/bin/phpunit --testsuite=WordPress' );
	} else {
		sscribe_log( SSCRIBE_INSTALL_TAG, '  Run tests with: vendor/bin/phpunit --testsuite=WordPress (configure wp-tests-config.php DB credentials first)' );
	}
}

sscribe_install_wp_tests( $argv );
