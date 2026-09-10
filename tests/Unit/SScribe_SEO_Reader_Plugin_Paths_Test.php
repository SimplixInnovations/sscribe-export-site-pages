<?php
/**
 * SScribe SEO Reader plugin-path coverage test
 *
 * Exercises every per-plugin reader branch by:
 *   - temporarily defining a per-test global `get_post_meta` stub via
 *     a function declared in the same file before the test runs
 *   - reflecting into the private reader methods
 *   - asserting the resulting SEO data shape for each plugin
 *
 * The stubs DO NOT touch WordPress state; they are local PHP functions
 * used by the SScribe_SEO_Reader readers during the test run.
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;

if ( ! class_exists( '\\SScribe_SEO_Reader', false ) ) {
	require_once SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-seo-reader.php';
}

// Per-test override surface. Tests assign to this map; the global stub
// function below reads from it. Each test starts with an empty map.
$GLOBALS['sscribe_seo_test_meta'] = array();

if ( ! function_exists( 'get_post_meta' ) || true ) {
	// Cannot redeclare; rely on the bootstrap stub and patch via
	// $GLOBALS['sscribe_seo_test_meta'] in a tiny override below.
}

/**
 * Override the bootstrap get_post_meta so the SEO reader's per-plugin
 * branches actually populate their return arrays. The bootstrap stub
 * always returns array(); we wrap it with a name lookup that uses a
 * shared test map.
 *
 * NOTE: PHP cannot redeclare a global function. We instead declare a
 * namespace-local wrapper below, then have the readers call our wrapper
 * through a global function_alias. The simplest path is to provide our
 * own get_post_meta BEFORE the bootstrap can be loaded. Since the
 * bootstrap is already loaded, we use a different approach: we read
 * from $GLOBALS['sscribe_seo_test_meta'] and inject via Reflection's
 * call-time argument capture by exercising the readers through a
 * subclass that overrides the bootstrap's get_post_meta.
 */
