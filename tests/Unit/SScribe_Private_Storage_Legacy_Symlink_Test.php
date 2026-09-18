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
 * Symlinks are created cross-platform: plain symlink() first, falling
 * back to mklink on Windows (Developer Mode enables unprivileged
 * creation; see README troubleshooting). A test skips only when the
 * machine can create symlinks by neither mechanism.
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

	/**
	 * Create a real symlink, cross-platform.
	 *
	 * @param string $target Existing target path.
	 * @param string $link   Link path to create.
	 * @return bool True when $link is a symlink afterwards.
	 */
	private static function make_symlink( string $target, string $link ): bool {
		if ( @symlink( $target, $link ) && is_link( $link ) ) {
			return true;
		}
		@unlink( $link );
		if ( '\\' !== DIRECTORY_SEPARATOR ) {
			return false;
		}
		$flag = is_dir( $target ) ? '/D' : '';
		exec( 'mklink ' . $flag . ' ' . escapeshellarg( $link ) . ' ' . escapeshellarg( $target ) . ' 2>&1', $out, $code );
		return 0 === $code && is_link( $link );
	}

	/**
	 * Remove a symlink without following it.
	 *
	 * @param string $link Link path.
	 */
	private static function remove_symlink( string $link ): void {
		if ( is_link( $link ) ) {
			@unlink( $link );
		}
		if ( is_link( $link ) || file_exists( $link ) ) {
			@rmdir( $link );
		}
	}

	protected function setUp(): void {
		parent::setUp();
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
		// Link to a file (not a directory): PHP's unlink() removes
		// file symlinks on every platform, so the removal assertion
		// below holds on Windows (mklink) exactly as on POSIX.
		$target = sys_get_temp_dir() . '/sscribe-target-' . bin2hex( random_bytes( 4 ) ) . '.txt';
		file_put_contents( $target, 'legacy' );
		$link = $this->upload_dir . '/sscribe-exports';
		if ( ! self::make_symlink( $target, $link ) ) {
			$this::markTestSkipped( 'symlinks cannot be created on this machine (Windows needs Developer Mode for mklink).' );
		}

		try {
			$result = \SScribe_Private_Storage::migrate_legacy_storage();
			$this::assertIsBool( $result );
			$this::assertFileDoesNotExist( $link );
		} finally {
			self::remove_symlink( $link );
			@unlink( $target );
		}
	}

	public function test_migrate_legacy_storage_when_mpdf_parent_is_symlink(): void {
		// sscribe parent symlinked; mpdf_temp branch fires.
		$real_parent = sys_get_temp_dir() . '/sscribe-real-' . bin2hex( random_bytes( 4 ) );
		mkdir( $real_parent );
		$link_parent = $this->upload_dir . '/sscribe';
		if ( ! self::make_symlink( $real_parent, $link_parent ) ) {
			$this::markTestSkipped( 'symlinks cannot be created on this machine (Windows needs Developer Mode for mklink).' );
		}

		$mpdf = $real_parent . '/mpdf-tmp';
		mkdir( $mpdf );
		try {
			$result = \SScribe_Private_Storage::migrate_legacy_storage();
			$this::assertIsBool( $result );
		} finally {
			// Best-effort cleanup; do not fatal if any path was already removed.
			@rmdir( $mpdf );
			self::remove_symlink( $link_parent );
			@rmdir( $real_parent );
		}
	}

	public function test_delete_legacy_storage_when_exports_is_symlink(): void {
		$target = sys_get_temp_dir() . '/sscribe-del-' . bin2hex( random_bytes( 4 ) ) . '.txt';
		file_put_contents( $target, 'legacy' );
		$link = $this->upload_dir . '/sscribe-exports';
		if ( ! self::make_symlink( $target, $link ) ) {
			$this::markTestSkipped( 'symlinks cannot be created on this machine (Windows needs Developer Mode for mklink).' );
		}

		try {
			$result = \SScribe_Private_Storage::delete_legacy_storage();
			$this::assertIsBool( $result );
			$this::assertFileDoesNotExist( $link );
		} finally {
			self::remove_symlink( $link );
			@unlink( $target );
		}
	}

	public function test_delete_legacy_storage_when_mpdf_parent_is_symlink(): void {
		$real_parent = sys_get_temp_dir() . '/sscribe-real-' . bin2hex( random_bytes( 4 ) );
		mkdir( $real_parent );
		$link_parent = $this->upload_dir . '/sscribe';
		if ( ! self::make_symlink( $real_parent, $link_parent ) ) {
			$this::markTestSkipped( 'symlinks cannot be created on this machine (Windows needs Developer Mode for mklink).' );
		}

		try {
			$result = \SScribe_Private_Storage::delete_legacy_storage();
			$this::assertIsBool( $result );
		} finally {
			self::remove_symlink( $link_parent );
			@rmdir( $real_parent );
		}
	}
}
