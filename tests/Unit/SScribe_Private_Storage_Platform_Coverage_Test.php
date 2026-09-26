<?php
/**
 * SScribe Private Storage platform-portable coverage test.
 *
 * Covers private-storage branches that need no POSIX or privilege-gated
 * primitives, so the 90% critical-module threshold holds on Windows as
 * well as Linux:
 *
 *   - get_export_dir() rejects a symlinked leaf directory (L100-101),
 *     using a per-process SSCRIBE_PRIVATE_STORAGE_DIR plus a real
 *     symlink (symlink() with an mklink fallback on Windows).
 *   - get_subdirectory() rejects a path occupied by a file (L151).
 *   - migrate_legacy_storage() reports failure when the private target
 *     is unresolvable (L221-222), via a DOCUMENT_ROOT that contains the
 *     export tree.
 *   - move_directory_contents() reports failure when a subdirectory
 *     cannot be created (L530-531), via a file blocking the target.
 *
 * Symlink-guard branches that require link creation live in
 * SScribe_Private_Storage_Legacy_Symlink_Test; POSIX-only ownership
 * branches (is_owned_by_current_process) remain Linux-covered by
 * design and are documented in README troubleshooting.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

if ( ! class_exists( '\\SScribe_Private_Storage', false ) ) {
	require_once SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-private-storage.php';
}

final class SScribe_Private_Storage_Platform_Coverage_Test extends TestCase {

	/**
	 * Create a real symlink, cross-platform (mirrors the helper in
	 * SScribe_Private_Storage_Legacy_Symlink_Test; kept local so each
	 * test class stays self-contained).
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

	/**
	 * Remove a directory tree created by these tests.
	 *
	 * @param string $dir Directory path.
	 */
	private static function remove_tree( string $dir ): void {
		if ( ! is_dir( $dir ) || is_link( $dir ) ) {
			return;
		}
		$items = scandir( $dir );
		if ( false === $items ) {
			return;
		}
		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $dir . '/' . $item;
			if ( is_dir( $path ) && ! is_link( $path ) ) {
				self::remove_tree( $path );
			} else {
				@unlink( $path );
			}
		}
		@rmdir( $dir );
	}

	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	#[\PHPUnit\Framework\Attributes\PreserveGlobalState( false )]
	public function test_get_export_dir_rejects_symlinked_leaf(): void {
		$base = sys_get_temp_dir() . '/sscribe-pscov-' . uniqid();
		mkdir( $base, 0777, true );
		define( 'SSCRIBE_PRIVATE_STORAGE_DIR', $base );
		try {
			$leaf = \SScribe_Private_Storage::get_export_dir( true );
			$this::assertNotSame( '', $leaf );
			// The resolver hardens the fresh leaf (.htaccess/index.php),
			// so clear it fully before planting the link in its place.
			self::remove_tree( $leaf );
			$other = $base . '/other-target';
			mkdir( $other, 0777, true );
			if ( ! self::make_symlink( $other, $leaf ) ) {
				$this::markTestSkipped( 'symlinks cannot be created on this machine (Windows needs Developer Mode for mklink).' );
			}
			$this::assertSame( '', \SScribe_Private_Storage::get_export_dir( false ) );
		} finally {
			self::remove_symlink( $leaf ?? '' );
			self::remove_tree( $base );
		}
	}

	public function test_get_subdirectory_rejects_file_path(): void {
		$root = \SScribe_Private_Storage::get_export_dir( true );
		if ( '' === $root ) {
			$this::markTestSkipped( 'No private storage dir' );
		}
		$name = 'pscov_file_' . uniqid();
		file_put_contents( $root . '/' . $name, 'x' );
		try {
			$this::assertSame( '', \SScribe_Private_Storage::get_subdirectory( $name, false ) );
		} finally {
			@unlink( $root . '/' . $name );
		}
	}

	public function test_migrate_reports_failure_when_target_unresolvable(): void {
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			$this::markTestSkipped( 'No uploads basedir' );
		}
		$legacy = rtrim( (string) $uploads['basedir'], '/\\' ) . '/sscribe-exports';
		wp_mkdir_p( $legacy );
		file_put_contents( $legacy . '/legacy.txt', 'legacy' );

		// Force every private-storage base candidate to fail validation so
		// get_export_dir() cannot resolve a migration target. Using the
		// supported filter is deterministic on Windows and CLI, unlike
		// DOCUMENT_ROOT tricks (parent-of-docroot remains a valid base and
		// TCPDF autoconfig mutates DOCUMENT_ROOT itself).
		$filter = static function ( $candidates ) {
			return array( sys_get_temp_dir() . '/sscribe-never-created-' . uniqid() );
		};
		add_filter( 'sscribe_private_storage_base_candidates', $filter );
		try {
			$this::assertFalse( \SScribe_Private_Storage::migrate_legacy_storage() );
		} finally {
			remove_filter( 'sscribe_private_storage_base_candidates', $filter );
			@unlink( $legacy . '/legacy.txt' );
			@rmdir( $legacy );
		}
	}

	public function test_move_directory_contents_fails_when_target_unmakable(): void {
		$root = sys_get_temp_dir() . '/sscribe-pscov-move-' . uniqid();
		mkdir( $root . '/src/kid', 0777, true );
		file_put_contents( $root . '/blocker', 'x' );
		try {
			$method = new \ReflectionMethod( \SScribe_Private_Storage::class, 'move_directory_contents' );
			$this::assertFalse( $method->invoke( null, $root . '/src', $root . '/blocker' ) );
		} finally {
			@rmdir( $root . '/src/kid' );
			@rmdir( $root . '/src' );
			@unlink( $root . '/blocker' );
			@rmdir( $root );
		}
	}
}
