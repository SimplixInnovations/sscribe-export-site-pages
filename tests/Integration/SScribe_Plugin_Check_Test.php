<?php
/**
 * SScribe Plugin Check CI Integration Test
 *
 * @package SScribe_Export_Site_Pages
 *
 * Phase 33: locks the official WordPress Plugin Check CI contract.
 *
 * Plugin Check is the official WP.org submission-time audit. The
 * CI integration in `.github/workflows/ci.yml` runs it on every
 * PR that targets main / develop, so a regression in Plugin Check
 * configuration silently ships a build that the WP.org review
 * team will reject on submission.
 *
 * This integration test runs scripts/verify-plugin-check.php
 * against the live repo and against a series of synthetic
 * mutations: drop the plugin-check job, downgrade strict to false,
 * drop include-experimental, swap the action for an unofficial one,
 * drop the dependency on test/frontend-quality/audit/real-wp-tests,
 * drop the build-dir reference, drop the build-release step. A
 * regression that:
 *
 *   - silently accepts a missing plugin-check job,
 *   - silently accepts strict: false,
 *   - silently accepts an unofficial action in place of
 *     wordpress/plugin-check-action@10857da14b6c2246d15402b3e69f777edcf8c12e,
 *   - silently accepts a plugin-check job that depends on nothing,
 *   - silently accepts a build-dir that doesn't match the dist
 *     output,
 *   - silently accepts a plugin-check job that skips
 *     scripts/build-release.php,
 *   - silently allows local release-audit.sh to SKIP Plugin Check,
 *
 * ...fails the suite immediately.
 */

declare( strict_types=1 );

namespace SScribe\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class SScribe_Plugin_Check_Test extends TestCase {

	private const SCRIPT_PATH = 'scripts/verify-plugin-check.php';

	private static function plugin_root(): string {
		return dirname( __DIR__, 2 );
	}

