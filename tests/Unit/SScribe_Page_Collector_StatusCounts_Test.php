<?php
/**
 * SScribe Page Collector Status Counts Unit Test
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests;

use PHPUnit\Framework\TestCase;
use SScribe_Page_Collector;

final class SScribe_Page_Collector_StatusCounts_Test extends TestCase {



	private SScribe_Page_Collector $collector;

	protected function setUp(): void {
		$this->collector = new SScribe_Page_Collector();
	}

	public function test_returns_all_status_keys(): void {
		$counts = $this->collector->get_post_status_counts();

		$this->assertArrayHasKey( 'publish', $counts );
		$this->assertArrayHasKey( 'draft', $counts );
		$this->assertArrayHasKey( 'pending', $counts );
		$this->assertArrayHasKey( 'private', $counts );
		$this->assertArrayHasKey( 'all', $counts );
	}

	public function test_all_is_sum_of_statuses(): void {
		$counts     = $this->collector->get_post_status_counts();
		$individual = $counts['publish']
			+ $counts['draft']
			+ $counts['pending']
			+ $counts['private'];

		$this->assertEquals( $individual, $counts['all'] );
	}

	public function test_counts_are_integers(): void {
		$counts = $this->collector->get_post_status_counts();

		foreach ( $counts as $count ) {
			$this->assertIsInt( $count );
			$this->assertGreaterThanOrEqual( 0, $count );
		}
	}

	public function test_returns_array(): void {
		$counts = $this->collector->get_post_status_counts();
		$this->assertIsArray( $counts );
	}

	public function test_page_count_all_uses_bounded_aggregate_path_before_query_normalization(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/class-sscribe-page-collector.php' );
		$method_start = strpos( $source, 'public function get_page_count_only(' );
		$method_end   = strpos( $source, 'private function get_permalink_cached(', $method_start );
		$this->assertNotFalse( $method_start );
		$this->assertNotFalse( $method_end );
		$method = substr( $source, (int) $method_start, (int) $method_end - (int) $method_start );

		$this->assertStringContainsString(
			"return \$this->count_readable_posts_across_statuses( \$language, \$post_type );",
			$method
		);
		$this->assertStringNotContainsString( 'get_post_status_counts( $language, $post_type )', $method );
		$this->assertLessThan(
			strpos( $method, '$post_status = $this->validate_post_status( $post_status );' ),
			strpos( $method, "if ( 'all' === sanitize_key( \$post_status ) )" ),
			'The all-status sentinel must be handled before normal query-status normalization.'
		);
	}


	public function test_language_parameter_accepted(): void {

		$counts = $this->collector->get_post_status_counts( 'en' );
		$this->assertIsArray( $counts );
		$this->assertArrayHasKey( 'all', $counts );
	}

	public function test_empty_language_returns_same_structure(): void {
		$default = $this->collector->get_post_status_counts();
		$empty   = $this->collector->get_post_status_counts( '' );

		$this->assertEquals( array_keys( $default ), array_keys( $empty ) );
	}
}
