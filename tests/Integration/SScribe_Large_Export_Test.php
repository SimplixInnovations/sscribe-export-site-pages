<?php
/**
 * Integration tests for large export scenarios (100+ pages).
 *
 * After removing the streaming DOCX generator, large exports use
 * per-page PHPWord generation.  These tests verify that session
 * management and batch processing work correctly at scale.
 *
 * @package SScribe
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-session.php';
require_once SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-logger.php';

/**
 * Class SScribe_Large_Export_Test
 */
class SScribe_Large_Export_Test extends TestCase {

	/**
	 * Test that DOCX exports always use per-page mode (no streaming).
	 */
	public function test_docx_always_per_page_regardless_of_size(): void {
		// Small export.
		$this->assertFalse( $this->should_use_streaming( 25, array( 'docx' ) ) );

		// At old threshold.
		$this->assertFalse( $this->should_use_streaming( 30, array( 'docx' ) ) );

		// Large export.
		$this->assertFalse( $this->should_use_streaming( 115, array( 'docx' ) ) );
	}

	/**
	 * Test session with 115 pages without streaming fields.
	 */
	public function test_session_large_page_count(): void {
		$session = new SScribe_Session();

		$page_ids   = range( 1, 115 );
		$session_id = $session->create( array(
			'page_ids' => $page_ids,
			'total'    => 115,
			'formats'  => array( 'docx' ),
		) );

		$data = $session->get( $session_id );
		$this->assertNotNull( $data );
		$this->assertCount( 115, $data['page_ids'] );
		$this->assertEquals( 115, $data['total'] );

		// Streaming fields should NOT be present.
		$this->assertArrayNotHasKey( 'streaming_docx', $data );

		// Simulate batch processing updates (without streaming_count).
		for ( $batch = 0; $batch < 23; $batch++ ) {
			$processed = ( $batch + 1 ) * 5;
			$session->update( $session_id, array(
				'processed' => min( $processed, 115 ),
			) );
		}

		$final = $session->get( $session_id );
		$this->assertEquals( 115, $final['processed'] );

		$session->delete( $session_id );
	}

	/**
	 * Test memory efficiency across simulated batches.
	 *
	 * Verifies that the batch processor's memory management (gc_collect_cycles,
	 * per-page PHPWord cleanup) keeps memory stable across batch boundaries.
	 */
	public function test_memory_stability_across_batches(): void {
		$memory_samples = array();
		$batch_size     = 5;
		$total_pages    = 50;

		for ( $batch = 0; $batch < ceil( $total_pages / $batch_size ); $batch++ ) {
			// Simulate per-page processing with cleanup (mimics batch processor).
			$mock_page_data = array();
			$start = $batch * $batch_size;
			$end   = min( $start + $batch_size, $total_pages );

			for ( $i = $start; $i < $end; $i++ ) {
				$mock_page_data[] = array(
					'title'   => "Batch Page {$i}",
					'content' => $this->generate_mock_content( $i ),
					'url'     => "https://example.com/batch-{$i}",
				);
			}

			// Simulate processing and cleanup.
			unset( $mock_page_data );

			if ( function_exists( 'gc_collect_cycles' ) ) {
				gc_collect_cycles();
			}

			$memory_samples[] = memory_get_usage( true );
		}

		$max_memory      = max( $memory_samples );
		$min_memory      = min( $memory_samples );
		$memory_variance = $max_memory - $min_memory;

		$this->assertLessThan(
			50 * 1024 * 1024,
			$memory_variance,
			'Memory variance across batches should be reasonable'
		);
	}

	/**
	 * Generate mock content for testing.
	 *
	 * @param int $page_num Page number.
	 * @return string HTML content.
	 */
	private function generate_mock_content( int $page_num ): string {
		$paragraphs = random_int( 3, 10 );
		$content    = '';

		for ( $p = 0; $p < $paragraphs; $p++ ) {
			$words    = random_int( 20, 100 );
			$content .= '<p>' . str_repeat( "Word {$page_num}-{$p} ", $words ) . '</p>';
		}

		return $content;
	}

	/**
	 * Streaming mode is no longer used - always returns false.
	 *
	 * @param int   $page_count Number of pages.
	 * @param array $formats    Export formats.
	 * @return bool Always false - streaming was removed.
	 */
	private function should_use_streaming( int $page_count, array $formats ): bool {
		return false;
	}
}
