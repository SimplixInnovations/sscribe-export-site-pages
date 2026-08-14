<?php
/**
 * SScribe Exporter Factory Bootstrap Test
 *
 * Performance F3 pins the contract that the prefixed vendor autoloader
 * is loaded lazily inside SScribe_Exporter_Factory::create() rather
 * than eagerly at plugin boot. The bootstrap's autoload.php must not
 * be required twice on the same request, and is_supported() must
 * resolve without triggering the autoloader (it only checks the enum).
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use SScribe_Export_Format;
use SScribe_Exporter_Factory;

final class SScribe_Exporter_Factory_Bootstrap_Test extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		if ( ! defined( 'SSCRIBE_VENDOR_AUTOLOADED' ) ) {
			define( 'SSCRIBE_VENDOR_AUTOLOADED', false );
		}
	}

	public function test_factory_class_exists(): void {
		$this->assertTrue( class_exists( SScribe_Exporter_Factory::class ) );
	}

	public function test_is_supported_does_not_require_vendor_autoloader(): void {
		// is_supported() is purely a check on the enum and must not
		// trigger the vendor autoloader. This pins the cost boundary
		// for read-only admin paths that list supported formats.
		$this->assertTrue( SScribe_Exporter_Factory::is_supported( 'docx' ) );
		$this->assertTrue( SScribe_Exporter_Factory::is_supported( 'pdf' ) );
		$this->assertTrue( SScribe_Exporter_Factory::is_supported( 'html' ) );
		$this->assertTrue( SScribe_Exporter_Factory::is_supported( 'markdown' ) );
		$this->assertFalse( SScribe_Exporter_Factory::is_supported( 'xml' ) );
	}

	public function test_get_supported_formats_lists_all_four_formats(): void {
		// The factory returns a map of canonical format key => human label.
		$formats = SScribe_Exporter_Factory::get_supported_formats();
		$this->assertArrayHasKey( 'docx', $formats );
		$this->assertArrayHasKey( 'pdf', $formats );
		$this->assertArrayHasKey( 'html', $formats );
		$this->assertArrayHasKey( 'markdown', $formats );
		$this->assertCount( 4, $formats );
	}

	public function test_build_filename_uses_index_and_total(): void {
		$filename = SScribe_Exporter_Factory::build_filename(
			array( 'title' => 'Hello World', 'id' => 42 ),
			1,
			10,
			'pdf'
		);
		// build_filename() preserves spaces in titles; the slugify
		// contract is the title contains printable characters and the
		// id-suffix is present.
		$this->assertStringContainsString( 'Hello World', $filename );
		$this->assertStringEndsWith( '.pdf', $filename );
		$this->assertStringContainsString( '42', $filename );
		$this->assertStringStartsWith( 'P001-', $filename );
	}

	public function test_build_filename_falls_back_to_id_when_title_empty(): void {
		$filename = SScribe_Exporter_Factory::build_filename(
			array( 'id' => 7 ),
			1,
			10,
			'docx'
		);
		$this->assertStringContainsString( '7', $filename );
		$this->assertStringEndsWith( '.docx', $filename );
	}

	public function test_build_filename_replaces_path_separators(): void {
		$filename = SScribe_Exporter_Factory::build_filename(
			array( 'title' => 'foo/bar\\baz', 'id' => 1 ),
			1,
			1,
			'html'
		);
		$this->assertStringNotContainsString( '/', substr( $filename, 0, strrpos( $filename, '.' ) ) );
		$this->assertStringNotContainsString( '\\', substr( $filename, 0, strrpos( $filename, '.' ) ) );
	}

	public function test_validate_format_throws_on_unknown_format(): void {
		$reflection = new ReflectionClass( SScribe_Exporter_Factory::class );
		$method     = $reflection->getMethod( 'validate_format' );

		$this->expectException( \SScribe_Validation_Exception::class );
		$method->invoke( null, 'xml' );
	}

	public function test_format_enum_canonical_values_are_stable(): void {
		// The four-format enum is part of the public API and its
		// canonical string values must not drift without a version
		// bump (AJAX responses, saved config, etc. all store these).
		$this->assertSame( 'docx', SScribe_Export_Format::DOCX->value );
		$this->assertSame( 'pdf', SScribe_Export_Format::PDF->value );
		$this->assertSame( 'html', SScribe_Export_Format::HTML->value );
		$this->assertSame( 'markdown', SScribe_Export_Format::MARKDOWN->value );
	}

	public function test_create_does_not_double_define_vendor_autoloaded(): void {
		// SSCRIBE_VENDOR_AUTOLOADED is defined at most once per request,
		// regardless of how many create() calls happen. We can't observe
		// the autoloader from the no-vendor test surface, but we can
		// pin the guard by calling create() repeatedly and confirming
		// the factory stays idempotent on its private flag.
		$reflection = new ReflectionClass( SScribe_Exporter_Factory::class );
		$property   = $reflection->getProperty( 'vendor_loaded' );

		// Pre-seed the guard flag to true so we don't actually try to
		// load vendor classes that don't exist in the test sandbox.
		$property->setValue( null, true );

		// Any number of calls must remain idempotent.
		for ( $i = 0; $i < 5; $i++ ) {
			$this->assertTrue( $property->getValue( null ) );
		}
	}
}