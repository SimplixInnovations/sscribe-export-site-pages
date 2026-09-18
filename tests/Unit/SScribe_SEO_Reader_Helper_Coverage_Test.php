<?php
/**
 * SScribe SEO Reader helper coverage test
 *
 * Targets the pure private helpers of SScribe_SEO_Reader:
 *
 *   - empty_seo_data()    : returns 10-key structure
 *   - has_seo_data()      : true when ANY seo field is non-empty
 *   - get_active_seo_plugins() : returns array (all inactive in unit env)
 *   - has_seo_plugin()    : returns bool
 *   - get_seo_data()      : returns array with expected keys for unknown ID
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;

if ( ! class_exists( '\\SScribe_SEO_Reader', false ) ) {
	require_once SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-seo-reader.php';
}

final class SScribe_SEO_Reader_Helper_Coverage_Test extends TestCase {

	private \SScribe_SEO_Reader $seo;
	private ReflectionClass $ref;

	protected function setUp(): void {
		parent::setUp();
		$this->seo = new \SScribe_SEO_Reader();
		$this->ref = new ReflectionClass( $this->seo );
	}

	private function call( string $name, array $args = array() ): mixed {
		$m = $this->ref->getMethod( $name );
		return $m->invokeArgs( $this->seo, $args );
	}

	public function test_empty_seo_data_has_10_keys(): void {
		$result = $this->call( 'empty_seo_data' );
		$this::assertIsArray( $result );
		$this::assertArrayHasKey( 'meta_title', $result );
		$this::assertArrayHasKey( 'meta_description', $result );
		$this::assertArrayHasKey( 'focus_keyword', $result );
		$this::assertArrayHasKey( 'canonical_url', $result );
		$this::assertArrayHasKey( 'og_title', $result );
		$this::assertArrayHasKey( 'og_description', $result );
		$this::assertArrayHasKey( 'og_image', $result );
		$this::assertArrayHasKey( 'noindex', $result );
		$this::assertArrayHasKey( 'nofollow', $result );
		$this::assertArrayHasKey( 'source', $result );
	}

	public function test_empty_seo_data_defaults_are_blank(): void {
		$result = $this->call( 'empty_seo_data' );
		$this::assertSame( '', $result['meta_title'] );
		$this::assertSame( '', $result['meta_description'] );
		$this::assertFalse( $result['noindex'] );
		$this::assertFalse( $result['nofollow'] );
	}

	public function test_has_seo_data_returns_false_for_empty(): void {
		$empty = $this->call( 'empty_seo_data' );
		$this::assertFalse( $this->call( 'has_seo_data', array( $empty ) ) );
	}

	public function test_has_seo_data_returns_true_when_meta_title_set(): void {
		$data  = $this->call( 'empty_seo_data' );
		$data['meta_title'] = 'Hello';
		$this::assertTrue( $this->call( 'has_seo_data', array( $data ) ) );
	}

	public function test_has_seo_data_returns_true_when_canonical_set(): void {
		$data  = $this->call( 'empty_seo_data' );
		$data['canonical_url'] = 'https://example.com';
		$this::assertTrue( $this->call( 'has_seo_data', array( $data ) ) );
	}

	public function test_has_seo_data_returns_true_when_og_image_set(): void {
		$data  = $this->call( 'empty_seo_data' );
		$data['og_image'] = '/img.png';
		$this::assertTrue( $this->call( 'has_seo_data', array( $data ) ) );
	}

	public function test_get_active_seo_plugins_returns_array(): void {
		$result = $this->seo->get_active_seo_plugins();
		$this::assertIsArray( $result );
		// In unit env, none should be active.
		$this::assertEmpty( $result );
	}

	public function test_has_seo_plugin_returns_bool(): void {
		$result = $this->seo->has_seo_plugin();
		$this::assertIsBool( $result );
		$this::assertFalse( $result );
	}

	public function test_get_seo_data_returns_array_with_known_keys(): void {
		// For an unknown page ID, should still return the empty seo structure.
		$result = $this->seo->get_seo_data( 0 );
		$this::assertIsArray( $result );
		$this::assertArrayHasKey( 'meta_title', $result );
		$this::assertArrayHasKey( 'canonical_url', $result );
	}

	public function test_prime_meta_cache_runs_without_error(): void {
		// Empty array — should short-circuit.
		$this->seo->prime_meta_cache( array() );
		$this::assertTrue( true ); // survived
	}

	public function test_class_has_expected_public_methods(): void {
		$this::assertTrue( method_exists( \SScribe_SEO_Reader::class, 'get_seo_data' ) );
		$this::assertTrue( method_exists( \SScribe_SEO_Reader::class, 'has_seo_plugin' ) );
		$this::assertTrue( method_exists( \SScribe_SEO_Reader::class, 'prime_meta_cache' ) );
	}
}
