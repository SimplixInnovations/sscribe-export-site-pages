<?php
/**
 * SScribe Rate Limit Response Unit Test
 *
 * @package SScribe_Export_Site_Pages
 *
 * Phase 20 regression: every 429 / 503 response emitted by
 * SScribe_Rate_Limit_Response::emit() MUST carry both:
 *   1. The server-side SScribe_Request_Id::RESPONSE_KEY (Phase 13
 *      contract) so client-side failures correlate to server logs.
 *   2. A `retry_in` body field that the JS layer honors via
 *      getAjaxFailureDecision (Phase 5).
 *
 * The HTTP `Retry-After` response header is also emitted by
 * SScribe_Rate_Limit_Response::emit() for external HTTP clients that
 * do not parse the SScribe envelope. This test cannot capture raw
 * PHP `header()` calls (the fake-wp bootstrap only stubs
 * `status_header()`), so the header assertion is a code-level
 * contract verified separately by code review / static analysis.
 * The body contract below is the primary client-facing one.
 */

declare(strict_types=1);

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

class SScribe_Rate_Limit_Response_Test extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['sscribe_test_current_user_can'] = true;
		$GLOBALS['sscribe_test_current_user_id']  = 1;
		$GLOBALS['sscribe_test_status_header']    = null;
		$_POST                                    = array();
	}

	protected function tearDown(): void {
		$_POST                                    = array();
		$GLOBALS['sscribe_test_current_user_can'] = null;
		$GLOBALS['sscribe_test_current_user_id']  = null;
		$GLOBALS['sscribe_test_status_header']    = null;
		parent::tearDown();
	}

	private function capture_response( \SScribe_Rate_Limit_Decision $decision ): array {
		ob_start();
		$GLOBALS['sscribe_test_status_header'] = null;
		try {
			\SScribe_Rate_Limit_Response::emit( $decision );
			$this->fail( 'Expected RuntimeException from emit' );
		} catch ( \RuntimeException $e ) {
			$body = (string) ob_get_clean();
		}
		return array(
			'status' => $GLOBALS['sscribe_test_status_header'],
			'body'   => $body,
		);
	}

	public function test_quota_exceeded_sends_429_with_request_id_and_retry_in(): void {
		$now      = time();
		$decision = \SScribe_Rate_Limit_Decision::quota_exceeded(
			'export',
			500,
			3500,
			$now + 30
		);
		$captured = $this->capture_response( $decision );

		$this->assertSame( 429, $captured['status'], '429 status header must be emitted for quota exhaustion' );

		$json = json_decode( $captured['body'], true );
		$this->assertIsArray( $json );
		$this->assertFalse( $json['success'] );
		$this->assertSame( 'rate_limited', $json['data']['code'] );

		// Phase 13 contract: every server->client error envelope carries request_id.
		$this->assertArrayHasKey( 'request_id', $json['data'] );
		$this->assertNotEmpty( $json['data']['request_id'] );

		// Body must surface the recommended back-off so JS can honor it.
		$this->assertArrayHasKey( 'retry_in', $json['data'] );
		$this->assertGreaterThanOrEqual( 500, $json['data']['retry_in'] );
		$this->assertTrue( $json['data']['retry'] );

		// Bucket + limit context exposed so the client UI can show "X of Y used".
		$this->assertSame( 'export', $json['data']['bucket'] );
		$this->assertSame( 500, $json['data']['limit'] );
	}

	public function test_limiter_contention_sends_503_with_request_id(): void {
		// 100ms < 200ms contention floor → must clamp up.
		$decision = \SScribe_Rate_Limit_Decision::limiter_contention(
			'export',
			500,
			100
		);
		$captured = $this->capture_response( $decision );

		$this->assertSame( 503, $captured['status'], '503 status header must be emitted for limiter contention' );

		$json = json_decode( $captured['body'], true );
		$this->assertIsArray( $json );
		$this->assertFalse( $json['success'] );
		$this->assertSame( 'rate_limiter_busy', $json['data']['code'] );

		$this->assertArrayHasKey( 'request_id', $json['data'] );
		$this->assertNotEmpty( $json['data']['request_id'] );

		// Documented floor for limiter_contention is 200ms.
		$this->assertGreaterThanOrEqual( 200, $json['data']['retry_in'] );
		// Input was 100; body should now reflect the clamped value (200).
		$this->assertSame( 200, $json['data']['retry_in'] );
	}

	public function test_quota_exceeded_clamps_retry_in_below_1000ms_floor(): void {
		// 100ms < 1000ms quota floor → must clamp up.
		$decision = \SScribe_Rate_Limit_Decision::quota_exceeded(
			'export',
			500,
			100,
			time() + 30
		);
		$captured = $this->capture_response( $decision );

		$json = json_decode( $captured['body'], true );
		$this->assertGreaterThanOrEqual( 1000, $json['data']['retry_in'], 'quota_exceeded retry_in must honor the 1000ms floor' );
		$this->assertSame( 1000, $json['data']['retry_in'], 'quota_exceeded should clamp 100ms up to exactly 1000ms' );
	}

	public function test_response_body_includes_localized_message(): void {
		$decision = \SScribe_Rate_Limit_Decision::quota_exceeded(
			'export',
			500,
			1500,
			time() + 30
		);
		$captured = $this->capture_response( $decision );

		$json = json_decode( $captured['body'], true );
		$this->assertNotEmpty( $json['data']['message'] );
		$this->assertIsString( $json['data']['message'] );
	}

	/**
	 * Static contract: the HTTP Retry-After response header MUST be
	 * emitted alongside the JSON body so external HTTP clients
	 * (without knowledge of the SScribe envelope) can honor the
	 * server's recommended back-off. This test fails immediately if
	 * the source regresses and removes the header.
	 */
	public function test_source_emits_retry_after_header(): void {
		$source = file_get_contents( __DIR__ . '/../../includes/class-sscribe-rate-limit-response.php' );
		$this->assertNotFalse( $source );
		$this->assertStringContainsString(
			"header( 'Retry-After:",
			$source,
			'SScribe_Rate_Limit_Response::emit() must emit the HTTP Retry-After response header'
		);
	}
}