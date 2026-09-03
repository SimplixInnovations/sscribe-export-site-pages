<?php
/**
 * Phase 16 regression: external HTTP 429 from a remote image origin
 * must be classified distinctly and retried with a bounded budget.
 *
 * Before the fix, download_to_temp() lumped every non-2xx response
 * (including 429) into the same "return false" bucket. Two failure
 * modes followed:
 *
 *   1. A single 429 from the origin caused the image to be dropped
 *      even though a polite Retry-After would have unblocked the
 *      request in milliseconds.
 *
 *   2. The failure was indistinguishable from a 404 or 500 in the
 *      operational log, so production-side diagnosis was impossible.
 *
 * The fix routes 429 through a bounded retry loop that honors the
 * Retry-After header up to a hard cap (default 30s), logs the
 * classification to the operational channel, and only then gives up.
 * Other 4xx/5xx responses remain terminal — retrying 5xx would mask
 * server bugs and retrying 4xx (other than 429) is wasted work.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SScribe_Image_Processor_Remote_429_Test extends TestCase {

	/**
	 * 1x1 transparent PNG, header + body. Real binary payload so that
	 * if the production code reaches the staging step it can pass
	 * getimagesize() and write a valid file.
	 */
	private const VALID_PNG = "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8/5+hHgAHggJ/PchI7wAAAABJRU5ErkJggg==";

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['sscribe_test_http_response'] = array();
		$GLOBALS['sscribe_test_http_calls']    = 0;
		\SScribe_Operational_Logger::reset_for_testing();
		// Replace the production usleep with a no-op so the retry budget
		// assertions run instantly even when Retry-After is positive.
		\SScribe_Image_Processor::$test_backoff_override = static function (): void {};
	}

	protected function tearDown(): void {
		$GLOBALS['sscribe_test_http_response'] = array();
		$GLOBALS['sscribe_test_http_calls']    = 0;
		\SScribe_Operational_Logger::reset_for_testing();
		\SScribe_Image_Processor::$test_backoff_override = null;
		parent::tearDown();
	}

	/**
	 * Queue one or more mock HTTP responses. Each entry is the array
	 * shape wp_safe_remote_get() is expected to return: 'response' =>
	 * ['code' => int], 'headers' => [], 'body' => string.
	 *
	 * @param array<int,array<string,mixed>> $responses
	 */
	private function queue_responses( array $responses ): void {
		$GLOBALS['sscribe_test_http_response'] = $responses;
	}

	/**
	 * Number of wp_safe_remote_get() calls observed during the test.
	 * Bumped by the test stub on every invocation regardless of the
	 * queue length, so the test can assert "the loop ran exactly N
	 * times" without inspecting the queue itself.
	 */
	private function http_call_count(): int {
		return (int) ( $GLOBALS['sscribe_test_http_calls'] ?? 0 );
	}

	private static function invoke_download( string $url ): string|false {
		$ref    = new \ReflectionClass( \SScribe_Image_Processor::class );
		$method = $ref->getMethod( 'download_to_temp' );
		return $method->invoke( null, $url );
	}

	private static function invoke_parse_retry_after( $header_value ): int {
		$ref    = new \ReflectionClass( \SScribe_Image_Processor::class );
		$method = $ref->getMethod( 'parse_retry_after_seconds' );
		return $method->invoke( null, $header_value );
	}

	public function test_429_then_200_retries_and_returns_temp_path(): void {
		$this->queue_responses(
			array(
				array(
					'response' => array( 'code' => 429 ),
					'headers'  => array( 'Retry-After' => '1' ),
					'body'     => '',
				),
				array(
					'response' => array( 'code' => 200 ),
					'headers'  => array( 'content-type' => 'image/png' ),
					'body'     => base64_decode( self::VALID_PNG ),
				),
			)
		);

		$result = self::invoke_download( 'https://example.org/image.png' );

		// The retry loop must have fired exactly twice: once for the 429
		// and once for the 200. Anything else means the budget was not
		// honoured (off-by-one) or the 2xx was missed (off-by-many).
		$this->assertSame( 2, $this->http_call_count(), 'retry loop fired the wrong number of times' );
		$this->assertIsString( $result, 'after a successful retry, download must return the staged path' );
		$this->assertFileExists( $result );
		@unlink( $result );
	}

	public function test_persistent_429_returns_false_after_retry_budget_exhausted(): void {
		// Three 429s in a row: attempts 1, 2, 3 must all fire. The
		// retry budget is 3; after the third 429 the function returns
		// false and never makes a fourth call.
		$this->queue_responses(
			array(
				array( 'response' => array( 'code' => 429 ), 'headers' => array( 'Retry-After' => '1' ), 'body' => '' ),
				array( 'response' => array( 'code' => 429 ), 'headers' => array( 'Retry-After' => '1' ), 'body' => '' ),
				array( 'response' => array( 'code' => 429 ), 'headers' => array( 'Retry-After' => '1' ), 'body' => '' ),
			)
		);

		$result = self::invoke_download( 'https://example.org/rate-limited.png' );

		$this->assertFalse( $result );
		$this->assertSame( 3, $this->http_call_count(), 'retry budget must be exactly 3 (no over-shoot, no early bail)' );
	}

	public function test_500_response_is_terminal_no_retry(): void {
		// 500 must NOT be retried — only 429 is. A single 500 must
		// result in a single HTTP call. Retrying 5xx would mask
		// server-side bugs.
		$this->queue_responses(
			array(
				array(
					'response' => array( 'code' => 500 ),
					'headers'  => array(),
					'body'     => 'oops',
				),
			)
		);

		$result = self::invoke_download( 'https://example.org/500.png' );

		$this->assertFalse( $result );
		$this->assertSame( 1, $this->http_call_count(), 'non-2xx non-429 responses must be terminal, not retried' );
	}

	public function test_404_response_is_terminal_no_retry(): void {
		// 404 is the canonical "missing image" failure. Retrying
		// wastes the budget and obscures the bug from the operator.
		$this->queue_responses(
			array(
				array(
					'response' => array( 'code' => 404 ),
					'headers'  => array(),
					'body'     => 'not found',
				),
			)
		);

		$result = self::invoke_download( 'https://example.org/missing.png' );

		$this->assertFalse( $result );
		$this->assertSame( 1, $this->http_call_count() );
	}

	public function test_network_error_response_is_terminal_no_retry(): void {
		// Empty queue -> stub returns WP_Error -> production must treat
		// this as terminal (not as 429).
		$result = self::invoke_download( 'https://example.org/network-error.png' );

		$this->assertFalse( $result );
		$this->assertSame( 1, $this->http_call_count() );
	}

	public function test_retry_after_delta_seconds_is_parsed_as_integer(): void {
		$this->assertSame( 0, self::invoke_parse_retry_after( '' ) );
		$this->assertSame( 0, self::invoke_parse_retry_after( null ) );
		$this->assertSame( 0, self::invoke_parse_retry_after( 'not-a-number' ) );
		$this->assertSame( 0, self::invoke_parse_retry_after( '-5' ) );
		$this->assertSame( 7, self::invoke_parse_retry_after( '7' ) );
		$this->assertSame( 120, self::invoke_parse_retry_after( '120' ) );

		// A past HTTP-date must clamp to 0 (delta negative).
		$past = gmdate( 'D, d M Y H:i:s', time() - 600 ) . ' GMT';
		$this->assertSame( 0, self::invoke_parse_retry_after( $past ) );

		// A future HTTP-date must yield a positive delta.
		$future = gmdate( 'D, d M Y H:i:s', time() + 45 ) . ' GMT';
		$this->assertGreaterThanOrEqual( 44, self::invoke_parse_retry_after( $future ) );
		$this->assertLessThanOrEqual( 46, self::invoke_parse_retry_after( $future ) );
	}

	public function test_retry_after_is_clamped_to_maximum(): void {
		// 9999-second Retry-After must be clamped to 30s in the
		// download_to_temp() retry path. We can't observe the clamp
		// from outside the function, but we CAN verify it indirectly:
		// with the test backoff set to a recorder, the clamp means the
		// recorded sleep duration is at most 30s even when the header
		// asks for far more.
		$recorded = array();
		\SScribe_Image_Processor::$test_backoff_override = static function ( int $s ) use ( &$recorded ): void {
			$recorded[] = $s;
		};

		$this->queue_responses(
			array(
				array( 'response' => array( 'code' => 429 ), 'headers' => array( 'Retry-After' => '9999' ), 'body' => '' ),
				array( 'response' => array( 'code' => 200 ), 'headers' => array( 'content-type' => 'image/png' ), 'body' => base64_decode( self::VALID_PNG ) ),
			)
		);

		$result = self::invoke_download( 'https://example.org/huge-retry-after.png' );

		$this->assertIsString( $result, 'second attempt must still succeed even when Retry-After is pathologically large' );
		$this->assertNotEmpty( $recorded, 'the backoff override must have been invoked at least once' );
		$this->assertLessThanOrEqual(
			30,
			$recorded[0],
			'Retry-After must be clamped to REMOTE_429_MAX_RETRY_AFTER_SECONDS (30s); a hostile origin cannot stall an export'
		);
		@unlink( $result );
	}

	public function test_429_event_is_recorded_to_operational_logger(): void {
		\SScribe_Image_Processor::$test_backoff_override = static function (): void {};
		$this->queue_responses(
			array(
				array( 'response' => array( 'code' => 429 ), 'headers' => array( 'Retry-After' => '1' ), 'body' => '' ),
				array( 'response' => array( 'code' => 429 ), 'headers' => array( 'Retry-After' => '1' ), 'body' => '' ),
				array( 'response' => array( 'code' => 429 ), 'headers' => array( 'Retry-After' => '1' ), 'body' => '' ),
			)
		);

		self::invoke_download( 'https://example.org/audit.png' );

		$events = \SScribe_Operational_Logger::events_for_testing();
		$found_429 = false;
		foreach ( $events as $event ) {
			if (
				( $event['message'] ?? '' ) === 'Image fetch rate-limited by remote origin'
				&& ( $event['category'] ?? '' ) === 'image_download'
				&& isset( $event['host'] )
				&& isset( $event['attempt'] )
			) {
				$found_429 = true;
				break;
			}
		}
		$this->assertTrue( $found_429, 'every 429 attempt must produce an operational-log record with category=image_download and attempt metadata' );
	}
}