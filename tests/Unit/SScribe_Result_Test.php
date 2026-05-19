<?php

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

class SScribe_Result_Test extends TestCase {

	public function test_success_creates_successful_result() {
		$result = \SScribe_Result::success( array( 'id' => 1 ) );
		
		$this->assertTrue( $result->is_success() );
		$this->assertFalse( $result->is_failure() );
		$this->assertEquals( array( 'id' => 1 ), $result->get_data() );
		$this->assertNull( $result->get_error() );
	}

	public function test_failure_creates_failed_result() {
		$result = \SScribe_Result::failure( 'Something went wrong', array( 'context' => 'test' ) );
		
		$this->assertFalse( $result->is_success() );
		$this->assertTrue( $result->is_failure() );
		$this->assertEquals( 'Something went wrong', $result->get_error() );
		$this->assertEquals( array( 'context' => 'test' ), $result->get_context() );
	}

	public function test_get_data_returns_null_for_failure() {
		$result = \SScribe_Result::failure( 'Error' );
		$this->assertNull( $result->get_data() );
	}

	public function test_get_error_returns_null_for_success() {
		$result = \SScribe_Result::success( 'data' );
		$this->assertNull( $result->get_error() );
	}
}
