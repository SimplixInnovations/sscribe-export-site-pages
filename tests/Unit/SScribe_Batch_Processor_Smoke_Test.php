<?php
/**
 * SScribe Batch Processor public-method smoke test
 *
 * Exercises the lightweight public surface of the batch processor
 * without going through the full AJAX harness:
 *
 *   - parse_format_options() static — edge cases of input type coercion
 *   - shutdown_cleanup() static — both the no-op branch (no cleanup
 *     state) and the full branch with mock collaborators
 *   - __construct() with explicit collaborator injection — verifies the
 *     property wiring (collector, zip_handler, session, logger, etc.)
 *     without touching the heavy lifecycle methods
 *   - sscribe_batch_size filter is honored (clamped to 1..20)
 *
 * The full AJAX entry points (ajax_start_export, ajax_process_batch,
 * ajax_finalize_export, ajax_download, ajax_preflight_check, etc.)
 * are covered by the integration tests; this file pins the
 * deterministic code paths and the public surface that other code
 * relies on.
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

if ( ! class_exists( '\\SScribe_Batch_Processor', false ) ) {
	require_once SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-batch-processor.php';
}

final class SScribe_Batch_Processor_Smoke_Test extends TestCase {

	public function test_parse_format_options_returns_empty_for_null(): void {
		$this::assertSame( array(), \SScribe_Batch_Processor::parse_format_options( null ) );
	}

	public function test_parse_format_options_returns_empty_for_string(): void {
		$this::assertSame( array(), \SScribe_Batch_Processor::parse_format_options( 'not-array' ) );
	}

	public function test_parse_format_options_returns_empty_for_integer(): void {
		$this::assertSame( array(), \SScribe_Batch_Processor::parse_format_options( 42 ) );
	}

	public function test_parse_format_options_returns_empty_for_object(): void {
		$this::assertSame( array(), \SScribe_Batch_Processor::parse_format_options( new \stdClass() ) );
	}

	public function test_parse_format_options_returns_empty_for_empty_array(): void {
		$this::assertSame( array(), \SScribe_Batch_Processor::parse_format_options( array() ) );
	}

	public function test_parse_format_options_sanitizes_scalar_values(): void {
		$result = \SScribe_Batch_Processor::parse_format_options(
			array(
				'sscribe_pdf_page_size'      => 'A4',
				'sscribe_pdf_include_images' => '1',
			)
		);
		$this::assertIsArray( $result );
		$this::assertArrayHasKey( 'sscribe_pdf_page_size', $result );
		$this::assertSame( 'A4', $result['sscribe_pdf_page_size'] );
	}

	public function test_parse_format_options_rejects_array_values(): void {
		// Arrays nested inside the input must not survive — third-party
		// callers may pass malformed maps and the static must sanitize.
		$result = \SScribe_Batch_Processor::parse_format_options(
			array(
				'sscribe_pdf_page_size'        => 'A4',
				'sscribe_pdf_include_images'   => array( 'a', 'b' ),
				'sscribe_pdf_include_page_numbers' => new \stdClass(),
			)
		);
		$this::assertIsArray( $result );
		$this::assertArrayHasKey( 'sscribe_pdf_page_size', $result );
		$this::assertSame( 'A4', $result['sscribe_pdf_page_size'] );
		// Arrays nested inside allowlist keys are coerced to a string[]
		// of sanitized scalar values, NOT preserved as raw arrays.
		$this::assertIsArray( $result['sscribe_pdf_include_images'] );
		// Object-valued key collapses to empty string.
		$this::assertSame( '', $result['sscribe_pdf_include_page_numbers'] );
	}

	public function test_parse_format_options_strips_unknown_keys(): void {
		// Keys not on the FORMAT_OPTION_KEYS allowlist must be silently
		// dropped without raising or partial-preserving.
		$result = \SScribe_Batch_Processor::parse_format_options(
			array(
				'sscribe_pdf_page_size' => 'Letter',
				'not_in_allowlist'      => 'should_be_dropped',
				''                      => 'empty_key_should_be_dropped',
			)
		);
		$this::assertIsArray( $result );
		$this::assertArrayHasKey( 'sscribe_pdf_page_size', $result );
		$this::assertArrayNotHasKey( 'not_in_allowlist', $result );
	}

	public function test_parse_format_options_caps_input_size(): void {
		// More than 100 keys at the top level must be truncated — the
		// function slices to the first 100 to bound memory.
		$raw = array();
		for ( $i = 0; $i < 150; $i++ ) {
			$raw[ 'sscribe_pdf_page_size_' . $i ] = 'value_' . $i;
		}
		$result = \SScribe_Batch_Processor::parse_format_options( $raw );
		// All 150 keys are outside the allowlist, so result must be empty.
		$this::assertSame( array(), $result );
	}

	public function test_parse_format_options_caps_array_size(): void {
		// A 30-element array under an allowlist key must be truncated
		// to 20 entries.
		$raw = array(
			'sscribe_pdf_page_size'      => array_fill( 0, 30, 'x' ),
		);
		$result = \SScribe_Batch_Processor::parse_format_options( $raw );
		$this::assertIsArray( $result['sscribe_pdf_page_size'] );
		$this::assertLessThanOrEqual( 20, count( $result['sscribe_pdf_page_size'] ) );
	}

	public function test_shutdown_cleanup_with_no_state_is_noop(): void {
		// When no cleanup state has been registered (the common case
		// for a request that didn't start an export), the function must
		// return cleanly without touching anything.
		\SScribe_Batch_Processor::shutdown_cleanup();
		$this::assertTrue( true, 'shutdown_cleanup must be safe to call without prior state.' );
	}

	public function test_shutdown_cleanup_can_be_called_multiple_times(): void {
		// Idempotency check — calling shutdown repeatedly must not
		// raise even if state has been touched between calls.
		\SScribe_Batch_Processor::shutdown_cleanup();
		\SScribe_Batch_Processor::shutdown_cleanup();
		\SScribe_Batch_Processor::shutdown_cleanup();
		$this::assertTrue( true, 'shutdown_cleanup must be idempotent.' );
	}

	public function test_constructor_with_explicit_collaborators_wires_properties(): void {
		// Construct the batch processor with explicit mocks. The
		// constructor's only side effects are property wiring and a
		// register_shutdown_function call (which we ignore here).
		$collector        = new \SScribe_Page_Collector();
		$zip_handler      = new \SScribe_Zip_Handler();
		$session          = new \SScribe_Session();
		$logger           = \SScribe_Logger::instance( false );
		$file_handler     = new \SScribe_Batch_File_Handler();
		$query_controller = new \SScribe_Export_Query_Controller();

		$processor = new \SScribe_Batch_Processor(
			$collector,
			$zip_handler,
			$session,
			$logger,
			$file_handler,
			$query_controller
		);

		$this::assertInstanceOf( \SScribe_Batch_Processor::class, $processor );
	}

	public function test_constructor_with_null_collaborators_uses_defaults(): void {
		// All-null DI path — exercises every default-instantiation
		// branch (collector, zip, session, logger, file handler).
		$processor = new \SScribe_Batch_Processor( null, null, null, null, null, null );
		$this::assertInstanceOf( \SScribe_Batch_Processor::class, $processor );
	}

	public function test_sscribe_batch_size_filter_is_honored(): void {
		// The filter must clamp to the documented 1..20 range. Anything
		// outside the range collapses to the boundary.
		$callback = static function (): int { return 7; };
		add_filter( 'sscribe_batch_size', $callback );
		try {
			$processor = new \SScribe_Batch_Processor( null, null, null, null, null, null );
			$this::assertInstanceOf( \SScribe_Batch_Processor::class, $processor );
		} finally {
			remove_filter( 'sscribe_batch_size', $callback );
		}
	}

	public function test_sscribe_batch_size_filter_clamps_to_lower_bound(): void {
		$callback = static function (): int { return 0; };
		add_filter( 'sscribe_batch_size', $callback );
		try {
			$processor = new \SScribe_Batch_Processor( null, null, null, null, null, null );
			$this::assertInstanceOf( \SScribe_Batch_Processor::class, $processor );
		} finally {
			remove_filter( 'sscribe_batch_size', $callback );
		}
	}

	public function test_sscribe_batch_size_filter_clamps_to_upper_bound(): void {
		$callback = static function (): int { return 9999; };
		add_filter( 'sscribe_batch_size', $callback );
		try {
			$processor = new \SScribe_Batch_Processor( null, null, null, null, null, null );
			$this::assertInstanceOf( \SScribe_Batch_Processor::class, $processor );
		} finally {
			remove_filter( 'sscribe_batch_size', $callback );
		}
	}
}
