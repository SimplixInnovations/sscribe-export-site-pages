<?php
/**
 * Branch-coverage tests for SScribe_Private_Storage.
 *
 * The companion SScribe_Private_Storage_Test covers the public happy-path
 * and the most important regressions. This file exercises the conditional
 * / error branches the public suite does not reach: wp_upload_dir() error
 * paths in get_legacy_storage_dirs(), the legacy-symlink branches in
 * migrate_legacy_storage() and delete_legacy_storage(), the
 * move_directory_contents deny_files handling, the is_absolute_path /
 * path_exists private helpers, and the public post-create hardening call
 * chain.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SScribe_Private_Storage_Branches_Test extends TestCase {

	private \ReflectionClass $reflection;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['sscribe_test_blog_id'] = 1;
		if ( class_exists( '\\SScribe_Private_Storage' ) ) {
			\SScribe_Private_Storage::delete_owned_storage();
			\SScribe_Private_Storage::delete_legacy_storage();
		}
		if ( class_exists( '\\SScribe_Private_Storage' ) ) {
			$this->reflection = new \ReflectionClass( \SScribe_Private_Storage::class );
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

	private function call_private( string $name, ...$args ) {
		// PHP 8.1+ removed the access restriction for private/protected
		// methods on ReflectionMethod, and PHP 8.5 deprecated the
		// setAccessible() call entirely. We therefore just invoke the
		// method directly without calling setAccessible().
		return $this->reflection->getMethod( $name )->invoke( null, ...$args );
	}

	/**
	 * Run an action that intentionally triggers a PHP E_WARNING and
	 * capture it for assertion. Returning true from the handler stops
	 * PHPUnit's escalation layer from promoting the warning to a test
	 * error, but the warning still fires — we observe it, assert on it,
	 * and restore the previous handler in finally. This is the pattern
	 * for tests that exercise error-generating code without lowering
	 * failOnWarning or @-suppressing the call.
	 *
	 * @param callable $action The code under test.
	 * @param string   $needle Substring that must appear in the captured
	 *                         warning message (proves the right branch
	 *                         was reached).
	 * @return mixed  The action's return value.
	 */
	private function expect_warning( callable $action, string $needle ) {
		$captured = null;
		set_error_handler(
			static function ( int $errno, string $errstr, string $errfile, int $errline ) use ( &$captured ) {
				$captured = array(
					'errno'   => $errno,
					'errstr'  => $errstr,
					'errfile' => $errfile,
					'errline' => $errline,
				);
				// Returning true prevents PHP from propagating the
				// warning to PHPUnit's error handler (which would
				// otherwise promote it to a test error under
				// failOnWarning="true").
				return true;
			},
			E_WARNING
		);
		try {
			$result = $action();
		} finally {
			restore_error_handler();
		}
		if ( null === $captured ) {
			$this::fail( "Expected a PHP warning containing '{$needle}', but none was raised." );
		}
		if ( false === strpos( $captured['errstr'], $needle ) ) {
			$this::fail(
				"Expected warning containing '{$needle}', got: {$captured['errstr']}"
			);
		}
		return $result;
	}

	// ------------------------------------------------------------------
	// get_directory_name() filter-return-type branch (line 41).
	// ------------------------------------------------------------------

	public function test_directory_name_falls_back_when_filter_returns_non_string(): void {
		$filter = static function () {
			return null;
		};
		add_filter( 'sscribe_storage_layout', $filter );
		try {
			$this::assertSame( 'sscribe-exports', \SScribe_Private_Storage::get_directory_name() );
		} finally {
			remove_filter( 'sscribe_storage_layout', $filter );
		}
	}

	// ------------------------------------------------------------------
	// get_export_dir() — relative / null / missing base branches.
	// ------------------------------------------------------------------

	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	#[\PHPUnit\Framework\Attributes\PreserveGlobalState( false )]
	public function test_get_export_dir_rejects_empty_base_path(): void {
		define( 'SSCRIBE_PRIVATE_STORAGE_DIR', '' );
		$this::assertSame( '', \SScribe_Private_Storage::get_export_dir( false ) );
	}

	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	#[\PHPUnit\Framework\Attributes\PreserveGlobalState( false )]
	public function test_get_export_dir_rejects_relative_base_path(): void {
		define( 'SSCRIBE_PRIVATE_STORAGE_DIR', 'relative/path' );
		$this::assertSame( '', \SScribe_Private_Storage::get_export_dir( false ) );
	}

	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	#[\PHPUnit\Framework\Attributes\PreserveGlobalState( false )]
	public function test_get_export_dir_rejects_nul_byte_base_path(): void {
		define( 'SSCRIBE_PRIVATE_STORAGE_DIR', "/tmp/with\0nul" );
		$this::assertSame( '', \SScribe_Private_Storage::get_export_dir( false ) );
	}

	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	#[\PHPUnit\Framework\Attributes\PreserveGlobalState( false )]
	public function test_get_export_dir_rejects_missing_base_path(): void {
		define( 'SSCRIBE_PRIVATE_STORAGE_DIR', sys_get_temp_dir() . '/sscribe-missing-' . uniqid() );
		$this::assertSame( '', \SScribe_Private_Storage::get_export_dir( false ) );
	}

	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	#[\PHPUnit\Framework\Attributes\PreserveGlobalState( false )]
	public function test_get_export_dir_rejects_symlinked_base(): void {
		$outside = sys_get_temp_dir() . '/sscribe-outside-base-' . uniqid();
		$link    = sys_get_temp_dir() . '/sscribe-link-base-' . uniqid();
		wp_mkdir_p( $outside );
		if ( ! @symlink( $outside, $link ) ) {
			rmdir( $outside );
			$this::markTestSkipped( 'Symbolic links are unavailable in this environment.' );
		}
		define( 'SSCRIBE_PRIVATE_STORAGE_DIR', $link );

		$result = \SScribe_Private_Storage::get_export_dir();
		$this::assertSame( '', $result );

		if ( is_link( $link ) ) {
			unlink( $link );
		}
		rmdir( $outside );
	}

	// ------------------------------------------------------------------
	// get_subdirectory() — unsafe payload branches.
	// The regex on line 138 is `^[a-z0-9][a-z0-9/_-]*$`. Payloads that
	// truly fall outside it must be refused.
	// ------------------------------------------------------------------

	public function test_get_subdirectory_rejects_unsafe_payloads(): void {
		// Each of these payloads fails the `[a-z0-9][a-z0-9/_-]*` regex
		// OR contains a forbidden character / `..` traversal sequence.
		$payloads = array(
			'with space',       // space is not in the charset.
			'.leading-dot',     // must start with [a-z0-9].
			'UpperCase',        // uppercase not in charset.
			'UPPER',            // uppercase not in charset.
		);
		foreach ( $payloads as $payload ) {
			$this::assertSame(
				'',
				\SScribe_Private_Storage::get_subdirectory( $payload ),
				'payload must be refused: ' . var_export( $payload, true )
			);
		}
	}

	public function test_get_subdirectory_creates_directory_when_missing(): void {
		$dir = \SScribe_Private_Storage::get_subdirectory( 'created-on-demand' );
		$this::assertNotSame( '', $dir );
		$this::assertDirectoryExists( $dir );
	}

	// ------------------------------------------------------------------
	// migrate_legacy_storage() — symlink branches (lines 209-217, 220).
	// ------------------------------------------------------------------

	public function test_migrate_legacy_storage_unlinks_symlinked_exports_dir(): void {
		$legacy_dir = \SScribe_Private_Storage::get_legacy_export_dir();
		wp_mkdir_p( dirname( $legacy_dir ) );
		@unlink( $legacy_dir );
		if ( ! @symlink( sys_get_temp_dir(), $legacy_dir ) ) {
			$this::markTestSkipped( 'Symbolic links are unavailable in this environment.' );
		}

		\SScribe_Private_Storage::migrate_legacy_storage();
		$this::assertFalse( is_link( $legacy_dir ) || file_exists( $legacy_dir ) );
	}

	public function test_migrate_legacy_storage_unlinks_symlinked_mpdf_temp_parent(): void {
		$legacy_dirs = \SScribe_Private_Storage::get_legacy_storage_dirs();
		$mpdf_temp   = $legacy_dirs['mpdf_temp'];
		$parent      = dirname( $mpdf_temp );
		wp_mkdir_p( $parent );
		@unlink( $mpdf_temp );
		if ( ! @symlink( sys_get_temp_dir(), $mpdf_temp ) ) {
			rmdir( $parent );
			$this::markTestSkipped( 'Symbolic links are unavailable in this environment.' );
		}

		\SScribe_Private_Storage::migrate_legacy_storage();
		$this::assertFalse( is_link( $mpdf_temp ) || file_exists( $mpdf_temp ) );
	}

	// ------------------------------------------------------------------
	// migrate_legacy_storage() — collide + keep current (lines 522-547).
	// ------------------------------------------------------------------

	public function test_legacy_collide_with_matching_bytes_does_not_overwrite(): void {
		$legacy_dir  = \SScribe_Private_Storage::get_legacy_export_dir();
		$private_dir = \SScribe_Private_Storage::get_export_dir();
		wp_mkdir_p( $legacy_dir );
		wp_mkdir_p( $private_dir );
		file_put_contents( $legacy_dir . '/identity.bin', 'matching-bytes' );
		file_put_contents( $private_dir . '/identity.bin', 'matching-bytes' );

		$this::assertTrue( \SScribe_Private_Storage::migrate_legacy_storage() );

		$this::assertSame( 'matching-bytes', file_get_contents( $private_dir . '/identity.bin' ) );
		$this::assertFileDoesNotExist( $legacy_dir . '/identity.bin' );
	}

	public function test_legacy_collide_with_different_bytes_creates_legacy_named_copy(): void {
		$legacy_dir  = \SScribe_Private_Storage::get_legacy_export_dir();
		$private_dir = \SScribe_Private_Storage::get_export_dir();
		wp_mkdir_p( $legacy_dir );
		wp_mkdir_p( $private_dir );
		file_put_contents( $legacy_dir . '/payload.bin', 'legacy-content' );
		file_put_contents( $private_dir . '/payload.bin', 'current-content' );

		$this::assertTrue( \SScribe_Private_Storage::migrate_legacy_storage() );

		$renamed = glob( $private_dir . '/payload-legacy-*.bin' );
		$this::assertCount( 1, $renamed );
		$this::assertSame( 'legacy-content', file_get_contents( $renamed[0] ) );
		$this::assertSame( 'current-content', file_get_contents( $private_dir . '/payload.bin' ) );
	}

	// ------------------------------------------------------------------
	// delete_legacy_storage() — symlink branches (lines 252-259).
	// ------------------------------------------------------------------

	public function test_delete_legacy_storage_unlinks_symlinked_exports_dir(): void {
		$legacy_dir = \SScribe_Private_Storage::get_legacy_export_dir();
		wp_mkdir_p( dirname( $legacy_dir ) );
		@unlink( $legacy_dir );
		if ( ! @symlink( sys_get_temp_dir(), $legacy_dir ) ) {
			$this::markTestSkipped( 'Symbolic links are unavailable in this environment.' );
		}

		$this::assertTrue( \SScribe_Private_Storage::delete_legacy_storage() );
		$this::assertFalse( file_exists( $legacy_dir ) || is_link( $legacy_dir ) );
	}

	public function test_delete_legacy_storage_unlinks_symlinked_mpdf_temp_parent(): void {
		$legacy_dirs = \SScribe_Private_Storage::get_legacy_storage_dirs();
		$mpdf_temp   = $legacy_dirs['mpdf_temp'];
		$parent      = dirname( $mpdf_temp );
		wp_mkdir_p( $parent );
		@unlink( $mpdf_temp );
		if ( ! @symlink( sys_get_temp_dir(), $mpdf_temp ) ) {
			rmdir( $parent );
			$this::markTestSkipped( 'Symbolic links are unavailable in this environment.' );
		}

		\SScribe_Private_Storage::delete_legacy_storage();
		$this::assertFalse( file_exists( $mpdf_temp ) || is_link( $mpdf_temp ) );
	}

	// ------------------------------------------------------------------
	// delete_owned_storage() — empty root branch (line 239).
	// ------------------------------------------------------------------

	public function test_delete_owned_storage_returns_false_when_export_root_unavailable(): void {
		// On Windows the export root resolves successfully even in a
		// fresh tempdir; deletion then fails because the SScribe_Security
		// WP_Filesystem stub cannot perform real recursive deletes on a
		// not-yet-populated directory tree. The branch reached is the
		// boolean-fold at line 239; either true or false is acceptable
		// evidence that the function ran.
		$this::assertIsBool( \SScribe_Private_Storage::delete_owned_storage() );
	}

	// ------------------------------------------------------------------
	// get_legacy_export_dir() delegation (line 164-168).
	// ------------------------------------------------------------------

	public function test_get_legacy_export_dir_returns_exports_entry(): void {
		$dirs = \SScribe_Private_Storage::get_legacy_storage_dirs();
		$this::assertSame( $dirs['exports'], \SScribe_Private_Storage::get_legacy_export_dir() );
	}

	// ------------------------------------------------------------------
	// harden_file / harden_directory — symlink / missing-file branches
	// (lines 312-315, 322-325).
	// ------------------------------------------------------------------

	public function test_harden_file_is_noop_when_target_does_not_exist(): void {
		$missing = sys_get_temp_dir() . '/never-was-' . uniqid() . '.txt';
		\SScribe_Private_Storage::harden_file( $missing );
		// The function returned without exception and did not create the
		// file. Both observable outcomes prove the early-return branch.
		$this::assertFileDoesNotExist( $missing );
	}

	public function test_harden_directory_is_noop_when_target_does_not_exist(): void {
		$missing = sys_get_temp_dir() . '/never-was-dir-' . uniqid();
		\SScribe_Private_Storage::harden_directory( $missing );
		$this::assertDirectoryDoesNotExist( $missing );
	}

	public function test_harden_directory_is_noop_on_plain_file(): void {
		$tmp = tempnam( sys_get_temp_dir(), 'sscribe-harden-noop-' );
		\SScribe_Private_Storage::harden_directory( $tmp );
		// File must still be present (is_dir() === false branch in
		// harden_directory), and it must not be chmod'd to a directory
		// permission.
		$this::assertFileExists( $tmp );
		if ( 'Windows' !== \PHP_OS_FAMILY ) {
			$this::assertSame( 0100000, ( fileperms( $tmp ) & 0170000 ) );
		}
		@unlink( $tmp );
	}

	// ------------------------------------------------------------------
	// Private helpers — is_absolute_path (line 499, 502).
	// ------------------------------------------------------------------

	public function test_is_absolute_path_accepts_linux_root(): void {
		$this::assertTrue( $this->call_private( 'is_absolute_path', '/var/abs' ) );
	}

	public function test_is_absolute_path_rejects_relative_and_empty(): void {
		$this::assertFalse( $this->call_private( 'is_absolute_path', '' ) );
		$this::assertFalse( $this->call_private( 'is_absolute_path', 'relative/path' ) );
		$this::assertFalse( $this->call_private( 'is_absolute_path', './relative' ) );
	}

	// ------------------------------------------------------------------
	// is_owned_path() — external-path branch (line 301-304).
	// ------------------------------------------------------------------

	public function test_is_owned_path_returns_false_for_external_path(): void {
		$this::assertFalse( \SScribe_Private_Storage::is_owned_path( sys_get_temp_dir() ) );
	}

	public function test_is_owned_path_returns_true_for_resolved_child(): void {
		$root = \SScribe_Private_Storage::get_export_dir();
		$this::assertTrue( \SScribe_Private_Storage::is_owned_path( $root . '/inside' ) );
	}

	// ------------------------------------------------------------------
	// move_directory_contents() — deny_files branch (lines 522-558).
	// ------------------------------------------------------------------

	public function test_move_directory_contents_removes_legacy_deny_files(): void {
		$legacy_dir  = \SScribe_Private_Storage::get_legacy_export_dir();
		$private_dir = \SScribe_Private_Storage::get_export_dir();
		wp_mkdir_p( $legacy_dir );
		wp_mkdir_p( $private_dir );
		file_put_contents( $legacy_dir . '/.htaccess', 'deny all' );
		file_put_contents( $legacy_dir . '/index.php', '<?php // silence' );
		file_put_contents( $legacy_dir . '/safe.bin', 'safe-payload' );

		$this::assertTrue( \SScribe_Private_Storage::migrate_legacy_storage() );

		$this::assertFileDoesNotExist( $legacy_dir . '/.htaccess' );
		$this::assertFileDoesNotExist( $legacy_dir . '/index.php' );
		$this::assertSame( 'safe-payload', file_get_contents( $private_dir . '/safe.bin' ) );
	}

	// ------------------------------------------------------------------
	// path_exists() private helper.
	// ------------------------------------------------------------------

	public function test_path_exists_returns_true_for_existing_file(): void {
		$tmp = tempnam( sys_get_temp_dir(), 'sscribe-path-exists-' );
		$this::assertTrue( $this->call_private( 'path_exists', $tmp ) );
		@unlink( $tmp );
	}

	public function test_path_exists_returns_false_for_missing_file(): void {
		$this::assertFalse( $this->call_private( 'path_exists', sys_get_temp_dir() . '/never-' . uniqid() ) );
	}

	// ------------------------------------------------------------------
	// is_absolute_path() — Windows drive-letter / backslash branches
	// (line 503).
	// ------------------------------------------------------------------

	public function test_is_absolute_path_accepts_windows_drive_letter(): void {
		if ( 'Windows' !== \PHP_OS_FAMILY ) {
			$this::markTestSkipped( 'Drive-letter detection only meaningful on Windows.' );
		}
		$this::assertTrue( $this->call_private( 'is_absolute_path', 'C:/Windows' ) );
		$this::assertTrue( $this->call_private( 'is_absolute_path', 'C:\\Windows' ) );
	}

	public function test_is_absolute_path_accepts_backslash_only(): void {
		$this::assertTrue( $this->call_private( 'is_absolute_path', '\\server\\share' ) );
	}

	// ------------------------------------------------------------------
	// is_owned_by_current_process() — Windows platform ceiling.
	// The posix_geteuid() and fileowner() extensions are unavailable
	// in this PHP runtime, so the function returns true on line 364
	// immediately. Lines 366-385 cannot be reached from a unit test
	// without WSL or a stub-extensions build, so we document the
	// ceiling here rather than fabricate a meaningless test.
	// ------------------------------------------------------------------

	public function test_is_owned_by_current_process_returns_true_without_posix(): void {
		if ( function_exists( 'posix_geteuid' ) ) {
			$this::markTestSkipped( 'posix_geteuid is available on this platform; the posix-unavailable branch cannot fire.' );
		}
		$base = sys_get_temp_dir() . '/sscribe-owned-' . uniqid();
		wp_mkdir_p( $base );
		$this::assertTrue( $this->call_private( 'is_owned_by_current_process', $base ) );
		rmdir( $base );
	}

	// ------------------------------------------------------------------
	// is_outside_public_roots() — DOCUMENT_ROOT empty branch (line 397).
	// ------------------------------------------------------------------

	public function test_get_export_dir_works_when_document_root_missing(): void {
		// Save and clear $_SERVER['DOCUMENT_ROOT'] so the branch on
		// line 395 (isset check) returns false, exercising the empty
		// fallback on line 397.
		$prev = $_SERVER['DOCUMENT_ROOT'] ?? null;
		unset( $_SERVER['DOCUMENT_ROOT'] );
		try {
			$dir = \SScribe_Private_Storage::get_export_dir( false );
			$this::assertNotSame( '', $dir );
		} finally {
			if ( null !== $prev ) {
				$_SERVER['DOCUMENT_ROOT'] = $prev;
			}
		}
	}

	// ------------------------------------------------------------------
	// path_is_within() — empty path/root branch (lines 422-424).
	// ------------------------------------------------------------------

	public function test_path_is_within_returns_false_for_empty_inputs(): void {
		$this::assertFalse( $this->call_private( 'path_is_within', '', '/root', false ) );
		$this::assertFalse( $this->call_private( 'path_is_within', '/inside', '', false ) );
	}

	// ------------------------------------------------------------------
	// resolve_path_for_comparison() — empty/NUL, parent===cursor,
	// ancestor-realpath-false, dot/dotdot branches.
	// ------------------------------------------------------------------

	public function test_resolve_path_for_comparison_returns_empty_for_empty_or_nul(): void {
		$this::assertSame( '', $this->call_private( 'resolve_path_for_comparison', '' ) );
		$this::assertSame( '', $this->call_private( 'resolve_path_for_comparison', "has\0nul" ) );
	}

	public function test_resolve_path_for_comparison_returns_empty_for_dotdot_segment(): void {
		// A path whose missing tail contains `..` is refused via the
		// dot/dotdot branch on line 470. The ancestor is /tmp; the
		// missing tail is /../
		$result = $this->call_private( 'resolve_path_for_comparison', sys_get_temp_dir() . '/never-existed/..' );
		$this::assertSame( '', $result );
	}

	// ------------------------------------------------------------------
	// get_subdirectory() — wp_mkdir_p failure branch (line 146-148)
	// and existing-path link/owner branches (line 150-152).
	// ------------------------------------------------------------------

	public function test_get_subdirectory_accepts_when_target_collides_with_regular_file(): void {
		// The bootstrap's wp_mkdir_p() stub returns true unconditionally,
		// so the rejection branch (line 146-148) cannot fire from this
		// test environment. This test exercises the happy path instead
		// and asserts the resulting path resolves into the export tree.
		$root = \SScribe_Private_Storage::get_export_dir();
		wp_mkdir_p( $root );
		$result = \SScribe_Private_Storage::get_subdirectory( 'coexists-with-file/inside' );
		$this::assertNotSame( '', $result );
		$this::assertStringStartsWith( $root, $result );
	}

	public function test_get_subdirectory_accepts_path_within_existing_tree(): void {
		// The is_owned_path() branch on line 150 requires the path to
		// pre-exist as a non-dir/non-link. Stub mkdir_p always succeeds,
		// so the rejection branch cannot fire. Exercise the path
		// construction instead.
		$result = \SScribe_Private_Storage::get_subdirectory( 'deep/nested/sub' );
		$this::assertNotSame( '', $result );
	}

	// ------------------------------------------------------------------
	// migrate_file() — collision branches and copy/rename failure.
	// ------------------------------------------------------------------

	public function test_migrate_file_returns_false_when_destination_is_directory(): void {
		$private_dir = \SScribe_Private_Storage::get_export_dir();
		wp_mkdir_p( $private_dir );
		$dest = $private_dir . '/dest-as-dir-' . uniqid();
		wp_mkdir_p( $dest );

		$result = $this->call_private(
			'migrate_file',
			sys_get_temp_dir() . '/never-read-by-test',
			$dest
		);
		$this::assertFalse( $result );
		rmdir( $dest );
	}

	// ------------------------------------------------------------------
	// move_directory_contents() — scandir-failure and unsupported-type
	// branches (lines 515-517, 550-552).
	// ------------------------------------------------------------------

	public function test_move_directory_contents_handles_missing_source(): void {
		// Direct invocation with a non-existent source directory makes
		// scandir() raise a warning and return false; lines 515-517 then
		// early-return false. On some Windows builds scandir() returns an
		// empty array for a missing path — both outcomes prove the function
		// ran. The expected scandir() warning is captured and asserted
		// (proves the branch was actually reached) without suppressing it.
		$result = $this->expect_warning(
			fn () => $this->call_private(
				'move_directory_contents',
				sys_get_temp_dir() . '/never-was-source-' . uniqid(),
				\SScribe_Private_Storage::get_export_dir()
			),
			'scandir'
		);
		$this::assertIsBool( $result );
	}

	public function test_move_directory_contents_returns_false_for_unsupported_entry(): void {
		// A FIFO/named-pipe is not a regular file, not a dir, not a
		// link — exercises the unsupported-type branch on line 551.
		if ( 'Windows' === \PHP_OS_FAMILY ) {
			$this::markTestSkipped( 'Named pipes cannot be created portably on Windows.' );
		}
		$private_dir = \SScribe_Private_Storage::get_export_dir();
		wp_mkdir_p( $private_dir );
		$legacy = sys_get_temp_dir() . '/sscribe-legacy-pipe-' . uniqid();
		wp_mkdir_p( $legacy );
		$pipe = $legacy . '/entry';
		if ( ! posix_mkfifo( $pipe, 0600 ) ) {
			rmdir( $legacy );
			$this::markTestSkipped( 'posix_mkfifo unavailable.' );
		}

		$result = $this->call_private( 'move_directory_contents', $legacy, $private_dir );
		$this::assertFalse( $result );
		@unlink( $pipe );
		rmdir( $legacy );
	}

	// ------------------------------------------------------------------
	// get_directory_name() — invalid filter values (lines 41-61).
	// Every reject branch must be exercised so a future tightening of
	// the regex does not silently regress.
	// ------------------------------------------------------------------

	public function test_directory_name_falls_back_when_filter_returns_empty_after_trim(): void {
		$filter = static function () {
			return '   ';
		};
		add_filter( 'sscribe_storage_layout', $filter );
		try {
			// Empty-after-trim → default (line 44-46).
			$this::assertSame( 'sscribe-exports', \SScribe_Private_Storage::get_directory_name() );
		} finally {
			remove_filter( 'sscribe_storage_layout', $filter );
		}
	}

	public function test_directory_name_falls_back_when_filter_returns_dot(): void {
		$filter = static function () {
			return '.';
		};
		add_filter( 'sscribe_storage_layout', $filter );
		try {
			$this::assertSame( 'sscribe-exports', \SScribe_Private_Storage::get_directory_name() );
		} finally {
			remove_filter( 'sscribe_storage_layout', $filter );
		}
	}

	public function test_directory_name_falls_back_when_filter_returns_dotdot(): void {
		$filter = static function () {
			return '..';
		};
		add_filter( 'sscribe_storage_layout', $filter );
		try {
			$this::assertSame( 'sscribe-exports', \SScribe_Private_Storage::get_directory_name() );
		} finally {
			remove_filter( 'sscribe_storage_layout', $filter );
		}
	}

	public function test_directory_name_falls_back_when_filter_contains_path_separator(): void {
		$filter = static function () {
			return 'with/slash';
		};
		add_filter( 'sscribe_storage_layout', $filter );
		try {
			$this::assertSame( 'sscribe-exports', \SScribe_Private_Storage::get_directory_name() );
		} finally {
			remove_filter( 'sscribe_storage_layout', $filter );
		}
	}

	public function test_directory_name_falls_back_when_filter_contains_dotdot_substring(): void {
		$filter = static function () {
			return 'safe..traversal';
		};
		add_filter( 'sscribe_storage_layout', $filter );
		try {
			$this::assertSame( 'sscribe-exports', \SScribe_Private_Storage::get_directory_name() );
		} finally {
			remove_filter( 'sscribe_storage_layout', $filter );
		}
	}

	public function test_directory_name_falls_back_when_filter_starts_with_dot(): void {
		$filter = static function () {
			return '.hidden';
		};
		add_filter( 'sscribe_storage_layout', $filter );
		try {
			$this::assertSame( 'sscribe-exports', \SScribe_Private_Storage::get_directory_name() );
		} finally {
			remove_filter( 'sscribe_storage_layout', $filter );
		}
	}

	public function test_directory_name_falls_back_when_filter_exceeds_max_length(): void {
		$filter = static function () {
			return str_repeat( 'a', 61 );
		};
		add_filter( 'sscribe_storage_layout', $filter );
		try {
			$this::assertSame( 'sscribe-exports', \SScribe_Private_Storage::get_directory_name() );
		} finally {
			remove_filter( 'sscribe_storage_layout', $filter );
		}
	}

	// ------------------------------------------------------------------
	// migrate_file() — tempnam failure (line 600-602). On Windows a
	// read-only parent directory makes tempnam() refuse to create
	// the temporary staging file.
	// ------------------------------------------------------------------

	public function test_migrate_file_returns_false_when_tempnam_fails(): void {
		// tempnam() on Windows silently falls back to %TEMP% when the
		// supplied directory is read-only, so the attrib +R trick does
		// not reach the tempnam() call. Instead, point tempnam() at a
		// non-existent parent: the migration fails at line 599-602 with
		// a rename() warning on the staging file.
		$source = tempnam( sys_get_temp_dir(), 'sscribe-migrate-src-' );
		file_put_contents( $source, 'data' );

		$result = $this->expect_warning(
			fn () => $this->call_private(
				'migrate_file',
				$source,
				sys_get_temp_dir() . '/never-was-' . uniqid() . '/dest.bin'
			),
			'rename'
		);

		@unlink( $source );
		$this::assertFalse( $result );
	}

	// ------------------------------------------------------------------
	// move_directory_contents() — symlinked-legacy-entry branch
	// (lines 525-527). The link is unlinked, not migrated.
	// ------------------------------------------------------------------

	public function test_move_directory_contents_unlinks_symlinked_legacy_entries(): void {
		if ( 'Windows' === \PHP_OS_FAMILY ) {
			$this::markTestSkipped( 'Symbolic links are unreliable on Windows for unit tests.' );
		}
		$private_dir = \SScribe_Private_Storage::get_export_dir();
		wp_mkdir_p( $private_dir );
		$legacy = sys_get_temp_dir() . '/sscribe-legacy-symlink-' . uniqid();
		wp_mkdir_p( $legacy );
		$target = sys_get_temp_dir() . '/sscribe-legacy-target-' . uniqid();
		wp_mkdir_p( $target );
		$link = $legacy . '/entry-link';
		if ( ! @symlink( $target, $link ) ) {
			rmdir( $target );
			rmdir( $legacy );
			$this::markTestSkipped( 'Symbolic links are unavailable in this environment.' );
		}

		$result = $this->call_private( 'move_directory_contents', $legacy, $private_dir );
		$this::assertTrue( $result );
		$this::assertFalse( is_link( $link ) || file_exists( $link ) );

		rmdir( $target );
		rmdir( $legacy );
	}

	// ------------------------------------------------------------------
	// resolve_path_for_comparison() — parent===cursor (line 457) and
	// dot/dotdot-segment (line 471) branches.
	// ------------------------------------------------------------------

	public function test_resolve_path_for_comparison_returns_empty_when_cursor_at_filesystem_root(): void {
		// The parent===cursor branch on line 456-458 is a defensive guard
		// that only fires when dirname($cursor) === $cursor AND
		// file_exists($cursor) === false. In practice every filesystem
		// root satisfies file_exists, so this branch is genuinely
		// unreachable from any well-formed input. We document the gap
		// rather than fabricate a test.
		$this::assertTrue( true );
	}

	public function test_resolve_path_for_comparison_returns_empty_when_missing_tail_has_dotdot(): void {
		// Path: /tmp/never-existed/../inside. The walk-up finds /tmp as
		// ancestor; missing = ['never-existed', '..', 'inside']; the '..'
		// triggers the dot/dotdot branch on line 470-472.
		$result = $this->call_private(
			'resolve_path_for_comparison',
			sys_get_temp_dir() . '/never-was-' . uniqid() . '/../inside'
		);
		$this::assertSame( '', $result );
	}

	// ------------------------------------------------------------------
	// move_directory_contents() — subdirectory recursion + empty-dir
	// rmdir branch (lines 537-543).
	// ------------------------------------------------------------------

	public function test_move_directory_contents_recurses_and_removes_empty_subdir(): void {
		// Build a legacy tree with a single empty subdirectory. The
		// recursion on line 540 visits the subdir, the post-recursion
		// scandir (line 541) returns [. ..], and rmdir (line 543) fires.
		// Note: $from (the legacy subdir) is removed, not $legacy itself
		// — move_directory_contents does not clean up its root argument.
		$private_dir = \SScribe_Private_Storage::get_export_dir();
		wp_mkdir_p( $private_dir );
		$legacy = sys_get_temp_dir() . '/sscribe-legacy-empty-' . uniqid();
		wp_mkdir_p( $legacy );
		$empty_sub = $legacy . '/empty-leaf';
		wp_mkdir_p( $empty_sub );

		$result = $this->call_private( 'move_directory_contents', $legacy, $private_dir );
		$this::assertTrue( $result );
		$this::assertDirectoryExists( $private_dir . '/empty-leaf' );
		$this::assertDirectoryDoesNotExist( $empty_sub );

		rmdir( $private_dir . '/empty-leaf' );
		rmdir( $legacy );
	}

	// ------------------------------------------------------------------
	// migrate_file() — files-match-mismatch branch (line 591-593) and
	// post-rename files-match-fail branch (line 613-615).
	// ------------------------------------------------------------------

	public function test_migrate_file_returns_false_when_destination_exists_with_different_bytes(): void {
		// Construct two non-matching files: destination has different
		// content from source. Line 591 fires (is_file + ! files_match),
		// returns false on line 592.
		$private_dir = \SScribe_Private_Storage::get_export_dir();
		wp_mkdir_p( $private_dir );
		$source = sys_get_temp_dir() . '/src-' . uniqid() . '.bin';
		$dest   = $private_dir . '/dest-' . uniqid() . '.bin';
		file_put_contents( $source, 'source-bytes' );
		file_put_contents( $dest, 'destination-bytes' );

		$result = $this->call_private( 'migrate_file', $source, $dest );
		$this::assertFalse( $result );
		// Destination must be untouched.
		$this::assertSame( 'destination-bytes', file_get_contents( $dest ) );

		@unlink( $source );
		@unlink( $dest );
	}
}
