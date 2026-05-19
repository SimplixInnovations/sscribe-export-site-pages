<?php

declare(strict_types=1);

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SScribe_Admin;

final class SScribe_Admin_Test_Double extends SScribe_Admin {
	public bool $redirect_called = false;
	public string $redirect_target = '';

	protected function redirect_to_plugin_page(): void {
		$this->redirect_called = true;
		$this->redirect_target = admin_url( 'admin.php?page=sscribe-export' );
	}
}

class SScribe_Admin_Test extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		global $sscribe_test_filters, $sscribe_test_menu_pages, $sscribe_test_styles, $sscribe_test_scripts, $sscribe_test_localized;
		global $sscribe_test_transients, $sscribe_test_current_user_can, $sscribe_test_is_admin, $sscribe_test_doing_ajax;

		$sscribe_test_filters          = array();
		$sscribe_test_menu_pages       = array();
		$sscribe_test_styles           = array();
		$sscribe_test_scripts          = array();
		$sscribe_test_localized        = array();
		$sscribe_test_transients       = array();
		$sscribe_test_current_user_can = true;
		$sscribe_test_is_admin         = true;
		$sscribe_test_doing_ajax       = false;
		$_GET                          = array();
	}

	public function test_add_admin_menu_registers_top_level_page(): void {
		global $sscribe_test_menu_pages;

		$admin = new SScribe_Admin();
		$admin->add_admin_menu();

		$this->assertCount( 1, $sscribe_test_menu_pages );
		$this->assertSame( 'sscribe-export', $sscribe_test_menu_pages[0]['menu_slug'] );
		$this->assertSame( 'manage_options', $sscribe_test_menu_pages[0]['capability'] );
		$this->assertSame( 'dashicons-media-document', $sscribe_test_menu_pages[0]['icon_url'] );
	}

	public function test_add_admin_menu_uses_filtered_capability(): void {
		global $sscribe_test_menu_pages;

		add_filter(
			'sscribe_export_capability',
			static function (): string {
				return 'edit_pages';
			}
		);

		$admin = new SScribe_Admin();
		$admin->add_admin_menu();

		$this->assertSame( 'edit_pages', $sscribe_test_menu_pages[0]['capability'] );
	}

	public function test_enqueue_admin_assets_only_runs_for_plugin_pages(): void {
		global $sscribe_test_styles, $sscribe_test_scripts, $sscribe_test_localized;

		$admin = new SScribe_Admin();
		$admin->enqueue_admin_assets( 'dashboard_page_unrelated' );

		$this->assertCount( 0, $sscribe_test_styles );
		$this->assertCount( 0, $sscribe_test_scripts );
		$this->assertCount( 0, $sscribe_test_localized );

		$admin->enqueue_admin_assets( 'toplevel_page_sscribe-export' );

		$this->assertCount( 2, $sscribe_test_styles );
		$this->assertCount( 1, $sscribe_test_scripts );
		$this->assertCount( 1, $sscribe_test_localized );
	}

	public function test_plugin_action_link_points_to_top_level_admin_page(): void {
		$admin = new SScribe_Admin();
		$links = $admin->add_plugin_action_links( array( 'existing-link' ) );

		$this->assertStringContainsString( 'admin.php?page=sscribe-export', $links[0] );
		$this->assertSame( 'existing-link', $links[1] );
	}

	public function test_activation_redirect_transient_is_consumed_without_capability(): void {
		global $sscribe_test_transients, $sscribe_test_current_user_can;

		$sscribe_test_transients['sscribe_activation_redirect'] = '1';
		$sscribe_test_current_user_can                          = false;

		$admin = new SScribe_Admin();
		$admin->maybe_redirect_after_activation();

		$this->assertArrayNotHasKey( 'sscribe_activation_redirect', $sscribe_test_transients );
	}

	public function test_activation_redirect_sends_authorized_admins_to_plugin_page(): void {
		global $sscribe_test_transients;

		$sscribe_test_transients['sscribe_activation_redirect'] = '1';

		$admin = new SScribe_Admin_Test_Double();
		$admin->maybe_redirect_after_activation();

		$this->assertTrue( $admin->redirect_called );
		$this->assertSame( 'http://example.org/wp-admin/admin.php?page=sscribe-export', $admin->redirect_target );
		$this->assertArrayNotHasKey( 'sscribe_activation_redirect', $sscribe_test_transients );
	}
}
