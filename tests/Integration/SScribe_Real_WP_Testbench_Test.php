<?php
/**
 * Real WordPress testbench contract.
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class SScribe_Real_WP_Testbench_Test extends TestCase {

	private const INSTALL_SCRIPT   = 'bin/install-wp-tests.sh';
	private const UNINSTALL_SCRIPT = 'bin/uninstall-wp-tests.sh';
	private const PHPUNIT_CONFIG   = 'phpunit-wp.xml';
	private const WP_TESTS_CONFIG  = 'tests-wp/wp-tests-config.php';
	private const WP_BOOTSTRAP     = 'tests-wp/bootstrap.php';
	private const WP_CORE_DIR      = 'tests-wp/_wordpress';

	private static function plugin_root(): string {
		return dirname(__DIR__, 2);
	}

	public function test_install_script_is_present_and_executable(): void {
		$path = self::plugin_root() . '/' . self::INSTALL_SCRIPT;
		$this->assertFileExists($path);
		$first_line = (string) (strtok((string) file_get_contents($path), "\n") ?: '');
		$this->assertStringStartsWith('#!/usr/bin/env bash', $first_line);
	}

	public function test_uninstall_script_is_present(): void {
		$this->assertFileExists(self::plugin_root() . '/' . self::UNINSTALL_SCRIPT);
	}

	public function test_phpunit_wp_xml_exists_and_points_at_wp_bootstrap(): void {
		$path = self::plugin_root() . '/' . self::PHPUNIT_CONFIG;
		$this->assertFileExists($path);
		$contents = (string) file_get_contents($path);
		$this->assertStringContainsString('tests-wp/bootstrap.php', $contents);
		$this->assertMatchesRegularExpression('/<env[^>]*name="WP_TESTS_DIR"/', $contents);
		$this->assertMatchesRegularExpression('/<env[^>]*name="WP_CORE_DIR"/', $contents);
	}

	public function test_wp_tests_config_uses_sqlite_drop_in(): void {
		$path = self::plugin_root() . '/' . self::WP_TESTS_CONFIG;
		$this->assertFileExists($path);
		$contents = strtolower((string) file_get_contents($path));
		$this->assertStringContainsString('sqlite', $contents);
		$this->assertStringContainsString('db.php', $contents);
	}

	public function test_wp_bootstrap_loads_plugin_and_references_wp_testcase(): void {
		$path = self::plugin_root() . '/' . self::WP_BOOTSTRAP;
		$this->assertFileExists($path);
		$contents = (string) file_get_contents($path);
		$this->assertStringContainsString('wp-tests-config', $contents);
		$this->assertStringContainsString('sscribe-export-site-pages.php', $contents);
	}

	public function test_sscribe_wp_testcase_extends_wp_unit_testcase(): void {
		$path = self::plugin_root() . '/tests-wp/WordPress/SScribe_WP_TestCase.php';
		$this->assertFileExists($path);
		$contents = (string) file_get_contents($path);
		$this->assertMatchesRegularExpression(
			'/class\s+SScribe_WP_TestCase\s+extends\s+WP_UnitTestCase/',
			$contents
		);
	}

	public function test_install_script_provisions_sqlite_dropin(): void {
		$path = self::plugin_root() . '/' . self::INSTALL_SCRIPT;
		$contents = (string) file_get_contents($path);

		$this->assertStringContainsString('sqlite-database-integration', $contents);
		$this->assertStringContainsString('WordPress/sqlite-database-integration/releases/latest', $contents);
		$this->assertStringContainsString("-name 'db.copy'", $contents);
		$this->assertStringContainsString('wp-content/db.php', $contents);
		$this->assertStringContainsString(
			'cp "${SQLITE_DIR}/db.copy" "${WP_CORE_DIR}/wp-content/db.php"',
			$contents,
			'The installer must provision the generated SQLite drop-in; generated tests-wp/_wordpress contents must not be required in a clean checkout.'
		);
	}

	public function test_composer_test_wp_command_is_wired(): void {
		$composer = (string) file_get_contents(self::plugin_root() . '/composer.json');
		$this->assertStringContainsString('test:wp', $composer);
		$this->assertStringContainsString('phpunit-wp.xml', $composer);
	}

	public function test_real_wp_suite_runs_clean_against_live_bench(): void {
		$core_dir = self::plugin_root() . '/' . self::WP_CORE_DIR;
		if (! is_dir($core_dir) || ! is_file($core_dir . '/wp-load.php')) {
			$this->markTestSkipped(
				'Real WP bench is not installed at tests-wp/_wordpress. The dedicated real-WP CI job provisions it before running the suite.'
			);
		}

		$descriptors = array(
			0 => array('pipe', 'r'),
			1 => array('pipe', 'w'),
			2 => array('pipe', 'w'),
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
		$this->assertIsResource($process, 'phpunit must launch against the real WP bench');
		$stdout = (string) stream_get_contents($pipes[1]);
		$stderr = (string) stream_get_contents($pipes[2]);
		$code   = proc_close($process);

		$this->assertSame(
			0,
			$code,
			"Real WP testbench must pass a clean run (excluding the two pre-existing risky tests). Output:\n" . $stdout . $stderr
		);
	}
}
