<?php
/**
 * SScribe License SPDX Integration Test
 *
 * @package SScribe_Export_Site_Pages
 *
 * Phase 23: locks the "single license identity" contract for the
 * plugin's license metadata.
 *
 *   - The plugin mainfile `* License:` header MUST use the canonical
 *     SPDX identifier 'GPL-2.0-or-later' (NOT 'GPLv2' or 'GPL-2.0').
 *   - The composer.json `license` field MUST use the same SPDX
 *     identifier.
 *   - The readme.txt `License:` line MUST use the same SPDX
 *     identifier and reference the canonical gpl-2.0.html URI.
 *   - The shipped ZIP MUST contain a license.txt file (the full
 *     GPL v2 text) at the plugin root.
 *
 * A drift between any of these and the canonical SPDX is a release
 * blocker. WP.org plugin-check rejects non-SPDX identifiers and
 * requires the license.txt file at the plugin root.
 *
 * This test reads the locations from disk (no PHP-side caching) so a
 * regression that updates one but not the others fails the suite
 * immediately.
 */

declare(strict_types=1);

namespace SScribe\Tests\Integration;

use PHPUnit\Framework\TestCase;

class SScribe_License_SPDIX_Test extends TestCase {

	private const PLUGIN_FILE  = 'sscribe-export-site-pages.php';
	private const README_FILE  = 'readme.txt';
	private const PACKAGE_FILE = 'composer.json';
	private const LICENSE_FILE = 'license.txt';

	/** Canonical SPDX identifier used in every location. */
	private const SPDX = 'GPL-2.0-or-later';

	/** Canonical license URI used in plugin header + readme. */
	private const LICENSE_URI = 'https://www.gnu.org/licenses/gpl-2.0.html';

	private static function plugin_root(): string {
		return dirname( __DIR__, 2 );
	}

	public function test_mainfile_uses_canonical_spdx_identifier(): void {
		$contents = (string) file_get_contents( self::plugin_root() . '/' . self::PLUGIN_FILE );
		$this->assertNotFalse( $contents );

		$this->assertMatchesRegularExpression(
			'/\*\s*License:\s+' . preg_quote( self::SPDX, '/' ) . '\s*\n/',
			$contents,
			'plugin mainfile must declare License: GPL-2.0-or-later (SPDX)'
		);
		$this->assertMatchesRegularExpression(
			'/\*\s*License URI:\s+' . preg_quote( self::LICENSE_URI, '/' ) . '/',
			$contents,
			'plugin mainfile must reference the canonical gpl-2.0.html URI'
		);
	}

	public function test_composer_json_uses_canonical_spdx_identifier(): void {
		$decoded = json_decode( (string) file_get_contents( self::plugin_root() . '/' . self::PACKAGE_FILE ), true );
		$this->assertIsArray( $decoded, 'composer.json must decode as JSON object' );
		$this->assertArrayHasKey( 'license', $decoded, 'composer.json must declare a license field' );
		$this->assertSame(
			self::SPDX,
			$decoded['license'],
			'composer.json license must be the canonical SPDX identifier GPL-2.0-or-later'
		);
	}

	public function test_readme_uses_canonical_spdx_identifier(): void {
		$contents = (string) file_get_contents( self::plugin_root() . '/' . self::README_FILE );
		$this->assertNotFalse( $contents );

		$this->assertMatchesRegularExpression(
			'/^License:\s+' . preg_quote( self::SPDX, '/' ) . '/m',
			$contents,
			'readme.txt must declare License: GPL-2.0-or-later (SPDX)'
		);
		$this->assertMatchesRegularExpression(
			'/^License URI:\s+' . preg_quote( self::LICENSE_URI, '/' ) . '/m',
			$contents,
			'readme.txt must reference the canonical gpl-2.0.html URI'
		);
	}

	public function test_all_spdx_locations_agree(): void {
		$mainfile = (string) file_get_contents( self::plugin_root() . '/' . self::PLUGIN_FILE );
		$readme   = (string) file_get_contents( self::plugin_root() . '/' . self::README_FILE );
		$composer = json_decode( (string) file_get_contents( self::plugin_root() . '/' . self::PACKAGE_FILE ), true );

		preg_match( '/\*\s*License:\s*([A-Za-z0-9.\-+]+)/', $mainfile, $main_match );
		preg_match( '/^License:\s*([A-Za-z0-9.\-+]+)/m', $readme, $readme_match );

		$this->assertSame( self::SPDX, $main_match[1] ?? null, 'mainfile SPDX must match canonical' );
		$this->assertSame( self::SPDX, $readme_match[1] ?? null, 'readme SPDX must match canonical' );
		$this->assertSame( self::SPDX, $composer['license'] ?? null, 'composer SPDX must match canonical' );
		$this->assertSame( $main_match[1], $readme_match[1], 'mainfile and readme SPDX must agree' );
		$this->assertSame( $main_match[1], $composer['license'] ?? null, 'mainfile and composer SPDX must agree' );
	}

