<?php
/**
 * SScribe Export Resource Monitor
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Monitors memory and time availability during batch exports.
 */
class SScribe_Export_Resource_Monitor {

	/**
	 * Check if sufficient memory is available.
	 *
	 * @param int $buffer_mb Required buffer in MB.
	 * @return bool
	 */
	public function is_memory_available( int $buffer_mb = 10 ): bool {
		$limit = wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) );

		if ( $limit <= 0 ) {
			return true;
		}

		$used      = memory_get_usage( true );
		$available = $limit - $used;

		return $available > ( $buffer_mb * 1024 * 1024 );
	}

	/**
	 * Check if sufficient execution time remains.
	 *
	 * @param float $batch_start_time Batch start timestamp.
	 * @param int   $buffer_seconds   Required buffer in seconds.
	 * @return bool
	 */
	public function is_time_available( float $batch_start_time, int $buffer_seconds = 10 ): bool {
		$max_execution = (int) ini_get( 'max_execution_time' );

		if ( $max_execution <= 0 ) {
			return true;
		}

		$elapsed   = microtime( true ) - $batch_start_time;
		$remaining = $max_execution - $elapsed;

		return $remaining > $buffer_seconds;
	}

	/**
	 * Get remaining execution time in seconds.
	 *
	 * @param float $batch_start_time Batch start timestamp.
	 * @return float
	 */
	public function get_remaining_time( float $batch_start_time ): float {
		$max_execution = (int) ini_get( 'max_execution_time' );

		if ( $max_execution <= 0 ) {
			return -1.0;
		}

		$elapsed   = microtime( true ) - $batch_start_time;
		$remaining = $max_execution - $elapsed;

		return max( 0.0, $remaining );
	}

	/**
	 * Get current memory usage as percentage of limit.
	 *
	 * @return float
	 */
	public function get_memory_usage_percent(): float {
		$limit = wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) );

		if ( $limit <= 0 ) {
			return 0.0;
		}

		$used = memory_get_usage( true );

		return round( ( $used / $limit ) * 100, 1 );
	}

	/**
	 * Calculate optimal batch size based on available resources.
	 *
	 * @param array $formats Export formats.
	 * @return int
	 */
	public function get_optimal_batch_size( array $formats = array() ): int {
		$memory_limit  = wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) );
		$current_usage = memory_get_usage( true );
		$available     = $memory_limit - $current_usage;

		$memory_per_page = 5 * 1024 * 1024;

		$safe_available = $available * 0.8;

		$safe_batch_size = (int) floor( $safe_available / $memory_per_page );

		$configured_size = (int) apply_filters( 'sscribe_batch_size', 5 );

		$optimal = max( 1, min( $safe_batch_size, $configured_size, 20 ) );

		if ( in_array( 'pdf', $formats, true ) && $optimal > 2 ) {
			$optimal = 2;
		}

		return $optimal;
	}

	/**
	 * Calculate total memory requirement for an export.
	 *
	 * @param int   $page_count Number of pages.
	 * @param array $formats    Export formats.
	 * @return int Bytes required.
	 */
	public function calculate_export_memory_requirement( int $page_count, array $formats ): int {
		$memory_per_page = 1.0;

		if ( in_array( 'docx', $formats, true ) ) {
			$memory_per_page += 4.0;
		}
		if ( in_array( 'pdf', $formats, true ) ) {
			$memory_per_page += 3.0;
		}
		if ( in_array( 'markdown', $formats, true ) ) {
			$memory_per_page += 0.5;
		}

		$total_mb = ( $page_count * $memory_per_page ) + 50;

		return (int) ( $total_mb * 1024 * 1024 );
	}

	/**
	 * Get memory warning if export may exceed available memory.
	 *
	 * @param int   $page_count Number of pages.
	 * @param array $formats    Export formats.
	 * @return array|null
	 */
	public function get_memory_warning( int $page_count, array $formats ): ?array {
		$memory_limit  = wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) );
		$current_usage = memory_get_usage( true );
		$available     = $memory_limit - $current_usage;

		$estimated_need = $this->calculate_export_memory_requirement( $page_count, $formats );
		$safe_available = (int) ( $available * 0.8 );

		if ( $estimated_need <= $safe_available ) {
			return null;
		}

		$estimated_mb   = (int) round( $estimated_need / 1024 / 1024 );
		$available_mb   = (int) round( $available / 1024 / 1024 );
		$limit_mb       = (int) round( $memory_limit / 1024 / 1024 );
		$recommended_mb = (int) ceil( $estimated_mb / 128 ) * 128;

		if ( $estimated_need > $available ) {
			return array(
				'level'          => 'error',
				'message'        => sprintf(
					/* translators: 1: Estimated memory needed (MB), 2: Available memory (MB), 3: Recommended memory limit (MB) */
					__( 'Warning: Export requires ~%1$d MB but only %2$d MB available. Increase PHP memory_limit to %3$d MB+ for reliable export.', 'sscribe-export-site-pages' ),
					$estimated_mb,
					$available_mb,
					$recommended_mb
				),
				'estimated_mb'   => $estimated_mb,
				'available_mb'   => $available_mb,
				'recommended_mb' => $recommended_mb,
			);
		}

		return array(
			'level'        => 'warning',
			'message'      => sprintf(
				/* translators: 1: Estimated memory needed (MB), 2: Available memory (MB), 3: Usage percentage */
				__( 'Note: Export will use ~%1$d MB of %2$d MB available (%3$d%%). Consider increasing memory for safety.', 'sscribe-export-site-pages' ),
				$estimated_mb,
				$available_mb,
				(int) round( ( $estimated_mb / $available_mb ) * 100 )
			),
			'estimated_mb' => $estimated_mb,
			'available_mb' => $available_mb,
		);
	}
}
