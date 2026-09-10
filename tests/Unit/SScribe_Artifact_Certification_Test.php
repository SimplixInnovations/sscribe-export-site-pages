<?php
/**
 * SScribe Artifact Certification Test
 *
 * @package SScribe_Export_Site_Pages
 *
 * Phase 28: artifact certification. Locks in that the release ZIP
 * is a faithful, complete, parseable artifact of the documented
 * build pipeline.
 *
 * The release ZIP is the only artifact the WP.org reviewer ever
 * sees. Any drift between what `git` tracks and what `dist/*.zip`
 * ships — whether an unexpected extra file, a missing file, a
 * stale sha256, or a forbidden path sneaking through the
 * exclusion list — is a release-blocker.
 *
 * Invariants this test enforces (run against the pre-built
 * `dist/sscribe-export-site-pages-<version>.zip`):
 *
 *   1. SHA256 parity. The hash in `dist/*.sha256` matches the
 *      actual SHA256 of `dist/*.zip`. A stale sidecar file is
 *      a release-blocker because the WP.org upload tool
 *      cross-checks the hash on submission.
 *
 *   2. ZIP ↔ staging-dir parity. The file listing inside the
 *      ZIP is exactly the file listing inside
 *      `dist/sscribe-export-site-pages/`. The reviewer can
 *      unpack the ZIP and get the same tree — no synthesized
 *      files, no dropped files, no out-of-tree paths.
 *
 *   3. No forbidden top-level entries. The ZIP must not contain
 *      any of the documented excluded paths: tests/, tests-wp/,
 *      tests-e2e/, scripts/, .github/, docs/, examples/,
 *      samples/, .git*, .distignore, .gitignore, .editorconfig,
 *      phpunit*.xml, phpcs.xml, phpstan*.neon*, composer.lock,
 *      playwright.config.ts, node_modules/, vendor/, vendor-prefixed/,
 *      stubs/, .cache/, .playground-cache/, .phpunit.cache/,
 *      .superpowers/, .audit/, scratch/, tmp/, dist/, build/,
 *      coverage/, etc.
 *
 *   4. Every shipped PHP, CSS, and JS file is non-empty. An
 *      accidental empty file slipping through the strip pipeline
 *      (e.g. a CSS file that becomes 0 bytes after comment
 *      stripping) would break the admin UI silently.
 *
 *   5. The build-strip-comments invariant is layered: a
 *      regression here indicates the strip pipeline in
 *      scripts/build-release.php regressed. This test is the
 *      certification gate that Phase 24 (Build Transparency)
 *      and Phase 22 (Repo Hygiene) hang off.
 *
 * The test reads the ZIP that already exists in dist/. Set
 * SSCRIBE_REBUILD_BEFORE_TEST=1 in the environment to force
 * a rebuild before assertion.
 */

declare( strict_types=1 );

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZipArchive;

final class SScribe_Artifact_Certification_Test extends TestCase {

	private const PLUGIN_SLUG = 'sscribe-export-site-pages';

	/**
	 * Top-level entries that MUST NOT be in the release ZIP. These
	 * are dev-only artifacts that are explicitly stripped by the
	 * build pipeline per docs/BUILD_TRANSFORMATIONS.md §1.
	 *
	 * Match is exact-segment for directories and exact-filename for
	 * root files (per `is_release_path_excluded` in build-release.php).
	 *
	 * @var string[]
	 */
	private const FORBIDDEN_TOP_LEVEL = array(
		// Dev-only directories.
		'tests',
		'tests-wp',
		'tests-e2e',
		'scripts',
		'.github',
		'docs',
		'examples',
		'samples',
		'.audit',
		'.agent',
		'.claude',
		'.opencode',
		'.cursor',
		'.windsurf',
		'.continue',
		'.codeium',
		'.mimosa',
		'.omo',
		'.sisyphus',
		'.superpowers',
		'.codegraph',
		'.wp-env',
		'.playground-tools',
		'.playground-cache',
		'.phpunit.cache',
		'.cache',
		'stubs',
		'.stubs',
		'bin',
		'node_modules',
		'dist',
		'build',
		'coverage',
		'sscribe-exports',
		'WPScan',
		'WordPress-Core',
		'wordpress',
		'wordpress-tests-lib',
		'scratch',
		'tmp',
		'tmp_diffs',
		'vendor',
		'husky',

		// Dev-only root files.
		'.git',
		'.gitattributes',
		'.gitignore',
		'.distignore',
		'phpunit-wp.xml',
		'playwright.config.ts',
		'composer.lock',
		'CONTRIBUTING.md',
		'CHANGELOG.md',
		'phpstan.neon',
		'phpstan.neon.dist',
		'phpstan-baseline.neon',
		'phpstan-bootstrap.php',
		'phpunit.xml',
		'phpunit.xml.dist',
		'phpcs.xml',
		'.editorconfig',
		'.prettierrc',
		'.eslintrc.json',
		'.stylelintrc.json',
		'.php-cs-fixer.php',
		'.php-cs-fixer.dist.php',
		'mkdocs.yml',
		'.travis.yml',
		'.scrutinizer.yml',
		'.github_changelog_generator',
		'ruleset.xml',
		'CREDITS.txt',
		'.wp-env.json',
		'review-diff.patch',
		'opencode.json',
		'eslint.config.js',
		'aider.input.history',
		'aider.chat.history',
		'.aider.model.settings.json',
		'playground-blueprint.json',
		'infection.json5',
		'commit-message.txt',
		'strauss.json',
		'.debug-journal.md',
		'.DS_Store',
		'Thumbs.db',
		'desktop.ini',
		// Generically-patterned files: caught at the extension level too.
	);

