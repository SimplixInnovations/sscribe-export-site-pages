<?php
/**
 * SScribe Export Outcome unit test
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SScribe_Export_Outcome_Test extends TestCase {

	public function test_ok_defaults_to_empty_payload_and_status_200(): void {
		$outcome = \SScribe_Export_Outcome::ok();

		$this::assertTrue( $outcome->is_success() );
		$this::assertSame( 'ok', $outcome->kind() );
		$this::assertSame( array(), $outcome->payload() );
		$this::assertSame( 200, $outcome->http_status() );
		$this::assertSame( '', $outcome->code() );
		$this::assertNull( $outcome->decision() );
		$this::assertSame( '', $outcome->session_id() );
		$this::assertSame( 0, $outcome->retry_after_ms() );
	}

	public function test_ok_keeps_payload_and_custom_status(): void {
		$outcome = \SScribe_Export_Outcome::ok( array( 'status' => 'processing' ), 202 );

		$this::assertSame( array( 'status' => 'processing' ), $outcome->payload() );
		$this::assertSame( 202, $outcome->http_status() );
	}

	public function test_fail_exposes_code_and_status(): void {
		$outcome = \SScribe_Export_Outcome::fail(
			array(
				'code'    => 'session_expired',
				'message' => 'gone',
			),
			404
		);

		$this::assertFalse( $outcome->is_success() );
		$this::assertSame( 'error', $outcome->kind() );
		$this::assertSame( 404, $outcome->http_status() );
		$this::assertSame( 'session_expired', $outcome->code() );
		$this::assertSame( 'gone', $outcome->payload()['message'] );
	}

	public function test_fail_without_code_returns_empty_code(): void {
		$outcome = \SScribe_Export_Outcome::fail( array( 'message' => 'x' ), 500 );

		$this::assertSame( '', $outcome->code() );
		$this::assertSame( 500, $outcome->http_status() );
	}

	public function test_fail_with_non_string_code_returns_empty_code(): void {
		$outcome = \SScribe_Export_Outcome::fail( array( 'code' => 42 ), 500 );

		$this::assertSame( '', $outcome->code() );
	}

	public function test_cancelled_failure_can_carry_status_200_and_is_still_not_success(): void {
		$outcome = \SScribe_Export_Outcome::fail(
			array(
				'code'      => 'cancelled',
				'cancelled' => true,
			),
			200
		);

		$this::assertFalse( $outcome->is_success() );
		$this::assertSame( 200, $outcome->http_status() );
		$this::assertSame( 'cancelled', $outcome->code() );
	}

	public function test_rate_limited_carries_decision_and_its_status(): void {
		$decision = \SScribe_Rate_Limit_Decision::quota_exceeded( 'export_batch', 30, 1500, time() + 60 );
		$outcome  = \SScribe_Export_Outcome::rate_limited( $decision );

		$this::assertFalse( $outcome->is_success() );
		$this::assertSame( 'rate_limited', $outcome->kind() );
		$this::assertSame( $decision, $outcome->decision() );
		$this::assertSame( $decision->http_status(), $outcome->http_status() );
		$this::assertSame( $decision->error_code(), $outcome->code() );
		$this::assertSame( 1500, $outcome->retry_after_ms() );
	}

	public function test_lock_conflict_defaults_to_5000ms_and_409(): void {
		$outcome = \SScribe_Export_Outcome::lock_conflict( 'a1b2c3d4e5f60718' );

		$this::assertFalse( $outcome->is_success() );
		$this::assertSame( 'lock_conflict', $outcome->kind() );
		$this::assertSame( 409, $outcome->http_status() );
		$this::assertSame( 'batch_in_progress', $outcome->code() );
		$this::assertSame( 'a1b2c3d4e5f60718', $outcome->session_id() );
		$this::assertSame( 5000, $outcome->retry_after_ms() );
		$this::assertNull( $outcome->decision() );
	}

	public function test_lock_conflict_keeps_custom_retry(): void {
		$outcome = \SScribe_Export_Outcome::lock_conflict( 'a1b2c3d4e5f60718', 1200 );

		$this::assertSame( 1200, $outcome->retry_after_ms() );
	}

	public function test_outcome_class_is_final_with_private_constructor_and_readonly_state(): void {
		$ref = new \ReflectionClass( \SScribe_Export_Outcome::class );

		$this::assertTrue( $ref->isFinal() );
		$this::assertFalse( $ref->getConstructor()->isPublic() );
		foreach ( $ref->getProperties() as $property ) {
			$this::assertTrue( $property->isReadOnly(), $property->getName() . ' must be readonly' );
		}
	}

	public function test_payload_copy_cannot_change_the_outcome(): void {
		$outcome      = \SScribe_Export_Outcome::ok( array( 'a' => 1 ) );
		$payload      = $outcome->payload();
		$payload['a'] = 2;

		$this::assertSame( array( 'a' => 1 ), $outcome->payload() );
	}

	public function test_state_cannot_be_rewritten_after_construction(): void {
		$outcome = \SScribe_Export_Outcome::ok();
		$prop    = new \ReflectionProperty( \SScribe_Export_Outcome::class, 'http_status' );

		$this->expectException( \Error::class );
		$prop->setValue( $outcome, 500 );
	}
}
