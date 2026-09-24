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

			$this->assertFalse(
				$this->column_exists( $table_logs, 'session_id' ),
				'Fixture must start from the historical schema.'
			);

			$method = new ReflectionMethod( SScribe_Upgrader::class, 'run_migrations' );
			$method->setAccessible( true );
			$method->invoke( null, '2.0.3' );

			$this->assertTrue(
				$this->column_exists( $table_logs, 'session_id' ),
				'dbDelta migration must add the current session_id column on SQLite.'
			);
			$this->assertTrue(
				$this->index_exists( $table_logs, 'idx_session_id' ),
				'dbDelta migration must add idx_session_id on SQLite.'
			);
		} finally {
			foreach ( array( $table_logs, $table_stats, $table_audit ) as $table ) {
				$wpdb->query( "DROP TABLE IF EXISTS $table" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Internal isolated test tables only.
			}
			$wpdb->prefix = $original_prefix;
		}
	}

	private function column_exists( string $table, string $column ): bool {
		global $wpdb;

		// WordPress SQLite Database Integration translates SHOW COLUMNS, while
		// MySQL/MariaDB support it natively. Test the same wpdb compatibility
		// surface that production WordPress code is expected to use.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Isolated plugin-owned test table.
		$rows = $wpdb->get_results( "SHOW COLUMNS FROM {$table}" );
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$name = (string) ( $row->Field ?? $row->field ?? $row->name ?? '' );
			if ( $column === $name ) {
				return true;
			}
		}
		return false;
	}

	private function index_exists( string $table, string $index ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Isolated plugin-owned test table.
		$rows = $wpdb->get_results( "SHOW INDEX FROM {$table}" );
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$name = (string) ( $row->Key_name ?? $row->key_name ?? $row->name ?? '' );
			if ( $index === $name ) {
				return true;
			}
		}
		return false;
	}
}
