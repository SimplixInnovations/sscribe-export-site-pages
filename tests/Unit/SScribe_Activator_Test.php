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

		$upload_dir  = wp_upload_dir();
		$export_path = untrailingslashit( $upload_dir['basedir'] ) . '/sscribe-exports';

		$this->assertFileExists( $export_path . '/.htaccess' );
		$this->assertFileExists( $export_path . '/index.php' );
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
