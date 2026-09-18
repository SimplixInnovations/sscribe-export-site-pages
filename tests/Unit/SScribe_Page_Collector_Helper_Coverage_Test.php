<?php
/**
 * SScribe Page Collector helper coverage test.
 *
 * Targets the simpler public helpers of SScribe_Page_Collector that
 * don't depend on WP_Query / WP_Post existence:
 *
 *   - is_wpml_active()
 *   - get_selectable_post_types()
 *   - resolve_post_type_for_query()  (with and without explicit allow-list)
 *   - get_valid_post_statuses()
 *   - get_content_cache_generation()
 *   - clear_page_caches()
 *   - get_permalink_cached()
 *   - get_title_cached()
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;

if ( ! class_exists( '\\SScribe_Page_Collector', false ) ) {
	require_once SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-page-collector.php';
}

final class SScribe_Page_Collector_Helper_Coverage_Test extends TestCase {

	private \SScribe_Page_Collector $pc;
	private ReflectionClass $ref;

	protected function setUp(): void {
		parent::setUp();
		$this->pc  = new \SScribe_Page_Collector();
		$this->ref = new ReflectionClass( $this->pc );
	}

	private function call( string $name, array $args = array() ): mixed {
		$m = $this->ref->getMethod( $name );
		return $m->invokeArgs( $this->pc, $args );
	}

	public function test_is_wpml_active_default_false(): void {
		$this::assertIsBool( $this->pc->is_wpml_active() );
		$this::assertFalse( $this->pc->is_wpml_active() );
	}

	public function test_get_selectable_post_types_returns_array(): void {
		$result = $this->pc->get_selectable_post_types();
		$this::assertIsArray( $result );
		// Must never include 'attachment'.
		$this::assertNotContains( 'attachment', $result );
	}

	public function test_get_selectable_post_types_passes_through_filter(): void {
		$cb = static function ( array $types ): array {
			return array_merge( $types, array( 'custom_type' ) );
		};
		add_filter( 'sscribe_allowed_post_types', $cb );
		try {
			$result = $this->pc->get_selectable_post_types();
			$this::assertContains( 'custom_type', $result );
		} finally {
			remove_filter( 'sscribe_allowed_post_types', $cb );
		}
	}

	public function test_resolve_post_type_any_returns_allow_list(): void {
		$result = $this->pc->resolve_post_type_for_query( 'any', array( 'page', 'post' ) );
		$this::assertIsArray( $result );
		$this::assertSame( array( 'page', 'post' ), $result );
	}

	public function test_resolve_post_type_returns_first_when_unknown(): void {
		$result = $this->pc->resolve_post_type_for_query( 'unknown', array( 'page', 'post' ) );
		$this::assertSame( 'page', $result );
	}

	public function test_resolve_post_type_returns_input_when_known(): void {
		$result = $this->pc->resolve_post_type_for_query( 'post', array( 'page', 'post' ) );
		$this::assertSame( 'post', $result );
	}

	public function test_resolve_post_type_falls_back_when_empty_allow_list(): void {
		$result = $this->pc->resolve_post_type_for_query( 'unknown', array() );
		$this::assertSame( 'page', $result );
	}

	public function test_get_valid_post_statuses_returns_array(): void {
		$result = $this->pc->get_valid_post_statuses();
		$this::assertIsArray( $result );
		$this::assertArrayHasKey( 'publish', $result );
		$this::assertArrayHasKey( 'draft', $result );
		$this::assertArrayHasKey( 'private', $result );
		$this::assertArrayHasKey( 'future', $result );
		$this::assertArrayHasKey( 'pending', $result );
	}

	public function test_clear_page_caches_runs(): void {
		$this->pc->clear_page_caches();
		$this::assertTrue( true );
	}

	public function test_get_content_cache_generation_returns_int(): void {
		$result = $this->pc->get_content_cache_generation();
		$this::assertIsInt( $result );
		$this::assertGreaterThanOrEqual( 1, $result );
	}

	public function test_cache_add_helper_appends(): void {
		$cache = array();
		$this->call( 'cache_add', array( &$cache, 'key1', 'val1' ) );
		$this::assertSame( array( 'key1' => 'val1' ), $cache );
	}

	public function test_get_permalink_cached_returns_string(): void {
		$result = $this->call( 'get_permalink_cached', array( 0 ) );
		$this::assertIsString( $result );
	}

	public function test_get_title_cached_returns_string(): void {
		$result = $this->call( 'get_title_cached', array( 0 ) );
		$this::assertIsString( $result );
	}

	public function test_class_has_expected_public_methods(): void {
		$this::assertTrue( method_exists( \SScribe_Page_Collector::class, 'is_wpml_active' ) );
		$this::assertTrue( method_exists( \SScribe_Page_Collector::class, 'get_selectable_post_types' ) );
		$this::assertTrue( method_exists( \SScribe_Page_Collector::class, 'resolve_post_type_for_query' ) );
		$this::assertTrue( method_exists( \SScribe_Page_Collector::class, 'get_valid_post_statuses' ) );
		$this::assertTrue( method_exists( \SScribe_Page_Collector::class, 'clear_page_caches' ) );
		$this::assertTrue( method_exists( \SScribe_Page_Collector::class, 'get_content_cache_generation' ) );
	}
}
