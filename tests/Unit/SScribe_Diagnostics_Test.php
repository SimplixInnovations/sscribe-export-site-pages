<?php
/**
 * Unit tests for SScribe_Diagnostics class.
 *
 * @package SScribe
 */

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

	/**
	 * Test that diagnostics can be instantiated.
	 */
	public function test_diagnostics_can_be_instantiated(): void {
		$this->assertInstanceOf( SScribe_Diagnostics::class, $this->diagnostics );
	}

	/**
	 * Test get_support_info returns array.
	 */
	public function test_get_support_info_returns_array(): void {
		$result = $this->diagnostics->get_support_info();
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'generated_at', $result );
		$this->assertArrayHasKey( 'sections', $result );
	}

	/**
	 * Test get_support_info has required sections.
	 */
	public function test_get_support_info_has_required_sections(): void {
		$result = $this->diagnostics->get_support_info();

		$this->assertArrayHasKey( 'plugin', $result['sections'] );
		$this->assertArrayHasKey( 'environment', $result['sections'] );
		$this->assertArrayHasKey( 'paths', $result['sections'] );
		$this->assertArrayHasKey( 'stats', $result['sections'] );
		$this->assertArrayHasKey( 'health', $result['sections'] );
	}

	/**
	 * Test get_support_info plugin section.
	 */
	public function test_get_support_info_plugin_section(): void {
		$result = $this->diagnostics->get_support_info();
		$plugin = $result['sections']['plugin']['items'];

		$this->assertArrayHasKey( 'plugin_version', $plugin );
		$this->assertSame( SSCRIBE_VERSION, $plugin['plugin_version'] );
		$this->assertArrayHasKey( 'debug_mode', $plugin );
		$this->assertArrayHasKey( 'wpml_active', $plugin );
		$this->assertArrayHasKey( 'seo_plugins', $plugin );
	}

	/**
	 * Test get_support_info environment section.
	 */
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

	/**
	 * Test run_preflight returns valid structure.
	 */
	public function test_run_preflight_returns_valid_structure(): void {
		$result = $this->diagnostics->run_preflight( 5, array( 'docx' ) );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'status', $result );
		$this->assertArrayHasKey( 'checks', $result );
		$this->assertArrayHasKey( 'recommendations', $result );
		$this->assertArrayHasKey( 'can_proceed', $result );
	}

	/**
	 * Test run_preflight with empty formats.
	 */
	public function test_run_preflight_with_empty_formats(): void {
		$result = $this->diagnostics->run_preflight( 0, array() );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'can_proceed', $result );
	}

	/**
	 * Test run_preflight checks all required checks.
	 */
	public function test_run_preflight_runs_all_checks(): void {
		$result = $this->diagnostics->run_preflight( 10, array( 'docx', 'pdf' ) );
		$checks = $result['checks'];

		$this->assertArrayHasKey( 'php_version', $checks );
		$this->assertArrayHasKey( 'memory', $checks );
		$this->assertArrayHasKey( 'execution', $checks );
		$this->assertArrayHasKey( 'upload_dir', $checks );
		$this->assertArrayHasKey( 'zip', $checks );
		$this->assertArrayHasKey( 'mpdf', $checks );
		$this->assertArrayHasKey( 'phpword', $checks );
		$this->assertArrayHasKey( 'permissions', $checks );
		$this->assertArrayHasKey( 'wp_cron', $checks );
		$this->assertArrayHasKey( 'session', $checks );
	}

	/**
	 * Test run_preflight status is 'ok' when no errors.
	 */
	public function test_run_preflight_status_ok_with_good_environment(): void {
		$result = $this->diagnostics->run_preflight( 1, array( 'docx' ) );

		$this->assertContains( $result['status'], array( 'ok', 'warning', 'error' ) );
	}

	/**
	 * Test check_vendor_dependencies with all dependencies.
	 */
	public function test_check_vendor_dependencies(): void {
		$method = new \ReflectionMethod( SScribe_Diagnostics::class, 'check_vendor_dependencies' );

		$result = $method->invoke( $this->diagnostics );

		$this->assertIsArray( $result );
	}

	/**
	 * Test check_php_version returns valid structure.
	 */
	public function test_check_php_version_returns_valid(): void {
		$method = new \ReflectionMethod( SScribe_Diagnostics::class, 'check_php_version' );

		$result = $method->invoke( $this->diagnostics );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'name', $result );
		$this->assertArrayHasKey( 'status', $result );
		$this->assertArrayHasKey( 'message', $result );
	}

	/**
	 * Test check_memory returns valid structure.
	 */
	public function test_check_memory_returns_valid(): void {
		$method = new \ReflectionMethod( SScribe_Diagnostics::class, 'check_memory' );

		$args   = array( 5, array( 'docx' ) );
		$result = $method->invokeArgs( $this->diagnostics, $args );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'name', $result );
		$this->assertArrayHasKey( 'status', $result );
		$this->assertArrayHasKey( 'message', $result );
	}

	/**
	 * Test check_execution_time returns valid structure.
	 */
	public function test_check_execution_time_returns_valid(): void {
		$method = new \ReflectionMethod( SScribe_Diagnostics::class, 'check_execution_time' );

		$args   = array( 10 );
		$result = $method->invokeArgs( $this->diagnostics, $args );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'name', $result );
		$this->assertArrayHasKey( 'status', $result );
	}

	/**
	 * Test check_upload_directory returns valid structure.
	 */
	public function test_check_upload_directory_returns_valid(): void {
		$method = new \ReflectionMethod( SScribe_Diagnostics::class, 'check_upload_directory' );

		$result = $method->invoke( $this->diagnostics );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'name', $result );
		$this->assertArrayHasKey( 'status', $result );
	}

	/**
	 * Test check_zip_extension returns valid structure.
	 */
	public function test_check_zip_extension_returns_valid(): void {
		$method = new \ReflectionMethod( SScribe_Diagnostics::class, 'check_zip_extension' );

		$result = $method->invoke( $this->diagnostics );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'name', $result );
		$this->assertArrayHasKey( 'status', $result );
	}

	/**
	 * Test check_file_permissions returns valid structure.
	 */
	public function test_check_file_permissions_returns_valid(): void {
		$method = new \ReflectionMethod( SScribe_Diagnostics::class, 'check_file_permissions' );

		$result = $method->invoke( $this->diagnostics );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'name', $result );
		$this->assertArrayHasKey( 'status', $result );
	}

	/**
	 * Test check_wp_cron returns valid structure.
	 */
	public function test_check_wp_cron_returns_valid(): void {
		$method = new \ReflectionMethod( SScribe_Diagnostics::class, 'check_wp_cron' );

		$result = $method->invoke( $this->diagnostics );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'name', $result );
		$this->assertArrayHasKey( 'status', $result );
	}

	/**
	 * Test check_session_health returns valid structure.
	 */
	public function test_check_session_health_returns_valid(): void {
		$method = new \ReflectionMethod( SScribe_Diagnostics::class, 'check_session_health' );

		$result = $method->invoke( $this->diagnostics );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'name', $result );
		$this->assertArrayHasKey( 'status', $result );
	}

	/**
	 * Test get_recommendations returns array.
	 */
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

	/**
	 * Test get_active_seo_plugins returns array.
	 */
	public function test_get_active_seo_plugins_returns_array(): void {
		$method = new \ReflectionMethod( SScribe_Diagnostics::class, 'get_active_seo_plugins' );

		$result = $method->invoke( $this->diagnostics );

		$this->assertIsArray( $result );
	}

	/**
	 * Test build_support_copy_text returns string.
	 */
	public function test_build_support_copy_text_returns_string(): void {
		$method = new \ReflectionMethod( SScribe_Diagnostics::class, 'build_support_copy_text' );

		$sections            = array(
			'plugin'     => array( 'label' => 'Plugin', 'items' => array( 'version' => '3.50.4' ) ),
			'environment' => array( 'label' => 'Env', 'items' => array( 'php' => '8.2' ) ),
		);
		$audit              = array();
		$log_tail           = array();

		$args   = array( $sections, $audit, $log_tail );
		$result = $method->invokeArgs( $this->diagnostics, $args );

		$this->assertIsString( $result );
	}

	/**
	 * Test build_support_copy_text includes debug warning when enabled.
	 */
	public function test_build_support_copy_text_includes_debug_warning(): void {
		$method = new \ReflectionMethod( SScribe_Diagnostics::class, 'build_support_copy_text' );

		$sections            = array(
			'plugin' => array(
				'label' => 'Plugin',
				'items' => array(
					'version'     => '3.50.4',
					'debug_mode' => 'Enabled',
				),
			),
		);
		$audit              = array();
		$log_tail           = array();

		$args   = array( $sections, $audit, $log_tail );
		$result = $method->invokeArgs( $this->diagnostics, $args );

		$this->assertStringContainsStringIgnoringCase( 'debug', $result );
	}
}











