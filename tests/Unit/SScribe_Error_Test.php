<?php
/**
 * SScribe Error Unit Test
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

class SScribe_Error_Test extends TestCase {

	public function test_constructor_sets_all_properties(): void {
		$error = new \SScribe_Error(
			'TEST_CODE',
			\SScribe_Error::CATEGORY_SYSTEM,
			\SScribe_Error::SEVERITY_CRITICAL,
			'Test message',
			'Test details',
			'Test guidance',
			array( 'Step 1', 'Step 2' ),
			'https://docs.example.com',
			array( 'key' => 'value' )
		);

		$this->assertEquals( 'TEST_CODE', $error->get_code() );
		$this->assertEquals( \SScribe_Error::CATEGORY_SYSTEM, $error->get_category() );
		$this->assertEquals( \SScribe_Error::SEVERITY_CRITICAL, $error->get_severity() );
		$this->assertEquals( 'Test message', $error->get_message() );
		$this->assertEquals( 'Test details', $error->get_details() );
		$this->assertEquals( 'Test guidance', $error->get_guidance() );
		$this->assertEquals( array( 'Step 1', 'Step 2' ), $error->get_fix_steps() );
		$this->assertEquals( 'https://docs.example.com', $error->get_doc_url() );
		$this->assertEquals( array( 'key' => 'value' ), $error->get_context() );
	}

	public function test_constructor_sets_timestamp(): void {
		$error = new \SScribe_Error( 'TS', \SScribe_Error::CATEGORY_SYSTEM, \SScribe_Error::SEVERITY_INFO, 'msg' );
		$this->assertIsInt( $error->get_timestamp() );
		$this->assertGreaterThan( 0, $error->get_timestamp() );
	}

	public function test_from_template_with_known_code(): void {
		$error = \SScribe_Error::from_template( 'SYSTEM_ZIP_EXTENSION_MISSING' );
		$this->assertInstanceOf( \SScribe_Error::class, $error );
		$this->assertEquals( 'SYSTEM_ZIP_EXTENSION_MISSING', $error->get_code() );
		$this->assertEquals( \SScribe_Error::CATEGORY_SYSTEM, $error->get_category() );
		$this->assertEquals( \SScribe_Error::SEVERITY_CRITICAL, $error->get_severity() );
		$this->assertStringContainsString( 'ZIP', $error->get_message() );
	}

	public function test_from_template_with_unknown_code(): void {
		$error = \SScribe_Error::from_template( 'NONEXISTENT_CODE' );
		$this->assertEquals( 'UNKNOWN_ERROR', $error->get_code() );
		$this->assertEquals( \SScribe_Error::CATEGORY_SYSTEM, $error->get_category() );
		$this->assertEquals( \SScribe_Error::SEVERITY_ERROR, $error->get_severity() );
	}

	public function test_from_template_interpolates_context(): void {
		$error = \SScribe_Error::from_template(
			'RESOURCE_MEMORY_EXHAUSTED',
			array( 'memory_usage' => '128M', 'memory_limit' => '256M' )
		);
		$this->assertStringContainsString( '128M', $error->get_details() );
		$this->assertStringContainsString( '256M', $error->get_details() );
	}

	public function test_from_template_with_export_format(): void {
		$error = \SScribe_Error::from_template(
			'EXPORT_DOCX_FAILED',
			array( 'title' => 'My Page', 'error' => 'Out of memory' )
		);
		$this->assertStringContainsString( 'My Page', $error->get_message() );
	}

	public function test_interpolate_replaces_placeholders(): void {
		$result = \SScribe_Error::interpolate(
			'Hello {name}, your {item} is ready.',
			array( 'name' => 'John', 'item' => 'report' )
		);
		$this->assertEquals( 'Hello John, your report is ready.', $result );
	}

	public function test_interpolate_handles_missing_keys(): void {
		$result = \SScribe_Error::interpolate(
			'Value: {missing}',
			array( 'other' => 'val' )
		);
		$this->assertEquals( 'Value: {missing}', $result );
	}

	public function test_get_templates_returns_all_templates(): void {
		$templates = \SScribe_Error::get_templates();
		$this->assertIsArray( $templates );
		$this->assertArrayHasKey( 'SYSTEM_ZIP_EXTENSION_MISSING', $templates );
		$this->assertArrayHasKey( 'VALIDATION_NO_PAGES_SELECTED', $templates );
		$this->assertArrayHasKey( 'SESSION_EXPIRED', $templates );
		$this->assertArrayHasKey( 'RATE_LIMIT_EXCEEDED', $templates );
	}

	public function test_to_array_without_details(): void {
		$error = new \SScribe_Error( 'TEST', 'system', 'error', 'msg', 'details', 'guidance' );
		$arr   = $error->to_array();
		$this->assertArrayHasKey( 'code', $arr );
		$this->assertArrayHasKey( 'category', $arr );
		$this->assertArrayHasKey( 'severity', $arr );
		$this->assertArrayHasKey( 'message', $arr );
		$this->assertArrayHasKey( 'guidance', $arr );
		$this->assertArrayNotHasKey( 'details', $arr );
		$this->assertArrayNotHasKey( 'timestamp', $arr );
	}

	public function test_to_array_with_details(): void {
		$error = new \SScribe_Error( 'TEST', 'system', 'error', 'msg', 'details' );
		$arr   = $error->to_array( true );
		$this->assertArrayHasKey( 'details', $arr );
		$this->assertArrayHasKey( 'timestamp', $arr );
		$this->assertArrayHasKey( 'context', $arr );
	}

	public function test_to_array_includes_fix_steps(): void {
		$error = new \SScribe_Error( 'TEST', 'system', 'error', 'msg', '', '', array( 'Step A' ) );
		$arr   = $error->to_array();
		$this->assertArrayHasKey( 'fix_steps', $arr );
		$this->assertEquals( array( 'Step A' ), $arr['fix_steps'] );
	}

	public function test_to_array_includes_doc_url(): void {
		$error = new \SScribe_Error( 'TEST', 'system', 'error', 'msg', '', '', array(), 'https://example.com' );
		$arr   = $error->to_array();
		$this->assertArrayHasKey( 'doc_url', $arr );
	}

	public function test_to_string_with_message_only(): void {
		$error = new \SScribe_Error( 'T', 's', 'e', 'Simple message' );
		$this->assertEquals( 'Simple message', (string) $error );
	}

	public function test_to_string_with_guidance(): void {
		$error = new \SScribe_Error( 'T', 's', 'e', 'Error occurred', '', 'Try again' );
		$str   = (string) $error;
		$this->assertStringContainsString( 'Error occurred', $str );
		$this->assertStringContainsString( 'Try again', $str );
	}

	public function test_to_string_with_fix_steps(): void {
		$error = new \SScribe_Error( 'T', 's', 'e', 'Failed', '', 'Help', array( 'Step 1', 'Step 2' ) );
		$str   = (string) $error;
		$this->assertStringContainsString( 'Steps to fix:', $str );
		$this->assertStringContainsString( '1. Step 1', $str );
		$this->assertStringContainsString( '2. Step 2', $str );
	}

	public function test_to_string_with_all(): void {
		$error = new \SScribe_Error(
			'FULL', 'system', 'error', 'Main msg', 'Detail', 'Guidance',
			array( 'Fix 1' ), 'https://docs.example.com',
			array( 'trace' => '123' )
		);
		$str = (string) $error;
		$this->assertStringContainsString( 'Main msg', $str );
		$this->assertStringContainsString( 'Guidance', $str );
		$this->assertStringContainsString( 'Fix 1', $str );
	}

	public function test_error_category_constants(): void {
		$this->assertEquals( 'system', \SScribe_Error::CATEGORY_SYSTEM );
		$this->assertEquals( 'permission', \SScribe_Error::CATEGORY_PERMISSION );
		$this->assertEquals( 'resource', \SScribe_Error::CATEGORY_RESOURCE );
		$this->assertEquals( 'content', \SScribe_Error::CATEGORY_CONTENT );
		$this->assertEquals( 'export', \SScribe_Error::CATEGORY_EXPORT );
		$this->assertEquals( 'network', \SScribe_Error::CATEGORY_NETWORK );
		$this->assertEquals( 'validation', \SScribe_Error::CATEGORY_VALIDATION );
	}

	public function test_error_severity_constants(): void {
		$this->assertEquals( 'critical', \SScribe_Error::SEVERITY_CRITICAL );
		$this->assertEquals( 'error', \SScribe_Error::SEVERITY_ERROR );
		$this->assertEquals( 'warning', \SScribe_Error::SEVERITY_WARNING );
		$this->assertEquals( 'info', \SScribe_Error::SEVERITY_INFO );
	}
}
