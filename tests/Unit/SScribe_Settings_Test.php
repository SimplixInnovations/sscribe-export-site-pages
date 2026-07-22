<?php
/**
 * SScribe Settings Unit Test
 *
 * Verifies the round-trip behaviour of every public static option
 * accessor on SScribe_Settings so that silent "value not stored"
 * regressions (cf. the export_logs.session_id drift found during
 * the 2026-07 audit) are caught by CI.
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * @group settings
 */
class SScribe_Settings_Test extends TestCase {

	protected function tearDown(): void {
		delete_option( \SScribe_Settings::OPT_DEBUG_ENABLED );
		delete_option( \SScribe_Settings::OPT_DEBUG_LOG_LEVEL );
		delete_option( \SScribe_Settings::OPT_DEBUG_AUTO_REFRESH );
		parent::tearDown();
	}

	public function test_debug_enabled_round_trip(): void {
		$this->assertFalse( \SScribe_Settings::is_debug_enabled(), 'defaults to false' );
		$this->assertTrue( \SScribe_Settings::set_debug_enabled( true ) );
		$this->assertTrue( \SScribe_Settings::is_debug_enabled() );
		$this->assertTrue( \SScribe_Settings::set_debug_enabled( true ), 'idempotent' );
		$this->assertTrue( \SScribe_Settings::set_debug_enabled( false ) );
		$this->assertFalse( \SScribe_Settings::is_debug_enabled() );
	}

	public function test_debug_log_level_round_trip(): void {
		$this->assertSame( \SScribe_Settings::LEVEL_DEBUG, \SScribe_Settings::get_debug_log_level() );
		$this->assertTrue( \SScribe_Settings::set_debug_log_level( \SScribe_Settings::LEVEL_ERROR ) );
		$this->assertSame( \SScribe_Settings::LEVEL_ERROR, \SScribe_Settings::get_debug_log_level() );
	}

	public function test_debug_log_level_rejects_unknown_values(): void {
		$this->assertTrue( \SScribe_Settings::set_debug_log_level( 'NOT_A_LEVEL' ) );
		$this->assertSame(
			\SScribe_Settings::LEVEL_DEBUG,
			\SScribe_Settings::get_debug_log_level(),
			'invalid level must fall back to default'
		);
	}

	public function test_auto_refresh_round_trip(): void {
		$this->assertTrue( \SScribe_Settings::is_auto_refresh(), 'defaults to true' );
		$this->assertTrue( \SScribe_Settings::set_auto_refresh( false ) );
		$this->assertFalse( \SScribe_Settings::is_auto_refresh() );
		$this->assertTrue( \SScribe_Settings::set_auto_refresh( true ) );
		$this->assertTrue( \SScribe_Settings::is_auto_refresh() );
	}

	public function test_all_levels_are_known_to_settings(): void {
		$levels = array(
			\SScribe_Settings::LEVEL_DEBUG,
			\SScribe_Settings::LEVEL_INFO,
			\SScribe_Settings::LEVEL_NOTICE,
			\SScribe_Settings::LEVEL_WARNING,
			\SScribe_Settings::LEVEL_ERROR,
			\SScribe_Settings::LEVEL_CRITICAL,
			\SScribe_Settings::LEVEL_ALL,
		);
		foreach ( $levels as $level ) {
			$this->assertNotEmpty( $level );
			$this->assertTrue( \SScribe_Settings::set_debug_log_level( $level ) );
			$this->assertSame( $level, \SScribe_Settings::get_debug_log_level() );
		}
	}
}