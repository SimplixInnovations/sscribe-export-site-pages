<?php
/**
 * SScribe Lock Response Unit Test
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

class SScribe_Lock_Response_Test extends TestCase {

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

	private function capture_conflict( string $session_id, int $retry_after_ms ): array {
		ob_start();
		$GLOBALS['sscribe_test_status_header'] = null;
		try {
			\SScribe_Lock_Response::emit_conflict( $session_id, $retry_after_ms );
			$this->fail( 'Expected RuntimeException from emit_conflict' );
		} catch ( \RuntimeException $e ) {
			$body = ob_get_clean();
		}
		return array(
			'status' => $GLOBALS['sscribe_test_status_header'],
			'body'   => $body,
		);
	}

	public function test_emit_conflict_sends_409(): void {
		$captured = $this->capture_conflict( 'sess_abc', 5000 );

		$this->assertSame( 409, $captured['status'] );

		$json = json_decode( $captured['body'], true );
		$this->assertIsArray( $json );
		$this->assertFalse( $json['success'] );
		$this->assertSame( 'batch_in_progress', $json['data']['code'] );
		$this->assertSame( 5000, $json['data']['retry_in'] );
		$this->assertTrue( $json['data']['retry'] );
		$this->assertSame( 'sess_abc', $json['data']['session_id'] );
		$this->assertArrayHasKey( 'request_id', $json['data'] );
		$this->assertNotEmpty( $json['data']['request_id'] );
	}

	public function test_emit_conflict_clamps_retry_below_500ms(): void {
		$captured = $this->capture_conflict( 'sess_x', 100 );

		$json = json_decode( $captured['body'], true );
		$this->assertGreaterThanOrEqual( 500, $json['data']['retry_in'] );
	}

	public function test_emit_conflict_clamps_retry_above_30s(): void {
		$captured = $this->capture_conflict( 'sess_y', 600000 );

		$json = json_decode( $captured['body'], true );
		$this->assertLessThanOrEqual( 30000, $json['data']['retry_in'] );
	}

	public function test_emit_conflict_includes_localized_message(): void {
		$captured = $this->capture_conflict( 'sess_z', 5000 );

		$json = json_decode( $captured['body'], true );
		$this->assertNotEmpty( $json['data']['message'] );
		$this->assertIsString( $json['data']['message'] );
	}
}