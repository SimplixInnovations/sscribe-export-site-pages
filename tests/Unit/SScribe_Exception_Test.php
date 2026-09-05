<?php
/**
 * SScribe_Exception class unit test
 *
 * Covers every public surface of the base SScribe exception:
 *   - constructor (default + custom HTTP status + previous throwable)
 *   - get_error_code / get_error_data / get_http_status_code
 *   - should_log / is_recoverable accessors
 *   - to_wp_error conversion
 *   - to_scribe_error delegation
 *   - to_array with and without debug details
 *   - from_template happy path + unknown-code fallback
 *   - severity-driven HTTP status mapping
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

if ( ! class_exists( '\\SScribe_Exception' ) ) {
	require_once SSCRIBE_PLUGIN_DIR . 'includes/exceptions/class-sscribe-exception.php';
}

if ( ! class_exists( '\\SScribe_Error' ) ) {
	require_once SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-error.php';
}

if ( ! class_exists( '\\WP_Error' ) && ! class_exists( '\\WP_ErrorStub' ) ) {
	require_once SSCRIBE_PLUGIN_DIR . 'tests/fake-wp/class-wp-error.php';
}

final class SScribe_Exception_Test extends TestCase {

	public function test_constructor_stores_all_fields(): void {
		$previous  = new \RuntimeException( 'root cause' );
		$exception = new \SScribe_Exception(
			\SScribe_Exception::CODE_MEMORY_EXHAUSTED,
			'out of memory',
			507,
			array( 'bytes' => 1024 ),
			$previous
		);

		$this::assertSame( \SScribe_Exception::CODE_MEMORY_EXHAUSTED, $exception->get_error_code() );
		$this::assertSame( 'out of memory', $exception->getMessage() );
		$this::assertSame( 507, $exception->get_http_status_code() );
		$this::assertSame( 507, $exception->getCode() );
		$this::assertSame( $previous, $exception->getPrevious() );
	}

	public function test_get_error_data_merges_status_field(): void {
		$exception = new \SScribe_Exception(
			\SScribe_Exception::CODE_INVALID_DATA,
			'bad payload',
			400,
			array( 'field' => 'title' )
		);

		$data = $exception->get_error_data();
		$this::assertSame( 'title', $data['field'] );
		$this::assertSame( 400, $data['status'] );
	}

	public function test_should_log_and_is_recoverable_return_default_values(): void {
		$exception = new \SScribe_Exception( \SScribe_Exception::CODE_UNEXPECTED_ERROR, 'oh no' );

		$this::assertTrue( $exception->should_log() );
		$this::assertFalse( $exception->is_recoverable() );
	}

	public function test_to_wp_error_uses_error_code_and_data(): void {
		$exception = new \SScribe_Exception(
			\SScribe_Exception::CODE_ZIP_FAILED,
			'zip broken',
			500,
			array( 'file' => 'x.zip' )
		);

		$wp_error = $exception->to_wp_error();
		$this::assertSame( \SScribe_Exception::CODE_ZIP_FAILED, $wp_error->get_error_code() );
		$this::assertSame( 'zip broken', $wp_error->get_error_message() );
		$data = $wp_error->get_error_data();
		$this::assertSame( 'x.zip', $data['file'] );
		$this::assertSame( 500, $data['status'] );
	}

	public function test_to_scribe_error_delegates_to_template_factory(): void {
		// E_EXPORT_011 is intentionally NOT a registered SScribe_Error
		// template. The factory's documented behavior is to fall back to
		// UNKNOWN_ERROR so the response always carries a meaningful code.
		// This test locks in that delegation contract.
		$exception = new \SScribe_Exception(
			\SScribe_Exception::CODE_VALIDATION_FAILED,
			'invalid',
			400,
			array( 'field' => 'x' )
		);

		$error = $exception->to_scribe_error();
		$this::assertInstanceOf( \SScribe_Error::class, $error );
		// The exception passes its raw error_code through, so SScribe_Error
		// is responsible for the final code. UNKNOWN_ERROR is the documented
		// fallback when no template matches.
		$this::assertSame( 'UNKNOWN_ERROR', $error->get_code() );
	}

	public function test_to_scribe_error_preserves_known_template_code(): void {
		// Use a real, registered template code to verify the happy path:
		// when the SScribe_Error factory knows the code, it preserves it.
		if ( ! class_exists( '\\SScribe_Error' ) ) {
			$this::markTestSkipped( 'SScribe_Error unavailable.' );
		}
		$templates = \SScribe_Error::get_templates();
		$known_code = array_key_first( $templates );
		if ( null === $known_code ) {
			$this::markTestSkipped( 'No SScribe_Error templates registered.' );
		}

		$exception = new \SScribe_Exception(
			$known_code,
			'template-known',
			500,
			array( 'foo' => 'bar' )
		);

		$error = $exception->to_scribe_error();
		$this::assertSame( $known_code, $error->get_code() );
		$this::assertSame( 'bar', $error->get_context()['foo'] );
	}

	public function test_to_array_basic_shape_excludes_debug_details(): void {
		$exception = new \SScribe_Exception(
			\SScribe_Exception::CODE_TIMEOUT,
			'slow',
			408,
			array( 'limit' => 30 )
		);

		$arr = $exception->to_array();
		$this::assertSame( \SScribe_Exception::CODE_TIMEOUT, $arr['code'] );
		$this::assertSame( 'slow', $arr['message'] );
		$this::assertSame( 408, $arr['httpStatus'] );
		$this::assertArrayNotHasKey( 'context', $arr );
		$this::assertArrayNotHasKey( 'file', $arr );
		$this::assertArrayNotHasKey( 'trace', $arr );
	}

	public function test_to_array_with_details_includes_context_and_file(): void {
		$exception = new \SScribe_Exception(
			\SScribe_Exception::CODE_PDF_GENERATION_FAILED,
			'pdf died',
			500,
			array( 'page_id' => 7 )
		);

		$arr = $exception->to_array( true );
		$this::assertArrayHasKey( 'context', $arr );
		$this::assertSame( 7, $arr['context']['page_id'] );
		$this::assertArrayHasKey( 'file', $arr );
		$this::assertArrayHasKey( 'line', $arr );
		$this::assertArrayHasKey( 'recoverable', $arr );
	}

	public function test_to_array_with_details_and_debug_includes_trace(): void {
		if ( ! defined( 'SSCRIBE_DEBUG' ) || ! SSCRIBE_DEBUG ) {
			$this::markTestSkipped( 'SSCRIBE_DEBUG not enabled.' );
		}
		$exception = new \SScribe_Exception( \SScribe_Exception::CODE_UNEXPECTED_ERROR, 'x' );
		$arr = $exception->to_array( true );
		$this::assertArrayHasKey( 'trace', $arr );
		$this::assertIsString( $arr['trace'] );
	}

	public function test_from_template_returns_unknown_when_code_unmapped(): void {
		$exception = \SScribe_Exception::from_template( 'E_THIS_DOES_NOT_EXIST' );
		$this::assertSame( \SScribe_Exception::CODE_UNEXPECTED_ERROR, $exception->get_error_code() );
		$this::assertSame( 500, $exception->get_http_status_code() );
	}

	public function test_from_template_uses_critical_severity_for_500(): void {
		if ( ! class_exists( '\\SScribe_Error' ) ) {
			$this::markTestSkipped( 'SScribe_Error class unavailable.' );
		}
		$templates = \SScribe_Error::get_templates();
		$critical_code = null;
		foreach ( $templates as $code => $tpl ) {
			if ( isset( $tpl['severity'] ) && \SScribe_Error::SEVERITY_CRITICAL === $tpl['severity'] ) {
				$critical_code = $code;
				break;
			}
		}
		if ( null === $critical_code ) {
			$this::markTestSkipped( 'No critical-severity template registered.' );
		}
		$exception = \SScribe_Exception::from_template( $critical_code );
		$this::assertSame( 500, $exception->get_http_status_code() );
	}

	public function test_from_template_uses_warning_severity_for_200(): void {
		if ( ! class_exists( '\\SScribe_Error' ) ) {
			$this::markTestSkipped( 'SScribe_Error class unavailable.' );
		}
		$templates = \SScribe_Error::get_templates();
		$warning_code = null;
		foreach ( $templates as $code => $tpl ) {
			if ( isset( $tpl['severity'] ) && \SScribe_Error::SEVERITY_WARNING === $tpl['severity'] ) {
				$warning_code = $code;
				break;
			}
		}
		if ( null === $warning_code ) {
			$this::markTestSkipped( 'No warning-severity template registered.' );
		}
		$exception = \SScribe_Exception::from_template( $warning_code );
		$this::assertSame( 200, $exception->get_http_status_code() );
	}

	public function test_from_template_uses_error_severity_for_400(): void {
		if ( ! class_exists( '\\SScribe_Error' ) ) {
			$this::markTestSkipped( 'SScribe_Error class unavailable.' );
		}
		$templates = \SScribe_Error::get_templates();
		$error_code = null;
		foreach ( $templates as $code => $tpl ) {
			if ( isset( $tpl['severity'] ) && \SScribe_Error::SEVERITY_ERROR === $tpl['severity'] ) {
				$error_code = $code;
				break;
			}
		}
		if ( null === $error_code ) {
			$this::markTestSkipped( 'No error-severity template registered.' );
		}
		$exception = \SScribe_Exception::from_template( $error_code );
		$this::assertSame( 400, $exception->get_http_status_code() );
	}
}
