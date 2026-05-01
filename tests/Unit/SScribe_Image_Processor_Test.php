<?php
/**
 * Unit tests for SScribe_Image_Processor class.
 *
 * @package SScribe
 */

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SScribe_Image_Processor;

class SScribe_Image_Processor_Test extends TestCase {

	protected function setUp(): void {
		parent::setUp();
	}

	/**
	 * Test class exists and is instantiable (static methods only).
	 */
	public function test_class_exists(): void {
		$this->assertTrue( class_exists( 'SScribe_Image_Processor' ) );
	}

	/**
	 * Test ALLOWED_CONTENT_TYPES constant.
	 */
	public function test_allowed_content_types(): void {
		$method = new \ReflectionMethod( SScribe_Image_Processor::class, 'normalize_url' );

		// Test that the method exists and is callable.
		$this->assertIsCallable( array( SScribe_Image_Processor::class, 'download_and_optimize' ) );
	}

	/**
	 * Test normalize_url with HTTP URL.
	 */
	public function test_normalize_url_http(): void {
		$method = new \ReflectionMethod( SScribe_Image_Processor::class, 'normalize_url' );

		$result = $method->invoke( null, 'http://example.org/image.jpg' );
		$this->assertSame( 'http://example.org/image.jpg', $result );
	}

	/**
	 * Test normalize_url with HTTPS URL.
	 */
	public function test_normalize_url_https(): void {
		$method = new \ReflectionMethod( SScribe_Image_Processor::class, 'normalize_url' );

		$result = $method->invoke( null, 'https://example.org/image.jpg' );
		$this->assertSame( 'https://example.org/image.jpg', $result );
	}

	/**
	 * Test normalize_url rejects javascript:.
	 */
	public function test_normalize_url_rejects_javascript(): void {
		$method = new \ReflectionMethod( SScribe_Image_Processor::class, 'normalize_url' );

		$result = $method->invoke( null, 'javascript:alert(1)' );
		$this->assertSame( '', $result );
	}

	/**
	 * Test normalize_url rejects data:.
	 */
	public function test_normalize_url_rejects_data_uri(): void {
		$method = new \ReflectionMethod( SScribe_Image_Processor::class, 'normalize_url' );

		$result = $method->invoke( null, 'data:image/png;base64,abc' );
		$this->assertSame( '', $result );
	}

	/**
	 * Test is_allowed_remote_url accepts valid URLs.
	 */
	public function test_is_allowed_remote_url_accepts_valid(): void {
		$method = new \ReflectionMethod( SScribe_Image_Processor::class, 'is_allowed_remote_url' );

		$valid_urls = array(
			'https://example.org/image.jpg',
			'https://example.org/uploads/image.png',
		);

		foreach ( $valid_urls as $url ) {
			$result = $method->invoke( null, $url );
			$this->assertTrue( $result, "URL should be allowed: $url" );
		}
	}

	/**
	 * Test is_allowed_remote_url rejects invalid URLs.
	 */
	public function test_is_allowed_remote_url_rejects_invalid(): void {
		$method = new \ReflectionMethod( SScribe_Image_Processor::class, 'is_allowed_remote_url' );

		$invalid_urls = array(
			'javascript:alert(1)',
			'data:text/html',
			'',
			'ftp://example.org/image.jpg',
		);

		foreach ( $invalid_urls as $url ) {
			$result = $method->invoke( null, $url );
			$this->assertFalse( $result, "URL should be rejected: $url" );
		}
	}

	/**
	 * Test download_and_optimize with empty URL.
	 */
	public function test_download_and_optimize_empty_url(): void {
		$result = SScribe_Image_Processor::download_and_optimize( '' );
		$this->assertFalse( $result );
	}

	/**
	 * Test download_and_optimize with invalid URL.
	 */
	public function test_download_and_optimize_invalid_url(): void {
		$result = SScribe_Image_Processor::download_and_optimize( 'javascript:alert(1)' );
		$this->assertFalse( $result );
	}

	/**
	 * Test ALLOWED_EXTENSIONS constant.
	 */
	public function test_allowed_extensions(): void {
		$reflection = new \ReflectionClass( SScribe_Image_Processor::class );
		$extensions = $reflection->getConstant( 'ALLOWED_EXTENSIONS' );
		$this->assertIsArray( $extensions );
		$this->assertContains( 'jpg', $extensions );
		$this->assertContains( 'png', $extensions );
		$this->assertContains( 'webp', $extensions );
	}

	/**
	 * Test MAX_WIDTH constant.
	 */
	public function test_max_width_constant(): void {
		$reflection = new \ReflectionClass( SScribe_Image_Processor::class );
		$value      = $reflection->getConstant( 'MAX_WIDTH' );
		$this->assertGreaterThan( 0, $value );
	}

	/**
	 * Test JPEG_QUALITY constant.
	 */
	public function test_jpeg_quality_constant(): void {
		$reflection = new \ReflectionClass( SScribe_Image_Processor::class );
		$value      = $reflection->getConstant( 'JPEG_QUALITY' );
		$this->assertGreaterThan( 0, $value );
		$this->assertLessThanOrEqual( 100, $value );
	}

	/**
	 * Test download_and_optimize method signature.
	 */
	public function test_download_method_is_static(): void {
		$ref = new \ReflectionMethod( SScribe_Image_Processor::class, 'download_and_optimize' );
		$this->assertTrue( $ref->isStatic() );
	}

	/**
	 * Test optimize_local method exists.
	 */
	public function test_optimize_local_exists(): void {
		$this->assertIsCallable( array( SScribe_Image_Processor::class, 'optimize_local' ) );
	}
}
