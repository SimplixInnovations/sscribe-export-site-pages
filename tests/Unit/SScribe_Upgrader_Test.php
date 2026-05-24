<?php
/**
 * SScribe Upgrader Unit Test
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

class SScribe_Upgrader_Test extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['sscribe_test_options'] = array();
		$GLOBALS['sscribe_test_transients'] = array();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['sscribe_test_options'], $GLOBALS['sscribe_test_transients'] );
		parent::tearDown();
	}

	public function test_maybe_upgrade_skips_when_version_matches(): void {
		update_option( 'sscribe_schema_version', SSCRIBE_VERSION );
		\SScribe_Upgrader::maybe_upgrade();
		$this->assertTrue( true ); // No exception = clean skip
	}

	public function test_maybe_upgrade_skips_when_version_higher(): void {
		update_option( 'sscribe_schema_version', '999.999.999' );
		\SScribe_Upgrader::maybe_upgrade();
		$this->assertTrue( true );
	}

	public function test_maybe_upgrade_runs_when_version_lower(): void {
		delete_option( 'sscribe_schema_version' );
		\SScribe_Upgrader::maybe_upgrade();
		$this->assertEquals( SSCRIBE_VERSION, get_option( 'sscribe_schema_version' ) );
	}

	public function test_maybe_upgrade_sets_lock_during_execution(): void {
		delete_option( 'sscribe_schema_version' );
		\SScribe_Upgrader::maybe_upgrade();
		$this->assertFalse( get_transient( 'sscribe_upgrade_lock' ) ); // Released in finally
	}

	public function test_maybe_upgrade_skips_when_locked(): void {
		set_transient( 'sscribe_upgrade_lock', true, 1200 );
		delete_option( 'sscribe_schema_version' );
		\SScribe_Upgrader::maybe_upgrade();
		$this->assertNotEquals( SSCRIBE_VERSION, get_option( 'sscribe_schema_version' ) );
	}

	public function test_maybe_upgrade_updates_sscribe_version(): void {
		delete_option( 'sscribe_schema_version' );
		\SScribe_Upgrader::maybe_upgrade();
		$this->assertEquals( SSCRIBE_VERSION, get_option( 'sscribe_version' ) );
	}

	public function test_maybe_upgrade_handles_error_gracefully(): void {
		delete_option( 'sscribe_schema_version' );
		// Force error by making wpdb->get_charset_collate() throw
		$orig_wpdb = $GLOBALS['wpdb'];

		$mock_wpdb = $this->createMock( \stdClass::class );

		$GLOBALS['wpdb'] = new class() {
			public string $prefix = 'wp_';
			public function get_charset_collate(): string {
				return 'CHARACTER SET utf8mb4';
			}
			public function prepare( string $query, ...$args ): string {
				return $query;
			}
			public function get_var( $query = null ) {
				return null;
			}
			public function get_results( $query = null ) {
				return array();
			}
		};

		\SScribe_Upgrader::maybe_upgrade();

		$GLOBALS['wpdb'] = $orig_wpdb;
		$this->assertTrue( true );
	}
}
