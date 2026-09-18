<?php
/**
 * Phase 56 — Real-WordPress matrix CI integration test.
 *
 * A single (PHP 8.4, latest-WP, SQLite) real-WP run is a smoke
 * test. The contract this test pins is a real MATRIX: PHP 8.2 +
 * 8.3 + 8.4 × WP latest + previous, plus the declared WP 6.1 /
 * PHP 8.2 floor, fail-fast off, per-leg log
 * artifacts, with the install script honoring the version arg.
 *
 * A regression that drops 8.2 from the matrix, removes per-leg
 * logs, or breaks the installer's WP_VERSION arg fails here
 * before it reaches the WP.org release gate.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

namespace SScribe\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class SScribe_Real_WP_Matrix_Test extends TestCase {

	private const VERIFIER_PATH = 'scripts/verify-real-wp-matrix.php';
	private const MANIFEST_PATH = 'dist/real-wp-matrix-manifest.json';
	private const CI_PATH       = '.github/workflows/ci.yml';
	private const INSTALLER     = 'scripts/install-wp-tests.php';
	private const DOC_PATH      = 'docs/BRANCH_PROTECTION_v2.0.0.md';

	private static function plugin_root(): string {
		return dirname( __DIR__, 2 );
	}

	private function run_verifier(): array {
		$root        = self::plugin_root();
		$descriptors = array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) );
		$process     = proc_open( array( PHP_BINARY, $root . '/' . self::VERIFIER_PATH ), $descriptors, $pipes );
		if ( ! is_resource( $process ) ) {
			throw new \RuntimeException( 'Could not spawn verifier subprocess.' );
		}
		$stdout = (string) stream_get_contents( $pipes[1] );
		$stderr = (string) stream_get_contents( $pipes[2] );
		$code   = proc_close( $process );
		return array( (int) $code, $stdout . $stderr );
	}

	public function test_live_ci_passes_real_wp_matrix_verifier(): void {
		list( $code, $output ) = $this->run_verifier();
		$this::assertSame(
			0,
			$code,
			'Live .github/workflows/ci.yml must satisfy the Phase 56 real-WP matrix contract. Output:' . "\n" . $output
		);
		$this::assertStringContainsString( 'Real-WP matrix contract valid', $output );
	}

	public function test_manifest_records_twelve_or_more_passing_rules(): void {
		list( $code ) = $this->run_verifier();
		$this::assertSame( 0, $code );

		$payload = json_decode( (string) file_get_contents( self::plugin_root() . '/' . self::MANIFEST_PATH ), true );
		$this::assertIsArray( $payload );
		$this::assertTrue( $payload['passes'] );
		$this::assertGreaterThanOrEqual( 12, $payload['rule_count'] );
		$this::assertSame( $payload['rule_count'], $payload['passed_count'] );
		$this::assertSame( 0, $payload['errors_count'] );
		$this::assertNotEmpty( $payload['matrix'] );
		$this::assertNotEmpty( $payload['php_versions_in_matrix'] );
		$this::assertNotEmpty( $payload['wp_versions_in_matrix'] );
	}

	public function test_ci_declares_strategy_matrix_on_real_wp_tests(): void {
		$source = (string) file_get_contents( self::plugin_root() . '/' . self::CI_PATH );
		// Find real-wp-tests job slice.
		$this::assertMatchesRegularExpression(
			'/real-wp-tests:[\s\S]{0,500}?strategy:[\s\S]{0,200}?matrix:\s*\n/m',
			$source,
			'real-wp-tests job must declare `strategy.matrix` block.'
		);
	}

	public function test_matrix_includes_php_82_floor(): void {
		$source = (string) file_get_contents( self::plugin_root() . '/' . self::CI_PATH );
		// PHP 8.2 is the plugin's minimum per composer.json require.php >= 8.2.
		$this::assertStringContainsString( '8.2', $source );
		$this::assertMatchesRegularExpression(
			'/php-version:\s*\[[^\]]*8\.2[^\]]*\]/m',
			$source,
			'Real-WP matrix php-version list must include 8.2 (the plugin floor).'
		);
	}

	public function test_matrix_includes_declared_wp61_php82_floor_leg(): void {
		$source = (string) file_get_contents( self::plugin_root() . '/' . self::CI_PATH );
		$this::assertMatchesRegularExpression(
			"/include:[\\s\\S]{0,800}?php-version:\\s*['\"]8\\.2['\"][\\s\\S]{0,160}?wp-version:\\s*['\"]6\\.1['\"]/",
			$source,
			'Real-WP matrix must explicitly exercise the declared WordPress 6.1 / PHP 8.2 minimum pair.'
		);
	}

	public function test_matrix_includes_at_least_two_wp_versions(): void {
		$source = (string) file_get_contents( self::plugin_root() . '/' . self::CI_PATH );
		$this::assertMatchesRegularExpression(
			'/wp-version:\s*\[[^\]]+,[^\]]+\]/m',
			$source,
			'Real-WP matrix wp-version list must include at least two versions.'
		);
	}

	public function test_matrix_disables_fail_fast(): void {
		$source = (string) file_get_contents( self::plugin_root() . '/' . self::CI_PATH );
		$this::assertMatchesRegularExpression(
			'/real-wp-tests:[\s\S]*?fail-fast:\s*false/m',
			$source,
			'real-wp-tests matrix MUST set `fail-fast: false` so all legs complete.'
		);
	}

	public function test_matrix_uploads_per_leg_logs_with_always_or_failure(): void {
		$source = (string) file_get_contents( self::plugin_root() . '/' . self::CI_PATH );
		// Both an upload-artifact step AND an if: always() / if: failure()
		// must be present in the real-wp-tests slice.
		$pos = strpos( $source, 'real-wp-tests:' );
		$this::assertNotFalse( $pos );
		$slice = substr( $source, $pos, 4000 );
		if ( preg_match( '/\n    [a-z][a-z0-9_-]*:\s*\n/', $slice, $boundary, PREG_OFFSET_CAPTURE ) ) {
			$slice = substr( $slice, 0, $boundary[0][1] );
		}
		$this::assertStringContainsString( 'actions/upload-artifact', $slice );
		$this::assertMatchesRegularExpression(
			'/if:\s*(?:always\(\)|failure\(\))/',
			$slice,
			'real-wp-tests matrix must gate at least one step with `if: always()` or `if: failure()` so per-leg logs upload even on failure.'
		);
	}

	public function test_installer_honors_wp_version_variable(): void {
		$source = (string) file_get_contents( self::plugin_root() . '/' . self::INSTALLER );
		$this::assertStringContainsString( 'WP_VERSION', $source );
		$this::assertStringContainsString( '--version', $source );
	}

	public function test_installer_supports_sqlite_dropin(): void {
		$source = (string) file_get_contents( self::plugin_root() . '/' . self::INSTALLER );
		$this::assertStringContainsString( '--sqlite', $source );
	}

	public function test_branch_protection_doc_lists_real_wp_matrix_label(): void {
		$doc_src = (string) file_get_contents( self::plugin_root() . '/' . self::DOC_PATH );
		$this::assertStringContainsString( 'Real WordPress Integration Suite', $doc_src );
		$this::assertStringContainsString( 'real-wp-tests', $doc_src );
	}
}
