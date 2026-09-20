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
			self::DIST_TREE . '/vendor-prefixed/tecnickcom/tcpdf/LICENSE.TXT',
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

	public function test_missing_tcpdf_license_fails(): void {
		$paths = array(
			self::DIST_TREE . '/vendor-prefixed/tecnickcom/tcpdf/LICENSE.TXT',
			self::INVENTORY_PATH,
		);
		list( $code, $output ) = $this->run_with_state( $paths, array(), array(
			self::DIST_TREE . '/vendor-prefixed/tecnickcom/tcpdf/LICENSE.TXT',
		) );
		$this::assertSame( 1, $code, 'Missing TCPDF LICENSE.TXT must fail. Output:' . "\n" . $output );
		$this::assertStringContainsString( 'tecnickcom/tcpdf', $output );
		$this::assertStringContainsString( 'no license file shipped', $output );
	}

	public function test_unknown_license_fails(): void {
		// Replace TCPDF's LGPL-3.0-or-later LICENSE.TXT with a proprietary notice.
		// The verifier's SPDX heuristic should not classify this, and
		// the resulting `unknown` value must fail the contract.
		$proprietary = "PROPRIETARY LICENSE\nAll rights reserved.\nNo redistribution permitted.\n";
		$paths = array(
			self::DIST_TREE . '/vendor-prefixed/tecnickcom/tcpdf/LICENSE.TXT',
			self::INVENTORY_PATH,
		);
		list( $code, $output ) = $this->run_with_state(
			$paths,
			array( self::DIST_TREE . '/vendor-prefixed/tecnickcom/tcpdf/LICENSE.TXT' => $proprietary )
		);
		$this::assertSame( 1, $code, 'Proprietary license must fail. Output:' . "\n" . $output );
		$this::assertStringContainsString( 'tecnickcom/tcpdf', $output );
		$this::assertStringContainsString( 'SPDX identifier could not be determined', $output );
	}

	public function test_recognized_but_wrong_license_fails(): void {
		$mit = "MIT License\n\nPermission is hereby granted, free of charge, to any person obtaining a copy\n";
		$paths = array(
			self::DIST_TREE . '/vendor-prefixed/tecnickcom/tcpdf/LICENSE.TXT',
			self::INVENTORY_PATH,
		);
		list( $code, $output ) = $this->run_with_state(
			$paths,
			array( self::DIST_TREE . '/vendor-prefixed/tecnickcom/tcpdf/LICENSE.TXT' => $mit )
		);
		$this::assertSame( 1, $code, 'A recognizable but incorrect license notice must fail. Output:' . "\n" . $output );
		$this::assertStringContainsString( 'does not match composer.lock', $output );
	}

	public function test_legacy_amiri_bundle_is_not_part_of_source_tree(): void {
		$root = self::plugin_root();
		foreach (
			array(
				'assets/fonts/amiri/Amiri-Regular.ttf',
				'assets/fonts/amiri/Amiri-Bold.ttf',
				'assets/fonts/amiri/OFL.txt',
				'assets/fonts/amiri/index.php',
				'assets/fonts/index.php',
			) as $relative
		) {
			$this::assertFileDoesNotExist( $root . '/' . $relative, $relative . ' is obsolete after the TCPDF migration.' );
		}
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
		// Sanity: TCPDF is in the inventory with LGPL-3.0-or-later.
		$tcpdf = null;
		foreach ( $decoded['packages'] as $pkg ) {
			if ( isset( $pkg['name'] ) && 'tecnickcom/tcpdf' === $pkg['name'] ) {
				$tcpdf = $pkg;
				break;
			}
		}
		$this::assertNotNull( $tcpdf, 'tecnickcom/tcpdf must appear in the inventory' );
		$this::assertSame( 'LGPL-3.0-or-later', $tcpdf['license'] );
		$this::assertSame( 'https://github.com/tecnickcom/TCPDF', $tcpdf['source_url'] );
		$this::assertSame( 'yes (Strauss namespace/class prefix)', $tcpdf['modified'] );
		$this::assertNotEmpty( $tcpdf['runtime_purpose'] );
	}
}
