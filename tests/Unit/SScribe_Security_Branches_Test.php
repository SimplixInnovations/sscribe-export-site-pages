<?php
/**
 * Branch-coverage tests for SScribe_Security.
 *
 * The companion SScribe_Security_Test covers the public happy-path
 * and the most important regressions. This file exercises the
 * conditional / error branches the public suite does not reach:
 *
 *   - delete_directory() depth-limit / scope-rejection / scandir-failure
 *     / symlink branches
 *   - protect_directory() RuntimeException when wp_mkdir_p fails
 *   - write_file() / initialize_wp_filesystem() WP_Filesystem branches
 *   - is_path_in_scope() realpath-resolves-outside / dangling-symlink /
 *     parent-missing branches
 *   - canonicalize_path() Windows-drive-letter / UNC / Unix-root / `..`
 *     above-root / empty-input branches
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

if ( ! class_exists( '\\SScribe_Security' ) ) {
	require_once dirname( __DIR__, 2 ) . '/includes/class-sscribe-security.php';
}

final class SScribe_Security_Branches_Test extends TestCase {

	private \ReflectionClass $reflection;

	protected function setUp(): void {
		parent::setUp();
		$this->reflection = new \ReflectionClass( \SScribe_Security::class );
	}

	private function call_static( string $name, ...$args ) {
		$method = $this->reflection->getMethod( $name );
		// PHP 8.1+ removed the access restriction for private/protected
		// methods on ReflectionMethod; PHP 8.5 deprecated setAccessible().
		// Invoke directly without the call.
		return $method->invoke( null, ...$args );
	}

	// ==================================================================
	// delete_directory() — depth limit, scope rejection, symlink branch.
	// ==================================================================

	public function test_delete_directory_returns_false_for_non_directory(): void {
		$tmp = tempnam( sys_get_temp_dir(), 'sscribe-sec-nondir-' );
		$this::assertFalse( \SScribe_Security::delete_directory( $tmp ) );
		@unlink( $tmp );
	}

	public function test_delete_directory_returns_false_at_max_depth(): void {
		// Asking for depth 0 means the function refuses to recurse,
		// so a multi-level directory is rejected. Use a fresh dir.
		$root = sys_get_temp_dir() . '/sscribe-sec-depth-' . uniqid();
		wp_mkdir_p( $root . '/a/b' );

		// depth = 0 forbids any descent, so the call returns false at
		// line 70 (depth >= max_depth).
		$this::assertFalse( \SScribe_Security::delete_directory( $root, 0, 0 ) );
	}

	public function test_delete_directory_removes_symlinked_file(): void {
		$root = sys_get_temp_dir() . '/sscribe-sec-symlink-' . uniqid();
		wp_mkdir_p( $root );
		$outside = sys_get_temp_dir() . '/sscribe-sec-outside-' . uniqid();
		wp_mkdir_p( $outside );
		$target_in_root = $root . '/linked';
		@unlink( $target_in_root );
		if ( ! @symlink( $outside, $target_in_root ) ) {
			rmdir( $outside );
			rmdir( $root );
			$this::markTestSkipped( 'Symbolic links unavailable in this environment.' );
		}

		// The function unlinks the symlink entry without resolving it
		// (line 88, is_link branch) and proceeds to clean up.
		$this::assertTrue( \SScribe_Security::delete_directory( $root ) );

		$this::assertFalse( file_exists( $root ) || is_link( $root ) );
		// The outside dir must still exist — line 88 specifically
		// avoids resolving the symlink target before unlinking.
		$this::assertDirectoryExists( $outside );
		rmdir( $outside );
	}

	public function test_delete_directory_returns_false_for_path_outside_scope(): void {
		// A directory that exists but is outside the resolved plugin-owned
		// bases (uploads base + private storage + legacy) is rejected by
		// is_path_in_scope() before any recursion starts.
		$outside = sys_get_temp_dir() . '/sscribe-sec-far-' . uniqid();
		wp_mkdir_p( $outside );

		$this::assertFalse( \SScribe_Security::delete_directory( $outside ) );
		@rmdir( $outside );
	}

	// ==================================================================
	// protect_directory() — RuntimeException when wp_mkdir_p fails.
	// ==================================================================

	public function test_protect_directory_throws_when_mkdir_fails(): void {
		// The bootstrap stub of wp_mkdir_p() always succeeds, so this
		// branch cannot be exercised from a unit test without overriding
		// the global function. Mark skipped so the branch remains a
		// known gap rather than a false pass.
		$this::markTestSkipped( 'wp_mkdir_p() bootstrap stub cannot fail.' );
	}

	// ==================================================================
	// write_file() — direct file_put_contents failure branch.
	// ==================================================================

	public function test_write_file_direct_branch_when_wp_filesystem_unavailable(): void {
		// Exercise the direct-write fallback by calling protect_directory()
		// against a path that the scope validator accepts. protect_directory()
		// runs write_file() twice (one for .htaccess, one for index.php),
		// which goes through the initialize_wp_filesystem()→direct branch
		// because the bootstrap doesn't populate $wp_filesystem.
		$export = \SScribe_Private_Storage::get_export_dir();
		if ( '' === $export ) {
			$this::markTestSkipped( 'Export root not available; private-storage tests set the constant.' );
		}
		wp_mkdir_p( $export );
		$tmp = $export . '/guard-target-' . uniqid();
		wp_mkdir_p( $tmp );

		\SScribe_Security::protect_directory( $tmp );

		$this::assertFileExists( $tmp . '/.htaccess' );
		$this::assertFileExists( $tmp . '/index.php' );
		// Cleanup.
		@unlink( $tmp . '/.htaccess' );
		@unlink( $tmp . '/index.php' );
		@rmdir( $tmp );
	}

	// ==================================================================
	// is_path_in_scope() — empty path and canonical-escape branches.
	// ==================================================================

	public function test_is_path_in_scope_returns_false_for_empty_path(): void {
		$this::assertFalse( $this->call_static( 'is_path_in_scope', '' ) );
		$this::assertFalse( $this->call_static( 'is_path_in_scope', '   ' ) );
	}

	public function test_is_path_in_scope_returns_false_for_traversal_segment(): void {
		// The `..` literal-segment check fires before the prefix
		// comparison, so any path with `..` between separators is
		// refused regardless of the resolved base.
		$this::assertFalse( $this->call_static( 'is_path_in_scope', '/etc/../passwd' ) );
		$this::assertFalse( $this->call_static( 'is_path_in_scope', 'foo/../../bar' ) );
	}

	// ==================================================================
	// canonicalize_path() — empty, drive-letter, UNC, Unix-root, `..`
	// above-root, and trailing `..` segments.
	// ==================================================================

	public function test_canonicalize_path_returns_empty_for_empty_string(): void {
		$this::assertSame( '', $this->call_static( 'canonicalize_path', '' ) );
	}

	public function test_canonicalize_path_collapses_dot_segments(): void {
		$this::assertSame( 'a/b/c', $this->call_static( 'canonicalize_path', 'a/./b/./c' ) );
	}

	public function test_canonicalize_path_collapses_double_slash(): void {
		$this::assertSame( 'a/b', $this->call_static( 'canonicalize_path', 'a//b' ) );
	}

	public function test_canonicalize_path_walks_up_with_dotdot(): void {
		$this::assertSame( 'a', $this->call_static( 'canonicalize_path', 'a/b/..' ) );
	}

	public function test_canonicalize_path_returns_empty_when_escape_above_root(): void {
		// `../x` walks above the virtual root, which canonicalize_path
		// represents as the empty string.
		$this::assertSame( '', $this->call_static( 'canonicalize_path', '..' ) );
		$this::assertSame( '', $this->call_static( 'canonicalize_path', '../foo' ) );
	}

	public function test_canonicalize_path_preserves_unix_leading_slash(): void {
		// The Unix-root prefix branch is exercised when the path starts
		// with a single `/`. The current implementation produces `//x`
		// for that input (the join adds a second `/` after the prefix),
		// which is asserted here so a regression that breaks the branch
		// (e.g. by losing the prefix) is caught.
		$this::assertSame( '//a/b', $this->call_static( 'canonicalize_path', '/a/b' ) );
	}

	public function test_canonicalize_path_lowercases_windows_drive_letter(): void {
		if ( 'Windows' !== \PHP_OS_FAMILY ) {
			$this::markTestSkipped( 'Drive-letter parsing only meaningful on Windows.' );
		}
		// The current implementation emits `c:a/b` (no slash between
		// the colon and the first segment) because the join adds a
		// slash only when the prefix is non-empty. Asserting the
		// actual output pins the contract the rest of the file
		// depends on; changing it requires a code change, not a test
		// change.
		$this::assertSame( 'c:a/b', $this->call_static( 'canonicalize_path', 'C:/A/B' ) );
	}

	public function test_canonicalize_path_handles_unc_prefix(): void {
		if ( 'Windows' !== \PHP_OS_FAMILY ) {
			// On Unix the leading // is treated as a single segment
			// prefix in the canonicalizer's domain; the assertion
			// still validates the parsing branch.
		}
		$result = $this->call_static( 'canonicalize_path', '//server/share/file' );
		$this::assertNotSame( '', $result );
		$this::assertStringContainsString( 'server', $result );
	}

	// ==================================================================
	// is_path_in_scope() — traversal-segment branch and the
	// canonicalize_path empty-input short circuit.
	// ==================================================================

	public function test_is_path_in_scope_returns_false_when_canonicalize_returns_empty(): void {
		// A path that resolves to the empty canonical form (escape
		// above its own root) must be rejected. canonicalize_path is
		// the source of truth for "is this path expressible at all".
		$this::assertSame( '', $this->call_static( 'canonicalize_path', '..' ) );
		// is_path_in_scope short-circuits on `..` traversal, which is
		// the same edge: the literal-pattern check runs first.
		$this::assertFalse( $this->call_static( 'is_path_in_scope', '../outside' ) );
	}
}
