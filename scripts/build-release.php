<?php
/**
 * SScribe Build Release Script
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

echo "\n===========================================\n";
echo "  SSCRIBE EXPORT BUILD\n";
echo "===========================================\n\n";

$root       = dirname( __DIR__ );
$start_time = microtime( true );

$config = array(

	'clean_dist'       => true,
	'keep_releases'    => 0,
	'run_tests'        => true,
	'run_phpstan'      => true,
	'run_phpcs'        => true,
	'generate_sha256'  => true,
	'auto_clean_root'  => true,
	'strip_comments'   => true,

	'mainPluginFile'   => 'sscribe-export-site-pages.php',
	'readmeFile'       => 'readme.txt',
	'distignore'      => '.distignore',

	'base_excludes'    => array(
		'dist', 'vendor', '.git', '.gitignore', '.distignore', '.cache', '.phpunit.cache',
		'.sisyphus', '.wp-env', '.playground-cache', 'wordpress', 'wordpress-tests-lib',
		'package.json', 'package-lock.json', 'opencode.json', 'CONTRIBUTING.md', 'CHANGELOG.md',
		'phpunit.xml', 'phpunit.xml.dist', 'phpstan.neon', 'phpstan.neon.dist',
		'phpcs.xml', 'phpstan-bootstrap.php', '.editorconfig', '.wp-env.json',
		'tests', 'tests-wp', 'tests-js', 'tests-e2e', 'scripts', '.github', '.gitattributes', 'docs', 'examples', 'samples',
		// bin/ holds real-WP testbench shell helpers (install-wp-tests.sh etc.)
		// added in 14379d2. Never ship them in the release ZIP.
		'bin',
		'composer.lock', 'scratch', 'strauss.json', 'infection.json5',
		'commit-message.txt', '.prettierrc', '.eslintrc.json', '.stylelintrc.json', '.husky',
		'node_modules', 'WPScan',
		// PHPUnit's failure-tracker directory (created at the repo root on
		// every run by PHPUnit 11+; the `cacheDirectory` setting in phpunit.xml
		// is `.phpunit.cache` but PHPUnit also writes `.last-run.json` summary
		// state into a sibling `test-results/` directory). Phase 34
		// ZIP-certification surfaces this leak.
		'test-results',
		// Defense in depth: a Windows null-device redirect must never become
		// a shippable root file when a command runs on POSIX.
		'NUL',
		'vendor-prefixed/phpoffice/phpword/COPYING.LESSER',
		// Ad-hoc Python transform scripts left over from one-off
		// SVG / kses / indent fixes. Nothing in the production code
		// path executes them; if a future maintainer drops a *.py at
		// the repo root, the pattern keeps it out of the ZIP.
		'*.py',

		'phpstan-baseline.neon', 'ruleset.xml', 'CREDITS.txt',
		// Third-party licenses remain in the package. PHPWord's LGPL text
		// ships as COPYING.LESSER.txt because Plugin Check rejects the
		// upstream COPYING.LESSER suffix as an unexpected file extension.
		'.php-cs-fixer.php', '.php-cs-fixer.dist.php', 'mkdocs.yml',
		'.travis.yml', '.scrutinizer.yml', '.github_changelog_generator',

		// AI tooling (Claude Code, OpenCode, Aider, Cursor, Windsurf, Continue, Codeium)
		// These contain user-local settings and prompt history that must never ship
		// in the production plugin. WordPress.org plugin-check flags them as
		// "ai_instruction_directory" warnings if present.
		'.claude', '.opencode', '.impeccable', '.agent', '.aider', '.aider.chat.history',
		'.aider.model.settings.json', '.aider.input.history', '.cursor', '.windsurf',
		'.continue', '.codeium', '.github/copilot', '.cody', '.mimosa', '.omo',
		'.debug-journal.md',

		// Local browser-audit image artifacts.
		// The .audit/ directory holds v3 verification images; root-level
		// .export-*.png and tab-export-*.{jpg,jpeg} are full-page captures
		// produced during UI polish passes. None of this belongs in the
		// production ZIP - WordPress.org reviewers will see the files and flag
		// them as junk.
		'.audit', 'export-full.png', 'export-debug.png', 'export-history.png',
		'export-main.png', 'export-support.png', 'export-v3-final.png',
		'tab-export-top.jpg', 'tab-export-bottom.jpg', 'tab-export-light.jpg',
		'tab-export-scrolled.jpeg', '.export-debug.png', '.export-history.png',
		'.export-main.png', '.export-support.png', '.export-v3-final.png',

		// PHPStan + Intelephense stubs (WordPress function signatures for static
		// analysis). Not part of the production plugin - the real WordPress
		// runtime provides these functions.
		'stubs', '.stubs',

		// Phase 22: additional dev-only root-level files that previously
		// leaked into the release ZIP. Per "Build-release .distignore glob
		// blindness" memory, the .distignore pattern matcher is segment-only
		// (no real globs) — these must live in base_excludes for guaranteed
		// exclusion.
		//   - .superpowers:    local planning/review scratch dir with diff
		//                      files. NEVER ship to WP.org.
		//   - phpunit-wp.xml: real-WP testbench config. Dev-only.
		//   - playwright.config.ts: E2E test config. Dev-only.
		'.superpowers', 'phpunit-wp.xml', 'playwright.config.ts',
	),

	'font_excludes'    => array(),



	'show_excluded'    => true,
);

$all_excludes = array_unique( array_merge( $config['base_excludes'], $config['font_excludes'], $config['fpdi_excludes'] ?? array() ) );

function rrmdir( string $dir ): void {
	if ( is_link( $dir ) ) {
		@unlink( $dir );
		return;
	}
	if ( ! is_dir( $dir ) ) {
		return;
	}
	$objects = scandir( $dir );
	foreach ( $objects as $object ) {
		if ( $object === '.' || $object === '..' ) {
			continue;
		}
		$path = $dir . '/' . $object;
		if ( is_link( $path ) ) {
			@unlink( $path );
		} elseif ( is_dir( $path ) ) {
			rrmdir( $path );
		} else {
			@unlink( $path );
		}
	}
	@rmdir( $dir );
}

function format_bytes( int $bytes ): string {
	$units = array( 'B', 'KB', 'MB', 'GB' );
	$unit  = 0;
	while ( $bytes >= 1024 && $unit < count( $units ) - 1 ) {
		$bytes /= 1024;
		$unit++;
	}
	return round( $bytes, 2 ) . ' ' . $units[ $unit ];
}

function strip_php_comments( string $source ): string {
	$tokens = @token_get_all( $source );
	if ( false === $tokens ) {
		return $source;
	}

	$output = '';
	foreach ( $tokens as $token ) {
		if ( is_array( $token ) ) {
			$type  = $token[0];
			$value = $token[1];

			if ( T_DOC_COMMENT === $type ) {
				$output .= $value;
				continue;
			}

			if ( T_COMMENT === $type ) {
				if ( preg_match( '/phpcs:|phpcs-disable|phpcs-enable|phpcs:ignore|translators:|@preserve/i', $value ) ) {
					$output .= $value;
				}
				continue;
			}

			$output .= $value;
		} else {
			$output .= $token;
		}
	}

	return $output;
}

/**
 * Sanitize AI-artifact Unicode characters that WP.org plugin-check
 * flags (per wp-org-ai-artifact-audit memory).
 *
 * Applied AFTER comment-stripping so docblocks and jsdoc content
 * (which the build preserves for @preserve/@var/@type pragmas) cannot
 * leak em-dashes, en-dashes, ellipses, or curly quotes into the
 * shipped ZIP.
 *
 *   —  (em-dash, U+2014)        -> ' - '
 *   –  (en-dash, U+2013)        -> '-'
 *   …  (ellipsis, U+2026)       -> '...'
 *   “  (left double quote)      -> '"'
 *   ”  (right double quote)     -> '"'
 *   ‘  (left single quote)      -> "'"
 *   ’  (right single quote)     -> "'"
 */
