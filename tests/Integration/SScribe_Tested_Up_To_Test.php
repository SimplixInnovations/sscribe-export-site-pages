<?php
/**
 * SScribe "Tested up to" Integration Test
 *
 * @package SScribe_Export_Site_Pages
 *
 * Phase 26: locks the WP.org "Tested up to" header contract.
 *
 * The `Tested up to:` header in readme.txt tells WP.org operators the
 * latest WordPress version the plugin has been tested on. A stale or
 * malformed value is rejected by Plugin Check at submission and
 * auto-flagged as untested by WP.org.
 *
 * This integration test runs scripts/verify-tested-up-to.php against
 * a series of synthetic readme.txt payloads and asserts the script's
 * verdict for each one. A regression that:
 *
 *   - silently accepts a missing `Tested up to:` header,
 *   - silently accepts a version below the canonical WordPress
 *     minimum the plugin itself declares,
 *   - silently accepts a version above the freshness ceiling (which
 *     is almost certainly a typo),
 *
 * ...fails the suite immediately.
 */

declare( strict_types=1 );

namespace SScribe\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class SScribe_Tested_Up_To_Test extends TestCase {

	private const SCRIPT_PATH = 'scripts/verify-tested-up-to.php';

	private static function plugin_root(): string {
		return dirname( __DIR__, 2 );
	}

	/**
	 * @return array{0:int,1:string}
	 */
	private function run_against( string $readme ): array {
		$readme_path   = self::plugin_root() . '/readme.txt';
		$backup        = file_get_contents( $readme_path );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $readme_path, $readme );

		try {
			$descriptors = array(
				0 => array( 'pipe', 'r' ),
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			);
			$process = proc_open(
				array( PHP_BINARY, self::plugin_root() . '/' . self::SCRIPT_PATH ),
				$descriptors,
				$pipes
			);
			$this->assertIsResource( $process );
			$stdout = (string) stream_get_contents( $pipes[1] );
			$stderr = (string) stream_get_contents( $pipes[2] );
			$code   = proc_close( $process );
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $readme_path, $backup );
		}

		return array( (int) $code, $stdout . $stderr );
	}

	private function well_formed_readme( string $tested = '7.1' ): string {
		return "=== Plugin Name ===\n"
			. "Contributors: simplix\n"
			. "Requires at least: 6.0\n"
			. "Tested up to: {$tested}\n"
			. "Requires PHP: 8.2\n"
			. "Stable tag: 2.0.0\n"
			. "License: GPL-2.0-or-later\n";
	}

	public function test_well_formed_readme_passes(): void {
		list( $code, $output ) = $this->run_against( $this->well_formed_readme() );
		$this->assertSame(
			0,
			$code,
			'Well-formed readme.txt must pass. Output: ' . $output
		);
		$this->assertStringContainsString( 'Tested-up-to contract holds', $output );
	}

	public function test_missing_tested_up_to_fails(): void {
		$readme = $this->well_formed_readme();
		$readme = preg_replace( '/^Tested up to:.*\n/m', '', $readme );
		list( $code, $output ) = $this->run_against( $readme );
		$this->assertSame( 1, $code, 'missing `Tested up to:` header must fail' );
		$this->assertStringContainsString( 'missing the `Tested up to', $output );
	}

	public function test_below_canonical_min_fails(): void {
		list( $code, $output ) = $this->run_against( $this->well_formed_readme( '5.9' ) );
		$this->assertSame( 1, $code, 'Tested up to 5.9 must fail (below WP 6.0 floor)' );
		$this->assertStringContainsString( 'below the canonical WordPress minimum', $output );
	}

	public function test_above_ceiling_fails(): void {
		list( $code, $output ) = $this->run_against( $this->well_formed_readme( '99.0' ) );
		$this->assertSame( 1, $code, 'Tested up to 99.0 must fail (above freshness ceiling)' );
		$this->assertStringContainsString( 'above the canonical freshness ceiling', $output );
	}

	public function test_at_canonical_floor_passes(): void {
		list( $code, $output ) = $this->run_against( $this->well_formed_readme( '6.0' ) );
		$this->assertSame(
			0,
			$code,
			'Tested up to 6.0 (canonical WP minimum) must pass. Output: ' . $output
		);
	}

	public function test_at_canonical_ceiling_passes(): void {
		list( $code, $output ) = $this->run_against( $this->well_formed_readme( '8.0' ) );
		$this->assertSame(
			0,
			$code,
			'Tested up to 8.0 (canonical freshness ceiling) must pass. Output: ' . $output
		);
	}

	public function test_live_readme_passes(): void {
		// Sanity check: the script is wired against the real readme.txt
		// on disk. If the live header regresses, this test fires
		// immediately.
		$script = self::plugin_root() . '/' . self::SCRIPT_PATH;
		$descriptors = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);
		$process = proc_open( array( PHP_BINARY, $script ), $descriptors, $pipes );
		$this->assertIsResource( $process );
		$stdout = (string) stream_get_contents( $pipes[1] );
		$stderr = (string) stream_get_contents( $pipes[2] );
		$code   = proc_close( $process );
		$this->assertSame(
			0,
			$code,
			"Live readme.txt must satisfy the tested-up-to contract. Output:\n" . $stdout . $stderr
		);
	}
}
