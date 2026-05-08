<?php
/**
 * Adaptive metrics for export time and size estimation.
 *
 * Learns from historical exports to provide increasingly accurate
 * estimates for time and memory requirements.
 *
 * @package SScribe
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SScribe_Adaptive_Metrics
 *
 * Provides adaptive time and size estimates based on historical export data.
 * Uses exponential moving average to blend baseline estimates with actuals.
 */
class SScribe_Adaptive_Metrics {

	/**
	 * Exponential moving average alpha weight.
	 *
	 * Higher values weight recent exports more heavily.
	 * α = 0.3 means 30% weight on new data, 70% on historical.
	 */
	private const EMA_ALPHA = 0.3;

	/**
	 * Minimum sample count before using historical data.
	 *
	 * Below this threshold, blend 70% baseline + 30% historical.
	 * Above this threshold, blend 20% baseline + 80% historical.
	 */
	private const MIN_SAMPLES = 3;

	/**
	 * Conservative baselines for first-time exports (seconds per page).
	 */
	private const BASELINE_SECONDS = array(
		'docx'     => 1.5,
		'pdf'      => 8.0,
		'html'     => 1.0,
		'markdown' => 0.5,
	);

	/**
	 * Conservative baselines for first-time exports (MB per page).
	 */
	private const BASELINE_MB = array(
		'docx'     => 0.5,
		'pdf'      => 2.0,
		'html'     => 0.3,
		'markdown' => 0.1,
	);

	/**
	 * Get adaptive seconds-per-page estimate for a format.
	 *
	 * Blends baseline estimates with historical actuals using EMA.
	 *
	 * @param string $format Export format (docx, pdf, html, markdown).
	 * @return float Seconds per page estimate.
	 */
	public function get_seconds_per_page( string $format ): float {
		$baseline = self::BASELINE_SECONDS[ $format ] ?? 2.0;

		$metrics = get_option( 'sscribe_export_metrics', array() );
		if ( ! isset( $metrics['formats'][ $format ]['avg_seconds_per_page'] ) ) {
			return $baseline;
		}

		$historical = (float) $metrics['formats'][ $format ]['avg_seconds_per_page'];
		$samples    = (int) ( $metrics['formats'][ $format ]['sample_count'] ?? 0 );

		if ( $samples < self::MIN_SAMPLES ) {
			// Not enough data — blend 70% baseline + 30% historical.
			return ( $baseline * 0.7 ) + ( $historical * 0.3 );
		}

		// Enough data — blend 20% baseline + 80% historical for stability.
		return ( $baseline * 0.2 ) + ( $historical * 0.8 );
	}

	/**
	 * Get adaptive MB-per-page estimate for a format.
	 *
	 * Blends baseline estimates with historical actuals using EMA.
	 *
	 * @param string $format Export format (docx, pdf, html, markdown).
	 * @return float MB per page estimate.
	 */
	public function get_mb_per_page( string $format ): float {
		$baseline = self::BASELINE_MB[ $format ] ?? 0.5;

		$metrics = get_option( 'sscribe_export_metrics', array() );
		if ( ! isset( $metrics['formats'][ $format ]['avg_mb_per_page'] ) ) {
			return $baseline;
		}

		$historical = (float) $metrics['formats'][ $format ]['avg_mb_per_page'];
		$samples    = (int) ( $metrics['formats'][ $format ]['sample_count'] ?? 0 );

		if ( $samples < self::MIN_SAMPLES ) {
			// Not enough data — blend 70% baseline + 30% historical.
			return ( $baseline * 0.7 ) + ( $historical * 0.3 );
		}

		// Enough data — blend 20% baseline + 80% historical for stability.
		return ( $baseline * 0.2 ) + ( $historical * 0.8 );
	}

	/**
	 * Save export metrics for adaptive estimation.
	 *
	 * Updates exponential moving averages for time and size per page.
	 *
	 * @param string $format     Export format.
	 * @param int    $page_count Number of pages exported.
	 * @param float  $elapsed_sec Total elapsed seconds.
	 * @param float  $total_mb    Total file size in MB.
	 * @return void
	 */
	public function save( string $format, int $page_count, float $elapsed_sec, float $total_mb ): void {
		if ( $page_count <= 0 ) {
			return;
		}

		$metrics = get_option( 'sscribe_export_metrics', array() );
		if ( ! isset( $metrics['formats'] ) ) {
			$metrics['formats'] = array();
		}
		if ( ! isset( $metrics['formats'][ $format ] ) ) {
			$metrics['formats'][ $format ] = array(
				'avg_seconds_per_page' => 0,
				'avg_mb_per_page'      => 0,
				'sample_count'         => 0,
			);
		}

		$new_seconds = $elapsed_sec / $page_count;
		$new_mb      = $total_mb / $page_count;

		$existing_seconds = (float) ( $metrics['formats'][ $format ]['avg_seconds_per_page'] ?? 0 );
		$existing_mb      = (float) ( $metrics['formats'][ $format ]['avg_mb_per_page'] ?? 0 );
		$samples          = (int) ( $metrics['formats'][ $format ]['sample_count'] ?? 0 );

		if ( 0 === $samples ) {
			$metrics['formats'][ $format ]['avg_seconds_per_page'] = $new_seconds;
			$metrics['formats'][ $format ]['avg_mb_per_page']      = $new_mb;
		} else {
			$metrics['formats'][ $format ]['avg_seconds_per_page'] = round(
				( $existing_seconds * ( 1 - self::EMA_ALPHA ) ) + ( $new_seconds * self::EMA_ALPHA ),
				4
			);
			$metrics['formats'][ $format ]['avg_mb_per_page']      = round(
				( $existing_mb * ( 1 - self::EMA_ALPHA ) ) + ( $new_mb * self::EMA_ALPHA ),
				4
			);
		}

		++$metrics['formats'][ $format ]['sample_count'];
		$metrics['last_export'] = current_time( 'mysql' );

		update_option( 'sscribe_export_metrics', $metrics, false );
	}
}
