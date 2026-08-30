<?php
/**
 * SScribe Export Query Controller Unit Test
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class SScribe_Export_Query_Controller_Test extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['sscribe_test_current_user_can'] = true;
		$GLOBALS['sscribe_test_current_user_id']  = 1;
		$_POST                                    = array();
	}

	protected function tearDown(): void {
		$_POST                                    = array();
		$GLOBALS['sscribe_test_current_user_can'] = null;
		$GLOBALS['sscribe_test_current_user_id']  = null;
		parent::tearDown();
	}

	public function test_can_instantiate_with_no_args(): void {
		$controller = new \SScribe_Export_Query_Controller();
		$this->assertInstanceOf( \SScribe_Export_Query_Controller::class, $controller );
	}

	public function test_can_instantiate_with_mock_deps(): void {
		$controller = new \SScribe_Export_Query_Controller(
			$this->createMock( \SScribe_Export_Rate_Limiter::class ),
			$this->createMock( \SScribe_Diagnostics::class ),
			$this->createMock( \SScribe_Page_Collector::class ),
			$this->createMock( \SScribe_Logger_Interface::class ),
			$this->createMock( \SScribe_Zip_Handler::class ),
			$this->createMock( \SScribe_Adaptive_Metrics::class ),
			$this->createMock( \SScribe_Export_Error_Handler::class )
		);
		$this->assertInstanceOf( \SScribe_Export_Query_Controller::class, $controller );
	}

	public function test_can_instantiate_with_partial_deps(): void {
		$controller = new \SScribe_Export_Query_Controller(
			$this->createMock( \SScribe_Export_Rate_Limiter::class ),
			null,
			$this->createMock( \SScribe_Page_Collector::class )
		);
		$this->assertInstanceOf( \SScribe_Export_Query_Controller::class, $controller );
	}

	private function build_controller(
		?\SScribe_Export_Rate_Limiter $rate_limiter = null,
		?\SScribe_Diagnostics $diagnostics = null,
		?\SScribe_Page_Collector $collector = null,
		?\SScribe_Zip_Handler $zip_handler = null,
		?\SScribe_Adaptive_Metrics $adaptive_metrics = null,
		?\SScribe_Export_Error_Handler $error_handler = null,
		?\SScribe_Logger_Interface $logger = null
	): \SScribe_Export_Query_Controller {
		if ( null === $rate_limiter ) {
			$rate_limiter = $this->createMock( \SScribe_Export_Rate_Limiter::class );
			$rate_limiter->method( 'check_rate_limit' )->willReturn( true );
		}
		return new \SScribe_Export_Query_Controller(
			$rate_limiter,
			$diagnostics ?? new \SScribe_Diagnostics(),
			$collector ?? new \SScribe_Page_Collector(),
			$logger ?? $this->createMock( \SScribe_Logger_Interface::class ),
			$zip_handler ?? new \SScribe_Zip_Handler(),
			$adaptive_metrics ?? new \SScribe_Adaptive_Metrics(),
			$error_handler ?? new \SScribe_Export_Error_Handler()
		);
	}

	public function test_ajax_get_status_counts_merges_any_post_type(): void {
		$_POST['nonce']     = wp_create_nonce( 'sscribe_export_nonce' );
		$_POST['post_type'] = 'any';

		$collector = $this->createMock( \SScribe_Page_Collector::class );
		$collector->method( 'normalize_language_code' )->willReturn( '' );
		$collector->method( 'get_post_status_counts' )->willReturnCallback(
			static function ( string $language, string $post_type ): array {
				if ( 'page' === $post_type ) {
					return array(
						'publish' => 4,
						'draft'   => 1,
					);
				}
				return array(
					'publish' => 3,
					'pending' => 2,
				);
			}
		);

		$controller = $this->build_controller( collector: $collector );

		$json = $this->invoke_and_capture( $controller, 'ajax_get_status_counts' );

		$this->assertTrue( $json['success'] );
		$this->assertSame( 7, $json['data']['counts']['publish'] );
		$this->assertSame( 1, $json['data']['counts']['draft'] );
		$this->assertSame( 2, $json['data']['counts']['pending'] );
		$this->assertSame( 4, $json['data']['counts_page']['publish'] );
		$this->assertSame( 3, $json['data']['counts_post']['publish'] );
		$this->assertSame( 7, $json['data']['counts_any']['publish'] );
	}

	public function test_ajax_get_all_status_counts_combines_page_post(): void {
		$_POST['nonce']        = wp_create_nonce( 'sscribe_export_nonce' );
		$_POST['post_type']    = 'page';
		$_POST['languages']    = array( 'en' );

		$collector = $this->createMock( \SScribe_Page_Collector::class );
		$collector->method( 'normalize_language_code' )->willReturn( 'en' );
		$collector->method( 'get_post_status_counts' )->willReturnCallback(
			static function ( string $language, string $post_type ): array {
				return array(
					'publish' => 'page' === $post_type ? 7 : 5,
				);
			}
		);

		$controller = $this->build_controller( collector: $collector );

		$json = $this->invoke_and_capture( $controller, 'ajax_get_all_status_counts' );

		$this->assertTrue( $json['success'] );
		$this->assertSame( 'page', $json['data']['post_type'] );
		$this->assertSame( 1, $json['data']['queried_count'] );
		$this->assertArrayHasKey( 'en', $json['data']['languages'] );
		$this->assertSame( 7, $json['data']['languages']['en']['counts']['publish'] );
		$this->assertSame( 7, $json['data']['languages']['en']['counts_page']['publish'] );
		$this->assertSame( 5, $json['data']['languages']['en']['counts_post']['publish'] );
	}

	public function test_ajax_get_export_log_rejects_invalid_filename(): void {
		$_POST['nonce'] = wp_create_nonce( 'sscribe_download' );
		$_POST['file']  = '../etc/passwd';

		$controller = $this->build_controller();

		$json = $this->invoke_and_capture( $controller, 'ajax_get_export_log' );

		$this->assertFalse( $json['success'] );
		$this->assertSame( 'Invalid filename.', $json['data']['message'] );
	}

	public function test_ajax_preflight_check_response_shape(): void {
		$_POST['nonce']      = wp_create_nonce( 'sscribe_export_nonce' );
		$_POST['formats']    = array( 'docx' );
		$_POST['page_count'] = 10;

		$diagnostics = $this->createMock( \SScribe_Diagnostics::class );
		$diagnostics->method( 'run_preflight' )->willReturn(
			array(
				'ok'             => true,
				'estimated_time' => 5,
				'warnings'       => array(),
			)
		);

		$controller = $this->build_controller( diagnostics: $diagnostics );

		$json = $this->invoke_and_capture( $controller, 'ajax_preflight_check' );

		$this->assertTrue( $json['success'] );
		$this->assertTrue( $json['data']['ok'] );
		$this->assertSame( 5, $json['data']['estimated_time'] );
		$this->assertIsArray( $json['data']['warnings'] );
	}

	public function test_ajax_get_export_preview_returns_estimated_shape(): void {
		$_POST['nonce']       = wp_create_nonce( 'sscribe_export_nonce' );
		$_POST['language']    = '';
		$_POST['post_status'] = 'publish';
		$_POST['post_type']   = 'page';
		$_POST['format']      = 'docx';

		$collector = $this->createMock( \SScribe_Page_Collector::class );
		$collector->method( 'normalize_language_code' )->willReturn( '' );
		$collector->method( 'get_page_count_only' )->willReturn( 0 );
		$collector->method( 'get_page_ids' )->willReturn( array() );

		$adaptive_metrics = $this->createMock( \SScribe_Adaptive_Metrics::class );
		$adaptive_metrics->method( 'get_seconds_per_page' )->willReturn( 0.0 );
		$adaptive_metrics->method( 'get_mb_per_page' )->willReturn( 0.0 );

		$controller = $this->build_controller(
			collector: $collector,
			adaptive_metrics: $adaptive_metrics
		);

		$json = $this->invoke_and_capture( $controller, 'ajax_get_export_preview' );

		$this->assertTrue( $json['success'] );
		$this->assertSame( 0, $json['data']['total_pages'] );
		$this->assertSame( 'docx', $json['data']['format'] );
		$this->assertArrayHasKey( 'estimated_time', $json['data'] );
		$this->assertArrayHasKey( 'file_size_estimate', $json['data'] );
		$this->assertSame( 'publish', $json['data']['post_status'] );
	}

	public function test_ajax_get_support_info_health_cap_gate(): void {
		$GLOBALS['sscribe_test_current_user_can'] = false;
		$_POST['nonce']                          = wp_create_nonce( 'sscribe_health_nonce' );

		$diagnostics = $this->createMock( \SScribe_Diagnostics::class );
		$diagnostics->expects( $this->never() )->method( 'get_support_info' );

		$controller = $this->build_controller( diagnostics: $diagnostics );

		$json = $this->invoke_and_capture( $controller, 'ajax_get_support_info' );

		$this->assertFalse( $json['success'] );
		$this->assertSame( 'permission_denied', $json['data']['code'] );
	}

	public function test_ajax_get_support_info_thrown_error_returns_500(): void {
		$_POST['nonce'] = wp_create_nonce( 'sscribe_health_nonce' );

		$diagnostics = $this->createMock( \SScribe_Diagnostics::class );
		$diagnostics->method( 'get_support_info' )->willReturnCallback(
			static function (): array {
				throw new \RuntimeException( 'boom' );
			}
		);

		$logger = $this->createMock( \SScribe_Logger_Interface::class );
		$logger->expects( $this->once() )->method( 'error' );

		$controller = $this->build_controller(
			diagnostics: $diagnostics,
			logger: $logger
		);

		$json = $this->invoke_and_capture( $controller, 'ajax_get_support_info' );

		$this->assertFalse( $json['success'] );
		$this->assertSame( 'support_info_unavailable', $json['data']['code'] );
	}

	private function invoke_and_capture( \SScribe_Export_Query_Controller $controller, string $method ): array {
		$output = '';

		try {
			ob_start();
			$controller->$method();
			$this->fail( 'Expected RuntimeException from AJAX response' );
		} catch ( \RuntimeException $e ) {
			$output = ob_get_clean();
		}

		$json = json_decode( $output, true );

		return is_array( $json ) ? $json : array();
	}
}