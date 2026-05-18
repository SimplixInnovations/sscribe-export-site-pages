<?php
/**
 * Unit tests for SScribe_Export_Error_Handler class.
 *
 * @package SScribe
 */

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

class SScribe_Export_Error_Handler_Test extends TestCase {

	public function test_build_diagnostics_payload_returns_expected_structure(): void {
		$handler = new \SScribe_Export_Error_Handler();

		$structured_errors = array(
			array(
				'page_id'    => 1,
				'page_title' => 'Test Page',
				'errors'     => array(
					array(
						'format'   => 'DOCX',
						'message'  => 'Memory exhausted',
						'category' => 'memory_exhausted',
						'context'  => array(
							'html_size'   => 500000,
							'memory_peak' => 134217728,
						),
					),
				),
				'time' => '2024-01-01 00:00:00',
			),
		);

		$result = $handler->build_diagnostics_payload( $structured_errors );

		$this->assertArrayHasKey( 'total_errors', $result );
		$this->assertArrayHasKey( 'categories', $result );
		$this->assertArrayHasKey( 'guidance', $result );
		$this->assertArrayHasKey( 'fix_steps', $result );
		$this->assertArrayHasKey( 'technical', $result );
		$this->assertArrayHasKey( 'entries', $result );
		$this->assertIsInt( $result['total_errors'] );
		$this->assertIsArray( $result['categories'] );
		$this->assertIsString( $result['guidance'] );
		$this->assertIsArray( $result['fix_steps'] );
	}

	public function test_build_diagnostics_payload_string_errors_fallback(): void {
		$handler = new \SScribe_Export_Error_Handler();

		$string_errors = array(
			'A system error occurred during export.',
			'Could not read output file.',
		);

		$result = $handler->build_diagnostics_payload( array(), $string_errors );

		$this->assertEquals( 2, $result['total_errors'] );
		$this->assertNotEmpty( $result['entries'] );
	}

	public function test_build_structured_errors_from_log_returns_correct_structure(): void {
		$handler = new \SScribe_Export_Error_Handler();

		$log_data = array(
			'pages' => array(
				1 => array(
					'title'   => 'Test Page',
					'formats' => array(
						'docx' => array( 'success' => false, 'error' => 'Generation failed' ),
					),
				),
			),
		);

		$errors = $handler->build_structured_errors_from_log( $log_data );

		$this->assertCount( 1, $errors );
		$this->assertEquals( 1, $errors[0]['page_id'] );
		$this->assertCount( 1, $errors[0]['errors'] );
		$this->assertEquals( 'DOCX', $errors[0]['errors'][0]['format'] );
		$this->assertEquals( 'Generation failed', $errors[0]['errors'][0]['message'] );
	}

	public function test_build_structured_errors_from_log_handles_plain_array_formats(): void {
		$handler = new \SScribe_Export_Error_Handler();

		// When formats are plain arrays (['docx', 'pdf']), they should be
		// treated as successful entries and not produce errors.
		$log_data = array(
			'pages' => array(
				1 => array(
					'title'   => 'Test Page',
					'formats' => array( 'docx', 'pdf' ),
				),
			),
		);

		$errors = $handler->build_structured_errors_from_log( $log_data );

		$this->assertCount( 0, $errors );
	}

	public function test_build_structured_errors_from_log_with_page_error_fallback(): void {
		$handler = new \SScribe_Export_Error_Handler();

		$log_data = array(
			'pages' => array(
				1 => array(
					'title'   => 'Test Page',
					'formats' => array(),
					'error'   => 'Critical failure',
				),
			),
		);

		$errors = $handler->build_structured_errors_from_log( $log_data );

		$this->assertCount( 1, $errors );
		$this->assertEquals( 'SYSTEM', $errors[0]['errors'][0]['format'] );
	}

	public function test_get_guidance_for_known_category_returns_non_empty(): void {
		$handler = new \SScribe_Export_Error_Handler();
		$text    = $handler->get_guidance_for_category( 'memory_exhausted' );
		$this->assertNotEmpty( $text );
		$this->assertIsString( $text );
	}

	public function test_get_guidance_for_unknown_category_returns_fallback(): void {
		$handler = new \SScribe_Export_Error_Handler();
		$text    = $handler->get_guidance_for_category( 'nonexistent_category_xyz' );
		$this->assertNotEmpty( $text );
		$this->assertIsString( $text );
	}

	public function test_get_guidance_for_category_returns_translated_text(): void {
		$handler = new \SScribe_Export_Error_Handler();
		$text    = $handler->get_guidance_for_category( 'unknown' );
		$this->assertNotEmpty( $text );
	}
}
