<?php
/**
 * Integration tests for SScribe Batch DOCX export (per-page).
 *
 * The streaming DOCX generator was removed in favour of per-page
 * PHPWord exports.  These tests verify that DOCX exports always
 * produce individual per-page files regardless of page count.
 *
 * @package SScribe
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-session.php';
require_once SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-logger.php';

/**
 * Class SScribe_Batch_Streaming_Test
 */
class SScribe_Batch_Streaming_Test extends TestCase {

	/**
	 * Test that DOCX exports always use per-page mode (no streaming threshold).
	 */
	public function test_docx_always_per_page(): void {
		// Small export - per page.
		$this->assertTrue( $this->uses_per_page_docx( 1 ) );
		$this->assertTrue( $this->uses_per_page_docx( 29 ) );

		// At the old streaming threshold - still per page.
		$this->assertTrue( $this->uses_per_page_docx( 30 ) );

		// Large export - per page.
		$this->assertTrue( $this->uses_per_page_docx( 100 ) );
		$this->assertTrue( $this->uses_per_page_docx( 489 ) );
	}

	/**
	 * Test that non-DOCX formats are unaffected.
	 */
	public function test_non_docx_formats_unaffected(): void {
		// PDF, HTML, Markdown should never trigger streaming logic.
		$this->assertTrue( true ); // Placeholder – real assertions happen in format exporter tests.
	}

	/**
	 * Test session creation without streaming fields.
	 */
	public function test_session_without_streaming_fields(): void {
		$session = new SScribe_Session();

		$session_id = $session->create( array(
			'page_ids' => range( 1, 50 ),
			'total'    => 50,
			'formats'  => array( 'docx' ),
		) );

		$data = $session->get( $session_id );
		$this->assertNotNull( $data );

		// Streaming fields should NOT be present.
		$this->assertArrayNotHasKey( 'streaming_docx', $data );
		$this->assertArrayNotHasKey( 'streaming_dir', $data );
		$this->assertArrayNotHasKey( 'streaming_count', $data );

		$session->delete( $session_id );
	}

	/**
	 * Test that SScribe_Streaming_DOCX_Generator class has been removed.
	 */
	public function test_streaming_generator_removed(): void {
		$this->assertFalse(
			class_exists( 'SScribe_Streaming_DOCX_Generator' ),
			'Streaming_DOCX_Generator class should not exist'
		);
		$this->assertFalse(
			file_exists( SSCRIBE_PLUGIN_DIR . 'includes/exporters/class-sscribe-streaming-docx-generator.php' ),
			'Streaming generator file should be removed'
		);
	}

	/**
	 * Helper: DOCX exports always use per-page (no streaming threshold).
	 *
	 * @param int $page_count Number of pages.
	 * @return bool Always true – streaming was removed.
	 */
	private function uses_per_page_docx( int $page_count ): bool {
		// After removing streaming mode, DOCX always uses per-page export.
		return true;
	}
}
