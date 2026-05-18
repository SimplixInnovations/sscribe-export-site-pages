<?php
/**
 * Server resource monitoring for SScribe export operations.
 *
 * Provides runtime checks for PHP memory and execution time thresholds
 * during batch export processing. All methods operate on current process
 * state and carry no mutable state of their own (pure utility class).
 *
 * @package       SScribe
 * @since         1.1.5
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Server resource monitor for export operations.
 *
 * Evaluates whether the current PHP process has sufficient memory and
 * execution time remaining to safely process additional export pages.
 * Used by the batch processor to dynamically pause/resume export flows
 * and to warn administrators of potential resource constraints.
 *
 * @since 1.1.5
 */
class SScribe_Export_Resource_Monitor {

	/**
	 * Check if enough memory is available.
	 *
	 * Compares current memory usage against the PHP memory_limit,
	 * keeping at least {@see $buffer_mb} megabytes free.
	 *
	 * @since 1.1.5
	 *
	 * @param int $buffer_mb Minimum free memory to maintain, in MB.
	 *                        Default 10.
	 *
	 * @return bool True if at least {@see $buffer_mb} MB is available,
	 *              false otherwise. Also returns true if memory_limit
	 *              is unlimited (<= 0).
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
	 * Check if enough time remains before PHP max_execution_time.
	 *
	 * @since 1.1.5
	 *
	 * @param float $batch_start_time Microtime (from microtime(true)) when
	 *                                the current batch started processing.
	 * @param int   $buffer_seconds  Minimum seconds to keep as a safety
	 *                                margin before the timeout. Default 10.
	 *
	 * @return bool True if at least {@see $buffer_seconds} remain, false
	 *              otherwise. Also returns true if max_execution_time is 0
	 *              (unlimited).
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
	 * Get remaining seconds before PHP max_execution_time.
	 *
	 * @since 1.1.5
	 *
	 * @param float $batch_start_time Microtime when the current batch started.
	 *
	 * @return float Remaining seconds, floored at 0. Returns -1 if
	 *               max_execution_time is 0 (unlimited).
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
	 * Get current PHP memory usage as a percentage of memory_limit.
	 *
	 * @since 1.1.5
	 *
	 * @return float Memory usage percentage (0.0–100.0). Returns 0.0 if
	 *               memory_limit is unlimited.
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
	 * Calculate the optimal batch size based on available memory and formats.
	 *
	 * Estimates ~5 MB per page for typical DOCX exports and reduces the
	 * batch to 2 when PDF format is included (mPDF is CPU-intensive at
	 * ~3 s/page).
	 *
	 * @since 1.1.5
	 *
	 * @param array $formats Export format slugs (e.g. ['docx'], ['pdf', 'html']).
	 *
	 * @return int Optimal batch size, minimum 1, clamped to the configured
	 *             maximum (via {@see 'sscribe_batch_size'}) and capped at 20.
	 */
	public function get_optimal_batch_size( array $formats = array() ): int {
		$memory_limit  = wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) );
		$current_usage = memory_get_usage( true );
		$available     = $memory_limit - $current_usage;

		/*
		 * Estimate per-page memory:
		 * - Page data collection:    ~500 KB
		 * - Content parsing (DOM):   ~1 MB
		 * - PHPWord DOCX generation: ~2-3 MB
		 * Total: ~4 MB avg, use 5 MB for safety.
		 */
		$memory_per_page = 5 * 1024 * 1024;

		// Reserve 20 % safety margin.
		$safe_available = $available * 0.8;

		// Calculate safe batch size from available memory.
		$safe_batch_size = (int) floor( $safe_available / $memory_per_page );

		/*
		 * Apply the configured batch size as the upper bound, but allow
		 * memory pressure to reduce it further.
		 *
		 * @since 1.1.5
		 * @param int $batch_size Maximum batch size. Default 5.
		 */
		$configured_size = (int) apply_filters( 'sscribe_batch_size', 5 );

		$optimal = max( 1, min( $safe_batch_size, $configured_size, 20 ) );

		/*
		 * PDF generation via mPDF is ~3 s/page — reduce batch size to
		 * prevent max_execution_time violations on large pages.
		 */
		if ( in_array( 'pdf', $formats, true ) && $optimal > 2 ) {
			$optimal = 2;
		}

		return $optimal;
	}

	/**
	 * Calculate estimated memory requirement for an export.
	 *
	 * Accounts for base page data collection plus per-format overhead.
	 *
	 * @since 1.1.5
	 *
	 * @param int   $page_count Number of pages to export.
	 * @param array $formats    Export format slugs.
	 *
	 * @return int Estimated memory requirement in bytes.
	 */
	public function calculate_export_memory_requirement( int $page_count, array $formats ): int {
		$memory_per_page = 1.0; // Base 1 MB for page data collection.

		if ( in_array( 'docx', $formats, true ) ) {
			$memory_per_page += 4.0; // PHPWord overhead.
		}
		if ( in_array( 'pdf', $formats, true ) ) {
			$memory_per_page += 3.0; // mPDF overhead.
		}
		if ( in_array( 'markdown', $formats, true ) ) {
			$memory_per_page += 0.5;
		}

		// Convert to bytes; add 50 MB overhead for PHP and WordPress core.
		$total_mb = ( $page_count * $memory_per_page ) + 50;

		return (int) ( $total_mb * 1024 * 1024 );
	}

	/**
	 * Get a memory warning if the export may fail due to resource limits.
	 *
	 * Compares estimated memory requirement (for the full export) against
	 * currently available PHP memory. Returns null if there is sufficient
	 * headroom (with a 20 % safety margin).
	 *
	 * @since 1.1.5
	 *
	 * @param int   $page_count Number of pages to export.
	 * @param array $formats    Export format slugs.
	 *
	 * @return array|null Warning array with keys 'level' ('error'|'warning'),
	 *                    'message' (localised), 'estimated_mb', 'available_mb',
	 *                    and optionally 'recommended_mb'. Null if no warning.
	 */
	public function get_memory_warning( int $page_count, array $formats ): ?array {
		$memory_limit  = wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) );
		$current_usage = memory_get_usage( true );
		$available     = $memory_limit - $current_usage;

		$estimated_need = $this->calculate_export_memory_requirement( $page_count, $formats );
		$safe_available = (int) ( $available * 0.8 ); // 20 % safety margin.

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
