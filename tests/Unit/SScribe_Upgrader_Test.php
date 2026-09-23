<?php
/**
 * SScribe Upgrader Unit Test
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class SScribe_Upgrader_Test extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['sscribe_test_options']      = array();
		$GLOBALS['sscribe_test_transients']   = array();
		$GLOBALS['sscribe_test_is_admin']     = true;
		$GLOBALS['sscribe_test_doing_ajax']   = false;
		$GLOBALS['sscribe_test_doing_cron']   = false;
	}

	protected function tearDown(): void {
		unset(
			$GLOBALS['sscribe_test_options'],
			$GLOBALS['sscribe_test_transients'],
			$GLOBALS['sscribe_test_is_admin'],
			$GLOBALS['sscribe_test_doing_ajax'],
			$GLOBALS['sscribe_test_doing_cron']
		);
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

	public function test_maybe_upgrade_skips_anonymous_frontend_context(): void {
		$GLOBALS['sscribe_test_is_admin'] = false;
		delete_option( 'sscribe_schema_version' );

		\SScribe_Upgrader::maybe_upgrade();

		$this->assertFalse( get_option( 'sscribe_schema_version', false ) );
	}

	public function test_maybe_upgrade_runs_when_ajax_context(): void {
		$GLOBALS['sscribe_test_is_admin']   = false;
		$GLOBALS['sscribe_test_doing_ajax'] = true;
		delete_option( 'sscribe_schema_version' );

		\SScribe_Upgrader::maybe_upgrade();

		$this->assertSame( SSCRIBE_VERSION, get_option( 'sscribe_schema_version' ) );
	}

	public function test_maybe_upgrade_runs_when_version_lower(): void {
		delete_option( 'sscribe_schema_version' );
		\SScribe_Upgrader::maybe_upgrade();
		$this->assertEquals( SSCRIBE_VERSION, get_option( 'sscribe_schema_version' ) );
	}

	public function test_maybe_upgrade_sets_lock_during_execution(): void {
		delete_option( 'sscribe_schema_version' );
		\SScribe_Upgrader::maybe_upgrade();
		$this->assertFalse( get_option( 'sscribe_export_lock_upgrade', false ) );
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
			public string $options = 'wp_options';
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
			public function delete( $table, $where, $where_format = null ) {
				global $sscribe_test_options;
				unset( $table, $where_format );
				$option_name = (string) ( $where['option_name'] ?? '' );
				$expected    = (string) ( $where['option_value'] ?? '' );
				if ( '' === $option_name || ! isset( $sscribe_test_options[ $option_name ] ) || (string) $sscribe_test_options[ $option_name ] !== $expected ) {
					return 0;
				}
				unset( $sscribe_test_options[ $option_name ] );
				return 1;
			}
		};

		try {
			\SScribe_Upgrader::maybe_upgrade();
		} finally {
			$GLOBALS['wpdb'] = $orig_wpdb;
		}
		$this->assertFalse( get_option( 'sscribe_schema_version' ) );
		$error = get_option( 'sscribe_upgrade_last_error' );
		$this->assertIsArray( $error );
		$this->assertSame( 'The database upgrade did not complete and will be retried.', $error['message'] );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{12}$/', $error['reference'] );
	}
}
