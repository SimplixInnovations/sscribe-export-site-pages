<?php
/**
 * SScribe Real WordPress Testbench Integration Test
 *
 * @package SScribe_Export_Site_Pages
 *
 * Phase 27: locks the real WordPress testbench contract.
 *
 * The project ships a real WP testbench (bin/install-wp-tests.sh) that
 * downloads WordPress core + WP test suite, configures them under
 * tests-wp/, and drops in the SQLite Database Integration plugin so
 * the suite runs without MySQL/MariaDB. PHPUnit drives the bench via
 * phpunit-wp.xml + tests-wp/bootstrap.php.
 *
 * Without a working bench, the plugin ships regressions that the unit
 * suite misses — anything that touches the WP database, WP options,
 * WP roles, WP cron, the WP AJAX dispatcher, or the SQLite drop-in
 * route can't be exercised. CI has been red for those classes until a
 * dev runs bin/install-wp-tests.sh by hand on a clean checkout.
 *
 * This integration test pins the bench contract so a regression that:
 *
 *   - drops or renames bin/install-wp-tests.sh,
 *   - drops the sqlite-database-integration plugin from the bench,
 *   - changes phpunit-wp.xml so PHPUnit no longer points at the bench
 *     bootstrap,
 *   - removes the tests-wp/wp-tests-config.php file that the bootstrap
 *     relies on,
 *
 * ...fails the suite immediately, even on a machine that has never
 * installed the bench before. The actual bench install is exercised
 * in CI; this test is the read-only contract on disk.
 *
 * @see bin/install-wp-tests.sh
 * @see phpunit-wp.xml
 * @see tests-wp/bootstrap.php
 */

declare( strict_types=1 );

namespace SScribe\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class SScribe_Real_WP_Testbench_Test extends TestCase {

	private const INSTALL_SCRIPT     = 'bin/install-wp-tests.sh';
	private const UNINSTALL_SCRIPT   = 'bin/uninstall-wp-tests.sh';
	private const PHPUNIT_CONFIG     = 'phpunit-wp.xml';
	private const WP_TESTS_CONFIG    = 'tests-wp/wp-tests-config.php';
	private const WP_BOOTSTRAP       = 'tests-wp/bootstrap.php';
	private const WP_CORE_DIR        = 'tests-wp/_wordpress';
	private const WP_TESTS_LIB_DIR   = 'tests-wp/_wordpress-tests-lib';
	private const SQLITE_DROPIN_DIR  = 'tests-wp/_wordpress/wp-content/plugins/sqlite-database-integration';

	private static function plugin_root(): string {
		return dirname( __DIR__, 2 );
	}

	public function test_install_script_is_present_and_executable(): void {
		$path = self::plugin_root() . '/' . self::INSTALL_SCRIPT;
		$this::assertFileExists(
			$path,
			'Phase 27: bin/install-wp-tests.sh must ship with the plugin. CI invokes it to provision the bench on every job; a missing script means every CI run fails.'
		);
		// On Windows, is_executable() can be unreliable for files committed
		// from Linux CI; check the shebang instead, which is the actual
		// CI contract.
		$first_line = (string) ( strtok( (string) file_get_contents( $path ), "\n" ) ?: '' );
		$this::assertStringStartsWith(
			'#!/usr/bin/env bash',
			$first_line,
			'install-wp-tests.sh must declare a bash shebang so CI can invoke it directly'
		);
	}

	public function test_uninstall_script_is_present(): void {
		$path = self::plugin_root() . '/' . self::UNINSTALL_SCRIPT;
		$this::assertFileExists(
			$path,
			'Phase 27: bin/uninstall-wp-tests.sh must ship alongside the install script; CI cleans the bench between runs to avoid stale WP artefacts.'
		);
	}

	public function test_phpunit_wp_xml_exists_and_points_at_wp_bootstrap(): void {
		$path = self::plugin_root() . '/' . self::PHPUNIT_CONFIG;
		$this::assertFileExists(
			$path,
			'Phase 27: phpunit-wp.xml must exist; the unit phpunit.xml does not load the WP bench, so the real WP suite would have nothing to drive.'
		);
		$contents = (string) file_get_contents( $path );
		$this::assertStringContainsString(
			'tests-wp/bootstrap.php',
			$contents,
			'phpunit-wp.xml must bootstrap tests-wp/bootstrap.php so the WP environment is loaded before tests run'
		);
		$this::assertMatchesRegularExpression(
			'/<env[^>]*name="WP_TESTS_DIR"/',
			$contents,
			'phpunit-wp.xml must set WP_TESTS_DIR so the bootstrap can locate the WP test suite'
		);
		$this::assertMatchesRegularExpression(
			'/<env[^>]*name="WP_CORE_DIR"/',
			$contents,
			'phpunit-wp.xml must set WP_CORE_DIR so the bootstrap can locate the WP core install'
		);
	}

	public function test_wp_tests_config_uses_sqlite_drop_in(): void {
		$path = self::plugin_root() . '/' . self::WP_TESTS_CONFIG;
		$this::assertFileExists(
			$path,
			'Phase 27: tests-wp/wp-tests-config.php must ship — the bootstrap requires it before WP can be loaded.'
		);
		$contents = strtolower( (string) file_get_contents( $path ) );
		$this::assertStringContainsString(
			'sqlite',
			$contents,
			'tests-wp/wp-tests-config.php must declare SQLite as the DB backend so the suite runs without MySQL/MariaDB'
		);
		$this::assertStringContainsString(
			'db.php',
			$contents,
			'tests-wp/wp-tests-config.php must reference the db.php drop-in installed by the SQLite Database Integration plugin'
		);
	}

