<?php
/**
 * Real-WordPress/SQLite schema-upgrade convergence regression.
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

require_once __DIR__ . '/SScribe_WP_TestCase.php';

final class SScribe_Upgrader_SQLite_Convergence_Test extends SScribe_WP_TestCase {

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_dbdelta_upgrade_converges_legacy_log_schema_on_sqlite(): void {
		global $wpdb;

		$db_class = get_class( $wpdb );
		if ( false === stripos( $db_class, 'sqlite' ) ) {
			$this::markTestSkipped( 'This regression specifically verifies the WordPress SQLite drop-in path.' );
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// Never mutate the canonical plugin tables in the shared WordPress test
		// process. The SQLite integration keeps internal schema-emulation state;
		// dropping/recreating the live table here poisoned later AJAX/session
		// tests even when the table was restored in finally.
		$original_prefix = $wpdb->prefix;
		$test_suffix     = substr( hash( 'sha256', __METHOD__ . '|' . microtime( true ) ), 0, 12 );
		$wpdb->prefix    = $original_prefix . 'sscribe_migration_' . $test_suffix . '_';
		$table           = $wpdb->prefix . 'sscribe_export_logs';

		try {
			$charset_collate = $wpdb->get_charset_collate();
			$legacy_sql      = "CREATE TABLE {$table} (
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
			) {$charset_collate};";
			dbDelta( $legacy_sql );

			$this::assertFalse( $this->column_exists( $table, 'session_id' ), 'Fixture must start without session_id.' );
			$this::assertFalse( $this->index_exists( $table, 'idx_session_id' ), 'Fixture must start without idx_session_id.' );

			$method = new ReflectionMethod( SScribe_Upgrader::class, 'run_migrations' );
			$method->setAccessible( true );
			$method->invoke( null, '2.0.3' );

			$this::assertTrue( $this->column_exists( $table, 'session_id' ), 'SQLite dbDelta migration must add session_id.' );
			$this::assertTrue( $this->index_exists( $table, 'idx_session_id' ), 'SQLite dbDelta migration must add idx_session_id.' );

			$inserted = $wpdb->insert(
				$table,
				array(
					'timestamp'  => current_time( 'mysql', true ),
					'level'      => 'info',
					'message'    => 'sqlite migration convergence probe',
					'session_id' => 'sqlite-proof',
				),
				array( '%s', '%s', '%s', '%s' )
			);
			$this::assertSame( 1, $inserted );
			$this::assertSame( '', (string) $wpdb->last_error );
		} finally {
			$wpdb->prefix = $original_prefix;
		}
	}

	private function column_exists( string $table, string $column ): bool {
		global $wpdb;
		// The official SQLite integration translates WordPress's SHOW syntax;
		// using wpdb here verifies the compatibility surface production sees.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Integration assertion against plugin-owned table.
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
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Integration assertion against plugin-owned table.
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
