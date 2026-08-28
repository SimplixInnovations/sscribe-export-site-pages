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

	public function test_add_admin_menu_rejects_unapproved_filtered_capability(): void {
		global $sscribe_test_menu_pages;

		add_filter(
			'sscribe_export_capability',
			static function (): string {
				return 'edit_pages';
			}
		);

		$admin = new SScribe_Admin();
		$admin->add_admin_menu();

		$this->assertSame( 'sscribe_export', $sscribe_test_menu_pages[0]['capability'] );
	}

	public function test_enqueue_admin_assets_only_runs_for_plugin_pages(): void {
		global $sscribe_test_styles, $sscribe_test_scripts, $sscribe_test_current_user_can;

		$admin = new SScribe_Admin();
		$admin->enqueue_admin_assets( 'dashboard_page_unrelated' );

		$this->assertCount( 0, $sscribe_test_styles );
		$this->assertCount( 0, $sscribe_test_scripts );

		// Export-only users do not receive the diagnostics console assets.
		$sscribe_test_current_user_can = false;
		$admin->enqueue_admin_assets( 'toplevel_page_sscribe-export' );

		$this->assertCount( 2, $sscribe_test_styles );
		$this->assertCount( 1, $sscribe_test_scripts );

		// Diagnostics users need the controls even while logging is disabled.
		$sscribe_test_styles          = array();
		$sscribe_test_scripts         = array();
		$sscribe_test_current_user_can = true;
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
		$export_dir = trailingslashit( \SScribe_Private_Storage::get_export_dir() );
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

	public function test_build_post_type_strings_returns_any_and_per_slug_labels(): void {
		$collector = new class() extends \SScribe_Page_Collector {
			public function __construct() {}

			public function get_selectable_post_types(): array {
				return array( 'page', 'post', 'product' );
			}
		};

		$admin  = new SScribe_Admin( $collector );
		$method = new \ReflectionMethod( $admin, 'build_post_type_strings' );

		$strings = $method->invoke( $admin );

		$this->assertArrayHasKey( 'post_type_any', $strings );
		$this->assertSame( 'All types', $strings['post_type_any'] );
		$this->assertArrayHasKey( 'post_type_page', $strings );
		$this->assertArrayHasKey( 'post_type_post', $strings );
		$this->assertArrayHasKey( 'post_type_product', $strings );
		$this->assertSame( 'Product', $strings['post_type_product'] );
	}

	public function test_build_post_type_strings_falls_back_to_ucfirst_when_label_missing(): void {
		$GLOBALS['sscribe_test_registered_post_types'] = array();

		$collector = new class() extends \SScribe_Page_Collector {
			public function __construct() {}

			public function get_selectable_post_types(): array {
				return array( 'page', 'unknown_thing' );
			}
		};

		$admin  = new SScribe_Admin( $collector );
		$method = new \ReflectionMethod( $admin, 'build_post_type_strings' );

		$strings = $method->invoke( $admin );

		$this->assertSame( 'Unknown_thing', $strings['post_type_unknown_thing'] );

		unset( $GLOBALS['sscribe_test_registered_post_types'] );
	}

	public function test_build_selectable_type_rows_appends_any_aggregate(): void {
		$collector = new class() extends \SScribe_Page_Collector {
			public function __construct() {}

			public function get_selectable_post_types(): array {
				return array( 'page', 'post' );
			}

			public function get_page_count_only( string $language = '', string $post_status = 'publish', string $post_type = 'page' ): int {
				if ( 'post' === $post_type ) {
					return 7;
				}
				return 3;
			}
		};

		$admin  = new SScribe_Admin( $collector );
		$method = new \ReflectionMethod( $admin, 'build_selectable_type_rows' );

		$rows = $method->invoke( $admin );

		$this->assertCount( 3, $rows );
		$this->assertSame( 'page', $rows[0]['slug'] );
		$this->assertSame( 3, $rows[0]['count'] );
		$this->assertFalse( $rows[0]['is_any'] );
		$this->assertSame( 'post', $rows[1]['slug'] );
		$this->assertSame( 7, $rows[1]['count'] );
		$this->assertSame( 'any', $rows[2]['slug'] );
		$this->assertTrue( $rows[2]['is_any'] );
		$this->assertSame( 10, $rows[2]['count'] );
		$this->assertSame( 'All types', $rows[2]['label'] );
	}

	public function test_build_selectable_type_rows_uses_known_icons_per_slug(): void {
		$collector = new class() extends \SScribe_Page_Collector {
			public function __construct() {}

			public function get_selectable_post_types(): array {
				return array( 'page', 'post', 'product' );
			}

			public function get_page_count_only( string $language = '', string $post_status = 'publish', string $post_type = 'page' ): int {
				return 0;
			}
		};

		$admin  = new SScribe_Admin( $collector );
		$method = new \ReflectionMethod( $admin, 'build_selectable_type_rows' );

		$rows = $method->invoke( $admin );

		$icons = array();
		foreach ( $rows as $row ) {
			$icons[ $row['slug'] ] = $row['icon'];
		}

		$this->assertSame( 'file-text', $icons['page'] );
		$this->assertSame( 'article', $icons['post'] );
		$this->assertSame( 'file-text', $icons['product'] );
		$this->assertSame( 'copy', $icons['any'] );
	}

	public function test_partial_emits_data_sscribe_count_for_per_selectable_type(): void {
		$collector = new class() extends \SScribe_Page_Collector {
			public function __construct() {}

			public function get_selectable_post_types(): array {
				return array( 'page', 'post', 'product' );
			}

			public function get_page_count_only( string $language = '', string $post_status = 'publish', string $post_type = 'page' ): int {
				if ( 'post' === $post_type ) {
					return 12;
				}
				if ( 'product' === $post_type ) {
					return 4;
				}
				return 8;
			}
		};

		$admin = new SScribe_Admin( $collector );

		$rows = ( new \ReflectionMethod( $admin, 'build_selectable_type_rows' ) )->invoke( $admin );

		$partial_path = dirname( __DIR__, 2 ) . '/admin/partials/sscribe-admin-display.php';
		$this->assertFileExists( $partial_path );

		$sscribe_wpml_active          = false;
		$sscribe_languages            = array();
		$sscribe_total_pages_all      = 8;
		$sscribe_total_posts_all      = 12;
		$sscribe_total_either_all     = 20;
		$sscribe_status_counts        = array();
		$sscribe_recent_exports       = array();
		$sscribe_debug_info           = array();
		$sscribe_is_debug             = false;
		$sscribe_can_view_health      = false;
		$sscribe_export_index         = array();
		$sscribe_preflight_warnings   = array();
		$sscribe_selectable_types     = $rows;

		ob_start();
		include $partial_path;
		$output = (string) ob_get_clean();

		preg_match_all( '/data-sscribe-count-for="([^"]+)"/', $output, $matches );
		$slugs = $matches[1] ?? array();

		$this->assertContains( 'page', $slugs );
		$this->assertContains( 'post', $slugs );
		$this->assertContains( 'product', $slugs );
		$this->assertContains( 'any', $slugs );
		$this->assertCount( 4, $slugs );

		$this->assertStringContainsString( 'data-sscribe-count-for="page"', $output );
		$this->assertStringContainsString( 'data-sscribe-count-for="any"', $output );
	}
}
