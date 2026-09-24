<?php
/**
 * Real-WordPress regression tests for cross-database schema upgrades.
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

require_once __DIR__ . '/SScribe_WP_TestCase.php';

final class SScribe_Upgrader_SQLite_Test extends SScribe_WP_TestCase {

	public function test_old_log_schema_converges_on_sqlite_without_mysql_introspection(): void {
		global $wpdb;

		$original_prefix = $wpdb->prefix;
		$test_prefix     = $original_prefix . 'sscribe_upg_' . substr( md5( (string) microtime( true ) ), 0, 8 ) . '_';
		$wpdb->prefix    = $test_prefix;

		$table_logs  = $test_prefix . 'sscribe_export_logs';
		$table_stats = $test_prefix . 'sscribe_export_stats';
		$table_audit = $test_prefix . 'sscribe_audit_log';

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$old_sql = "CREATE TABLE $table_logs (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			timestamp DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			level VARCHAR(20) NOT NULL,
			message TEXT NOT NULL,
			context LONGTEXT,
			user_id BIGINT UNSIGNED,
			request_id VARCHAR(12),
			memory_usage VARCHAR(20),
			PRIMARY KEY  (id),
			KEY idx_timestamp (timestamp),
			KEY idx_level (level),
			KEY idx_user_id (user_id),
			KEY idx_request_id (request_id)
		) $charset;";

		try {
			dbDelta( $old_sql );

			$before = $wpdb->get_results( "PRAGMA table_info('$table_logs')", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Isolated SQLite test table name generated internally.
			$before_names = array_column( (array) $before, 'name' );
			$this->assertNotContains( 'session_id', $before_names, 'Fixture must start from the historical schema.' );

			$method = new ReflectionMethod( SScribe_Upgrader::class, 'run_migrations' );
			$method->setAccessible( true );
			$method->invoke( null, '2.0.3' );

			$columns = $wpdb->get_results( "PRAGMA table_info('$table_logs')", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Isolated SQLite test table name generated internally.
			$names   = array_column( (array) $columns, 'name' );
			$this->assertContains( 'session_id', $names, 'dbDelta migration must add the current session_id column on SQLite.' );

			$indexes = $wpdb->get_results( "PRAGMA index_list('$table_logs')", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Isolated SQLite test table name generated internally.
			$index_names = array_column( (array) $indexes, 'name' );
			$this->assertContains( 'idx_session_id', $index_names, 'dbDelta migration must add idx_session_id on SQLite.' );
		} finally {
			foreach ( array( $table_logs, $table_stats, $table_audit ) as $table ) {
				$wpdb->query( "DROP TABLE IF EXISTS $table" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Internal isolated test tables only.
			}
			$wpdb->prefix = $original_prefix;
		}
	}
}
