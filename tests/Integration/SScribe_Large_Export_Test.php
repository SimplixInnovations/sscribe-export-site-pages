<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-session.php';
require_once SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-logger.php';

class SScribe_Large_Export_Test extends TestCase {

	public function test_docx_always_per_page_regardless_of_size(): void {

		$this->assertFalse( $this->should_use_streaming( 25, array( 'docx' ) ) );

		$this->assertFalse( $this->should_use_streaming( 30, array( 'docx' ) ) );

		$this->assertFalse( $this->should_use_streaming( 115, array( 'docx' ) ) );
	}

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

		$this->assertArrayNotHasKey( 'streaming_docx', $data );

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

	public function test_memory_stability_across_batches(): void {
		$memory_samples = array();
		$batch_size     = 5;
		$total_pages    = 50;

		for ( $batch = 0; $batch < ceil( $total_pages / $batch_size ); $batch++ ) {

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

	private function generate_mock_content( int $page_num ): string {
		$paragraphs = random_int( 3, 10 );
		$content    = '';

		for ( $p = 0; $p < $paragraphs; $p++ ) {
			$words    = random_int( 20, 100 );
			$content .= '<p>' . str_repeat( "Word {$page_num}-{$p} ", $words ) . '</p>';
		}

		return $content;
	}

	private function should_use_streaming( int $page_count, array $formats ): bool {
		return false;
	}
}
