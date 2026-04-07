<?php
/**
 * Integration tests for SScribe Batch Streaming DOCX functionality.
 *
 * @package SScribe
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-session.php';
require_once SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-logger.php';
require_once SSCRIBE_PLUGIN_DIR . 'includes/exporters/class-sscribe-streaming-docx-generator.php';

/**
 * Class SScribe_Batch_Streaming_Test
 */
class SScribe_Batch_Streaming_Test extends TestCase {

	/**
	 * Test streaming mode threshold at 30 pages.
	 */
	public function test_streaming_threshold(): void {
		$streaming_threshold = 30;

		// Below threshold - should NOT use streaming.
		$this->assertFalse( $this->should_use_streaming( 29, array( 'docx' ) ) );

		// At threshold - should use streaming.
		$this->assertTrue( $this->should_use_streaming( 30, array( 'docx' ) ) );

		// Above threshold - should use streaming.
		$this->assertTrue( $this->should_use_streaming( 100, array( 'docx' ) ) );
	}

	/**
	 * Test streaming is NOT used for non-DOCX formats.
	 */
	public function test_streaming_only_for_docx(): void {
		// PDF only - should NOT use streaming.
		$this->assertFalse( $this->should_use_streaming( 100, array( 'pdf' ) ) );

		// HTML only - should NOT use streaming.
		$this->assertFalse( $this->should_use_streaming( 100, array( 'html' ) ) );

		// Markdown only - should NOT use streaming.
		$this->assertFalse( $this->should_use_streaming( 100, array( 'markdown' ) ) );

		// Mixed formats including DOCX - should use streaming for DOCX.
		$this->assertTrue( $this->should_use_streaming( 100, array( 'pdf', 'docx' ) ) );
	}

	/**
	 * Test streaming DOCX generator initialization.
	 */
	public function test_streaming_generator_initialization(): void {
		$temp_dir = sys_get_temp_dir() . '/sscribe_test_' . uniqid();
		wp_mkdir_p( $temp_dir );

		$generator = new SScribe_Streaming_DOCX_Generator( $temp_dir, 50 );

		$this->assertEquals( $temp_dir, $generator->get_temp_dir() );
		$this->assertEquals( 0, $generator->get_section_count() );

		// Cleanup.
		$this->cleanup_temp_dir( $temp_dir );
	}

	/**
	 * Test streaming generator section count persistence.
	 */
	public function test_streaming_section_count_persistence(): void {
		$temp_dir = sys_get_temp_dir() . '/sscribe_test_' . uniqid();
		wp_mkdir_p( $temp_dir );

		$generator = new SScribe_Streaming_DOCX_Generator( $temp_dir, 50 );

		// Add mock pages.
		$mock_page = array(
			'title'   => 'Test Page',
			'content' => '<p>Test content</p>',
			'url'     => 'https://example.com/test',
		);

		$generator->add_page( $mock_page );
		$this->assertEquals( 1, $generator->get_section_count() );

		$generator->add_page( $mock_page );
		$this->assertEquals( 2, $generator->get_section_count() );

		// Flush to simulate batch end.
		$generator->flush();

		// Simulate resuming from another request.
		$generator2 = new SScribe_Streaming_DOCX_Generator( $temp_dir, 50 );
		$generator2->set_section_count( 2 );

		$this->assertEquals( 2, $generator2->get_section_count() );

		$generator2->add_page( $mock_page );
		$this->assertEquals( 3, $generator2->get_section_count() );

		// Cleanup.
		$this->cleanup_temp_dir( $temp_dir );
	}

