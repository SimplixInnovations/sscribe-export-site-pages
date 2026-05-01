<?php
/**
 * Unit tests for SScribe_Export_Format enum.
 *
 * @package SScribe
 */

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SScribe_Export_Format;

class SScribe_Export_Format_Test extends TestCase {

	/**
	 * Test that all enum cases exist.
	 */
	public function test_enum_cases_exist(): void {
		$cases = SScribe_Export_Format::cases();
		$this->assertCount( 4, $cases );
	}

	/**
	 * Test DOCX case value.
	 */
	public function test_docx_case_value(): void {
		$this->assertSame( 'docx', SScribe_Export_Format::DOCX->value );
	}

	/**
	 * Test PDF case value.
	 */
	public function test_pdf_case_value(): void {
		$this->assertSame( 'pdf', SScribe_Export_Format::PDF->value );
	}

	/**
	 * Test HTML case value.
	 */
	public function test_html_case_value(): void {
		$this->assertSame( 'html', SScribe_Export_Format::HTML->value );
	}

	/**
	 * Test MARKDOWN case value.
	 */
	public function test_markdown_case_value(): void {
		$this->assertSame( 'markdown', SScribe_Export_Format::MARKDOWN->value );
	}

	/**
	 * Test get_supported_formats returns all formats.
	 */
	public function test_get_supported_formats_returns_all(): void {
		$formats = SScribe_Export_Format::get_supported_formats();
		$this->assertIsArray( $formats );
		$this->assertCount( 4, $formats );
	}

	/**
	 * Test get_supported_formats has correct keys.
	 */
	public function test_get_supported_formats_has_correct_keys(): void {
		$formats = SScribe_Export_Format::get_supported_formats();

		$this->assertArrayHasKey( 'docx', $formats );
		$this->assertArrayHasKey( 'pdf', $formats );
		$this->assertArrayHasKey( 'html', $formats );
		$this->assertArrayHasKey( 'markdown', $formats );
	}

	/**
	 * Test get_supported_formats values are translated strings.
	 */
	public function test_get_supported_formats_values_are_strings(): void {
		$formats = SScribe_Export_Format::get_supported_formats();

		foreach ( $formats as $format ) {
			$this->assertIsString( $format );
		}
	}

	/**
	 * Test enum from value.
	 */
	public function test_enum_from_value(): void {
		$docx = SScribe_Export_Format::from( 'docx' );
		$this->assertSame( SScribe_Export_Format::DOCX, $docx );
	}

	/**
	 * Test enum cases are backed correctly.
	 */
	public function test_enum_is_backed(): void {
		$this->assertInstanceOf( SScribe_Export_Format::class, SScribe_Export_Format::DOCX );
		$this->assertSame( 'docx', SScribe_Export_Format::DOCX->value );
	}

	/**
	 * Test trying invalid value throws.
	 */
	public function test_enum_invalid_value_throws(): void {
		$this->expectException( \ValueError::class );
		SScribe_Export_Format::from( 'invalid' );
	}

	/**
	 * Test all format values are lowercase.
	 */
	public function test_all_values_are_lowercase(): void {
		foreach ( SScribe_Export_Format::cases() as $case ) {
			$this->assertSame( strtolower( $case->value ), $case->value );
		}
	}

	/**
	 * Test docx format is commonly used.
	 */
	public function test_docx_is_first_in_cases(): void {
		$cases = SScribe_Export_Format::cases();
		$this->assertSame( SScribe_Export_Format::DOCX, $cases[0] );
	}
}
