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
			$rate_limiter->method( 'check_rate_limit_decision' )->willReturn(
				\SScribe_Rate_Limit_Decision::allowed( 'export_read', 200, 199, 0 )
			);
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

	/* =========================================================================
	 * Phase 1 — count request ordering regression
	 *
	 * The browser owns the generation counter. The server must ECHO the
	 * value the browser originated; it must NOT generate its own sequence
	 * number. Without the echo, the JS exact-equality check would always
	 * see a stale or missing `client_generation` and discard every response.
	 * Without the `post_type` / `language` echo, a response from a
	 * partially-completed older refresh would be indistinguishable from
	 * the current one.
	 * =======================================================================*/

	public function test_phase1_ajax_get_status_counts_echoes_client_generation(): void {
		$_POST['nonce']             = wp_create_nonce( 'sscribe_export_nonce' );
		$_POST['post_type']         = 'page';
		$_POST['client_generation'] = '7';

		$collector = $this->createMock( \SScribe_Page_Collector::class );
		$collector->method( 'normalize_language_code' )->willReturn( '' );
		$collector->method( 'get_post_status_counts' )->willReturn(
			array( 'publish' => 1 )
		);

		$controller = $this->build_controller( collector: $collector );

		$json = $this->invoke_and_capture( $controller, 'ajax_get_status_counts' );

		$this->assertTrue( $json['success'] );
		$this->assertArrayHasKey( 'client_generation', $json['data'], 'server must echo client_generation' );
		$this->assertSame( 7, $json['data']['client_generation'] );
		$this->assertArrayHasKey( 'post_type', $json['data'] );
		$this->assertSame( 'page', $json['data']['post_type'] );
		$this->assertArrayHasKey( 'language', $json['data'] );
		$this->assertArrayNotHasKey(
			'request_seq',
			$json['data'],
			'Phase 1 removed the per-process PHP request_seq mechanism; it must not come back'
		);
	}

	public function test_phase1_ajax_get_status_counts_default_generation_is_zero(): void {
		$_POST['nonce']     = wp_create_nonce( 'sscribe_export_nonce' );
		$_POST['post_type'] = 'page';

		$collector = $this->createMock( \SScribe_Page_Collector::class );
		$collector->method( 'normalize_language_code' )->willReturn( '' );
		$collector->method( 'get_post_status_counts' )->willReturn(
			array( 'publish' => 1 )
		);

		$controller = $this->build_controller( collector: $collector );

		$json = $this->invoke_and_capture( $controller, 'ajax_get_status_counts' );

		$this->assertTrue( $json['success'] );
		$this->assertSame(
			0,
			$json['data']['client_generation'],
			'missing client_generation must default to 0 so JS exact-equality still works'
		);
	}

	public function test_phase1_ajax_get_status_counts_clamps_negative_generation(): void {
		$_POST['nonce']             = wp_create_nonce( 'sscribe_export_nonce' );
		$_POST['post_type']         = 'page';
		$_POST['client_generation'] = '-99';

		$collector = $this->createMock( \SScribe_Page_Collector::class );
		$collector->method( 'normalize_language_code' )->willReturn( '' );
		$collector->method( 'get_post_status_counts' )->willReturn(
			array( 'publish' => 1 )
		);

		$controller = $this->build_controller( collector: $collector );

		$json = $this->invoke_and_capture( $controller, 'ajax_get_status_counts' );

		$this->assertTrue( $json['success'] );
		$this->assertSame( 0, $json['data']['client_generation'], 'negative values must clamp to 0' );
	}

	public function test_phase1_ajax_get_status_counts_clamps_absurd_generation(): void {
		$_POST['nonce']             = wp_create_nonce( 'sscribe_export_nonce' );
		$_POST['post_type']         = 'page';
		$_POST['client_generation'] = '99999999999999';

		$collector = $this->createMock( \SScribe_Page_Collector::class );
		$collector->method( 'normalize_language_code' )->willReturn( '' );
		$collector->method( 'get_post_status_counts' )->willReturn(
			array( 'publish' => 1 )
		);

		$controller = $this->build_controller( collector: $collector );

		$json = $this->invoke_and_capture( $controller, 'ajax_get_status_counts' );

		$this->assertTrue( $json['success'] );
		$this->assertSame(
			0,
			$json['data']['client_generation'],
			'absurdly large values must clamp to 0 to keep the JS check robust'
		);
	}

	public function test_phase1_ajax_get_all_status_counts_echoes_client_generation(): void {
		$_POST['nonce']             = wp_create_nonce( 'sscribe_export_nonce' );
		$_POST['post_type']         = 'post';
		$_POST['client_generation'] = '42';
		$_POST['languages']         = array( 'en', 'ar' );

		$collector = $this->createMock( \SScribe_Page_Collector::class );
		$collector->method( 'normalize_language_code' )->willReturnCallback(
			static function ( string $code ): string {
				return $code;
			}
		);
		$collector->method( 'get_post_status_counts' )->willReturn(
			array( 'publish' => 1 )
		);

		$controller = $this->build_controller( collector: $collector );

		$json = $this->invoke_and_capture( $controller, 'ajax_get_all_status_counts' );

		$this->assertTrue( $json['success'] );
		$this->assertSame( 42, $json['data']['client_generation'] );
		$this->assertSame( 'post', $json['data']['post_type'] );
		$this->assertArrayNotHasKey(
			'request_seq',
			$json['data'],
			'Phase 1 removed the per-process PHP request_seq mechanism'
		);
	}

	public function test_phase1_next_request_seq_method_is_removed(): void {
		$this->assertFalse(
			method_exists( \SScribe_Export_Query_Controller::class, 'next_request_seq' ),
			'next_request_seq() must be removed; the server must not generate its own sequence'
		);
	}

	/* =========================================================================
	 * Phase 2 — `__all__` as a real end-to-end sentinel
	 *
	 * The All Languages radio, the single counts endpoint, and the batch
	 * counts endpoint must all agree on the same transport value
	 * (`__all__`). The batch response must include `languages.__all__`
	 * with aggregate counts so the JS exact-key lookup finds it. Feeding
	 * `__all__` through the active-language validator would silently
	 * reject it and leave the All Languages card stale forever.
	 * =======================================================================*/

	public function test_phase2_single_endpoint_accepts_sentinel_all(): void {
		$_POST['nonce']     = wp_create_nonce( 'sscribe_export_nonce' );
		$_POST['post_type'] = 'page';
		$_POST['language']  = '__all__';

		$collector = $this->createMock( \SScribe_Page_Collector::class );
		$collector->method( 'normalize_language_code' )->willReturn( '' );
		$collector->method( 'get_post_status_counts' )->willReturn(
			array( 'publish' => 9 )
		);

		$controller = $this->build_controller( collector: $collector );

		$json = $this->invoke_and_capture( $controller, 'ajax_get_status_counts' );

		$this->assertTrue( $json['success'] );
		$this->assertSame(
			'__all__',
			$json['data']['language'],
			'server must echo the __all__ sentinel; the validator would have rejected it otherwise'
		);
		$this->assertSame( 9, $json['data']['counts']['publish'] );
	}

	public function test_phase2_batch_endpoint_includes_sentinel_all_key(): void {
		$_POST['nonce']     = wp_create_nonce( 'sscribe_export_nonce' );
		$_POST['post_type'] = 'page';
		$_POST['languages'] = array( '__all__', 'en' );

		$collector = $this->createMock( \SScribe_Page_Collector::class );
		$collector->method( 'normalize_language_code' )->willReturnCallback(
			static function ( string $code ): string {
				return 'en' === $code ? 'en' : '';
			}
		);
		$collector->method( 'get_post_status_counts' )->willReturn(
			array( 'publish' => 11 )
		);

		$controller = $this->build_controller( collector: $collector );

		$json = $this->invoke_and_capture( $controller, 'ajax_get_all_status_counts' );

		$this->assertTrue( $json['success'] );
		$this->assertArrayHasKey(
			'__all__',
			$json['data']['languages'],
			'batch response must include a __all__ key so the JS exact lookup succeeds'
		);
		$this->assertArrayHasKey( 'en', $json['data']['languages'] );
		$this->assertSame( 2, $json['data']['queried_count'] );
		$this->assertSame( 11, $json['data']['languages']['__all__']['counts']['publish'] );
		$this->assertSame( 11, $json['data']['languages']['__all__']['counts_page']['publish'] );
		$this->assertSame( 11, $json['data']['languages']['__all__']['counts_post']['publish'] );
		// counts_any is the page+post aggregate. Mock returns 11 for each
		// post_type so the aggregate is 11 + 11 = 22.
		$this->assertSame( 22, $json['data']['languages']['__all__']['counts_any']['publish'] );
	}

	public function test_phase2_batch_endpoint_dedupes_sentinel_all(): void {
		$_POST['nonce']     = wp_create_nonce( 'sscribe_export_nonce' );
		$_POST['post_type'] = 'page';
		$_POST['languages'] = array( '__all__', '__all__' );

		$collector = $this->createMock( \SScribe_Page_Collector::class );
		$collector->method( 'normalize_language_code' )->willReturn( '' );
		$collector->method( 'get_post_status_counts' )->willReturn(
			array( 'publish' => 1 )
		);

		$controller = $this->build_controller( collector: $collector );

		$json = $this->invoke_and_capture( $controller, 'ajax_get_all_status_counts' );

		$this->assertTrue( $json['success'] );
		$this->assertSame( 1, $json['data']['queried_count'], 'duplicate __all__ must dedupe' );
		$this->assertCount( 1, $json['data']['languages'] );
	}

	public function test_phase2_batch_endpoint_drops_unknown_languages_but_keeps_sentinel(): void {
		$_POST['nonce']     = wp_create_nonce( 'sscribe_export_nonce' );
		$_POST['post_type'] = 'page';
		$_POST['languages'] = array( '__all__', 'xx-not-a-language' );

		$collector = $this->createMock( \SScribe_Page_Collector::class );
		$collector->method( 'normalize_language_code' )->willReturn( '' );
		$collector->method( 'get_post_status_counts' )->willReturn(
			array( 'publish' => 1 )
		);

		$controller = $this->build_controller( collector: $collector );

		$json = $this->invoke_and_capture( $controller, 'ajax_get_all_status_counts' );

		$this->assertTrue( $json['success'] );
		$this->assertArrayHasKey( '__all__', $json['data']['languages'] );
		$this->assertArrayNotHasKey( 'xx-not-a-language', $json['data']['languages'] );
		$this->assertSame( 1, $json['data']['queried_count'] );
	}
}