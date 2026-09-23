<?php
/**
 * Real-WordPress regression coverage for SScribe database upgrades on SQLite.
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

require_once __DIR__ . '/SScribe_WP_TestCase.php';

final class SScribe_Upgrader_SQLite_Test extends SScribe_WP_TestCase {

	public function set_up(): void {
		parent::set_up();
		SScribe_Activator::activate( false );
		delete_option( 'sscribe_upgrade_last_error' );
		delete_option( 'sscribe_upgrade_failures' );
		delete_option( 'sscribe_upgrade_next_attempt' );
	}

	public function test_legacy_schema_version_converges_on_sqlite_without_mysql_only_ddl(): void {
		global $wpdb;

		$server_info = method_exists( $wpdb, 'db_server_info' )
			? strtolower( (string) $wpdb->db_server_info() )
			: '';
		$class_name = strtolower( get_class( $wpdb ) );

		if ( ! str_contains( $server_info, 'sqlite' ) && ! str_contains( $class_name, 'sqlite' ) ) {
			$this::markTestSkipped( 'SQLite-only migration portability regression.' );
		}

		update_option( 'sscribe_schema_version', '1.0.0', false );
		update_option( 'sscribe_version', '1.0.0', false );

		SScribe_Upgrader::maybe_upgrade();

		$this::assertSame(
			SSCRIBE_VERSION,
			(string) get_option( 'sscribe_schema_version' ),
			'SQLite upgrades must converge to the current schema version.'
		);
		$this::assertFalse(
			get_option( 'sscribe_upgrade_last_error', false ),
			'SQLite migration must not fall into the retry/error path because of MySQL-only introspection or ALTER syntax.'
		);
	}
}
