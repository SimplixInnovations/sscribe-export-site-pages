<?php
/**
 * SScribe Filesystem Unit Test
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

class SScribe_Filesystem_Test extends TestCase {

	private string $test_dir;

	protected function setUp(): void {
		parent::setUp();
		$this->test_dir = sys_get_temp_dir() . '/sscribe-filesystem-test-' . uniqid();
		mkdir( $this->test_dir, 0755, true );
	}

	protected function tearDown(): void {
		if ( is_dir( $this->test_dir ) ) {
			$this->deleteDirectory( $this->test_dir );
		}
		parent::tearDown();
	}

	private function deleteDirectory( string $dir ): void {
		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \RecursiveDirectoryIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $items as $item ) {
			if ( $item->isDir() ) {
				rmdir( $item->getRealPath() );
			} else {
				unlink( $item->getRealPath() );
			}
		}

		rmdir( $dir );
	}

	public function test_put_contents_creates_file(): void {
		$fs     = new \SScribe_Filesystem();
		$file   = $this->test_dir . '/test.txt';
		$result = $fs->put_contents( $file, 'Hello World' );

		$this->assertTrue( $result );
		$this->assertFileExists( $file );
		$this->assertEquals( 'Hello World', file_get_contents( $file ) );
	}

	public function test_get_contents_reads_file(): void {
		$fs   = new \SScribe_Filesystem();
		$file = $this->test_dir . '/test-read.txt';
		file_put_contents( $file, 'Test content' );

		$contents = $fs->get_contents( $file );

		$this->assertEquals( 'Test content', $contents );
	}

	public function test_get_contents_returns_false_for_missing_file(): void {
		$fs   = new \SScribe_Filesystem();
		$file = $this->test_dir . '/nonexistent.txt';

		$result = $fs->get_contents( $file );

		$this->assertFalse( $result );
	}

	public function test_delete_removes_file(): void {
		$fs   = new \SScribe_Filesystem();
		$file = $this->test_dir . '/to-delete.txt';
		file_put_contents( $file, 'Delete me' );

		$result = $fs->delete( $file );

		$this->assertTrue( $result );
		$this->assertFileDoesNotExist( $file );
	}

	public function test_exists_checks_file_existence(): void {
		$fs   = new \SScribe_Filesystem();
		$file = $this->test_dir . '/exists.txt';
		file_put_contents( $file, 'I exist' );

		$this->assertTrue( $fs->exists( $file ) );
		$this->assertFalse( $fs->exists( $this->test_dir . '/not-exists.txt' ) );
	}

	public function test_is_dir_checks_directory(): void {
		$fs     = new \SScribe_Filesystem();
		$subdir = $this->test_dir . '/subdir';
		mkdir( $subdir );

		$this->assertTrue( $fs->is_dir( $subdir ) );
		$this->assertFalse( $fs->is_dir( $this->test_dir . '/nonexistent' ) );
	}

	public function test_is_writable_checks_write_permissions(): void {
		$fs = new \SScribe_Filesystem();

		$this->assertTrue( $fs->is_writable( $this->test_dir ) );
	}

	public function test_copy_copies_file(): void {
		$fs       = new \SScribe_Filesystem();
		$source   = $this->test_dir . '/source.txt';
		$dest     = $this->test_dir . '/dest.txt';
		file_put_contents( $source, 'Copy me' );

		$result = $fs->copy( $source, $dest );

		$this->assertTrue( $result );
		$this->assertFileExists( $dest );
		$this->assertEquals( 'Copy me', file_get_contents( $dest ) );
	}

	public function test_move_renames_file(): void {
		$fs     = new \SScribe_Filesystem();
		$source = $this->test_dir . '/move-source.txt';
		$dest   = $this->test_dir . '/move-dest.txt';
		file_put_contents( $source, 'Move me' );

		$result = $fs->move( $source, $dest );

		$this->assertTrue( $result );
		$this->assertFileDoesNotExist( $source );
		$this->assertFileExists( $dest );
		$this->assertEquals( 'Move me', file_get_contents( $dest ) );
	}

	public function test_mkdir_creates_directory(): void {
		$fs     = new \SScribe_Filesystem();
		$subdir = $this->test_dir . '/newdir/subdir';

		$result = $fs->mkdir( $subdir );

		$this->assertTrue( $result );
		$this->assertDirectoryExists( $subdir );
	}

	public function test_sanitize_path_strips_traversal_segments(): void {
		$unsafe = $this->test_dir . '/../../../etc/passwd';
		$safe   = \SScribe_Filesystem::sanitize_path( $unsafe );

		// ".." segments must not survive anywhere in the path.
		$this->assertStringNotContainsString( '..', $safe );
		// The path must remain rooted at test_dir — no escape via traversal.
		// Normalize both sides to forward slashes for cross-platform comparison.
		$test_dir_norm      = str_replace( '\\', '/', rtrim( $this->test_dir, '/' ) );
		$this->assertStringStartsWith( $test_dir_norm, $safe );
	}

	public function test_sanitize_path_preserves_directory_component(): void {
		$path = '/var/www/uploads/file-name.txt';
		$safe = \SScribe_Filesystem::sanitize_path( $path );

		$this->assertEquals( '/var/www/uploads/file-name.txt', $safe );
	}

	public function test_sanitize_path_handles_basename_only(): void {
		$safe = \SScribe_Filesystem::sanitize_path( 'simple-file.txt' );

		$this->assertEquals( 'simple-file.txt', $safe );
	}

	public function test_sanitize_path_strips_null_bytes(): void {
		$unsafe = $this->test_dir . "/file\x00name.txt";
		$safe   = \SScribe_Filesystem::sanitize_path( $unsafe );

		$this->assertStringNotContainsString( "\0", $safe );
	}

	public function test_put_contents_sanitizes_traversal_in_path(): void {
		$fs     = new \SScribe_Filesystem();
		$unsafe = $this->test_dir . '/../../../tmp/evil.txt';

		// After sanitization the file should land inside test_dir
		// (not escape to /tmp). The ".." segments are stripped, leaving
		// "<test_dir>/tmp/evil.txt".
		$result = $fs->put_contents( $unsafe, 'content' );

		$this->assertTrue( $result );
		// File must be created inside test_dir, not in /tmp.
		$expected = $this->test_dir . '/tmp/evil.txt';
		$this->assertFileExists( $expected );
		$this->assertFileDoesNotExist( '/tmp/evil.txt' );
	}

	public function test_sanitize_path_preserves_windows_drive_letter(): void {
		$path = 'C:/Users/test/file.txt';
		$safe = \SScribe_Filesystem::sanitize_path( $path );

		// Drive letter and following path preserved.
		$this->assertEquals( 'C:/Users/test/file.txt', $safe );
	}

	public function test_sanitize_path_preserves_spaces_in_filename(): void {
		// Legitimate filenames with spaces should pass through unchanged.
		// Language is no longer in the filename (the output dir carries it),
		// so this fixture uses the new clean format.
		$path = sys_get_temp_dir() . '/sscribe test/P001-Test Markdown Page-42.md';
		$safe = \SScribe_Filesystem::sanitize_path( $path );

		$this->assertEquals( $path, $safe );
	}

	public function test_sanitize_path_preserves_unicode_in_filename(): void {
		$path = sys_get_temp_dir() . '/صفحة عربية.md';
		$safe = \SScribe_Filesystem::sanitize_path( $path );

		$this->assertEquals( $path, $safe );
	}

	/**
	 * Symlink attack protection: put_contents() must reject writes to
	 * files whose parent directory is a symlink that escapes the
	 * SScribe export directory.
	 */
	public function test_put_contents_rejects_symlink_escape(): void {
		$fs = new \SScribe_Filesystem();

		// Create an "outside" target and a symlink under what would
		// conceptually be the SScribe export dir.
		$outside   = $this->test_dir . '/outside-target';
		$link_dir  = $this->test_dir . '/exports';
		$link_name = $link_dir . '/evil';

		mkdir( $outside, 0755, true );
		mkdir( $link_dir, 0755, true );
		symlink( $outside, $link_name );

		// Pretend the SScribe export dir IS this test's exports dir.
		// is_path_safe_for_write() compares lexically against the
		// SSCRIBE export dir (computed from wp_upload_dir()). The test
		// validates the *helper* directly.
		$safe = $fs->is_path_safe_for_write( $link_name . '/file.txt' );

		$this->assertEquals( \SScribe_Filesystem::SSCRIBE_PATH_REJECT, $safe,
			'Expected symlink escape to be REJECTed' );
	}

	/**
	 * Symlink attack protection: copy() must also reject destinations
	 * that resolve outside the SScribe export directory.
	 */
	public function test_copy_rejects_symlink_escape(): void {
		$fs = new \SScribe_Filesystem();

		$outside   = $this->test_dir . '/outside-target-2';
		$link_dir  = $this->test_dir . '/exports-2';
		$link_name = $link_dir . '/evil-copy';

		mkdir( $outside, 0755, true );
		mkdir( $link_dir, 0755, true );
		symlink( $outside, $link_name );

		$safe = $fs->is_path_safe_for_write( $link_name );

		$this->assertEquals( \SScribe_Filesystem::SSCRIBE_PATH_REJECT, $safe,
			'Expected symlink escape via copy destination to be REJECTed' );
	}

	/**
	 * is_within_allowed_directory() must return true for paths
	 * that are lexically inside the allowed root.
	 */
	public function test_is_within_allowed_directory_accepts_inside(): void {
		$fs = new \SScribe_Filesystem();

		$allowed = $this->test_dir . '/exports';
		mkdir( $allowed, 0755, true );

		$inside = $allowed . '/sub/dir/file.txt';
		$this->assertTrue( $fs->is_within_allowed_directory( $inside, $allowed ) );
	}

	/**
	 * is_within_allowed_directory() must return false for paths
	 * outside the allowed root (and not equal to it).
	 */
	public function test_is_within_allowed_directory_rejects_outside(): void {
		$fs = new \SScribe_Filesystem();

		$allowed = $this->test_dir . '/exports';
		$outside = $this->test_dir . '/other/file.txt';
		$this->assertFalse( $fs->is_within_allowed_directory( $outside, $allowed ) );
	}

	/**
	 * is_path_safe_for_write() should return EXTERNAL for files that
	 * are lexically *outside* the SScribe export dir — e.g. WP temp —
	 * because the caller is performing a legitimate external write.
	 */
	public function test_is_path_safe_for_write_allows_external_writes(): void {
		$fs = new \SScribe_Filesystem();

		// sys_get_temp_dir() is outside the SScribe export dir.
		$temp = sys_get_temp_dir() . '/sscribe-external-test.txt';
		$safe = $fs->is_path_safe_for_write( $temp );

		$this->assertEquals( \SScribe_Filesystem::SSCRIBE_PATH_EXTERNAL, $safe,
			'Expected WP temp dir writes to be allowed (EXTERNAL)' );
	}
}
