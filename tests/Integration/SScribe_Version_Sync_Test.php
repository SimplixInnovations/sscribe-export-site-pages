<?php
/**
 * SScribe Version Sync Integration Test
 *
 * @package SScribe_Export_Site_Pages
 *
 * Phase 16 + Phase 51: locks the "single source of truth" contract
 * for the plugin's version metadata.
 *
 *   - The PHP constant SSCRIBE_VERSION is the SOLE canonical source.
 *   - The WordPress plugin header (`* Version: ...`), the readme.txt
 *     `Stable tag:` line, and the package.json `version` field MUST
 *     all match SSCRIBE_VERSION verbatim.
 *   - A drift between any of these and the constant is a release
 *     blocker (would ship a broken version on WP.org).
 *   - Phase 51 extends the contract to release scripts: scripts/
 *     build-release.php must derive the version dynamically via
 *     `get_version(string, string): string`, NOT a hardcoded literal.
 *
 * This test reads the sources from disk (no PHP-side caching) so a
 * regression that updates one but not the others fails the suite
 * immediately.
 */

declare(strict_types=1);

namespace SScribe\Tests\Integration;

use PHPUnit\Framework\TestCase;

class SScribe_Version_Sync_Test extends TestCase {

	private const PLUGIN_FILE   = 'sscribe-export-site-pages.php';
	private const README_FILE   = 'readme.txt';
	private const PACKAGE_FILE  = 'package.json';

	/**
	 * Locate the plugin root (one directory above tests/).
	 */
	private static function plugin_root(): string {
		return dirname( __DIR__, 2 );
	}

	public function test_constant_definition_is_present_and_well_formed(): void {
		$plugin_file = self::plugin_root() . '/' . self::PLUGIN_FILE;
		$this->assertFileExists( $plugin_file, 'plugin file must exist' );

		$contents = file_get_contents( $plugin_file );
		$this->assertNotFalse( $contents, 'plugin file must be readable' );

		// SSCRIBE_VERSION constant must be defined as a string literal.
		$this->assertMatchesRegularExpression(
			"/define\s*\(\s*['\"]SSCRIBE_VERSION['\"]\s*,\s*['\"]([0-9]+\.[0-9]+\.[0-9]+)['\"]/",
			$contents,
			'SSCRIBE_VERSION must be defined as a quoted X.Y.Z literal'
		);
	}

	public function test_plugin_header_version_matches_constant(): void {
		$plugin_file = self::plugin_root() . '/' . self::PLUGIN_FILE;
		$contents    = file_get_contents( $plugin_file );

		preg_match( "/define\s*\(\s*['\"]SSCRIBE_VERSION['\"]\s*,\s*['\"]([0-9]+\.[0-9]+\.[0-9]+)['\"]/", $contents, $constant_match );
		$canonical = $constant_match[1];

		preg_match( '/\*\s*Version:\s*([0-9]+\.[0-9]+\.[0-9]+)/', $contents, $header_match );
		$this->assertNotEmpty( $header_match, 'plugin header must declare a Version:' );
		$this->assertSame( $canonical, $header_match[1], 'plugin header Version: must match SSCRIBE_VERSION' );
	}

	public function test_readme_stable_tag_matches_constant(): void {
		$readme = self::plugin_root() . '/' . self::README_FILE;
		$this->assertFileExists( $readme, 'readme.txt must exist' );

		$contents = file_get_contents( $readme );
		$this->assertNotFalse( $contents );

		preg_match( '/Stable tag:\s*([0-9]+\.[0-9]+\.[0-9]+)/', $contents, $tag_match );
		$this->assertNotEmpty( $tag_match, 'readme.txt must declare a Stable tag:' );

		$canonical = self::load_canonical_version();
		$this->assertSame( $canonical, $tag_match[1], 'readme.txt Stable tag must match SSCRIBE_VERSION' );
	}