function sanitize_ai_artifacts( string $source ): string {
	$replacements = array(
		"\xE2\x80\x94" => ' - ',   // em-dash
		"\xE2\x80\x93" => '-',     // en-dash
		"\xE2\x80\xA6" => '...',   // ellipsis
		"\xE2\x80\x9C" => '"',     // left double curly quote
		"\xE2\x80\x9D" => '"',     // right double curly quote
		"\xE2\x80\x98" => "'",     // left single curly quote
		"\xE2\x80\x99" => "'",     // right single curly quote / apostrophe
	);
	return strtr( $source, $replacements );
}

function strip_css_comments( string $source ): string {
	$out = preg_replace( '/\/\*[\s\S]*?\*\//', '', $source );
	if ( null === $out ) {
		return $source;
	}
	$out = preg_replace( '/\n\s*\n/', "\n", $out );
	if ( null === $out ) {
		return $source;
	}
	return $out;
}

/**
 * Strip non-docblock comments from JS source.
 *
 * Matches the spirit of the zip-no-comments-rule:
 * preserve /*! license banners and /* eslint pragmas,
 * drop everything else (line //, block /* *\/) while
 * keeping /* *\/ (jsdoc) and /*! *\/ (license).
 */
function strip_js_comments( string $source ): string {
	// Drop block /* ... */ that is NOT /*! or /** (preserves license + jsdoc).
	$out = preg_replace( '#/\*(?![*!]).*?\*/#s', '', $source );
	if ( null === $out ) {
		return $source;
	}
	// Drop line // comments (NOT inside strings; same regex approach as strip_php_comments' pragmatic pass).
	$out = preg_replace( '#(?<![:"\'`])//[^\n]*#', '', $out );
	if ( null === $out ) {
		return $source;
	}
	// Collapse runs of blank lines.
	$out = preg_replace( '/\n\s*\n/', "\n", $out );
	if ( null === $out ) {
		return $source;
	}
	return $out;
}

