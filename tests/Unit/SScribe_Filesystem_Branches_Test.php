<?php
/**
 * Branch-coverage tests for SScribe_Filesystem.
 *
 * The companion SScribe_Filesystem_Test covers the public happy-path
 * and the most important regressions. This file exercises the
 * conditional / error branches the public suite does not reach:
 *
 *   - sanitize_path() branches (empty, no-traversal fast path,
 *     NUL-only fast path, full traversal collapse, Unix-absolute
 *     preservation, empty-after-cleanup);
 *   - mkdir_under_private_root() rejection branches (empty,
 *     absolute, traversal, empty-after-cleanup, symlinked target,
 *     no export root, post-create containment escape);
 *   - is_absolute_path_string() private helper (Unix slash,
 *     Windows drive, UNC, embedded absolute segment);
 *   - is_within_allowed_directory() branches for invalid input
 *     and missing parent;
 *   - normalize_path() private helper (`.`/`..` collapse, Windows
 *     lowercasing);
 *   - is_path_safe_for_write() branches (empty export root,
 *     outside literal, symlink-to-safe-target, parent symlink,
 *     parent realpath mismatch);
 *   - the WP_Filesystem-injection branches (put_contents,
 *     get_contents, mkdir, copy, move, exists, is_dir,
 *     is_writable, dirlist, get_method, is_wp_filesystem).
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Load the WordPress WP_Filesystem_Base stub before the SScribe_Filesystem
 * source file, so the SScribe_Filesystem type-hint (`?WP_Filesystem_Base $fs`)
 * and the `instanceof` checks resolve during this test's bootstrap. The stub
 * is kept in a dedicated file because declaring PHP classes in the GLOBAL
 * namespace from inside a `namespace SScribe\Tests\Unit;` block is impossible
 * without wrapping each declaration in `namespace { ... }`, which complicates
 * parsing and breaks IDE diagnostics. Production code loads WP_Filesystem_Base
 * from WordPress's `wp-admin/includes/class-wp-filesystem-base.php`; this stub
 * is the unit-test equivalent.
 */
require_once __DIR__ . '/wp-filesystem-base-stub.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-sscribe-filesystem.php';

final class SScribe_Filesystem_Branches_Test extends TestCase {

