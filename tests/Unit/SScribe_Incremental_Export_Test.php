<?php
/**
 * Incremental export (modified-since) tests.
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SScribe_Export_Job;
use SScribe_Page_Collector;

final class SScribe_Incremental_Export_Test extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['sscribe_test_wp_query_chunks']    = array( array( 11, 12 ) );
		$GLOBALS['sscribe_test_wp_query_calls']     = 0;
		$GLOBALS['sscribe_test_wp_query_last_args'] = null;
		$GLOBALS['sscribe_test_transients']         = array();
	}

	protected function tearDown(): void {
		unset(
			$GLOBALS['sscribe_test_wp_query_chunks'],
			$GLOBALS['sscribe_test_wp_query_calls'],
			$GLOBALS['sscribe_test_wp_query_last_args'],
			$GLOBALS['sscribe_test_transients']
		);
		remove_filter( 'sscribe_use_chunked_page_ids', '__return_false' );
		parent::tearDown();
	}

	public function test_job_accepts_modified_since_as_timestamp_or_iso_string(): void {
		$from_int = SScribe_Export_Job::from_array( array( 'modified_since' => 1791374400 ) );
		$this->assertSame( 1791374400, $from_int->modified_since );

		$from_iso = SScribe_Export_Job::from_array( array( 'modified_since' => '2026-10-07T12:00:00Z' ) );
		$this->assertSame( 1791374400, $from_iso->modified_since );

		$garbage = SScribe_Export_Job::from_array( array( 'modified_since' => 'not a date' ) );
		$this->assertSame( 0, $garbage->modified_since );

		$this->assertSame( 0, ( new SScribe_Export_Job() )->modified_since );
		$this->assertSame( '', ( new SScribe_Export_Job() )->schedule_id );

		$scheduled = SScribe_Export_Job::from_array( array( 'schedule_id' => 'Nightly Pages!' ) );
		$this->assertSame( 'nightlypages', $scheduled->schedule_id );
	}

	public function test_direct_query_adds_a_modified_after_clause_in_gmt(): void {
		add_filter( 'sscribe_use_chunked_page_ids', '__return_false' );
		$collector = new SScribe_Page_Collector();

		$ids = $collector->get_page_ids( '', 'publish', 'page', -1, 1791374400 );

		$this->assertSame( array( 11, 12 ), $ids );
		$args = $GLOBALS['sscribe_test_wp_query_last_args'];
		$this->assertIsArray( $args );
		$this->assertSame(
			array(
				array(
					'column'    => 'post_modified_gmt',
					'after'     => '2026-10-07 12:00:00',
					'inclusive' => false,
				),
			),
			$args['date_query']
		);
	}

	public function test_query_without_watermark_has_no_date_clause(): void {
		add_filter( 'sscribe_use_chunked_page_ids', '__return_false' );
		$collector = new SScribe_Page_Collector();

		$collector->get_page_ids( '', 'publish', 'page', -1 );

		$this->assertArrayNotHasKey( 'date_query', $GLOBALS['sscribe_test_wp_query_last_args'] );
	}

	public function test_chunked_query_adds_the_same_clause(): void {
		$collector = new SScribe_Page_Collector();

		foreach ( $collector->get_page_ids_chunked( '', 'publish', 'page', 100, 1791374400 ) as $chunk ) {
			$this->assertSame( array( 11, 12 ), $chunk );
			break;
		}

		$this->assertSame( '2026-10-07 12:00:00', $GLOBALS['sscribe_test_wp_query_last_args']['date_query'][0]['after'] );
	}

	public function test_watermark_is_part_of_the_page_id_cache_key(): void {
		add_filter( 'sscribe_use_chunked_page_ids', '__return_false' );
		$collector = new SScribe_Page_Collector();

		$collector->get_page_ids( '', 'publish', 'page', -1, 1791374400 );
		$GLOBALS['sscribe_test_wp_query_chunks'] = array( array( 99 ) );
		$GLOBALS['sscribe_test_wp_query_calls']  = 0;
		$fresh = $collector->get_page_ids( '', 'publish', 'page', -1, 1791374500 );

		$this->assertSame( array( 99 ), $fresh, 'A different watermark must not be served from the earlier cache entry.' );
	}
}
