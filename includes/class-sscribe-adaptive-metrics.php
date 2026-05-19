<?php

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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

	public function get_seconds_per_page( string $format, string $post_type = 'page' ): float {
		$baseline = self::BASELINE_SECONDS[ $format ] ?? 2.0;

		$metrics = get_option( 'sscribe_export_metrics', array() );
		$key     = $format . '_' . $post_type;
		if ( ! isset( $metrics['formats'][ $key ]['avg_seconds_per_page'] ) ) {
			return $baseline;
		}

		$historical = (float) $metrics['formats'][ $key ]['avg_seconds_per_page'];
		$samples    = (int) ( $metrics['formats'][ $key ]['sample_count'] ?? 0 );

		if ( $samples < self::MIN_SAMPLES ) {

			return ( $baseline * 0.7 ) + ( $historical * 0.3 );
		}

		return ( $baseline * 0.2 ) + ( $historical * 0.8 );
	}

	public function get_mb_per_page( string $format, string $post_type = 'page' ): float {
		$baseline = self::BASELINE_MB[ $format ] ?? 0.5;

		$metrics = get_option( 'sscribe_export_metrics', array() );
		$key     = $format . '_' . $post_type;
		if ( ! isset( $metrics['formats'][ $key ]['avg_mb_per_page'] ) ) {
			return $baseline;
		}

		$historical = (float) $metrics['formats'][ $key ]['avg_mb_per_page'];
		$samples    = (int) ( $metrics['formats'][ $key ]['sample_count'] ?? 0 );

		if ( $samples < self::MIN_SAMPLES ) {

			return ( $baseline * 0.7 ) + ( $historical * 0.3 );
		}

		return ( $baseline * 0.2 ) + ( $historical * 0.8 );
	}

	public function save( string $format, int $page_count, float $elapsed_sec, float $total_mb, string $post_type = 'page' ): void {
		if ( $page_count <= 0 ) {
			return;
		}

		$metrics = get_option( 'sscribe_export_metrics', array() );
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
	}
}
