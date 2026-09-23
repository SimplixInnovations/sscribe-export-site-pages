<?php
/**
 * Real-WordPress integration tests for SScribe activation.
 *
 * These tests exercise the plugin's activator against a genuine wpdb
 * (via the SQLite Database Integration drop-in). They cover:
 *   - the three tables created by dbDelta during activation,
 *   - the two custom capabilities granted to the Administrator role,
 *   - the three cron events registered on first activation,
 *   - the side-effect-free re-activation (idempotent).
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

require_once __DIR__ . '/SScribe_WP_TestCase.php';

final class SScribe_Activation_Test extends SScribe_WP_TestCase {

	/**
	 * Run the plugin's activation callback before each test.
	 *
	 * WP_UnitTestCase::set_up() already swaps the wpdb, installs roles, and
	 * primes the schema; we just need to invoke our activator. Calling it
	 * directly avoids depending on `register_activation_hook()` (which only
	 * fires when a real admin clicks "Activate" in wp-admin).
	 */
	public function set_up(): void {
		parent::set_up();
		SScribe_Activator::activate( false );
	}

	public function test_activation_creates_export_logs_table(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'sscribe_export_logs';

		// INFORMATION_SCHEMA.columns (the SQLite Database Integration drop-in's
		// virtual table) is the only schema-introspection path that works on
		// the SQLite drop-in. PRAGMA table_info() throws inside the drop-in.
		$this->assertTableExists( $table );

		$columns = $this->getTableColumns( $table );
		$this->assertContains( 'id', $columns );
		$this->assertContains( 'timestamp', $columns );
		$this->assertContains( 'level', $columns );
		$this->assertContains( 'message', $columns );
		$this->assertContains( 'context', $columns );
		$this->assertContains( 'session_id', $columns );
		$this->assertContains( 'user_id', $columns );
		$this->assertContains( 'request_id', $columns );
		$this->assertContains( 'memory_usage', $columns );
	}

	public function test_activation_creates_export_stats_table(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'sscribe_export_stats';
		$this->assertTableExists( $table );

		$columns = $this->getTableColumns( $table );
		$this->assertContains( 'id', $columns );
		$this->assertContains( 'export_session_id', $columns );
		$this->assertContains( 'user_id', $columns );
		$this->assertContains( 'export_date', $columns );
		$this->assertContains( 'total_pages', $columns );
		$this->assertContains( 'successful_pages', $columns );
		$this->assertContains( 'failed_pages', $columns );
		$this::assertContains( 'formats', $columns );
		$this->assertContains( 'status', $columns );
		$this->assertContains( 'file_size_mb', $columns );
		$this->assertContains( 'duration_seconds', $columns );
		$this->assertContains( 'created_at', $columns );
	}

	public function test_activation_creates_audit_log_table(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'sscribe_audit_log';
		$this->assertTableExists( $table );

		$columns = $this->getTableColumns( $table );
		$this->assertContains( 'id', $columns );
		$this::assertContains( 'timestamp', $columns );
		$this->assertContains( 'event', $columns );
		$this->assertContains( 'user_id', $columns );
		$this->assertContains( 'ip_address', $columns );
		$this::assertContains( 'user_agent', $columns );
		$this->assertContains( 'request_uri', $columns );
		$this::assertContains( 'context', $columns );
		$this->assertContains( 'session_id', $columns );
	}

	public function test_activation_grants_sscribe_export_to_administrator(): void {
		$role = get_role( 'administrator' );
		$this::assertNotNull( $role, 'Administrator role must exist after WP install.' );
		$this::assertTrue(
			$role->has_cap( 'sscribe_export' ),
			'Administrator must receive the sscribe_export capability on activation.'
		);
	}

	public function test_activation_grants_sscribe_health_to_administrator(): void {
		$role = get_role( 'administrator' );
		$this::assertNotNull( $role );
		$this::assertTrue(
			$role->has_cap( 'sscribe_health' ),
			'Administrator must receive the sscribe_health capability on activation.'
		);
	}

	public function test_activation_does_not_grant_caps_to_subscriber(): void {
		$role = get_role( 'subscriber' );
		$this::assertNotNull( $role );
		$this::assertFalse( $role->has_cap( 'sscribe_export' ) );
		$this::assertFalse( $role->has_cap( 'sscribe_health' ) );
	}

	public function test_activation_schedules_three_cron_events(): void {
		// WP_UnitTestCase::set_up() runs WP's own test install, which clears
		// cron. Schedule fresh ones via the activator (already done in setUp).

		$this::assertNotFalse(
			wp_next_scheduled( 'sscribe_cleanup_exports' ),
			'sscribe_cleanup_exports cron must be scheduled.'
		);
		$this::assertNotFalse(
			wp_next_scheduled( 'sscribe_cleanup_sessions' ),
			'sscribe_cleanup_sessions cron must be scheduled.'
		);
		$this::assertNotFalse(
			wp_next_scheduled( 'sscribe_cleanup_audit_trail' ),
			'sscribe_cleanup_audit_trail cron must be scheduled.'
		);
	}

	public function test_activation_is_idempotent(): void {
		// Re-running activation must not throw, must not duplicate cron
		// entries, and must not strip granted caps.
		SScribe_Activator::activate( false );

		// wp_next_scheduled() is the canonical "what's currently booked"
		// check; wp_get_ready_cron_jobs() returns only events that are
		// already due, so freshly-scheduled future events look absent and
		// would false-fail this assertion. We assert "scheduled once per
		// hook" via the timestamp being non-false.
		$hooks = array(
			'sscribe_cleanup_exports',
			'sscribe_cleanup_sessions',
			'sscribe_cleanup_audit_trail',
		);
		foreach ( $hooks as $hook ) {
			$this::assertNotFalse(
				wp_next_scheduled( $hook ),
				"Re-activation must keep {$hook} scheduled exactly once."
			);
		}

		// And we verify there isn't a duplicate by inspecting the cron
		// option directly. WordPress stores cron as `[timestamp => [hook
		// => [hash => [schedule, args, interval]]]]` — outer keys are
		// timestamps, inner keys are hook names. Each hook must therefore
		// appear under exactly one timestamp with one hash entry.
		$cron_option = get_option( 'cron' );
		$this::assertIsArray( $cron_option, 'The cron option must be an array after activation.' );
		foreach ( $hooks as $hook ) {
			$occurrences = 0;
			foreach ( (array) $cron_option as $timestamp => $events ) {
				if ( ! is_array( $events ) ) {
					continue;
				}
				if ( isset( $events[ $hook ] ) && is_array( $events[ $hook ] ) ) {
					++$occurrences;
				}
			}
			$this::assertSame(
				1,
				$occurrences,
				"Hook {$hook} must be scheduled exactly once (no duplicates) after re-activation."
			);
		}

		$role = get_role( 'administrator' );
		$this::assertTrue( $role->has_cap( 'sscribe_export' ) );
		$this::assertTrue( $role->has_cap( 'sscribe_health' ) );

		$this::assertNotEmpty( get_option( 'sscribe_version' ) );
	}

	public function test_upgrader_converges_legacy_schema_on_sqlite_without_mysql_ddl(): void {
		$this::assertTrue(
			defined( 'DB_ENGINE' ) && 'sqlite' === strtolower( (string) DB_ENGINE ),
			'This integration regression must execute against the SQLite Database Integration driver.'
		);

		$reflection = new \ReflectionClass( SScribe_Upgrader::class );
		$method     = $reflection->getMethod( 'run_migrations' );
		$method->setAccessible( true );
		$method->invoke( null, '1.0.0' );

		global $wpdb;
		$logs_columns  = $this->getTableColumns( $wpdb->prefix . 'sscribe_export_logs' );
		$stats_columns = $this->getTableColumns( $wpdb->prefix . 'sscribe_export_stats' );
		$this::assertContains( 'session_id', $logs_columns );
		$this::assertContains( 'export_session_id', $stats_columns );
		$this::assertContains( 'status', $stats_columns );
	}

	public function test_activation_records_version_option(): void {
		$option = get_option( 'sscribe_version' );
		$this::assertNotEmpty( $option, 'sscribe_version option must be set on activation.' );
		$this::assertSame(
			SSCRIBE_VERSION,
			$option,
			'sscribe_version option must equal the plugin version constant.'
		);
	}

	/**
	 * Assert that a database table exists by running a structural query
	 * against INFORMATION_SCHEMA (which the SQLite Database Integration
	 * drop-in supports, unlike PRAGMA which throws an error).
	 */
	private function assertTableExists( string $table ): void {
		global $wpdb;

		// INFORMATION_SCHEMA.columns is a virtual table inside the
		// SQLite Database Integration drop-in. Counting rows for the
		// table name confirms both existence and that the table has
		// columns (otherwise dbDelta would have created an empty stub).
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$column_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM information_schema.columns WHERE table_name = %s',
				$table
			)
		);
		// phpcs:enable

		$this::assertGreaterThan(
			0,
			$column_count,
			"Table {$table} must exist with at least one column after activation."
		);
	}

	/**
	 * Get the list of column names for a table via INFORMATION_SCHEMA.
	 * Returns a flat array of column names.
	 *
	 * @return string[]
	 */
	private function getTableColumns( string $table ): array {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT column_name FROM information_schema.columns WHERE table_name = %s',
				$table
			)
		);
		// phpcs:enable

		return array_map( 'strval', (array) $rows );
	}
}