function run_tests( string $root ): bool {
	echo "  🧪 Running PHPUnit tests...\n";

	$phpunit = $root . '/vendor/bin/phpunit';
	$config = $root . '/phpunit.xml';

	if ( ! file_exists( $phpunit ) || ! file_exists( $config ) ) {
		echo "     ⚠️  PHPUnit not found - skipping\n";
		return true;
	}

	$output = array();
	$return = 0;
	exec( "php \"$phpunit\" --exclude-group=release-contract 2>&1", $output, $return );

	if ( $return !== 0 ) {
		$output_str = implode( "\n", $output );
		$lines      = explode( "\n", $output_str );
		// Compact PHPUnit output keeps failure details at the end; retain a
		// generous tail so hosted CI exposes every failing test/assertion.
		$show = implode( "\n     ", array_slice( $lines, -160 ) );
		echo "     ❌ PHPUnit tests failed:\n     $show\n";
		return false;
	}

	echo "     ✅ PHPUnit tests passed\n";
	return true;
}

function run_phpstan( string $root ): bool {
	echo "  📊 Running PHPStan...\n";

	$phpstan = $root . '/vendor/bin/phpstan';
	if ( ! file_exists( $phpstan ) ) {
		echo "     ⚠️  PHPStan not found - skipping\n";
		return true;
	}

	$output = array();
	$return = 0;

	exec( "php \"$phpstan\" analyse --no-progress --memory-limit 2G 2>&1", $output, $return );

	if ( $return !== 0 ) {
		$output_str = implode( "\n", $output );
		$lines = explode( "\n", $output_str );
		$show = implode( "\n     ", array_slice( $lines, -10 ) );
		echo "     ❌ PHPStan found errors:\n     $show\n";
		return false;
	}

	echo "     ✅ PHPStan passed\n";
	return true;
}

function run_phpcs( string $root ): bool {
	echo "  📋 Running PHPCS...\n";

	$phpcs = $root . '/vendor/bin/phpcs';
	$standard = $root . '/phpcs.xml';

	if ( ! file_exists( $phpcs ) || ! file_exists( $standard ) ) {
		echo "     ⚠️  PHPCS not found - skipping\n";
		return true;
	}

	$output = array();
	$return = 0;

	exec( "php -d memory_limit=512M \"$phpcs\" --standard=\"$standard\" -q 2>&1", $output, $return );

	if ( $return !== 0 ) {

		$error_output = array();
		exec( "php -d memory_limit=512M \"$phpcs\" --standard=\"$standard\" 2>&1", $error_output, $return );
		$show = implode( "\n     ", $error_output );
		echo "     ❌ PHPCS found errors (exit code: $return):\n     $show\n";
		return false;
	}

	echo "     ✅ PHPCS passed\n";
	return true;
}

function get_version( string $root, string $plugin_file ): string {
	$file = $root . '/' . $plugin_file;
	if ( ! file_exists( $file ) ) {
		throw new RuntimeException( "Plugin file not found: $plugin_file" );
	}

	$content = file_get_contents( $file );
	if ( ! preg_match( '/Version:\s*([0-9.]+)/', $content, $match ) ) {
		throw new RuntimeException( "Version not found in $plugin_file" );
	}

	return $match[1];
}