	public function test_shipped_license_txt_contains_gpl_v2_text(): void {
		$license_path = self::plugin_root() . '/' . self::LICENSE_FILE;
		$this->assertFileExists( $license_path, 'license.txt must exist at the plugin root' );

		$contents = (string) file_get_contents( $license_path );
		$this->assertNotFalse( $contents );
		$this->assertStringContainsString( 'GNU GENERAL PUBLIC LICENSE', $contents );
		$this->assertStringContainsString( 'Version 2', $contents );
		$this->assertGreaterThan(
			1500,
			strlen( $contents ),
			'license.txt must contain the full GPL v2 text (not a stub)'
		);
	}

	public function test_runtime_dependency_license_set_has_no_gpl2_only_v3_only_conflict(): void {
		$lock_path = self::plugin_root() . '/composer.lock';
		$this->assertFileExists( $lock_path );

		$lock = json_decode( (string) file_get_contents( $lock_path ), true );
		$this->assertIsArray( $lock );

		$licenses = array();
		foreach ( (array) ( $lock['packages'] ?? array() ) as $package ) {
			$name = isset( $package['name'] ) ? (string) $package['name'] : '';
			foreach ( (array) ( $package['license'] ?? array() ) as $license ) {
				$licenses[ $name ][] = (string) $license;
			}
		}

		$gpl2_only = array();
		$v3_only   = array();
		foreach ( $licenses as $name => $package_licenses ) {
			if ( in_array( 'GPL-2.0-only', $package_licenses, true ) ) {
				$gpl2_only[] = $name;
			}
			if (
				in_array( 'GPL-3.0-only', $package_licenses, true )
				|| in_array( 'GPL-3.0-or-later', $package_licenses, true )
				|| in_array( 'LGPL-3.0-only', $package_licenses, true )
				|| in_array( 'LGPL-3.0-or-later', $package_licenses, true )
			) {
				$v3_only[] = $name;
			}
		}

		$this->assertFalse(
			! empty( $gpl2_only ) && ! empty( $v3_only ),
			'Runtime dependency graph mixes GPL-2.0-only packages (' . implode( ', ', $gpl2_only )
				. ') with GPL/LGPL v3-only packages (' . implode( ', ', $v3_only )
				. '). GNU license compatibility rules do not provide one compatible license for that combined runtime.'
		);
	}

	public function test_license_header_does_not_use_non_spdx_aliases(): void {
		$contents = (string) file_get_contents( self::plugin_root() . '/' . self::PLUGIN_FILE );

		// Common legacy aliases that WP.org plugin-check sometimes rejects.
		// Anchored to "License:" so substrings inside GPL-2.0-or-later do not
		// trigger false positives (the literal string GPL-2.0 is a substring
		// of the canonical SPDX).
		$this->assertDoesNotMatchRegularExpression(
			'/\*\s*License:\s+GPLv2\b/',
			$contents,
			'mainfile must not use legacy GPLv2 alias; use SPDX GPL-2.0-or-later'
		);
		$this->assertDoesNotMatchRegularExpression(
			'/\*\s*License:\s+GPL-2\.0\s/',
			$contents,
			'mainfile must not use strict GPL-2.0; the "-or-later" suffix is required'
		);
		$this->assertDoesNotMatchRegularExpression(
			'/\*\s*License:\s+GPL2\b/',
			$contents,
			'mainfile must not use GPL2 alias; use SPDX GPL-2.0-or-later'
		);
	}

	public function test_license_txt_matches_locked_runtime_dependency_inventory(): void {
		$root    = self::plugin_root();
		$license = (string) file_get_contents( $root . '/' . self::LICENSE_FILE );
		$lock    = json_decode( (string) file_get_contents( $root . '/composer.lock' ), true );

		$this->assertIsArray( $lock );
		foreach ( (array) ( $lock['packages'] ?? array() ) as $package ) {
			$name = (string) ( $package['name'] ?? '' );
			$this->assertNotSame( '', $name );
			$this->assertStringContainsString(
				$name,
				$license,
				'license.txt must identify every production Composer package that can ship in vendor-prefixed/.'
			);
		}

		foreach ( array( 'mPDF', 'setasign/fpdi', 'mpdf/psr-http-message-shim', 'mpdf/psr-log-aware-trait', 'assets/fonts/amiri' ) as $removed ) {
			$this->assertStringNotContainsString(
				$removed,
				$license,
				'license.txt must not claim removed runtime dependencies/assets are still bundled.'
			);
		}
	}

}