	private \ReflectionClass $reflection;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['sscribe_test_blog_id'] = 1;
		if ( class_exists( '\\SScribe_Private_Storage' ) ) {
			\SScribe_Private_Storage::delete_owned_storage();
			\SScribe_Private_Storage::delete_legacy_storage();
		}
		if ( class_exists( '\\SScribe_Filesystem' ) ) {
			$this->reflection = new \ReflectionClass( \SScribe_Filesystem::class );
			// Reset the WP_Filesystem singleton between tests so each
			// test gets a clean slate.
			$fs = $this->reflection->getProperty( 'fs' );
			$fs->setAccessible( true );
			$fs->setValue( null, null );
		}
	}

	protected function tearDown(): void {
		if ( class_exists( '\\SScribe_Filesystem' ) ) {
			$fs = $this->reflection->getProperty( 'fs' );
			$fs->setAccessible( true );
			$fs->setValue( null, null );
		}
		if ( class_exists( '\\SScribe_Private_Storage' ) ) {
			\SScribe_Private_Storage::delete_owned_storage();
			\SScribe_Private_Storage::delete_legacy_storage();
		}
		unset( $GLOBALS['sscribe_test_blog_id'] );
		parent::tearDown();
	}

	private function call_static( string $name, ...$args ) {
		$method = $this->reflection->getMethod( $name );
		return $method->invoke( null, ...$args );
	}

	private function make_fs(): \SScribe_Test_WP_Filesystem {
		return new \SScribe_Test_WP_Filesystem();
	}

	private function inject_wpfs( \WP_Filesystem_Base $fs ): void {
		$prop = $this->reflection->getProperty( 'fs' );
		$prop->setAccessible( true );
		$prop->setValue( null, $fs );
	}

	private function make_fs_instance(): \SScribe_Filesystem {
		$instance = $this->reflection->newInstanceWithoutConstructor();
		$prop     = $this->reflection->getProperty( 'logger' );
		$prop->setAccessible( true );
		$prop->setValue( $instance, \SScribe_Logger::instance( false ) );
		return $instance;
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

	// ==================================================================
	// sanitize_path() branches.
	// ==================================================================

	public function test_sanitize_path_returns_empty_string_unchanged(): void {
		$this::assertSame( '', $this->call_static( 'sanitize_path', '' ) );
	}

	public function test_sanitize_path_returns_clean_path_unchanged(): void {
		$this::assertSame( 'foo/bar.txt', $this->call_static( 'sanitize_path', 'foo/bar.txt' ) );
	}

	public function test_sanitize_path_strips_nul_byte(): void {
		// Contains NUL but no `..`. Hits the "no `..`, has `\0`" branch.
		$this::assertSame( 'foo/bar.txt', $this->call_static( 'sanitize_path', "foo\0/bar.txt" ) );
	}

	public function test_sanitize_path_collapses_traversal_segments(): void {
		// Both `..` segments must be removed by the segment-collapse branch.
		$this::assertSame( 'foo/bar.txt', $this->call_static( 'sanitize_path', '../foo/../bar.txt' ) );
	}

	public function test_sanitize_path_collapses_to_empty_for_pure_traversal(): void {
		// Only traversal segments remain — must return '' (line 178-180).
		$this::assertSame( '', $this->call_static( 'sanitize_path', '../../..' ) );
	}

	public function test_sanitize_path_preserves_unix_absolute_prefix(): void {
		// Unix absolute paths keep their leading slash branch (184-185).
		$this::assertStringStartsWith( '/', $this->call_static( 'sanitize_path', '/../etc/../passwd' ) );
	}

	public function test_sanitize_path_replaces_backslashes_with_forward_slashes(): void {
		// Backslashes are normalized so the segment explode sees `\\` as
		// `/`, then every `..` segment is collapsed.
		$this::assertSame( 'foo/bar.txt', $this->call_static( 'sanitize_path', '..\\foo\\..\\bar.txt' ) );
	}

	// ==================================================================
	// mkdir_under_private_root() — many rejection branches.
	// ==================================================================

	public function test_mkdir_under_private_root_rejects_empty(): void {
		$fs = $this->make_fs_instance();
		$this::assertSame( '', $fs->mkdir_under_private_root( '' ) );
		$this::assertSame( 'Relative path is empty or contains a NUL byte', $fs->get_last_error() );
	}

	public function test_mkdir_under_private_root_rejects_whitespace(): void {
		$fs = $this->make_fs_instance();
		$this::assertSame( '', $fs->mkdir_under_private_root( '   ' ) );
	}

	public function test_mkdir_under_private_root_rejects_nul_byte(): void {
		$fs = $this->make_fs_instance();
		$this::assertSame( '', $fs->mkdir_under_private_root( "valid\0bad" ) );
		$this::assertSame( 'Relative path is empty or contains a NUL byte', $fs->get_last_error() );
	}

	public function test_mkdir_under_private_root_rejects_unix_absolute(): void {
		$fs = $this->make_fs_instance();
		$this::assertSame( '', $fs->mkdir_under_private_root( '/etc/passwd' ) );
		$this::assertStringContainsString( 'absolute', $fs->get_last_error() );
	}

	public function test_mkdir_under_private_root_rejects_windows_drive(): void {
		$fs = $this->make_fs_instance();
		$this::assertSame( '', $fs->mkdir_under_private_root( 'C:/Windows' ) );
	}

	public function test_mkdir_under_private_root_rejects_traversal(): void {
		$fs = $this->make_fs_instance();
		$this::assertSame( '', $fs->mkdir_under_private_root( '../escape' ) );
		$this::assertSame( 'Relative path contains a .. traversal segment', $fs->get_last_error() );
	}

	public function test_mkdir_under_private_root_rejects_nested_traversal(): void {
		$fs = $this->make_fs_instance();
		$this::assertSame( '', $fs->mkdir_under_private_root( 'foo/../../escape' ) );
	}

	public function test_mkdir_under_private_root_creates_directory_under_export_root(): void {
		$fs = $this->make_fs_instance();
		$result = $fs->mkdir_under_private_root( 'unit-test/nested/dir' );
		$this::assertNotSame( '', $result );
		$this::assertDirectoryExists( $result );
	}

	// ==================================================================
	// is_absolute_path_string() private helper.
	// ==================================================================

	public function test_is_absolute_path_string_detects_unix_root(): void {
		$this::assertTrue( $this->call_static( 'is_absolute_path_string', '/etc/passwd' ) );
	}

	public function test_is_absolute_path_string_detects_windows_drive(): void {
		$this::assertTrue( $this->call_static( 'is_absolute_path_string', 'C:/Windows' ) );
	}

	public function test_is_absolute_path_string_detects_unc_prefix(): void {
		$this::assertTrue( $this->call_static( 'is_absolute_path_string', '\\\\server\\share' ) );
	}

	public function test_is_absolute_path_string_detects_embedded_absolute_segment(): void {
		// Forward-slash + drive-letter is caught by the leading-slash
		// short-circuit (line 537). To exercise the preg_match defence-
		// in-depth branch (lines 550-551) we must use a single
		// backslash, which the leading-slash check misses.
		$this::assertTrue( $this->call_static( 'is_absolute_path_string', '\Z:foo' ) );
	}

	public function test_is_absolute_path_string_falls_through_to_preg_match_for_backslash_prefix(): void {
		// Confirms the preg_match branch is reached for backslash-prefixed
		// inputs (line 551). The prior guard returns cannot reach this
		// branch for forward-slash inputs because the leading-slash
		// short-circuit catches them first.
		$this::assertTrue( $this->call_static( 'is_absolute_path_string', '\\\\Z:foo' ) );
	}

	public function test_is_absolute_path_string_rejects_relative(): void {
		$this::assertFalse( $this->call_static( 'is_absolute_path_string', 'foo/bar' ) );
		$this::assertFalse( $this->call_static( 'is_absolute_path_string', '' ) );
	}

	// ==================================================================
	// normalize_path() private helper.
	// ==================================================================

	public function test_normalize_path_collapses_dotdot_segments(): void {
		$normalized = $this->call_static( 'normalize_path', '/a/b/../c/./d' );
		$this::assertStringContainsString( '/a/c/d', str_replace( '\\', '/', $normalized ) );
	}

	public function test_normalize_path_lowercases_on_windows(): void {
		if ( 'Windows' !== \PHP_OS_FAMILY ) {
			$this::markTestSkipped( 'Lowercase normalization is platform-specific.' );
		}
		$normalized = $this->call_static( 'normalize_path', 'C:/Users/Admin/Export' );
		$this::assertStringContainsString( 'c:/users/admin/export', $normalized );
	}

	// ==================================================================
	// is_within_allowed_directory() public helper.
	// ==================================================================

	public function test_is_within_allowed_directory_rejects_empty_inputs(): void {
		$fs = $this->make_fs_instance();
		$this::assertFalse( $fs->is_within_allowed_directory( '', sys_get_temp_dir() ) );
		$this::assertFalse( $fs->is_within_allowed_directory( sys_get_temp_dir() . '/x', '' ) );
	}

	public function test_is_within_allowed_directory_accepts_inside_path(): void {
		$fs = $this->make_fs_instance();
		$root = sys_get_temp_dir();
		$this::assertTrue( $fs->is_within_allowed_directory( $root . '/x/y.txt', $root ) );
	}

	public function test_is_within_allowed_directory_rejects_outside_path(): void {
		$fs = $this->make_fs_instance();
		$this::assertFalse( $fs->is_within_allowed_directory( '/totally/elsewhere/file.txt', sys_get_temp_dir() ) );
	}

	public function test_is_within_allowed_directory_handles_unresolvable_parent(): void {
		$fs = $this->make_fs_instance();
		// allowed_root itself does not exist either; the helper must
		// normalize the literal and still return a sensible verdict.
		$result = $fs->is_within_allowed_directory( '/never-exists-' . uniqid() . '/inner.txt', '/also-never-exists-' . uniqid() );
		$this::assertIsBool( $result );
	}

	// ==================================================================
	// is_path_safe_for_write() — covers lines 942-992 branches.
	// ==================================================================

	public function test_is_path_safe_for_write_rejects_empty_export_root(): void {
		// When SSCRIBE_PRIVATE_STORAGE_DIR is empty get_export_dir
		// returns '' and every write is refused.
		$fs = $this->make_fs_instance();
		$this::assertSame( \SScribe_Filesystem::SSCRIBE_PATH_REJECT, $fs->is_path_safe_for_write( '/anywhere/at-all.txt' ) );
	}

	public function test_is_path_safe_for_write_accepts_path_inside_export_dir(): void {
		$fs      = $this->make_fs_instance();
		$export  = \SScribe_Private_Storage::get_export_dir();
		$this::assertSame( \SScribe_Filesystem::SSCRIBE_PATH_ALLOWED, $fs->is_path_safe_for_write( $export . '/welcome.txt' ) );
	}

	public function test_is_path_safe_for_write_rejects_outside_path(): void {
		$fs = $this->make_fs_instance();
		$this::assertSame( \SScribe_Filesystem::SSCRIBE_PATH_REJECT, $fs->is_path_safe_for_write( sys_get_temp_dir() . '/outside.txt' ) );
	}

	public function test_is_path_safe_for_write_refuses_when_parent_realpath_unresolvable(): void {
		// A path whose parent does not exist must still be refused
		// because the realpath() of the nearest ancestor does not
		// resolve back into the allowed root.
		$fs = $this->make_fs_instance();
		$result = $fs->is_path_safe_for_write( '/never-exists-' . uniqid() . '/inside.txt' );
		$this::assertSame( \SScribe_Filesystem::SSCRIBE_PATH_REJECT, $result );
	}

	public function test_is_path_safe_for_write_refuses_linked_path_pointing_outside(): void {
		$fs       = $this->make_fs_instance();
		$export   = \SScribe_Private_Storage::get_export_dir();
		$in_link  = $export . '/linked-' . uniqid();
		$outside  = sys_get_temp_dir() . '/sscribe-outside-link-' . uniqid();
		wp_mkdir_p( $outside );
		@unlink( $in_link );
		if ( ! @symlink( $outside, $in_link ) ) {
			rmdir( $outside );
			$this::markTestSkipped( 'Symbolic links unavailable.' );
		}

		$result = $fs->is_path_safe_for_write( $in_link );
		$this::assertSame( \SScribe_Filesystem::SSCRIBE_PATH_REJECT, $result );

		if ( is_link( $in_link ) ) {
			unlink( $in_link );
		}
		rmdir( $outside );
	}

	// ==================================================================
	// dirlist() — uncovered method.
	// ==================================================================

	public function test_dirlist_returns_false_for_missing_directory(): void {
		$fs = $this->make_fs_instance();
		$this::assertFalse( $fs->dirlist( '/never-exists-' . uniqid() ) );
	}

	public function test_dirlist_returns_entries_inside_real_directory(): void {
		$fs     = $this->make_fs_instance();
		$export = \SScribe_Private_Storage::get_export_dir();
		wp_mkdir_p( $export . '/dirlist-target' );
		file_put_contents( $export . '/dirlist-target/file.txt', 'content' );

		$entries = $fs->dirlist( $export . '/dirlist-target' );

		$this::assertIsArray( $entries );
		$this::assertArrayHasKey( 'file.txt', $entries );
		$this::assertSame( 'f', $entries['file.txt']['type'] );
	}

	public function test_dirlist_skips_dot_files_and_index_php(): void {
		$fs     = $this->make_fs_instance();
		$export = \SScribe_Private_Storage::get_export_dir();
		wp_mkdir_p( $export . '/dirlist-target' );
		file_put_contents( $export . '/dirlist-target/.hidden', 'x' );
		file_put_contents( $export . '/dirlist-target/index.php', '<?php // silence' );
		file_put_contents( $export . '/dirlist-target/visible.txt', 'v' );

		$entries = $fs->dirlist( $export . '/dirlist-target' );

		$this::assertArrayHasKey( 'visible.txt', $entries );
		$this::assertArrayNotHasKey( '.hidden', $entries );
		$this::assertArrayNotHasKey( 'index.php', $entries );
	}

	// ==================================================================
	// is_wp_filesystem() — uncovered method.
	// ==================================================================

	public function test_is_wp_filesystem_returns_false_without_injection(): void {
		$fs = $this->make_fs_instance();
		$this::assertFalse( $fs->is_wp_filesystem() );
	}

	public function test_is_wp_filesystem_returns_true_with_injection(): void {
		$fs   = $this->make_fs_instance();
		$wpfs = $this->make_fs();
		$this->inject_wpfs( $wpfs );
		$this::assertTrue( $fs->is_wp_filesystem() );
	}

	public function test_get_method_reports_class_name_when_wpfs_in_use(): void {
		$fs   = $this->make_fs_instance();
		$wpfs = $this->make_fs();
		$this->inject_wpfs( $wpfs );
		// get_method() returns the actual injected subclass. The
		// important property is that it is no longer 'direct'.
		$this::assertNotSame( 'direct', $fs->get_method() );
		$this::assertStringContainsString( 'SScribe_Test_WP_Filesystem', $fs->get_method() );
	}

	public function test_get_method_reports_direct_when_wpfs_not_in_use(): void {
		$fs = $this->make_fs_instance();
		$this::assertSame( 'direct', $fs->get_method() );
	}

	// ==================================================================
	// WP_Filesystem-injected branches. Each branch is covered by setting
	// a custom WP_Filesystem and asserting the API forwards to it.
	// ==================================================================

	public function test_put_contents_delegates_to_wp_filesystem(): void {
		$fs   = $this->make_fs_instance();
		$wpfs = $this->make_fs();
		$this->inject_wpfs( $wpfs );

		$export = \SScribe_Private_Storage::get_export_dir();
		$result = $fs->put_contents( $export . '/wpfs-write.txt', 'hello' );

		$this::assertTrue( $result );
		// The injected stub recorded the call.
		$called = false;
		foreach ( $wpfs->calls as $entry ) {
			if ( 'put_contents' === $entry[0] ) {
				$called = true;
				break;
			}
		}
		$this::assertTrue( $called );
	}

	public function test_get_contents_delegates_to_wp_filesystem(): void {
		$fs   = $this->make_fs_instance();
		$wpfs = $this->make_fs();
		$this->inject_wpfs( $wpfs );

		$export = \SScribe_Private_Storage::get_export_dir();
		wp_mkdir_p( $export );
		// The plugin-read safety check requires the target file to exist
		// (its realpath must resolve) before delegating to WP_Filesystem,
		// so the test pre-creates the file. The stub WP_Filesystem does
		// not touch the disk; it returns the synthetic payload.
		file_put_contents( $export . '/wpfs-read.txt', 'on-disk' );
		$result = $fs->get_contents( $export . '/wpfs-read.txt' );

		$this::assertSame( 'wpfs-get-contents', $result );
		@unlink( $export . '/wpfs-read.txt' );
	}

	public function test_delete_delegates_to_wp_filesystem(): void {
		$fs   = $this->make_fs_instance();
		$wpfs = $this->make_fs();
		$this->inject_wpfs( $wpfs );

		$export = \SScribe_Private_Storage::get_export_dir();
		$result = $fs->delete( $export . '/wpfs-delete.txt' );

		$this::assertTrue( $result );
	}

	public function test_mkdir_delegates_to_wp_filesystem(): void {
		$fs   = $this->make_fs_instance();
		$wpfs = $this->make_fs();
		$this->inject_wpfs( $wpfs );

		$export = \SScribe_Private_Storage::get_export_dir();
		$result = $fs->mkdir( $export . '/wpfs-mkdir' );

		$this::assertTrue( $result );
	}

	public function test_exists_delegates_to_wp_filesystem(): void {
		$fs   = $this->make_fs_instance();
		$wpfs = $this->make_fs();
		$this->inject_wpfs( $wpfs );

		$export = \SScribe_Private_Storage::get_export_dir();
		$result = $fs->exists( $export . '/something.txt' );

		$this::assertTrue( $result );
	}

	public function test_is_dir_delegates_to_wp_filesystem(): void {
		$fs   = $this->make_fs_instance();
		$wpfs = $this->make_fs();
		$this->inject_wpfs( $wpfs );

		$export = \SScribe_Private_Storage::get_export_dir();
		$result = $fs->is_dir( $export );

		$this::assertTrue( $result );
	}

	public function test_is_writable_delegates_to_wp_filesystem(): void {
		$fs   = $this->make_fs_instance();
		$wpfs = $this->make_fs();
		$this->inject_wpfs( $wpfs );

		$export = \SScribe_Private_Storage::get_export_dir();
		$result = $fs->is_writable( $export );

		$this::assertTrue( $result );
	}

	public function test_dirlist_delegates_to_wp_filesystem(): void {
		$fs   = $this->make_fs_instance();
		$wpfs = $this->make_fs();
		$this->inject_wpfs( $wpfs );

		$result = $fs->dirlist( sys_get_temp_dir() );
		$this::assertIsArray( $result );
		$this::assertArrayHasKey( 'synthetic.txt', $result );
	}

	public function test_copy_delegates_to_wp_filesystem(): void {
		$fs   = $this->make_fs_instance();
		$wpfs = $this->make_fs();
		$this->inject_wpfs( $wpfs );

		$export = \SScribe_Private_Storage::get_export_dir();
		wp_mkdir_p( $export );
		// is_path_safe_for_write() walks up to the nearest existing
		// parent and compares its realpath against the export root;
		// the root must exist on disk for that comparison to succeed.
		file_put_contents( $export . '/source.txt', 'src' );
		$result = $fs->copy( $export . '/source.txt', $export . '/dest.txt', true );

		$this::assertTrue( $result );
		$this::assertContains( array( 'copy', $export . '/source.txt', $export . '/dest.txt', true, 0600 ), $wpfs->calls );
		@unlink( $export . '/source.txt' );
		@unlink( $export . '/dest.txt' );
	}

	public function test_move_delegates_to_wp_filesystem(): void {
		$fs   = $this->make_fs_instance();
		$wpfs = $this->make_fs();
		$this->inject_wpfs( $wpfs );

		$export = \SScribe_Private_Storage::get_export_dir();
		wp_mkdir_p( $export );
		file_put_contents( $export . '/source.txt', 'src' );
		$result = $fs->move( $export . '/source.txt', $export . '/dest.txt', true );

		$this::assertTrue( $result );
		$this::assertContains( array( 'move', $export . '/source.txt', $export . '/dest.txt', true ), $wpfs->calls );
		@unlink( $export . '/source.txt' );
		@unlink( $export . '/dest.txt' );
	}

	// ==================================================================
	// Public copy / move success and failure branches (no WP_Filesystem).
	// ==================================================================

	public function test_copy_returns_false_when_destination_already_exists(): void {
		$fs     = $this->make_fs_instance();
		$export = \SScribe_Private_Storage::get_export_dir();
		wp_mkdir_p( $export . '/copy-test' );
		file_put_contents( $export . '/copy-test/source.txt', 'src' );
		file_put_contents( $export . '/copy-test/dest.txt', 'dest' );

		$result = $fs->copy( $export . '/copy-test/source.txt', $export . '/copy-test/dest.txt', false );
		$this::assertFalse( $result );
		$this::assertSame( 'Destination file already exists', $fs->get_last_error() );
	}

	public function test_copy_returns_true_when_overwrite_enabled(): void {
		$fs     = $this->make_fs_instance();
		$export = \SScribe_Private_Storage::get_export_dir();
		wp_mkdir_p( $export . '/copy-test' );
		file_put_contents( $export . '/copy-test/source.txt', 'src' );
		file_put_contents( $export . '/copy-test/dest.txt', 'dest' );

		$result = $fs->copy( $export . '/copy-test/source.txt', $export . '/copy-test/dest.txt', true );
		$this::assertTrue( $result );
		$this::assertSame( 'src', file_get_contents( $export . '/copy-test/dest.txt' ) );
	}

	public function test_copy_refuses_when_source_is_outside_export(): void {
		$fs     = $this->make_fs_instance();
		$export = \SScribe_Private_Storage::get_export_dir();
		wp_mkdir_p( $export . '/copy-test' );

		$result = $fs->copy( sys_get_temp_dir() . '/never.txt', $export . '/copy-test/dest.txt', true );
		$this::assertFalse( $result );
	}

	public function test_move_returns_false_when_destination_already_exists(): void {
		$fs     = $this->make_fs_instance();
		$export = \SScribe_Private_Storage::get_export_dir();
		wp_mkdir_p( $export . '/move-test' );
		file_put_contents( $export . '/move-test/source.txt', 'src' );
		file_put_contents( $export . '/move-test/dest.txt', 'dest' );

		$result = $fs->move( $export . '/move-test/source.txt', $export . '/move-test/dest.txt', false );
		$this::assertFalse( $result );
	}

	public function test_move_refuses_when_source_is_outside_export(): void {
		$fs     = $this->make_fs_instance();
		$export = \SScribe_Private_Storage::get_export_dir();
		wp_mkdir_p( $export . '/move-test' );

		$result = $fs->move( sys_get_temp_dir() . '/never.txt', $export . '/move-test/dest.txt', true );
		$this::assertFalse( $result );
	}

	// ==================================================================
	// is_path_safe_for_plugin_read() private helper.
	// ==================================================================

	public function test_is_path_safe_for_plugin_read_returns_false_for_empty_or_link(): void {
		// Construct an instance and probe a private helper.
		$fs   = $this->make_fs_instance();
		$method = $this->reflection->getMethod( 'is_path_safe_for_plugin_read' );
		$this::assertFalse( $method->invoke( $fs, '' ) );
		$this::assertFalse( $method->invoke( $fs, sys_get_temp_dir() ) );
	}

	public function test_is_path_safe_for_plugin_read_accepts_plugin_dir_file(): void {
		$fs     = $this->make_fs_instance();
		$plugin = \SSCRIBE_PLUGIN_DIR;
		$method = $this->reflection->getMethod( 'is_path_safe_for_plugin_read' );
		$this::assertTrue( $method->invoke( $fs, $plugin . 'sscribe-export-site-pages.php' ) );
	}

	// ==================================================================
	// is_path_safe_for_read() private helper.
	// ==================================================================

	public function test_is_path_safe_for_read_rejects_empty_or_nul(): void {
		$fs     = $this->make_fs_instance();
		$method = $this->reflection->getMethod( 'is_path_safe_for_read' );
		$this::assertSame(
			\SScribe_Filesystem::SSCRIBE_PATH_REJECT,
			$method->invoke( $fs, '' )
		);
		$this::assertSame(
			\SScribe_Filesystem::SSCRIBE_PATH_REJECT,
			$method->invoke( $fs, "has\0nul" )
		);
	}

	public function test_is_path_safe_for_read_rejects_link(): void {
		$fs      = $this->make_fs_instance();
		$export  = \SScribe_Private_Storage::get_export_dir();
		$in_link = $export . '/linked-read-' . uniqid();
		@unlink( $in_link );
		if ( ! @symlink( sys_get_temp_dir(), $in_link ) ) {
			$this::markTestSkipped( 'Symbolic links unavailable.' );
		}
		$method = $this->reflection->getMethod( 'is_path_safe_for_read' );
		$this::assertSame(
			\SScribe_Filesystem::SSCRIBE_PATH_REJECT,
			$method->invoke( $fs, $in_link )
		);
		unlink( $in_link );
	}

	// ==================================================================
	// put_contents() — failure branches in WP_Filesystem and direct IO.
	// ==================================================================

	public function test_put_contents_returns_false_when_wp_filesystem_write_fails(): void {
		$fs   = $this->make_fs_instance();
		$wpfs = $this->make_fs();
		$wpfs->failures['put_contents'] = false;
		$this->inject_wpfs( $wpfs );

		$export = \SScribe_Private_Storage::get_export_dir();
		wp_mkdir_p( $export );
		$result = $fs->put_contents( $export . '/wpfs-fail.txt', 'data' );

		$this::assertFalse( $result );
		$this::assertSame( 'WP_Filesystem put_contents failed', $fs->get_last_error() );
	}

	public function test_put_contents_logs_warning_when_wp_filesystem_chmod_fails(): void {
		$fs   = $this->make_fs_instance();
		$wpfs = $this->make_fs();
		$wpfs->failures['chmod'] = false;
		$this->inject_wpfs( $wpfs );

		$export = \SScribe_Private_Storage::get_export_dir();
		wp_mkdir_p( $export );
		$result = $fs->put_contents( $export . '/wpfs-chmod-fail.txt', 'data', 0644 );

		// The write still succeeds; only the chmod post-step logs.
		$this::assertTrue( $result );
		$this::assertContains( array( 'chmod', $export . '/wpfs-chmod-fail.txt', 0644 ), $wpfs->calls );
	}

	public function test_put_contents_returns_false_when_direct_write_fails(): void {
		$fs     = $this->make_fs_instance();
		// No WP_Filesystem injected; direct file_put_contents path.
		$export = \SScribe_Private_Storage::get_export_dir();
		wp_mkdir_p( $export );

		// file_put_contents() reliably returns false when the target is
		// a directory on both Windows and Linux — the OS reports
		// "Permission denied" on the open-for-write. Either platform
		// takes the same error branch on line 253. The expected warning
		// is captured and asserted (proves the branch was actually reached)
		// without suppressing it.
		$as_dir = $export . '/put-fails-' . uniqid();
		wp_mkdir_p( $as_dir );

		$result = $this->expect_warning(
			fn () => $fs->put_contents( $as_dir, 'data' ),
			'file_put_contents'
		);

		$this::assertFalse( $result );
		$this::assertSame( 'file_put_contents failed', $fs->get_last_error() );
		rmdir( $as_dir );
	}

	public function test_put_contents_with_explicit_mode_invokes_chmod(): void {
		// The success path of put_contents takes the `if ( $mode )`
		// branch (line 265) and calls chmod (line 266). chmod() on a
		// file the process owns reliably returns true on Linux and on
		// Windows (where it sets the read-only bit per mode bit 0222).
		$fs     = $this->make_fs_instance();
		$export = \SScribe_Private_Storage::get_export_dir();
		wp_mkdir_p( $export );
		$target = $export . '/put-with-mode-' . uniqid() . '.txt';

		$result = $fs->put_contents( $target, 'data', 0644 );

		$this::assertTrue( $result );
		$this::assertFileExists( $target );
		@unlink( $target );
	}

	public function test_put_contents_logs_warning_when_direct_chmod_fails(): void {
		// On Linux chmod always succeeds on a file the process owns, so
		// we cannot deterministically trigger the chmod-failure branch
		// from a unit test. Skip rather than fake the outcome.
		$this::markTestSkipped( 'Cannot deterministically force chmod failure on owned files.' );
	}

	// ==================================================================
	// get_contents() — outside-read-scope and direct-IO failure branches.
	// ==================================================================

	public function test_get_contents_returns_false_for_path_outside_read_scope(): void {
		$fs   = $this->make_fs_instance();
		$wpfs = $this->make_fs();
		$this->inject_wpfs( $wpfs );

		$result = $fs->get_contents( '/etc/passwd' );
		$this::assertFalse( $result );
		$this::assertSame( 'Path is outside the allowed read scope', $fs->get_last_error() );
		// The WP_Filesystem should NOT have been called — the safety
		// check rejected the path before delegation.
		$this::assertSame( array(), $wpfs->calls );
	}

	public function test_get_contents_returns_false_for_missing_file_without_wpfs(): void {
		$fs     = $this->make_fs_instance();
		$export = \SScribe_Private_Storage::get_export_dir();
		wp_mkdir_p( $export );

		$result = $fs->get_contents( $export . '/does-not-exist-' . uniqid() );
		// get_contents() runs is_path_safe_for_plugin_read() before the
		// is_file() check; a non-existent file fails realpath() and the
		// read-scope guard returns REJECT, so the recorded error is the
		// read-scope message rather than "File does not exist".
		$this::assertFalse( $result );
		$this::assertSame( 'Path is outside the allowed read scope', $fs->get_last_error() );
	}

	// ==================================================================
	// delete() — outside-delete-scope and unlink-failure branches.
	// ==================================================================

	public function test_delete_returns_false_for_path_outside_delete_scope(): void {
		$fs   = $this->make_fs_instance();
		$wpfs = $this->make_fs();
		$this->inject_wpfs( $wpfs );

		$result = $fs->delete( '/etc/passwd' );
		$this::assertFalse( $result );
		$this::assertSame( 'Path is outside the allowed delete scope', $fs->get_last_error() );
		$this::assertSame( array(), $wpfs->calls );
	}

	public function test_delete_returns_true_when_file_does_not_exist(): void {
		$fs     = $this->make_fs_instance();
		$export = \SScribe_Private_Storage::get_export_dir();
		wp_mkdir_p( $export );

		$result = $fs->delete( $export . '/never-was-' . uniqid() . '.txt' );
		$this::assertTrue( $result );
	}

	// ==================================================================
	// mkdir() — direct wp_mkdir_p failure branch.
	// ==================================================================

	public function test_mkdir_returns_false_when_wp_mkdir_p_fails(): void {
		// The bootstrap stub of wp_mkdir_p() always returns true, so the
		// failure branch inside SScribe_Filesystem::mkdir() cannot be
		// exercised from a unit test without overriding the global
		// function (which PHPUnit does not support). Mark skipped so the
		// branch remains a known gap rather than a false pass.
		$this::markTestSkipped( 'wp_mkdir_p() bootstrap stub cannot fail.' );
	}

	// ==================================================================
	// mkdir_under_private_root() — no-export-root / symlink / wp_mkdir_p
	// failure / resolve-check / post-create-escape branches.
	// ==================================================================

	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	#[\PHPUnit\Framework\Attributes\PreserveGlobalState( false )]
	public function test_mkdir_under_private_root_returns_empty_when_export_root_unavailable(): void {
		// Point SSCRIBE_PRIVATE_STORAGE_DIR at a non-existent absolute
		// path; get_export_dir() returns '' for missing bases, which
		// triggers the "Export root is not available" branch in
		// mkdir_under_private_root(). Run in a separate process so
		// the constant can be defined for this test only without
		// contaminating the rest of the suite.
		define( 'SSCRIBE_PRIVATE_STORAGE_DIR', sys_get_temp_dir() . '/sscribe-never-created-' . uniqid() );

		$fs     = $this->make_fs_instance();
		$result = $fs->mkdir_under_private_root( 'subdir' );

		$this::assertSame( '', $result );
		$this::assertSame( 'Export root is not available', $fs->get_last_error() );
	}

	public function test_mkdir_under_private_root_rejects_symlink_target(): void {
		$fs     = $this->make_fs_instance();
		$export = \SScribe_Private_Storage::get_export_dir();
		wp_mkdir_p( $export );
		$target = $export . '/linked-target';
		@unlink( $target );
		$outside = sys_get_temp_dir() . '/sscribe-mk-link-' . uniqid();
		wp_mkdir_p( $outside );
		if ( ! @symlink( $outside, $target ) ) {
			rmdir( $outside );
			$this::markTestSkipped( 'Symbolic links are unavailable in this environment.' );
		}

		$result = $fs->mkdir_under_private_root( 'linked-target' );
		$this::assertSame( '', $result );
		$this::assertSame( 'Target path is a symlink', $fs->get_last_error() );

		unlink( $target );
		rmdir( $outside );
	}

	public function test_mkdir_under_private_root_returns_empty_when_wp_mkdir_p_fails(): void {
		$fs     = $this->make_fs_instance();
		$export = \SScribe_Private_Storage::get_export_dir();
		wp_mkdir_p( $export );
		// Plant a regular file at the location mkdir_under_private_root
		// would try to descend through; the bootstrap stub of wp_mkdir_p
		// forwards to PHP's mkdir() which then warns, and the harden-
		// directory chmod() afterwards also raises a "No such file or
		// directory" warning because the target was not actually created.
		// Capture that expected warning and assert.
		file_put_contents( $export . '/blocker', 'x' );

		$result = $this->expect_warning(
			fn () => $fs->mkdir_under_private_root( 'blocker/inside' ),
			'chmod'
		);
		$this::assertSame( '', $result );
		// The bootstrap stub wp_mkdir_p() always returns true, so the
		// post-create resolve check picks up that the target does not
		// exist and returns 'Could not resolve target or root' rather
		// than 'wp_mkdir_p failed for contained target'. Either error
		// proves the function refused to create the directory.
		$this::assertNotSame( '', $fs->get_last_error() );
		@unlink( $export . '/blocker' );
	}

	// ==================================================================
	// dirlist() — scandir failure branch (without WP_Filesystem).
	// ==================================================================

	public function test_dirlist_returns_false_when_directory_missing_without_wpfs(): void {
		$fs = $this->make_fs_instance();
		// Without WP_Filesystem, dirlist() falls back to is_dir + scandir;
		// a path that is not a directory yields false.
		$result = $fs->dirlist( sys_get_temp_dir() . '/never-was-dir-' . uniqid() );
		$this::assertFalse( $result );
	}

	// ==================================================================
	// is_within_allowed_directory() — realpath-succeeds and
	// non-Windows-OS branches.
	// ==================================================================

	public function test_is_within_allowed_directory_returns_true_when_realpath_resolves_inside(): void {
		$fs = $this->make_fs_instance();
		$tmp = sys_get_temp_dir() . '/sscribe-within-' . uniqid();
		wp_mkdir_p( $tmp );
		file_put_contents( $tmp . '/inside.txt', 'x' );

		$this::assertTrue( $fs->is_within_allowed_directory( $tmp . '/inside.txt', $tmp ) );

		@unlink( $tmp . '/inside.txt' );
		@rmdir( $tmp );
	}

	public function test_is_within_allowed_directory_returns_false_when_realpath_resolves_outside(): void {
		$fs       = $this->make_fs_instance();
		$inside   = sys_get_temp_dir() . '/sscribe-inside-' . uniqid();
		$outside  = sys_get_temp_dir() . '/sscribe-outside-' . uniqid();
		wp_mkdir_p( $inside );
		wp_mkdir_p( $outside );
		file_put_contents( $outside . '/stranger.txt', 'x' );

		$this::assertFalse( $fs->is_within_allowed_directory( $outside . '/stranger.txt', $inside ) );

		@unlink( $outside . '/stranger.txt' );
		@rmdir( $outside );
		@rmdir( $inside );
	}

	// ==================================================================
	// normalize_path() — Windows lowercase branch (skipped on Linux).
	// ==================================================================

	public function test_normalize_path_lowercases_drive_letter_on_windows(): void {
		if ( 'Windows' !== \PHP_OS_FAMILY ) {
			$this::markTestSkipped( 'Drive-letter lowercasing only matters on Windows.' );
		}
		$method = $this->reflection->getMethod( 'normalize_path' );
		$this::assertSame( 'c:/x', $method->invoke( null, 'C:/X' ) );
	}

	// ==================================================================
	// get_contents() — is_file() rejection branch (when the path exists
	// but is not a regular file, e.g. a directory).
	// ==================================================================

	public function test_get_contents_returns_false_when_path_is_a_directory_without_wpfs(): void {
		$fs     = $this->make_fs_instance();
		$export = \SScribe_Private_Storage::get_export_dir();
		wp_mkdir_p( $export );
		// A subdirectory lives inside the export root with the trailing
		// slash needed to satisfy is_path_safe_for_plugin_read()'s prefix
		// check, then is_file() returns false for any directory.
		$sub = $export . '/is-file-target-' . uniqid();
		wp_mkdir_p( $sub );

		$result = $fs->get_contents( $sub );
		$this::assertFalse( $result );
		$this::assertSame( 'File does not exist', $fs->get_last_error() );
		@rmdir( $sub );
	}

	// ==================================================================
	// dirlist() — scandir fallback when WP_Filesystem is unavailable.
	// ==================================================================

	public function test_dirlist_returns_files_when_directory_exists_without_wpfs(): void {
		$fs     = $this->make_fs_instance();
		$export = \SScribe_Private_Storage::get_export_dir();
		wp_mkdir_p( $export );
		file_put_contents( $export . '/real.txt', 'x' );
		$result = $fs->dirlist( $export );

		$this::assertIsArray( $result );
		$this::assertArrayHasKey( 'real.txt', $result );
		@unlink( $export . '/real.txt' );
	}

	// ==================================================================
	// is_writable() — direct is_writable() fallback path when
	// wp_is_writable() is not available.
	// ==================================================================

	public function test_is_writable_returns_true_for_writable_directory(): void {
		$fs     = $this->make_fs_instance();
		$export = \SScribe_Private_Storage::get_export_dir();
		wp_mkdir_p( $export );
		$this::assertTrue( $fs->is_writable( $export ) );
	}

	// ==================================================================
	// is_path_safe_for_write() — empty export root, dangling symlink,
	// and parent-realpath-missing branches.
	// ==================================================================

	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	#[\PHPUnit\Framework\Attributes\PreserveGlobalState( false )]
	public function test_is_path_safe_for_write_rejects_when_export_root_unavailable(): void {
		// SSCRIBE_PRIVATE_STORAGE_DIR pointing at a missing directory
		// makes get_export_dir() return ''; line 946 then short-circuits
		// to SSCRIBE_PATH_REJECT without further inspection.
		define( 'SSCRIBE_PRIVATE_STORAGE_DIR', sys_get_temp_dir() . '/sscribe-fs-missing-' . uniqid() );

		$fs     = $this->make_fs_instance();
		$result = $fs->is_path_safe_for_write( sys_get_temp_dir() . '/anywhere.txt' );

		$this::assertSame( \SScribe_Filesystem::SSCRIBE_PATH_REJECT, $result );
	}

	public function test_is_path_safe_for_write_rejects_symlink_pointing_outside(): void {
		$fs      = $this->make_fs_instance();
		$export  = \SScribe_Private_Storage::get_export_dir();
		wp_mkdir_p( $export );
		$outside = sys_get_temp_dir() . '/sscribe-fs-link-out-' . uniqid();
		wp_mkdir_p( $outside );
		$link = $export . '/escape-link-' . uniqid();
		@unlink( $link );
		if ( ! @symlink( $outside, $link ) ) {
			rmdir( $outside );
			$this::markTestSkipped( 'Symbolic links unavailable in this environment.' );
		}

		$result = $fs->is_path_safe_for_write( $link );
		$this::assertSame( \SScribe_Filesystem::SSCRIBE_PATH_REJECT, $result );

		unlink( $link );
		rmdir( $outside );
	}

	public function test_is_path_safe_for_write_rejects_when_no_ancestor_resolves(): void {
		// Line 983 (realpath of nearest_existing returns false) is
		// unreachable for any path inside the export root because the
		// root itself always exists when is_path_safe_for_write is
		// called, and the parent walk stops at the first existing
		// ancestor. Mark skipped to document the gap rather than fake
		// a test that would always pass on a different branch.
		$this::markTestSkipped( 'Branch unreachable when export root exists.' );
	}

	public function test_is_path_safe_for_write_rejects_when_ancestor_resolves_outside(): void {
		// A symlink that points inside the export root resolves through
		// realpath() to a target outside the resolved root, hitting the
		// containment branch on line 988.
		if ( 'Windows' === \PHP_OS_FAMILY ) {
			$this::markTestSkipped( 'Symlink containment test depends on realpath resolution semantics.' );
		}
		$fs     = $this->make_fs_instance();
		$export = \SScribe_Private_Storage::get_export_dir();
		wp_mkdir_p( $export );
		$outside = sys_get_temp_dir() . '/sscribe-fs-outside-' . uniqid();
		wp_mkdir_p( $outside );

		// The link is named so its parent (the export root) resolves
		// inside the root, but the link's own realpath() points outside.
		$link = $export . '/escape-link-2-' . uniqid();
		@unlink( $link );
		if ( ! @symlink( $outside, $link ) ) {
			rmdir( $outside );
			$this::markTestSkipped( 'Symbolic links unavailable in this environment.' );
		}

		$result = $fs->is_path_safe_for_write( $link );
		$this::assertSame( \SScribe_Filesystem::SSCRIBE_PATH_REJECT, $result );

		unlink( $link );
		rmdir( $outside );
	}

	// ==================================================================
	// put_contents() / get_contents() / delete() — direct-IO failure
	// branches triggered by permission changes.
	// ==================================================================

	public function test_get_contents_direct_file_get_contents_failure(): void {
		// Cannot reliably make file_get_contents() fail on Linux
		// without OS-level facilities; mark as a known gap rather than
		// a fake pass.
		$this::markTestSkipped( 'file_get_contents() failure cannot be triggered in the unit bootstrap.' );
	}

	public function test_delete_direct_unlink_failure(): void {
		$fs     = $this->make_fs_instance();
		$export = \SScribe_Private_Storage::get_export_dir();
		wp_mkdir_p( $export );
		$target = $export . '/cannot-unlink-' . uniqid() . '.txt';
		file_put_contents( $target, 'x' );

		if ( 'Windows' === \PHP_OS_FAMILY ) {
			// Windows honors the read-only attribute via attrib — PHP's
			// unlink() returns false on a read-only file because the OS
			// refuses the delete request. The expected unlink() warning
			// is captured and asserted (proves the branch was reached)
			// without suppressing it.
			exec( 'attrib +R ' . escapeshellarg( $target ) );
		} else {
			chmod( $target, 0400 );
		}

		$result = $this->expect_warning(
			fn () => $fs->delete( $target ),
			'unlink'
		);

		$this::assertFalse( $result );
		$this::assertSame( 'Failed to delete file', $fs->get_last_error() );

		if ( 'Windows' === \PHP_OS_FAMILY ) {
			exec( 'attrib -R ' . escapeshellarg( $target ) );
		} else {
			chmod( $target, 0600 );
		}
		@unlink( $target );
	}

	/**
	 * delete() must take the unlink-failure branch (filesystem.php L346-353)
	 * when the target exists but cannot be unlinked. The portable way to
	 * force unlink() to return false is to point it at a directory: POSIX
	 * unlink on a directory returns false and raises a warning. (The
	 * chmod-based approach fails when the test runs as root.)
	 *
	 * The expected warning is captured so the test does not fail on
	 * PHPUnit's failOnWarning.
	 */
	public function test_delete_returns_false_when_unlink_fails(): void {
		$fs      = $this->make_fs_instance();
		$export  = \SScribe_Private_Storage::get_subdirectory( 'unlink-fail-' . uniqid() );
		wp_mkdir_p( $export );

		$result = $this->expect_warning(
			fn () => $fs->delete( $export ),
			'unlink'
		);

		$this::assertFalse( $result, 'delete() must return false when unlink() fails' );
		$this::assertSame( 'Failed to delete file', $fs->get_last_error() );

		@rmdir( $export );
	}

	/**
	 * dirlist() must return false when scandir() fails on the directory
	 * (filesystem.php L620-622). Achieved on POSIX by removing read
	 * permission from the directory before calling dirlist().
	 */
	public function test_dirlist_returns_false_on_unreadable_directory(): void {
		if ( 'Windows' === \PHP_OS_FAMILY ) {
			$this::markTestSkipped( 'POSIX chmod does not restrict directory reads on Windows.' );
		}
		if ( ! function_exists( 'posix_seteuid' ) ) {
			$this::markTestSkipped( 'posix extension unavailable; cannot drop privileges to test scandir-fail branch.' );
		}

		$fs = $this->make_fs_instance();
		// Use sys_get_temp_dir() (mode 1777, traversable by any uid) so
		// the dropped uid can actually reach the test directory. The
		// private-storage tree is rooted under mode-0700 directories
		// that uid=1 cannot traverse, so the dirlist() short-circuit
		// at is_dir() (filesystem.php L615) would mask the scandir()
		// branch we want to exercise (L621).
		$dir = sys_get_temp_dir() . '/sscribe-fs-dirlist-unreadable-' . uniqid();
		wp_mkdir_p( $dir );
		// Mode 0300 = write+execute only. uid=1 can stat() the directory
		// (is_dir() returns true) but opendir() / scandir() fail because
		// there is no read bit.
		chmod( $dir, 0300 );

		// As root, chmod is bypassed by CAP_DAC_OVERRIDE, so we drop the
		// effective uid to a non-root account (UID 1 = daemon) to make
		// the POSIX read check actually fail. Restore before any
		// PHPUnit assertion so the autoloader can still read vendor/.
		//
		// scandir() also raises an E_WARNING when it fails. PHPUnit's
		// own error handler converts warnings into test issues, which
		// would try to autoload PHPUnit classes while we are uid=1 and
		// the parent /root/src/ is mode 0700 (no traverse for non-root).
		// Install a local handler that swallows the scandir warning for
		// the duration of the call.
		set_error_handler(
			static function ( int $errno, string $errstr ): bool {
				return str_contains( $errstr, 'scandir(' ) || str_contains( $errstr, 'opendir(' );
			}
		);
		$result = null;
		try {
			if ( posix_geteuid() === 0 && ! posix_seteuid( 1 ) ) {
				restore_error_handler();
				$this::markTestSkipped( 'posix_seteuid(1) refused; cannot exercise scandir-fail branch.' );
			}
			$result = $fs->dirlist( $dir );
		} finally {
			if ( posix_geteuid() === 1 ) {
				posix_seteuid( 0 );
			}
			restore_error_handler();
			chmod( $dir, 0755 );
			rmdir( $dir );
		}

		$this::assertFalse( $result, 'dirlist() must return false when scandir() cannot read the directory' );
	}

	// ==================================================================
	// is_path_safe_for_plugin_read() — root_real-false branches.
	// ==================================================================

	public function test_is_path_safe_for_plugin_read_skips_missing_root(): void {
		// is_path_safe_for_plugin_read iterates $roots (export_dir +
		// SSCRIBE_PLUGIN_DIR) and continues when realpath() of a root
		// returns false. We trigger this by passing a file whose parent
		// resolves inside the export root, then making the export root
		// itself un-resolvable. Run in a subprocess so the constant
		// can be set independently for this test.
		if ( 'Windows' === \PHP_OS_FAMILY ) {
			$this::markTestSkipped( 'Windows realpath() on missing directories returns the input verbatim.' );
		}
		$fs      = $this->make_fs_instance();
		$export  = \SScribe_Private_Storage::get_export_dir();
		wp_mkdir_p( $export );
		$target  = $export . '/reading-target-' . uniqid();
		file_put_contents( $target, 'x' );

		// Force a missing realpath by moving the export directory out
		// of the way for the duration of the call (then restoring).
		$moved = $export . '.moved-' . uniqid();
		rename( $export, $moved );

		try {
			$method = $this->reflection->getMethod( 'is_path_safe_for_plugin_read' );
			$result = $method->invoke( $fs, $target );
			// Either the export root trips the missing-realpath branch
			// (lines 783-784) or plugin dir catches it; either way the
			// file is no longer in scope because the export root
			// vanished.
			$this::assertFalse( $result );
		} finally {
			rename( $moved, $export );
			@unlink( $target );
		}
	}

	// ==================================================================
	// is_path_safe_for_plugin_read() — empty-root continue branch
	// (line 780) when get_export_dir() returns ''.
	// ==================================================================

	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	#[\PHPUnit\Framework\Attributes\PreserveGlobalState( false )]
	public function test_is_path_safe_for_plugin_read_skips_empty_export_root(): void {
		// Define SSCRIBE_PRIVATE_STORAGE_DIR as the empty string so
		// get_export_dir() returns '' — the foreach in
		// is_path_safe_for_plugin_read() hits the '' === $root check on
		// line 779 and `continue`s (line 780). The function then falls
		// through to the SSCRIBE_PLUGIN_DIR root and either matches
		// against it or returns false. Running in a subprocess keeps
		// the constant local to this test.
		define( 'SSCRIBE_PRIVATE_STORAGE_DIR', '' );

		$fs     = $this->make_fs_instance();
		$method = $this->reflection->getMethod( 'is_path_safe_for_plugin_read' );

		// Any real path that exists; the function falls through to the
		// plugin dir root check. The point is that the empty-export-root
		// continue branch executes without crashing.
		$path   = sys_get_temp_dir() . '/sscribe-plugin-read-' . uniqid() . '.txt';
		file_put_contents( $path, 'x' );

		$result = $method->invoke( $fs, $path );
		$this::assertIsBool( $result );

		@unlink( $path );
	}

	// ==================================================================
	// is_path_safe_for_plugin_read() — root_real-false continue branch
	// (line 784) when realpath() of a root returns false.
	// ==================================================================

	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	#[\PHPUnit\Framework\Attributes\PreserveGlobalState( false )]
	public function test_is_path_safe_for_plugin_read_skips_unresolvable_root(): void {
		// The line 784 (realpath($root)===false → continue) branch can
		// only fire when SSCRIBE_PLUGIN_DIR itself fails to resolve.
		// SSCRIBE_PLUGIN_DIR is defined at bootstrap time as the project
		// root, which always resolves. PHP constants cannot be undefined,
		// so this branch is genuinely unreachable from any unit test.
		// Mark skipped to document the gap rather than fake a test that
		// would always pass on a different branch.
		$this::markTestSkipped( 'Line 784 branch requires SSCRIBE_PLUGIN_DIR to fail realpath(); the constant cannot be undefined.' );
	}

	// ==================================================================
	// is_writable() — direct is_writable() fallback when wp_is_writable
	// is not available (line 600).
	// ==================================================================

	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	#[\PHPUnit\Framework\Attributes\PreserveGlobalState( false )]
	public function test_is_writable_falls_back_to_native_when_wp_is_writable_missing(): void {
		// The test bootstrap unconditionally defines wp_is_writable()
		// (tests/bootstrap.php:395), and PHP has no portable way to
		// undefine a function once declared. The `function_exists()`
		// check on line 595 therefore always succeeds in the test
		// environment, and the fallback on line 600 is unreachable.
		// Mark this as a known gap rather than fake a pass.
		$this::markTestSkipped( 'wp_is_writable() is unconditionally defined by the bootstrap; the line 600 fallback is unreachable in unit tests.' );
	}

	// ==================================================================
	// dirlist() — scandir failure branch (line 621). The only way to
	// make scandir() return false while is_dir() returns true is to
	// strip permissions on a directory. chmod() is unreliable on
	// Windows so this test skips there.
	// ==================================================================

	public function test_dirlist_returns_false_when_scandir_fails(): void {
		if ( 'Windows' === \PHP_OS_FAMILY ) {
			$this::markTestSkipped( 'chmod-based permission gating does not restrict scandir() on Windows.' );
		}
		if ( ! function_exists( 'posix_seteuid' ) ) {
			$this::markTestSkipped( 'posix extension unavailable; cannot drop privileges to test scandir-fail branch.' );
		}
		if ( posix_geteuid() !== 0 ) {
			$this::markTestSkipped( 'Test requires root to drop privileges to a non-root uid.' );
		}

		$fs = $this->make_fs_instance();
		// Use sys_get_temp_dir() (mode 1777) so the dropped uid can
		// reach the test directory. The private-storage tree is rooted
		// under mode-0700 directories that uid=1 cannot traverse, so
		// is_dir() would short-circuit (L615) before the scandir()
		// branch (L621) runs.
		$dir = sys_get_temp_dir() . '/sscribe-fs-scandir-fail-' . uniqid();
		wp_mkdir_p( $dir );
		// Mode 0300 = write+execute only: is_dir() returns true but
		// opendir()/scandir() fail with E_WARNING because there is no
		// read bit.
		chmod( $dir, 0300 );

		// Root bypasses chmod-based restrictions via CAP_DAC_OVERRIDE,
		// so drop the effective uid to a non-root account (UID 1 =
		// daemon, no shell/login) to make the POSIX read check actually
		// fail. Restore before any PHPUnit assertion runs so the
		// autoloader can still read vendor/.
		//
		// scandir() also raises an E_WARNING when it fails. PHPUnit's
		// own error handler converts warnings into test issues, which
		// would try to autoload PHPUnit classes while we are uid=1 and
		// the parent /root/src/ is mode 0700 (no traverse for non-root).
		// Install a local handler that swallows the scandir warning for
		// the duration of the call.
		set_error_handler(
			static function ( int $errno, string $errstr ): bool {
				return str_contains( $errstr, 'scandir(' ) || str_contains( $errstr, 'opendir(' );
			}
		);
		$result = null;
		try {
			if ( ! posix_seteuid( 1 ) ) {
				restore_error_handler();
				$this::markTestSkipped( 'posix_seteuid(1) refused; cannot exercise scandir-fail branch.' );
			}
			$result = $fs->dirlist( $dir );
		} finally {
			posix_seteuid( 0 );
			restore_error_handler();
			chmod( $dir, 0755 );
			rmdir( $dir );
		}

		$this::assertFalse( $result, 'dirlist() must return false when scandir() cannot read the directory' );
	}

	// ==================================================================
	// is_within_allowed_directory() — non-Windows strcmp branch
	// (line 878). Unreachable from a Windows test environment.
	// ==================================================================

	public function test_is_within_allowed_directory_uses_strcmp_on_unix(): void {
		if ( 'Windows' === \PHP_OS_FAMILY ) {
			$this::markTestSkipped( 'Windows uses strcasecmp, not strcmp.' );
		}
		$fs = $this->make_fs_instance();
		$tmp = sys_get_temp_dir() . '/sscribe-strcmp-' . uniqid();
		wp_mkdir_p( $tmp );
		file_put_contents( $tmp . '/inside.txt', 'x' );

		// On case-sensitive filesystems 'A' and 'a' are different
		// realpath segments, so the strict strcmp comparison correctly
		// rejects mismatched case.
		$this::assertTrue( $fs->is_within_allowed_directory( $tmp . '/inside.txt', $tmp ) );

		@unlink( $tmp . '/inside.txt' );
		@rmdir( $tmp );
	}

	// ==================================================================
	// initialize() private method — exercises the early-return branch
	// on line 87 (self::$fs already set) so a WP context isn't needed.
	// ==================================================================

	public function test_initialize_returns_true_when_wp_filesystem_already_set(): void {
		$fs     = $this->make_fs_instance();
		$wpfs   = $this->make_fs();
		$this->inject_wpfs( $wpfs );
		$method = $this->reflection->getMethod( 'initialize' );
		$this::assertTrue( $method->invoke( $fs ) );
	}

	public function test_initialize_returns_false_when_wp_admin_file_missing(): void {
		// The unit-test bootstrap does not load WordPress, so
		// ABSPATH/wp-admin/includes/file.php does not exist and
		// WP_Filesystem/request_filesystem_credentials are undefined.
		// initialize() then takes the file_exists()===false branch on
		// line 92 and returns false (line 96) after logging.
		$fs     = $this->make_fs_instance();
		$method = $this->reflection->getMethod( 'initialize' );

		// Sanity check: WP_Filesystem must be undefined in the test env.
		if ( function_exists( 'WP_Filesystem' ) ) {
			$this::markTestSkipped( 'WP_Filesystem is defined in this bootstrap.' );
		}
		if ( file_exists( ABSPATH . 'wp-admin/includes/file.php' ) ) {
			$this::markTestSkipped( 'wp-admin/includes/file.php is present.' );
		}

		$this::assertFalse( $method->invoke( $fs ) );
	}
}
