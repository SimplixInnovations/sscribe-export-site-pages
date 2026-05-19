<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once SSCRIBE_PLUGIN_DIR . 'includes/exceptions/class-sscribe-exception.php';
require_once SSCRIBE_PLUGIN_DIR . 'includes/exceptions/class-sscribe-memory-exception.php';
require_once SSCRIBE_PLUGIN_DIR . 'includes/exceptions/class-sscribe-permission-exception.php';
require_once SSCRIBE_PLUGIN_DIR . 'includes/exceptions/class-sscribe-validation-exception.php';
require_once SSCRIBE_PLUGIN_DIR . 'includes/exceptions/class-sscribe-export-exception.php';
require_once SSCRIBE_PLUGIN_DIR . 'includes/exceptions/class-sscribe-session-exception.php';

class SScribe_Exception_Test extends TestCase {

	public function test_base_exception_properties(): void {
		$exception = new SScribe_Exception(
			'E_EXPORT_001',
			'Test error message',
			500,
			array( 'key' => 'value' )
		);

		$this->assertEquals( 'E_EXPORT_001', $exception->get_error_code() );
		$this->assertEquals( 'Test error message', $exception->getMessage() );
		$this->assertEquals( 500, $exception->get_http_status_code() );
		$this->assertEquals( array( 'key' => 'value', 'status' => 500 ), $exception->get_error_data() );
	}

	public function test_to_array(): void {
		$exception = new SScribe_Exception(
			'E_EXPORT_002',
			'Permission denied',
			403,
			array( 'path' => '/test/path' )
		);

		$array = $exception->to_array( false );

		$this->assertEquals( 'E_EXPORT_002', $array['code'] );
		$this->assertEquals( 'Permission denied', $array['message'] );
		$this->assertEquals( 403, $array['httpStatus'] );
		$this->assertArrayNotHasKey( 'context', $array );
	}

	public function test_to_array_with_details(): void {
		$exception = new SScribe_Exception(
			'E_EXPORT_003',
			'Invalid data',
			400,
			array( 'field' => 'title' )
		);

		$array = $exception->to_array( true );

		$this->assertArrayHasKey( 'context', $array );
		$this->assertArrayHasKey( 'file', $array );
		$this->assertArrayHasKey( 'line', $array );
	}

	public function test_memory_exception(): void {
		$exception = new SScribe_Memory_Exception(
			null,
			256 * 1024 * 1024,
			300 * 1024 * 1024,
			array( 'pages' => 50 )
		);

		$this->assertEquals( SScribe_Exception::CODE_MEMORY_EXHAUSTED, $exception->get_error_code() );
		$this->assertEquals( 256 * 1024 * 1024, $exception->get_memory_limit() );
		$this->assertEquals( 300 * 1024 * 1024, $exception->get_memory_used() );
		$this->assertGreaterThan( 0, $exception->get_recommended_memory_limit() );
	}

	public function test_permission_exception(): void {
		$exception = new SScribe_Permission_Exception(
			'/var/www/uploads',
			null,
			'write'
		);

		$this->assertEquals( SScribe_Exception::CODE_PERMISSION_DENIED, $exception->get_error_code() );
		$this->assertEquals( '/var/www/uploads', $exception->get_path() );
		$this->assertEquals( 'write', $exception->get_permission_type() );
		$this->assertEquals( 403, $exception->get_http_status_code() );
	}

	public function test_validation_exception(): void {
		$exception = new SScribe_Validation_Exception(
			'Invalid format specified',
			'format',
			'required'
		);

		$this->assertEquals( SScribe_Exception::CODE_VALIDATION_FAILED, $exception->get_error_code() );
		$this->assertEquals( 'format', $exception->get_field() );
		$this->assertEquals( 'required', $exception->get_rule() );
		$this->assertEquals( 400, $exception->get_http_status_code() );
		$this->assertTrue( $exception->is_recoverable() );
	}

	public function test_export_exception(): void {
		$exception = new SScribe_Export_Exception(
			'Failed to generate DOCX',
			123,
			'docx'
		);

		$this->assertEquals( SScribe_Exception::CODE_DOCX_GENERATION_FAILED, $exception->get_error_code() );
		$this->assertEquals( 123, $exception->get_page_id() );
		$this->assertEquals( 'docx', $exception->get_format() );
	}

	public function test_export_exception_pdf(): void {
		$exception = new SScribe_Export_Exception(
			'Failed to generate PDF',
			456,
			'pdf'
		);

		$this->assertEquals( SScribe_Exception::CODE_PDF_GENERATION_FAILED, $exception->get_error_code() );
	}

	public function test_session_exception(): void {
		$exception = new SScribe_Session_Exception(
			null,
			'sess_abc123',
			true
		);

		$this->assertEquals( SScribe_Exception::CODE_SESSION_EXPIRED, $exception->get_error_code() );
		$this->assertEquals( 'sess_abc123', $exception->get_session_id() );
		$this->assertEquals( 410, $exception->get_http_status_code() );
	}

	public function test_session_exception_corrupted(): void {
		$exception = new SScribe_Session_Exception(
			null,
			'sess_xyz789',
			false
		);

		$this->assertEquals( SScribe_Exception::CODE_SESSION_CORRUPTED, $exception->get_error_code() );
	}

	public function test_exception_chaining(): void {
		$previous = new \RuntimeException( 'Original error' );

		$exception = new SScribe_Export_Exception(
			'Export failed due to underlying error',
			1,
			'docx',
			array(),
			$previous
		);

		$this->assertSame( $previous, $exception->getPrevious() );
	}

	public function test_export_exception_retryable(): void {
		$exception = new SScribe_Export_Exception( 'Temporary error', 1, 'docx' );

		$this->assertFalse( $exception->is_retryable() );

		$exception->set_retryable( true );

		$this->assertTrue( $exception->is_retryable() );
	}
}
