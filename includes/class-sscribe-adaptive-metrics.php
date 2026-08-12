<?php
/**
 * SScribe Adaptive Metrics
 *
 * @package SScribe_Export_Site_Pages
 * @license GPL v2 or later
 * @link    https://www.gnu.org/licenses/gpl-2.0.html
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tracks and predicts export performance using exponential moving averages.
 */
class SScribe_Adaptive_Metrics {

	private const EMA_ALPHA = 0.3;

	private const MIN_SAMPLES = 3;

	private const BASELINE_SECONDS = array(
		'docx'     => 1.5,
		'pdf'      => 8.0,
		'html'     => 1.0,
		'markdown' => 0.5,
	);

	private const BASELINE_MB = array(
		'docx'     => 0.5,
		'pdf'      => 2.0,
		'html'     => 0.3,
		'markdown' => 0.1,
	);

	private const POST_TYPES = array( 'page', 'post' );

	/**
	 * Get estimated seconds per page for a format.
	 *
	 * @param string $format    Export format.
	 * @param string $post_type Post type.
	 * @return float
	 */
	public function get_seconds_per_page( string $format, string $post_type = 'page' ): float {
		$baseline = self::BASELINE_SECONDS[ $format ] ?? 2.0;
		if ( ! isset( self::BASELINE_SECONDS[ $format ] ) || ! in_array( $post_type, self::POST_TYPES, true ) ) {
			return $baseline;
		}

		$metrics = get_option( 'sscribe_export_metrics', array() );
		$metrics = is_array( $metrics ) ? $metrics : array();
		$key     = $format . '_' . $post_type;
		if ( ! isset( $metrics['formats'][ $key ]['avg_seconds_per_page'] ) ) {
			return $baseline;
		}

		$historical = max( 0.0, (float) $metrics['formats'][ $key ]['avg_seconds_per_page'] );
		$samples    = (int) ( $metrics['formats'][ $key ]['sample_count'] ?? 0 );

		if ( $samples < self::MIN_SAMPLES ) {

			return ( $baseline * 0.7 ) + ( $historical * 0.3 );
		}

		return ( $baseline * 0.2 ) + ( $historical * 0.8 );
	}

	/**
	 * Get estimated MB per page for a format.
	 *
	 * @param string $format    Export format.
	 * @param string $post_type Post type.
	 * @return float
	 */
	public function get_mb_per_page( string $format, string $post_type = 'page' ): float {
		$baseline = self::BASELINE_MB[ $format ] ?? 0.5;
		if ( ! isset( self::BASELINE_MB[ $format ] ) || ! in_array( $post_type, self::POST_TYPES, true ) ) {
			return $baseline;
		}

		$metrics = get_option( 'sscribe_export_metrics', array() );
		$metrics = is_array( $metrics ) ? $metrics : array();
		$key     = $format . '_' . $post_type;
		if ( ! isset( $metrics['formats'][ $key ]['avg_mb_per_page'] ) ) {
			return $baseline;
		}

		$historical = max( 0.0, (float) $metrics['formats'][ $key ]['avg_mb_per_page'] );
		$samples    = (int) ( $metrics['formats'][ $key ]['sample_count'] ?? 0 );

		if ( $samples < self::MIN_SAMPLES ) {

			return ( $baseline * 0.7 ) + ( $historical * 0.3 );
		}

		return ( $baseline * 0.2 ) + ( $historical * 0.8 );
	}

	/**
	 * Save export metrics and update moving averages.
	 *
	 * @param string $format      Export format.
	 * @param int    $page_count  Number of pages.
	 * @param float  $elapsed_sec Elapsed seconds.
	 * @param float  $total_mb    Total MB.
	 * @param string $post_type   Post type.
	 */
	public function save( string $format, int $page_count, float $elapsed_sec, float $total_mb, string $post_type = 'page' ): void {
		if (
			$page_count <= 0
			|| ! isset( self::BASELINE_SECONDS[ $format ] )
			|| ! in_array( $post_type, self::POST_TYPES, true )
			|| ! is_finite( $elapsed_sec )
			|| ! is_finite( $total_mb )
			|| $elapsed_sec < 0
			|| $total_mb < 0
		) {
			return;
		}

		$lock_manager = new SScribe_Export_Lock_Manager();
		$lock_name    = 'metrics-save';
		$lock_token   = $lock_manager->acquire_lock( $lock_name, 5, 4 );
		if ( null === $lock_token ) {
			return;
		}

		try {
			$metrics = get_option( 'sscribe_export_metrics', array() );
			$metrics = is_array( $metrics ) ? $metrics : array();
			$key     = $format . '_' . $post_type;
			if ( ! isset( $metrics['formats'] ) ) {
				$metrics['formats'] = array();
			}
			if ( ! isset( $metrics['formats'][ $key ] ) ) {
				$metrics['formats'][ $key ] = array(
					'avg_seconds_per_page' => 0,
					'avg_mb_per_page'      => 0,
					'sample_count'         => 0,
				);
			}

			$new_seconds = $elapsed_sec / $page_count;
			$new_mb      = $total_mb / $page_count;

			$existing_seconds = (float) ( $metrics['formats'][ $key ]['avg_seconds_per_page'] ?? 0 );
			$existing_mb      = (float) ( $metrics['formats'][ $key ]['avg_mb_per_page'] ?? 0 );
			$samples          = (int) ( $metrics['formats'][ $key ]['sample_count'] ?? 0 );

			if ( 0 === $samples ) {
				$metrics['formats'][ $key ]['avg_seconds_per_page'] = $new_seconds;
				$metrics['formats'][ $key ]['avg_mb_per_page']      = $new_mb;
			} else {
				$metrics['formats'][ $key ]['avg_seconds_per_page'] = round(
					( $existing_seconds * ( 1 - self::EMA_ALPHA ) ) + ( $new_seconds * self::EMA_ALPHA ),
					4
				);
				$metrics['formats'][ $key ]['avg_mb_per_page']      = round(
					( $existing_mb * ( 1 - self::EMA_ALPHA ) ) + ( $new_mb * self::EMA_ALPHA ),
					4
				);
			}

			++$metrics['formats'][ $key ]['sample_count'];
			$metrics['last_export'] = current_time( 'mysql' );

			update_option( 'sscribe_export_metrics', $metrics, false );
		} finally {
			$lock_manager->release_lock( $lock_name, $lock_token );
		}
	}
}
