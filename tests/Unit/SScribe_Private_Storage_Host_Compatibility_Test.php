<?php
/**
 * Real-host compatibility regressions for private storage ownership.
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SScribe_Private_Storage_Host_Compatibility_Test extends TestCase {

	/**
	 * Evaluate the policy used after fileowner() proves a private base is
	 * foreign-owned. The helper intentionally accepts synthetic permission
	 * bits so ACL/container-volume cases can be tested deterministically
	 * without requiring root/chown inside the test runner.
	 */
	private static function foreign_owned_base_is_safe( int $permissions, bool $writable_by_php ): bool {
		$method = ( new \ReflectionClass( \SScribe_Private_Storage::class ) )
			->getMethod( 'foreign_owned_base_permissions_are_safe' );

		return (bool) $method->invoke( null, $permissions, $writable_by_php );
	}

	public function test_acl_writable_foreign_owned_0755_base_is_accepted(): void {
		$this->assertTrue(
			self::foreign_owned_base_is_safe( 0755, true ),
			'A foreign-owned 0755 directory that PHP can write via ACL/container mapping must remain usable.'
		);
	}

	public function test_acl_writable_foreign_owned_0700_base_is_accepted(): void {
		$this->assertTrue(
			self::foreign_owned_base_is_safe( 0700, true ),
			'Mode bits can be owner-only while an ACL grants the PHP process access.'
		);
	}

	public function test_foreign_owned_0755_base_without_php_write_access_is_rejected(): void {
		$this->assertFalse(
			self::foreign_owned_base_is_safe( 0755, false ),
			'A private mode is insufficient when PHP cannot actually write the base.'
		);
	}

	public function test_foreign_owned_group_writable_base_without_sticky_bit_is_rejected(): void {
		$this->assertFalse(
			self::foreign_owned_base_is_safe( 0775, true ),
			'Group-writable foreign-owned bases remain unsafe because another group member may replace children.'
		);
	}

	public function test_foreign_owned_world_writable_base_without_sticky_bit_is_rejected(): void {
		$this->assertFalse(
			self::foreign_owned_base_is_safe( 0777, true ),
			'World-writable foreign-owned bases without sticky deletion protection must remain rejected.'
		);
	}

	public function test_foreign_owned_world_writable_sticky_base_is_accepted(): void {
		$this->assertTrue(
			self::foreign_owned_base_is_safe( 01777, true ),
			'The standard shared-host /tmp mode remains an accepted safe base.'
		);
	}
}
