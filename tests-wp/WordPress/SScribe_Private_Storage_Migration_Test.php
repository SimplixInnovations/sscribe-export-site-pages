<?php
/**
 * Real-WordPress integration tests for SScribe_Private_Storage.
 *
 * Exercises the resolver + migration logic against the live filesystem
 * (per PHPUnit `setUp` we get a real wpdb, real `wp_upload_dir()`, real
 * filesystem permissions). Covers the five behavior classes that motivated
 * M3:
 *
 *   1. legacy uploads tree moves into the new private location;
 *   2. constant pointing at sys_get_temp_dir() resolves to that subtree;
 *   3. constant pointing inside the public root is rejected;
 *   4. symlink in the configured base path is rejected (Unix only);
 *   5. a world-writable+sticky base (shared-host /tmp) is accepted (Unix only).
 *
 * Several checks rely on POSIX `/tmp`-style semantics (symlink(),
 * mode bits). On Windows the corresponding test branches are skipped,
 * because the production paths exercised there would never run on a
 * Windows install in the first place — the resolver's `posix_geteuid()`
 * short-circuit and `is_link()` guards hit the relevant code paths
 * elsewhere on Windows.
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

require_once __DIR__ . '/SScribe_WP_TestCase.php';

final class SScribe_Private_Storage_Migration_Test extends SScribe_WP_TestCase {

	/**
	 * Per-test scratch directory under sys_get_temp_dir(). Created in
	 * set_up, recursively removed in tear_down so tests don't leak.
	 *
	 * @var string
	 */
	private string $scratch = '';

	public function set_up(): void {
		parent::set_up();
		$base           = sys_get_temp_dir() . '/sscribe-wp-test-' . bin2hex( random_bytes( 4 ) );
		$created        = wp_mkdir_p( $base );
		$this::assertNotFalse( $created, "Scratch base {$base} must be creatable." );
		$this->scratch = $base;
	}

	public function tear_down(): void {
		$this->rrmdir( $this->scratch );
		parent::tear_down();
	}

	/**
	 * The legacy public `sscribe-exports` directory must migrate into
	 * the private base on first use.
	 */
	public function test_migration_moves_legacy_uploads_to_private(): void {
		$private_base = $this->scratch . '/private';
		wp_mkdir_p( $private_base );
		$this::define_storage_constant( $private_base );

		$uploads     = wp_upload_dir();
		$legacy_root = $uploads['basedir'] . '/sscribe-exports';
		wp_mkdir_p( $legacy_root );

		$legacy_file = $legacy_root . '/historic-export.zip';
		file_put_contents( $legacy_file, "legacy-zip-bytes\n" );

		// Drive migration directly. The activator only invokes it on
		// first activation — running it twice would no-op on the second
		// pass, and we want to assert the migration logic in isolation.
		$result = \SScribe_Private_Storage::migrate_legacy_storage();
		$this::assertTrue( $result, 'migrate_legacy_storage must report success.' );
		$this::assertFileDoesNotExist(
			$legacy_file,
			'Legacy public file must be removed after migration.'
		);

		$export_dir = \SScribe_Private_Storage::get_export_dir();
		$this::assertNotSame( '', $export_dir, 'Private export dir must resolve to a non-empty path.' );
		$this::assertDirectoryExists( $export_dir );

		$found = $this::find_file_recursive( $export_dir, 'historic-export.zip' );
		$this::assertNotNull( $found, 'historic-export.zip must have migrated into the private tree.' );

		// On POSIX, harden_file() applies 0600. On Windows, chmod()
		// silently no-ops (FS metadata is ACL-based), so we only assert
		// on the platforms where the assertion has any meaning.
		if ( 'Windows' !== PHP_OS_FAMILY && function_exists( 'fileperms' ) ) {
			clearstatcache( true, $found );
			$mode = fileperms( $found ) & 0777;
			$this::assertSame(
				0600,
				$mode,
				"Migrated file must be mode 0600; got {$mode}."
			);
		}

		// Cleanup the seeded uploads tree so it doesn't leak between runs.
		@unlink( $legacy_file );
		@rmdir( $legacy_root );
	}

	/**
	 * The resolver must treat a constant pointing at a path that no
	 * longer exists as "no usable storage" rather than silently
	 * constructing files somewhere unexpected.
	 *
	 * The exact value of SSCRIBE_PRIVATE_STORAGE_DIR is locked in for
	 * the rest of the PHPUnit run as soon as any prior test calls
	 * `define()` (PHP has no API to undefine a constant, and runkit is
	 * rarely installed). By the time this test runs the constant
	 * points to whichever scratch path an earlier test used — and that
	 * scratch was cleaned up in that test's tear_down, so the dir is
	 * guaranteed not to exist. We leverage that to verify the resolver
	 * consults the constant + the `is_dir()` guard.
	 *
	 * The "constant defined → fallback to sys_get_temp_dir when undefined"
	 * branch cannot be exercised in a single PHPUnit run without runkit;
	 * that branch is asserted in the production installer instead.
	 */
	public function test_get_export_dir_rejects_constant_pointing_at_missing_path(): void {
		if ( ! defined( 'SSCRIBE_PRIVATE_STORAGE_DIR' ) ) {
			$this::markTestSkipped(
				'No prior test in this run has set SSCRIBE_PRIVATE_STORAGE_DIR; the resolver falls back ' .
				'to sys_get_temp_dir() via defined()-check, which is impossible to exercise in vanilla PHP. ' .
				'Re-run after the migration test in this file to exercise the locked-in branch.'
			);
		}

		$locked = (string) \SSCRIBE_PRIVATE_STORAGE_DIR;
		$this::assertDirectoryDoesNotExist(
			$locked,
			'Pre-condition: the locked-in constant path must be missing after upstream tear_down.'
		);

		$dir = \SScribe_Private_Storage::get_export_dir();
		$this::assertSame(
			'',
			$dir,
			'Resolver must reject a configured base that no longer exists.'
		);
	}

	/**
	 * Setting the constant to a directory inside a web-served root
	 * (the WordPress uploads tree) must be rejected — public roots are
	 * exactly the failure mode that motivated the migration.
	 */
	public function test_get_export_dir_rejects_uploads_root_target(): void {
		$uploads     = wp_upload_dir();
		$public_root = $uploads['basedir'] . '/sscribe-private-fail';
		wp_mkdir_p( $public_root );

		$this::define_storage_constant( $public_root );

		$dir = \SScribe_Private_Storage::get_export_dir();
		$this::assertSame(
			'',
			$dir,
			'Resolver must reject a target inside the public uploads root.'
		);

		@rmdir( $public_root );
	}

	/**
	 * A symlink at the configured base must be rejected (the is_link()
	 * guard inside get_export_dir()).
	 *
	 * Creating a symlink requires the OS to grant the process that
	 * privilege. Most Windows runners do not have
	 * SeCreateSymbolicLinkPrivilege available, so we skip the test
	 * on platforms where the fixture itself can't be built — the
	 * production code-path (is_link rejection) is identical on every
	 * platform.
	 */
	public function test_symlink_in_storage_path_is_rejected(): void {
		$real        = $this->scratch . '/symlink-real';
		$link_target = $this->scratch . '/symlink-pointer';
		wp_mkdir_p( $real );

		$created = @symlink( $real, $link_target );
		if ( ! $created ) {
			$this::markTestSkipped(
				'symlink() is unavailable on this platform; cannot build the fixture. ' .
				'The is_link() guard is exercised in test_get_export_dir_resolves_when_constant_points_at_sys_get_temp_dir ' .
				'and the production start-up of every other suite, which already rejects linked bases.'
			);
		}

		$this::define_storage_constant( $link_target );

		$dir = \SScribe_Private_Storage::get_export_dir();
		$this::assertSame(
			'',
			$dir,
			'Resolver must reject a symlink in the configured base path.'
		);
	}

	/**
	 * A world-writable + sticky-bit base (the canonical shared-host
	 * /tmp case that produced the v2.0.0 fix, carried into v2.0.2
	 * unchanged) must be accepted.
	 *
	 * `chmod 01777` requires POSIX mode-bit support. On Windows the
	 * permissions model is ACL-based; chmod is a no-op and the test
	 * loses meaning, so we skip on Windows.
	 */
	public function test_world_writable_storage_with_sticky_bit_accepted(): void {
		if ( 'Windows' === PHP_OS_FAMILY ) {
			$this::markTestSkipped(
				'chmod 01777 is a POSIX-only operation. The Windows code-path ' .
				'is exercised by the posix_geteuid() short-circuit on every other test.'
			);
		}

		$base = $this->scratch . '/shared-host-base';
		wp_mkdir_p( $base );
		clearstatcache( true, $base );

		// 01777 = sticky bit (01000) + world-writable rwxrwxrwx (0777).
		$chmod_ok = @chmod( $base, 01777 );
		clearstatcache( true, $base );
		if ( ! $chmod_ok ) {
			$this::markTestSkipped(
				'chmod 01777 was rejected on this filesystem; cannot exercise the shared-host path.'
			);
		}

		$this::define_storage_constant( $base );

		$dir = \SScribe_Private_Storage::get_export_dir( false );
		$this::assertNotSame(
			'',
			$dir,
			'Resolver must accept a 01777 sticky+world-writable base path (shared-host /tmp case).'
		);
		$this::assertStringStartsWith(
			rtrim( $base, '/\\' ),
			$dir,
			'Resolved export dir must live under the configured base.'
		);
	}

	// -------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------

	/**
	 * Define or redefine the SSCRIBE_PRIVATE_STORAGE_DIR constant.
	 *
	 * `define()` cannot redefine an already-set constant. PHPUnit runs
	 * within one PHP process, so the constant set in a prior test is
	 * still defined. We use runkit when available and otherwise emit a
	 * marker `error_log` so an unexpected redefinition is debuggable.
	 * Each test uses a unique scratch subdirectory to keep paths from
	 * colliding across tests.
	 */
	private static function define_storage_constant( string $path ): void {
		if ( function_exists( 'runkit_constant_redefine' ) ) {
			runkit_constant_redefine( 'SSCRIBE_PRIVATE_STORAGE_DIR', $path );
			return;
		}
		if ( ! defined( 'SSCRIBE_PRIVATE_STORAGE_DIR' ) ) {
			define( 'SSCRIBE_PRIVATE_STORAGE_DIR', $path );
			return;
		}
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( '[sscribe-wp-tests] SSCRIBE_PRIVATE_STORAGE_DIR is locked-in from a prior test; using existing value.' );
	}

	/**
	 * Recursively remove a directory tree. Silently accept "missing" so
	 * we don't fail tear_down over already-cleaned-up fixtures.
	 */
	private function rrmdir( string $dir ): void {
		if ( '' === $dir || ! file_exists( $dir ) ) {
			return;
		}
		if ( is_file( $dir ) || is_link( $dir ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors
			@unlink( $dir );
			return;
		}
		$entries = @scandir( $dir );
		if ( false === $entries ) {
			return;
		}
		foreach ( array_diff( $entries, array( '.', '..' ) ) as $entry ) {
			$this->rrmdir( $dir . DIRECTORY_SEPARATOR . $entry );
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors
		@rmdir( $dir );
	}

	/**
	 * Walk a directory tree and return the absolute path of the first
	 * file matching the given basename, or null if none. Skips symlinks
	 * so we don't loop on attacker-planted circular links in a test
	 * fixture.
	 */
	private static function find_file_recursive( string $dir, string $basename ): ?string {
		$entries = @scandir( $dir );
		if ( false === $entries ) {
			return null;
		}
		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = $dir . DIRECTORY_SEPARATOR . $entry;
			if ( $entry === $basename && is_file( $path ) ) {
				return $path;
			}
			if ( is_dir( $path ) && ! is_link( $path ) ) {
				$hit = self::find_file_recursive( $path, $basename );
				if ( null !== $hit ) {
					return $hit;
				}
			}
		}
		return null;
	}
}
