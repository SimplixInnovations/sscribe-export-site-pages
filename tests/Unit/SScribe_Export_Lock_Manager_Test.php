<?php
/**
 * Unit tests for SScribe_Export_Lock_Manager class.
 *
 * @package SScribe
 */

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

class SScribe_Export_Lock_Manager_Test extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['sscribe_test_transients'] = array();
	}

	protected function tearDown(): void {
		$GLOBALS['sscribe_test_transients'] = array();
		parent::tearDown();
	}

	public function test_acquire_lock_returns_token_when_no_existing_lock(): void {
		$manager = new \SScribe_Export_Lock_Manager();
		$token   = $manager->acquire_lock( 'test-session-123' );

		$this->assertNotNull( $token );
		$this->assertIsString( $token );
		$this->assertEquals( 32, strlen( $token ) );
	}

	public function test_acquire_lock_returns_null_when_active_lock_exists(): void {
		$manager = new \SScribe_Export_Lock_Manager();

		// Pre-set a lock with current timestamp (not stale).
		$current_time = time();
		$GLOBALS['sscribe_test_transients']['sscribe_lock_test-session-456'] = $current_time . '|other-token';

		$token = $manager->acquire_lock( 'test-session-456' );
		$this->assertNull( $token );
	}

	public function test_acquire_lock_succeeds_on_stale_lock(): void {
		$manager = new \SScribe_Export_Lock_Manager();

		// Pre-set a lock with a very old timestamp (stale).
		$GLOBALS['sscribe_test_transients']['sscribe_lock_test-stale'] = '100|old-token';

		$token = $manager->acquire_lock( 'test-stale' );
		$this->assertNotNull( $token );
		$this->assertIsString( $token );
		$this->assertEquals( 32, strlen( $token ) );

		// Verify the old lock was replaced.
		$lock_data = get_transient( 'sscribe_lock_test-stale' );
		$this->assertNotFalse( $lock_data );
		$lock_parts = explode( '|', $lock_data );
		$this->assertCount( 2, $lock_parts );
		$this->assertEquals( $lock_parts[1], $token );
	}

	public function test_release_lock_returns_true_with_correct_token(): void {
		$manager = new \SScribe_Export_Lock_Manager();
		$token   = $manager->acquire_lock( 'test-release' );

		$this->assertNotNull( $token );
		$this->assertTrue( $manager->release_lock( 'test-release', $token ) );
	}

	public function test_release_lock_returns_false_with_wrong_token(): void {
		$manager = new \SScribe_Export_Lock_Manager();

		// Acquire lock.
		$token = $manager->acquire_lock( 'test-wrong-token' );
		$this->assertNotNull( $token );

		// Try to release with a different token.
		$this->assertFalse( $manager->release_lock( 'test-wrong-token', 'wrong-token-value' ) );
	}

	public function test_release_lock_returns_false_with_null_token(): void {
		$manager = new \SScribe_Export_Lock_Manager();

		$this->assertFalse( $manager->release_lock( 'test-null-token', null ) );
	}

	public function test_release_lock_returns_true_when_lock_already_expired(): void {
		$manager = new \SScribe_Export_Lock_Manager();
		$token   = $manager->acquire_lock( 'test-expired' );

		// Clear transients (simulating expired lock).
		$GLOBALS['sscribe_test_transients'] = array();

		// Should return true (lock already gone, nothing to release).
		$this->assertTrue( $manager->release_lock( 'test-expired', $token ) );
	}

	public function test_lock_owner_token_is_verified_on_release(): void {
		$manager = new \SScribe_Export_Lock_Manager();

		// Acquire a lock.
		$token_a = $manager->acquire_lock( 'test-ownership' );
		$this->assertNotNull( $token_a );

		// Release with correct token.
		$this->assertTrue( $manager->release_lock( 'test-ownership', $token_a ) );

		// Acquire again — should succeed since it was released.
		$token_b = $manager->acquire_lock( 'test-ownership' );
		$this->assertNotNull( $token_b );
		$this->assertNotEquals( $token_a, $token_b );
	}
}