	/**
	 * File extensions that MUST NOT appear anywhere in the ZIP.
	 * These are dev-only artifacts that nothing in production
	 * references (a regression here means the build script's
	 * extension filter broke).
	 *
	 * @var string[]
	 */
	private const FORBIDDEN_EXTENSIONS = array(
		'py',  // No Python in a WP plugin.
		'log', // No log files.
		'tmp', // No editor temp files.
		'bak', // No editor backup files.
	);

	/** @var array<int, string> */
	private static array $zip_entries = array();

	/** @var array<int, string> */
	private static array $staging_entries = array();

	private static ?string $plugin_root = null;

	private static ?string $dist_dir = null;

	private static ?string $version = null;

	private static ?string $zip_path = null;

	private static ?string $staging_dir = null;

	private static ?string $sha256_sidecar = null;

	public static function setUpBeforeClass(): void {
		self::$plugin_root   = dirname( __DIR__, 2 );
		self::$dist_dir      = self::$plugin_root . '/dist';
		self::$version       = self::detect_version();
		self::$zip_path      = self::$dist_dir . '/' . self::PLUGIN_SLUG . '-' . self::$version . '.zip';
		self::$staging_dir   = self::$dist_dir . '/' . self::PLUGIN_SLUG;
		self::$sha256_sidecar = self::$dist_dir . '/' . self::PLUGIN_SLUG . '-' . self::$version . '.sha256';

		if ( ! is_dir( self::$dist_dir ) || ! is_file( self::$zip_path ) || ! is_dir( self::$staging_dir ) ) {
			self::markTestSkipped(
				'Required dist artifacts missing — run scripts/build-release.php first.'
			);
		}
		// The staging directory must actually contain files (not just exist
		// as an empty placeholder). An empty staging dir would silently pass
		// the existence check above and then explode in the parity assertion
		// with 1000+ ZIP entries vs zero staging entries.
		$staging_probe = self::read_dir_listing( self::$staging_dir );
		if ( 0 === count( $staging_probe ) ) {
			self::markTestSkipped(
				'Staging directory is empty — run scripts/build-release.php first.'
			);
		}

		if ( getenv( 'SSCRIBE_REBUILD_BEFORE_TEST' ) === '1' ) {
			self::rebuild_zip();
		}

		self::$zip_entries     = self::read_zip_listing( self::$zip_path );
		self::$staging_entries = self::read_dir_listing( self::$staging_dir );
	}

	public static function tearDownAfterClass(): void {
		// The staging directory is part of the dist artifact set,
		// not a transient test extract — do NOT clean it up.
	}

	private static function detect_version(): string {
		$plugin_file = self::$plugin_root . '/sscribe-export-site-pages.php';
		if ( ! is_file( $plugin_file ) ) {
			self::markTestSkipped( 'Plugin file missing.' );
		}
		$contents = (string) file_get_contents( $plugin_file );
		if ( ! preg_match( '/Version:\s*([0-9.]+)/', $contents, $match ) ) {
			self::markTestSkipped( 'Could not detect plugin version.' );
		}
		return $match[1];
	}

