<?php
/**
 * SScribe Export Stats Unit Test
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

class SScribe_Export_Stats_Test extends TestCase {

	private \SScribe_Export_Stats $stats;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['sscribe_test_db_tables']['wp_sscribe_export_stats'] = array();
		$this->stats = new \SScribe_Export_Stats();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['sscribe_test_db_tables']['wp_sscribe_export_stats'] );
		parent::tearDown();
	}

	public function test_start_export_creates_record(): void {
		$result = $this->stats->start_export( 'session_1', 1, array(
			'total_pages' => 10,
			'formats'     => array( 'docx', 'pdf' ),
		) );
		$this->assertTrue( $result );
	}

	public function test_start_export_with_empty_config(): void {
		$result = $this->stats->start_export( 'session_2', 1, array() );
		$this->assertTrue( $result );
	}

	public function test_update_progress(): void {
		$this->stats->start_export( 'session_3', 1, array( 'total_pages' => 5 ) );
		$result = $this->stats->update_progress( 'session_3', 3, 1 );
		$this->assertTrue( $result );
	}

	public function test_complete_export(): void {
		$this->stats->start_export( 'session_4', 1, array( 'total_pages' => 5 ) );
		$result = $this->stats->complete_export( 'session_4', array(
			'successful_pages' => 4,
			'failed_pages'     => 1,
			'memory_peak'      => '32M',
			'duration'         => 12.5,
			'file_size_mb'     => 2.3,
		) );
		$this->assertTrue( $result );
	}

	public function test_fail_export(): void {
		$this->stats->start_export( 'session_5', 1, array( 'total_pages' => 5 ) );
		$result = $this->stats->fail_export( 'session_5', 'Out of memory' );
		$this->assertTrue( $result );
	}

	public function test_get_exports_by_user_returns_empty_for_invalid_user(): void {
		$result = $this->stats->get_exports_by_user( 0 );
		$this->assertIsArray( $result );
		$this->assertEmpty( $result );
	}

	public function test_get_exports_by_user_returns_results(): void {
		$this->stats->start_export( 'session_6', 1, array( 'total_pages' => 3 ) );
		$results = $this->stats->get_exports_by_user( 1 );
		$this->assertIsArray( $results );
		$this->assertCount( 1, $results );
	}

	public function test_erase_user_data_anonymizes(): void {
		$this->stats->start_export( 'session_7', 1, array( 'total_pages' => 3 ) );
		$count = $this->stats->erase_user_data( 1 );
		$this->assertEquals( 1, $count );
	}

	public function test_erase_user_data_zero_id_returns_zero(): void {
		$count = $this->stats->erase_user_data( 0 );
		$this->assertEquals( 0, $count );
	}

	public function test_cleanup_removes_old_records(): void {
		$this->stats->start_export( 'session_8', 1, array( 'total_pages' => 1 ) );
		$count = $this->stats->cleanup( 0 );
		$this->assertIsInt( $count );
	}

	public function test_get_stats_returns_array(): void {
		$result = $this->stats->get_stats( 'month' );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'total_exports', $result );
		$this->assertArrayHasKey( 'failed_exports', $result );
		$this->assertArrayHasKey( 'avg_duration', $result );
		$this->assertArrayHasKey( 'format_breakdown', $result );
		$this->assertArrayHasKey( 'daily_exports', $result );
	}

	public function test_get_stats_with_different_periods(): void {
		$month = $this->stats->get_stats( 'month' );
		$today = $this->stats->get_stats( 'today' );
		$week  = $this->stats->get_stats( 'week' );
		$year  = $this->stats->get_stats( 'year' );

		$this->assertIsArray( $month );
		$this->assertIsArray( $today );
		$this->assertIsArray( $week );
		$this->assertIsArray( $year );
	}

	public function test_get_recent_exports_returns_array(): void {
		$result = $this->stats->get_recent_exports( 5 );
		$this->assertIsArray( $result );
	}
}