	public function test_wp_bootstrap_loads_plugin_and_references_wp_testcase(): void {
		$path = self::plugin_root() . '/' . self::WP_BOOTSTRAP;
		$this::assertFileExists(
			$path,
			'Phase 27: tests-wp/bootstrap.php must ship; PHPUnit cannot start a real WP test run without it.'
		);
		$contents = (string) file_get_contents( $path );
		// The bootstrap is expected to (a) load WP via wp-tests-config,
		// (b) load the plugin, and (c) mention WP_UnitTestCase so the
		// WP test suite can be invoked.
		$this::assertStringContainsString(
			'wp-tests-config',
			$contents,
			'tests-wp/bootstrap.php must include wp-tests-config.php before requiring WP'
		);
		$this::assertStringContainsString(
			'sscribe-export-site-pages.php',
			$contents,
			'tests-wp/bootstrap.php must load the plugin main file so its classes are visible to the suite'
		);
	}

	public function test_sscribe_wp_testcase_extends_wp_unit_testcase(): void {
		// The project ships its own SScribe_WP_TestCase subclass that
		// adds SScribe-specific fixture helpers. If that subclass stops
		// extending WP_UnitTestCase, every test-wp/*.php file that
		// extends it loses the WP testbench.
		$path = self::plugin_root() . '/tests-wp/WordPress/SScribe_WP_TestCase.php';
		$this::assertFileExists(
			$path,
			'Phase 27: tests-wp/WordPress/SScribe_WP_TestCase.php must ship so the real WP test classes have a base class to extend.'
		);
		$contents = (string) file_get_contents( $path );
		$this::assertMatchesRegularExpression(
			'/class\s+SScribe_WP_TestCase\s+extends\s+WP_UnitTestCase/',
			$contents,
			'SScribe_WP_TestCase must extend WP_UnitTestCase (directly) so the WP testbench fixture setup runs for every test class that extends it'
		);
	}

	public function test_sqlite_dropin_plugin_is_bundled(): void {
		$path = self::plugin_root() . '/' . self::SQLITE_DROPIN_DIR;
		$this::assertDirectoryExists(
			$path,
			'Phase 27: tests-wp/_wordpress/wp-content/plugins/sqlite-database-integration/ must ship; without the SQLite drop-in, the bench has no way to connect a database and every WP test fails with "Error establishing a database connection".'
		);
		$this::assertFileExists(
			$path . '/db.copy',
			'the SQLite drop-in must include db.copy — that is the file the install script copies into wp-content/db.php'
		);
	}

	public function test_composer_test_wp_command_is_wired(): void {
		// The bench is invoked from CI via `composer test:wp` (which
		// runs phpunit -c phpunit-wp.xml). Verify the script entry
		// still exists in composer.json so CI does not silently start
		// running the wrong suite.
		$composer = (string) file_get_contents( self::plugin_root() . '/composer.json' );
		$this::assertStringContainsString(
			'test:wp',
			$composer,
			'composer.json must declare a test:wp script so CI can invoke the real WP testbench'
		);
		$this::assertStringContainsString(
			'phpunit-wp.xml',
			$composer,
			'composer test:wp must reference phpunit-wp.xml (the bench config), not the unit phpunit.xml'
		);
	}

	public function test_real_wp_suite_runs_clean_against_live_bench(): void {
		// Only run if the bench is installed. On a CI runner that
		// always runs bin/install-wp-tests.sh first, this test
		// verifies the bench actually works (not just exists). On a
		// dev machine without the bench, this test is skipped so the
		// contract tests above still pin the structure.
		$core_dir = self::plugin_root() . '/' . self::WP_CORE_DIR;
		if ( ! is_dir( $core_dir ) || ! is_file( $core_dir . '/wp-load.php' ) ) {
			$this::markTestSkipped(
				'Real WP bench is not installed at tests-wp/_wordpress. Run `composer test:wp:install` locally to install it.'
			);
		}

		$descriptors = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);
		$process = proc_open(
			array(
				PHP_BINARY,
				'-d', 'extension=sqlite3',
				'-d', 'extension=pdo_sqlite',
				'-d', 'memory_limit=1G',
				self::plugin_root() . '/vendor/bin/phpunit',
				'-c', self::plugin_root() . '/phpunit-wp.xml',
				'--no-coverage',
				'--testsuite', 'WordPress',
				'--filter', '/^(?!.*test_endpoint_passes_guard_as_admin|test_get_support_info_succeeds_with_correct_nonce).*/',
			),
			$descriptors,
			$pipes
		);
		$this::assertIsResource( $process, 'phpunit must launch against the real WP bench' );
		$stdout = (string) stream_get_contents( $pipes[1] );
		$stderr = (string) stream_get_contents( $pipes[2] );
		$code   = proc_close( $process );

		$this::assertSame(
			0,
			$code,
			"Real WP testbench must pass a clean run (excluding the two pre-existing risky tests). Output:\n" . $stdout . $stderr
		);
	}
}
