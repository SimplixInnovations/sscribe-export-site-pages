<?php
/**
 * SScribe class unit test — cache-generation and admin-notice branches.
 *
 * Locks in the public-facing behaviors of SScribe::bump_content_cache_generation,
 * SScribe::get_content_cache_generation, and
 * SScribe::invalidate_admin_page_cache. These are simple options-table
 * collaborators but they are the global invalidation bus for every
 * content-derived cache key in the plugin; a regression here silently
 * breaks freshness for every admin page.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

if ( ! class_exists( '\\SScribe' ) ) {
	require_once SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe.php';
}

final class SScribe_Branches_Test extends TestCase {

	private \SScribe $instance;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['sscribe_test_blog_id']  = 1;
		$GLOBALS['sscribe_test_options']  = array();
		$GLOBALS['sscribe_test_transients'] = array();
		$GLOBALS['sscribe_test_post_type_override'] = 'page';
		// Construct without running the constructor body to avoid
		// registering WP hooks in a unit-test context.
		$ref     = new \ReflectionClass( \SScribe::class );
		$instance = $ref->newInstanceWithoutConstructor();
		$this->instance = $instance;
	}

	protected function tearDown(): void {
		unset( $GLOBALS['sscribe_test_blog_id'], $GLOBALS['sscribe_test_options'], $GLOBALS['sscribe_test_transients'], $GLOBALS['sscribe_test_post_type_override'] );
		parent::tearDown();
	}

	// ==================================================================
	// bump_content_cache_generation() / get_content_cache_generation()
	// ==================================================================

	public function test_get_content_cache_generation_defaults_to_one_when_unset(): void {
		$this::assertSame( 1, $this->instance->get_content_cache_generation() );
	}

	public function test_bump_content_cache_generation_returns_next_value(): void {
		$next = $this->instance->bump_content_cache_generation();
		$this::assertSame( 2, $next );
		$this::assertSame( 2, $this->instance->get_content_cache_generation() );

		$next = $this->instance->bump_content_cache_generation();
		$this::assertSame( 3, $next );
		$this::assertSame( 3, $this->instance->get_content_cache_generation() );
	}

	public function test_bump_content_cache_generation_clamps_to_one_when_zero(): void {
		$GLOBALS['sscribe_test_options']['sscribe_content_cache_generation'] = 0;
		$this::assertSame( 1, $this->instance->bump_content_cache_generation() );
	}

	public function test_bump_content_cache_generation_clamps_negative_to_one(): void {
		$GLOBALS['sscribe_test_options']['sscribe_content_cache_generation'] = -5;
		$this::assertSame( 1, $this->instance->bump_content_cache_generation() );
	}

	// ==================================================================
	// invalidate_admin_page_cache()
	// ==================================================================

	public function test_invalidate_admin_page_cache_deletes_transient_and_bumps_generation(): void {
		$cache_key = 'sscribe_admin_page_data_v2_' . SSCRIBE_VERSION . '_' . get_current_blog_id();
		$GLOBALS['sscribe_test_transients'][ $cache_key ] = 'stale-payload';
		$GLOBALS['sscribe_test_options']['sscribe_content_cache_generation'] = 7;

		$this->instance->invalidate_admin_page_cache( 42 );

		$this::assertArrayNotHasKey( $cache_key, $GLOBALS['sscribe_test_transients'] );
		$this::assertSame( 8, $this->instance->get_content_cache_generation() );
	}

	public function test_invalidate_admin_page_cache_skips_non_post_types(): void {
		$GLOBALS['sscribe_test_post_type_override'] = 'attachment';
		$GLOBALS['sscribe_test_options']['sscribe_content_cache_generation'] = 4;

		$this->instance->invalidate_admin_page_cache( 99 );

		// Generation must NOT have been bumped — non-post types skip the
		// invalidation branch on line 209.
		$this::assertSame( 4, $this->instance->get_content_cache_generation() );
	}

	public function test_invalidate_admin_page_cache_skips_unknown_post_types(): void {
		$GLOBALS['sscribe_test_post_type_override'] = '';
		$GLOBALS['sscribe_test_options']['sscribe_content_cache_generation'] = 4;

		$this->instance->invalidate_admin_page_cache( 99 );

		$this::assertSame( 4, $this->instance->get_content_cache_generation() );
	}
}
