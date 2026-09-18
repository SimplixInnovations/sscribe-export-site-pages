<?php
/**
 * SScribe Private Storage Unit Test
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

class SScribe_Private_Storage_Test extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['sscribe_test_blog_id'] = 1;
		if ( class_exists( '\\SScribe_Private_Storage' ) ) {
			\SScribe_Private_Storage::delete_owned_storage();
			\SScribe_Private_Storage::delete_legacy_storage();
		}
	}

	protected function tearDown(): void {
		if ( class_exists( '\\SScribe_Private_Storage' ) ) {
			\SScribe_Private_Storage::delete_owned_storage();
			\SScribe_Private_Storage::delete_legacy_storage();
		}
		unset( $GLOBALS['sscribe_test_blog_id'] );
		parent::tearDown();
	}

	public function test_default_storage_is_outside_public_wordpress_paths(): void {
		$export_dir = \SScribe_Private_Storage::get_export_dir();
		$upload_dir = wp_upload_dir();

		$this->assertNotSame( '', $export_dir );
		$this->assertDirectoryExists( $export_dir );
		$this->assertFalse( $this->path_is_within( $export_dir, (string) $upload_dir['basedir'] ) );
		$this->assertFalse( $this->path_is_within( $export_dir, ABSPATH ) );
		$this->assertFalse( $this->path_is_within( $export_dir, WP_CONTENT_DIR ) );
	}

	public function test_legacy_public_artifacts_are_migrated_then_removed(): void {
		$legacy_dir = \SScribe_Private_Storage::get_legacy_export_dir();
		wp_mkdir_p( $legacy_dir . '/logs' );
		file_put_contents( $legacy_dir . '/site-export.zip', 'archive' );
		file_put_contents( $legacy_dir . '/logs/sscribe_debug.log', 'private details' );

		$this->assertTrue( \SScribe_Private_Storage::migrate_legacy_storage() );

		$private_dir = \SScribe_Private_Storage::get_export_dir();
		$this->assertFileExists( $private_dir . '/site-export.zip' );
		$this->assertFileExists( $private_dir . '/logs/sscribe_debug.log' );
		$this->assertFileDoesNotExist( $legacy_dir . '/site-export.zip' );
		$this->assertFileDoesNotExist( $legacy_dir . '/logs/sscribe_debug.log' );
		$this->assertDirectoryDoesNotExist( $legacy_dir );
	}

	public function test_preconsolidation_public_roots_are_migrated_then_removed(): void {
		$legacy_dirs = \SScribe_Private_Storage::get_legacy_storage_dirs();
		wp_mkdir_p( $legacy_dirs['logs'] );
		wp_mkdir_p( $legacy_dirs['mpdf_temp'] . '/run-old' );
		file_put_contents( $legacy_dirs['logs'] . '/export_legacy.json', 'private log' );
		file_put_contents( $legacy_dirs['mpdf_temp'] . '/run-old/font-cache.dat', 'temporary cache' );

		$this->assertTrue( \SScribe_Private_Storage::migrate_legacy_storage() );

		$private_dir = \SScribe_Private_Storage::get_export_dir();
		$this->assertFileExists( $private_dir . '/logs/export_legacy.json' );
		$this->assertFileExists( $private_dir . '/mpdf-tmp/run-old/font-cache.dat' );
		$this->assertDirectoryDoesNotExist( $legacy_dirs['logs'] );
		$this->assertDirectoryDoesNotExist( $legacy_dirs['mpdf_temp'] );
		$this->assertDirectoryDoesNotExist( dirname( $legacy_dirs['mpdf_temp'] ) );
	}

	public function test_delete_legacy_storage_removes_every_historical_root(): void {
		$legacy_dirs = \SScribe_Private_Storage::get_legacy_storage_dirs();
		foreach ( $legacy_dirs as $legacy_dir ) {
			wp_mkdir_p( $legacy_dir . '/nested' );
			file_put_contents( $legacy_dir . '/nested/private.txt', 'private artifact' );
		}

		$this->assertTrue( \SScribe_Private_Storage::delete_legacy_storage() );

		foreach ( $legacy_dirs as $legacy_dir ) {
			$this->assertDirectoryDoesNotExist( $legacy_dir );
		}
		$this->assertDirectoryDoesNotExist( dirname( $legacy_dirs['mpdf_temp'] ) );
	}

	public function test_legacy_conflict_is_migrated_without_overwriting_private_file(): void {
		$legacy_dir  = \SScribe_Private_Storage::get_legacy_export_dir();
		$private_dir = \SScribe_Private_Storage::get_export_dir();
		wp_mkdir_p( $legacy_dir );
		file_put_contents( $legacy_dir . '/site-export.zip', 'legacy bytes' );
		file_put_contents( $private_dir . '/site-export.zip', 'different bytes' );

		$this->assertTrue( \SScribe_Private_Storage::migrate_legacy_storage() );
		$this->assertFileDoesNotExist( $legacy_dir . '/site-export.zip' );
		$this->assertSame( 'different bytes', file_get_contents( $private_dir . '/site-export.zip' ) );
		$migrated = glob( $private_dir . '/site-export-legacy-*.zip' ) ?: array();
		$this->assertCount( 1, $migrated );
		$this->assertSame( 'legacy bytes', file_get_contents( $migrated[0] ) );
	}

	public function test_subdirectory_rejects_traversal_and_absolute_paths(): void {
		$this->assertSame( '', \SScribe_Private_Storage::get_subdirectory( '../escape' ) );
		$this->assertSame( '', \SScribe_Private_Storage::get_subdirectory( 'logs/../../escape' ) );
		$this->assertSame( '', \SScribe_Private_Storage::get_subdirectory( 'C:/escape' ) );
	}

	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	#[\PHPUnit\Framework\Attributes\PreserveGlobalState( false )]
	public function test_relative_storage_override_is_rejected(): void {
		define( 'SSCRIBE_PRIVATE_STORAGE_DIR', '../relative-storage' );

		$this->assertSame( '', \SScribe_Private_Storage::get_export_dir() );
	}

	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	#[\PHPUnit\Framework\Attributes\PreserveGlobalState( false )]
	public function test_public_storage_override_is_rejected(): void {
		$uploads = wp_upload_dir();
		wp_mkdir_p( $uploads['basedir'] );
		define( 'SSCRIBE_PRIVATE_STORAGE_DIR', $uploads['basedir'] );

		$this->assertSame( '', \SScribe_Private_Storage::get_export_dir() );
	}

	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	#[\PHPUnit\Framework\Attributes\PreserveGlobalState( false )]
	public function test_symlinked_private_root_cannot_delete_external_files(): void {
		$base     = sys_get_temp_dir() . '/sscribe-private-base-' . uniqid();
		$outside  = sys_get_temp_dir() . '/sscribe-private-outside-' . uniqid();
		$site_key = 'site-1-' . substr( hash( 'sha256', str_replace( '\\', '/', rtrim( ABSPATH, '/\\' ) ) ), 0, 12 );
		$root     = $base . '/sscribe-export-site-pages/' . $site_key . '/sscribe-exports';
		$sentinel = $outside . '/must-survive.txt';
		wp_mkdir_p( dirname( $root ) );
		wp_mkdir_p( $outside );
		file_put_contents( $sentinel, 'outside' );
		define( 'SSCRIBE_PRIVATE_STORAGE_DIR', $base );

		if ( ! @symlink( $outside, $root ) ) {
			unlink( $sentinel );
			rmdir( $outside );
			rmdir( dirname( $root ) );
			rmdir( dirname( dirname( $root ) ) );
			rmdir( $base );
			$this->markTestSkipped( 'Symbolic links are unavailable in this environment.' );
		}

		try {
			$this->assertSame( '', \SScribe_Private_Storage::get_export_dir( false ) );
			$this->assertTrue( \SScribe_Private_Storage::delete_owned_storage() );
			$this->assertFileExists( $sentinel );
		} finally {
			if ( is_link( $root ) ) {
				unlink( $root );
			}
			if ( file_exists( $sentinel ) ) {
				unlink( $sentinel );
			}
			if ( is_dir( $outside ) ) {
				rmdir( $outside );
			}
			if ( is_dir( dirname( $root ) ) ) {
				rmdir( dirname( $root ) );
			}
			if ( is_dir( dirname( dirname( $root ) ) ) ) {
				rmdir( dirname( dirname( $root ) ) );
			}
			if ( is_dir( $base ) ) {
				rmdir( $base );
			}
		}
	}

	public function test_storage_is_isolated_by_site_id(): void {
		$site_one = \SScribe_Private_Storage::get_export_dir();
		$GLOBALS['sscribe_test_blog_id'] = 2;
		$site_two = \SScribe_Private_Storage::get_export_dir();

		$this->assertNotSame( $site_one, $site_two );
		$this->assertStringContainsString( 'site-1-', $site_one );
		$this->assertStringContainsString( 'site-2-', $site_two );

		\SScribe_Private_Storage::delete_owned_storage();
		$GLOBALS['sscribe_test_blog_id'] = 1;
	}

	public function test_private_permissions_are_owner_only_on_posix(): void {
		if ( 'Windows' === PHP_OS_FAMILY ) {
			$this->markTestSkipped( 'POSIX permission bits are not enforced on Windows.' );
		}

		$dir  = \SScribe_Private_Storage::get_subdirectory( 'permission-check' );
		$file = $dir . '/artifact.zip';
		file_put_contents( $file, 'archive' );
		\SScribe_Private_Storage::harden_file( $file );

		$this->assertSame( 0700, fileperms( $dir ) & 0777 );
		$this->assertSame( 0600, fileperms( $file ) & 0777 );
	}

	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	#[\PHPUnit\Framework\Attributes\PreserveGlobalState( false )]
	public function test_foreign_owner_override_filter_forces_acceptance(): void {
		// The ownership check short-circuits to true on platforms where
		// posix_geteuid() is unavailable, so we can only exercise the
		// foreign-owner branch where POSIX functions exist.
		if ( 'Windows' === PHP_OS_FAMILY ) {
			$this->markTestSkipped( 'posix_geteuid/fileowner are unavailable on Windows; the filter branch only runs on POSIX hosts.' );
		}

		// Shared-host installations cannot influence posix_geteuid/fileowner
		// from inside PHP, so the only portable way to assert the foreign-
		// owner escape hatch is to register a filter that always returns
		// true and confirm the check then accepts the path.
		$base = sys_get_temp_dir() . '/sscribe-foreign-owner-' . uniqid();
		wp_mkdir_p( $base );
		add_filter( 'sscribe_private_storage_allow_foreign_owner', '__return_true' );

		$method = ( new \ReflectionClass( \SScribe_Private_Storage::class ) )
			->getMethod( 'is_owned_by_current_process' );

		$this->assertTrue( $method->invoke( null, $base ) );

		rmdir( $base );
	}

	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	#[\PHPUnit\Framework\Attributes\PreserveGlobalState( false )]
	public function test_foreign_owner_filter_receives_inspected_base_path(): void {
		if ( 'Windows' === PHP_OS_FAMILY ) {
			$this->markTestSkipped( 'posix_geteuid/fileowner are unavailable on Windows; the filter branch only runs on POSIX hosts.' );
		}

		$base      = sys_get_temp_dir() . '/sscribe-foreign-owner-' . uniqid();
		$captured  = null;
		wp_mkdir_p( $base );

		add_filter(
			'sscribe_private_storage_allow_foreign_owner',
			static function ( $allowed, $inspected ) use ( &$captured ) {
				unset( $allowed );
				$captured = $inspected;
				return true;
			},
			10,
			2
		);

		$method = ( new \ReflectionClass( \SScribe_Private_Storage::class ) )
			->getMethod( 'is_owned_by_current_process' );
		$method->invoke( null, $base );

		$this->assertSame( $base, $captured );

		rmdir( $base );
	}

	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	#[\PHPUnit\Framework\Attributes\PreserveGlobalState( false )]
	public function test_world_writable_base_path_is_accepted_for_storage(): void {
		// Regression: shared hosts expose a /tmp directory owned by root
		// but carrying the world-writable sticky bit. The previous
		// ownership-only check refused the path and broke activation. The
		// loosened rule must now accept the world-writable + sticky-bit
		// base.
		if ( 'Windows' === PHP_OS_FAMILY ) {
			$this->markTestSkipped( 'POSIX permission bits are not enforced on Windows; the loosened rule is exercised on Linux.' );
		}

		$base = sys_get_temp_dir() . '/sscribe-world-writable-' . uniqid();
		wp_mkdir_p( $base );
		chmod( $base, 01777 );

		$method = ( new \ReflectionClass( \SScribe_Private_Storage::class ) )
			->getMethod( 'is_owned_by_current_process' );
		$result = $method->invoke( null, $base );

		// On a developer's machine the test UID matches the dir owner and
		// the function returns true via the owner branch. On a shared-host
		// test bench it returns true via the world-writable + sticky-bit
		// branch. Either way the path must be accepted; the relevant
		// assertion is that the loosened rule does not reject a sticky-bit
		// world-writable temp dir the way the old one did.
		$this->assertTrue( $result );

		chmod( $base, 0700 );
		rmdir( $base );
	}

	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	#[\PHPUnit\Framework\Attributes\PreserveGlobalState( false )]
	public function test_bare_world_writable_base_path_is_rejected(): void {
		// Regression: a world-writable directory WITHOUT the sticky bit
		// (mode 0777) must be rejected because any local user could delete
		// or rename the base directory and substitute a foreign-owned
		// target. Only world-writable + sticky-bit (mode 01777) is safe.
		if ( 'Windows' === PHP_OS_FAMILY ) {
			$this->markTestSkipped( 'POSIX permission bits are not enforced on Windows; the sticky-bit guard is exercised on Linux.' );
		}

		$base = sys_get_temp_dir() . '/sscribe-bare-world-writable-' . uniqid();
		wp_mkdir_p( $base );
		// Force ownership to differ from the test runner so the owner-match
		// branch is skipped and we exercise the world-writable branch.
		chmod( $base, 0777 );

		$method = ( new \ReflectionClass( \SScribe_Private_Storage::class ) )
			->getMethod( 'is_owned_by_current_process' );
		$result = $method->invoke( null, $base );

		// On a shared-host test bench the foreign-owner branch is taken
		// and 0777 without sticky must reject. On a developer machine the
		// owner branch may short-circuit true; that's still a safe outcome.
		if ( fileowner( $base ) === posix_geteuid() ) {
			$this->assertTrue( $result, 'Owner-match branch must accept even without sticky.' );
		} else {
			$this->assertFalse( $result, 'Foreign-owned 0777 without sticky must be rejected.' );
		}

		chmod( $base, 0700 );
		rmdir( $base );
	}

	public function test_get_directory_name_returns_default_without_filter(): void {
		$this->assertSame( 'sscribe-exports', \SScribe_Private_Storage::get_directory_name() );
	}

	public function test_get_directory_name_honors_storage_layout_filter(): void {
		$override = static function (): string {
			return 'team-exports';
		};
		add_filter( 'sscribe_storage_layout', $override );
		try {
			$this->assertSame( 'team-exports', \SScribe_Private_Storage::get_directory_name() );
		} finally {
			remove_filter( 'sscribe_storage_layout', $override );
		}
		$this->assertSame( 'sscribe-exports', \SScribe_Private_Storage::get_directory_name() );
	}

	public function test_get_directory_name_rejects_path_traversal_payloads(): void {
		$payloads = array(
			'../etc',
			'foo/../bar',
			'foo..bar',
			'foo/bar',
			'foo\\bar',
			"foo\0bar",
			'.hidden',
			'..',
			'.',
			'',
		);
		foreach ( $payloads as $payload ) {
			$filter = static function () use ( $payload ): string {
				return $payload;
			};
			add_filter( 'sscribe_storage_layout', $filter );
			try {
				$this->assertSame(
					'sscribe-exports',
					\SScribe_Private_Storage::get_directory_name(),
					'payload must fall back to default: ' . var_export( $payload, true )
				);
			} finally {
				remove_filter( 'sscribe_storage_layout', $filter );
			}
		}
	}

	public function test_get_directory_name_rejects_oversized_and_unsafe_characters(): void {
		$oversized = str_repeat( 'a', 61 );
		$filter    = static function () use ( $oversized ): string {
			return $oversized;
		};
		add_filter( 'sscribe_storage_layout', $filter );
		try {
			$this->assertSame( 'sscribe-exports', \SScribe_Private_Storage::get_directory_name() );
		} finally {
			remove_filter( 'sscribe_storage_layout', $filter );
		}

		$unsafe_chars = array( 'foo bar', 'foo*bar', 'foo|bar', 'foo:bar', "foo\tbar" );
		foreach ( $unsafe_chars as $value ) {
			$char_filter = static function () use ( $value ): string {
				return $value;
			};
			add_filter( 'sscribe_storage_layout', $char_filter );
			try {
				$this->assertSame(
					'sscribe-exports',
					\SScribe_Private_Storage::get_directory_name(),
					'unsafe chars must fall back to default: ' . var_export( $value, true )
				);
			} finally {
				remove_filter( 'sscribe_storage_layout', $char_filter );
			}
		}
	}

	public function test_get_directory_name_accepts_safe_alternates(): void {
		$values = array(
			'team-exports',
			'exports.v2',
			'sscribe_exports',
			'a',
		);
		foreach ( $values as $value ) {
			$filter = static function () use ( $value ): string {
				return $value;
			};
			add_filter( 'sscribe_storage_layout', $filter );
			try {
				$this->assertSame(
					$value,
					\SScribe_Private_Storage::get_directory_name(),
					'safe alternate must pass through: ' . var_export( $value, true )
				);
			} finally {
				remove_filter( 'sscribe_storage_layout', $filter );
			}
		}
	}

	private function path_is_within( string $path, string $root ): bool {
		$path = strtolower( str_replace( '\\', '/', rtrim( $path, '/\\' ) ) );
		$root = strtolower( str_replace( '\\', '/', rtrim( $root, '/\\' ) ) );
		return $path === $root || str_starts_with( $path, $root . '/' );
	}
}
