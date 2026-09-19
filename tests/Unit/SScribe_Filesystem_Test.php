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
	private string $export_dir;

	protected function setUp(): void {
		parent::setUp();
		$this->test_dir = sys_get_temp_dir() . '/sscribe-filesystem-test-' . uniqid();
		mkdir( $this->test_dir, 0755, true );

		$this->export_dir = \SScribe_Private_Storage::get_subdirectory( 'test-filesystem-' . uniqid() );
		if ( ! is_dir( $this->export_dir ) ) {
			mkdir( $this->export_dir, 0755, true );
		} else {

			// Wipe stale files left behind by previous tests so a
			// copy()/put_contents() with overwrite=false does not
			// collide with leftover state from earlier runs.
			$leftover = glob( $this->export_dir . '/{,.}*', GLOB_BRACE );
			if ( is_array( $leftover ) ) {
				foreach ( $leftover as $candidate ) {
					$name = basename( $candidate );
					if ( '.' === $name || '..' === $name ) {
						continue;
					}
					if ( is_file( $candidate ) ) {
						@unlink( $candidate );
					}
				}
			}
		}
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

	/**
	 * Build a path inside the SScribe export dir (where writes are
	 * permitted) instead of sys_get_temp_dir() (where they are not).
	 */
	private function in_export_dir( string $name ): string {
		return $this->export_dir . '/' . ltrim( $name, '/' );
	}

	public function test_put_contents_creates_file(): void {
		$fs     = new \SScribe_Filesystem();
		$file   = $this->in_export_dir( 'test.txt' );
		$result = $fs->put_contents( $file, 'Hello World' );

		$this->assertTrue( $result );
		$this->assertFileExists( $file );
		$this->assertEquals( 'Hello World', file_get_contents( $file ) );
	}

	public function test_put_contents_rejects_symlink_destination_to_prefix_sibling_root(): void {
		if ( ! function_exists( 'symlink' ) ) {
			$this->markTestSkipped( 'symlink() not available on this platform' );
		}

		$root = \SScribe_Private_Storage::get_export_dir();
		if ( '' === $root ) {
			$this->markTestSkipped( 'Private export root is unavailable.' );
		}

		$outside_dir = rtrim( $root, '/\\' ) . '-prefix-sibling-' . uniqid();
		if ( ! @mkdir( $outside_dir, 0700, true ) && ! is_dir( $outside_dir ) ) {
			$this->markTestSkipped( 'Could not create a prefix-sibling directory for the symlink regression.' );
		}

		$outside_file = $outside_dir . '/target.txt';
		$link         = $this->in_export_dir( 'write-link-' . uniqid() . '.txt' );
		file_put_contents( $outside_file, 'original' );

		if ( ! @symlink( $outside_file, $link ) ) {
			@unlink( $outside_file );
			@rmdir( $outside_dir );
			$this->markTestSkipped( 'symlink() not permitted on this platform' );
		}

		$fs = new \SScribe_Filesystem();
		$this->assertSame(
			\SScribe_Filesystem::SSCRIBE_PATH_REJECT,
			$fs->is_path_safe_for_write( $link ),
			'A write symlink must not be accepted merely because its target path begins with the private-root string.'
		);
		$this->assertFalse( $fs->put_contents( $link, 'overwrite-attempt' ) );
		$this->assertSame( 'original', file_get_contents( $outside_file ) );

		@unlink( $link );
		@unlink( $outside_file );
		@rmdir( $outside_dir );
	}

	public function test_get_contents_reads_file(): void {
		$fs   = new \SScribe_Filesystem();
		$file = $this->in_export_dir( 'test-read.txt' );
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
		$file = $this->in_export_dir( 'to-delete.txt' );
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
		$source   = $this->in_export_dir( 'source.txt' );
		$dest     = $this->in_export_dir( 'dest.txt' );
		file_put_contents( $source, 'Copy me' );

		$result = $fs->copy( $source, $dest );

		$this->assertTrue( $result );
		$this->assertFileExists( $dest );
		$this->assertEquals( 'Copy me', file_get_contents( $dest ) );
	}

	/**
	 * A caller must not be able to copy an arbitrary local file into an export.
	 */
	public function test_copy_refuses_source_outside_export_dir(): void {
		$fs     = new \SScribe_Filesystem();
		$source = $this->test_dir . '/outside-secret.txt';
		$dest   = $this->in_export_dir( 'copied-secret.txt' );
		file_put_contents( $source, 'sensitive local data' );

		$result = $fs->copy( $source, $dest );

		$this->assertFalse( $result );
		$this->assertFileDoesNotExist( $dest );
		$this->assertStringContainsString( 'source outside', $fs->get_last_error() );
	}

	/**
	 * Canonical source validation must reject symlinks planted in the export dir.
	 */
	public function test_copy_refuses_symlink_source(): void {
		if ( ! function_exists( 'symlink' ) ) {
			$this->markTestSkipped( 'symlink() not available on this platform' );
		}

		$fs      = new \SScribe_Filesystem();
		$outside = $this->test_dir . '/outside-secret.txt';
		$source  = $this->in_export_dir( 'source-link.txt' );
		$dest    = $this->in_export_dir( 'copied-link.txt' );
		file_put_contents( $outside, 'sensitive local data' );

		if ( ! @symlink( $outside, $source ) ) {
			$this->markTestSkipped( 'symlink() not permitted on this platform' );
		}

		$result = $fs->copy( $source, $dest );

		$this->assertFalse( $result );
		$this->assertFileDoesNotExist( $dest );
		@unlink( $source );
	}

	public function test_move_renames_file(): void {
		$fs     = new \SScribe_Filesystem();
		// Both source and destination must live INSIDE the SScribe export
		// directory. The new source-side guard (audit follow-up) rejects
		// sources resolving outside the allowlist, same as the destination
		// guard. This mirrors the production call site (zip-handler
		// staging rename) where the staging file is created inside
		// $this->export_dir.
		$source = $this->export_dir . '/move-source.txt';
		$dest   = $this->export_dir . '/move-dest.txt';
		file_put_contents( $source, 'Move me' );

		$result = $fs->move( $source, $dest );

		$this->assertTrue( $result );
		$this->assertFileDoesNotExist( $source );
		$this->assertFileExists( $dest );
		$this->assertEquals( 'Move me', file_get_contents( $dest ) );
	}

	/**
	 * Audit #7 regression: move() must refuse a destination outside the
	 * SScribe export directory. Symlink-aware (same check as put_contents).
	 */
	public function test_move_refuses_destination_outside_export_dir(): void {
		if ( ! function_exists( 'symlink' ) ) {
			$this->markTestSkipped( 'symlink() not available on this platform' );
		}

		$fs     = new \SScribe_Filesystem();
		$source = $this->test_dir . '/move-outside-source.txt';
		$dest   = $this->test_dir . '/move-outside-dest.txt';
		file_put_contents( $source, 'Should not move' );

		$result = $fs->move( $source, $dest );

		$this->assertFalse( $result, 'move() must refuse destinations outside the export dir' );
		$this->assertFileExists( $source, 'Source file must NOT be consumed when the move is rejected' );
		$this->assertFileDoesNotExist( $dest );
		$this->assertNotEmpty( $fs->get_last_error() );
	}

	/**
	 * Audit #7 regression: move() must refuse a destination whose parent
	 * is a symlink planted inside the export dir that escapes the allowlist
	 * (defends against symlink planting).
	 */
	public function test_move_refuses_planted_symlink_destination(): void {
		if ( ! function_exists( 'symlink' ) ) {
			$this->markTestSkipped( 'symlink() not available on this platform' );
		}

		$fs         = new \SScribe_Filesystem();
		$source     = $this->test_dir . '/planted-source.txt';
		$planted    = $this->export_dir . '/planted-link-' . uniqid();
		$plant_dest = $this->test_dir . '/planted-target.txt';

		file_put_contents( $source, 'Should not escape' );
		// symlink() requires the target to exist on most platforms
		// (Windows specifically rejects dangling symlinks by default).
		file_put_contents( $plant_dest, 'attacker-controlled target' );

		// Plant a symlink: $planted (inside export dir) -> $plant_dest (outside).
		// Some platforms (notably Windows non-admin and locked-down CI
		// containers) refuse symlink creation entirely; skip the assertion
		// rather than fail so the suite stays portable.
		$symlink_ok = @symlink( $plant_dest, $planted );
		if ( ! $symlink_ok ) {
			$this->markTestSkipped( 'symlink() not permitted on this platform (Windows non-admin / locked-down CI)' );
		}
		$this->assertTrue( $symlink_ok );

		$result = $fs->move( $source, $planted . '/planted-target.txt' );

		$this->assertFalse( $result, 'move() must refuse destinations whose parent resolves outside the export dir via symlink' );
		$this->assertFileExists( $source, 'Source file must NOT be consumed when the move is rejected' );

		@unlink( $planted );
	}

	public function test_mkdir_creates_directory(): void {
		$fs     = new \SScribe_Filesystem();
		$subdir = $this->in_export_dir( 'newdir/subdir' );

		$result = $fs->mkdir( $subdir );

		$this->assertTrue( $result );
		$this->assertDirectoryExists( $subdir );
	}

	// Phase 38 — mkdir() containment regression tests.

	public function test_mkdir_rejects_path_outside_export_dir(): void {
		$fs = new \SScribe_Filesystem();
		// sys_get_temp_dir() is outside the SScribe export root, so
		// this must be rejected even though the path is otherwise
		// well-formed.
		$result = $fs->mkdir( $this->test_dir . '/phase38-temp' );
		$this::assertFalse( $result );
		$this::assertDirectoryDoesNotExist( $this->test_dir . '/phase38-temp' );
	}

	public function test_mkdir_rejects_uploads_path(): void {
		$fs   = new \SScribe_Filesystem();
		$path = WP_CONTENT_DIR . '/uploads/sscribe-phase38-escape';
		$this::assertFalse( $fs->mkdir( $path ) );
		$this::assertDirectoryDoesNotExist( $path );
	}

	public function test_mkdir_rejects_traversal_in_absolute_path(): void {
		$fs   = new \SScribe_Filesystem();
		// Try to escape via an absolute path with embedded traversal.
		// The containment check rejects anything that doesn't resolve
		// under the export root, so an arbitrary absolute path must
		// fail even when normalized by sanitize_path().
		$escape = sys_get_temp_dir() . '/../phase38-escape';
		$this::assertFalse( $fs->mkdir( $escape ) );
	}

	public function test_mkdir_rejects_symlink_escape(): void {
		$fs        = new \SScribe_Filesystem();
		$export_dir = rtrim( $this->export_dir, '/' );
		if ( ! is_dir( $export_dir ) ) {
			$this::markTestSkipped( 'export_dir not available' );
		}
		// Plant a symlink inside the export dir pointing OUTSIDE.
		$symlink = $export_dir . '/phase38-symlink-escape';
		if ( is_link( $symlink ) || file_exists( $symlink ) ) {
			@unlink( $symlink );
		}
		$plant_ok = @symlink( sys_get_temp_dir() . '/phase38-outside-' . uniqid(), $symlink );
		if ( ! $plant_ok ) {
			$this::markTestSkipped( 'symlink() not permitted on this platform' );
		}
		$this::assertFalse( $fs->mkdir( $symlink . '/subdir' ) );
		@unlink( $symlink );
	}

	public function test_mkdir_under_private_root_happy_path(): void {
		$fs = new \SScribe_Filesystem();
		$result = $fs->mkdir_under_private_root( 'phase38-happy/sub' );
		$this::assertNotSame( '', $result );
		$this::assertDirectoryExists( $result );
		// And the returned path must be inside the export root.
		$export_dir = \SScribe_Private_Storage::get_export_dir();
		$this::assertStringStartsWith( $export_dir, $result );
	}

	public function test_mkdir_under_private_root_rejects_absolute(): void {
		$fs = new \SScribe_Filesystem();
		$this::assertSame( '', $fs->mkdir_under_private_root( '/tmp/phase38-absolute' ) );
		$this::assertSame( '', $fs->mkdir_under_private_root( 'C:\\Windows\\Temp\\phase38-drive' ) );
	}

	public function test_mkdir_under_private_root_rejects_traversal(): void {
		$fs = new \SScribe_Filesystem();
		$this::assertSame( '', $fs->mkdir_under_private_root( '../phase38-traversal' ) );
		$this::assertSame( '', $fs->mkdir_under_private_root( 'safe/../../escape' ) );
	}

	public function test_mkdir_under_private_root_rejects_nul(): void {
		$fs = new \SScribe_Filesystem();
		$this::assertSame( '', $fs->mkdir_under_private_root( "good\x00bad" ) );
	}

	public function test_mkdir_under_private_root_rejects_empty(): void {
		$fs = new \SScribe_Filesystem();
		$this::assertSame( '', $fs->mkdir_under_private_root( '' ) );
		$this::assertSame( '', $fs->mkdir_under_private_root( '/' ) );
		$this::assertSame( '', $fs->mkdir_under_private_root( './' ) );
	}

	public function test_mkdir_under_private_root_collapses_dot_segments(): void {
		$fs     = new \SScribe_Filesystem();
		$result = $fs->mkdir_under_private_root( 'phase38-dots/./inner' );
		$this::assertNotSame( '', $result );
		$this::assertDirectoryExists( $result );
	}

	/**
	 * mkdir_under_private_root must refuse when the target path is an
	 * existing symlink (filesystem.php L487-491). Even if the symlink
	 * resolves inside the export root, the function refuses to follow
	 * pre-existing symlinks because the resulting directory would not
	 * be owned by this plugin's containment check at creation time.
	 *
	 * Symlink() requires Developer Mode on Windows; skip otherwise.
	 */
	public function test_mkdir_under_private_root_rejects_intermediate_symlink_before_write(): void {
		if ( ! function_exists( 'symlink' ) ) {
			$this->markTestSkipped( 'symlink() not available on this platform' );
		}

		$fs          = new \SScribe_Filesystem();
		$export_root = \SScribe_Private_Storage::get_export_dir();
		$outside     = $this->test_dir . '/mkdir-intermediate-outside';
		$link        = $export_root . '/mkdir-intermediate-' . uniqid();
		wp_mkdir_p( $outside );

		if ( ! @symlink( $outside, $link ) ) {
			$this->markTestSkipped( 'symlink() not permitted on this platform' );
		}

		$relative = basename( $link ) . '/nested/child';
		$result   = $fs->mkdir_under_private_root( $relative );

		$this->assertSame( '', $result );
		$this->assertDirectoryDoesNotExist(
			$outside . '/nested',
			'Rejected intermediate symlinks must not create descendants outside private storage.'
		);

		@unlink( $link );
	}

	public function test_mkdir_under_private_root_rejects_existing_symlink(): void {
		if ( ! function_exists( 'symlink' ) ) {
			$this::markTestSkipped( 'symlink() not available on this platform' );
		}

		$fs    = new \SScribe_Filesystem();
		$name  = 'phase38-symlink-' . uniqid();
		// mkdir_under_private_root places the target at the EXPORT ROOT
		// (not under any per-test subdirectory), so the planted symlink
		// must live at $export_root/$name to be seen by is_link().
		$export_root = \SScribe_Private_Storage::get_export_dir();
		$link  = $export_root . DIRECTORY_SEPARATOR . $name;
		$real  = $this->test_dir . DIRECTORY_SEPARATOR . $name . '-target';
		mkdir( $real, 0755, true );

		$ok = @symlink( $real, $link );
		if ( ! $ok ) {
			$this::markTestSkipped( 'symlink() not permitted on this platform (Windows non-admin / locked-down CI)' );
		}

		$this::assertTrue( is_link( $link ), 'pre-condition: link must exist as a symlink' );

		$result = $fs->mkdir_under_private_root( $name );

		$this::assertSame( '', $result, 'mkdir_under_private_root must refuse an existing symlink' );
		$this::assertNotEmpty( $fs->get_last_error(), 'last_error must be populated on rejection' );

		@unlink( $link );
		@rmdir( $real );
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

	public function test_sanitize_path_rejects_null_bytes(): void {
		$unsafe = $this->test_dir . "/file\x00name.txt";
		$safe   = \SScribe_Filesystem::sanitize_path( $unsafe );

		$this->assertSame( '', $safe );
	}


	public function test_put_contents_rejects_nul_path_instead_of_rewriting_filename(): void {
		$fs      = new \SScribe_Filesystem();
		$rewritten = $this->in_export_dir( 'filename.txt' );
		$unsafe    = $this->in_export_dir( "file\x00name.txt" );

		$this->assertFalse( $fs->put_contents( $unsafe, 'must-not-write' ) );
		$this->assertFileDoesNotExist( $rewritten );
	}

	public function test_get_contents_rejects_nul_path_instead_of_reading_rewritten_filename(): void {
		$fs        = new \SScribe_Filesystem();
		$rewritten = $this->in_export_dir( 'secret.txt' );
		file_put_contents( $rewritten, 'secret' );

		$this->assertFalse( $fs->get_contents( $this->in_export_dir( "sec\x00ret.txt" ) ) );
	}

	public function test_put_contents_sanitizes_traversal_in_path(): void {
		$fs     = new \SScribe_Filesystem();
		// Construct a path INSIDE the export dir, then inject a traversal
		// attempt that, if it landed unsanitized, would escape to the
		// system temp dir. After sanitize_path() the ".." segments must
		// be stripped (the literal text disappears) and the file must
		// land inside the export dir.
		$unsafe = $this->in_export_dir( 'subdir/../../../../../tmp/evil.txt' );

		$result = $fs->put_contents( $unsafe, 'content' );

		$this->assertTrue( $result );
		// sanitise_path() drops '..' segments by skipping, leaving
		// "<export_dir>/subdir/tmp/evil.txt".
		$inside = $this->export_dir . '/subdir/tmp/evil.txt';
		$this->assertFileExists( $inside );
		$this->assertFileDoesNotExist( '/tmp/evil.txt' );
	}

	public function test_put_contents_rejects_path_outside_export_dir(): void {
		$fs   = new \SScribe_Filesystem();
		$file = $this->test_dir . '/should-not-write.txt';

		$result = $fs->put_contents( $file, 'should not land' );

		$this->assertFalse( $result, 'Expected put_contents to REJECT writes outside the export dir' );
		$this->assertFileDoesNotExist( $file );
	}

	public function test_copy_rejects_destination_outside_export_dir(): void {
		$fs     = new \SScribe_Filesystem();
		$source = $this->in_export_dir( 'copy-source.txt' );
		$dest   = $this->test_dir . '/copy-dest.txt';
		file_put_contents( $source, 'do not copy me' );

		$result = $fs->copy( $source, $dest );

		$this->assertFalse( $result, 'Expected copy() to REJECT destinations outside the export dir' );
		$this->assertFileDoesNotExist( $dest );
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
		if ( ! function_exists( 'symlink' ) ) {
			$this->markTestSkipped( 'symlink() not available' );
		}

		$fs = new \SScribe_Filesystem();

		$export_dir = $this->export_dir;
		if ( ! is_dir( $export_dir ) ) {
			mkdir( $export_dir, 0755, true );
		}

		$outside   = $this->test_dir . '/outside-target';
		$link_name = $export_dir . '/evil-' . uniqid();

		mkdir( $outside, 0755, true );

		// symlink() requires SeCreateSymbolicLinkPrivilege on Windows
		// (admin-only by default). Skip on platforms where the call
		// fails — the test exercises a privilege-gated attack surface
		// that the rest of the suite does not depend on.
		if ( ! @symlink( $outside, $link_name ) ) {
			rmdir( $outside );
			$this->markTestSkipped( 'symlink() not permitted on this platform' );
		}

		$safe = $fs->is_path_safe_for_write( $link_name . '/file.txt' );

		// Clean up before asserting so a failure doesn't leak symlinks.
		@unlink( $link_name );
		rmdir( $outside );

		$this->assertEquals( \SScribe_Filesystem::SSCRIBE_PATH_REJECT, $safe,
			'Expected symlink escape to be REJECTed' );
	}

	public function test_write_check_rejects_intermediate_symlink_even_when_target_stays_inside_private_root(): void {
		if ( ! function_exists( 'symlink' ) ) {
			$this->markTestSkipped( 'symlink() not available' );
		}

		$fs         = new \SScribe_Filesystem();
		$export_dir = \SScribe_Private_Storage::get_export_dir();
		$real_dir   = $export_dir . '/symlink-safe-target-' . uniqid();
		$link_name  = $export_dir . '/symlink-safe-link-' . uniqid();
		wp_mkdir_p( $real_dir );

		if ( ! @symlink( $real_dir, $link_name ) ) {
			@rmdir( $real_dir );
			$this->markTestSkipped( 'symlink() not permitted on this platform' );
		}

		try {
			$this->assertSame(
				\SScribe_Filesystem::SSCRIBE_PATH_REJECT,
				$fs->is_path_safe_for_write( $link_name . '/file.txt' ),
				'Writes must reject every symlink component, even when the current target remains inside private storage.'
			);
		} finally {
			if ( is_link( $link_name ) ) {
				@unlink( $link_name );
			}
			@rmdir( $real_dir );
		}
	}

	public function test_write_check_rejects_missing_path_below_symlink_escape(): void {
		if ( ! function_exists( 'symlink' ) ) {
			$this->markTestSkipped( 'symlink() not available' );
		}

		$fs          = new \SScribe_Filesystem();
		$export_dir  = $this->export_dir;
		$outside     = $this->test_dir . '/nested-outside';
		$link_name   = $export_dir . '/nested-escape-' . uniqid();
		wp_mkdir_p( $export_dir );
		wp_mkdir_p( $outside );

		if ( ! @symlink( $outside, $link_name ) ) {
			rmdir( $outside );
			$this->markTestSkipped( 'symlink() not permitted on this platform' );
		}

		$safe = $fs->is_path_safe_for_write( $link_name . '/missing/child/file.txt' );

		@unlink( $link_name );
		rmdir( $outside );

		$this->assertSame( \SScribe_Filesystem::SSCRIBE_PATH_REJECT, $safe );
	}

	/**
	 * Symlink attack protection: copy() must also reject destinations
	 * that resolve outside the SScribe export directory.
	 */
	public function test_copy_rejects_symlink_escape(): void {
		if ( ! function_exists( 'symlink' ) ) {
			$this->markTestSkipped( 'symlink() not available' );
		}

		$fs = new \SScribe_Filesystem();

		$export_dir = $this->export_dir;
		if ( ! is_dir( $export_dir ) ) {
			mkdir( $export_dir, 0755, true );
		}

		$outside   = $this->test_dir . '/outside-target-2';
		$link_name = $export_dir . '/evil-copy-' . uniqid();

		mkdir( $outside, 0755, true );

		if ( ! @symlink( $outside, $link_name ) ) {
			rmdir( $outside );
			$this->markTestSkipped( 'symlink() not permitted on this platform' );
		}

		$safe = $fs->is_path_safe_for_write( $link_name );

		@unlink( $link_name );
		rmdir( $outside );

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
	 * is_path_safe_for_write() must REJECT any file whose literal
	 * path is outside the private SScribe export dir, even if no symlink is
	 * involved. Other filesystem destinations are default-denied.
	 */
	public function test_is_path_safe_for_write_rejects_external_writes(): void {
		$fs = new \SScribe_Filesystem();

		// sys_get_temp_dir() is outside the SScribe export dir.
		$temp = sys_get_temp_dir() . '/sscribe-external-test.txt';
		$safe = $fs->is_path_safe_for_write( $temp );

		$this->assertEquals( \SScribe_Filesystem::SSCRIBE_PATH_REJECT, $safe,
			'Expected WP temp dir writes to be REJECTed (default-deny outside export dir)' );
	}
}