function get_distignore_excludes( string $root, string $distignore ): array {
	$file = $root . '/' . $distignore;
	$excludes = array();

	if ( ! file_exists( $file ) ) {
		return $excludes;
	}

	$lines = file( $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
	foreach ( $lines as $line ) {
		$line = trim( $line );
		if ( $line === '' || str_starts_with( $line, '#' ) ) {
			continue;
		}
		$excludes[] = trim( $line, '/' );
	}

	return $excludes;
}

/**
 * Determine whether a release-relative path matches an exclusion rule.
 *
 * Supports the `*` and `?` wildcards used by .distignore. Rules without a
 * slash also match any individual path segment, preserving the existing
 * segment-level exclusions for development directories such as `tests`.
 *
 * @param string        $relative Relative path using forward slashes.
 * @param array<string> $excludes Exclusion patterns.
 * @return bool
 */
function is_release_path_excluded( string $relative, array $excludes ): bool {
	$relative = trim( str_replace( '\\', '/', $relative ), '/' );
	$segments = explode( '/', $relative );

	foreach ( $excludes as $exclude ) {
		$exclude = trim( str_replace( '\\', '/', (string) $exclude ), '/' );
		if ( '' === $exclude ) {
			continue;
		}

		$has_wildcard = str_contains( $exclude, '*' ) || str_contains( $exclude, '?' );
		if ( ! $has_wildcard ) {
			if ( $relative === $exclude || str_starts_with( $relative, $exclude . '/' ) || ( ! str_contains( $exclude, '/' ) && in_array( $exclude, $segments, true ) ) ) {
				return true;
			}
			continue;
		}

		$quoted = preg_quote( $exclude, '#' );
		$regex  = str_replace( array( '\\*', '\\?' ), array( '[^/]*', '[^/]' ), $quoted );
		if ( str_contains( $exclude, '/' ) ) {
			if ( 1 === preg_match( '#^' . $regex . '(?:/.*)?$#i', $relative ) ) {
				return true;
			}
		} else {
			foreach ( $segments as $segment ) {
				if ( 1 === preg_match( '#^' . $regex . '$#i', $segment ) ) {
					return true;
				}
			}
		}
	}

	return false;
}

function generate_checksum( string $file ): string {
	return hash_file( 'sha256', $file );
}

echo "  📦 Preparing build...\n";

// Phase 34: CI passes --skip-validation to bypass the test/phpstan/phpcs
// pre-flight when the ZIP-certification step is wired into the
// version-check job (those checks already gate the `test` and `lint`
// jobs; re-running them inside the build is a ~30s penalty that does
// not change the ZIP contract this step certifies).
if ( in_array( '--skip-validation', $argv, true ) ) {
	$config['run_tests']   = false;
	$config['run_phpstan'] = false;
	$config['run_phpcs']   = false;
	echo "     ⚡ --skip-validation: skipping pre-flight tests/phpstan/phpcs\n";
}

try {
	$version = get_version( $root, $config['mainPluginFile'] );
	echo "     Version: $version\n";
} catch ( RuntimeException $e ) {
	echo "     ❌ " . $e->getMessage() . "\n";
	exit( 1 );
}

echo "\n===========================================\n";
echo "  PRE-BUILD VALIDATION\n";
echo "===========================================\n\n";

if ( $config['run_tests'] ) {
	if ( ! run_tests( $root ) ) {
		echo "\n❌ Build aborted: PHPUnit tests failed\n";
		exit( 1 );
	}
}

if ( $config['run_phpstan'] ) {
	if ( ! run_phpstan( $root ) ) {
		echo "\n❌ Build aborted: PHPStan failed\n";
		exit( 1 );
	}
}

if ( $config['run_phpcs'] ) {
	if ( ! run_phpcs( $root ) ) {
		echo "\n❌ Build aborted: PHPCS failed\n";
		exit( 1 );
	}
}

echo "\n===========================================\n";
echo "  CLEANING PREVIOUS BUILDS\n";
echo "===========================================\n\n";

$dist_dir = $root . '/dist';

if ( $config['auto_clean_root'] && is_dir( $root . '/build' ) ) {
	echo "  🧹 Cleaning root build folder...\n";
	rrmdir( $root . '/build' );
}

if ( is_dir( $dist_dir ) ) {
	if ( $config['clean_dist'] ) {
		echo "  🧹 Cleaning dist folder...\n";
		rrmdir( $dist_dir );
	}
}

if ( ! is_dir( $dist_dir ) ) {
	mkdir( $dist_dir, 0755, true );
}

echo "\n===========================================\n";
echo "  BUILDING PLUGIN\n";
echo "===========================================\n\n";

$plugin_dir = $dist_dir . '/sscribe-export-site-pages';
if ( ! is_dir( $plugin_dir ) ) {
	mkdir( $plugin_dir, 0755, true );
}

$distignore_excludes = get_distignore_excludes( $root, $config['distignore'] );
$excludes = array_unique( array_merge( $all_excludes, $distignore_excludes ) );

echo "  📁 Copying files...\n";

$dir    = new RecursiveDirectoryIterator( $root, RecursiveDirectoryIterator::SKIP_DOTS );
$filter = new RecursiveCallbackFilterIterator(
	$dir,
	static function ( $current ) use ( $root, $excludes ): bool {
		if ( $current->isLink() ) {
			return false;
		}
		$relative = str_replace( $root . DIRECTORY_SEPARATOR, '', $current->getPathname() );
		$relative = str_replace( $root . '/', '', $relative );
		$relative_norm = str_replace( '\\', '/', $relative );

		return ! is_release_path_excluded( $relative_norm, $excludes );
	}
);

$iterator   = new RecursiveIteratorIterator( $filter, RecursiveIteratorIterator::SELF_FIRST );
$copied     = 0;

foreach ( $iterator as $file ) {
	$relative = str_replace( $root . DIRECTORY_SEPARATOR, '', $file->getPathname() );
	$relative = str_replace( $root . '/', '', $relative );
	$dest     = $plugin_dir . '/' . $relative;

	if ( $file->isDir() ) {
		if ( ! is_dir( $dest ) && ! mkdir( $dest, 0755, true ) && ! is_dir( $dest ) ) {
			throw new RuntimeException( 'Unable to create release directory: ' . $relative );
		}
	} else {
		$dest_parent = dirname( $dest );
		if ( ! is_dir( $dest_parent ) && ! mkdir( $dest_parent, 0755, true ) && ! is_dir( $dest_parent ) ) {
			throw new RuntimeException( 'Unable to create release parent directory: ' . dirname( $relative ) );
		}
		if ( $config['strip_comments'] ) {
			$ext = strtolower( pathinfo( $file->getPathname(), PATHINFO_EXTENSION ) );
			if ( 'php' === $ext ) {
				$src = file_get_contents( $file->getPathname() );
				file_put_contents( $dest, sanitize_ai_artifacts( strip_php_comments( $src ) ) );
			} elseif ( 'css' === $ext ) {
				$src = file_get_contents( $file->getPathname() );
				file_put_contents( $dest, sanitize_ai_artifacts( strip_css_comments( $src ) ) );
			} elseif ( 'js' === $ext ) {
				$src = file_get_contents( $file->getPathname() );
				file_put_contents( $dest, sanitize_ai_artifacts( strip_js_comments( $src ) ) );
			} else {
				// Only sanitize known text assets. Binary files (fonts,
				// images, compiled translations, etc.) must be copied
				// byte-for-byte; running string replacement across binary
				// payloads can corrupt otherwise valid assets.
				$text_extensions = array(
					'txt', 'md', 'pot', 'po', 'json', 'xml', 'html', 'htm',
					'svg', 'ini', 'csv', 'yml', 'yaml', 'map',
				);
				$basename = $file->getBasename();
				$is_extensionless_text = in_array(
					$basename,
					array( 'LICENSE', 'COPYING', 'NOTICE' ),
					true
				);
				if ( in_array( $ext, $text_extensions, true ) || $is_extensionless_text ) {
					$src = file_get_contents( $file->getPathname() );
					if ( false === $src ) {
						throw new RuntimeException( 'Unable to read release text file: ' . $relative );
					}
					file_put_contents( $dest, sanitize_ai_artifacts( $src ) );
				} elseif ( ! copy( $file->getPathname(), $dest ) ) {
					throw new RuntimeException( 'Unable to copy binary release file: ' . $relative );
				}
			}
		} else {
			if ( ! copy( $file->getPathname(), $dest ) ) {
				throw new RuntimeException( 'Unable to copy release file: ' . $relative );
			}
		}
		$copied++;
	}
}

echo "     ✅ Copied: $copied files\n";

// The upstream PHPWord notice is required for LGPL attribution, but Plugin
// Check treats its `.LESSER` suffix as a forbidden extension. Copy the exact
// bytes into the distribution under an accepted text filename even when the
// generated vendor tree has just been rebuilt from scratch.
$lgpl_source = $root . '/vendor-prefixed/phpoffice/phpword/COPYING.LESSER';
$lgpl_destination = $plugin_dir . '/vendor-prefixed/phpoffice/phpword/COPYING.LESSER.txt';
if ( is_file( $lgpl_source ) ) {
	if ( ! is_dir( dirname( $lgpl_destination ) ) ) {
		mkdir( dirname( $lgpl_destination ), 0755, true );
	}
	if ( ! copy( $lgpl_source, $lgpl_destination ) ) {
		echo "     ❌ Failed to normalize the PHPWord LGPL notice filename\n";
		exit( 1 );
	}
}

if ( $config['strip_comments'] ) {
	echo "  🧹 Stripping non-docblock comments from PHP / CSS / JS files...\n";
}

echo "  Pruning vendor development files...\n";
$vendor_dir = $plugin_dir . '/vendor-prefixed';
if ( is_dir( $vendor_dir ) ) {
	$prune_patterns = array(
		'tests', 'docs', '.github', 'samples', 'examples', 'utils', 'bin',
		'other',
		/* PHP 5 polyfill; not autoloaded on the plugin's PHP 8.2+ runtime. */
		'random_compat',
		'composer.json', 'composer.lock', 'installed.json', 'package.json', 'phpunit.xml',
		'phpunit.xml.dist', 'phpmd.xml.dist', 'phpword.ini.dist',
		'.gitignore', '.gitattributes', '.travis.yml', '.scrutinizer.yml',
		'CHANGELOG.md', 'CONTRIBUTING.md', 'README.md', 'CREDITS.txt',
		'SECURITY.md', /* setasign/fpdi: dev doc, not autoloaded */
		// LICENSE/COPYING preserved: WordPress.org Plugin Directory
		// Guideline 1 requires third-party license texts to ship with
		// the bundled code. Removing them was a WP.org compliance bug.
		// Keeping them here leaves license.txt's "preserved alongside
		// its source" claim accurate, and lets a reviewer grep the ZIP
		// for a license when checking TCPDF/PHPWord attribution.
		'.github_changelog_generator', 'roave-bc-check.yaml',
		/* Development-only package files. */
		'psalm-autoload.php',
		/* setasign/fpdi: ad-hoc manual test scripts that read files from
		 * outside the package directory; not autoloaded, never referenced
		 * by the runtime PDFs we generate. */
		'local-tests',
		/* setasign/fpdi: scratch experiments checked into the repo next to
		 * `src/` - not part of the library, not autoloaded. */
		'scratches',
		/* myclabs/deep-copy: generated doc/ images and graph PNGs that
		 * sit next to `src/`; not autoloaded, only used by the package's
		 * own README on GitHub. */
		'doc',
		/* myclabs/deep-copy: PHP test fixtures that deep-copy exercises
		 * under tests/ - never autoloaded by the runtime. */
		'fixtures',
		/* phpoffice/phpword: brand PNGs in resources/ - referenced
		 * only by PHPWord's own README on GitHub; the runtime
		 * never loads them and the export format doesn't embed them. */
		'doc.png',
		'ppt.png',
		'xls.png',
	);

	$pruned_count = 0;
	// Extensions that WordPress.org Plugin Check rejects as build artifacts.
	$prune_extensions = array( 'sh', 'bat', 'cmd', 'exe', 'msi', 'pkg', 'dmg', 'phar' );
	$prune_files      = array(
		'tecnickcom/tcpdf/Makefile',
		'tecnickcom/tcpdf/VERSION',
	);

	$v_iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $vendor_dir, RecursiveDirectoryIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);

	foreach ( $v_iterator as $item ) {
		$name     = $item->getBasename();
		$ext      = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
		$relative = str_replace( '\\', '/', substr( $item->getPathname(), strlen( $vendor_dir ) + 1 ) );
		$matched = in_array( $name, $prune_patterns, true )
			|| ( ! $item->isDir() && in_array( $relative, $prune_files, true ) )
			|| ( ! $item->isDir() && in_array( $ext, $prune_extensions, true ) );
		if ( $matched ) {
			if ( $item->isDir() ) {
				rrmdir( $item->getPathname() );
			} else {
				@unlink( $item->getPathname() );
			}
			$pruned_count++;
		}
	}
	// TCPDF ships ~25 MB of font assets. SScribe pins PDF rendering to
	// DejaVu Sans (regular/bold/italic/bold-italic) and TCPDF's initial
	// Helvetica core font, so all other generated fonts are unreachable.
	// Retain the upstream DejaVu license texts alongside the generated data.
	$tcpdf_fonts_dir = $vendor_dir . '/tecnickcom/tcpdf/fonts';
	if ( is_dir( $tcpdf_fonts_dir ) ) {
		$tcpdf_font_allow = array(
			'helvetica.php',
			'dejavusans.php', 'dejavusans.z', 'dejavusans.ctg.z',
			'dejavusansb.php', 'dejavusansb.z', 'dejavusansb.ctg.z',
			'dejavusansi.php', 'dejavusansi.z', 'dejavusansi.ctg.z',
			'dejavusansbi.php', 'dejavusansbi.z', 'dejavusansbi.ctg.z',
			'dejavu-fonts-ttf-2.33/LICENSE',
			'dejavu-fonts-ttf-2.34/LICENSE',
		);
		$font_iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $tcpdf_fonts_dir, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $font_iterator as $font_item ) {
			$font_relative = str_replace( '\\', '/', substr( $font_item->getPathname(), strlen( $tcpdf_fonts_dir ) + 1 ) );
			if ( $font_item->isDir() ) {
				$children = new RecursiveDirectoryIterator( $font_item->getPathname(), RecursiveDirectoryIterator::SKIP_DOTS );
				if ( 0 === iterator_count( $children ) ) {
					@rmdir( $font_item->getPathname() );
				}
				continue;
			}
			if ( ! in_array( $font_relative, $tcpdf_font_allow, true ) ) {
				@unlink( $font_item->getPathname() );
				++$pruned_count;
			}
		}
	}

	// After pruning matched files, sweep up any directory under vendor-prefixed
	// that is now empty. CHILD_FIRST ordering means we already attempted to
	// delete every matched directory; this pass catches directories that only
	// contained prune-matched files (e.g. phpoffice/phpword/src/PhpWord/resources
	// after the doc.png/ppt.png/xls.png PNGs are removed) and any vendor
	// directory that was empty in the upstream package.
	$empty_dir_iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $vendor_dir, RecursiveDirectoryIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $empty_dir_iterator as $item ) {
		if ( ! $item->isDir() ) {
			continue;
		}
		$children = new RecursiveDirectoryIterator( $item->getPathname(), RecursiveDirectoryIterator::SKIP_DOTS );
		if ( 0 === iterator_count( $children ) ) {
			@rmdir( $item->getPathname() );
		}
	}
	echo "     ✅ Pruned: $pruned_count vendor development artifacts\n";
}

