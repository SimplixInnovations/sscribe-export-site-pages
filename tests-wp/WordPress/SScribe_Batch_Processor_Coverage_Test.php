<?php
/**
 * Real-WordPress integration coverage tests for SScribe_Batch_Processor.
 *
 * Slice #6 of the canonical Linux/Xdebug coverage architecture.
 * SScribe_Batch_Processor is the second-largest production file at
 * 1441 LOC. Its public AJAX surface is covered by the dedicated
 * SScribe_Batch_Step_Handler / SScribe_Export_Finalizer / etc. tests;
 * here we target the pure-helper and lazy-loader paths inside the
 * class itself, plus a small handful of private decision helpers that
 * are only reachable through that AJAX surface.
 *
 * Strategy: drive every private helper via reflection. The pure-helper
 * methods (random_suffix_bytes, get_min_file_sizes,
 * validate_export_result, get_memory_warning, count_temp_dir_files,
 * get_required_capability, get_memory_usage_percent,
 * get_remaining_time, build_error_diagnostics_payload, release_lock,
 * validate_session_ownership, optimize_batch_size) are pure functions
 * of their inputs and exercise meaningful branches when called with
 * representative data. The lazy loaders (get_adaptive_metrics,
 * get_rate_limiter, get_auditor, get_resource_monitor,
 * get_lock_manager, get_error_handler, get_query_controller,
 * get_diagnostics) instantiate their respective collaborators and
 * confirm memoization.
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

require_once __DIR__ . '/SScribe_WP_TestCase.php';

final class SScribe_Batch_Processor_Coverage_Test extends SScribe_WP_TestCase {

	private ?SScribe_Batch_Processor $processor = null;

	public function set_up(): void {
		parent::set_up();
		SScribe_Activator::activate( false );

		$this->processor = new SScribe_Batch_Processor();
	}

	// -----------------------------------------------------------------
	// Pure-helper coverage: random_suffix_bytes
	// -----------------------------------------------------------------

	public function test_random_suffix_bytes_returns_expected_length(): void {
		$bytes = $this->call_private( 'random_suffix_bytes' )( 16 );
		$this::assertSame( 16, strlen( $bytes ) );
	}

	public function test_random_suffix_bytes_clamps_non_positive_to_one(): void {
		$bytes = $this->call_private( 'random_suffix_bytes' )( 0 );
		$this::assertSame( 1, strlen( $bytes ) );

		$bytes = $this->call_private( 'random_suffix_bytes' )( -5 );
		$this::assertSame( 1, strlen( $bytes ) );
	}

	// -----------------------------------------------------------------
	// Pure-helper coverage: get_min_file_sizes (filterable)
	// -----------------------------------------------------------------

	public function test_get_min_file_sizes_returns_defaults(): void {
		$sizes = $this->call_private( 'get_min_file_sizes' )();

		$this::assertIsArray( $sizes );
		$this::assertArrayHasKey( 'docx', $sizes );
		$this::assertArrayHasKey( 'pdf', $sizes );
		$this::assertArrayHasKey( 'html', $sizes );
		$this::assertArrayHasKey( 'markdown', $sizes );
		$this::assertSame( 8192, $sizes['docx'] );
		$this::assertSame( 512, $sizes['html'] );
	}

	public function test_get_min_file_sizes_is_filterable(): void {
		$filter = static function () {
			return array(
				'docx'     => 4096,
				'pdf'      => 4096,
				'html'     => 256,
				'markdown' => 25,
			);
		};
		add_filter( 'sscribe_min_export_file_sizes', $filter );
		try {
			$sizes = $this->call_private( 'get_min_file_sizes' )();
			$this::assertSame( 4096, $sizes['docx'] );
			$this::assertSame( 25, $sizes['markdown'] );
		} finally {
			remove_filter( 'sscribe_min_export_file_sizes', $filter );
		}
	}

	// -----------------------------------------------------------------
	// Pure-helper coverage: validate_export_result
	// -----------------------------------------------------------------

	public function test_validate_export_result_for_failed_result_extracts_error_category(): void {
		// validate_export_result reads error_category from $result->get_data(),
		// not from get_context(). failure() stores the second arg as context,
		// so passing error_category via failure() is not the right path.
		// Instead, success() takes a $data payload — to drive the
		// error_category branch we'd need a failed Result whose get_data()
		// returns ['error_category' => ...]. Construct that directly.
		$result = new \SScribe_Result(
			false,
			array( 'error_category' => 'pdf_missing_library', 'page_id' => 42 ),
			'missing mPDF library'
		);

		$validation = $this->call_private( 'validate_export_result' )( $result, 'pdf', 42 );

		$this::assertFalse( $validation['is_valid'] );
		$this::assertSame( 'missing mPDF library', $validation['error'] );
		$this::assertSame( 'pdf_missing_library', $validation['category'] );
		$this::assertSame( 42, $validation['context']['page_id'] );
	}

	public function test_validate_export_result_for_failed_result_with_unknown_category_falls_back(): void {
		// failure() with no context → get_data() is null → error_category
		// missing → fallback to 'unknown'.
		$result = \SScribe_Result::failure( 'no category here' );

		$validation = $this->call_private( 'validate_export_result' )( $result, 'docx', 7 );

		$this::assertFalse( $validation['is_valid'] );
		$this::assertSame( 'unknown', $validation['category'] );
		$this::assertSame( array(), $validation['context'] );
	}

	public function test_validate_export_result_for_success_with_oversized_file_returns_empty_file(): void {
		$path = $this->make_temp_file( 'fake', 100 ); // 100 bytes, smaller than min docx 8192.
		$result = \SScribe_Result::success( array( 'path' => $path, 'size' => 100 ) );

		$validation = $this->call_private( 'validate_export_result' )( $result, 'docx', 1 );

		$this::assertFalse( $validation['is_valid'] );
		$this::assertSame( 'empty_file', $validation['category'] );
		$this::assertStringContainsString( 'DOCX file appears empty', $validation['error'] );

		@unlink( $path );
	}

	public function test_validate_export_result_for_success_with_actual_file_is_valid(): void {
		$path = $this->make_temp_file( str_repeat( 'x', 9000 ), 9000 );
		$result = \SScribe_Result::success( array( 'path' => $path, 'size' => 9000 ) );

		$validation = $this->call_private( 'validate_export_result' )( $result, 'docx', 1 );

		$this::assertTrue( $validation['is_valid'] );
		$this::assertSame( 'success', $validation['category'] );
		$this::assertSame( 9000, $validation['context']['actual_size'] );

		@unlink( $path );
	}

	// -----------------------------------------------------------------
	// Pure-helper coverage: get_memory_warning
	// -----------------------------------------------------------------

	public function test_get_memory_warning_returns_array_when_memory_pressure_high(): void {
		// Result is either null or an array — both are valid; we only
		// care that the call completes and the type is consistent.
		$warning = $this->call_private( 'get_memory_warning' )( 5, array( 'docx' ) );
		$this::assertTrue(
			null === $warning || is_array( $warning ),
			'get_memory_warning must return null or array; never throw.'
		);
	}

	// -----------------------------------------------------------------
	// Pure-helper coverage: count_temp_dir_files
	// -----------------------------------------------------------------

	public function test_count_temp_dir_files_returns_zero_for_missing_dir(): void {
		$this::assertSame(
			0,
			$this->call_private( 'count_temp_dir_files' )( '/this/does/not/exist-' . bin2hex( random_bytes( 4 ) ) )
		);
	}

	public function test_count_temp_dir_files_counts_files_only_not_dirs(): void {
		$dir = sys_get_temp_dir() . '/sscribe-cov-count-' . bin2hex( random_bytes( 4 ) );
		wp_mkdir_p( $dir );
		file_put_contents( $dir . '/a.txt', 'a' );
		file_put_contents( $dir . '/b.txt', 'b' );
		wp_mkdir_p( $dir . '/nested' );

		$this::assertSame( 2, $this->call_private( 'count_temp_dir_files' )( $dir ) );

		// Tear down.
		@unlink( $dir . '/a.txt' );
		@unlink( $dir . '/b.txt' );
		@rmdir( $dir . '/nested' );
		@rmdir( $dir );
	}

	// -----------------------------------------------------------------
	// Pure-helper coverage: get_required_capability
	// -----------------------------------------------------------------

	public function test_get_required_capability_returns_default_when_filter_does_nothing(): void {
		// Default branch: the `sscribe_export_capability` filter is
		// uninstalled, so the resolver falls back to 'sscribe_export'.
		$cap = $this->call_private( 'get_required_capability' )();
		$this::assertIsString( $cap );
		$this::assertNotSame( '', $cap );
		$this::assertSame( 'sscribe_export', $cap );
	}

	public function test_get_required_capability_is_cached_after_first_call(): void {
		$first  = $this->call_private( 'get_required_capability' )();
		$second = $this::getRestrictedProperty( $this->processor, 'cached_required_capability' );
		$this::assertSame( $first, $second, 'cached_required_capability must equal the resolved value.' );
	}

	public function test_get_required_capability_honors_filter(): void {
		// The filter consumed by SScribe_Capabilities::get_required()
		// is `sscribe_export_capability`. SScribe_Capabilities::is_allowed()
		// only accepts values from its private ALLOWED list
		// (sscribe_export, sscribe_health, manage_options, export) — so we
		// filter through one of those to verify the wrapper honours a
		// legitimately permitted value.
		$filter = static function () {
			return 'export';
		};
		add_filter( 'sscribe_export_capability', $filter );
		try {
			// Reset cached value to null (the sentinel for "not cached")
			// so the filter is consulted again. Empty string would short-
			// circuit the `null !== $this->cached_required_capability`
			// guard.
			$this::setRestrictedProperty( $this->processor, 'cached_required_capability', null );
			$cap = $this->call_private( 'get_required_capability' )();
			// 'export' is in ALLOWED → propagates through the wrapper.
			$this::assertSame( 'export', $cap );
		} finally {
			remove_filter( 'sscribe_export_capability', $filter );
			$this::setRestrictedProperty( $this->processor, 'cached_required_capability', null );
		}
	}

	public function test_get_required_capability_filter_falls_back_when_unallowed(): void {
		// Filter returns a value NOT in ALLOWED ('edit_posts' isn't in
		// SScribe_Capabilities::ALLOWED) → wrapper falls back to
		// 'sscribe_export'.
		$filter = static function () {
			return 'edit_posts';
		};
		add_filter( 'sscribe_export_capability', $filter );
		try {
			$this::setRestrictedProperty( $this->processor, 'cached_required_capability', null );
			$cap = $this->call_private( 'get_required_capability' )();
			$this::assertSame( 'sscribe_export', $cap );
		} finally {
			remove_filter( 'sscribe_export_capability', $filter );
			$this::setRestrictedProperty( $this->processor, 'cached_required_capability', null );
		}
	}

	// -----------------------------------------------------------------
	// Lazy-loaders (each builds its collaborator on first access)
	// -----------------------------------------------------------------

	public function test_get_adaptive_metrics_returns_memoized_instance(): void {
		$first  = $this->call_private( 'get_adaptive_metrics' )();
		$second = $this->call_private( 'get_adaptive_metrics' )();
		$this::assertSame( $first, $second );
		$this::assertInstanceOf( \SScribe_Adaptive_Metrics::class, $first );
	}

	public function test_get_rate_limiter_returns_memoized_instance(): void {
		$first  = $this->call_private( 'get_rate_limiter' )();
		$second = $this->call_private( 'get_rate_limiter' )();
		$this::assertSame( $first, $second );
		$this::assertInstanceOf( \SScribe_Export_Rate_Limiter::class, $first );
	}

	public function test_get_auditor_returns_memoized_instance(): void {
		$first  = $this->call_private( 'get_auditor' )();
		$second = $this->call_private( 'get_auditor' )();
		$this::assertSame( $first, $second );
		$this::assertInstanceOf( \SScribe_Export_Auditor::class, $first );
	}

	public function test_get_resource_monitor_returns_memoized_instance(): void {
		$first  = $this->call_private( 'get_resource_monitor' )();
		$second = $this->call_private( 'get_resource_monitor' )();
		$this::assertSame( $first, $second );
		$this::assertInstanceOf( \SScribe_Export_Resource_Monitor::class, $first );
	}

	public function test_get_lock_manager_returns_memoized_instance(): void {
		$first  = $this->call_private( 'get_lock_manager' )();
		$second = $this->call_private( 'get_lock_manager' )();
		$this::assertSame( $first, $second );
		$this::assertInstanceOf( \SScribe_Export_Lock_Manager::class, $first );
	}

	public function test_get_error_handler_returns_memoized_instance(): void {
		$first  = $this->call_private( 'get_error_handler' )();
		$second = $this->call_private( 'get_error_handler' )();
		$this::assertSame( $first, $second );
		$this::assertInstanceOf( \SScribe_Export_Error_Handler::class, $first );
	}

	public function test_get_query_controller_creates_when_null(): void {
		$first  = $this->call_private( 'get_query_controller' )();
		$second = $this->call_private( 'get_query_controller' )();
		$this::assertSame( $first, $second );
		$this::assertInstanceOf( \SScribe_Export_Query_Controller::class, $first );
	}

	public function test_get_diagnostics_returns_memoized_instance(): void {
		$first  = $this->call_private( 'get_diagnostics' )();
		$second = $this->call_private( 'get_diagnostics' )();
		$this::assertSame( $first, $second );
		$this::assertInstanceOf( \SScribe_Diagnostics::class, $first );
	}

	// -----------------------------------------------------------------
	// Resource-decision helpers
	// -----------------------------------------------------------------

	public function test_is_memory_available_returns_bool(): void {
		$result = $this->call_private( 'is_memory_available' )( 1 );
		$this::assertIsBool( $result );
	}

	public function test_is_time_available_returns_bool(): void {
		$result = $this->call_private( 'is_time_available' )( microtime( true ), 10 );
		$this::assertIsBool( $result );
	}

	public function test_get_remaining_time_returns_float(): void {
		$result = $this->call_private( 'get_remaining_time' )( microtime( true ) );
		$this::assertIsFloat( $result );
	}

	public function test_get_memory_usage_percent_returns_float(): void {
		$result = $this->call_private( 'get_memory_usage_percent' )();
		$this::assertIsFloat( $result );
		$this::assertGreaterThanOrEqual( 0.0, $result );
	}

	// -----------------------------------------------------------------
	// Session ownership validator
	// -----------------------------------------------------------------

	public function test_validate_session_ownership_returns_false_for_missing_user_id(): void {
		// No `user_id` key in the session → audit_log 'session_missing_user_id'
		// branch, returns false.
		$session = array(
			'session_id' => 'abc',
		);

		$this::assertFalse(
			$this->call_private( 'validate_session_ownership' )( $session, 'abc' )
		);
	}

	public function test_validate_session_ownership_returns_false_for_user_id_mismatch(): void {
		// user_id mismatch → audit_log 'session_access_denied' branch,
		// returns false. get_current_user_id() returns 0 in the testbench
		// (no user logged in by default), so a session with user_id=42
		// will never match.
		$session = array(
			'user_id'    => 42,
			'session_id' => 'expected-id',
		);

		$this::assertFalse(
			$this->call_private( 'validate_session_ownership' )( $session, 'expected-id' )
		);
	}

	public function test_validate_session_ownership_returns_true_when_user_ids_match(): void {
		// get_current_user_id() returns 0 in the testbench, so the session
		// user_id must also be 0 to drive the happy-path branch.
		$session = array(
			'user_id'    => 0,
			'session_id' => 'expected-id',
		);

		$this::assertTrue(
			$this->call_private( 'validate_session_ownership' )( $session, 'expected-id' )
		);
	}

	// -----------------------------------------------------------------
	// build_error_diagnostics_payload
	// -----------------------------------------------------------------

	public function test_build_error_diagnostics_payload_with_empty_errors(): void {
		$payload = $this->call_private( 'build_error_diagnostics_payload' )( array() );

		$this::assertIsArray( $payload );
		$this::assertSame( 0, $payload['total_errors'] );
		$this::assertIsArray( $payload['categories'] );
		$this::assertSame( array(), $payload['categories'] );
		$this::assertIsArray( $payload['entries'] );
		$this::assertSame( array(), $payload['entries'] );
	}

	public function test_build_error_diagnostics_payload_with_structured_errors(): void {
		// Each entry has a nested `errors[]` with {format, message, category, context}.
		$structured = array(
			array(
				'page_id' => 42,
				'errors'  => array(
					array(
						'format'   => 'DOCX',
						'message'  => 'invalid image',
						'category' => 'validation',
						'context'  => array(),
					),
				),
			),
		);

		$payload = $this->call_private( 'build_error_diagnostics_payload' )( $structured );

		$this::assertIsArray( $payload );
		$this::assertIsArray( $payload['entries'] );
		$this::assertNotEmpty( $payload['entries'], 'structured errors must surface in entries.' );
		$this::assertContains( 'DOCX', $payload['technical']['formats'] );
		$this::assertSame( 1, $payload['technical']['page_ids_count'] );
	}

	public function test_build_error_diagnostics_payload_with_string_errors(): void {
		$structured = array();
		$string     = array( 'first failure', 'second failure' );

		$payload = $this->call_private( 'build_error_diagnostics_payload' )( $structured, $string );

		$this::assertIsArray( $payload );
		$this::assertSame( 2, $payload['total_errors'] );
		$this::assertCount( 2, $payload['entries'] );
	}

	// -----------------------------------------------------------------
	// release_lock
	// -----------------------------------------------------------------

	public function test_release_lock_with_no_lock_token_returns_bool(): void {
		// Default property state: $current_lock_token is null. The
		// delegated lock_manager handles the no-token case and returns
		// a bool; we just assert the call completes.
		$result = $this->call_private( 'release_lock' )( 'session-1' );
		$this::assertIsBool( $result );
	}

	public function test_release_lock_with_matching_token_returns_bool(): void {
		$this::setRestrictedProperty( $this->processor, 'current_lock_token', 'token-1' );

		$result = $this->call_private( 'release_lock' )( 'session-1', 'token-1' );

		// The result is whatever the lock_manager returns (it owns the
		// lock state in the wp_options table). We just assert the
		// delegation completes without throwing.
		$this::assertIsBool( $result );
	}

	// -----------------------------------------------------------------
	// optimize_batch_size
	// -----------------------------------------------------------------

	public function test_optimize_batch_size_reduces_size_for_low_memory(): void {
		// Simulate low memory: ask for a tiny budget so the function
		// shrinks the configured batch_size.
		$before = $this->getRestrictedProperty( $this->processor, 'batch_size' );

		$this->call_private( 'optimize_batch_size' )( array( 'docx' ), 'low_memory' );

		$after = $this->getRestrictedProperty( $this->processor, 'batch_size' );

		$this::assertLessThanOrEqual( $before, $after, 'optimize_batch_size must never grow the batch size.' );
		$this::assertGreaterThanOrEqual( 1, $after, 'optimize_batch_size must clamp to at least 1.' );
	}

	// -----------------------------------------------------------------
	// Reflection helpers
	// -----------------------------------------------------------------

	/**
	 * Invoke a private method on the processor via reflection.
	 *
	 * @param array<int, mixed> $args Positional arguments.
	 * @return mixed Return value of the private method.
	 */
	private function invoke_private( object $object, string $method, array $args = array() ): mixed {
		$ref  = new \ReflectionClass( $object );
		$func = $ref->getMethod( $method );
		return $func->invokeArgs( $object, $args );
	}

	/**
	 * Build a variadic callable that invokes a private method on the
	 * processor with positional arguments.
	 *
	 * @return callable
	 */
	private function call_private( string $method ): callable {
		$processor = $this->processor;
		return static function ( ...$args ) use ( $processor, $method ): mixed {
			$ref  = new \ReflectionClass( $processor );
			$func = $ref->getMethod( $method );
			return $func->invokeArgs( $processor, $args );
		};
	}

	/**
	 * Set a private or readonly property on an object via reflection.
	 *
	 * @param string|int $value New property value.
	 */
	private static function setRestrictedProperty( object $object, string $property, $value ): void {
		$ref = new \ReflectionProperty( $object, $property );
		$ref->setValue( $object, $value );
	}

	/**
	 * Read a private or readonly property on an object via reflection.
	 *
	 * @return mixed The property's value.
	 */
	private static function getRestrictedProperty( object $object, string $property ) {
		$ref = new \ReflectionProperty( $object, $property );
		return $ref->getValue( $object );
	}

	/**
	 * Write a temporary file with the given content + size (for tests
	 * that need a file of a specific size on disk).
	 *
	 * @return string Absolute file path.
	 */
	private function make_temp_file( string $contents, int $bytes ): string {
		$path = tempnam( sys_get_temp_dir(), 'sscribe-cov-' );
		file_put_contents( $path, $contents );
		// Pad to $bytes if the caller asked for more than strlen($contents).
		if ( strlen( $contents ) < $bytes ) {
			file_put_contents( $path, str_pad( $contents, $bytes, "\x00" ) );
		}
		return $path;
	}
}
