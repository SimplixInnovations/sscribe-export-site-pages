<?php
/**
 * SScribe Strauss Vendor-Prefix Integration Test
 *
 * @package SScribe_Export_Site_Pages
 *
 * Phase 28: locks the composer Strauss vendor-prefix contract.
 *
 * SScribe ships three Composer libraries (tecnickcom/tcpdf, phpoffice/phpword,
 * psr/container) that have to coexist with every other plugin on a
 * shared WordPress install. Without a prefixed vendor tree, the first
 * site that loads another plugin using the same library crashes with
 * a "Cannot redeclare class TCPDF\..." fatal.
 *
 * This integration test runs scripts/verify-strauss-config.php against
 * the live repo and against a series of synthetic composer.json
 * payloads (well-formed, missing strauss block, wrong namespace
 * prefix, missing canonical package, missing vendor:prefix script,
 * missing required fixup step). A regression that:
 *
 *   - silently accepts a missing extra.strauss block,
 *   - silently accepts a wrong namespace_prefix,
 *   - silently accepts a missing canonical package,
 *   - silently accepts a vendor:prefix script missing a fixup step,
 *
 * ...fails the suite immediately.
 */

declare( strict_types=1 );

namespace SScribe\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class SScribe_Strauss_Config_Test extends TestCase {

	private const SCRIPT_PATH = 'scripts/verify-strauss-config.php';

	private static function plugin_root(): string {
		return dirname( __DIR__, 2 );
	}

	/**
	 * @return array{0:int,1:string}
	 */
	private function run_against( ?string $composer_payload ): array {
		$composer_path = self::plugin_root() . '/composer.json';
		$backup        = file_get_contents( $composer_path );
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
			file_put_contents( $composer_path, $backup );
		}

		return array( (int) $code, $stdout . $stderr );
	}

	private function well_formed_composer(): string {
		return json_encode(
			array(
				'name'    => 'simplix/sscribe',
				'scripts' => array(
					'vendor:prefix' => 'php scripts/prune-tcpdf-for-strauss.php && php scripts/run-strauss.php && php scripts/fix-prefixed-safe.php && php scripts/fix-phpword-style-deprecation.php',
				),
				'extra'   => array(
					'strauss' => array(
						'target_directory'  => 'vendor-prefixed',
						'namespace_prefix'  => 'SScribeVendor\\',
						'classmap_prefix'   => 'SScribeVendor_',
						'packages'          => array(
							'tecnickcom/tcpdf',
							'phpoffice/phpword',
							'psr/container',
						),
						'delete_vendor_packages' => false,
					),
				),
			)
		);
	}

	public function test_well_formed_composer_passes(): void {
		list( $code, $output ) = $this->run_against( $this->well_formed_composer() );
		$this::assertSame(
			0,
			$code,
			'Well-formed composer.json must pass. Output: ' . $output
		);
		$this::assertStringContainsString( 'Strauss configuration contract holds', $output );
	}

	public function test_missing_strauss_block_fails(): void {
		$payload = json_encode(
			array(
				'name'    => 'simplix/sscribe',
				'scripts' => array(
					'vendor:prefix' => 'php scripts/prune-tcpdf-for-strauss.php && php scripts/run-strauss.php && php scripts/fix-prefixed-safe.php && php scripts/fix-phpword-style-deprecation.php',
				),
			)
		);
		list( $code, $output ) = $this->run_against( $payload );
		$this::assertSame( 1, $code, 'missing extra.strauss block must fail' );
		$this::assertStringContainsString( 'missing the required', $output );
	}

	public function test_wrong_namespace_prefix_fails(): void {
		$composer                                         = json_decode( $this->well_formed_composer(), true );
		$composer['extra']['strauss']['namespace_prefix'] = 'SomeOther\\';
		list( $code, $output ) = $this->run_against( json_encode( $composer ) );
		$this::assertSame( 1, $code, 'wrong namespace_prefix must fail' );
		$this::assertStringContainsString( 'namespace_prefix', $output );
	}

	public function test_missing_canonical_package_fails(): void {
		$composer                                 = json_decode( $this->well_formed_composer(), true );
		$composer['extra']['strauss']['packages'] = array( 'tecnickcom/tcpdf' ); // missing phpoffice + psr
		list( $code, $output ) = $this->run_against( json_encode( $composer ) );
		$this::assertSame( 1, $code, 'missing canonical Strauss package must fail' );
		$this::assertStringContainsString( 'missing the canonical libraries', $output );
	}

	public function test_missing_vendor_prefix_script_fails(): void {
		$composer = json_decode( $this->well_formed_composer(), true );
		unset( $composer['scripts']['vendor:prefix'] );
		list( $code, $output ) = $this->run_against( json_encode( $composer ) );
		$this::assertSame( 1, $code, 'missing vendor:prefix script must fail' );
		$this::assertStringContainsString( 'missing the `vendor:prefix` script', $output );
	}

	public function test_missing_fixup_step_fails(): void {
		$composer                              = json_decode( $this->well_formed_composer(), true );
		$composer['scripts']['vendor:prefix'] = 'php scripts/run-strauss.php';
		list( $code, $output ) = $this->run_against( json_encode( $composer ) );
		$this::assertSame( 1, $code, 'missing fix-prefixed-safe + fix-phpword fixup steps must fail' );
		$this::assertStringContainsString( 'fix-prefixed-safe.php', $output );
	}

	public function test_live_repo_configuration_passes(): void {
		// Clean source checkouts do not commit vendor-prefixed/. The
		// generated tree is certified separately with --built immediately
		// after composer vendor:prefix in E2E and release-build paths.
		list( $code, $output ) = $this->run_against( null );
		$this::assertSame(
			0,
			$code,
			"Live composer.json must satisfy the Strauss configuration contract. Output:\n" . $output
		);
		$this::assertStringContainsString( 'Strauss configuration contract holds', $output );
	}
}
