<?php
/**
 * Cross-platform developer/release tooling regressions.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

namespace SScribe\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class SScribe_Cross_Platform_Tooling_Test extends TestCase {

	private static function plugin_root(): string {
		return dirname( __DIR__, 2 );
	}

	public function test_bootstrap_uses_platform_correct_null_device(): void {
		$source = (string) file_get_contents( self::plugin_root() . '/tests/bootstrap.php' );
		$this::assertStringNotContainsString( "' >NUL 2>&1'", $source );
		$this::assertStringContainsString( "'Windows' === PHP_OS_FAMILY ? 'NUL' : '/dev/null'", $source );
		if ( 'Windows' !== PHP_OS_FAMILY ) {
			$this::assertFileDoesNotExist( self::plugin_root() . '/NUL' );
		}
	}

	public function test_release_builder_defensively_excludes_nul_artifact(): void {
		$source = (string) file_get_contents( self::plugin_root() . '/scripts/build-release.php' );
		$this::assertMatchesRegularExpression( "/['\"]NUL['\"]/", $source );
	}

	public function test_process_runner_handles_large_stdout_and_stderr_without_pipe_deadlock(): void {
		require_once self::plugin_root() . '/scripts/lib/cross-platform.php';
		$log = tempnam( sys_get_temp_dir(), 'sscribe-runner-test-' );
		$this::assertNotFalse( $log );
		$code   = 'fwrite(STDOUT, str_repeat("O", 524288)); fwrite(STDERR, str_repeat("E", 524288));';
		$result = sscribe_run_argv( array( PHP_BINARY, '-r', $code ), self::plugin_root(), array(), $log );
		$this::assertSame( 0, $result['code'] );
		$this::assertGreaterThanOrEqual( 1048576, filesize( $log ) ?: 0 );
		@unlink( $log );
	}

	public function test_windows_powershell_scripts_are_not_routed_through_cmd(): void {
		$source = (string) file_get_contents( self::plugin_root() . '/scripts/lib/cross-platform.php' );
		$this::assertStringContainsString( "str_ends_with( \$lower, '.ps1' )", $source );
		$this::assertStringContainsString( "'pwsh.exe'", $source );
		$this::assertStringContainsString( "'powershell.exe'", $source );
	}

	public function test_e2e_workflow_uses_non_interactive_release_builder(): void {
		$source = (string) file_get_contents( self::plugin_root() . '/.github/workflows/e2e.yml' );
		$this::assertStringContainsString( 'run: composer release', $source );
		$this::assertStringNotContainsString( 'run: composer release:prepare', $source );
	}

	public function test_windows_verify_auto_provisions_real_wordpress(): void {
		$source = (string) file_get_contents( self::plugin_root() . '/scripts/dev.ps1' );
		$this::assertStringContainsString( 'real WordPress testbench install', $source );
		$this::assertStringNotContainsString( 'SKIP: composer test:wp', $source );
	}

	public function test_i18n_make_pot_uses_cross_platform_php_wrapper(): void {
		$composer = (string) file_get_contents( self::plugin_root() . '/composer.json' );
		$this::assertStringContainsString( '"i18n:make-pot": "@php scripts/make-pot.php"', $composer );
		$this::assertStringNotContainsString( '$(which wp)', $composer );
		$this::assertFileExists( self::plugin_root() . '/scripts/make-pot.php' );
	}
}
