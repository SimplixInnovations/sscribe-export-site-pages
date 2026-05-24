<?php
/**
 * SScribe Adaptive Metrics Unit Test
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

class SScribe_Adaptive_Metrics_Test extends TestCase {

	protected function tearDown(): void {
		unset( $GLOBALS['sscribe_test_options']['sscribe_export_metrics'] );
		parent::tearDown();
	}

	public function test_get_seconds_per_page_returns_baseline_when_no_metrics(): void {
		$metrics = new \SScribe_Adaptive_Metrics();
		$result  = $metrics->get_seconds_per_page( 'docx' );
		$this->assertEquals( 1.5, $result );
	}

	public function test_get_seconds_per_page_returns_baseline_for_unknown_format(): void {
		$metrics = new \SScribe_Adaptive_Metrics();
		$result  = $metrics->get_seconds_per_page( 'unknown' );
		$this->assertEquals( 2.0, $result );
	}

	public function test_get_mb_per_page_returns_baseline_when_no_metrics(): void {
		$metrics = new \SScribe_Adaptive_Metrics();
		$result  = $metrics->get_mb_per_page( 'pdf' );
		$this->assertEquals( 2.0, $result );
	}

	public function test_get_mb_per_page_returns_baseline_for_unknown_format(): void {
		$metrics = new \SScribe_Adaptive_Metrics();
		$result  = $metrics->get_mb_per_page( 'unknown' );
		$this->assertEquals( 0.5, $result );
	}

	public function test_save_stores_metrics(): void {
		$metrics = new \SScribe_Adaptive_Metrics();
		$metrics->save( 'docx', 10, 15.0, 5.0 );

		$stored = get_option( 'sscribe_export_metrics' );
		$this->assertIsArray( $stored );
		$this->assertArrayHasKey( 'formats', $stored );
		$this->assertArrayHasKey( 'docx_page', $stored['formats'] );
	}

	public function test_save_updates_moving_average(): void {
		update_option( 'sscribe_export_metrics', array() );

		$metrics = new \SScribe_Adaptive_Metrics();
		$metrics->save( 'docx', 10, 15.0, 5.0 );
		$metrics->save( 'docx', 10, 25.0, 8.0 );

		$stored = get_option( 'sscribe_export_metrics' );
		$avg_sec = $stored['formats']['docx_page']['avg_seconds_per_page'];

		// First save: 1.5, second save uses EMA: (1.5 * 0.7) + (2.5 * 0.3) = 1.8
		$this->assertEquals( 1.8, $avg_sec );
	}

	public function test_save_with_zero_pages_does_nothing(): void {
		$metrics = new \SScribe_Adaptive_Metrics();
		$metrics->save( 'docx', 0, 15.0, 5.0 );

		$stored = get_option( 'sscribe_export_metrics' );
		$this->assertEmpty( $stored );
	}

	public function test_get_seconds_with_few_samples_uses_weighted_blend(): void {
		update_option(
			'sscribe_export_metrics',
			array(
				'formats' => array(
					'docx_page' => array(
						'avg_seconds_per_page' => 0.5,
						'sample_count'         => 1,
					),
				),
			)
		);

		$metrics = new \SScribe_Adaptive_Metrics();
		$result  = $metrics->get_seconds_per_page( 'docx' );
		// (1.5 * 0.7) + (0.5 * 0.3) = 1.2
		$this->assertEqualsWithDelta( 1.2, $result, 0.0001 );
	}

	public function test_get_seconds_with_many_samples_uses_historical_weight(): void {
		update_option(
			'sscribe_export_metrics',
			array(
				'formats' => array(
					'docx_page' => array(
						'avg_seconds_per_page' => 2.0,
						'sample_count'         => 10,
					),
				),
			)
		);

		$metrics = new \SScribe_Adaptive_Metrics();
		$result  = $metrics->get_seconds_per_page( 'docx' );
		// (1.5 * 0.2) + (2.0 * 0.8) = 1.9
		$this->assertEqualsWithDelta( 1.9, $result, 0.0001 );
	}

	public function test_get_mb_with_many_samples_uses_historical_weight(): void {
		update_option(
			'sscribe_export_metrics',
			array(
				'formats' => array(
					'pdf_page' => array(
						'avg_mb_per_page' => 3.0,
						'sample_count'    => 10,
					),
				),
			)
		);

		$metrics = new \SScribe_Adaptive_Metrics();
		$result  = $metrics->get_mb_per_page( 'pdf' );
		// (2.0 * 0.2) + (3.0 * 0.8) = 2.8
		$this->assertEqualsWithDelta( 2.8, $result, 0.0001 );
	}

	public function test_save_increments_sample_count(): void {
		$metrics = new \SScribe_Adaptive_Metrics();
		$metrics->save( 'html', 5, 2.5, 0.8 );
		$metrics->save( 'html', 5, 3.0, 1.0 );

		$stored = get_option( 'sscribe_export_metrics' );
		$this->assertEquals( 2, $stored['formats']['html_page']['sample_count'] );
	}

	public function test_save_sets_last_export_timestamp(): void {
		$metrics = new \SScribe_Adaptive_Metrics();
		$metrics->save( 'docx', 1, 0.5, 0.1 );

		$stored = get_option( 'sscribe_export_metrics' );
		$this->assertArrayHasKey( 'last_export', $stored );
	}
}
