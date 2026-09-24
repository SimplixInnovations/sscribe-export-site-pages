<?php
/**
 * Real-WordPress query-budget regressions for the final audit closure.
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

require_once __DIR__ . '/SScribe_WP_TestCase.php';

final class SScribe_Agent_Audit_Performance_Test extends SScribe_WP_TestCase {

	public function set_up(): void {
		parent::set_up();
		SScribe_Activator::activate( false );
		$admin_user_id = (int) $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_user_id );
	}

	public function test_cached_readability_filter_stays_bounded(): void {
		global $wpdb;

		$ids = array();
		for ( $i = 0; $i < 80; ++$i ) {
			$ids[] = (int) $this->factory()->post->create(
				array(
					'post_type'   => 'page',
					'post_status' => 'draft',
					'post_title'  => 'SScribe readability budget ' . $i,
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
			'Cached permission filtering must bulk-prime post objects rather than issue one query per ID. Queries: ' . $delta
		);
	}

	public function test_nonpublic_status_counts_have_a_bounded_query_budget(): void {
		global $wpdb;

		for ( $i = 0; $i < 120; ++$i ) {
			$this->factory()->post->create(
				array(
					'post_type'   => 'page',
					'post_status' => 'draft',
					'post_title'  => 'SScribe status-count budget ' . $i,
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
			'Status counts must use bounded batched queries rather than an N+1 per post. Queries: ' . $delta
		);
	}

	public function test_all_status_count_is_correct_and_bounded(): void {
		global $wpdb;

		foreach ( array( 'publish', 'draft', 'private', 'pending' ) as $status ) {
			for ( $i = 0; $i < 20; ++$i ) {
				$this->factory()->post->create(
					array(
						'post_type'   => 'page',
						'post_status' => $status,
						'post_title'  => 'SScribe all-status budget ' . $status . ' ' . $i,
					)
				);
			}
		}

		$collector = new SScribe_Page_Collector();
		$before    = (int) $wpdb->num_queries;
		$total     = $collector->get_page_count_only( '', 'all', 'page' );
		$delta     = (int) $wpdb->num_queries - $before;

		$this->assertGreaterThanOrEqual( 80, $total );
		$this->assertLessThan(
			30,
			$delta,
			'All-status count must remain bounded across the canonical status set. Queries: ' . $delta
		);
	}

	public function test_export_history_rows_are_batch_loaded(): void {
		global $wpdb;

		$index = array();
		for ( $i = 0; $i < 30; ++$i ) {
			$filename = sprintf( 'agent-audit-history-%02d.zip', $i );
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
		$this->assertLessThan( 8, $delta, 'History loading must use one bounded options query. Queries: ' . $delta );

		foreach ( $index as $filename ) {
			delete_option( 'sscribe_export_row_' . md5( $filename ) );
		}
		delete_option( 'sscribe_export_index' );
	}
}
