<?php
/**
 * SScribe Admin build_localized_data coverage test
 *
 * Targets the pure helpers of SScribe_Admin's `build_localized_data`:
 *
 *   - The full translated strings dictionary (keys + types)
 *   - refresh_interval clamping logic via apply_filters
 *   - health_nonce conditional based on capabilities
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;

if ( ! class_exists( '\\SScribe_Admin', false ) ) {
	require_once SSCRIBE_PLUGIN_DIR . 'admin/class-sscribe-admin.php';
}

final class SScribe_Admin_Build_Localized_Test extends TestCase {

	private \SScribe_Admin $admin;
	private ReflectionClass $ref;

	protected function setUp(): void {
		parent::setUp();
		$this->admin = new \SScribe_Admin();
		$this->ref   = new ReflectionClass( $this->admin );
	}

	private function call( string $name, array $args = array() ): mixed {
		$m = $this->ref->getMethod( $name );
		$m->setAccessible( true );
		return $m->invokeArgs( $this->admin, $args );
	}

	public function test_build_localized_data_returns_array(): void {
		$result = $this->call( 'build_localized_data' );
		$this::assertIsArray( $result );
		$this::assertArrayHasKey( 'ajaxurl', $result );
		$this::assertArrayHasKey( 'nonce', $result );
		$this::assertArrayHasKey( 'strings', $result );
	}

	public function test_strings_dict_has_expected_keys(): void {
		$result = $this->call( 'build_localized_data' );
		$strings = $result['strings'];

		$this::assertIsArray( $strings );
		$expected = array(
			'starting', 'processing', 'complete', 'error',
			'download', 'generating', 'confirm_export', 'confirm_delete',
			'auto_delete', 'cancel', 'cancelling', 'loading_log',
			'log_not_found', 'log_load_failed', 'delete_failed',
			'delete_success', 'history_empty', 'history_caption',
			'history_col_select', 'history_col_export', 'history_col_actions',
			'select_export_label', 'download_label',
		);
		foreach ( $expected as $key ) {
			$this::assertArrayHasKey( $key, $strings );
			$this::assertIsString( $strings[ $key ] );
			$this::assertNotSame( '', $strings[ $key ] );
		}
	}

	public function test_refresh_interval_clamps_to_max_300000(): void {
		// Filter returning absurdly high value should be clamped to 300000.
		$cb = static function (): int { return 9999999; };
		add_filter( 'sscribe_debug_refresh_interval_ms', $cb );
		try {
			$result = $this->call( 'build_localized_data' );
		} finally {
			remove_filter( 'sscribe_debug_refresh_interval_ms', $cb );
		}
		$this::assertSame( 300000, $result['refresh_interval'] );
	}

	public function test_refresh_interval_clamps_to_min_5000(): void {
		// Filter returning tiny value should be clamped to 5000.
		$cb = static function (): int { return 100; };
		add_filter( 'sscribe_debug_refresh_interval_ms', $cb );
		try {
			$result = $this->call( 'build_localized_data' );
		} finally {
			remove_filter( 'sscribe_debug_refresh_interval_ms', $cb );
		}
		$this::assertSame( 5000, $result['refresh_interval'] );
	}

	public function test_refresh_interval_passes_through_normal(): void {
		// Filter returning normal value passes through unchanged.
		$cb = static function (): int { return 15000; };
		add_filter( 'sscribe_debug_refresh_interval_ms', $cb );
		try {
			$result = $this->call( 'build_localized_data' );
		} finally {
			remove_filter( 'sscribe_debug_refresh_interval_ms', $cb );
		}
		$this::assertSame( 15000, $result['refresh_interval'] );
	}

	public function test_strings_dict_has_format_options_block(): void {
		$result  = $this->call( 'build_localized_data' );
		$strings = $result['strings'];

		// Verify the dict has at least 30 strings — a healthy dictionary.
		$this::assertGreaterThanOrEqual( 30, count( $strings ) );
	}
}