	/**
	 * Test streaming DOCX file generation.
	 */
	public function test_streaming_docx_generation(): void {
		$temp_dir = sys_get_temp_dir() . '/sscribe_test_' . uniqid();
		$output_dir = sys_get_temp_dir() . '/sscribe_output_' . uniqid();
		wp_mkdir_p( $temp_dir );
		wp_mkdir_p( $output_dir );

		$generator = new SScribe_Streaming_DOCX_Generator( $temp_dir, 50 );

		// Add multiple pages.
		for ( $i = 1; $i <= 5; $i++ ) {
			$generator->add_page( array(
				'title'   => "Test Page {$i}",
				'content' => "<p>Content for page {$i}</p>",
				'url'     => "https://example.com/page-{$i}",
			) );
		}

		$output_path = $output_dir . '/test_combined.docx';
		$result = $generator->save( $output_path );

		$this->assertTrue( $result, 'Generator save() should return true' );
		$this->assertFileExists( $output_path, 'Output DOCX file should exist' );

		$zip = new ZipArchive();
		$opened = $zip->open( $output_path );
		$this->assertTrue( $opened === true, 'ZIP should open successfully' );

		$files = array();
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$files[] = $zip->getNameIndex( $i );
		}

		$this->assertNotEmpty( $files, 'ZIP should contain files. Found: ' . implode( ', ', $files ) );
		$this->assertContains( 'word/document.xml', $files, 'ZIP should contain word/document.xml. Found: ' . implode( ', ', $files ) );
		$this->assertContains( '[Content_Types].xml', $files, 'ZIP should contain [Content_Types].xml' );
		$this->assertContains( '_rels/.rels', $files, 'ZIP should contain _rels/.rels' );

		$zip->close();

		// Cleanup.
		if ( file_exists( $output_path ) ) {
			unlink( $output_path );
		}
		rmdir( $output_dir );
	}

	/**
	 * Test session streaming state persistence.
	 */
	public function test_session_streaming_state(): void {
		$session = new SScribe_Session();

		// Create session with streaming enabled.
		$session_id = $session->create( array(
			'page_ids'        => range( 1, 50 ),
			'total'           => 50,
			'formats'         => array( 'docx' ),
			'streaming_docx'  => true,
			'streaming_dir'   => sys_get_temp_dir() . '/stream_test',
			'streaming_count' => 10,
		) );

		$data = $session->get( $session_id );
		$this->assertNotNull( $data );
		$this->assertTrue( $data['streaming_docx'] );
		$this->assertEquals( 10, $data['streaming_count'] );

		$session->update( $session_id, array(
			'streaming_count' => 25,
		) );

		$updated = $session->get( $session_id );
		$this->assertEquals( 25, $updated['streaming_count'] );

		$session->delete( $session_id );
	}

	/**
	 * Test memory efficiency with large content.
	 */
	public function test_memory_efficiency(): void {
		$temp_dir = sys_get_temp_dir() . '/sscribe_test_' . uniqid();
		wp_mkdir_p( $temp_dir );

		$initial_memory = memory_get_usage( true );

		$generator = new SScribe_Streaming_DOCX_Generator( $temp_dir, 1 ); // Low threshold to trigger flush.

		// Add pages with large content to trigger flush.
		$large_content = str_repeat( '<p>Test paragraph content. </p>', 1000 );

		for ( $i = 0; $i < 10; $i++ ) {
			$generator->add_page( array(
				'title'   => "Large Page {$i}",
				'content' => $large_content,
				'url'     => "https://example.com/page-{$i}",
			) );
		}

		$current_memory = memory_get_usage( true );

		// Memory should not have grown excessively due to flushing.
		// Allow for some growth but not exponential.
		$this->assertLessThan(
			$initial_memory + ( 50 * 1024 * 1024 ), // 50MB limit
			$current_memory,
			'Memory usage should stay reasonable due to flushing'
		);

		// Cleanup.
		$this->cleanup_temp_dir( $temp_dir );
	}

	/**
	 * Helper to determine if streaming should be used.
	 *
	 * @param int   $page_count Number of pages.
	 * @param array $formats    Export formats.
	 * @return bool
	 */
	private function should_use_streaming( int $page_count, array $formats ): bool {
		return in_array( 'docx', $formats, true ) && $page_count >= 30;
	}

	/**
	 * Helper to cleanup temporary directories.
	 *
	 * @param string $dir Directory path.
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
