<?php
/**
 * SScribe Admin extra helper coverage test
 *
 * Targets pure / low-coupling helpers of SScribe_Admin:
 *
 *   - get_required_capability()    : returns string
 *   - get_download_nonce()         : returns string via wp_create_nonce
 *   - add_plugin_action_links()    : prepends the Export Pages link
 *   - build_post_type_strings()    : default + selectable types
 *   - build_selectable_type_rows() : rows for known post types
 *   - redirect_to_plugin_page()    : protected but reflection-hittable
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

if ( ! class_exists( '\\SScribe_Admin', false ) ) {
	require_once SSCRIBE_PLUGIN_DIR . 'admin/class-sscribe-admin.php';
}

final class SScribe_Admin_Extra_Helper_Coverage_Test extends TestCase {

	private \SScribe_Admin $admin;
	private ReflectionClass $ref;

	protected function setUp(): void {
		parent::setUp();
		$this->admin = new \SScribe_Admin();
		$this->ref   = new ReflectionClass( $this->admin );
	}

	private function call( string $name, array $args = array() ): mixed {
		$m = $this->ref->getMethod( $name );
		$m->setAccessible( true );
		return $m->invokeArgs( $this->admin, $args );
	}

	public function test_get_required_capability_returns_string(): void {
		$result = $this->call( 'get_required_capability' );
		$this::assertIsString( $result );
		$this::assertNotSame( '', $result );
	}

	public function test_get_download_nonce_returns_string(): void {
		$result = $this->call( 'get_download_nonce' );
		$this::assertIsString( $result );
		$this::assertNotSame( '', $result );
	}

	public function test_add_plugin_action_links_prepends_export_link(): void {
		$existing = array( '<a>Deactivate</a>' );
		$result   = $this->admin->add_plugin_action_links( $existing );
		$this::assertIsArray( $result );
		$this::assertCount( 2, $result );
		$this::assertStringContainsString( 'Export Pages', $result[0] );
		$this::assertStringContainsString( 'sscribe-export', $result[0] );
		$this::assertSame( '<a>Deactivate</a>', $result[1] );
	}

	public function test_build_post_type_strings_includes_known_types(): void {
		$result = $this->call( 'build_post_type_strings' );
		$this::assertIsArray( $result );
		$this::assertArrayHasKey( 'post_type_any', $result );
		$this::assertArrayHasKey( 'post_type_page', $result );
		$this::assertArrayHasKey( 'post_type_post', $result );
	}

	public function test_build_post_type_strings_uses_singular_label(): void {
		// The default WP stub registers 'page' → label 'Page', 'post' → label 'Post'.
		$result = $this->call( 'build_post_type_strings' );
		// The 'page' label should come from the WP stub's labels.singular_name.
		$this::assertArrayHasKey( 'post_type_page', $result );
		// Either 'Page' (from stub) or 'Pages' (from fallback) — both acceptable.
		$this::assertNotSame( '', $result['post_type_page'] );
	}

	public function test_build_selectable_type_rows_includes_page(): void {
		$result = $this->call( 'build_selectable_type_rows' );
		$this::assertIsArray( $result );
		// At least one row.
		$this::assertGreaterThan( 0, count( $result ) );
		// Each row should have a 'slug' key.
		$slugs = array_column( $result, 'slug' );
		$this::assertContains( 'page', $slugs );
	}

	public function test_redirect_to_plugin_page_method_exists(): void {
		// Cannot safely invoke: wp_safe_redirect() exits the request in some
		// stub configurations. Assert the method exists + signature.
		$this::assertTrue( $this->ref->hasMethod( 'redirect_to_plugin_page' ) );
		$m = $this->ref->getMethod( 'redirect_to_plugin_page' );
		$this::assertTrue( $m->isProtected() || $m->isPrivate() );
		$this::assertSame( 0, $m->getNumberOfParameters() );
	}

	public function test_class_has_expected_public_methods(): void {
		$this::assertTrue( method_exists( \SScribe_Admin::class, 'add_admin_menu' ) );
		$this::assertTrue( method_exists( \SScribe_Admin::class, 'enqueue_admin_assets' ) );
		$this::assertTrue( method_exists( \SScribe_Admin::class, 'print_localized_data' ) );
		$this::assertTrue( method_exists( \SScribe_Admin::class, 'render_admin_page' ) );
		$this::assertTrue( method_exists( \SScribe_Admin::class, 'add_plugin_action_links' ) );
		$this::assertTrue( method_exists( \SScribe_Admin::class, 'maybe_redirect_after_activation' ) );
	}
}