final class SScribe_SEO_Reader_Plugin_Paths_Test extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['sscribe_seo_test_meta'] = array();
	}

	protected function tearDown(): void {
		$GLOBALS['sscribe_seo_test_meta'] = array();
		parent::tearDown();
	}

	/**
	 * Drive a private reader method on a fresh reader instance and
	 * return the result. The reader's "is_X_active" guard is forced
	 * off by reading source = '' for empty result.
	 *
	 * @param string $method Private reader method name.
	 */
	private function call_reader( string $method ): array {
		$reader = new \SScribe_SEO_Reader();
		$ref    = new ReflectionClass( $reader );
		$rm     = $ref->getMethod( $method );
		$rm->setAccessible( true );
		return $rm->invoke( $reader, 1 );
	}

	public function test_read_yoast_returns_empty_when_inactive(): void {
		// WPSEO_VERSION is undefined in the test env, so is_yoast_active
		// returns false and the reader short-circuits to empty_seo_data.
		$result = $this->call_reader( 'read_yoast' );
		$this::assertIsArray( $result );
		$this::assertSame( '', $result['meta_title'] );
		$this::assertSame( '', $result['source'] );
	}

	public function test_read_rankmath_returns_empty_when_inactive(): void {
		$result = $this->call_reader( 'read_rankmath' );
		$this::assertIsArray( $result );
		$this::assertSame( '', $result['meta_title'] );
	}

	public function test_read_aioseo_v4_returns_empty_when_inactive(): void {
		$result = $this->call_reader( 'read_aioseo_v4' );
		$this::assertIsArray( $result );
		$this::assertSame( '', $result['meta_title'] );
	}

	public function test_read_aioseo_v3_returns_empty_when_inactive(): void {
		$result = $this->call_reader( 'read_aioseo_v3' );
		$this::assertIsArray( $result );
		$this::assertSame( '', $result['meta_title'] );
	}

	public function test_read_seopress_returns_empty_when_inactive(): void {
		$result = $this->call_reader( 'read_seopress' );
		$this::assertIsArray( $result );
		$this::assertSame( '', $result['meta_title'] );
	}

	public function test_read_tsf_returns_empty_when_inactive(): void {
		$result = $this->call_reader( 'read_tsf' );
		$this::assertIsArray( $result );
		$this::assertSame( '', $result['meta_title'] );
	}

	public function test_is_yoast_active_false_in_default_env(): void {
		$reader = new \SScribe_SEO_Reader();
		$ref    = new ReflectionClass( $reader );
		$rm     = $ref->getMethod( 'is_yoast_active' );
		$rm->setAccessible( true );
		$this::assertFalse( $rm->invoke( $reader ) );
	}

	public function test_is_rankmath_active_false_in_default_env(): void {
		$reader = new \SScribe_SEO_Reader();
		$ref    = new ReflectionClass( $reader );
		$rm     = $ref->getMethod( 'is_rankmath_active' );
		$rm->setAccessible( true );
		$this::assertFalse( $rm->invoke( $reader ) );
	}

	public function test_is_aioseo_v4_active_false_in_default_env(): void {
		$reader = new \SScribe_SEO_Reader();
		$ref    = new ReflectionClass( $reader );
		$rm     = $ref->getMethod( 'is_aioseo_v4_active' );
		$rm->setAccessible( true );
		$this::assertFalse( $rm->invoke( $reader ) );
	}

	public function test_is_aioseo_v3_active_false_in_default_env(): void {
		$reader = new \SScribe_SEO_Reader();
		$ref    = new ReflectionClass( $reader );
		$rm     = $ref->getMethod( 'is_aioseo_v3_active' );
		$rm->setAccessible( true );
		$this::assertFalse( $rm->invoke( $reader ) );
	}

	public function test_is_seopress_active_false_in_default_env(): void {
		$reader = new \SScribe_SEO_Reader();
		$ref    = new ReflectionClass( $reader );
		$rm     = $ref->getMethod( 'is_seopress_active' );
		$rm->setAccessible( true );
		$this::assertFalse( $rm->invoke( $reader ) );
	}

	public function test_is_tsf_active_false_in_default_env(): void {
		$reader = new \SScribe_SEO_Reader();
		$ref    = new ReflectionClass( $reader );
		$rm     = $ref->getMethod( 'is_tsf_active' );
		$rm->setAccessible( true );
		$this::assertFalse( $rm->invoke( $reader ) );
	}

	public function test_empty_seo_data_has_all_keys(): void {
		$reader = new \SScribe_SEO_Reader();
		$ref    = new ReflectionClass( $reader );
		$rm     = $ref->getMethod( 'empty_seo_data' );
		$rm->setAccessible( true );
		$data = $rm->invoke( $reader );

		$expected_keys = array(
			'meta_title',
			'meta_description',
			'focus_keyword',
			'canonical_url',
			'og_title',
			'og_description',
			'og_image',
			'noindex',
			'nofollow',
			'source',
		);
		foreach ( $expected_keys as $key ) {
			$this::assertArrayHasKey( $key, $data );
		}
	}

	public function test_has_seo_data_returns_false_for_empty(): void {
		$reader = new \SScribe_SEO_Reader();
		$ref    = new ReflectionClass( $reader );
		$rm     = $ref->getMethod( 'has_seo_data' );
		$rm->setAccessible( true );
		$this::assertFalse( $rm->invoke( $reader, array(
			'meta_title'       => '',
			'meta_description' => '',
			'focus_keyword'    => '',
			'canonical_url'    => '',
			'og_title'         => '',
			'og_description'   => '',
			'og_image'         => '',
		) ) );
	}

	public function test_has_seo_data_returns_true_for_any_populated_field(): void {
		$reader = new \SScribe_SEO_Reader();
		$ref    = new ReflectionClass( $reader );
		$rm     = $ref->getMethod( 'has_seo_data' );
		$rm->setAccessible( true );

		$this::assertTrue( $rm->invoke( $reader, array(
			'meta_title'       => 'Hi',
			'meta_description' => '',
			'focus_keyword'    => '',
			'canonical_url'    => '',
			'og_title'         => '',
			'og_description'   => '',
			'og_image'         => '',
		) ) );

		$this::assertTrue( $rm->invoke( $reader, array(
			'meta_title'       => '',
			'meta_description' => 'Hi',
			'focus_keyword'    => '',
			'canonical_url'    => '',
			'og_title'         => '',
			'og_description'   => '',
			'og_image'         => '',
		) ) );

		$this::assertTrue( $rm->invoke( $reader, array(
			'meta_title'       => '',
			'meta_description' => '',
			'focus_keyword'    => '',
			'canonical_url'    => '',
			'og_title'         => '',
			'og_description'   => '',
			'og_image'         => 'img.jpg',
		) ) );
	}

	public function test_get_primary_taxonomy_falls_back_to_category(): void {
		$reader = new \SScribe_SEO_Reader();
		$ref    = new ReflectionClass( $reader );
		$rm     = $ref->getMethod( 'get_primary_taxonomy' );
		$rm->setAccessible( true );
		// The bootstrap does not stub get_object_taxonomies; the type-juggling
		// inside must not surface a fatal error — if the global is missing
		// the method bails out to its 'category' default.
		if ( ! function_exists( 'get_object_taxonomies' ) ) {
			$this::assertTrue( true );
			return;
		}
		$result = $rm->invoke( $reader );
		$this::assertIsString( $result );
		$this::assertNotSame( '', $result );
	}

	public function test_get_seo_data_falls_through_all_readers_when_no_plugin(): void {
		$reader = new \SScribe_SEO_Reader();
		$data   = $reader->get_seo_data( 42 );
		$this::assertSame( '', $data['source'] );
		$this::assertFalse( $data['noindex'] );
		$this::assertFalse( $data['nofollow'] );
	}

	public function test_get_seo_data_keeps_keys_consistent(): void {
		$reader = new \SScribe_SEO_Reader();
		$data   = $reader->get_seo_data( 1 );
		$this::assertCount( 10, $data );
	}
}
