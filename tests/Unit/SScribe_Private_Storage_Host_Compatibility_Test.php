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

	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	#[\PHPUnit\Framework\Attributes\PreserveGlobalState( false )]
	public function test_intermediate_symlink_inside_private_base_is_rejected(): void {
		$base     = sys_get_temp_dir() . '/sscribe-host-base-' . uniqid();
		$attacker = $base . '/attacker-target';
		$link     = $base . '/sscribe-export-site-pages';

		wp_mkdir_p( $attacker );
		chmod( $base, 0700 );
		chmod( $attacker, 0700 );

		if ( ! @symlink( $attacker, $link ) ) {
			@rmdir( $attacker );
			@rmdir( $base );
			$this->markTestSkipped( 'Symbolic links are unavailable in this environment.' );
		}

		$filter = static function () use ( $base ): array {
			return array( $base );
		};
		add_filter( 'sscribe_private_storage_base_candidates', $filter );

		try {
			$this->assertSame(
				'',
				\SScribe_Private_Storage::get_export_dir(),
				'Any symlink in SScribe-managed path components must be rejected even when its target remains inside the validated base.'
			);
		} finally {
			remove_filter( 'sscribe_private_storage_base_candidates', $filter );
			if ( is_link( $link ) ) {
				@unlink( $link );
			}
			$entries = is_dir( $attacker ) ? ( scandir( $attacker ) ?: array() ) : array();
			foreach ( array_diff( $entries, array( '.', '..' ) ) as $entry ) {
				$path = $attacker . '/' . $entry;
				if ( is_dir( $path ) && ! is_link( $path ) ) {
					$it = new \RecursiveIteratorIterator(
						new \RecursiveDirectoryIterator( $path, \FilesystemIterator::SKIP_DOTS ),
						\RecursiveIteratorIterator::CHILD_FIRST
					);
					foreach ( $it as $child ) {
						$child->isDir() && ! $child->isLink() ? @rmdir( $child->getPathname() ) : @unlink( $child->getPathname() );
					}
					@rmdir( $path );
				} else {
					@unlink( $path );
				}
			}
			@rmdir( $attacker );
			@rmdir( $base );
		}
	}


	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	#[\PHPUnit\Framework\Attributes\PreserveGlobalState( false )]
	public function test_nested_subdirectory_rejects_intermediate_symlink_before_creating_descendants(): void {
		$base     = sys_get_temp_dir() . '/sscribe-subdir-base-' . uniqid();
		$attacker = $base . '/attacker-target';
		wp_mkdir_p( $base );
		wp_mkdir_p( $attacker );
		chmod( $base, 0700 );
		chmod( $attacker, 0700 );

		$filter = static function () use ( $base ): array {
			return array( $base );
		};
		add_filter( 'sscribe_private_storage_base_candidates', $filter );

		$link = '';
		try {
			$root = \SScribe_Private_Storage::get_export_dir();
			$this->assertNotSame( '', $root );

			$link = $root . '/logs';
			if ( ! @symlink( $attacker, $link ) ) {
				$this->markTestSkipped( 'Symbolic links are unavailable in this environment.' );
			}

			$this->assertSame(
				'',
				\SScribe_Private_Storage::get_subdirectory( 'logs/nested' ),
				'An intermediate symlink must be rejected before nested private-storage creation.'
			);
			$this->assertDirectoryDoesNotExist(
				$attacker . '/nested',
				'Rejected intermediate symlinks must not cause writes in their target.'
			);
		} finally {
			remove_filter( 'sscribe_private_storage_base_candidates', $filter );
			if ( '' !== $link && is_link( $link ) ) {
				@unlink( $link );
			}

			if ( is_dir( $base ) ) {
				$it = new \RecursiveIteratorIterator(
					new \RecursiveDirectoryIterator( $base, \FilesystemIterator::SKIP_DOTS ),
					\RecursiveIteratorIterator::CHILD_FIRST
				);
				foreach ( $it as $child ) {
					$child->isDir() && ! $child->isLink()
						? @rmdir( $child->getPathname() )
						: @unlink( $child->getPathname() );
				}
				@rmdir( $base );
			}
		}
	}

}
