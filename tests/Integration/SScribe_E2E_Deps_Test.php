<?php
/**
 * SScribe E2E Dev-Dependencies Integration Test
 *
 * @package SScribe_Export_Site_Pages
 *
 * Phase 29: locks the Playwright E2E dev-dependency contract.
 *
 * The E2E suite is the integration-tier smoke for everything the JS
 * layer touches: the export wizard, the download-token auth flow,
 * the dark-mode contrast matrix, and the WCAG 2.2 AA regressions.
 * A broken E2E contract means CI silently ships a wizard no human
 * has exercised, an a11y regression that fails on production
 * readers, or a download endpoint no test has authenticated.
 *
 * This integration test runs scripts/verify-e2e-deps.php against
 * the live repo and against a series of synthetic package.json /
 * composer.json payloads. A regression that:
 *
 *   - silently accepts a missing @playwright/test devDep,
 *   - silently accepts a missing @axe-core/playwright devDep,
 *   - silently accepts a missing @wp-playground/cli devDep,
 *   - silently accepts a missing test:e2e:* npm script,
 *   - silently accepts a dropped Playwright project,
 *   - silently accepts a missing tests-e2e/{a11y,e2e}/ subdir,
 *
 * ...fails the suite immediately.
 */

declare( strict_types=1 );

namespace SScribe\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class SScribe_E2E_Deps_Test extends TestCase {

	private const SCRIPT_PATH = 'scripts/verify-e2e-deps.php';

	private static function plugin_root(): string {
		return dirname( __DIR__, 2 );
	}

	/**
	 * @return array{0:int,1:string}
	 */
	private function run_against( ?string $package_payload, ?string $composer_payload ): array {
		$package_path  = self::plugin_root() . '/package.json';
		$composer_path = self::plugin_root() . '/composer.json';
		$backup_pkg    = file_get_contents( $package_path );
		$backup_cmp    = file_get_contents( $composer_path );
		if ( null !== $package_payload ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $package_path, $package_payload );
		}
		if ( null !== $composer_payload ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $composer_path, $composer_payload );
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
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $package_path, $backup_pkg );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $composer_path, $backup_cmp );
		}

		return array( (int) $code, $stdout . $stderr );
	}

	private function well_formed_package(): string {
		return json_encode(
			array(
				'name'    => 'sscribe',
				'scripts' => array(
					'test:e2e'       => 'playwright test',
					'test:e2e:smoke' => 'playwright test --grep smoke',
					'test:e2e:full'  => 'playwright test',
					'test:e2e:e2e'   => 'playwright test --project=e2e',
					'test:e2e:a11y'  => 'playwright test --project=a11y',
					'test:e2e:perf'  => 'playwright test --project=perf',
				),
				'devDependencies' => array(
					'@playwright/test'     => '^1.62.1',
					'@axe-core/playwright' => '^4.13.0',
					'@wp-playground/cli'   => '^3.1.44',
				),
			)
		);
	}

	private function well_formed_composer(): string {
		return json_encode(
			array(
				'name'    => 'simplix/sscribe',
				'scripts' => array(
					'release:prepare' => 'php scripts/release-prepare.php',
					'release'         => 'php scripts/build-release.php',
				),
			)
		);
	}

	public function test_well_formed_passes(): void {
		list( $code, $output ) = $this->run_against(
			$this->well_formed_package(),
			$this->well_formed_composer()
		);
		$this::assertSame(
			0,
			$code,
			'Well-formed package.json + composer.json must pass. Output: ' . $output
		);
		$this::assertStringContainsString( 'E2E dev-dependency contract holds', $output );
	}

	public function test_missing_playwright_devdep_fails(): void {
		$pkg                                                   = json_decode( $this->well_formed_package(), true );
		unset( $pkg['devDependencies']['@playwright/test'] );
		list( $code, $output ) = $this->run_against( json_encode( $pkg ), $this->well_formed_composer() );
		$this::assertSame( 1, $code );
		$this::assertStringContainsString( '@playwright/test', $output );
	}

	public function test_missing_axe_core_devdep_fails(): void {
		$pkg                                                     = json_decode( $this->well_formed_package(), true );
		unset( $pkg['devDependencies']['@axe-core/playwright'] );
		list( $code, $output ) = $this->run_against( json_encode( $pkg ), $this->well_formed_composer() );
		$this::assertSame( 1, $code );
		$this::assertStringContainsString( '@axe-core/playwright', $output );
	}

	public function test_missing_wp_playground_devdep_fails(): void {
		$pkg                                                    = json_decode( $this->well_formed_package(), true );
		unset( $pkg['devDependencies']['@wp-playground/cli'] );
		list( $code, $output ) = $this->run_against( json_encode( $pkg ), $this->well_formed_composer() );
		$this::assertSame( 1, $code );
		$this::assertStringContainsString( '@wp-playground/cli', $output );
	}

	public function test_missing_test_e2e_smoke_script_fails(): void {
		$pkg                                  = json_decode( $this->well_formed_package(), true );
		unset( $pkg['scripts']['test:e2e:smoke'] );
		list( $code, $output ) = $this->run_against( json_encode( $pkg ), $this->well_formed_composer() );
		$this::assertSame( 1, $code );
		$this::assertStringContainsString( 'test:e2e:smoke', $output );
	}

	public function test_missing_composer_release_prepare_fails(): void {
		$cmp                                    = json_decode( $this->well_formed_composer(), true );
		unset( $cmp['scripts']['release:prepare'] );
		list( $code, $output ) = $this->run_against( $this->well_formed_package(), json_encode( $cmp ) );
		$this::assertSame( 1, $code );
		$this::assertStringContainsString( 'release:prepare', $output );
	}

	public function test_live_repo_passes(): void {
		list( $code, $output ) = $this->run_against( null, null );
		$this::assertSame(
			0,
			$code,
			"Live package.json + composer.json must satisfy the E2E deps contract. Output:\n" . $output
		);
	}
}
