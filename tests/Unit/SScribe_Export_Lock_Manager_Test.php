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
		$GLOBALS['sscribe_test_options']    = array();
		unset( $GLOBALS['sscribe_test_before_wpdb_option_delete'] );
		unset( $GLOBALS['sscribe_test_before_wpdb_option_update'] );
	}

	protected function tearDown(): void {
		$GLOBALS['sscribe_test_transients'] = array();
		$GLOBALS['sscribe_test_options']    = array();
		unset( $GLOBALS['sscribe_test_before_wpdb_option_delete'] );
		unset( $GLOBALS['sscribe_test_before_wpdb_option_update'] );
		parent::tearDown();
	}

	public function test_database_fallback_acquires_atomic_option_lock(): void {
		$manager = new \SScribe_Export_Lock_Manager();
		$token   = $manager->acquire_lock( 'test-session-123' );

		$this->assertIsString( $token );
		$this->assertSame( 32, strlen( $token ) );
		$this->assertIsString( get_option( 'sscribe_export_lock_test-session-123', false ) );
	}

	public function test_second_acquisition_is_rejected(): void {
		$manager = new \SScribe_Export_Lock_Manager();
		$this->assertNotNull( $manager->acquire_lock( 'test-session-456' ) );
		$this->assertNull( $manager->acquire_lock( 'test-session-456' ) );
	}

	public function test_legacy_active_transient_is_honored(): void {
		$GLOBALS['sscribe_test_transients']['sscribe_lock_legacy-session'] = time() . '|other-token';

		$manager = new \SScribe_Export_Lock_Manager();
		$this->assertNull( $manager->acquire_lock( 'legacy-session' ) );
	}

	public function test_stale_legacy_transient_is_replaced(): void {
		$GLOBALS['sscribe_test_transients']['sscribe_lock_test-stale'] = '100|old-token';

		$manager = new \SScribe_Export_Lock_Manager();
		$token   = $manager->acquire_lock( 'test-stale' );
		$stored  = get_option( 'sscribe_export_lock_test-stale', false );

		$this->assertIsString( $token );
		$this->assertIsString( $stored );
		$this->assertStringContainsString( '|' . $token . '|', $stored );
		$this->assertFalse( get_transient( 'sscribe_lock_test-stale' ) );
	}

	public function test_unexpired_lock_uses_owners_expiry_not_callers_threshold(): void {
		$created = time() - 100;
		$expires = time() + 200;
		update_option( 'sscribe_export_lock_shared-resource', $created . '|owner-token|' . $expires, false );

		$manager = new \SScribe_Export_Lock_Manager();
		$this->assertNull( $manager->acquire_lock( 'shared-resource', 30, 25 ) );
		$this->assertSame(
			$created . '|owner-token|' . $expires,
			get_option( 'sscribe_export_lock_shared-resource', false )
		);
	}

	public function test_expired_versioned_lock_is_replaced(): void {
		update_option( 'sscribe_export_lock_expired-resource', ( time() - 100 ) . '|old-token|' . ( time() - 1 ), false );

		$manager = new \SScribe_Export_Lock_Manager();
		$token   = $manager->acquire_lock( 'expired-resource', 30, 25 );

		$this->assertIsString( $token );
		$this->assertStringContainsString( '|' . $token . '|', (string) get_option( 'sscribe_export_lock_expired-resource', false ) );
	}

	public function test_stale_reclamation_cannot_delete_a_successor_lock(): void {
		$manager   = new \SScribe_Export_Lock_Manager();
		$option    = 'sscribe_export_lock_stale-race';
		$expired   = ( time() - 100 ) . '|expired-token|' . ( time() - 1 );
		$successor = time() . '|successor-token|' . ( time() + 30 );

		update_option( $option, $expired, false );
		$GLOBALS['sscribe_test_before_wpdb_option_delete'] = static function ( array $where ) use ( $option, $successor ): void {
			if ( $option === $where['option_name'] ) {
				update_option( $option, $successor, false );
			}
		};

		$this->assertNull( $manager->acquire_lock( 'stale-race', 30, 25 ) );
		$this->assertSame( $successor, get_option( $option, false ) );
	}


	public function test_renew_lock_extends_owned_database_lock(): void {
		$manager = new \SScribe_Export_Lock_Manager();
		$token   = $manager->acquire_lock( 'renew-owned', 30, 25 );

		$this->assertIsString( $token );
		$before = (string) get_option( 'sscribe_export_lock_renew-owned', '' );

		$this->assertTrue( $manager->renew_lock( 'renew-owned', $token, 120 ) );

		$after = (string) get_option( 'sscribe_export_lock_renew-owned', '' );
		$this->assertNotSame( $before, $after );
		$parts = explode( '|', $after, 3 );
		$this->assertCount( 3, $parts );
		$this->assertSame( $token, $parts[1] );
		$this->assertGreaterThan( time() + 100, (int) $parts[2] );
	}

	public function test_renew_lock_cannot_overwrite_successor_during_conditional_update(): void {
		$manager   = new \SScribe_Export_Lock_Manager();
		$token     = $manager->acquire_lock( 'renew-race', 30, 25 );
		$option    = 'sscribe_export_lock_renew-race';
		$successor = time() . '|successor-token|' . ( time() + 300 );

		$this->assertIsString( $token );
		$GLOBALS['sscribe_test_before_wpdb_option_update'] = static function ( array $where ) use ( $option, $successor ): void {
			if ( $option === ( $where['option_name'] ?? '' ) ) {
				update_option( $option, $successor, false );
			}
		};

		$this->assertFalse( $manager->renew_lock( 'renew-race', $token, 120 ) );
		$this->assertSame( $successor, get_option( $option, false ) );
	}

	public function test_release_requires_owner_token(): void {
		$manager = new \SScribe_Export_Lock_Manager();
		$token   = $manager->acquire_lock( 'test-release' );

		$this->assertIsString( $token );
		$this->assertFalse( $manager->release_lock( 'test-release', 'wrong-token' ) );
		$this->assertIsString( get_option( 'sscribe_export_lock_test-release', false ) );
		$this->assertTrue( $manager->release_lock( 'test-release', $token ) );
		$this->assertFalse( get_option( 'sscribe_export_lock_test-release', false ) );
	}

	public function test_release_handles_missing_or_null_lock(): void {
		$manager = new \SScribe_Export_Lock_Manager();

		$this->assertFalse( $manager->release_lock( 'test-null', null ) );
		$this->assertTrue( $manager->release_lock( 'test-missing', 'token' ) );
	}

	public function test_lock_can_be_reacquired_after_release(): void {
		$manager = new \SScribe_Export_Lock_Manager();
		$first   = $manager->acquire_lock( 'test-ownership' );

		$this->assertIsString( $first );
		$this->assertTrue( $manager->release_lock( 'test-ownership', $first ) );

		$second = $manager->acquire_lock( 'test-ownership' );
		$this->assertIsString( $second );
		$this->assertNotSame( $first, $second );
	}

	public function test_release_cannot_delete_a_successor_lock_after_expiry(): void {
		$manager    = new \SScribe_Export_Lock_Manager();
		$token      = $manager->acquire_lock( 'test-atomic-release', 30, 25 );
		$option     = 'sscribe_export_lock_test-atomic-release';
		$successor  = time() . '|successor-token|' . ( time() + 30 );

		$this->assertIsString( $token );
		$GLOBALS['sscribe_test_before_wpdb_option_delete'] = static function ( array $where ) use ( $option, $successor ): void {
			if ( $option === $where['option_name'] ) {
				update_option( $option, $successor, false );
			}
		};

		$this->assertFalse( $manager->release_lock( 'test-atomic-release', $token ) );
		$this->assertSame( $successor, get_option( $option, false ) );
	}

	public function test_cleanup_expired_locks_cannot_delete_successor(): void {
		$manager   = new \SScribe_Export_Lock_Manager();
		$option    = 'sscribe_export_lock_cleanup-race';
		$expired   = ( time() - 100 ) . '|expired-token|' . ( time() - 1 );
		$successor = time() . '|successor-token|' . ( time() + 30 );
		update_option( $option, $expired, false );

		$GLOBALS['sscribe_test_before_wpdb_option_delete'] = static function ( array $where ) use ( $option, $successor ): void {
			if ( $option === ( $where['option_name'] ?? '' ) ) {
				update_option( $option, $successor, false );
			}
		};

		$this->assertSame( 0, $manager->cleanup_expired_locks() );
		$this->assertSame(
			$successor,
			get_option( $option, false ),
			'Expired-lock cleanup must not delete a successor that replaced the observed row.'
		);
	}

	public function test_discard_lock_removes_database_and_legacy_storage(): void {
		$manager = new \SScribe_Export_Lock_Manager();
		$this->assertNotNull( $manager->acquire_lock( 'test-discard' ) );
		$GLOBALS['sscribe_test_transients']['sscribe_lock_test-discard'] = 'legacy';

		$this->assertTrue( $manager->discard_lock( 'test-discard' ) );
		$this->assertFalse( get_option( 'sscribe_export_lock_test-discard', false ) );
		$this->assertFalse( get_transient( 'sscribe_lock_test-discard' ) );
	}
}
