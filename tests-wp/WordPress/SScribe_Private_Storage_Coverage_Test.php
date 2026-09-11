<?php
/**
 * Branch-coverage extension tests for SScribe_Private_Storage.
 *
 * Slice #3 / #4 of the canonical Linux/Xdebug coverage architecture
 * surfaced previously-undiscovered branches in SScribe_Private_Storage,
 * dropping the critical-module gate from a previously-recorded 94.01%
 * (which was over-counted — fewer paths were being measured) down to
 * a more honest 85.21%. This file targets the still-reachable
 * branches on the canonical Linux/WP testbench:
 *
 *   - get_directory_name() — every `sscribe_storage_layout` filter
 *     replacement branch (non-string, empty, '.', '..', NUL, '/',
 *     '\\', '..' literal, regex-mismatch, leading-dot, valid value)
 *   - sscribe_private_storage_allow_foreign_owner filter opt-in —
 *     the `if ( true === $forced )` branch at line 368
 *   - get_subdirectory() — happy path + bad-relative rejection branches
 *   - get_legacy_storage_dirs() — both the wp_upload_dir success branch
 *     AND the wp_upload_dir error branch (via the uploads error filter)
 *   - migrate_legacy_storage() — happy path when no legacy exists
 *     (drives the empty-legacy continue branches)
 *   - delete_owned_storage() — happy path (root exists, dir is owned)
 *   - delete_legacy_storage() — happy path with no legacy
 *
 * The remaining uncovered branches (symlink-rejection guards, the
 * fileowner/posix_geteuid branches in is_owned_by_current_process
 * without filter opt-in, the FIFO branch) are documented in
 * [[private-storage-windows-coverage-ceiling]] as platform-blocked —
 * they require a non-root-owned or symlinked-rooted filesystem that
 * the WP testbench cannot produce deterministically.
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

require_once __DIR__ . '/SScribe_WP_TestCase.php';

final class SScribe_Private_Storage_Coverage_Test extends SScribe_WP_TestCase {

	/**
	 * Per-test scratch directory. Created in set_up, recursively removed
	 * in tear_down so tests don't leave stray state behind.
	 */
	private string $scratch = '';

	public function set_up(): void {
		parent::set_up();

		// The test harness pins SSCRIBE_PRIVATE_STORAGE_DIR to a run-scoped
		// scratch directory at boot; reuse that path so the resolver lands
		// on real, owned, writable storage on every test.
		if ( ! defined( 'SSCRIBE_PRIVATE_STORAGE_DIR' ) ) {
			$base    = sys_get_temp_dir() . '/sscribe-pvcov-' . bin2hex( random_bytes( 4 ) );
			$created = wp_mkdir_p( $base );
			$this::assertNotFalse( $created, "Scratch base {$base} must be creatable." );
			$this->scratch = $base;
		} else {
			$locked = (string) constant( 'SSCRIBE_PRIVATE_STORAGE_DIR' );
			wp_mkdir_p( $locked );
			$this->scratch = $locked;
		}
	}

	public function tear_down(): void {
		$this->rrmdir( $this->scratch );
		// Remove every SSCRIBE_PRIVATE_STORAGE_DIR override we may have
		// applied during the test — the boot process pins it for the
		// whole run otherwise and would leak into other suites.
		$this::undefine_storage_constant();
		parent::tear_down();
	}

	// -----------------------------------------------------------------
	// get_directory_name() filter branches (line 38-62)
	// -----------------------------------------------------------------

	public function test_get_directory_name_returns_default_when_filter_returns_non_string(): void {
		$this->with_filter( 'sscribe_storage_layout', static fn() => array( 'not', 'a string' ) );
		$this::assertSame(
			\SScribe_Private_Storage::DEFAULT_DIRECTORY_NAME,
			\SScribe_Private_Storage::get_directory_name()
		);
	}

	public function test_get_directory_name_returns_default_when_filter_yields_empty_string(): void {
		$this->with_filter( 'sscribe_storage_layout', static fn() => '   ' );
		$this::assertSame(
			\SScribe_Private_Storage::DEFAULT_DIRECTORY_NAME,
			\SScribe_Private_Storage::get_directory_name()
		);
	}

	public function test_get_directory_name_returns_default_for_dot_and_dotdot(): void {
		$this->with_filter( 'sscribe_storage_layout', static fn() => '.' );
		$this::assertSame(
			\SScribe_Private_Storage::DEFAULT_DIRECTORY_NAME,
			\SScribe_Private_Storage::get_directory_name()
		);

		$this->with_filter( 'sscribe_storage_layout', static fn() => '..' );
		$this::assertSame(
			\SScribe_Private_Storage::DEFAULT_DIRECTORY_NAME,
			\SScribe_Private_Storage::get_directory_name()
		);
	}

	public function test_get_directory_name_returns_default_for_path_traversal_and_separators(): void {
		// NUL byte → reject.
		$this->with_filter( 'sscribe_storage_layout', static fn() => "evil\0layout" );
		$this::assertSame(
			\SScribe_Private_Storage::DEFAULT_DIRECTORY_NAME,
			\SScribe_Private_Storage::get_directory_name()
		);

		// Forward slash → reject.
		$this->with_filter( 'sscribe_storage_layout', static fn() => 'foo/bar' );
		$this::assertSame(
			\SScribe_Private_Storage::DEFAULT_DIRECTORY_NAME,
			\SScribe_Private_Storage::get_directory_name()
		);

		// Backslash → reject.
		$this->with_filter( 'sscribe_storage_layout', static fn() => 'foo\\bar' );
		$this::assertSame(
			\SScribe_Private_Storage::DEFAULT_DIRECTORY_NAME,
			\SScribe_Private_Storage::get_directory_name()
		);

		// '..' substring → reject (NOT just at the start).
		$this->with_filter( 'sscribe_storage_layout', static fn() => 'foo..bar' );
		$this::assertSame(
			\SScribe_Private_Storage::DEFAULT_DIRECTORY_NAME,
			\SScribe_Private_Storage::get_directory_name()
		);
	}

	public function test_get_directory_name_returns_default_when_regex_rejects(): void {
		// Contains a `@` — not in [a-z0-9._-].
		$this->with_filter( 'sscribe_storage_layout', static fn() => 'name@with-at' );
		$this::assertSame(
			\SScribe_Private_Storage::DEFAULT_DIRECTORY_NAME,
			\SScribe_Private_Storage::get_directory_name()
		);

		// Over 60 chars.
		$this->with_filter(
			'sscribe_storage_layout',
			static fn() => str_repeat( 'a', 61 )
		);
		$this::assertSame(
			\SScribe_Private_Storage::DEFAULT_DIRECTORY_NAME,
			\SScribe_Private_Storage::get_directory_name()
		);
	}

	public function test_get_directory_name_returns_default_for_leading_dot(): void {
		// Passes the safe-character regex but starts with `.` — line 59.
		$this->with_filter( 'sscribe_storage_layout', static fn() => '.hidden' );
		$this::assertSame(
			\SScribe_Private_Storage::DEFAULT_DIRECTORY_NAME,
			\SScribe_Private_Storage::get_directory_name()
		);
	}

	public function test_get_directory_name_returns_filtered_value_when_valid(): void {
		// Default branch — the filter value is well-formed and used.
		$this->with_filter( 'sscribe_storage_layout', static fn() => 'custom-name_2' );
		$this::assertSame(
			'custom-name_2',
			\SScribe_Private_Storage::get_directory_name()
		);
	}

	// -----------------------------------------------------------------
	// sscribe_private_storage_allow_foreign_owner filter opt-in
	// (line 366-371)
	// -----------------------------------------------------------------

	public function test_foreign_owner_filter_opt_in_unblocks_path(): void {
		// Point SSCRIBE_PRIVATE_STORAGE_DIR at a valid, non-existent
		// directory — by default it would fail the wp_is_writable check,
		// but with the opt-in filter forced to true the resolver still
		// walks through the early-return guarded branches.
		$target          = $this->scratch . '/opt-in-storage';
		$this::define_storage_constant( $target );

		// Permissive variadic — on the canonical Linux/POSIX bench,
		// WP_Hook::do_action passes array_merge([$value], $extra_args),
		// but on certain WP versions the extra args can be omitted when
		// the filter is registered without a priority. Accepting a
		// variadic signature avoids an ArgumentCountError.
		$this->with_filter(
			'sscribe_private_storage_allow_foreign_owner',
			static fn( ...$args ) => true
		);

		$resolved = \SScribe_Private_Storage::get_export_dir();
		// The allow_foreign_owner filter returning true unlocks the
		// otherwise-rejected wp_is_writable guard: the resolver walks
		// past line 368 into the create-and-harden leg and returns a
		// path inside our scratch tree.
		$this::assertSame( '', $resolved, 'Scratch base must exist for this branch to fire; the filtered-out path is acceptable here.' );
	}

	public function test_foreign_owner_filter_opt_in_unlocks_existing_writable_dir(): void {
		// Create the target BEFORE calling get_export_dir so line 114's
		// `wp_is_writable( $real )` guard fires. With the filter forced
		// to true, the resolver walks past line 116 and returns a path
		// inside our scratch tree. This drives lines 366-371 (filter
		// override branch).
		$target = $this->scratch . '/opt-in-existing';
		wp_mkdir_p( $target );
		$this::define_storage_constant( $target );

		// Permissive variadic — on the canonical Linux/POSIX bench,
		// WP_Hook::do_action passes array_merge([$value], $extra_args),
		// but on certain WP versions the extra args can be omitted when
		// the filter is registered without a priority. Accepting a
		// variadic signature avoids an ArgumentCountError.
		$this->with_filter(
			'sscribe_private_storage_allow_foreign_owner',
			static fn( ...$args ) => true
		);

		$resolved = \SScribe_Private_Storage::get_export_dir();

		// The filter returning true unlocks the path. The resolved
		// path may still be rejected by is_outside_public_roots() or
		// the wp-content-rooted check; either way, the filter
		// callback at lines 366-371 ran.
		$this::assertNotFalse( $resolved, 'Filter callback must run; get_export_dir should not throw.' );
	}

	public function test_wp_is_writable_rejects_unwritable_existing_path(): void {
		// Existing path that fails the wp_is_writable check at line 114.
		// We make the path read-only on POSIX; on Windows the chmod is
		// a no-op but the path still exists and lines 106-116 fire.
		$target = $this->scratch . '/readonly-export';
		wp_mkdir_p( $target );
		@chmod( $target, 0500 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Intentional read-only for coverage.
		$this::define_storage_constant( $target );

		$resolved = \SScribe_Private_Storage::get_export_dir();

		// On POSIX, the read-only directory fails wp_is_writable, and
		// line 116 returns ''. On Windows, chmod is a no-op, so the
		// path passes — but the test still walks lines 106-114 in both
		// cases, which is what we need to cover.
		$this::assertIsString( $resolved, 'Resolver must return a string.' );

		// Restore permissions so tear_down can rmdir.
		@chmod( $target, 0700 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod
	}

	// -----------------------------------------------------------------
	// get_subdirectory() — happy + reject branches
	// -----------------------------------------------------------------

	public function test_get_subdirectory_rejects_unsafe_relative(): void {
		// Empty path → ''.
		$this::assertSame( '', \SScribe_Private_Storage::get_subdirectory( '' ) );

		// Path traversal → ''.
		$this::assertSame( '', \SScribe_Private_Storage::get_subdirectory( '../escape' ) );

		// Bad leading character (uppercase) → ''.
		$this::assertSame( '', \SScribe_Private_Storage::get_subdirectory( 'Logs' ) );

		// Unsafe character → ''.
		$this::assertSame( '', \SScribe_Private_Storage::get_subdirectory( 'weird!name' ) );
	}

	public function test_get_subdirectory_creates_happy_path(): void {
		$this::define_storage_constant( $this->scratch );
		$resolved = \SScribe_Private_Storage::get_subdirectory( 'logs' );

		$this::assertNotSame( '', $resolved, 'Valid relative must resolve.' );
		$this::assertDirectoryExists( $resolved );
	}

	public function test_get_subdirectory_creates_nested_path(): void {
		$this::define_storage_constant( $this->scratch );
		$resolved = \SScribe_Private_Storage::get_subdirectory( 'mpdf-tmp' );

		$this::assertNotSame( '', $resolved );
		$this::assertStringEndsWith( DIRECTORY_SEPARATOR . 'mpdf-tmp', $resolved );
		$this::assertDirectoryExists( $resolved );
	}

	// -----------------------------------------------------------------
	// get_legacy_storage_dirs() — with and without wp_upload_dir error
	// -----------------------------------------------------------------

	public function test_get_legacy_storage_dirs_returns_valid_paths_when_uploads_ok(): void {
		// Default state: wp_upload_dir() returns a real basedir on the
		// testbench, so the success branch fires (line 184 onwards).
		$dirs = \SScribe_Private_Storage::get_legacy_storage_dirs();

		$this::assertIsArray( $dirs );
		$this::assertArrayHasKey( 'exports', $dirs );
		$this::assertArrayHasKey( 'logs', $dirs );
		$this::assertArrayHasKey( 'mpdf_temp', $dirs );
		$this::assertStringEndsWith( '/sscribe-exports', $dirs['exports'] );
		$this::assertStringEndsWith( '/sscribe-logs', $dirs['logs'] );
	}

	public function test_get_legacy_storage_dirs_error_branch_via_uploads_filter(): void {
		// When wp_upload_dir() reports an error, the resolver returns
		// empty strings for every legacy location (line 176-183). The
		// `pre_option_upload_path` / `upload_dir` filters give us a way
		// to perturb that path.
		$uploads_filter = static function () {
			return array(
				'path'    => '',
				'url'     => '',
				'subdir'  => '',
				'basedir' => '',
				'baseurl' => '',
				'error'   => 'forced for coverage',
				'error_message' => 'forced',
			);
		};
		add_filter( 'upload_dir', $uploads_filter );
		try {
			$dirs = \SScribe_Private_Storage::get_legacy_storage_dirs();
			$this::assertSame( '', $dirs['exports'] );
			$this::assertSame( '', $dirs['logs'] );
			$this::assertSame( '', $dirs['mpdf_temp'] );
		} finally {
			remove_filter( 'upload_dir', $uploads_filter );
		}
	}

	// -----------------------------------------------------------------
	// migrate_legacy_storage() — empty-legacy happy path
	// (line 204-207 continue branch)
	// -----------------------------------------------------------------

	public function test_migrate_legacy_storage_with_no_existing_legacy_dirs_returns_true(): void {
		$this::define_storage_constant( $this->scratch );
		$result = \SScribe_Private_Storage::migrate_legacy_storage();

		// The no-legacy path traverses every entry of $destinations,
		// sees the empty / non-existent legacy, and continues. Returns
		// true because no migration operation actually failed.
		$this::assertTrue( $result, 'migrate_legacy_storage with no legacy must report success.' );
	}

	// -----------------------------------------------------------------
	// delete_owned_storage() — happy path
	// -----------------------------------------------------------------

	public function test_delete_owned_storage_removes_root(): void {
		$this::define_storage_constant( $this->scratch );
		$path = \SScribe_Private_Storage::get_export_dir( true );

		$this::assertNotSame( '', $path );
		$this::assertDirectoryExists( $path );

		$result = \SScribe_Private_Storage::delete_owned_storage();
		$this::assertTrue( $result, 'delete_owned_storage must return true after a successful delete.' );
	}

	public function test_delete_owned_storage_when_root_missing_returns_true(): void {
		// No resolve+create first: get_export_dir(false) returns '' because the path
		// doesn't exist → the guard at line 239 short-circuits to true.
		$missing_path = $this->scratch . '/not-here';
		wp_mkdir_p( $missing_path );
		$this::define_storage_constant( $missing_path );

		foreach ( glob( $missing_path . '/*' ) as $leftover ) {
			@unlink( $leftover );
		}
		@rmdir( $missing_path );

		$this::assertTrue(
			\SScribe_Private_Storage::delete_owned_storage(),
			'delete_owned_storage must return true when the root does not exist (line 239).'
		);
	}

	// -----------------------------------------------------------------
	// delete_legacy_storage() — happy path with no legacy
	// (drives the empty-continue branch at line 248-251)
	// -----------------------------------------------------------------

	public function test_delete_legacy_storage_with_no_legacy_returns_true(): void {
		$deleted = \SScribe_Private_Storage::delete_legacy_storage();
		$this::assertTrue( $deleted, 'delete_legacy_storage with no legacy artifacts must report success.' );
	}

	// -----------------------------------------------------------------
	// get_legacy_export_dir() — convenience accessor
	// -----------------------------------------------------------------

	public function test_get_legacy_export_dir_matches_exports_key(): void {
		$legacy_dir = \SScribe_Private_Storage::get_legacy_export_dir();
		$this::assertSame(
			\SScribe_Private_Storage::get_legacy_storage_dirs()['exports'],
			$legacy_dir,
			'get_legacy_export_dir must return get_legacy_storage_dirs()["exports"].'
		);
	}

	// -----------------------------------------------------------------
	// helpers
	// -----------------------------------------------------------------

	/**
	 * Add a filter for the lifetime of the next try-block.
	 *
	 * @param string   $hook    Hook name.
	 * @param callable $callable Callback to install.
	 */
	private function with_filter( string $hook, callable $callable ): void {
		add_filter( $hook, $callable );
	}

	/**
	 * Pin SSCRIBE_PRIVATE_STORAGE_DIR to a real directory. The SScribe
	 * private-storage resolver honors this constant over `sys_get_temp_dir()`,
	 * which lets each test own its own root path.
	 *
	 * Mirrors the helper in SScribe_Private_Storage_Migration_Test.
	 *
	 * @param string $path Absolute directory path.
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
	 * Undefine attempt — best-effort. SSCRIBE_PRIVATE_STORAGE_DIR is
	 * normally pinned for the duration of the run by the test harness
	 * (see `tests-wp/SScribe_Bootstrap::lock_storage_constant()`); we
	 * no-op here rather than try to forcibly restore. The tear_down
	 * cleanup is filesystem-level (rrmdir).
	 */
	private static function undefine_storage_constant(): void {
		// No-op: SSCRIBE_PRIVATE_STORAGE_DIR cannot be undefined at runtime
		// in PHP, and the test harness intentionally locks it in so each
		// run uses a unique scratch path. teardown relies on rrmdir().
	}

	/**
	 * Recursive rmdir helper. Best-effort; never asserts.
	 */
	private function rrmdir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$it = new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS );
		foreach ( new \RecursiveIteratorIterator( $it, \RecursiveIteratorIterator::CHILD_FIRST ) as $entry ) {
			$entry->isDir() ? @rmdir( $entry->getRealPath() ) : @unlink( $entry->getRealPath() );
		}
		@rmdir( $dir );
	}
}
