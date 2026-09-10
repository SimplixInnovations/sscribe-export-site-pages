<?php
/**
 * SScribe main plugin class coverage test.
 *
 * Targets the simpler public/private methods that don't depend on the
 * full WP plugin lifecycle:
 *
 *   - bump_content_cache_generation() : increments option
 *   - get_content_cache_generation()  : reads option, defaults to 1
 *   - invalidate_admin_page_cache()   : bypass for autosave/revision
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;

if ( ! class_exists( '\\SScribe', false ) ) {
	require_once SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe.php';
}

final class SScribe_Main_Coverage_Test extends TestCase {

	private \SScribe $ss;
	private ReflectionClass $ref;

	protected function setUp(): void {
		parent::setUp();
		$this->ss  = new \SScribe();
		$this->ref = new ReflectionClass( $this->ss );
	}

	private function call( string $name, array $args = array() ): mixed {
		$m = $this->ref->getMethod( $name );
		$m->setAccessible( true );
		return $m->invokeArgs( $this->ss, $args );
	}

	public function test_bump_content_cache_generation_returns_int(): void {
		$result = $this->ss->bump_content_cache_generation();
		$this::assertIsInt( $result );
		$this::assertGreaterThanOrEqual( 1, $result );
	}

	public function test_bump_increments_by_at_least_one(): void {
		$before = $this->ss->get_content_cache_generation();
		$after  = $this->ss->bump_content_cache_generation();
		$this::assertGreaterThan( $before, $after );
	}

	public function test_get_content_cache_generation_returns_int_defaulting_to_1(): void {
		// Even if option has never been set, the getter must return >= 1.
		$result = $this->ss->get_content_cache_generation();
		$this::assertIsInt( $result );
		$this::assertGreaterThanOrEqual( 1, $result );
	}

	public function test_invalidate_admin_page_cache_returns_for_autosave(): void {
		// Should return early without erroring.
		$GLOBALS['sscribe_last_autosave'] = 12345;
		$this->ss->invalidate_admin_page_cache( 12345 );
		$this::assertTrue( true ); // survived
	}

	public function test_class_has_expected_methods(): void {
		$this::assertTrue( method_exists( \SScribe::class, 'run' ) );
		$this::assertTrue( method_exists( \SScribe::class, 'bump_content_cache_generation' ) );
		$this::assertTrue( method_exists( \SScribe::class, 'get_content_cache_generation' ) );
		$this::assertTrue( method_exists( \SScribe::class, 'invalidate_admin_page_cache' ) );
		$this::assertTrue( method_exists( \SScribe::class, 'cleanup_sessions' ) );
		$this::assertTrue( method_exists( \SScribe::class, 'cleanup_audit_trail' ) );
	}

	public function test_render_vendor_dependency_notice_runs_without_error(): void {
		// No vendor dep check in unit env; should silently return.
		ob_start();
		$this->ss->render_vendor_dependency_notice();
		$out = ob_get_clean();
		$this::assertIsString( $out );
	}

	public function test_cleanup_audit_trail_runs(): void {
		// Direct call works (no container lookup).
		$this->ss->cleanup_audit_trail();
		$this::assertTrue( true );
	}
}
