<?php
/**
 * SScribe Export Resource Monitor Unit Test
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

class SScribe_Export_Resource_Monitor_Test extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['sscribe_test_filters'] = array();
	}

	protected function tearDown(): void {
		$GLOBALS['sscribe_test_filters'] = array();
		parent::tearDown();
	}

	public function test_is_memory_available_returns_bool(): void {
		$monitor = new \SScribe_Export_Resource_Monitor();
		$result  = $monitor->is_memory_available();
		$this->assertIsBool( $result );
	}

	public function test_is_memory_available_with_zero_buffer(): void {
		$monitor = new \SScribe_Export_Resource_Monitor();
		$result  = $monitor->is_memory_available( 0 );
		$this->assertIsBool( $result );
	}

	public function test_is_time_available_returns_bool(): void {
		$monitor = new \SScribe_Export_Resource_Monitor();
		$result  = $monitor->is_time_available( microtime( true ) - 5, 10 );
		$this->assertIsBool( $result );
	}

	public function test_get_remaining_time_returns_float_or_neg_one(): void {
		$monitor = new \SScribe_Export_Resource_Monitor();
		$result  = $monitor->get_remaining_time( microtime( true ) - 10 );

		if ( -1.0 === $result ) {
			$this->assertEquals( -1.0, $result );
		} else {
			$this->assertIsFloat( $result );
			$this->assertGreaterThanOrEqual( 0.0, $result );
		}
	}

	public function test_get_memory_usage_percent_returns_float(): void {
		$monitor = new \SScribe_Export_Resource_Monitor();
		$result  = $monitor->get_memory_usage_percent();
		$this->assertIsFloat( $result );
		$this->assertGreaterThanOrEqual( 0.0, $result );
		$this->assertLessThanOrEqual( 100.0, $result );
	}

	public function test_get_optimal_batch_size_returns_reasonable_int(): void {
		$monitor = new \SScribe_Export_Resource_Monitor();
		$result  = $monitor->get_optimal_batch_size();
		$this->assertIsInt( $result );
		$this->assertGreaterThanOrEqual( 1, $result );
		$this->assertLessThanOrEqual( 20, $result );
	}

	public function test_get_optimal_batch_size_with_pdf_returns_two(): void {
		$monitor = new \SScribe_Export_Resource_Monitor();
		$result  = $monitor->get_optimal_batch_size( array( 'pdf' ) );
		$this->assertEquals( 2, $result );
	}

	public function test_calculate_export_memory_requirement_returns_positive_int(): void {
		$monitor = new \SScribe_Export_Resource_Monitor();
		$result  = $monitor->calculate_export_memory_requirement( 10, array( 'docx' ) );
		$this->assertIsInt( $result );
		$this->assertGreaterThan( 0, $result );
	}

	public function test_calculate_export_memory_requirement_scales_with_page_count(): void {
		$monitor = new \SScribe_Export_Resource_Monitor();
		$small   = $monitor->calculate_export_memory_requirement( 1, array( 'docx' ) );
		$large   = $monitor->calculate_export_memory_requirement( 10, array( 'docx' ) );
		$this->assertGreaterThan( $small, $large );
	}

	public function test_calculate_export_memory_requirement_adds_format_overhead(): void {
		$monitor = new \SScribe_Export_Resource_Monitor();
		$base    = $monitor->calculate_export_memory_requirement( 1, array() );
		$with_docx = $monitor->calculate_export_memory_requirement( 1, array( 'docx' ) );
		$this->assertGreaterThan( $base, $with_docx );
	}

	public function test_get_memory_warning_returns_array_or_null(): void {
		$monitor = new \SScribe_Export_Resource_Monitor();
		$result  = $monitor->get_memory_warning( 1, array( 'docx' ) );

		if ( null !== $result ) {
			$this->assertArrayHasKey( 'level', $result );
			$this->assertArrayHasKey( 'message', $result );
			$this->assertContains( $result['level'], array( 'error', 'warning' ) );
		} else {
			$this->assertNull( $result );
		}
	}

	public function test_get_memory_warning_for_large_export_returns_warning_or_error(): void {
		$monitor = new \SScribe_Export_Resource_Monitor();

		$result = $monitor->get_memory_warning( 5000, array( 'docx', 'pdf' ) );
		if ( null !== $result ) {
			$this->assertArrayHasKey( 'estimated_mb', $result );
			$this->assertArrayHasKey( 'available_mb', $result );
		}
	}
}