echo "  Validating distribution contents...\n";
$distribution_errors = array();
$readme_path         = $plugin_dir . '/readme.txt';
if ( is_file( $readme_path ) ) {
	$readme_size = filesize( $readme_path );
	if ( false === $readme_size || $readme_size >= 10240 ) {
		$distribution_errors[] = 'readme.txt must be smaller than 10 KiB for WordPress.org.';
	}
}
$required_release_files = array(
	'sscribe-export-site-pages.php',
	'readme.txt',
	'composer.json',
	'vendor-prefixed/autoload.php',
	'vendor-prefixed/phpoffice/phpword/COPYING.LESSER.txt',
	'vendor-prefixed/tecnickcom/tcpdf/LICENSE.TXT',
);
foreach ( $required_release_files as $required_release_file ) {
	if ( ! is_file( $plugin_dir . '/' . $required_release_file ) ) {
		$distribution_errors[] = 'Missing required release file: ' . $required_release_file;
	}
}

$blocked_extensions = array( 'lesser', 'dist', 'sh', 'bat', 'cmd', 'exe', 'msi', 'pkg', 'dmg', 'phar' );
$allowed_extensionless = array( 'COPYING', 'LICENSE', 'NOTICE' );
$distribution_iterator = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator( $plugin_dir, RecursiveDirectoryIterator::SKIP_DOTS ),
	RecursiveIteratorIterator::LEAVES_ONLY
);
foreach ( $distribution_iterator as $distribution_file ) {
	if ( ! $distribution_file->isFile() ) {
		continue;
	}

	$name      = $distribution_file->getBasename();
	$extension = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
	$relative  = str_replace( '\\', '/', substr( $distribution_file->getPathname(), strlen( $plugin_dir ) + 1 ) );

	if ( in_array( $extension, $blocked_extensions, true ) ) {
		$distribution_errors[] = "Forbidden release file type: {$relative}";
	} elseif ( '' === $extension && ! in_array( $name, $allowed_extensionless, true ) ) {
		$distribution_errors[] = "Unexpected extensionless release file: {$relative}";
	}
}

