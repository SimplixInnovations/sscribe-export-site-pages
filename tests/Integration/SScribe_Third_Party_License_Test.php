<?php
/**
 * Phase 37 — third-party license inventory integration test.
 *
 * The verifier (scripts/verify-third-party-license.php) walks the
 * shipped dist tree and confirms every bundled dependency ships its
 * own LICENSE / COPYING / NOTICE with a GPL-2.0-or-later compatible
 * SPDX identifier. This PHPUnit class backs every rule with a
 * regression test: each test mutates one well-formed payload and
 * confirms the verifier catches it.
 *
 * Test isolation pattern: every test snapshots the affected
 * file(s) (including the inventory JSON the verifier writes to
 * dist/), runs the verifier via proc_open, then restores the
 * snapshot in a `finally` block. The tests never modify the live
 * repo on disk after they finish.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

namespace SScribe\Tests\Integration;

use PHPUnit\Framework\TestCase;

#[\PHPUnit\Framework\Attributes\Group('release-contract')]
final class SScribe_Third_Party_License_Test extends TestCase {

	private const SCRIPT_PATH    = 'scripts/verify-third-party-license.php';
	private const DIST_TREE      = 'dist/sscribe-export-site-pages';
	private const INVENTORY_PATH = 'dist/third-party-license-inventory.json';

	private static function plugin_root(): string {
		return dirname( __DIR__, 2 );
	}

	/**
	 * Snapshot every file under `$paths`, run the verifier, then
	 * restore the snapshot in `finally`. `$path_overrides` lets the
	 * caller rewrite one or more files for the duration of the run.
	 *
	 * @param string[]              $paths
	 * @param array<string,string>  $path_overrides  rel-path => new contents
	 * @param string[]              $paths_to_remove  rel-paths to delete for the duration of the run
	 * @return array{0:int,1:string}
	 */
	private function run_with_state( array $paths, array $path_overrides = array(), array $paths_to_remove = array() ): array {
		$root = self::plugin_root();

		$snapshot = array();
		foreach ( $paths as $path ) {
			$abs = $root . '/' . $path;
			if ( is_file( $abs ) ) {
				$snapshot[ $path ] = (string) file_get_contents( $abs );
			} else {
				$snapshot[ $path ] = '__MISSING__';
			}
		}

		try {
			foreach ( $path_overrides as $path => $contents ) {
				$abs = $root . '/' . $path;
				$dir = dirname( $abs );
				if ( ! is_dir( $dir ) ) {
					mkdir( $dir, 0755, true );
				}
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				file_put_contents( $abs, $contents );
			}
			foreach ( $paths_to_remove as $path ) {
				$abs = $root . '/' . $path;
				if ( is_file( $abs ) ) {
					unlink( $abs );
				}
			}

			$descriptors = array(
				0 => array( 'pipe', 'r' ),
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			);
			$process = proc_open(
				array( PHP_BINARY, $root . '/' . self::SCRIPT_PATH ),
				$descriptors,
				$pipes
			);
			$this::assertIsResource( $process );
			$stdout = (string) stream_get_contents( $pipes[1] );
			$stderr = (string) stream_get_contents( $pipes[2] );
			$code   = proc_close( $process );
			return array( (int) $code, $stdout . $stderr );
		} finally {
			foreach ( $snapshot as $path => $contents ) {
				$abs = $root . '/' . $path;
				if ( '__MISSING__' === $contents ) {
					if ( is_file( $abs ) ) {
						@unlink( $abs );
					}
					continue;
				}
				$dir = dirname( $abs );
				if ( ! is_dir( $dir ) ) {
					mkdir( $dir, 0755, true );
				}
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				file_put_contents( $abs, $contents );
			}
		}
	}

	public function test_well_formed_passes(): void {
		$paths = array(
			self::DIST_TREE . '/vendor-prefixed/mpdf/mpdf/LICENSE.txt',
			self::INVENTORY_PATH,
		);
		list( $code, $output ) = $this->run_with_state( $paths );
		$this::assertSame(
			0,
			$code,
			'Well-formed dist tree must pass. Output:' . "\n" . $output
		);
		$this::assertStringContainsString( 'license inventory holds', $output );
	}

	public function test_missing_mpdf_license_fails(): void {
		$paths = array(
			self::DIST_TREE . '/vendor-prefixed/mpdf/mpdf/LICENSE.txt',
			self::INVENTORY_PATH,
		);
		list( $code, $output ) = $this->run_with_state( $paths, array(), array(
			self::DIST_TREE . '/vendor-prefixed/mpdf/mpdf/LICENSE.txt',
		) );
		$this::assertSame( 1, $code, 'Missing mpdf LICENSE.txt must fail. Output:' . "\n" . $output );
		$this::assertStringContainsString( 'mpdf/mpdf', $output );
		$this::assertStringContainsString( 'no license file shipped', $output );
	}

	public function test_unknown_license_fails(): void {
		// Replace mpdf's GPL-2.0 LICENSE.txt with a proprietary notice.
		// The verifier's SPDX heuristic should not classify this, and
		// the resulting `unknown` value must fail the contract.
		$proprietary = "PROPRIETARY LICENSE\nAll rights reserved.\nNo redistribution permitted.\n";
		$paths = array(
			self::DIST_TREE . '/vendor-prefixed/mpdf/mpdf/LICENSE.txt',
			self::INVENTORY_PATH,
		);
		list( $code, $output ) = $this->run_with_state(
			$paths,
			array( self::DIST_TREE . '/vendor-prefixed/mpdf/mpdf/LICENSE.txt' => $proprietary )
		);
		$this::assertSame( 1, $code, 'Proprietary license must fail. Output:' . "\n" . $output );
		$this::assertStringContainsString( 'mpdf/mpdf', $output );
		$this::assertStringContainsString( 'SPDX identifier could not be determined', $output );
	}

	public function test_amiri_font_missing_license_fails(): void {
		$paths = array(
			self::DIST_TREE . '/assets/fonts/amiri/OFL.txt',
			self::INVENTORY_PATH,
		);
		list( $code, $output ) = $this->run_with_state( $paths, array(), array(
			self::DIST_TREE . '/assets/fonts/amiri/OFL.txt',
		) );
		$this::assertSame( 1, $code, 'Missing Amiri OFL.txt must fail. Output:' . "\n" . $output );
		$this::assertStringContainsString( 'amiri', $output );
		$this::assertStringContainsString( 'no license file', $output );
	}

	public function test_inventory_json_persisted(): void {
		$paths = array( self::INVENTORY_PATH );
		list( $code ) = $this->run_with_state( $paths );
		$this::assertSame( 0, $code );
		$abs = self::plugin_root() . '/' . self::INVENTORY_PATH;
		$this::assertFileExists( $abs, 'Inventory JSON must be persisted to dist/' );
		$decoded = json_decode( (string) file_get_contents( $abs ), true );
		$this::assertIsArray( $decoded );
		$this::assertSame( 'GPL-2.0-or-later', $decoded['plugin_license'] );
		$this::assertNotEmpty( $decoded['packages'] );
		// Sanity: mpdf is in the inventory with GPL-2.0-only (compatible).
		$mpdf = null;
		foreach ( $decoded['packages'] as $pkg ) {
			if ( isset( $pkg['name'] ) && 'mpdf/mpdf' === $pkg['name'] ) {
				$mpdf = $pkg;
				break;
			}
		}
		$this::assertNotNull( $mpdf, 'mpdf/mpdf must appear in the inventory' );
		$this::assertSame( 'GPL-2.0-only', $mpdf['license'] );
		$this::assertSame( 'https://github.com/mpdf/mpdf', $mpdf['source_url'] );
		$this::assertSame( 'yes (Strauss namespace prefix)', $mpdf['modified'] );
		$this::assertNotEmpty( $mpdf['runtime_purpose'] );
	}
}
