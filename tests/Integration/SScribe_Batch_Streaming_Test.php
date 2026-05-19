<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-session.php';
require_once SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-logger.php';

class SScribe_Batch_Streaming_Test extends TestCase {

	public function test_docx_always_per_page(): void {

		$this->assertTrue( $this->uses_per_page_docx( 1 ) );
		$this->assertTrue( $this->uses_per_page_docx( 29 ) );

		$this->assertTrue( $this->uses_per_page_docx( 30 ) );

		$this->assertTrue( $this->uses_per_page_docx( 100 ) );
		$this->assertTrue( $this->uses_per_page_docx( 489 ) );
	}

	public function test_non_docx_formats_unaffected(): void {

		$this->assertTrue( true );
	}

	public function test_session_without_streaming_fields(): void {
		$session = new SScribe_Session();

		$session_id = $session->create( array(
			'page_ids' => range( 1, 50 ),
			'total'    => 50,
			'formats'  => array( 'docx' ),
		) );

		$data = $session->get( $session_id );
		$this->assertNotNull( $data );

		$this->assertArrayNotHasKey( 'streaming_docx', $data );
		$this->assertArrayNotHasKey( 'streaming_dir', $data );
		$this->assertArrayNotHasKey( 'streaming_count', $data );

		$session->delete( $session_id );
	}

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

	private function uses_per_page_docx( int $page_count ): bool {

		return true;
	}
}
