<?php
/**
 * SScribe WP/PHP Minimum Versions Integration Test
 *
 * @package SScribe_Export_Site_Pages
 *
 * Phase 25: locks the WP/PHP minimum version contract.
 *
 * The plugin declares its minimum supported versions in three places
 * that MUST stay synchronized:
 *
 *   1. sscribe-export-site-pages.php plugin header
 *        - `* Requires at least: X.Y`
 *        - `* Requires PHP:      X.Y`
 *
 *   2. readme.txt header
 *        - `Requires at least: X.Y`
 *        - `Requires PHP: X.Y`
 *
 *   3. The runtime version_compare() guard inside the plugin bootstrap:
 *        `version_compare( PHP_VERSION, 'X.Y', '<' )`
 *
 * Drift between these surfaces is a release blocker — see
 * scripts/verify-min-versions.php for the failure modes.
 *
 * This integration test runs that script against a series of
 * synthetic mainfile+readme.txt pairs and asserts the verifier's
 * verdict for each one. A regression that:
 *
 *   - silently accepts a missing Requires at least header,
 *   - silently accepts a missing Requires PHP header,
 *   - silently accepts a drift between mainfile and readme.txt,
 *   - silently accepts a runtime guard that disagrees with the header,
 *   - silently accepts a PHP minimum below the canonical 8.2 floor,
 *
 * ...fails the suite immediately.
 */

declare( strict_types=1 );

namespace SScribe\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class SScribe_Minimum_Versions_Test extends TestCase {

	private const SCRIPT_PATH = 'scripts/verify-min-versions.php';
	private const MAINFILE    = 'sscribe-export-site-pages.php';
	private const README      = 'readme.txt';

	private static function plugin_root(): string {
		return dirname( __DIR__, 2 );
	}

	/**
	 * Pair of synthetic (mainfile, readme) contents.
	 *
	 * @return array{0:string,1:string}
	 */
	private function run_against( string $mainfile, string $readme ): array {
		$mainfile_path = self::plugin_root() . '/' . self::MAINFILE;
		$readme_path   = self::plugin_root() . '/' . self::README;
		$backup_main   = file_get_contents( $mainfile_path );
		$backup_readme = file_get_contents( $readme_path );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $mainfile_path, $mainfile );
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
			file_put_contents( $mainfile_path, $backup_main );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $readme_path, $backup_readme );
		}

		return array( (int) $code, $stdout . $stderr );
	}

	private function well_formed_mainfile(): string {
		return "<?php\n/**\n"
			. " * Plugin Name:       SScribe Export Site Pages\n"
			. " * Description:       Test fixture.\n"
			. " * Version:           2.0.0\n"
			. " * Requires at least: 6.0\n"
			. " * Requires PHP:      8.2\n"
			. " * Author:            Simplix Innovations\n"
			. " * License:           GPL-2.0-or-later\n"
			. " */\n"
			. "declare(strict_types=1);\n"
			. "if ( ! defined( 'ABSPATH' ) ) { exit; }\n"
			. "if ( ! defined( 'SSCRIBE_VERSION' ) ) { define( 'SSCRIBE_VERSION', '2.0.0' ); }\n"
			. "if ( version_compare( PHP_VERSION, '8.2', '<' ) ) {\n"
			. "    add_action( 'admin_notices', function () {\n"
			. "        echo '<div class=\"notice notice-error\"><p>SScribe requires PHP 8.2+.</p></div>';\n"
			. "    } );\n"
			. "}\n";
	}

	private function well_formed_readme( string $wp_min = '6.0', string $php_min = '8.2' ): string {
		return "=== Plugin Name ===\n"
			. "Contributors: simplix\n"
			. "Requires at least: {$wp_min}\n"
			. "Tested up to: 7.1\n"
			. "Requires PHP: {$php_min}\n"
			. "Stable tag: 2.0.0\n"
			. "License: GPL-2.0-or-later\n";
	}

	public function test_well_formed_pair_passes(): void {
		list( $code, $output ) = $this->run_against(
			$this->well_formed_mainfile(),
			$this->well_formed_readme()
		);
		$this->assertSame(
			0,
			$code,
			'Well-formed mainfile + readme.txt must pass. Output: ' . $output
		);
		$this->assertStringContainsString( 'WP/PHP minimum contract holds', $output );
	}

	public function test_missing_wp_min_in_mainfile_fails(): void {
		$mainfile = str_replace( ' * Requires at least: 6.0', '', $this->well_formed_mainfile() );
		list( $code, $output ) = $this->run_against( $mainfile, $this->well_formed_readme() );
		$this->assertSame( 1, $code );
		$this->assertStringContainsString( 'Requires at least', $output );
	}

	public function test_missing_php_min_in_mainfile_fails(): void {
		$mainfile = str_replace( " * Requires PHP:      8.2\n", '', $this->well_formed_mainfile() );
		list( $code, $output ) = $this->run_against( $mainfile, $this->well_formed_readme() );
		$this->assertSame( 1, $code );
		$this->assertStringContainsString( 'Requires PHP', $output );
	}

	public function test_drift_wp_min_fails(): void {
		list( $code, $output ) = $this->run_against(
			$this->well_formed_mainfile(),
			$this->well_formed_readme( '6.5', '8.2' )
		);
		$this->assertSame( 1, $code );
		$this->assertStringContainsString( 'WordPress minimum drift', $output );
	}

	public function test_drift_php_min_fails(): void {
		list( $code, $output ) = $this->run_against(
			$this->well_formed_mainfile(),
			$this->well_formed_readme( '6.0', '8.3' )
		);
		$this->assertSame( 1, $code );
		$this->assertStringContainsString( 'PHP minimum drift', $output );
	}

	public function test_runtime_guard_does_not_match_header_fails(): void {
		// Header says PHP 8.2 but the runtime guard checks 8.1.
		$mainfile = str_replace(
			"version_compare( PHP_VERSION, '8.2', '<' )",
			"version_compare( PHP_VERSION, '8.1', '<' )",
			$this->well_formed_mainfile()
		);
		list( $code, $output ) = $this->run_against( $mainfile, $this->well_formed_readme() );
		$this->assertSame( 1, $code );
		$this->assertStringContainsString( 'runtime guard does not match', $output );
	}

	public function test_php_min_below_floor_fails(): void {
		// Header says PHP 8.1 (below 8.2 floor).
		$mainfile = str_replace(
			array( " * Requires PHP:      8.2\n", "version_compare( PHP_VERSION, '8.2', '<' )" ),
			array( " * Requires PHP:      8.1\n", "version_compare( PHP_VERSION, '8.1', '<' )" ),
			$this->well_formed_mainfile()
		);
		list( $code, $output ) = $this->run_against( $mainfile, $this->well_formed_readme( '6.0', '8.1' ) );
		$this->assertSame( 1, $code );
		$this->assertStringContainsString( 'below the canonical floor', $output );
	}

	public function test_live_repo_passes(): void {
		// Sanity: the script is wired against the real mainfile +
		// readme.txt. If either regresses, this test fires immediately.
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
			"Live mainfile + readme.txt must satisfy the WP/PHP minimum contract. Output:\n" . $stdout . $stderr
		);
	}
}
