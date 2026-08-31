<?php
/**
 * Real-WordPress integration tests for SScribe cron handlers.
 *
 * Each cleanup hook is fired directly via `do_action()` (no need to
 * wait for real cron) and the post-state of the system is asserted
 * against real tables, real transients, and the real private-storage
 * directory.
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

require_once __DIR__ . '/SScribe_WP_TestCase.php';

final class SScribe_Cron_Test extends SScribe_WP_TestCase {

	public function set_up(): void {
		parent::set_up();
		SScribe_Activator::activate( false );
	}

	/**
	 * Seed an old ZIP in the private export directory and assert the
	 * `sscribe_cleanup_exports` handler deletes it.
	 */
	public function test_cleanup_exports_event_deletes_old_zip(): void {
		$export_dir = SScribe_Private_Storage::get_export_dir();
		$this::assertNotSame( '', $export_dir, 'Private export dir must be available.' );
		$this::assertDirectoryExists( $export_dir );

		// Seed an old ZIP — 5 days old. The cleanup handler deletes
		// files older than 3 days (`$max_age = 3 * DAY_IN_SECONDS` in
		// SScribe_Zip_Handler::cleanup_expired).
		$old_zip    = $export_dir . '/cron-test-old.zip';
		file_put_contents( $old_zip, "old export contents\n" );
		touch( $old_zip, time() - 5 * DAY_IN_SECONDS );
		$this::assertFileExists( $old_zip, 'Old seeded ZIP must exist before cleanup runs.' );

		// Seed a fresh ZIP we expect NOT to be cleaned up.
		$fresh_zip    = $export_dir . '/cron-test-fresh.zip';
		file_put_contents( $fresh_zip, "fresh export contents\n" );
		$this::assertFileExists( $fresh_zip, 'Fresh seeded ZIP must exist before cleanup runs.' );

		// Fire the cron.
		do_action( 'sscribe_cleanup_exports' );

		$this::assertFileDoesNotExist(
			$old_zip,
			'Cleanup cron must delete a ZIP older than the retention window.'
		);
		$this::assertFileExists(
			$fresh_zip,
			'Cleanup cron must leave fresh ZIPs alone.'
		);

		// Tidy up so other tests don't trip on our seed.
		if ( file_exists( $fresh_zip ) ) {
			unlink( $fresh_zip );
		}
	}

	/**
	 * Seed an expired session via SScribe_Session::create() (with test
	 * mode bypassing AES encryption) and assert the
	 * `sscribe_cleanup_sessions` handler prunes it.
	 */
	public function test_cleanup_sessions_event_prunes_expired_sessions(): void {
		global $wpdb;

		// Bypass AES encryption so we can seed without the signing-key dance.
		if ( method_exists( '\SScribe_Session', 'enable_test_mode' ) ) {
			\SScribe_Session::enable_test_mode();
		}

		$session = new \SScribe_Session();

		$session_id = $session->create(
			array(
				'user_id'    => 1,
				'formats'    => array( 'pdf' ),
				'language'   => 'all',
				'post_type'  => 'page',
				'export_dir' => SScribe_Private_Storage::get_export_dir(),
				'status'     => 'completed',
			)
		);
		$this::assertNotEmpty( $session_id, 'SScribe_Session::create must return a 16-hex session id.' );

		// Backdate the created_at / updated_at so the cron sees a stale session.
		$option_name = 'sscribe_session_' . $session_id;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$current = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
				$option_name
			)
		);
		$this::assertNotNull( $current, 'Session option must exist after create().' );

		$decoded = $session->get( $session_id );
		$this::assertIsArray( $decoded, 'Session must be readable in test mode.' );

		$stale = time() - ( 48 * HOUR_IN_SECONDS );
		$decoded['created_at'] = $stale;
		$decoded['updated_at'] = $stale;
		$decoded['status']     = 'completed';

		// Rewrite the option with the backdated payload. Cleanup expects
		// either serialized or encrypted; test-mode stores as plain JSON.
		update_option( $option_name, wp_json_encode( $decoded ), 'no' );

		do_action( 'sscribe_cleanup_sessions' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$after = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
				$option_name
			)
		);
		$this::assertNull(
			$after,
			'Cleanup cron must delete session options older than the retention window.'
		);
	}

	/**
	 * Seed an old audit_log row and assert the `sscribe_cleanup_audit_trail`
	 * handler deletes it (its inner call is
	 * `SScribe_Audit_Trail::cleanup( 90 )`).
	 */
	public function test_cleanup_audit_trail_event_prunes_old_rows(): void {
		global $wpdb;

		require_once SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-audit-trail.php';

		$audit_table = $wpdb->prefix . 'sscribe_audit_log';

		// Seed an entry timestamped 100 days ago — beyond the 90-day
		// retention window used by SScribe::cleanup_audit_trail.
		$old_timestamp = gmdate( 'Y-m-d H:i:s', time() - ( 100 * DAY_IN_SECONDS ) );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			$audit_table,
			array(
				'event'      => 'cron_test_old_event',
				'user_id'    => 1,
				'timestamp'  => $old_timestamp,
				'ip_address' => '127.0.0.1',
				'context'    => maybe_serialize( array( 'cron_test' => true ) ),
			),
			array( '%s', '%d', '%s', '%s', '%s' )
		);
		// phpcs:enable

		$old_id = $wpdb->insert_id;
		$this::assertNotEmpty( $old_id, 'Old audit row insert must return an id.' );

		// Seed a fresh entry — within the retention window.
		$fresh_timestamp = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			$audit_table,
			array(
				'event'      => 'cron_test_fresh_event',
				'user_id'    => 1,
				'timestamp'  => $fresh_timestamp,
				'ip_address' => '127.0.0.1',
				'context'    => maybe_serialize( array( 'cron_test' => true ) ),
			),
			array( '%s', '%d', '%s', '%s', '%s' )
		);
		// phpcs:enable
		$fresh_id = $wpdb->insert_id;
		$this::assertNotEmpty( $fresh_id, 'Fresh audit row insert must return an id.' );

		do_action( 'sscribe_cleanup_audit_trail' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$old_row  = $wpdb->get_row(
			$wpdb->prepare( "SELECT id FROM {$audit_table} WHERE id = %d", $old_id )
		);
		$fresh_row = $wpdb->get_row(
			$wpdb->prepare( "SELECT id FROM {$audit_table} WHERE id = %d", $fresh_id )
		);
		// phpcs:enable

		$this::assertNull( $old_row, 'Cleanup cron must delete audit rows older than the retention window.' );
		$this::assertNotNull( $fresh_row, 'Cleanup cron must keep audit rows within the retention window.' );
	}

	/**
	 * Verify the full cron lifecycle: schedule → unschedule → reschedule.
	 *
	 * The "no duplicate on re-activation" property is already covered by
	 * {@see SScribe_Activation_Test::test_activation_is_idempotent()}.
	 * Here we exercise the canonical wp_clear_scheduled_hook /
	 * wp_schedule_event round-trip that deactivation + re-activation
	 * would trigger.
	 */
	public function test_unschedule_and_reschedule_roundtrip(): void {
		$hook = 'sscribe_cleanup_exports';
		$ts   = wp_next_scheduled( $hook );
		$this::assertNotFalse( $ts, 'Hook must be scheduled after setUp().' );

		// Unschedule.
		wp_clear_scheduled_hook( $hook );
		$this::assertFalse(
			wp_next_scheduled( $hook ),
			'wp_clear_scheduled_hook must remove the previously scheduled event.'
		);

		// Reschedule fresh. wp_schedule_event() in modern WP returns
		// `true` on success rather than the timestamp itself — the
		// contract we care about here is that wp_next_scheduled()
		// once again finds a future scheduled event.
		$rescheduled = wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', $hook );
		$this::assertNotFalse(
			$rescheduled,
			'wp_schedule_event must report success on reschedule.'
		);
		$this::assertNotFalse(
			wp_next_scheduled( $hook ),
			'Rescheduled hook must be discoverable via wp_next_scheduled().'
		);
	}
}