<?php
/**
 * SScribe Admin Unit Test
 *
 * @package SScribe_Export_Site_Pages
 */

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
		global $sscribe_test_transients, $sscribe_test_options, $sscribe_test_current_user_can, $sscribe_test_is_admin, $sscribe_test_doing_ajax;

		$sscribe_test_filters          = array();
		$sscribe_test_menu_pages       = array();
		$sscribe_test_styles           = array();
		$sscribe_test_scripts          = array();
		$sscribe_test_localized        = array();
		$sscribe_test_transients       = array();
		$sscribe_test_options          = array();
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
		$this->assertSame( 'sscribe_export', $sscribe_test_menu_pages[0]['capability'] );
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
		global $sscribe_test_styles, $sscribe_test_scripts, $sscribe_test_localized, $sscribe_test_options;

		$admin = new SScribe_Admin();
		$admin->enqueue_admin_assets( 'dashboard_page_unrelated' );

		$this->assertCount( 0, $sscribe_test_styles );
		$this->assertCount( 0, $sscribe_test_scripts );

		// Debug console assets are always enqueued because the Debug tab is
		// always rendered — without the JS, the user has no way to flip
		// the sscribe_debug_enabled toggle on a fresh install. The flag
		// controls *logging*, not whether the console UI is available.
		// Three styles load: sscribe-tokens (the design-token layer, cached
		// independently) plus sscribe-admin and sscribe-debug-console.
		$sscribe_test_options['sscribe_debug_enabled'] = false;
		$admin->enqueue_admin_assets( 'toplevel_page_sscribe-export' );

		$this->assertCount( 3, $sscribe_test_styles );
		$this->assertCount( 2, $sscribe_test_scripts );

		// The same three styles and two scripts are enqueued when debug is
		// enabled — the flag only changes which logs are captured, not the
		// UI surface.
		$sscribe_test_styles  = array();
		$sscribe_test_scripts = array();
		$sscribe_test_options['sscribe_debug_enabled'] = true;
		$admin->enqueue_admin_assets( 'toplevel_page_sscribe-export' );

		$this->assertCount( 3, $sscribe_test_styles );
		$this->assertCount( 2, $sscribe_test_scripts );
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

	public function test_build_recent_exports_sanitizes_filters_and_maps_language_data(): void {
		$upload_dir = wp_upload_dir();
		$export_dir = trailingslashit( $upload_dir['basedir'] ) . 'sscribe-exports/';
		wp_mkdir_p( $export_dir );

		$valid_file = 'valid-export-FR.zip';
		file_put_contents( $export_dir . $valid_file, 'zip bytes' );

		$zip_handler = new class() extends \SScribe_Zip_Handler {
			public function __construct() {}

			public function get_ajax_download_url( string $zip_filename ): string {
				return 'https://example.org/download?file=' . rawurlencode( $zip_filename );
			}
		};

		$admin  = new SScribe_Admin( null, null, $zip_handler );
		$method = new \ReflectionMethod( $admin, 'build_recent_exports' );

		$rows = $method->invoke(
			$admin,
			array(
				$valid_file              => array(
					'created_at' => 200,
					'user_id'    => 1,
					'lang_code'  => 'fr',
				),
				'../../invalid.zip'      => array(
					'created_at' => 300,
					'user_id'    => 1,
					'lang_code'  => 'en',
				),
				'not-an-export.txt'      => array(
					'created_at' => 400,
					'user_id'    => 1,
				),
				'other-user-export.zip'  => array(
					'created_at' => 500,
					'user_id'    => 2,
				),
				'broken-export.zip'      => 'not-an-array',
			),
			$export_dir,
			true,
			array(
				array(
					'code'     => 'fr',
					'name'     => 'French',
					'flag_url' => 'https://example.org/fr.svg',
				),
			),
			1
		);

		$this->assertCount( 1, $rows );
		$this->assertSame( $valid_file, $rows[0]['filename'] );
		$this->assertSame( 'fr', $rows[0]['lang_code'] );
		$this->assertSame( 'French', $rows[0]['lang_name'] );
		$this->assertSame( 'https://example.org/fr.svg', $rows[0]['flag_url'] );
		$this->assertSame( 'https://example.org/download?file=valid-export-FR.zip', $rows[0]['url'] );

		wp_delete_file( $export_dir . $valid_file );
	}
}
