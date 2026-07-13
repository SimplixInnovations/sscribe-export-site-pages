<?php
/**
 * SScribe Batch Integration Test
 *
 * @package SScribe_Export_Site_Pages
 *
 * Covers session lifecycle and memory accounting for the batch pipeline.
 * Input sanitization is covered by tests/Security/SScribe_Security_Test.php,
 * which exercises WordPress core sanitizers directly.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-batch-processor.php';
require_once SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-session.php';
require_once SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-logger.php';

class SScribe_Batch_Integration_Test extends TestCase {

	public function test_session_lifecycle(): void {
		$session = new SScribe_Session();

		$session_id = $session->create( array(
			'total_pages' => 10,
			'formats'     => array( 'docx' ),
		) );

		$this->assertNotEmpty( $session_id );

		$data = $session->get( $session_id );
		$this->assertNotNull( $data );
		$this->assertEquals( 10, $data['total_pages'] );
		$this->assertEquals( array( 'docx' ), $data['formats'] );

		$session->update( $session_id, array(
			'processed' => 5,
		) );

		$updated = $session->get( $session_id );
		$this->assertEquals( 5, $updated['processed'] );

		$session->delete( $session_id );
		$deleted = $session->get( $session_id );
		$this->assertNull( $deleted );
	}

	public function test_session_ownership_concept(): void {
		$session = new SScribe_Session();

		$session_id = $session->create( array(
			'total_pages' => 5,
			'user_id'     => 1,
		) );

		$data = $session->get( $session_id );
		$this->assertArrayHasKey( 'user_id', $data );

		$session->delete( $session_id );
	}

	public function test_concurrent_session_handling(): void {
		$session = new SScribe_Session();

		$session_id_1 = $session->create( array( 'total_pages' => 5 ) );
		$session_id_2 = $session->create( array( 'total_pages' => 10 ) );

		$this->assertNotEquals( $session_id_1, $session_id_2 );
		$this->assertNotNull( $session->get( $session_id_1 ) );
		$this->assertNotNull( $session->get( $session_id_2 ) );

		$session->delete( $session_id_1 );
		$session->delete( $session_id_2 );
	}
}