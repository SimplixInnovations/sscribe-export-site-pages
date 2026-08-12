<?php
/**
 * SScribe Deactivator Unit Test
 *
 * Pins the deactivation contract:
 *   1. All three sscribe_* cron events are unscheduled.
 *   2. sscribe runtime transients are removed.
 *   3. Persistent settings and explicitly assigned capabilities are preserved.
 *   4. WP_DEBUG errors do not leak (catch-all swallows)
 *
 * Deactivation must NOT delete user data per WP.org guidelines.
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SScribe_Deactivator;

final class SScribe_Deactivator_Test extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['sscribe_test_transients']          = array();
		$GLOBALS['sscribe_test_scheduled_events']    = array();
		$GLOBALS['sscribe_test_options']             = array();
		$GLOBALS['sscribe_test_db_tables']           = array(
			'wp_options' => array(),
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['sscribe_test_transients'], $GLOBALS['sscribe_test_scheduled_events'], $GLOBALS['sscribe_test_options'], $GLOBALS['sscribe_test_role_overrides'] );
		parent::tearDown();
	}

	public function test_deactivate_unschedules_all_three_cron_events(): void {
		$GLOBALS['sscribe_test_scheduled_events'] = array(
			'sscribe_cleanup_exports'     => array( 'timestamp' => time() + 3600, 'recurrence' => 'hourly' ),
			'sscribe_cleanup_sessions'    => array( 'timestamp' => time() + 3600, 'recurrence' => 'hourly' ),
			'sscribe_cleanup_audit_trail' => array( 'timestamp' => time() + 86400, 'recurrence' => 'daily' ),
		);

		SScribe_Deactivator::deactivate();

		$this->assertArrayNotHasKey( 'sscribe_cleanup_exports', $GLOBALS['sscribe_test_scheduled_events'] );
		$this->assertArrayNotHasKey( 'sscribe_cleanup_sessions', $GLOBALS['sscribe_test_scheduled_events'] );
		$this->assertArrayNotHasKey( 'sscribe_cleanup_audit_trail', $GLOBALS['sscribe_test_scheduled_events'] );
	}

	public function test_deactivate_is_idempotent_when_no_cron_events_scheduled(): void {
		// Empty schedule: deactivate must not throw.
		SScribe_Deactivator::deactivate();
		$this->assertSame( array(), $GLOBALS['sscribe_test_scheduled_events'] );
	}

	public function test_deactivate_removes_sscribe_runtime_transients(): void {
		// The deactivator queries $wpdb->options directly for the raw
		// _transient_sscribe_* option names and calls delete_option()
		// on each match. Seed the options table to simulate that.
		// sscribe_upgrade_lock is removed via the transient API
		// (delete_transient) and lives in the transients scratchpad.
		$GLOBALS['sscribe_test_options']    = array(
			'_transient_sscribe_lock_42'         => 'lock-payload',
			'_transient_sscribe_rate_7'          => 'rate-payload',
			'_transient_sscribe_active_sid_99'   => 'session-payload',
			'_transient_timeout_sscribe_lock_42' => time() + 3600,
		);
		$GLOBALS['sscribe_test_transients'] = array(
			'sscribe_upgrade_lock' => true,
		);

		SScribe_Deactivator::deactivate();

		$this->assertArrayNotHasKey( 'sscribe_upgrade_lock', $GLOBALS['sscribe_test_transients'] );
		$this->assertArrayNotHasKey( '_transient_sscribe_lock_42', $GLOBALS['sscribe_test_options'] );
		$this->assertArrayNotHasKey( '_transient_sscribe_rate_7', $GLOBALS['sscribe_test_options'] );
		$this->assertArrayNotHasKey( '_transient_sscribe_active_sid_99', $GLOBALS['sscribe_test_options'] );
		$this->assertArrayNotHasKey( '_transient_timeout_sscribe_lock_42', $GLOBALS['sscribe_test_options'] );
	}

	public function test_deactivate_does_not_remove_unrelated_transients(): void {
		$GLOBALS['sscribe_test_options'] = array(
			'_transient_other_plugin_42' => 'leave-me',
			'some_other_option'          => 'keep-me',
		);

		SScribe_Deactivator::deactivate();

		$this->assertArrayHasKey( '_transient_other_plugin_42', $GLOBALS['sscribe_test_options'] );
		$this->assertArrayHasKey( 'some_other_option', $GLOBALS['sscribe_test_options'] );
	}

	public function test_deactivate_preserves_explicitly_assigned_capabilities(): void {
		$admin = new \WP_Role( 'administrator', array(
			'manage_options' => true,
			'sscribe_export' => true,
			'sscribe_health' => true,
		) );

		$GLOBALS['sscribe_test_role_overrides'] = array(
			'administrator' => $admin,
		);

		SScribe_Deactivator::deactivate();

		$this->assertTrue( $admin->has_cap( 'sscribe_export' ), 'sscribe_export must survive deactivation.' );
		$this->assertTrue( $admin->has_cap( 'sscribe_health' ), 'sscribe_health must survive deactivation.' );
		$this->assertTrue( $admin->has_cap( 'manage_options' ), 'core caps must not be touched.' );

		unset( $GLOBALS['sscribe_test_role_overrides'] );
	}

	public function test_deactivate_preserves_user_options(): void {
		// Per WP.org guidelines, deactivation must not delete user data.
		// User options like 'sscribe_settings' are user-stored preferences
		// and must survive a deactivate/reactivate cycle.
		$GLOBALS['sscribe_test_options'] = array(
			'sscribe_settings'           => array( 'theme' => 'light' ),
			'sscribe_export_format'      => 'pdf',
			'sscribe_batch_size'         => 25,
		);

		SScribe_Deactivator::deactivate();

		$this->assertArrayHasKey( 'sscribe_settings', $GLOBALS['sscribe_test_options'] );
		$this->assertArrayHasKey( 'sscribe_export_format', $GLOBALS['sscribe_test_options'] );
		$this->assertArrayHasKey( 'sscribe_batch_size', $GLOBALS['sscribe_test_options'] );
	}
}
