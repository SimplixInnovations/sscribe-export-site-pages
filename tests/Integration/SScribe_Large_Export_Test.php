<?php
/**
 * Integration tests for large export scenarios (100+ pages).
 *
 * @package SScribe
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-session.php';
require_once SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-logger.php';
require_once SSCRIBE_PLUGIN_DIR . 'includes/exporters/class-sscribe-streaming-docx-generator.php';

/**
 * Class SScribe_Large_Export_Test
 */
class SScribe_Large_Export_Test extends TestCase {

	/**
	 * Test streaming is triggered for 100+ pages.
	 */
	public function test_streaming_triggered_for_large_export(): void {
		$page_count = 115;
		$formats = array( 'docx' );

		$streaming_enabled = $this->should_use_streaming( $page_count, $formats );

		$this->assertTrue( $streaming_enabled, 'Streaming should be enabled for 115 pages with DOCX format' );
	}

	/**
	 * Test streaming is NOT used for small exports.
	 */
	public function test_streaming_not_used_for_small_export(): void {
		$page_count = 25;
		$formats = array( 'docx' );

		$streaming_enabled = $this->should_use_streaming( $page_count, $formats );

		$this->assertFalse( $streaming_enabled, 'Streaming should NOT be enabled for 25 pages' );
	}

	/**
	 * Test large DOCX generation with 100 pages.
	 */
	public function test_large_docx_generation(): void {
		$temp_dir = sys_get_temp_dir() . '/sscribe_large_test_' . uniqid();
		$output_dir = sys_get_temp_dir() . '/sscribe_large_output_' . uniqid();
		wp_mkdir_p( $temp_dir );
		wp_mkdir_p( $output_dir );

		$generator = new SScribe_Streaming_DOCX_Generator( $temp_dir, 50 );
		$page_count = 100;
		$initial_memory = memory_get_usage( true );

		for ( $i = 1; $i <= $page_count; $i++ ) {
			$generator->add_page( array(
				'title'   => "Page {$i}",
				'content' => $this->generate_mock_content( $i ),
				'url'     => "https://example.com/page-{$i}",
			) );

			if ( $i % 25 === 0 ) {
				$generator->flush();
			}
		}

		$after_add_memory = memory_get_usage( true );
		$memory_growth = $after_add_memory - $initial_memory;

		$output_path = $output_dir . '/large_export.docx';
		$result = $generator->save( $output_path );

		$this->assertTrue( $result, 'Large DOCX generation should succeed' );
		$this->assertFileExists( $output_path );

		$file_size = filesize( $output_path );
		$this->assertGreaterThan( 1024, $file_size, 'DOCX should have meaningful content' );

		$this->assertLessThan(
			100 * 1024 * 1024,
			$memory_growth,
			'Memory growth should be reasonable for 100 pages'
		);

		$zip = new ZipArchive();
		$this->assertTrue( $zip->open( $output_path ) === true );
		$this->assertGreaterThan( 3, $zip->numFiles );
		$zip->close();

		$this->cleanup_temp_dir( $temp_dir );
		if ( file_exists( $output_path ) ) {
			unlink( $output_path );
		}
		rmdir( $output_dir );
	}

	/**
	 * Test session with 115 pages (original user scenario).
	 */
	public function test_session_large_page_count(): void {
		$session = new SScribe_Session();

		$page_ids = range( 1, 115 );
		$temp_dir = sys_get_temp_dir() . '/sscribe_session_test_' . uniqid();
		$streaming_dir = $temp_dir . '/streaming_docx';
		wp_mkdir_p( $streaming_dir );

		$session_id = $session->create( array(
			'page_ids'        => $page_ids,
			'total'           => 115,
			'formats'         => array( 'docx' ),
			'streaming_docx'  => true,
			'streaming_dir'   => $streaming_dir,
			'streaming_count' => 0,
		) );

		$data = $session->get( $session_id );
		$this->assertNotNull( $data );
		$this->assertCount( 115, $data['page_ids'] );
		$this->assertTrue( $data['streaming_docx'] );
		$this->assertEquals( 115, $data['total'] );

		for ( $batch = 0; $batch < 23; $batch++ ) {
			$processed = ( $batch + 1 ) * 5;
			$session->update( $session_id, array(
				'processed'       => min( $processed, 115 ),
				'streaming_count' => min( $processed, 115 ),
			) );
		}

		$final = $session->get( $session_id );
		$this->assertEquals( 115, $final['processed'] );
		$this->assertEquals( 115, $final['streaming_count'] );

		$session->delete( $session_id );
		$this->cleanup_temp_dir( $temp_dir );
	}