	/**
	 * @return array{0:int,1:string}
	 */
	private function run_against( ?string $ci_payload ): array {
		$ci_path = self::plugin_root() . '/.github/workflows/ci.yml';
		$backup  = file_get_contents( $ci_path );
		if ( null !== $ci_payload ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $ci_path, $ci_payload );
		}

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
			$this::assertIsResource( $process );
			$stdout = (string) stream_get_contents( $pipes[1] );
			$stderr = (string) stream_get_contents( $pipes[2] );
			$code   = proc_close( $process );
			return array( (int) $code, $stdout . $stderr );
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $ci_path, $backup );
		}
	}

	private function well_formed_ci(): string {
		$lines = array(
			'name: CI',
			'on:',
			'  push:',
			'    branches:',
			'      - main',
			'jobs:',
			'    test:',
			'        runs-on: ubuntu-latest',
			'        steps:',
			'          - run: echo test',
			'    frontend-quality:',
			'        runs-on: ubuntu-latest',
			'        steps:',
			'          - run: echo fe',
			'    audit:',
			'        runs-on: ubuntu-latest',
			'        steps:',
			'          - run: echo audit',
			'    real-wp-tests:',
			'        runs-on: ubuntu-latest',
			'        steps:',
			'          - run: echo real-wp',
			'    plugin-check:',
			'        name: Submission Package Check',
			'        runs-on: ubuntu-latest',
			'        needs: [test, frontend-quality, audit, real-wp-tests]',
			'        steps:',
			'          - name: Checkout',
			'            uses: actions/checkout@d23441a48e516b6c34aea4fa41551a30e30af803',
			'          - name: Install',
			'            run: composer install --no-progress',
			'          - name: Vendor prefix',
			'            run: composer vendor:prefix',
			'          - name: Build',
			'            run: php scripts/build-release.php',
			'          - name: Plugin Check',
			'            uses: wordpress/plugin-check-action@10857da14b6c2246d15402b3e69f777edcf8c12e',
			'            with:',
			'              build-dir: ./dist/sscribe-export-site-pages',
			'              strict: true',
			'              include-experimental: true',
		);
		return implode( "\n", $lines ) . "\n";
	}

	public function test_well_formed_passes(): void {
		list( $code, $output ) = $this->run_against( $this->well_formed_ci() );
		$this::assertSame(
			0,
			$code,
			'Well-formed CI must pass. Output:' . "\n" . $output
		);
		$this::assertStringContainsString( 'Plugin Check CI contract holds', $output );
	}

	public function test_missing_plugin_check_job_fails(): void {
		$ci = $this->well_formed_ci();
		// Remove the plugin-check job block (last job in the well-formed fixture).
		$ci = preg_replace(
			"/^    plugin-check:[\s\S]*?(?=^    [a-z][a-z0-9-]*:\s*$|\Z)/m",
			'',
			$ci,
			1,
			$count
		);
		$this::assertGreaterThan( 0, $count, 'preg_replace should have removed the plugin-check job block.' );
		list( $code, $output ) = $this->run_against( $ci );
		$this::assertSame( 1, $code );
		$this::assertStringContainsString( 'plugin-check:', $output );
	}

	public function test_strict_false_fails(): void {
		$ci = preg_replace( '/strict:\s*true/', 'strict: false', $this->well_formed_ci(), 1, $count );
		$this::assertSame( 1, $count );
		list( $code, $output ) = $this->run_against( $ci );
		$this::assertSame( 1, $code );
		$this::assertStringContainsString( 'strict', $output );
	}

	public function test_include_experimental_false_fails(): void {
		$ci = preg_replace( '/include-experimental:\s*true/', 'include-experimental: false', $this->well_formed_ci(), 1, $count );
		$this::assertSame( 1, $count );
		list( $code, $output ) = $this->run_against( $ci );
		$this::assertSame( 1, $code );
		$this::assertStringContainsString( 'include-experimental', $output );
	}

	public function test_unofficial_action_fails(): void {
		$ci = preg_replace( '/wordpress\/plugin-check-action@[0-9a-f]{40}/', 'third-party/plugin-check@latest', $this->well_formed_ci(), 1, $count );
		$this::assertSame( 1, $count );
		list( $code, $output ) = $this->run_against( $ci );
		$this::assertSame( 1, $code );
		$this::assertStringContainsString( 'wordpress/plugin-check-action@<40-char SHA>', $output );
	}

	public function test_missing_real_wp_tests_dependency_fails(): void {
		$ci = preg_replace( '/, real-wp-tests/', '', $this->well_formed_ci(), 1, $count );
		$this::assertSame( 1, $count );
		list( $code, $output ) = $this->run_against( $ci );
		$this::assertSame( 1, $code );
		$this::assertStringContainsString( 'real-wp-tests', $output );
	}

	public function test_wrong_build_dir_fails(): void {
		$ci = preg_replace( '/build-dir:\s*\.?\/?dist\/sscribe-export-site-pages/', 'build-dir: ./build', $this->well_formed_ci(), 1, $count );
		$this::assertSame( 1, $count );
		list( $code, $output ) = $this->run_against( $ci );
		$this::assertSame( 1, $code );
		$this::assertStringContainsString( 'build-dir', $output );
	}

	public function test_missing_build_release_step_fails(): void {
		$ci = preg_replace( '/- name: Build\s*\n\s*run: php scripts\/build-release\.php\s*\n/', '', $this->well_formed_ci(), 1, $count );
		$this::assertSame( 1, $count );
		list( $code, $output ) = $this->run_against( $ci );
		$this::assertSame( 1, $code );
		$this::assertStringContainsString( 'build-release.php', $output );
	}

	public function test_local_release_audit_fails_closed_when_plugin_check_testbench_is_missing(): void {
		$source = (string) file_get_contents( self::plugin_root() . '/scripts/release-audit.php' );
		$this::assertStringNotContainsString( 'SKIP plugin-check testbench missing', $source );
		$this::assertStringContainsString( "'Plugin-Check'", $source );
		$this::assertStringContainsString( 'release audit is fail-closed', $source );

		$wrapper = (string) file_get_contents( self::plugin_root() . '/bin/release-audit.sh' );
		$this::assertStringContainsString( 'scripts/release-audit.php', $wrapper );
	}

	public function test_release_audit_bootstraps_plugin_check_runtime_checks(): void {
		$source    = (string) file_get_contents( self::plugin_root() . '/scripts/release-audit.php' );
		$bootstrap = (string) @file_get_contents( self::plugin_root() . '/scripts/plugin-check-cli-bootstrap.php' );

		$this::assertStringContainsString(
			"'--require=' . " . '$plugin_check_bootstrap',
			$source,
			'Release audit must load the SScribe-owned Plugin Check bootstrap before WordPress starts.'
		);
		$this::assertStringNotContainsString(
			"'--require=' . " . '$plugin_check_cli',
			$source,
			'Release audit must not require Plugin Check cli.php directly because the CLI can reach PHPCS checks before its directory constant is defined.'
		);
		$this::assertStringContainsString( "define( 'WP_PLUGIN_CHECK_PLUGIN_DIR_PATH'", $bootstrap );
		$this::assertStringContainsString(
			'require ' . '$plugin_check_cli' . ';',
			$bootstrap,
			'The compatibility bootstrap must delegate to the official Plugin Check CLI entry point after defining the missing runtime constant.'
		);
		$this::assertStringContainsString( '$wp_bin,', $source );
		$this::assertStringContainsString( "'--path=' . " . '$wp_root', $source );
		$this::assertStringContainsString( "'plugin',", $source );
		$this::assertStringContainsString(
			"'check',",
			$source,
			'Release audit must still invoke the official wp plugin check command.'
		);
	}

	public function test_live_repo_passes(): void {
		list( $code, $output ) = $this->run_against( null );
		$this::assertSame(
			0,
			$code,
			'Live repo must satisfy the Plugin Check CI contract. Output:' . "\n" . $output
		);
	}
}
