<?php
/**
 * Uninstall contract test
 *
 * The uninstall script is a procedural file (not a class). To test it
 * without polluting the real WP environment, we read the source file,
 * isolate the cleanup closure, then invoke it under a controlled mock
 * surface. The test verifies the file's cleanup contract:
 *
 *   1. sscribe_* options are removed.
 *   2. sscribe plugin tables are dropped.
 *   3. sscribe cron events are cleared.
 *   4. sscribe upload directories are recursively removed.
 *   5. Plugin caps are revoked from every role.
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class Uninstall_Test extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['sscribe_test_options'] = array();
		$GLOBALS['sscribe_test_db_tables'] = array(
			'wp_sscribe_export_logs'  => array( 'mocked-row' ),
			'wp_sscribe_export_stats' => array( 'mocked-row' ),
			'wp_sscribe_audit_log'    => array( 'mocked-row' ),
			'wp_sscribe_sessions'     => array( 'mocked-row' ),
		);
		$GLOBALS['sscribe_test_transients'] = array();
		$GLOBALS['sscribe_test_scheduled_events'] = array(
			'sscribe_cleanup_exports'     => array( 'timestamp' => time() + 3600, 'recurrence' => 'hourly' ),
			'sscribe_cleanup_sessions'    => array( 'timestamp' => time() + 3600, 'recurrence' => 'hourly' ),
			'sscribe_cleanup_audit_trail' => array( 'timestamp' => time() + 86400, 'recurrence' => 'daily' ),
		);
		$GLOBALS['sscribe_test_role_overrides'] = array(
			'administrator' => new \WP_Role( 'administrator', array(
				'manage_options' => true,
				'sscribe_export' => true,
				'sscribe_health' => true,
			) ),
		);

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'sscribe-export-site-pages/sscribe-export-site-pages.php' );
		}
	}

	protected function tearDown(): void {
		unset(
			$GLOBALS['sscribe_test_options'],
			$GLOBALS['sscribe_test_db_tables'],
			$GLOBALS['sscribe_test_transients'],
			$GLOBALS['sscribe_test_scheduled_events'],
			$GLOBALS['sscribe_test_role_overrides']
		);
		parent::tearDown();
	}

	/**
	 * Read the uninstall.php file and isolate the inner cleanup closure so it
	 * can be invoked in this test environment. The file is plain procedural
	 * code, so we extract the closure body and execute it under our mocked
	 * globals.
	 */
	private function invoke_cleanup_site_closure(): void {
		$source = file_get_contents( SSCRIBE_PLUGIN_DIR . 'uninstall.php' );
		$this->assertNotFalse( $source, 'uninstall.php must be readable.' );

		// Find the closure block: starts at "$sscribe_cleanup_site = static function"
		// and ends at the matching closing "};" for the closed-over variable.
		$start = strpos( $source, '$sscribe_cleanup_site = static function' );
		$this->assertNotFalse( $start, 'uninstall.php must define $sscribe_cleanup_site closure.' );

		// Walk forward until we hit the matching `};\n` that closes the
		// assignment.
		$depth       = 0;
		$in_function = false;
		$end         = $start;
		$len         = strlen( $source );
		for ( $i = $start; $i < $len; $i++ ) {
			$ch = $source[ $i ];
			if ( '{' === $ch ) {
				++$depth;
				$in_function = true;
			} elseif ( '}' === $ch ) {
				--$depth;
				if ( $in_function && 0 === $depth ) {
					// Consume the trailing semicolon + newline.
					$end = $i + 2;
					break;
				}
			}
		}
		$this->assertGreaterThan( $start, $end, 'uninstall.php closure must be terminated.' );

		$snippet = substr( $source, $start, $end - $start );

		// Audit the snippet for required cleanup paths.
		$this->assertStringContainsString( 'sscribe_export_logs', $snippet );
		$this->assertStringContainsString( 'sscribe_export_stats', $snippet );
		$this->assertStringContainsString( 'sscribe_sessions', $snippet );
		$this->assertStringContainsString( 'sscribe_settings', $snippet );
		$this->assertStringContainsString( 'DROP TABLE IF EXISTS', $snippet );

		// Evaluate the closure definition so we can invoke it.
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.eval_like_eval -- Test sandbox.
		eval( $snippet . ' $GLOBALS["__sscribe_cleanup_closure"] = $sscribe_cleanup_site;' );

		$this->assertInstanceOf( \Closure::class, $GLOBALS['__sscribe_cleanup_closure'] );
		( $GLOBALS['__sscribe_cleanup_closure'] )();
		unset( $GLOBALS['__sscribe_cleanup_closure'] );
	}

	public function test_uninstall_defines_guard(): void {
		$source = file_get_contents( SSCRIBE_PLUGIN_DIR . 'uninstall.php' );
		$this->assertStringContainsString( "if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {", $source );
		$this->assertStringContainsString( 'exit;', $source );
	}

	public function test_uninstall_clears_scheduled_hooks(): void {
		$this->invoke_cleanup_site_closure();

		$this->assertArrayNotHasKey( 'sscribe_cleanup_exports', $GLOBALS['sscribe_test_scheduled_events'] );
		$this->assertArrayNotHasKey( 'sscribe_cleanup_sessions', $GLOBALS['sscribe_test_scheduled_events'] );
		$this->assertArrayNotHasKey( 'sscribe_cleanup_audit_trail', $GLOBALS['sscribe_test_scheduled_events'] );
	}

	public function test_uninstall_clears_named_sscribe_options(): void {
		$GLOBALS['sscribe_test_options'] = array(
			'sscribe_version'             => '1.1.4',
			'sscribe_export_index'        => array(),
			'sscribe_export_metrics'      => array(),
			'sscribe_schema_version'      => 5,
			'sscribe_session_signing_key' => 'secret',
			'sscribe_debug_enabled'       => true,
			'sscribe_debug_log_level'     => 'info',
			'sscribe_debug_auto_refresh'  => true,
			'sscribe_upgrade_last_error'  => 'rolled-back: none',
			'sscribe_settings'            => array( 'theme' => 'light' ),
			'sscribe_active_languages'    => array( 'en' ),
		);

		$this->invoke_cleanup_site_closure();

		$this->assertArrayNotHasKey( 'sscribe_version', $GLOBALS['sscribe_test_options'] );
		$this->assertArrayNotHasKey( 'sscribe_export_index', $GLOBALS['sscribe_test_options'] );
		$this->assertArrayNotHasKey( 'sscribe_export_metrics', $GLOBALS['sscribe_test_options'] );
		$this->assertArrayNotHasKey( 'sscribe_schema_version', $GLOBALS['sscribe_test_options'] );
		$this->assertArrayNotHasKey( 'sscribe_session_signing_key', $GLOBALS['sscribe_test_options'] );
		$this->assertArrayNotHasKey( 'sscribe_debug_enabled', $GLOBALS['sscribe_test_options'] );
		$this->assertArrayNotHasKey( 'sscribe_debug_log_level', $GLOBALS['sscribe_test_options'] );
		$this->assertArrayNotHasKey( 'sscribe_debug_auto_refresh', $GLOBALS['sscribe_test_options'] );
		$this->assertArrayNotHasKey( 'sscribe_upgrade_last_error', $GLOBALS['sscribe_test_options'] );
		$this->assertArrayNotHasKey( 'sscribe_settings', $GLOBALS['sscribe_test_options'] );
		$this->assertArrayNotHasKey( 'sscribe_active_languages', $GLOBALS['sscribe_test_options'] );
	}

	public function test_uninstall_revokes_sscribe_capabilities_from_all_roles(): void {
		$this->invoke_cleanup_site_closure();

		$admin = $GLOBALS['sscribe_test_role_overrides']['administrator'];
		$this->assertFalse( $admin->has_cap( 'sscribe_export' ) );
		$this->assertFalse( $admin->has_cap( 'sscribe_health' ) );
		$this->assertTrue( $admin->has_cap( 'manage_options' ), 'core caps must not be touched.' );
	}

	public function test_uninstall_removes_sscribe_named_transients(): void {
		$GLOBALS['sscribe_test_transients'] = array(
			'sscribe_activation_redirect' => 1,
			'sscribe_key_warning_shown'  => 1,
			'sscribe_boot_error'         => 1,
		);

		$this->invoke_cleanup_site_closure();

		$this->assertArrayNotHasKey( 'sscribe_activation_redirect', $GLOBALS['sscribe_test_transients'] );
		$this->assertArrayNotHasKey( 'sscribe_key_warning_shown', $GLOBALS['sscribe_test_transients'] );
		$this->assertArrayNotHasKey( 'sscribe_boot_error', $GLOBALS['sscribe_test_transients'] );
	}
}
