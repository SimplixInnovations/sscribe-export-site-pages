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

	public function test_page_count_all_uses_aggregate_status_counts_before_query_normalization(): void {
		$collector = new class() extends SScribe_Page_Collector {
			public function get_post_status_counts( string $language = '', string $post_type = 'page' ): array {
				return array(
					'publish' => 5,
					'draft'   => 4,
					'private' => 3,
					'future'  => 2,
					'pending' => 1,
					'all'     => 15,
				);
			}

			public function get_page_ids( string $language = '', string $post_status = 'publish', string $post_type = 'page', int $limit = -1 ): array {
				return array( 999 );
			}
		};

		$this->assertSame( 15, $collector->get_page_count_only( '', 'all', 'page' ) );
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
