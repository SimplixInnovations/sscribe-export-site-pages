<?php
/**
 * Tests for SScribe_Page_Collector::get_post_status_counts.
 *
 * @package SScribe\Tests
 */

declare(strict_types=1);

namespace SScribe\Tests;

use PHPUnit\Framework\TestCase;
use SScribe_Page_Collector;

final class SScribe_Page_Collector_StatusCounts_Test extends TestCase {

	/**
	 * Collector instance.
	 */
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

	public function test_language_parameter_accepted(): void {
		// With WPML not active, language parameter should still be accepted without error.
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
