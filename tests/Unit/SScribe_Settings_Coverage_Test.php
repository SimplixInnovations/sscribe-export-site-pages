<?php
/**
 * SScribe Settings coverage test.
 *
 * Targets the static settings class. All methods are static and use
 * get_option/update_option — both already stubbed in the unit env.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

if ( ! class_exists( '\\SScribe_Settings', false ) ) {
	require_once SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-settings.php';
}

final class SScribe_Settings_Coverage_Test extends TestCase {

	public function test_is_debug_enabled_default_false(): void {
		delete_option( \SScribe_Settings::OPT_DEBUG_ENABLED );
		$this::assertFalse( \SScribe_Settings::is_debug_enabled() );
	}

	public function test_is_debug_enabled_reflects_option(): void {
		update_option( \SScribe_Settings::OPT_DEBUG_ENABLED, true, false );
		$this::assertTrue( \SScribe_Settings::is_debug_enabled() );
		update_option( \SScribe_Settings::OPT_DEBUG_ENABLED, false, false );
		$this::assertFalse( \SScribe_Settings::is_debug_enabled() );
	}

	public function test_set_debug_enabled_returns_bool(): void {
		$previous = (bool) \SScribe_Settings::is_debug_enabled();
		$result = \SScribe_Settings::set_debug_enabled( true );
		$this::assertTrue( $result );
		// Idempotent set returns true.
		$result = \SScribe_Settings::set_debug_enabled( true );
		$this::assertTrue( $result );
		// Restore prior state. The `sscribe_test_options` global is shared
		// across the parent PHPUnit run; without this restore, every
		// later test sees `is_debug_enabled() === true`, which breaks
		// `SScribe_Logger_Singleton_Test`'s disabled-logger assertions
		// (is_logging_enabled() short-circuits to true and the
		// singleton cache key embeds the effective-enabled flag).
		\SScribe_Settings::set_debug_enabled( $previous );
	}

	public function test_get_debug_log_level_defaults_to_debug(): void {
		delete_option( \SScribe_Settings::OPT_DEBUG_LOG_LEVEL );
		$this::assertSame( 'DEBUG', \SScribe_Settings::get_debug_log_level() );
	}

	public function test_get_debug_log_level_sanitizes_invalid(): void {
		update_option( \SScribe_Settings::OPT_DEBUG_LOG_LEVEL, 'INVALID', false );
		$this::assertSame( 'DEBUG', \SScribe_Settings::get_debug_log_level() );
	}

	public function test_sanitize_debug_log_level_handles_non_scalar(): void {
		$result = \SScribe_Settings::sanitize_debug_log_level( array( 'evil' ) );
		$this::assertSame( 'DEBUG', $result );
		$result = \SScribe_Settings::sanitize_debug_log_level( null );
		$this::assertSame( 'DEBUG', $result );
	}

	public function test_sanitize_debug_log_level_accepts_valid(): void {
		$this::assertSame( 'INFO', \SScribe_Settings::sanitize_debug_log_level( 'info' ) );
		$this::assertSame( 'WARNING', \SScribe_Settings::sanitize_debug_log_level( 'WARNING' ) );
	}

	public function test_get_allowed_log_levels_returns_array(): void {
		$result = \SScribe_Settings::get_allowed_log_levels();
		$this::assertIsArray( $result );
		$this::assertContains( 'DEBUG', $result );
		$this::assertContains( 'CRITICAL', $result );
		$this::assertCount( 7, $result );
	}

	public function test_set_debug_log_level_returns_bool(): void {
		$result = \SScribe_Settings::set_debug_log_level( 'INFO' );
		$this::assertTrue( $result );
		$this::assertSame( 'INFO', \SScribe_Settings::get_debug_log_level() );
	}

	public function test_is_auto_refresh_default_true(): void {
		delete_option( \SScribe_Settings::OPT_DEBUG_AUTO_REFRESH );
		$this::assertTrue( \SScribe_Settings::is_auto_refresh() );
	}

	public function test_set_auto_refresh_returns_bool(): void {
		$result = \SScribe_Settings::set_auto_refresh( false );
		$this::assertTrue( $result );
		$this::assertFalse( \SScribe_Settings::is_auto_refresh() );
		$result = \SScribe_Settings::set_auto_refresh( false );
		$this::assertTrue( $result );
	}

	public function test_get_debug_settings_returns_array(): void {
		$result = \SScribe_Settings::get_debug_settings();
		$this::assertIsArray( $result );
		$this::assertArrayHasKey( 'debug_enabled', $result );
		$this::assertArrayHasKey( 'log_level', $result );
		$this::assertArrayHasKey( 'auto_refresh', $result );
	}

	public function test_save_debug_settings_returns_bool(): void {
		$result = \SScribe_Settings::save_debug_settings( array(
			'debug_enabled' => true,
			'log_level'     => 'INFO',
			'auto_refresh'  => true,
		) );
		$this::assertTrue( $result );
	}

	public function test_save_debug_settings_uses_defaults_for_missing_keys(): void {
		$result = \SScribe_Settings::save_debug_settings( array() );
		$this::assertTrue( $result );
	}

	public function test_class_has_expected_methods(): void {
		$this::assertTrue( method_exists( \SScribe_Settings::class, 'is_debug_enabled' ) );
		$this::assertTrue( method_exists( \SScribe_Settings::class, 'set_debug_enabled' ) );
		$this::assertTrue( method_exists( \SScribe_Settings::class, 'get_debug_log_level' ) );
		$this::assertTrue( method_exists( \SScribe_Settings::class, 'set_debug_log_level' ) );
		$this::assertTrue( method_exists( \SScribe_Settings::class, 'sanitize_debug_log_level' ) );
		$this::assertTrue( method_exists( \SScribe_Settings::class, 'is_auto_refresh' ) );
		$this::assertTrue( method_exists( \SScribe_Settings::class, 'set_auto_refresh' ) );
		$this::assertTrue( method_exists( \SScribe_Settings::class, 'get_debug_settings' ) );
		$this::assertTrue( method_exists( \SScribe_Settings::class, 'save_debug_settings' ) );
	}
}