if ( ! empty( $distribution_errors ) ) {
	foreach ( $distribution_errors as $distribution_error ) {
		echo "     ❌ {$distribution_error}\n";
	}
	exit( 1 );
}
echo "     ✅ Distribution file types and license notices validated\n";

echo "\n===========================================\n";
echo "  CREATING RELEASE PACKAGE\n";
echo "===========================================\n\n";

$zip_file = realpath( $dist_dir ) . DIRECTORY_SEPARATOR . "sscribe-export-site-pages-{$version}.zip";

$zip = new ZipArchive();
if ( true !== $zip->open( $zip_file, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
	echo "  ❌ Could not create ZIP file at: $zip_file\n";
	exit( 1 );
}

// Determinism contract (release invariants #5): iterate entries in a
// stable, sorted order so the ZIP SHA-256 is reproducible from source +
// tools. RecursiveDirectoryIterator order is filesystem-dependent and
// would otherwise inject platform entropy into every build.
$file_list = array();
$directory_iterator = new RecursiveDirectoryIterator( $plugin_dir, RecursiveDirectoryIterator::SKIP_DOTS );
foreach ( new RecursiveIteratorIterator( $directory_iterator, RecursiveIteratorIterator::LEAVES_ONLY ) as $file ) {
	if ( $file->isDir() ) {
		continue;
	}
	$full_path = str_replace( '\\', '/', $file->getPathname() );
	$file_list[] = $full_path;
}
sort( $file_list, SORT_STRING );

// Fixed mtime (epoch 0) so every entry has identical timestamp metadata;
// this guarantees the archive bytes are determined entirely by file
// contents and ordering, not by filesystem mtime or current clock.
$fixed_mtime = 0;

$plugin_dir_norm = str_replace( '\\', '/', $plugin_dir );

foreach ( $file_list as $full_path ) {
	$relative_in_zip = 'sscribe-export-site-pages/' . str_replace( $plugin_dir_norm . '/', '', $full_path );
	$zip->addFile( $full_path, $relative_in_zip );
	if ( method_exists( $zip, 'setMtimeName' ) ) {
		$zip->setMtimeName( $relative_in_zip, $fixed_mtime );
	}
}

if ( ! $zip->close() ) {
	echo "  ❌ Could not close ZIP file. (Path length or lock issue)\n";
	exit( 1 );
}

$zip_size = filesize( $zip_file );
if ( false === $zip_size || $zip_size >= 10000000 ) {
	echo "  ❌ Submission ZIP must be smaller than 10,000,000 bytes\n";
	exit( 1 );
}

echo "\n===========================================\n";
echo "  GENERATING CHECKSUMS\n";
echo "===========================================\n\n";

if ( $config['generate_sha256'] ) {
	$checksum = generate_checksum( $zip_file );
	$checksum_file = $dist_dir . '/sscribe-export-site-pages-' . $version . '.sha256';
	file_put_contents( $checksum_file, $checksum );
	echo "  ✅ SHA-256: $checksum\n";
}

$duration = round( microtime( true ) - $start_time, 2 );

echo "\n===========================================\n";
echo "  BUILD COMPLETE\n";
echo "===========================================\n\n";

echo "  📦 Plugin: sscribe-export-site-pages\n";
echo "  📌 Version: $version\n";
echo "  📁 Files: $copied copied\n";
echo "  📄 ZIP Size: " . format_bytes( $zip_size ) . "\n";
echo "  🔗 Location: dist/sscribe-export-site-pages-{$version}.zip\n";
echo "  ⏱️  Duration: {$duration}s\n";

echo "\n===========================================\n";
echo "  POST-BUILD INVARIANT CHECK\n";
echo "===========================================\n\n";

$invariant_test = $root . '/tests/Unit/SScribe_Shipped_Invariants_Test.php';
if ( is_file( $invariant_test ) && is_file( $root . '/vendor/bin/phpunit' ) ) {
	echo "  🔍 Verifying shipped-ZIP invariants (no em-dash, no AI personas, no Co-Authored-By, no rejected headers)...\n";
	$output = array();
	$return = 0;
	exec(
		'php "' . $root . '/vendor/bin/phpunit" --filter SScribe_Shipped_Invariants_Test --no-coverage 2>&1',
		$output,
		$return
	);
	if ( 0 !== $return ) {
		echo "     ❌ Shipped-ZIP invariants FAILED:\n";
		foreach ( $output as $line ) {
			echo "     " . $line . "\n";
		}
		exit( 1 );
	}
	echo "     ✅ Shipped-ZIP invariants verified\n";
} else {
	echo "  ⚠️  Invariant test not present; skipping post-build check\n";
}

echo "\n===========================================\n";
echo "  READY FOR WORDPRESS.ORG\n";
echo "===========================================\n\n";

// Phase 24: build transparency. Reviewers must be able to see at a
// glance which transformations were applied to the working tree to
// produce the release ZIP. Enumerate every category so a future
// maintainer does not have to read scripts/build-release.php to
// discover the delta between the git checkout and the shipped
// artifact.
echo "Build transformations applied (working tree -> shipped ZIP):\n\n";
echo "  Excluded paths:\n";
echo "    - All base_excludes entries (dev-only dirs: tests, tests-wp,\n";
echo "      tests-js, tests-e2e, scripts, .github, docs, examples, samples,\n";
echo "      .superpowers, .audit, .agent, .claude, .opencode, .cursor,\n";
echo "      .windsurf, .continue, .codeium, .aider*, .mimosa, .omo,\n";
echo "      node_modules, vendor-prefixed/.github, vendor-prefixed/.git,\n";
echo "      vendor-prefixed/*/tests, vendor-prefixed/*/docs,\n";
echo "      vendor-prefixed/*/utils, vendor-prefixed/*/tmp,\n";
echo "      stubs, .stubs, dist, .git, .gitignore, .distignore,\n";
echo "      .phpunit.cache, scratch, bin, etc.).\n";
echo "    - Dev-only root files: phpunit-wp.xml, playwright.config.ts,\n";
echo "      composer.lock, infection.json5, commit-message.txt,\n";
echo "      strauss.json, CONTRIBUTING.md, CHANGELOG.md, phpstan*.neon*,\n";
echo "      phpunit.xml*, phpcs.xml, phpstan-bootstrap.php,\n";
echo "      .editorconfig, .prettierrc, .eslintrc.json, .stylelintrc.json,\n";
echo "      .php-cs-fixer.php, mkdocs.yml, .travis.yml, .scrutinizer.yml,\n";
echo "      .github_changelog_generator, ruleset.xml, CREDITS.txt,\n";
echo "      .wp-env.json, .distignore, .gitattributes, .debug-journal.md,\n";
echo "      WPScan, wordpress, wordpress-tests-lib.\n";
echo "    - .distignore entries (segment-level match against\n";
echo "      vendor-prefixed/*/tests, vendor-prefixed/*/docs,\n";
echo "      vendor-prefixed/phpoffice/phpword/COPYING.LESSER,\n";
echo "      vendor-prefixed/phpoffice/phpword/phpword.ini.dist, etc.).\n";
echo "      UnBatang, Aegyptus, Aegean, Akkadian, Jomolhari, KhmerOS,\n";
echo "      Abyssinica SIL, etc.; XB Riyaz Bold/Italic/BoldItalic;\n";
echo "      Dhyana; Garuda; DejaVuSans variants not registered).\n";
echo "      parent-landmine memory).\n\n";

echo "  In-place transformations (each shipped PHP/CSS/JS file is\n";
echo "  rewritten before being written to dist/):\n";
echo "    - PHP: T_DOC_COMMENT preserved (for @preserve/@var/@type);\n";
echo "      T_COMMENT stripped unless pragma-annotated (phpcs:|@preserve);\n";
echo "      T_OPEN_TAG / T_STRING / T_VARIABLE untouched.\n";
echo "    - CSS: all /* ... */ comments stripped.\n";
echo "    - JS: license banners /*! ... */ and jsdoc /** ... */ preserved;\n";
echo "      other /* ... */ block comments stripped; line // comments\n";
echo "      stripped (except those preceded by :, \", ', or `, to keep\n";
echo "      URLs and JSON literal colons intact).\n";
echo "    - All four strip outputs pass through sanitize_ai_artifacts()\n";
echo "      which replaces em-dash (U+2014), en-dash (U+2013), ellipsis\n";
echo "      (U+2026), and curly quotes (U+201C/D, U+2018/9) with their\n";
echo "      ASCII equivalents. Required because the comment-strip pass\n";
echo "      preserves jsdoc / docblock content, which historically\n";
echo "      leaked Unicode into the ZIP. Known text assets also pass\n";
echo "      through the sanitizer as a backstop; binary assets (fonts,\n";
echo "      images, compiled translations) are copied byte-for-byte.\n\n";

echo "  Vendor-specific rewrites:\n";
echo "    - vendor-prefixed/phpoffice/phpword/COPYING.LESSER renamed to\n";
echo "      COPYING.LESSER.txt (WP.org plugin-check rejects the bare\n";
echo "      .lesser extension as an unexpected file type).\n";
echo "    - vendor-prefixed/phpoffice/phpword/src/PhpWord/Shared/PCLZip/\n";
echo "      removed (PCLZip conflict with WordPress core PCLZip).\n";
echo "    - vendor-prefixed/phpoffice/phpword/phpword.ini.dist removed\n";
echo "      (dead weight — Settings::loadConfig() is never called in\n";
echo "      SScribe code).\n";
echo "    - includes/sscribe-vendor-compat.php removed (local-dev\n";
echo "      shim — release code uses only prefixed vendors).\n\n";

echo "  Distribution paths created under a different relative name:\n";
echo "    - vendor-prefixed/phpoffice/phpword/COPYING.LESSER.txt is copied byte-for-byte from\n";
echo "      vendor-prefixed/phpoffice/phpword/COPYING.LESSER so the required LGPL notice\n";
echo "      ships under an extension accepted by WordPress Plugin Check.\n";
echo "    - All other distribution files retain their source-relative path.\n\n";

echo "Build successful. No development files or unused fonts included.\n\n";
