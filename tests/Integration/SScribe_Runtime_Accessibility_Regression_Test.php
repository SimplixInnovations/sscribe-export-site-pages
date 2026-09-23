<?php
/**
 * Static regression contracts for runtime-generated admin accessibility.
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class SScribe_Runtime_Accessibility_Regression_Test extends TestCase {

	private static function plugin_root(): string {
		return dirname( __DIR__, 2 );
	}

	public function test_toast_uses_an_explicit_keyboard_accessible_dismiss_button(): void {
		$source = (string) file_get_contents( self::plugin_root() . '/admin/js/sscribe-admin.js' );

		$this->assertStringContainsString( "addClass('sscribe-toast-dismiss')", $source );
		$this->assertStringContainsString( ".attr('type', 'button')", $source );
		$this->assertStringContainsString( "sscribe_data.strings.dismiss_notification", $source );
		$this->assertStringContainsString( "$toast.append($icon).append($body).append($dismissButton)", $source );
	}

	public function test_preflight_banner_restores_focus_when_it_is_removed(): void {
		$source = (string) file_get_contents( self::plugin_root() . '/admin/js/sscribe-admin.js' );

		$this->assertStringContainsString( 'const preflightReturnFocus = document.activeElement;', $source );
		$this->assertStringContainsString( 'restorePreflightFocus', $source );
		$this->assertStringContainsString( 'preflightReturnFocus', $source );
	}
}
