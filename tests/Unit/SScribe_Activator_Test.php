<?php
/**
 * SScribe activator unit tests.
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

class SScribe_Activator_Test extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['sscribe_test_options']             = array();
		$GLOBALS['sscribe_test_transients']          = array();
		$GLOBALS['sscribe_test_registered_settings'] = array();
		$GLOBALS['sscribe_test_scheduled_events']    = array();
		$GLOBALS['sscribe_test_db_tables']           = array(
			'wp_sscribe_audit_log'    => array(),
			'wp_sscribe_export_stats' => array(),
		);
	}

	public function test_activation_checks_session_crypto_provider_before_setup(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/class-sscribe-activator.php' );

		$this::assertStringContainsString( 'SScribe_Vendor_Bootstrap::has_session_crypto_provider()', $source );
		$this::assertStringContainsString( 'Sodium or OpenSSL AES-256-GCM', $source );
	}

	public function test_activate_single_site_completes_core_setup(): void {
		\SScribe_Activator::activate( false );

		$this->assertSame( SSCRIBE_VERSION, get_option( 'sscribe_version' ) );
		$this->assertSame( '1', get_transient( 'sscribe_activation_redirect' ) );
		$this->assertFalse( get_transient( 'sscribe_boot_error' ) );

		// Settings API registration is wired to admin_init in
		// sscribe-export-site-pages.php (not on activation). It is
		// covered separately by test_register_settings_populates_whitelist().
		$this->assertArrayNotHasKey( 'sscribe_debug_enabled', $GLOBALS['sscribe_test_registered_settings'] );
		$this->assertArrayHasKey( 'sscribe_cleanup_exports', $GLOBALS['sscribe_test_scheduled_events'] );
		$this->assertArrayHasKey( 'sscribe_cleanup_sessions', $GLOBALS['sscribe_test_scheduled_events'] );
		$this->assertArrayHasKey( 'sscribe_cleanup_audit_trail', $GLOBALS['sscribe_test_scheduled_events'] );

		$role = get_role( 'administrator' );
		$this->assertNotNull( $role );
		$this->assertTrue( $role->has_cap( 'sscribe_export' ) );

		$export_path = \SScribe_Private_Storage::get_export_dir();

		$this->assertFileExists( $export_path . '/.htaccess' );
		$this->assertFileExists( $export_path . '/index.php' );
	}


	public function test_cleanup_orphaned_data_uses_mysql_compatible_two_step_batches(): void {
		$original_wpdb = $GLOBALS['wpdb'];

		$wpdb = new class() extends \wpdb {
			public array $selects = array();
			public array $deletes = array();

			private int $select_calls = 0;

			public function get_col( $query ) {
				$this->selects[] = (string) $query;
				++$this->select_calls;

				return 1 === ( $this->select_calls % 2 )
					? array( '101', '102' )
					: array();
			}

			public function delete( $table, $where, $where_format = null ) {
				$this->deletes[] = array(
					'table'  => $table,
					'where'  => $where,
					'format' => $where_format,
				);

				return 1;
			}
		};

		$GLOBALS['wpdb'] = $wpdb;

		try {
			$method = new \ReflectionMethod( \SScribe_Activator::class, 'cleanup_orphaned_data' );
			$method->setAccessible( true );
			$method->invoke( null );
		} finally {
			$GLOBALS['wpdb'] = $original_wpdb;
		}

		$this->assertCount( 16, $wpdb->selects, 'Each of the eight cleanup patterns must select one batch and then confirm exhaustion.' );
		$this->assertCount( 16, $wpdb->deletes );

		foreach ( $wpdb->selects as $select ) {
			$this->assertStringContainsString( 'SELECT option_id FROM wp_options', $select );
			$this->assertStringContainsString( 'LIMIT 1000', $select );
			$this->assertStringNotContainsString( 'DELETE FROM', $select );
		}

		foreach ( $wpdb->deletes as $index => $delete ) {
			$this->assertSame( 'wp_options', $delete['table'] );
			$this->assertSame( array( 'option_id' => 0 === ( $index % 2 ) ? 101 : 102 ), $delete['where'] );
			$this->assertSame( array( '%d' ), $delete['format'] );
		}
	}

	/**
	 * The Settings API whitelist must be populated on every admin
	 * request, not just on activation. Cover the admin_init hook so a
	 * future refactor that re-introduces the dead-registry
	 * anti-pattern fails this test.
	 */
	public function test_register_settings_populates_whitelist(): void {
		\SScribe_Activator::register_settings();

		$this->assertArrayHasKey( 'sscribe_debug_enabled', $GLOBALS['sscribe_test_registered_settings'] );
		$this->assertArrayHasKey( 'sscribe_debug_log_level', $GLOBALS['sscribe_test_registered_settings'] );
		$this->assertArrayHasKey( 'sscribe_debug_auto_refresh', $GLOBALS['sscribe_test_registered_settings'] );
	}
}