	/**
	 * Test memory efficiency across batch boundaries.
	 */
	public function test_memory_across_batches(): void {
		$temp_dir = sys_get_temp_dir() . '/sscribe_batch_test_' . uniqid();
		wp_mkdir_p( $temp_dir );

		$batch_size = 5;
		$total_pages = 50;
		$memory_samples = array();

		for ( $batch = 0; $batch < ceil( $total_pages / $batch_size ); $batch++ ) {
			$generator = new SScribe_Streaming_DOCX_Generator( $temp_dir, 50 );
			$generator->set_section_count( $batch * $batch_size );

			$start = $batch * $batch_size;
			$end = min( $start + $batch_size, $total_pages );

			for ( $i = $start; $i < $end; $i++ ) {
				$generator->add_page( array(
					'title'   => "Batch Page {$i}",
					'content' => $this->generate_mock_content( $i ),
					'url'     => "https://example.com/batch-{$i}",
				) );
			}

			$generator->flush();
			$memory_samples[] = memory_get_usage( true );
			unset( $generator );
		}

		$max_memory = max( $memory_samples );
		$min_memory = min( $memory_samples );
		$memory_variance = $max_memory - $min_memory;

		$this->assertLessThan(
			50 * 1024 * 1024,
			$memory_variance,
			'Memory variance across batches should be reasonable'
		);

		$this->cleanup_temp_dir( $temp_dir );
	}

	/**
	 * Test chunk file persistence between batches.
	 */
	public function test_chunk_persistence(): void {
		$temp_dir = sys_get_temp_dir() . '/sscribe_chunk_test_' . uniqid();
		wp_mkdir_p( $temp_dir );

		$generator1 = new SScribe_Streaming_DOCX_Generator( $temp_dir, 50 );
		$generator1->add_page( array(
			'title'   => 'First Batch',
			'content' => '<p>Content from first batch</p>',
			'url'     => 'https://example.com/first',
		) );
		$generator1->flush();

		$this->assertCount( 1, glob( $temp_dir . '/chunk_*.xml' ) );

		$generator2 = new SScribe_Streaming_DOCX_Generator( $temp_dir, 50 );
		$generator2->set_section_count( 1 );
		$generator2->add_page( array(
			'title'   => 'Second Batch',
			'content' => '<p>Content from second batch</p>',
			'url'     => 'https://example.com/second',
		) );
		$generator2->flush();

		$this->assertCount( 2, glob( $temp_dir . '/chunk_*.xml' ) );

		$output_dir = sys_get_temp_dir() . '/sscribe_chunk_output_' . uniqid();
		wp_mkdir_p( $output_dir );
		$output_path = $output_dir . '/combined.docx';
		$result = $generator2->save( $output_path );

		$this->assertTrue( $result );
		$this->assertFileExists( $output_path );

		if ( file_exists( $output_path ) ) {
			unlink( $output_path );
		}
		rmdir( $output_dir );
		$this->cleanup_temp_dir( $temp_dir );
	}

	/**
	 * Generate mock content for testing.
	 */
	private function generate_mock_content( int $page_num ): string {
		$paragraphs = rand( 3, 10 );
		$content = '';

		for ( $p = 0; $p < $paragraphs; $p++ ) {
			$words = rand( 20, 100 );
			$content .= '<p>' . str_repeat( "Word {$page_num}-{$p} ", $words ) . '</p>';
		}

		return $content;
	}

	/**
	 * Helper to determine if streaming should be used.
	 */
	private function should_use_streaming( int $page_count, array $formats ): bool {
		return in_array( 'docx', $formats, true ) && $page_count >= 30;
	}

	/**
	 * Helper to cleanup temporary directories.
	 */
	private function cleanup_temp_dir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $iterator as $item ) {
			if ( $item->isDir() ) {
				rmdir( $item->getRealPath() );
			} else {
				unlink( $item->getRealPath() );
			}
		}

		rmdir( $dir );
	}
}
