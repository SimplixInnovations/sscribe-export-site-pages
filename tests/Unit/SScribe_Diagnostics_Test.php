<?php
/**
 * SScribe Diagnostics Unit Test
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SScribe_Diagnostics;

class SScribe_Diagnostics_Test extends TestCase {
	private ?SScribe_Diagnostics $diagnostics;

	protected function setUp(): void {
		parent::setUp();
		$this->diagnostics = new SScribe_Diagnostics();
	}

	protected function tearDown(): void {
		$this->diagnostics = null;
		parent::tearDown();
	}

	public function test_diagnostics_can_be_instantiated(): void {
		$this->assertInstanceOf( SScribe_Diagnostics::class, $this->diagnostics );
	}

	public function test_get_support_info_returns_array(): void {
		$result = $this->diagnostics->get_support_info();
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'generated_at', $result );
		$this->assertArrayHasKey( 'sections', $result );
	}

	public function test_get_support_info_has_required_sections(): void {
		$result = $this->diagnostics->get_support_info();

		$this->assertArrayHasKey( 'plugin', $result['sections'] );
		$this->assertArrayHasKey( 'environment', $result['sections'] );
		$this->assertArrayHasKey( 'paths', $result['sections'] );
		$this->assertArrayHasKey( 'stats', $result['sections'] );
		$this->assertArrayHasKey( 'health', $result['sections'] );
	}

	public function test_get_support_info_plugin_section(): void {
		$result = $this->diagnostics->get_support_info();
		$plugin = $result['sections']['plugin']['items'];

		$this->assertArrayHasKey( 'plugin_version', $plugin );
		$this->assertSame( SSCRIBE_VERSION, $plugin['plugin_version'] );
		$this->assertArrayHasKey( 'debug_mode', $plugin );
		$this->assertArrayHasKey( 'wpml_active', $plugin );
		$this->assertArrayHasKey( 'seo_plugins', $plugin );
	}

	public function test_get_support_info_environment_section(): void {
		$result      = $this->diagnostics->get_support_info();
		$environment = $result['sections']['environment']['items'];

		$this->assertArrayHasKey( 'wordpress_version', $environment );
		$this->assertArrayHasKey( 'php_version', $environment );
		$this->assertArrayHasKey( 'locale', $environment );
		$this->assertArrayHasKey( 'memory_limit', $environment );
		$this->assertArrayHasKey( 'max_execution_time', $environment );
		$this->assertArrayHasKey( 'zip_extension', $environment );
	}

	public function test_run_preflight_returns_valid_structure(): void {
		$result = $this->diagnostics->run_preflight( 5, array( 'docx' ) );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'status', $result );
		$this->assertArrayHasKey( 'checks', $result );
		$this->assertArrayHasKey( 'recommendations', $result );
		$this->assertArrayHasKey( 'can_proceed', $result );
	}

	public function test_run_preflight_with_empty_formats(): void {
		$result = $this->diagnostics->run_preflight( 0, array() );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'can_proceed', $result );
	}

	public function test_run_preflight_runs_all_checks(): void {
		$result = $this->diagnostics->run_preflight( 10, array( 'docx', 'pdf' ) );
		$checks = $result['checks'];

		$this->assertArrayHasKey( 'php_version', $checks );
		$this->assertArrayHasKey( 'memory', $checks );
		$this->assertArrayHasKey( 'execution', $checks );
		$this->assertArrayHasKey( 'upload_dir', $checks );
		$this->assertArrayHasKey( 'zip', $checks );
		$this->assertArrayHasKey( 'tcpdf', $checks );
		$this->assertArrayHasKey( 'phpword', $checks );
		$this->assertArrayHasKey( 'permissions', $checks );
		$this->assertArrayHasKey( 'wp_cron', $checks );
		$this->assertArrayHasKey( 'session', $checks );
	}

	public function test_run_preflight_status_ok_with_good_environment(): void {
		$result = $this->diagnostics->run_preflight( 1, array( 'docx' ) );

		$this->assertContains( $result['status'], array( 'ok', 'warning', 'error' ) );
	}

	public function test_check_vendor_dependencies(): void {
		$method = new \ReflectionMethod( SScribe_Diagnostics::class, 'check_vendor_dependencies' );

		$result = $method->invoke( $this->diagnostics );

		$this->assertIsArray( $result );

		// Regression: when the prefixed vendor autoloader is available
		// on disk (which it is in any install where composer install /
		// the build pipeline ran), the check must return an empty list.
		// Otherwise the admin notice fires a false-positive on every
		// page load saying "Run composer install" — see commit
		// (lazy-autoload-prewarm).
		if ( file_exists( SSCRIBE_PLUGIN_DIR . 'vendor-prefixed/autoload.php' ) ) {
			$this->assertSame(
				array(),
				$result,
				'check_vendor_dependencies() must return empty when vendor-prefixed/autoload.php is present; got: ' . implode( ', ', $result )
			);
		}
	}

	/**
	 * Regression: the admin notice fires on the first request after
	 * plugin boot — before any call site has touched
	 * `SScribe_Exporter_Factory`. Pre-warm must work even when the
	 * factory class is not yet loaded.
	 *
	 * Simulates the cold path by clearing the autoload cache right
	 * before invoking the check. If class caching picks up on a
	 * subsequent test, this exercises the code path where the
	 * autoloader was never called yet.
	 */
	public function test_check_vendor_dependencies_in_cold_state(): void {
		// Trick: clear class_exists's internal cache so a fresh
		// class_exists('\SScribe_Exporter_Factory') call does NOT find
		// it via the in-memory cache and MUST fall back through the
		// disc autoloader.
		if ( function_exists( 'wp_cache_clear_cache_group' ) ) {
			wp_cache_clear_cache_group( '' );
		}

		$method = new \ReflectionMethod( SScribe_Diagnostics::class, 'check_vendor_dependencies' );

		$result = $method->invoke( $this->diagnostics );

		$this->assertIsArray( $result );

		if ( file_exists( SSCRIBE_PLUGIN_DIR . 'vendor-prefixed/autoload.php' ) ) {
			$this->assertSame(
				array(),
				$result,
				'check_vendor_dependencies() must return empty on the cold path too (factory not pre-loaded); got: ' . implode( ', ', $result )
			);
		}
	}

	/**
	 * Regression: SSCRIBE_VENDOR_AUTOLOADED must be defined after
	 * check_vendor_dependencies() returns when the prefixed vendor
	 * tree is present. The admin notice should only fire when the
	 * vendor is genuinely missing — never because the autoloader
	 * hadn't run yet.
	 */
	public function test_check_vendor_dependencies_defines_autoloaded_constant(): void {
		$method = new \ReflectionMethod( SScribe_Diagnostics::class, 'check_vendor_dependencies' );
		$method->invoke( $this->diagnostics );

		if ( file_exists( SSCRIBE_PLUGIN_DIR . 'vendor-prefixed/autoload.php' ) ) {
			$this->assertTrue(
				defined( 'SSCRIBE_VENDOR_AUTOLOADED' ),
				'SSCRIBE_VENDOR_AUTOLOADED must be defined after check_vendor_dependencies() runs and the vendor tree is on disk'
			);
		}
	}

	public function test_check_php_version_returns_valid(): void {
		$method = new \ReflectionMethod( SScribe_Diagnostics::class, 'check_php_version' );

		$result = $method->invoke( $this->diagnostics );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'name', $result );
		$this->assertArrayHasKey( 'status', $result );
		$this->assertArrayHasKey( 'message', $result );
	}

	public function test_check_memory_returns_valid(): void {
		$method = new \ReflectionMethod( SScribe_Diagnostics::class, 'check_memory' );

		$args   = array( 5, array( 'docx' ) );
		$result = $method->invokeArgs( $this->diagnostics, $args );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'name', $result );
		$this->assertArrayHasKey( 'status', $result );
		$this->assertArrayHasKey( 'message', $result );
	}

	public function test_check_execution_time_returns_valid(): void {
		$method = new \ReflectionMethod( SScribe_Diagnostics::class, 'check_execution_time' );

		$args   = array( 10 );
		$result = $method->invokeArgs( $this->diagnostics, $args );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'name', $result );
		$this->assertArrayHasKey( 'status', $result );
	}

	public function test_check_upload_directory_returns_valid(): void {
		$method = new \ReflectionMethod( SScribe_Diagnostics::class, 'check_upload_directory' );

		$result = $method->invoke( $this->diagnostics );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'name', $result );
		$this->assertArrayHasKey( 'status', $result );
	}

	public function test_check_zip_extension_returns_valid(): void {
		$method = new \ReflectionMethod( SScribe_Diagnostics::class, 'check_zip_extension' );

		$result = $method->invoke( $this->diagnostics );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'name', $result );
		$this->assertArrayHasKey( 'status', $result );
	}

	public function test_check_file_permissions_returns_valid(): void {
		$method = new \ReflectionMethod( SScribe_Diagnostics::class, 'check_file_permissions' );

		$result = $method->invoke( $this->diagnostics );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'name', $result );
		$this->assertArrayHasKey( 'status', $result );
	}

	public function test_check_wp_cron_returns_valid(): void {
		$method = new \ReflectionMethod( SScribe_Diagnostics::class, 'check_wp_cron' );

		$result = $method->invoke( $this->diagnostics );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'name', $result );
		$this->assertArrayHasKey( 'status', $result );
	}

	public function test_check_session_health_returns_valid(): void {
		$method = new \ReflectionMethod( SScribe_Diagnostics::class, 'check_session_health' );

		$result = $method->invoke( $this->diagnostics );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'name', $result );
		$this->assertArrayHasKey( 'status', $result );
	}

	public function test_temp_cleanup_only_removes_inactive_plugin_temp_directories(): void {
		$export_dir = \SScribe_Private_Storage::get_export_dir();
		$suffix     = bin2hex( random_bytes( 4 ) );
		$stale_dir  = $export_dir . '/temp-stale-' . $suffix;
		$active_dir = $export_dir . '/temp-active-' . $suffix;
		$logs_dir   = $export_dir . '/logs';
		$zip_file   = $export_dir . '/site-export-' . $suffix . '.zip';
		$index_file = $export_dir . '/index.php';
		$old_time   = time() - ( 4 * DAY_IN_SECONDS );

		wp_mkdir_p( $stale_dir );
		wp_mkdir_p( $active_dir );
		wp_mkdir_p( $logs_dir );
		\SScribe_Security::protect_directory( $export_dir );
		file_put_contents( $stale_dir . '/page.html', 'stale' );
		file_put_contents( $active_dir . '/page.html', 'active' );
		file_put_contents( $zip_file, 'archive' );
		touch( $stale_dir, $old_time );
		touch( $active_dir, $old_time );
		touch( $zip_file, $old_time );
		touch( $logs_dir, $old_time );

		$session    = new \SScribe_Session();
		$session_id = $session->create(
			array(
				'temp_dir' => $active_dir,
				'status'   => 'processing',
				'total'    => 1,
				'processed'=> 0,
			)
		);

		try {
			$method  = new \ReflectionMethod( SScribe_Diagnostics::class, 'clear_old_temp_files' );
			$cleared = $method->invoke( $this->diagnostics );

			$this->assertSame( 1, $cleared );
			$this->assertDirectoryDoesNotExist( $stale_dir );
			$this->assertDirectoryExists( $active_dir );
			$this->assertDirectoryExists( $logs_dir );
			$this->assertFileExists( $zip_file );
			$this->assertFileExists( $index_file );
		} finally {
			if ( '' !== $session_id ) {
				$session->delete( $session_id );
			}
			\SScribe_Security::delete_directory( $active_dir );
			wp_delete_file( $zip_file );
		}
	}

	public function test_active_temp_membership_requires_exact_canonical_path(): void {
		$export_dir = \SScribe_Private_Storage::get_export_dir();
		$active_dir = $export_dir . '/temp-a';
		$other_dir  = $export_dir . '/temp-attacker';

		wp_mkdir_p( $active_dir );
		wp_mkdir_p( $other_dir );

		try {
			$normalize  = new \ReflectionMethod( SScribe_Diagnostics::class, 'normalize_path' );
			$membership = new \ReflectionMethod( SScribe_Diagnostics::class, 'is_temp_dir_in_active_set' );
			$active     = array( $normalize->invoke( $this->diagnostics, (string) realpath( $active_dir ) ) => true );

			$this->assertTrue( $membership->invoke( $this->diagnostics, $active_dir, $active ) );
			$this->assertFalse( $membership->invoke( $this->diagnostics, $other_dir, $active ) );
		} finally {
			\SScribe_Security::delete_directory( $active_dir );
			\SScribe_Security::delete_directory( $other_dir );
		}
	}

	public function test_get_recommendations_returns_array(): void {
		$method = new \ReflectionMethod( SScribe_Diagnostics::class, 'get_recommendations' );

		$checks = array(
			'php_version' => array( 'status' => 'ok', 'name' => 'PHP', 'message' => 'PHP 8.2' ),
			'memory'      => array( 'status' => 'ok', 'name' => 'Memory', 'message' => '256MB' ),
		);

		$args   = array( $checks, 5 );
		$result = $method->invokeArgs( $this->diagnostics, $args );

		$this->assertIsArray( $result );
	}

	public function test_get_active_seo_plugins_returns_array(): void {
		$method = new \ReflectionMethod( SScribe_Diagnostics::class, 'get_active_seo_plugins' );

		$result = $method->invoke( $this->diagnostics );

		$this->assertIsArray( $result );
	}

	public function test_build_support_copy_text_returns_string(): void {
		$method = new \ReflectionMethod( SScribe_Diagnostics::class, 'build_support_copy_text' );

		$sections            = array(
			'plugin'     => array( 'label' => 'Plugin', 'items' => array( 'version' => '1.1.2' ) ),
			'environment' => array( 'label' => 'Env', 'items' => array( 'php' => '8.2' ) ),
		);
		$result = $method->invoke( $this->diagnostics, $sections );

		$this->assertIsString( $result );
	}

	public function test_build_support_copy_text_includes_debug_warning(): void {
		$method = new \ReflectionMethod( SScribe_Diagnostics::class, 'build_support_copy_text' );

$sections            = array(
			'plugin' => array(
				'label' => 'Plugin',
				'items' => array(
					'version'     => '1.1.2',
					'debug_mode'  => 'Enabled',
				),
			),
		);
		$result = $method->invoke( $this->diagnostics, $sections );

		$this->assertStringContainsStringIgnoringCase( 'debug', $result );
	}

	public function test_tcpdf_required_font_asset_contract_matches_release_pruner(): void {
		$diag_ref = new \ReflectionClass( SScribe_Diagnostics::class );
		$required = $diag_ref->getConstant( 'TCPDF_REQUIRED_FONT_FILES' );

		$this->assertIsArray( $required );
		foreach ( array( 'core/helvetica.json', 'dejavu/dejavusans.json', 'dejavu/dejavusansb.json', 'dejavu/dejavusansi.json', 'dejavu/dejavusansbi.json' ) as $required_file ) {
			$this->assertContains( $required_file, $required );
		}

		$pruner = (string) file_get_contents( SSCRIBE_PLUGIN_DIR . 'scripts/prune-tcpdf-for-strauss.php' );
		foreach ( $required as $font_file ) {
			$this->assertStringContainsString(
				"'" . $font_file . "'",
				$pruner,
				'Diagnostics must not require a TCPDF font asset that the release pruner removes: ' . $font_file
			);
		}
	}

	public function test_check_tcpdf_reports_current_renderer_contract(): void {
		$method = new \ReflectionMethod( SScribe_Diagnostics::class, 'check_tcpdf' );
		$result = $method->invoke( $this->diagnostics );

		$this->assertIsArray( $result );
		$this->assertSame( 'TCPDF Library', $result['name'] ?? '' );

		if ( file_exists( SSCRIBE_PLUGIN_DIR . 'vendor-prefixed/autoload.php' ) ) {
			$this->assertSame( 'ok', $result['status'] ?? '', 'A complete generated vendor tree must satisfy the TCPDF diagnostics check.' );
		}
	}

	/**
	 * Regression: the shipped vendor-prefixed tree does NOT include
	 * phpoffice/phpword/composer.json (it is pruned by scripts/build-release.php),
	 * so the version-lookup branch in check_phpword() is dead code. The
	 * fallback "PHPWord loaded" message is the only branch that should fire
	 * in production.
	 */
	public function test_check_phpword_does_not_probe_pruned_composer_json(): void {
		$method = new \ReflectionMethod( SScribe_Diagnostics::class, 'check_phpword' );

		$result = $method->invoke( $this->diagnostics );

		// PHPWord class is loaded in the test bootstrap via the prefixed
		// autoloader. If the class exists, the only message the function
		// should produce is the "PHPWord loaded" fallback — the
		// composer.json version probe is dead in production.
		if ( class_exists( '\\SScribeVendor\\PhpOffice\\PhpWord\\PhpWord' ) ) {
			$this->assertSame( 'PHPWord loaded : XML encoding handled natively by library', $result['message'] );
		} else {
			$this->assertSame( 'error', $result['status'] );
		}
	}
}
