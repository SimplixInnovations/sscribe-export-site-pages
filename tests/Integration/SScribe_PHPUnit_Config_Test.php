<?php
/**
 * PHPUnit configuration contract tests.
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class SScribe_PHPUnit_Config_Test extends TestCase {

	private static function root(): string {
		return dirname( __DIR__, 2 );
	}

	public function test_default_config_keeps_strict_warning_policy_without_requesting_coverage(): void {
		$path = self::root() . '/phpunit.xml';
		$this->assertFileExists( $path );
		$xml = (string) file_get_contents( $path );

		$this->assertStringContainsString( 'failOnWarning="true"', $xml );
		$this->assertStringContainsString( 'failOnRisky="true"', $xml );
		$this->assertStringNotContainsString(
			'<coverage>',
			$xml,
			'Default PHPUnit must not request coverage in no-driver CI jobs; warnings remain fatal.'
		);
	}

	public function test_coverage_config_exists_and_writes_clover_report(): void {
		$path = self::root() . '/phpunit-coverage.xml';
		$this->assertFileExists( $path, 'Coverage reporting belongs in its own PHPUnit config.' );
		$xml = (string) file_get_contents( $path );

		$this->assertStringContainsString( 'failOnWarning="true"', $xml );
		$this->assertStringContainsString( '<coverage>', $xml );
		$this->assertStringContainsString( 'clover.xml', $xml );
		$this->assertStringContainsString( 'tests/Unit', $xml );
		$this->assertStringContainsString( 'tests/Integration', $xml );
		$this->assertStringContainsString( 'tests/Security', $xml );
	}

	public function test_composer_coverage_script_uses_coverage_config(): void {
		$composer = json_decode( (string) file_get_contents( self::root() . '/composer.json' ), true );
		$this->assertIsArray( $composer );
		$command = (string) ( $composer['scripts']['test:coverage'] ?? '' );
		$this->assertStringContainsString( 'phpunit-coverage.xml', $command );
	}
}
