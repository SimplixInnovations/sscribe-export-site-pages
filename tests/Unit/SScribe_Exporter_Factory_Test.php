<?php
/**
 * SScribe Exporter Factory Unit Test
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

class SScribe_Exporter_Factory_Test extends TestCase {

	public function test_create_returns_docx_exporter() {
		$exporter = \SScribe_Exporter_Factory::create( 'docx' );
		$this->assertInstanceOf( \SScribe_Exporter_Interface::class, $exporter );
		$this->assertEquals( 'docx', $exporter->get_extension() );
	}

	public function test_create_returns_html_exporter() {
		$exporter = \SScribe_Exporter_Factory::create( 'html' );
		$this->assertInstanceOf( \SScribe_Exporter_Interface::class, $exporter );
		$this->assertEquals( 'html', $exporter->get_extension() );
	}

	public function test_create_returns_markdown_exporter() {
		$exporter = \SScribe_Exporter_Factory::create( 'markdown' );
		$this->assertInstanceOf( \SScribe_Exporter_Interface::class, $exporter );
		$this->assertEquals( 'md', $exporter->get_extension() );
	}

	public function test_create_returns_null_for_invalid_format() {
		$this->expectException( \SScribe_Validation_Exception::class );
		\SScribe_Exporter_Factory::create( 'invalid' );
	}

	public function test_get_supported_formats_returns_array() {
		$formats = \SScribe_Exporter_Factory::get_supported_formats();
		$this->assertIsArray( $formats );
		$this->assertArrayHasKey( 'docx', $formats );
		$this->assertArrayHasKey( 'pdf', $formats );
		$this->assertArrayHasKey( 'html', $formats );
		$this->assertArrayHasKey( 'markdown', $formats );
	}

	public function test_is_supported_returns_true_for_valid_format() {
		$this->assertTrue( \SScribe_Exporter_Factory::is_supported( 'docx' ) );
		$this->assertTrue( \SScribe_Exporter_Factory::is_supported( 'pdf' ) );
	}

	public function test_is_supported_returns_false_for_invalid_format() {
		$this->assertFalse( \SScribe_Exporter_Factory::is_supported( 'invalid' ) );
	}
}
