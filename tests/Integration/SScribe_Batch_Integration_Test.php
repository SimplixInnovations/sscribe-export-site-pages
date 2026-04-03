<?php
/**
 * Integration tests for SScribe Batch Export flow.
 *
 * @package SScribe
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-batch-processor.php';
require_once SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-validator.php';
require_once SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-session.php';
require_once SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-logger.php';

/**
 * Class SScribe_Batch_Integration_Test
 */
class SScribe_Batch_Integration_Test extends TestCase {

	/**
	 * Test export configuration validation flow.
	 */
	public function test_export_config_validation_flow(): void {
		$config = array(
			'formats'     => array( 'docx' ),
			'page_ids'    => array( 1, 2, 3, 4, 5 ),
			'language'    => '',
			'post_status' => 'publish',
		);

		$result = SScribe_Validator::validate_export_config( $config );

		$this->assertTrue( $result['valid'] );
		$this->assertEmpty( $result['errors'] );
	}

	/**
	 * Test export session lifecycle.
	 */
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

	/**
	 * Test memory validation with realistic estimates.
	 */
	public function test_memory_validation_estimates(): void {
		$errors = SScribe_Validator::validate_memory_availability( 100, array( 'docx' ) );
		$this->assertIsArray( $errors );

		$errors = SScribe_Validator::validate_memory_availability( 100, array( 'pdf' ) );
		$this->assertIsArray( $errors );

		$errors = SScribe_Validator::validate_memory_availability( 100, array( 'docx', 'pdf' ) );
		$this->assertIsArray( $errors );
	}

	/**
	 * Test page data validation.
	 */
	public function test_page_data_validation_flow(): void {
		$valid_page = array(
			'id'      => 1,
			'title'   => 'Test Page',
			'content' => '<p>Test content</p>',
			'url'     => 'https://example.com/test-page',
			'author'  => 'Admin',
		);

		$result = SScribe_Validator::validate_page_data( $valid_page );
		$this->assertTrue( $result['valid'] );

		$invalid_page = array(
			'id' => 1,
		);

		$result = SScribe_Validator::validate_page_data( $invalid_page );
		$this->assertFalse( $result['valid'] );
		$this->assertNotEmpty( $result['errors'] );
	}

	/**
	 * test format validation with all supported formats.
	 */
	public function test_all_format_validation(): void {
		$all_formats = array( 'docx', 'pdf', 'html', 'markdown' );

		$errors = SScribe_Validator::validate_formats( $all_formats );
		$this->assertEmpty( $errors );
	}

	/**
	 * Test DOCX integrity validation.
	 */
	public function test_docx_integrity_validation(): void {
		$temp_file = sys_get_temp_dir() . '/test_invalid_' . uniqid() . '.docx';
		file_put_contents( $temp_file, 'not a valid docx' );

		$result = SScribe_Validator::validate_docx_integrity( $temp_file );
		$this->assertFalse( $result );

		unlink( $temp_file );

		$result = SScribe_Validator::validate_docx_integrity( '/nonexistent/file.docx' );
		$this->assertFalse( $result );
	}

	/**
	 * Test AJAX input sanitization flow.
	 */
	public function test_ajax_input_sanitization_flow(): void {
		$malicious_input = array(
			'page_ids'   => array( '1', '2', 'invalid' ),
			'format'     => '<img src=x onerror=alert(1)>',
			'nonce'      => 'valid_nonce_123',
			'redirect'   => 'javascript:alert(1)',
		);

		$expected = array(
			'page_ids' => 'array_int',
			'format'   => 'string',
			'nonce'    => 'key',
			'redirect' => 'url',
		);

		$sanitized = SScribe_Validator::sanitize_ajax_input( $malicious_input, $expected );

		$this->assertEquals( array( 1, 2 ), array_values( $sanitized['page_ids'] ) );
		$this->assertStringNotContainsString( '<script>', $sanitized['format'] );
		$this->assertMatchesRegularExpression( '/^[a-z0-9_\-]+$/', $sanitized['nonce'] );
		$this->assertEquals( '', $sanitized['redirect'] );
	}

	/**
	 * Test batch size limits.
	 */
	public function test_batch_size_limits(): void {
		$too_many_pages = range( 1, 6000 );

		$errors = SScribe_Validator::validate_page_selection( array( 'page_ids' => $too_many_pages ) );

		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'Maximum', $errors[0] );
	}

	/**
	 * Test post status validation.
	 */
	public function test_post_status_validation_flow(): void {
		$valid_statuses = array( 'publish', 'draft', 'pending', 'private', 'future', 'all' );

		foreach ( $valid_statuses as $status ) {
			$errors = SScribe_Validator::validate_post_status( $status );
			$this->assertEmpty( $errors, "Status '$status' should be valid" );
		}

		$errors = SScribe_Validator::validate_post_status( 'invalid_status' );
		$this->assertNotEmpty( $errors );
	}

	/**
	 * Test export session ownership concept.
	 */
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

	/**
	 * Test error propagation through export flow.
	 */
	public function test_error_propagation(): void {
		$invalid_config = array(
			'formats'     => array( 'invalid_format' ),
			'page_ids'    => array(),
			'post_status' => 'invalid',
		);

		$result = SScribe_Validator::validate_export_config( $invalid_config );

		$this->assertFalse( $result['valid'] );
		$this->assertGreaterThanOrEqual( 2, count( $result['errors'] ) );
	}

	/**
	 * Test concurrent session handling concept.
	 */
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
