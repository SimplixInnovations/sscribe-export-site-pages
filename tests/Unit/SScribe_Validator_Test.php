<?php
/**
 * SScribe Validator Unit Test
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-validator.php';

class SScribe_Validator_Test extends TestCase {

	public function test_validate_formats_valid(): void {
		$errors = SScribe_Validator::validate_formats( array( 'docx', 'pdf' ) );

		$this->assertEmpty( $errors );
	}

	public function test_validate_formats_empty(): void {
		$errors = SScribe_Validator::validate_formats( array() );

		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'No export formats selected', $errors[0] );
	}

	public function test_validate_formats_invalid(): void {
		$errors = SScribe_Validator::validate_formats( array( 'docx', 'invalid_format' ) );

		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'Invalid export format', $errors[0] );
	}

	public function test_validate_page_selection_valid(): void {
		$errors = SScribe_Validator::validate_page_selection( array( 'page_ids' => array( 1, 2, 3 ) ) );

		$this->assertEmpty( $errors );
	}

	public function test_validate_page_selection_empty(): void {
		$errors = SScribe_Validator::validate_page_selection( array( 'page_ids' => array() ) );

		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'No valid page IDs', $errors[0] );
	}

	public function test_validate_page_selection_too_many(): void {
		$page_ids = range( 1, 6000 );
		$errors   = SScribe_Validator::validate_page_selection( array( 'page_ids' => $page_ids ) );

		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'Maximum allowed', $errors[0] );
	}

	public function test_validate_post_status_valid(): void {
		$errors = SScribe_Validator::validate_post_status( 'publish' );

		$this->assertEmpty( $errors );
	}

	public function test_validate_post_status_invalid(): void {
		$errors = SScribe_Validator::validate_post_status( 'invalid_status' );

		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'Invalid post status', $errors[0] );
	}

	public function test_validate_export_config_valid(): void {
		$result = SScribe_Validator::validate_export_config( array(
			'formats'     => array( 'docx' ),
			'page_ids'    => array( 1, 2, 3 ),
			'language'    => '',
			'post_status' => 'publish',
		) );

		$this->assertTrue( $result['valid'] );
		$this->assertEmpty( $result['errors'] );
	}

	public function test_validate_export_config_invalid(): void {
		$result = SScribe_Validator::validate_export_config( array(
			'formats'     => array(),
			'page_ids'    => array(),
			'post_status' => 'invalid',
		) );

		$this->assertFalse( $result['valid'] );
		$this->assertNotEmpty( $result['errors'] );
	}

	public function test_validate_page_data_valid(): void {
		$result = SScribe_Validator::validate_page_data( array(
			'id'      => 1,
			'title'   => 'Test Page',
			'content' => 'Test content',
		) );

		$this->assertTrue( $result['valid'] );
	}

	public function test_validate_page_data_missing_fields(): void {
		$result = SScribe_Validator::validate_page_data( array(
			'id' => 1,
		) );

		$this->assertFalse( $result['valid'] );
		$this->assertNotEmpty( $result['errors'] );
	}

	public function test_sanitize_ajax_input(): void {
		$input = array(
			'page_id'    => '123',
			'name'       => '<script>alert("xss")</script>Test',
			'formats'    => array( 'docx', 'pdf' ),
			'status'     => 'publish',
		);

		$expected = array(
			'page_id'    => 'int',
			'name'       => 'string',
			'formats'    => 'array_string',
			'status'     => 'key',
		);

		$sanitized = SScribe_Validator::sanitize_ajax_input( $input, $expected );

		$this->assertEquals( 123, $sanitized['page_id'] );
		$this->assertStringNotContainsString( '<script>', $sanitized['name'] );
		$this->assertIsArray( $sanitized['formats'] );
		$this->assertEquals( 'publish', $sanitized['status'] );
	}
}