	private static function rebuild_zip(): void {
		$build = self::$plugin_root . '/scripts/build-release.php';
		if ( ! is_file( $build ) ) {
			self::markTestSkipped( 'Build script missing.' );
		}
		$cmd = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $build ) . ' 2>&1';
		exec( $cmd, $output, $exit );
		if ( 0 !== $exit ) {
			self::fail( 'Rebuild failed: ' . implode( "\n", $output ) );
		}
		// Re-read the now-fresh artifacts.
		self::$zip_entries     = self::read_zip_listing( self::$zip_path );
		self::$staging_entries = self::read_dir_listing( self::$staging_dir );
	}

	/**
	 * Returns the sorted list of file paths inside a ZIP archive
	 * (relative to the archive root, using forward slashes).
	 *
	 * @return array<int, string>
	 */
	private static function read_zip_listing( string $zip_path ): array {
		$zip = new ZipArchive();
		if ( true !== $zip->open( $zip_path ) ) {
			self::fail( 'Failed to open ZIP: ' . $zip_path );
		}
		$entries = array();
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$stat = $zip->statIndex( $i );
			if ( ! is_array( $stat ) || empty( $stat['name'] ) ) {
				continue;
			}
			// ZIP entries use forward slashes; convert to OS for
			// comparison with the staging-dir listing (normalized
			// to forward slashes below).
			$entries[] = str_replace( '\\', '/', (string) $stat['name'] );
		}
		$zip->close();
		sort( $entries );
		return $entries;
	}

	/**
	 * @return array<int, string>
	 */
	private static function read_dir_listing( string $root ): array {
		$entries = array();
		$iter    = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $root, \RecursiveDirectoryIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::LEAVES_ONLY
		);
		foreach ( $iter as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}
			$absolute = $file->getPathname();
			$relative = str_replace( $root . DIRECTORY_SEPARATOR, '', $absolute );
			$relative = str_replace( '\\', '/', $relative );
			$entries[] = $relative;
		}
		sort( $entries );
		return $entries;
	}

	public function test_sha256_sidecar_matches_actual_zip_hash(): void {
		if ( ! is_file( self::$sha256_sidecar ) ) {
			$this->markTestSkipped( 'No .sha256 sidecar at ' . self::$sha256_sidecar );
		}
		$sidecar = trim( (string) file_get_contents( self::$sha256_sidecar ) );
		// The sidecar format is "<sha>  filename" (two spaces between
		// hash and filename, per `sha256sum` output).
		$parts = preg_split( '/\s+/', $sidecar );
		$side_hash = is_array( $parts ) ? $parts[0] : '';
		$this->assertNotEmpty( $side_hash, 'sha256 sidecar is empty or malformed.' );

		$actual_hash = hash_file( 'sha256', self::$zip_path );
		$this->assertSame(
			$side_hash,
			$actual_hash,
			'sha256 sidecar does not match the actual ZIP hash. ' .
			'Re-run scripts/build-release.php to regenerate the sidecar.'
		);
	}

	public function test_zip_listing_matches_staging_directory_listing(): void {
		$zip     = self::$zip_entries;
		$staging = self::$staging_entries;

		// Both listings are relative to their root which is the plugin
		// slug directory. Stage listing is relative to that dir; ZIP
		// listing is relative to the archive root which also includes
		// the slug dir as a top-level entry. Strip the leading slug
		// from ZIP entries for comparison.
		$zip_normalized = array();
		$slug_prefix    = self::PLUGIN_SLUG . '/';
		$slug_prefix_len = strlen( $slug_prefix );
		foreach ( $zip as $entry ) {
			if ( 0 === strpos( $entry, $slug_prefix ) ) {
				$zip_normalized[] = substr( $entry, $slug_prefix_len );
			} elseif ( self::PLUGIN_SLUG === $entry ) {
				// The top-level directory entry; ignore.
				continue;
			} else {
				// File at the root of the archive — should not happen
				// for our build. Keep it so the parity diff surfaces it.
				$zip_normalized[] = $entry;
			}
		}
		sort( $zip_normalized );

		$only_in_zip     = array_diff( $zip_normalized, $staging );
		$only_in_staging = array_diff( $staging, $zip_normalized );

		$this->assertSame(
			array(),
			$only_in_zip,
			'Files inside the ZIP that are NOT in dist/sscribe-export-site-pages/. ' .
			'This means the build wrote something not on disk, or the ZIP ' .
			'wasn\'t rebuilt after a source change.'
		);
		$this->assertSame(
			array(),
			$only_in_staging,
			'Files in dist/sscribe-export-site-pages/ that are NOT in the ZIP. ' .
			'This means the ZIP is stale. Run scripts/build-release.php.'
		);
	}

	public function test_zip_contains_no_forbidden_top_level_entries(): void {
		$top_level = array();
		foreach ( self::$zip_entries as $entry ) {
			$first = explode( '/', $entry, 2 )[0];
			$top_level[ $first ] = true;
		}
		$top_level = array_keys( $top_level );
		sort( $top_level );

		$bad = array_values( array_intersect( $top_level, self::FORBIDDEN_TOP_LEVEL ) );
		$this->assertSame(
			array(),
			$bad,
			'Forbidden top-level entries found in ZIP: ' . implode( ', ', $bad ) . '. ' .
			'These must be excluded per docs/BUILD_TRANSFORMATIONS.md §1.'
		);
	}

	public function test_zip_contains_no_files_with_forbidden_extensions(): void {
		$bad = array();
		foreach ( self::$zip_entries as $entry ) {
			$ext = strtolower( pathinfo( $entry, PATHINFO_EXTENSION ) );
			if ( in_array( $ext, self::FORBIDDEN_EXTENSIONS, true ) ) {
				$bad[] = $entry;
			}
		}
		$this->assertSame(
			array(),
			$bad,
			'ZIP contains files with forbidden extensions: ' . implode( ', ', $bad )
		);
	}

	public function test_zip_contains_no_dotfile_top_level_entries(): void {
		// Catch-all for hidden config files at the ZIP root that
		// weren't enumerated in FORBIDDEN_TOP_LEVEL.
		$dotfile_top = array();
		foreach ( self::$zip_entries as $entry ) {
			$first = explode( '/', $entry, 2 )[0];
			if ( 0 === strpos( $first, '.' ) && self::PLUGIN_SLUG !== $first ) {
				$dotfile_top[] = $first;
			}
		}
		$dotfile_top = array_values( array_unique( $dotfile_top ) );
		$this->assertSame(
			array(),
			$dotfile_top,
			'Hidden top-level entries in ZIP: ' . implode( ', ', $dotfile_top )
		);
	}

	public function test_every_shipped_php_css_js_file_is_non_empty(): void {
		$empty = array();
		$zip   = new ZipArchive();
		if ( true !== $zip->open( self::$zip_path ) ) {
			$this->fail( 'Could not reopen ZIP.' );
		}
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$stat = $zip->statIndex( $i );
			if ( ! is_array( $stat ) || empty( $stat['name'] ) ) {
				continue;
			}
			$name = (string) $stat['name'];
			$ext  = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
			if ( ! in_array( $ext, array( 'php', 'css', 'js' ), true ) ) {
				continue;
			}
			// Empty files (<= 0 bytes) are an immediate red flag.
			// The strip pipeline can in theory produce a 0-byte
			// output if the source was 100% comments.
			if ( (int) $stat['size'] <= 0 ) {
				$empty[] = $name . ' (' . (int) $stat['size'] . ' bytes)';
			}
		}
		$zip->close();
		$this->assertSame(
			array(),
			$empty,
			'Empty PHP/CSS/JS files in ZIP: ' . implode( ', ', $empty )
		);
	}

	public function test_staging_directory_has_no_forbidden_top_level_entries(): void {
		$entries = scandir( self::$staging_dir );
		$bad     = array();
		foreach ( (array) $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			if ( in_array( $entry, self::FORBIDDEN_TOP_LEVEL, true ) ) {
				$bad[] = $entry;
			}
		}
		$this->assertSame(
			array(),
			$bad,
			'Forbidden top-level entries in dist/sscribe-export-site-pages/: ' . implode( ', ', $bad )
		);
	}

	public function test_zip_contains_the_main_plugin_file_at_known_path(): void {
		// The archive root is dist/sscribe-export-site-pages-<ver>.zip
		// and the first path component must be the plugin slug.
		$expected = self::PLUGIN_SLUG . '/sscribe-export-site-pages.php';
		$this->assertContains(
			$expected,
			self::$zip_entries,
			'Main plugin file not at expected path: ' . $expected
		);
	}

	public function test_zip_lists_a_non_empty_set_of_files(): void {
		// Sanity: the ZIP must actually contain SOMETHING. A build
		// that produces a 0-entry ZIP is a release-blocker.
		$this->assertGreaterThan(
			50,
			count( self::$zip_entries ),
			'ZIP contains suspiciously few files (<= 50). Build likely regressed.'
		);
	}
}