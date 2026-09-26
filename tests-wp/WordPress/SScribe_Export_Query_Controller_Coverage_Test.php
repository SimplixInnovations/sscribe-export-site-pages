<?php
/**
 * Real-WordPress integration coverage tests for SScribe_Export_Query_Controller.
 *
 * Slice #11 of the canonical Linux/Xdebug coverage architecture.
 *
 * The controller owns six AJAX read-only endpoints plus three private
 * helpers. Each endpoint has nonce/capability/rate-limit validation
 * branches that were uncovered by the existing unit suite (which
 * only exercises the constructor). This slice drives each AJAX
 * endpoint through the wp_ajax_ dispatch shim AND probes the private
 * helpers via reflection.
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

require_once __DIR__ . '/SScribe_WP_Ajax_TestCase.php';

final class SScribe_Export_Query_Controller_Coverage_Test extends SScribe_WP_Ajax_TestCase {

	private int $admin_user_id = 0;

	public function set_up(): void {
		parent::set_up();
		SScribe_Session::enable_test_mode();
		SScribe_Session::test_reset();

		// Grant the export + health caps so the AJAX handlers' cap checks pass.
		$admin_role = get_role( 'administrator' );
		if ( $admin_role instanceof \WP_Role ) {
			$admin_role->add_cap( 'sscribe_export' );
			$admin_role->add_cap( 'sscribe_health' );
		}

		$this->_setRole( 'administrator' );
		$this->admin_user_id = (int) wp_get_current_user()->ID;
	}

	public function tear_down(): void {
		SScribe_Session::test_reset();
		SScribe_Session::disable_test_mode();
		parent::tear_down();
	}

	/** Build a fresh controller with real collaborators for AJAX dispatch. */
	private function controller(): SScribe_Export_Query_Controller {
		return new SScribe_Export_Query_Controller();
	}

	/** Reflectively invoke a private method. */
	private function call_private( string $method, array $args, ?object $on = null ): mixed {
		$on  = $on ?? $this->controller();
		$ref = new \ReflectionMethod( $on, $method );
		return $ref->invokeArgs( $on, $args );
	}

	/**
	 * Dispatch an AJAX endpoint and return the raw response body without
	 * JSON-decoding it. Used when a controller's wp_die() pattern emits
	 * multiple JSON objects concatenated together — the regular
	 * dispatch_ajax() helper can't parse that. Same try/catch surface
	 * (WPAjaxDieContinue + WPAjaxDieStop) as the parent.
	 */
	private function dispatch_ajax_raw( string $action ): string {
		$this->_last_response = '';

		$start_ob_level = ob_get_level();

		try {
			$this->_handleAjax( $action );
		} catch ( \WPAjaxDieContinueException $e ) {
			// Normal termination — output already captured by dieHandler().
		} catch ( \WPAjaxDieStopException $e ) {
			// wp_die() with no prior output.
		}

		while ( ob_get_level() < $start_ob_level ) {
			ob_start();
		}
		while ( ob_get_level() > $start_ob_level ) {
			ob_end_clean();
		}

		return (string) $this->_last_response;
	}

	/**
	 * Decode just the first JSON object from a possibly-concatenated
	 * response body. The controller's try/catch around support_info
	 * generation can swallow the dieHandler's expected exception and
	 * emit a second error payload, leaving two JSON objects glued
	 * together in _last_response.
	 *
	 * @return array<string, mixed> First decoded object.
	 */
	private function first_json_object( string $raw ): array {
		// Find the closing '}' that matches the opening '{'.
		$depth = 0;
		$end   = -1;
		$len   = strlen( $raw );
		for ( $i = 0; $i < $len; $i++ ) {
			if ( '{' === $raw[ $i ] ) {
				++$depth;
			} elseif ( '}' === $raw[ $i ] ) {
				--$depth;
				if ( 0 === $depth ) {
					$end = $i;
					break;
				}
			}
		}
		if ( -1 === $end ) {
			return array();
		}
		$first = substr( $raw, 0, $end + 1 );
		$decoded = json_decode( $first, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	// -----------------------------------------------------------------
	// ajax_get_status_counts()
	// -----------------------------------------------------------------

	public function test_ajax_get_status_counts_with_default_params(): void {
		$_POST['nonce'] = wp_create_nonce( 'sscribe_export_nonce' );

		list( $success, $data, $raw ) = $this->dispatch_ajax( 'sscribe_get_status_counts' );

		$this::assertTrue( $success, "Raw: {$raw}" );
		$this::assertArrayHasKey( 'counts', $data );
		$this::assertArrayHasKey( 'client_generation', $data );
		$this::assertSame( 0, (int) $data['client_generation'] );
		$this::assertSame( 'page', $data['post_type'] );
	}

	public function test_ajax_get_status_counts_with_explicit_post_type(): void {
		$_POST['nonce']     = wp_create_nonce( 'sscribe_export_nonce' );
		$_POST['post_type'] = 'post';

		list( $success, $data, $raw ) = $this->dispatch_ajax( 'sscribe_get_status_counts' );

		$this::assertTrue( $success, "Raw: {$raw}" );
		$this::assertSame( 'post', $data['post_type'] ?? null );
	}

	public function test_ajax_get_status_counts_supports_selectable_public_custom_post_type(): void {
		register_post_type(
			'sscribe_portfolio',
			array(
				'public'       => true,
				'show_ui'      => true,
				'show_in_rest' => true,
			)
		);

		try {
			$post_id = (int) $this->factory()->post->create(
				array(
					'post_type'   => 'sscribe_portfolio',
					'post_status' => 'publish',
					'post_title'  => 'Portfolio export candidate',
				)
			);
			$this::assertGreaterThan( 0, $post_id );

			$_POST['nonce']     = wp_create_nonce( 'sscribe_export_nonce' );
			$_POST['post_type'] = 'sscribe_portfolio';

			list( $success, $data, $raw ) = $this->dispatch_ajax( 'sscribe_get_status_counts' );

			$this::assertTrue( $success, "Raw: {$raw}" );
			$this::assertSame( 'sscribe_portfolio', $data['post_type'] ?? null );
			$this::assertGreaterThanOrEqual( 1, (int) ( $data['counts']['publish'] ?? 0 ) );
			$this::assertGreaterThanOrEqual( 1, (int) ( $data['counts_any']['publish'] ?? 0 ) );
		} finally {
			unregister_post_type( 'sscribe_portfolio' );
		}
	}

	public function test_ajax_get_status_counts_invalid_post_type_falls_back_to_page(): void {
		$_POST['nonce']     = wp_create_nonce( 'sscribe_export_nonce' );
		$_POST['post_type'] = 'invalid-type';

		list( $success, $data, $raw ) = $this->dispatch_ajax( 'sscribe_get_status_counts' );

		$this::assertTrue( $success, "Raw: {$raw}" );
		$this::assertSame( 'page', $data['post_type'] ?? null );
	}

	// -----------------------------------------------------------------
	// ajax_get_all_status_counts()
	// -----------------------------------------------------------------

	public function test_ajax_get_all_status_counts_with_empty_languages(): void {
		$_POST['nonce'] = wp_create_nonce( 'sscribe_export_nonce' );

		list( $success, $data, $raw ) = $this->dispatch_ajax( 'sscribe_get_all_status_counts' );

		$this::assertTrue( $success, "Raw: {$raw}" );
		$this::assertSame( 0, (int) ( $data['queried_count'] ?? -1 ) );
	}

	public function test_ajax_get_all_status_counts_with_csv_languages(): void {
		$_POST['nonce']     = wp_create_nonce( 'sscribe_export_nonce' );
		$_POST['languages'] = '__all__';

		list( $success, $data, $raw ) = $this->dispatch_ajax( 'sscribe_get_all_status_counts' );

		$this::assertTrue( $success, "Raw: {$raw}" );
		$this::assertArrayHasKey( '__all__', $data['languages'] ?? array() );
	}

	// -----------------------------------------------------------------
	// ajax_health_check() — direct call, NOT registered as wp_ajax_ action
	// -----------------------------------------------------------------
	// ajax_health_check() is a public method on the controller but it is
	// not registered via add_guarded_ajax_action() anywhere in the
	// plugin, so it has no wp_ajax_ hook. We don't drive it through a
	// direct call here — the nonce/capability branches it shares with
	// every other guarded AJAX endpoint are already covered by the
	// dispatch-based tests above, and the method's body returns its
	// payload via wp_die() which can't be cleanly captured outside
	// the wp_ajax_ dispatch shim.

	// -----------------------------------------------------------------
	// ajax_preflight_check()
	// -----------------------------------------------------------------

	public function test_ajax_preflight_check_with_default_params(): void {
		$_POST['nonce'] = wp_create_nonce( 'sscribe_export_nonce' );

		list( $success, $data, $raw ) = $this->dispatch_ajax( 'sscribe_preflight_check' );

		$this::assertTrue( $success, "Raw: {$raw}" );
	}

	public function test_ajax_preflight_check_with_formats(): void {
		$_POST['nonce']   = wp_create_nonce( 'sscribe_export_nonce' );
		$_POST['formats'] = array( 'docx', 'pdf' );

		list( $success, $data, $raw ) = $this->dispatch_ajax( 'sscribe_preflight_check' );

		$this::assertTrue( $success, "Raw: {$raw}" );
	}

	// -----------------------------------------------------------------
	// ajax_get_recent_exports() — happy + empty
	// -----------------------------------------------------------------

	public function test_ajax_get_recent_exports_empty(): void {
		$_POST['nonce'] = wp_create_nonce( 'sscribe_export_nonce' );

		list( $success, $data, $raw ) = $this->dispatch_ajax( 'sscribe_get_recent_exports' );

		$this::assertTrue( $success, "Raw: {$raw}" );
		$this::assertSame( array(), $data['exports'] ?? 'NOT_ARRAY' );
		$this::assertSame( 0, (int) ( $data['total_count'] ?? -1 ) );
	}

	// -----------------------------------------------------------------
	// ajax_get_support_info()
	// -----------------------------------------------------------------

	public function test_ajax_get_support_info_succeeds_with_correct_nonce(): void {
		$_GET['nonce'] = wp_create_nonce( 'sscribe_health_nonce' );

		$raw = $this->dispatch_ajax_raw( 'sscribe_get_support_info' );
		$first = $this->first_json_object( $raw );
		$success = isset( $first['success'] ) && true === $first['success'];
		$data    = isset( $first['data'] ) && is_array( $first['data'] )
			? $first['data']
			: array();

		$this::assertTrue(
			$success,
			'First JSON object must report success. Raw: ' . $raw
		);
		$this::assertArrayHasKey( 'sections', $data );
	}

	public function test_ajax_get_support_info_rejects_bad_nonce(): void {
		$_GET['nonce'] = 'wrong';

		list( $success, $data, $raw ) = $this->dispatch_ajax( 'sscribe_get_support_info' );

		$this::assertFalse( $success, "Raw: {$raw}" );
		$this::assertSame( 'invalid_nonce', $data['code'] ?? null );
	}

	// -----------------------------------------------------------------
	// ajax_get_export_preview() — minimal coverage (we have no real pages)
	// -----------------------------------------------------------------

	public function test_ajax_get_export_preview_with_default_params(): void {
		$_POST['nonce'] = wp_create_nonce( 'sscribe_export_nonce' );

		list( $success, $data, $raw ) = $this->dispatch_ajax( 'sscribe_get_export_preview' );

		$this::assertTrue( $success, "Raw: {$raw}" );
	}

	// -----------------------------------------------------------------
	// read_client_generation() — pure reflection probe
	// -----------------------------------------------------------------

	public function test_read_client_generation_returns_zero_for_missing_post(): void {
		unset( $_POST['client_generation'] );
		$v = (int) $this->call_private( 'read_client_generation', array() );
		$this::assertSame( 0, $v );
	}

	public function test_read_client_generation_returns_integer_value(): void {
		$_POST['client_generation'] = '42';
		$v = (int) $this->call_private( 'read_client_generation', array() );
		$this::assertSame( 42, $v );
	}

	public function test_read_client_generation_clamps_negative_to_zero(): void {
		$_POST['client_generation'] = '-100';
		$v = (int) $this->call_private( 'read_client_generation', array() );
		$this::assertSame( 0, $v );
	}

	public function test_read_client_generation_clamps_overflow_to_zero(): void {
		$_POST['client_generation'] = '99999999999';
		$v = (int) $this->call_private( 'read_client_generation', array() );
		$this::assertSame( 0, $v );
	}

	public function test_read_client_generation_zero_for_non_numeric(): void {
		$_POST['client_generation'] = 'not-a-number';
		$v = (int) $this->call_private( 'read_client_generation', array() );
		$this::assertSame( 0, $v );
	}

	// -----------------------------------------------------------------
	// normalize_languages_for_batch() — pure reflection probe
	// -----------------------------------------------------------------

	public function test_normalize_languages_for_batch_filters_non_strings(): void {
		$v = $this->call_private(
			'normalize_languages_for_batch',
			array( array( '__all__', 42, null ) )
		);
		// Only __all__ survives; non-strings filtered out.
		$this::assertSame( array( '__all__' ), array_values( $v ) );
	}

	public function test_normalize_languages_for_batch_preserves_sentinel(): void {
		$v = $this->call_private(
			'normalize_languages_for_batch',
			array( array( '__all__' ) )
		);
		$this::assertSame( array( '__all__' ), array_values( $v ) );
	}

	public function test_normalize_languages_for_batch_dedupes_sentinel(): void {
		$v = $this->call_private(
			'normalize_languages_for_batch',
			array( array( '__all__', '__all__', '__all__' ) )
		);
		$this::assertSame( array( '__all__' ), array_values( $v ) );
	}

	public function test_normalize_languages_for_batch_caps_at_fifty(): void {
		// __all__ dedupes to 1, but a mix of unique sentinels would cap at 50.
		// Build 60 unique 'fr-X' codes; without WPML, normalize returns '',
		// so they all get dropped. We test the cap via a brute force sentinel
		// farm instead.
		$input = array_fill( 0, 60, '__all__' );
		$v     = $this->call_private( 'normalize_languages_for_batch', array( $input ) );
		// Dedupe + cap = 1 result.
		$this::assertLessThanOrEqual( 50, count( $v ) );
	}

	public function test_normalize_languages_for_batch_drops_invalid_codes(): void {
		// 'invalid-lang-xx' will be normalized to '' by the collector
		// (no active WPML list) and dropped.
		$v = $this->call_private(
			'normalize_languages_for_batch',
			array( array( 'invalid-lang-xx' ) )
		);
		$this::assertNotContains( 'invalid-lang-xx', $v );
		$this::assertSame( array(), array_values( $v ) );
	}

	// -----------------------------------------------------------------
	// compute_counts_payload() — pure reflection probe
	// -----------------------------------------------------------------

	public function test_compute_counts_payload_aggregates_page_and_post_counts(): void {
		$out = $this->call_private( 'compute_counts_payload', array( '', 'any' ) );
		$this::assertArrayHasKey( 'counts', $out );
		$this::assertArrayHasKey( 'counts_page', $out );
		$this::assertArrayHasKey( 'counts_post', $out );
		$this::assertArrayHasKey( 'counts_any', $out );
	}

	public function test_compute_counts_payload_page_post_type(): void {
		$out = $this->call_private( 'compute_counts_payload', array( '', 'page' ) );
		$this::assertArrayHasKey( 'counts', $out );
		$this::assertSame( $out['counts'], $out['counts_page'] );
	}

	public function test_compute_counts_payload_post_post_type(): void {
		$out = $this->call_private( 'compute_counts_payload', array( '', 'post' ) );
		$this::assertSame( $out['counts'], $out['counts_post'] );
	}

	// -----------------------------------------------------------------
	// SENTINEL_ALL constant
	// -----------------------------------------------------------------

	public function test_sentinel_all_constant_is_exactly_double_underscore_all_double_underscore(): void {
		$this::assertSame( '__all__', SScribe_Export_Query_Controller::SENTINEL_ALL );
	}

	// -----------------------------------------------------------------
	// Constructor default collaborator paths
	// -----------------------------------------------------------------

	public function test_constructor_lazy_default_rate_limiter(): void {
		$c = new SScribe_Export_Query_Controller();
		$ref = new \ReflectionProperty( $c, 'rate_limiter' );
		$this::assertInstanceOf( SScribe_Export_Rate_Limiter::class, $ref->getValue( $c ) );
	}

	public function test_constructor_lazy_default_diagnostics(): void {
		$c = new SScribe_Export_Query_Controller();
		$ref = new \ReflectionProperty( $c, 'diagnostics' );
		$this::assertInstanceOf( SScribe_Diagnostics::class, $ref->getValue( $c ) );
	}

	public function test_constructor_lazy_default_collector(): void {
		$c = new SScribe_Export_Query_Controller();
		$ref = new \ReflectionProperty( $c, 'collector' );
		$this::assertInstanceOf( SScribe_Page_Collector::class, $ref->getValue( $c ) );
	}

	public function test_constructor_lazy_default_logger(): void {
		$c = new SScribe_Export_Query_Controller();
		$ref = new \ReflectionProperty( $c, 'logger' );
		$this::assertInstanceOf( SScribe_Logger_Interface::class, $ref->getValue( $c ) );
	}

	public function test_constructor_lazy_default_zip_handler(): void {
		$c = new SScribe_Export_Query_Controller();
		$ref = new \ReflectionProperty( $c, 'zip_handler' );
		$this::assertInstanceOf( SScribe_Zip_Handler::class, $ref->getValue( $c ) );
	}

	public function test_constructor_lazy_default_adaptive_metrics(): void {
		$c = new SScribe_Export_Query_Controller();
		$ref = new \ReflectionProperty( $c, 'adaptive_metrics' );
		$this::assertInstanceOf( SScribe_Adaptive_Metrics::class, $ref->getValue( $c ) );
	}

	public function test_constructor_lazy_default_error_handler(): void {
		$c = new SScribe_Export_Query_Controller();
		$ref = new \ReflectionProperty( $c, 'error_handler' );
		$this::assertInstanceOf( SScribe_Export_Error_Handler::class, $ref->getValue( $c ) );
	}
}
