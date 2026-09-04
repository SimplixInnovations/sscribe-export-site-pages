<?php
/**
 * Phase 63 — Exact package clean install integration test.
 *
 * Pins the contract at the PHPUnit boundary so a regression that
 * silently breaks the WP.org install path fails locally before it
 * reaches a reviewer.
 *
 * The contract has three layers:
 *
 *   1. STRUCTURAL — the ZIP exists, matches SSCRIBE_VERSION, opens
 *      cleanly, has a Plugin Name-bearing mainfile, contains the
 *      activator + uninstall + autoloader.
 *   2. BEHAVIORAL — the ZIP activator carries the canonical 3 cron
 *      hooks + 2 capability names + delegates to the 3 table
 *      suffixes (export_logs, export_stats, audit_log).
 *   3. DOCUMENTARY — the smoke doc at docs/WP_ORG_CLEAN_INSTALL_SMOKE.md
 *      references the canonical ZIP filename and declares the
 *      canonical invariants table.
 *
 * The full end-to-end "extract + install + activate + smoke-probe"
 * lives in the live WordPress testbench under the SScribe_Clean_Install_Test
 * (Phase 30 evidence). This integration test pins the source-level
 * contract so a regression that drops a table name, mis-gates the
 * uninstall, or removes the smoke doc fails the gate before the
 * testbench boots.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

namespace SScribe\Tests\Integration;

use PHPUnit\Framework\TestCase;

#[\PHPUnit\Framework\Attributes\Group('release-contract')]
final class SScribe_Exact_Package_Clean_Install_Test extends TestCase {

	private const VERIFIER_PATH = 'scripts/verify-exact-package-clean-install.php';
	private const MANIFEST_PATH = 'dist/exact-package-clean-install-manifest.json';
	private const MAINFILE_PATH = 'sscribe-export-site-pages.php';
	private const SMOKE_DOC     = 'docs/WP_ORG_CLEAN_INSTALL_SMOKE.md';

	private static function plugin_root(): string {
		return dirname( __DIR__, 2 );
	}

	private function run_verifier(): array {
		$root        = self::plugin_root();
		$descriptors = array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) );
		$process     = proc_open( array( PHP_BINARY, $root . '/' . self::VERIFIER_PATH ), $descriptors, $pipes );
		if ( ! \is_resource( $process ) ) {
			throw new \RuntimeException( 'Could not spawn verifier subprocess.' );
		}
		$stdout = (string) stream_get_contents( $pipes[1] );
		$stderr = (string) stream_get_contents( $pipes[2] );
		$code   = proc_close( $process );
		return array( (int) $code, $stdout . $stderr );
	}

	private static function read_canonical_version(): string {
		$src = (string) file_get_contents( self::plugin_root() . '/' . self::MAINFILE_PATH );
		if ( ! preg_match( "/define\\s*\\(\\s*['\"]SSCRIBE_VERSION['\"]\\s*,\\s*['\"]([^'\"]+)['\"]/", $src, $m ) ) {
			throw new \RuntimeException( 'Mainfile must define SSCRIBE_VERSION.' );
		}
		return $m[1];
	}

	public function test_live_manifest_passes_verifier(): void {
		list( $code, $output ) = $this->run_verifier();
		$this::assertSame(
			0,
			$code,
			'Phase 63 verifier must return exit 0. Output:' . "\n" . $output
		);
		$this::assertStringContainsString( 'Exact-package clean-install contract valid', $output );
	}

	public function test_manifest_records_all_rules_passing(): void {
		list( $code ) = $this->run_verifier();
		$this::assertSame( 0, $code );

		$payload = json_decode( (string) file_get_contents( self::plugin_root() . '/' . self::MANIFEST_PATH ), true );
		$this::assertIsArray( $payload );
		$this::assertTrue( $payload['passes'] );
		$this::assertGreaterThanOrEqual( 11, $payload['rule_count'] );
		$this::assertSame( $payload['rule_count'], $payload['passed_count'] );
		$this::assertSame( 0, $payload['errors_count'] );
		$this::assertSame( self::read_canonical_version(), $payload['version'] );
	}

	public function test_exact_zip_exists_for_canonical_version(): void {
		$version = self::read_canonical_version();
		$this::assertFileExists(
			self::plugin_root() . '/dist/sscribe-export-site-pages-' . $version . '.zip',
			'Exact ZIP at dist/sscribe-export-site-pages-' . $version . '.zip must exist.'
		);
	}

	public function test_mainfile_version_header_matches_constant(): void {
		$src = (string) file_get_contents( self::plugin_root() . '/' . self::MAINFILE_PATH );
		$version = self::read_canonical_version();
		$this::assertMatchesRegularExpression(
			'/Version:\s*' . preg_quote( $version, '/' ) . '/',
			$src,
			'Mainfile Version: header must equal SSCRIBE_VERSION=' . $version . '.'
		);
	}

	public function test_zip_opens_cleanly_and_has_mainfile(): void {
		$version = self::read_canonical_version();
		$zip_path = self::plugin_root() . '/dist/sscribe-export-site-pages-' . $version . '.zip';
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this::markTestSkipped( 'ZipArchive extension not available.' );
		}
		$zip = new \ZipArchive();
		$this::assertTrue( $zip->open( $zip_path ) === true, 'Exact ZIP must open cleanly via ZipArchive.' );

		$has_plugin_name_bearing_mainfile = false;
		$has_activator                    = false;
		$has_uninstall                    = false;
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$stat = $zip->statIndex( $i );
			if ( ! isset( $stat['name'] ) ) {
				continue;
			}
			$name = $stat['name'];
			if ( preg_match( '#^sscribe-export-site-pages/[^/]+\.php$#', $name ) ) {
				$peek = (string) $zip->getFromIndex( $i );
				if ( false !== strpos( $peek, 'Plugin Name:' ) ) {
					$has_plugin_name_bearing_mainfile = true;
				}
			}
			if ( 'sscribe-export-site-pages/includes/class-sscribe-activator.php' === $name ) {
				$has_activator = true;
			}
			if ( 'sscribe-export-site-pages/uninstall.php' === $name ) {
				$has_uninstall = true;
			}
		}
		$zip->close();

		$this::assertTrue( $has_plugin_name_bearing_mainfile, 'ZIP must contain a Plugin Name-bearing mainfile.' );
		$this::assertTrue( $has_activator, 'ZIP must contain includes/class-sscribe-activator.php.' );
		$this::assertTrue( $has_uninstall, 'ZIP must contain uninstall.php.' );
	}

	public function test_zip_activator_schedules_canonical_cron_events(): void {
		$zip = self::open_zip();
		$src = (string) $zip->getFromName( 'sscribe-export-site-pages/includes/class-sscribe-activator.php' );
		$zip->close();
		$this::assertNotEmpty( $src, 'ZIP activator must be readable.' );
		foreach ( array( 'sscribe_cleanup_exports', 'sscribe_cleanup_sessions', 'sscribe_cleanup_audit_trail' ) as $hook ) {
			$this::assertStringContainsString(
				$hook,
				$src,
				'ZIP activator must reference cron hook ' . $hook . '.'
			);
		}
	}

	public function test_zip_grants_canonical_capabilities(): void {
		$zip = self::open_zip();
		$src = (string) $zip->getFromName( 'sscribe-export-site-pages/includes/class-sscribe-activator.php' );
		$zip->close();
		$this::assertNotEmpty( $src, 'ZIP activator must be readable.' );
		foreach ( array( 'sscribe_export', 'sscribe_health' ) as $cap ) {
			$this::assertStringContainsString(
				$cap,
				$src,
				'ZIP activator must reference capability ' . $cap . '.'
			);
		}
	}

	public function test_zip_creates_three_canonical_tables(): void {
		$zip = self::open_zip();
		$found = array_fill_keys( array( 'sscribe_export_logs', 'sscribe_export_stats', 'sscribe_audit_log' ), false );
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$stat = $zip->statIndex( $i );
			if ( ! isset( $stat['name'] ) || '.php' !== substr( $stat['name'], -4 ) ) {
				continue;
			}
			$peek = (string) $zip->getFromIndex( $i );
			foreach ( array_keys( $found ) as $suffix ) {
				if ( false !== strpos( $peek, $suffix ) ) {
					$found[ $suffix ] = true;
				}
			}
		}
		$zip->close();
		foreach ( $found as $suffix => $present ) {
			$this::assertTrue( $present, 'ZIP must reference table suffix ' . $suffix . ' from at least one PHP file.' );
		}
	}

	public function test_zip_uninstall_gated_on_wp_uninstall_plugin(): void {
		$zip = self::open_zip();
		$src = (string) $zip->getFromName( 'sscribe-export-site-pages/uninstall.php' );
		$zip->close();
		$this::assertNotEmpty( $src, 'ZIP must ship uninstall.php.' );
		$this::assertMatchesRegularExpression(
			"/defined\\s*\\(\\s*['\"]WP_UNINSTALL_PLUGIN['\"]\\s*\\)/",
			$src,
			'uninstall.php must be gated on defined( \'WP_UNINSTALL_PLUGIN\' ).'
		);
	}

	public function test_smoke_doc_references_canonical_zip_and_invariants(): void {
		$version = self::read_canonical_version();
		$smoke = (string) file_get_contents( self::plugin_root() . '/' . self::SMOKE_DOC );
		$this::assertStringContainsString(
			'dist/sscribe-export-site-pages-' . $version . '.zip',
			$smoke,
			'Smoke doc must reference the exact canonical ZIP filename.'
		);
		$this::assertStringContainsString( '| Invariant ', $smoke );
		$this::assertStringContainsString( 'wp_sscribe_export_logs', $smoke );
		$this::assertStringContainsString( 'wp_sscribe_export_stats', $smoke );
		$this::assertStringContainsString( 'wp_sscribe_audit_log', $smoke );
		$this::assertStringContainsString( 'sscribe_cleanup_exports', $smoke );
		$this::assertStringContainsString( 'sscribe_cleanup_sessions', $smoke );
		$this::assertStringContainsString( 'sscribe_cleanup_audit_trail', $smoke );
		$this::assertStringContainsString( 'sscribe_export', $smoke );
		$this::assertStringContainsString( 'sscribe_health', $smoke );
	}

	private static function open_zip(): \ZipArchive {
		if ( ! class_exists( 'ZipArchive' ) ) {
			throw new \RuntimeException( 'ZipArchive extension not available.' );
		}
		$version  = self::read_canonical_version();
		$zip_path = self::plugin_root() . '/dist/sscribe-export-site-pages-' . $version . '.zip';
		$zip      = new \ZipArchive();
		if ( $zip->open( $zip_path ) !== true ) {
			throw new \RuntimeException( 'Could not open exact ZIP at ' . $zip_path );
		}
		return $zip;
	}
}
