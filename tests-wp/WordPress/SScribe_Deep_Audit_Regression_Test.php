<?php
/**
 * Real-WordPress regressions from the final pre-submission deep audit.
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

require_once __DIR__ . '/SScribe_WP_TestCase.php';

final class SScribe_Deep_Audit_Regression_Test extends SScribe_WP_TestCase {

	public function set_up(): void {
		parent::set_up();
		SScribe_Activator::activate( false );
		$admin_user_id = (int) $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_user_id );
	}

	public function test_cached_readable_page_ids_do_not_degenerate_into_one_query_per_post(): void {
		global $wpdb;

		$ids = array();
		for ( $i = 0; $i < 80; ++$i ) {
			$ids[] = (int) $this->factory()->post->create(
				array(
					'post_type'   => 'page',
					'post_status' => 'draft',
					'post_title'  => 'SScribe cached readability ' . $i,
				)
			);
		}

		$collector = new SScribe_Page_Collector();
		$first     = $collector->get_page_ids( '', 'draft', 'page', -1 );
		$this->assertNotEmpty( array_intersect( $ids, $first ) );

		foreach ( $ids as $id ) {
			clean_post_cache( $id );
		}

		$before = (int) $wpdb->num_queries;
		$second = $collector->get_page_ids( '', 'draft', 'page', -1 );
		$delta  = (int) $wpdb->num_queries - $before;

		$this->assertNotEmpty( array_intersect( $ids, $second ) );
		$this->assertLessThan(
			20,
			$delta,
			'Permission filtering on a cached ID list must batch-prime post objects instead of issuing one get_post() query per ID. Queries: ' . $delta
		);
	}

	public function test_admin_status_counts_use_bounded_queries_on_large_nonpublic_inventory(): void {
		global $wpdb;

		for ( $i = 0; $i < 120; ++$i ) {
			$this->factory()->post->create(
				array(
					'post_type'   => 'page',
					'post_status' => 'draft',
					'post_title'  => 'SScribe status-count regression ' . $i,
				)
			);
		}

		$collector = new SScribe_Page_Collector();
		$before    = (int) $wpdb->num_queries;
		$counts    = $collector->get_post_status_counts( '', 'page' );
		$delta     = (int) $wpdb->num_queries - $before;

		$this->assertGreaterThanOrEqual( 120, (int) ( $counts['draft'] ?? 0 ) );
		$this->assertLessThan(
			30,
			$delta,
			'An administrator must use aggregate count queries instead of materializing every non-public post. Queries: ' . $delta
		);
	}

	public function test_export_history_rows_are_batch_loaded_from_options_table(): void {
		global $wpdb;

		$index = array();
		for ( $i = 0; $i < 30; ++$i ) {
			$filename = sprintf( 'deep-audit-history-%02d.zip', $i );
			$option   = 'sscribe_export_row_' . md5( $filename );
			$index[]  = $filename;
			update_option(
				$option,
				array(
					'user_id'    => get_current_user_id(),
					'created_at' => time() - $i,
					'dl_token'   => str_repeat( dechex( $i % 16 ), 32 ),
				),
				false
			);
			wp_cache_delete( $option, 'options' );
		}
		update_option( 'sscribe_export_index', $index, false );
		wp_cache_delete( 'sscribe_export_index', 'options' );

		$handler = new SScribe_Zip_Handler();
		$before  = (int) $wpdb->num_queries;
		$entries = $handler->list_export_entries();
		$delta   = (int) $wpdb->num_queries - $before;

		$this->assertCount( 30, $entries );
		$this->assertLessThan( 8, $delta, 'History row loading must use one bounded options query. Queries: ' . $delta );

		foreach ( $index as $filename ) {
			delete_option( 'sscribe_export_row_' . md5( $filename ) );
		}
		delete_option( 'sscribe_export_index' );
	}

	public function test_sqlite_old_schema_upgrade_reaches_current_version_without_mysql_only_ddl(): void {
		set_current_screen( 'dashboard' );
		delete_option( 'sscribe_upgrade_last_error' );
		delete_option( 'sscribe_upgrade_next_attempt' );
		delete_option( 'sscribe_upgrade_failures' );
		update_option( 'sscribe_schema_version', '1.0.0', false );

		SScribe_Upgrader::maybe_upgrade();

		$this->assertSame(
			SSCRIBE_VERSION,
			(string) get_option( 'sscribe_schema_version', '' ),
			'SQLite-backed upgrade must advance the schema version.'
		);
		$this->assertFalse(
			get_option( 'sscribe_upgrade_last_error', false ),
			'SQLite-backed upgrade must not fall into the recorded-error path.'
		);
	}
}
