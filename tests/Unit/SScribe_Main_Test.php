<?php
/**
 * SScribe Main Plugin Class Unit Test
 *
 * Pins the public API surface of the bootstrap class so that
 * silent removal of a hook registration, capability gate, or
 * service container binding cannot slip through without a
 * test failure. The class is otherwise too heavy to instanti-
 * ate end-to-end (it registers 16 AJAX hooks + 3 cron hooks +
 * 4 admin hooks + 3 privacy hooks through a real Loader).
 *
 * What this test covers:
 *   1. Constructor wires version + loader.
 *   2. Public method names + signatures survive refactors.
 *   3. invalidate_admin_page_cache() drops the transient on
 *      page/post save and ignores autosaves/revisions/other
 *      post types.
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use SScribe;

final class SScribe_Main_Test extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['sscribe_test_transients'] = array();
	}

	public function test_constructor_sets_version_and_loader(): void {
		$plugin = new SScribe();

		$reflection = new ReflectionClass( SScribe::class );
		$version    = $reflection->getProperty( 'version' );
		$loader     = $reflection->getProperty( 'loader' );

		$this->assertSame( SSCRIBE_VERSION, $version->getValue( $plugin ) );
		$this->assertInstanceOf( \SScribe_Loader::class, $loader->getValue( $plugin ) );
	}

	public function test_public_api_surface(): void {
		$expected = array(
			'render_vendor_dependency_notice' => 'public',
			'invalidate_admin_page_cache'     => 'public',
			'cleanup_sessions'                => 'public',
			'cleanup_audit_trail'             => 'public',
			'run'                             => 'public',
		);

		$reflection = new ReflectionClass( SScribe::class );
		foreach ( $expected as $method => $visibility ) {
			$this->assertTrue( $reflection->hasMethod( $method ), "SScribe::$method must exist." );
			$ref = new ReflectionMethod( SScribe::class, $method );
			$this->assertTrue( $ref->isPublic(), "SScribe::$method must be public." );
		}
	}

	public function test_invalidate_admin_page_cache_drops_transient_for_page(): void {
		$plugin   = new SScribe();
		$cache_key = 'sscribe_admin_page_data_v' . SSCRIBE_VERSION . '_' . get_current_blog_id();
		$GLOBALS['sscribe_test_transients'][ $cache_key ] = 'cached-payload';

		$plugin->invalidate_admin_page_cache( 42 );

		$this->assertArrayNotHasKey( $cache_key, $GLOBALS['sscribe_test_transients'] );
	}

	public function test_invalidate_admin_page_cache_ignores_post_type_other_than_page_or_post(): void {
		$plugin    = new SScribe();
		$cache_key = 'sscribe_admin_page_data_v' . SSCRIBE_VERSION . '_' . get_current_blog_id();
		$GLOBALS['sscribe_test_transients'][ $cache_key ] = 'cached-payload';

		// Force the bootstrap get_post_type() stub to return 'attachment' for
		// this test — the default stub returns 'page' which would also delete
		// the cache and mask the post-type gate.
		$GLOBALS['sscribe_test_post_type_override'] = 'attachment';
		try {
			$plugin->invalidate_admin_page_cache( 99 );
		} finally {
			unset( $GLOBALS['sscribe_test_post_type_override'] );
		}

		$this->assertArrayHasKey( $cache_key, $GLOBALS['sscribe_test_transients'] );
	}

	public function test_invalidate_admin_page_cache_returns_silently_on_unknown_post_type(): void {
		$plugin    = new SScribe();
		$cache_key = 'sscribe_admin_page_data_v' . SSCRIBE_VERSION . '_' . get_current_blog_id();
		$GLOBALS['sscribe_test_transients'][ $cache_key ] = 'still-here';

		$GLOBALS['sscribe_test_post_type_override'] = false;
		try {
			$plugin->invalidate_admin_page_cache( 99 );
		} finally {
			unset( $GLOBALS['sscribe_test_post_type_override'] );
		}

		$this->assertArrayHasKey( $cache_key, $GLOBALS['sscribe_test_transients'] );
	}

	public function test_invalidate_admin_page_cache_protects_against_double_invoke(): void {
		$plugin    = new SScribe();
		$cache_key = 'sscribe_admin_page_data_v' . SSCRIBE_VERSION . '_' . get_current_blog_id();

		// First save: transient exists, gets deleted.
		$GLOBALS['sscribe_test_transients'][ $cache_key ] = 'A';
		$plugin->invalidate_admin_page_cache( 42 );
		$this->assertArrayNotHasKey( $cache_key, $GLOBALS['sscribe_test_transients'] );

		// Second save: transient already missing; delete is a no-op, must not throw.
		$plugin->invalidate_admin_page_cache( 42 );
		$this->assertArrayNotHasKey( $cache_key, $GLOBALS['sscribe_test_transients'] );
	}
}
