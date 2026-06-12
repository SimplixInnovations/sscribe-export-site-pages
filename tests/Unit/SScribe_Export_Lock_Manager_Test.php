<?php
/**
 * SScribe Export Lock Manager Unit Test
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

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

		$current_time = time();
		$GLOBALS['sscribe_test_transients']['sscribe_lock_test-session-456'] = $current_time . '|other-token';

		$token = $manager->acquire_lock( 'test-session-456' );
		$this->assertNull( $token );
	}

	public function test_acquire_lock_succeeds_on_stale_lock(): void {
		$manager = new \SScribe_Export_Lock_Manager();

		$GLOBALS['sscribe_test_transients']['sscribe_lock_test-stale'] = '100|old-token';

		$token = $manager->acquire_lock( 'test-stale' );
		$this->assertNotNull( $token );
		$this->assertIsString( $token );
		$this->assertEquals( 32, strlen( $token ) );

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

		$token = $manager->acquire_lock( 'test-wrong-token' );
		$this->assertNotNull( $token );

		$this->assertFalse( $manager->release_lock( 'test-wrong-token', 'wrong-token-value' ) );
	}

	public function test_release_lock_returns_false_with_null_token(): void {
		$manager = new \SScribe_Export_Lock_Manager();

		$this->assertFalse( $manager->release_lock( 'test-null-token', null ) );
	}

	public function test_release_lock_returns_true_when_lock_already_expired(): void {
		$manager = new \SScribe_Export_Lock_Manager();
		$token   = $manager->acquire_lock( 'test-expired' );

		$GLOBALS['sscribe_test_transients'] = array();

		$this->assertTrue( $manager->release_lock( 'test-expired', $token ) );
	}

	public function test_lock_owner_token_is_verified_on_release(): void {
		$manager = new \SScribe_Export_Lock_Manager();

		$token_a = $manager->acquire_lock( 'test-ownership' );
		$this->assertNotNull( $token_a );

		$this->assertTrue( $manager->release_lock( 'test-ownership', $token_a ) );

		$token_b = $manager->acquire_lock( 'test-ownership' );
		$this->assertNotNull( $token_b );
		$this->assertNotEquals( $token_a, $token_b );
	}

	public function test_shutdown_handler_does_not_delete_lock_owned_by_different_token(): void {
		// Simulate the race: this request acquired the lock, then a stale-claim
		// by another process overwrote it with a different token before our
		// shutdown handler ran. The handler must NOT delete the new holder's lock.
		$manager = new \SScribe_Export_Lock_Manager();

		$token_a = $manager->acquire_lock( 'test-shutdown-race' );
		$this->assertNotNull( $token_a );

		// Simulate a new process overwriting the lock with a different token
		// (e.g. our TTL expired, another process took over).
		$new_holder_token = 'token-of-new-holder';
		$current_time     = time();
		$GLOBALS['sscribe_test_transients']['sscribe_lock_test-shutdown-race'] = $current_time . '|' . $new_holder_token;

		// Run the shutdown handler — it must not wipe the new holder's lock.
		\SScribe_Export_Lock_Manager::shutdown_cleanup_handler();

		$lock_data = get_transient( 'sscribe_lock_test-shutdown-race' );
		$this->assertNotFalse( $lock_data, 'Shutdown handler must not delete a lock owned by a different token' );
		$this->assertStringContainsString( $new_holder_token, $lock_data );
	}

	public function test_shutdown_handler_deletes_lock_owned_by_own_token(): void {
		// The normal case: shutdown handler is called for a lock we still own.
		$manager = new \SScribe_Export_Lock_Manager();

		$token = $manager->acquire_lock( 'test-shutdown-own' );
		$this->assertNotNull( $token );

		\SScribe_Export_Lock_Manager::shutdown_cleanup_handler();

		$lock_data = get_transient( 'sscribe_lock_test-shutdown-own' );
		$this->assertFalse( $lock_data, 'Shutdown handler must delete a lock we still own' );
	}

	public function test_shutdown_handler_is_noop_when_no_lock_acquired(): void {
		// No lock has been acquired — static state is null. The handler must
		// not throw and must not touch any transients.
		$marker = 'sscribe_lock_test-shutdown-noop';
		$GLOBALS['sscribe_test_transients'][ $marker ] = 'should-stay-intact';

		\SScribe_Export_Lock_Manager::shutdown_cleanup_handler();

		$this->assertSame( 'should-stay-intact', get_transient( $marker ) );
	}
}