	public function test_package_json_version_matches_constant(): void {
		$package = self::plugin_root() . '/' . self::PACKAGE_FILE;
		$this->assertFileExists( $package, 'package.json must exist' );

		$decoded = json_decode( (string) file_get_contents( $package ), true );
		$this->assertIsArray( $decoded, 'package.json must decode as JSON object' );
		$this->assertArrayHasKey( 'version', $decoded, 'package.json must declare a "version" field' );

		$canonical = self::load_canonical_version();
		$this->assertSame( $canonical, $decoded['version'], 'package.json version must match SSCRIBE_VERSION' );
	}

	public function test_canonical_version_is_semver(): void {
		$canonical = self::load_canonical_version();
		$this->assertMatchesRegularExpression(
			'/^[0-9]+\.[0-9]+\.[0-9]+$/',
			$canonical,
			'SSCRIBE_VERSION must be a strict X.Y.Z semver string'
		);
	}

	/**
	 * Phase 51 — release scripts must derive version dynamically from
	 * SSCRIBE_VERSION (via the plugin header) so a botched release
	 * never ships a stale ZIP tag.
	 */
	public function test_release_script_defines_dynamic_get_version(): void {
		$build_source = (string) file_get_contents( self::plugin_root() . '/scripts/build-release.php' );
		$this->assertNotFalse( $build_source, 'scripts/build-release.php must be readable' );
		$this->assertMatchesRegularExpression(
			'#function\s+get_version\s*\(\s*string\s+\$root\s*,\s*string\s+\$plugin_file\s*\)\s*:\s*string\s*\{#',
			$build_source,
			'build-release.php must define `function get_version(string $root, string $plugin_file): string`'
		);
	}

	/**
	 * Phase 51 — the get_version() function must read the version via a
	 * `Version:` preg_match, not a hardcoded literal. A regression that
	 * returns a literal string pins the script to one version forever.
	 */
	public function test_get_version_reads_from_plugin_header_regex(): void {
		$build_source = (string) file_get_contents( self::plugin_root() . '/scripts/build-release.php' );
		$this->assertMatchesRegularExpression(
			'#preg_match\s*\(\s*[\'"][^\'"]*Version:[^\'"]*[\'"]#',
			$build_source,
			'build-release.php get_version() must read the version via a `Version:` preg_match'
		);
	}

	/**
	 * Phase 51 — the ZIP filename must use a `$version` variable, not a
	 * hardcoded literal. A copy-paste regression that hardcodes `2.0.0`
	 * into the filename while SSCRIBE_VERSION is `2.0.1` ships a
	 * malformed tag.
	 */
	public function test_release_zip_filename_uses_version_variable(): void {
		$build_source = (string) file_get_contents( self::plugin_root() . '/scripts/build-release.php' );
		$this->assertMatchesRegularExpression(
			'#sscribe-export-site-pages-\{\s*\$version\s*\}\.zip#',
			$build_source,
			'ZIP filename must be `sscribe-export-site-pages-{$version}.zip` (variable, not literal)'
		);
	}

	/**
	 * Read the SSCRIBE_VERSION literal directly from the plugin file.
	 * Uses file_get_contents (not PHP-side constant cache) so the
	 * test always sees the on-disk source-of-truth.
	 */
	private static function load_canonical_version(): string {
		$plugin_file = self::plugin_root() . '/' . self::PLUGIN_FILE;
		$contents    = file_get_contents( $plugin_file );
		if ( false === $contents ) {
			throw new \RuntimeException( 'plugin file not readable: ' . $plugin_file );
		}

		preg_match( "/define\s*\(\s*['\"]SSCRIBE_VERSION['\"]\s*,\s*['\"]([0-9]+\.[0-9]+\.[0-9]+)['\"]/", $contents, $matches );
		if ( empty( $matches ) ) {
			throw new \RuntimeException( 'SSCRIBE_VERSION constant not found in ' . self::PLUGIN_FILE );
		}

		return $matches[1];
	}
}
