<?php
/**
 * SScribe SEO Reader coverage test
 *
 * Exercises the public surface of SScribe_SEO_Reader when no SEO plugin is
 * installed (the default test environment):
 *
 *   - get_seo_data() short-circuits when has_seo_plugin() is false, returning
 *     a default zeroed array
 *   - has_seo_plugin() returns false in the no-plugin test env
 *   - get_active_seo_plugins() returns an empty array in the no-plugin env
 *   - prime_meta_cache() is a no-op when no plugin is active and the input
 *     array is empty
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

if ( ! class_exists( '\\SScribe_SEO_Reader', false ) ) {
	require_once SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-seo-reader.php';
}

final class SScribe_SEO_Reader_Coverage_Test extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['sscribe_test_options']   = array();
		$GLOBALS['sscribe_test_db_tables'] = array();
		$GLOBALS['sscribe_test_post_meta'] = array();
	}

	protected function tearDown(): void {
		$GLOBALS['sscribe_test_options']   = array();
		$GLOBALS['sscribe_test_db_tables'] = array();
		$GLOBALS['sscribe_test_post_meta'] = array();
		parent::tearDown();
	}

	public function test_has_seo_plugin_returns_false_when_no_plugin_active(): void {
		$reader = new \SScribe_SEO_Reader();
		$this::assertFalse( $reader->has_seo_plugin() );
	}

	public function test_get_active_seo_plugins_returns_empty_array_when_no_plugin(): void {
		$reader = new \SScribe_SEO_Reader();
		$this::assertSame( array(), $reader->get_active_seo_plugins() );
	}

	public function test_get_seo_data_returns_zeroed_array_when_no_plugin(): void {
		$reader = new \SScribe_SEO_Reader();
		$data   = $reader->get_seo_data( 42 );

		$this::assertSame( '', $data['meta_title'] ?? 'unset' );
		$this::assertSame( '', $data['meta_description'] ?? 'unset' );
		$this::assertSame( '', $data['focus_keyword'] ?? 'unset' );
		$this::assertSame( '', $data['canonical_url'] ?? 'unset' );
		$this::assertSame( '', $data['og_title'] ?? 'unset' );
		$this::assertSame( '', $data['og_description'] ?? 'unset' );
		$this::assertSame( '', $data['og_image'] ?? 'unset' );
		$this::assertFalse( $data['noindex'] ?? true );
		$this::assertFalse( $data['nofollow'] ?? true );
		$this::assertSame( '', $data['source'] ?? 'unset' );
	}

	public function test_get_seo_data_with_zero_page_id(): void {
		$reader = new \SScribe_SEO_Reader();
		$data   = $reader->get_seo_data( 0 );
		$this::assertIsArray( $data );
		$this::assertArrayHasKey( 'meta_title', $data );
	}

	public function test_prime_meta_cache_with_empty_array_is_noop(): void {
		$reader = new \SScribe_SEO_Reader();
		$reader->prime_meta_cache( array() );
		$this::assertTrue( true );
	}

	public function test_prime_meta_cache_with_array_of_ids_is_noop_when_no_plugin(): void {
		$reader = new \SScribe_SEO_Reader();
		$reader->prime_meta_cache( array( 1, 2, 3 ) );
		$this::assertTrue( true );
	}

	public function test_prime_meta_cache_filters_invalid_entries(): void {
		$reader = new \SScribe_SEO_Reader();
		$reader->prime_meta_cache( array( 1, 'two', null, false, 0, -3, 4 ) );
		$this::assertTrue( true );
	}

	/**
	 * WordPress core names this function update_postmeta_cache(). An earlier
	 * call to the non-existent update_post_meta_cache() was a fatal error on
	 * every export from a site with an SEO plugin active.
	 */
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	#[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
	public function test_prime_meta_cache_calls_core_update_postmeta_cache_when_seo_plugin_active(): void {
		if ( ! function_exists( 'update_postmeta_cache' ) ) {
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- Defines the core function in this isolated process only.
			eval( 'function update_postmeta_cache( $ids ) { $GLOBALS["sscribe_primed_ids"] = $ids; return array(); }' );
		}
		define( 'WPSEO_VERSION', '99.0' );

		$reader = new \SScribe_SEO_Reader();
		$reader->prime_meta_cache( array( 3, 3, 7 ) );

		$this::assertSame( array( 3, 7 ), array_values( $GLOBALS['sscribe_primed_ids'] ?? array() ) );
	}

	public function test_seo_reader_never_calls_non_existent_update_post_meta_cache(): void {
		$source = (string) file_get_contents( SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-seo-reader.php' );
		$this::assertDoesNotMatchRegularExpression( '/\bupdate_post_meta_cache\s*\(/', $source );
	}
}
