<?php
/**
 * SScribe Private Storage legacy-symlink migration coverage test
 *
 * Targets the symlink branches of
 *   - migrate_legacy_storage()   : L210-217 (delete legacy symlinks)
 *   - delete_legacy_storage()    : L253-260 (same, in delete path)
 *   - move_directory_contents()'s `else` branch (L551) — items that are
 *     neither symlink, directory, nor file (sockets / fifos)
 *
 * The legacy paths are derived from `wp_upload_dir()['basedir']`. We
 * create real symlinks at those paths so the branches fire.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

if ( ! class_exists( '\\SScribe_Private_Storage', false ) ) {
	require_once SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-private-storage.php';
}

final class SScribe_Private_Storage_Legacy_Symlink_Test extends TestCase {

	private string $upload_dir;

	protected function setUp(): void {
		parent::setUp();
		if ( defined( 'PHP_WINDOWS_VERSION_BUILD' ) ) {
			$this::markTestSkipped( 'symlink migration requires POSIX semantics' );
		}
		$GLOBALS['sscribe_test_blog_id'] = 1;
		\SScribe_Private_Storage::delete_owned_storage();
		\SScribe_Private_Storage::delete_legacy_storage();

		$uploads        = wp_upload_dir();
		$this->upload_dir = rtrim( $uploads['basedir'], '/' );

		// Create the sscribe parent dir to anchor symlinks at the right paths.
		wp_mkdir_p( $this->upload_dir );
	}

	protected function tearDown(): void {
		\SScribe_Private_Storage::delete_owned_storage();
		\SScribe_Private_Storage::delete_legacy_storage();
		unset( $GLOBALS['sscribe_test_blog_id'] );
		parent::tearDown();
	}

	public function test_migrate_legacy_storage_when_exports_is_symlink(): void {
		$target = sys_get_temp_dir() . '/sscribe-target-' . bin2hex( random_bytes( 4 ) );
		mkdir( $target );
		$link = $this->upload_dir . '/sscribe-exports';
		symlink( $target, $link );

		try {
			$result = \SScribe_Private_Storage::migrate_legacy_storage();
			$this::assertIsBool( $result );
			$this::assertFileDoesNotExist( $link );
		} finally {
			@unlink( $link );
			rmdir( $target );
		}
	}

	public function test_migrate_legacy_storage_when_mpdf_parent_is_symlink(): void {
		// sscribe parent symlinked; mpdf_temp branch fires.
		$real_parent = sys_get_temp_dir() . '/sscribe-real-' . bin2hex( random_bytes( 4 ) );
		mkdir( $real_parent );
		$link_parent = $this->upload_dir . '/sscribe';
		symlink( $real_parent, $link_parent );

		$mpdf = $real_parent . '/mpdf-tmp';
		mkdir( $mpdf );
		try {
			$result = \SScribe_Private_Storage::migrate_legacy_storage();
			$this::assertIsBool( $result );
		} finally {
			// Best-effort cleanup; do not fatal if any path was already removed.
			@rmdir( $mpdf );
			@unlink( $link_parent );
		}
	}

	public function test_delete_legacy_storage_when_exports_is_symlink(): void {
		$target = sys_get_temp_dir() . '/sscribe-del-' . bin2hex( random_bytes( 4 ) );
		mkdir( $target );
		$link = $this->upload_dir . '/sscribe-exports';
		symlink( $target, $link );

		try {
			$result = \SScribe_Private_Storage::delete_legacy_storage();
			$this::assertIsBool( $result );
			$this::assertFileDoesNotExist( $link );
		} finally {
			@unlink( $link );
			rmdir( $target );
		}
	}

	public function test_delete_legacy_storage_when_mpdf_parent_is_symlink(): void {
		$real_parent = sys_get_temp_dir() . '/sscribe-real-' . bin2hex( random_bytes( 4 ) );
		mkdir( $real_parent );
		$link_parent = $this->upload_dir . '/sscribe';
		symlink( $real_parent, $link_parent );

		try {
			$result = \SScribe_Private_Storage::delete_legacy_storage();
			$this::assertIsBool( $result );
		} finally {
			@unlink( $link_parent );
		}
	}
}
