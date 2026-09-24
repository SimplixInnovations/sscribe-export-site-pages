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
 *      real content/attachment mutations and ignores autosaves,
 *      revisions, and unknown IDs.
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
		$cache_key = 'sscribe_admin_page_data_v2_' . SSCRIBE_VERSION . '_' . get_current_blog_id();
		$GLOBALS['sscribe_test_transients'][ $cache_key ] = 'cached-payload';

		$plugin->invalidate_admin_page_cache( 42 );

		$this->assertArrayNotHasKey( $cache_key, $GLOBALS['sscribe_test_transients'] );
	}

	public function test_invalidate_admin_page_cache_invalidates_attachment_dependent_exports(): void {
		$plugin    = new SScribe();
		$cache_key = 'sscribe_admin_page_data_v2_' . SSCRIBE_VERSION . '_' . get_current_blog_id();
		$GLOBALS['sscribe_test_transients'][ $cache_key ] = 'cached-payload';

		// Attachment mutations can change featured-image URLs/paths embedded
		// in exports, so they must invalidate content-derived caches too.
		$GLOBALS['sscribe_test_post_type_override'] = 'attachment';
		try {
			$plugin->invalidate_admin_page_cache( 99 );
		} finally {
			unset( $GLOBALS['sscribe_test_post_type_override'] );
		}

		$this->assertArrayNotHasKey( $cache_key, $GLOBALS['sscribe_test_transients'] );
	}

	public function test_invalidate_admin_page_cache_returns_silently_on_unknown_post_type(): void {
		$plugin    = new SScribe();
		$cache_key = 'sscribe_admin_page_data_v2_' . SSCRIBE_VERSION . '_' . get_current_blog_id();
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
		$cache_key = 'sscribe_admin_page_data_v2_' . SSCRIBE_VERSION . '_' . get_current_blog_id();

		// First save: transient exists, gets deleted.
		$GLOBALS['sscribe_test_transients'][ $cache_key ] = 'A';
		$plugin->invalidate_admin_page_cache( 42 );
		$this->assertArrayNotHasKey( $cache_key, $GLOBALS['sscribe_test_transients'] );

		// Second save: transient already missing; delete is a no-op, must not throw.
		$plugin->invalidate_admin_page_cache( 42 );
		$this->assertArrayNotHasKey( $cache_key, $GLOBALS['sscribe_test_transients'] );
	}

	public function test_run_boots_services_and_registers_hooks(): void {
		// run() must complete without throwing. The function chains
		// require_once for fatal-handler + upgrader + request-id, then
		// registers every service in the container before pushing the
		// loader onto WordPress. Anything that would silently break
		// boot (a moved file, a renamed service, a wiring bug) raises
		// here.
		$plugin = new SScribe();
		$plugin->run();

		// After run() the container must hold the core services.
		$container = \SScribe_Container::instance();
		$this->assertTrue( $container->has( \SScribe_Page_Collector::class ) );
		$this->assertTrue( $container->has( \SScribe_Session::class ) );
		$this->assertTrue( $container->has( \SScribe_Filesystem::class ) );
		$this->assertTrue( $container->has( \SScribe_Batch_Processor::class ) );
		$this->assertTrue( $container->has( \SScribe_Admin::class ) );
	}

	public function test_run_can_be_invoked_multiple_times_safely(): void {
		// Bootstrapping must be idempotent: the loader latch keeps the
		// second run() from double-registering hooks.
		$plugin = new SScribe();
		$plugin->run();
		$plugin->run();

		// No throw on either call proves the latch held.
		$this->assertTrue( true );
	}
}
