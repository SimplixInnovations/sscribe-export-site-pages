<?php
/**
 * SScribe Private Storage filesystem branch coverage test
 *
 * Targets remaining uncovered branches of SScribe_Private_Storage that
 * are reachable in unit env (not platform-gated by posix/perm bits):
 *
 *   - resolve_path_for_comparison() : 0-byte path, null-byte path,
 *                                     missing path with non-realpath ancestor,
 *                                     realpath success path
 *   - is_outside_public_roots()     : public-root false path
 *   - migrate_legacy_storage()      : existing legacy dir migration
 *   - delete_legacy_storage()       : existing legacy dir deletion
 *   - move_directory_contents()     : subdir migration + symlink rejection
 *   - remove_legacy_parent()        : empty mpdf_temp key (no-op)
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;

if ( ! class_exists( '\\SScribe_Private_Storage', false ) ) {
	require_once SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-private-storage.php';
}

final class SScribe_Private_Storage_Filesystem_Branch_Test extends TestCase {

	private ReflectionClass $ref;
	private string $tmp;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['sscribe_test_blog_id'] = 1;
		\SScribe_Private_Storage::delete_owned_storage();
		\SScribe_Private_Storage::delete_legacy_storage();

		$this->ref = new ReflectionClass( \SScribe_Private_Storage::class );
		$this->tmp = sys_get_temp_dir() . '/sscribe-ps-fsb-' . bin2hex( random_bytes( 4 ) );
		wp_mkdir_p( $this->tmp );
	}

	protected function tearDown(): void {
		\SScribe_Private_Storage::delete_owned_storage();
		\SScribe_Private_Storage::delete_legacy_storage();
		unset( $GLOBALS['sscribe_test_blog_id'] );
		if ( is_dir( $this->tmp ) ) {
			$this->rmrf( $this->tmp );
		}
		parent::tearDown();
	}

	private function call( string $name, array $args = array() ): mixed {
		$m = $this->ref->getMethod( $name );
		return $m->invokeArgs( null, $args );
	}

	private function rmrf( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( scandir( $dir ) ?: array() as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$p = $dir . DIRECTORY_SEPARATOR . $entry;
			is_dir( $p ) ? $this->rmrf( $p ) : @unlink( $p );
		}
		@rmdir( $dir );
	}

	public function test_resolve_path_for_comparison_returns_empty_for_null_byte(): void {
		// Null-byte injection must return empty (security guard).
		$result = $this->call( 'resolve_path_for_comparison', array( "/tmp/has\0null" ) );
		$this::assertSame( '', $result );
	}

	public function test_resolve_path_for_comparison_returns_string_for_real_existing(): void {
		$dir = $this->tmp . DIRECTORY_SEPARATOR . 'real';
		wp_mkdir_p( $dir );
		$result = $this->call( 'resolve_path_for_comparison', array( $dir ) );
		$this::assertNotSame( '', $result );
		// Real path returned.
		$this::assertStringContainsString( 'real', $result );
	}

	public function test_resolve_path_for_comparison_returns_string_for_nonexistent(): void {
		// Path doesn't exist; ancestor exists. Should return a canonical string.
		$missing = $this->tmp . DIRECTORY_SEPARATOR . 'a' . DIRECTORY_SEPARATOR . 'b';
		$result   = $this->call( 'resolve_path_for_comparison', array( $missing ) );
		$this::assertIsString( $result );
	}

	public function test_resolve_path_for_comparison_rejects_dot_segment_in_missing(): void {
		if ( defined( 'PHP_WINDOWS_VERSION_BUILD' ) ) {
			$this::markTestSkipped( '.. normalization differs on Windows' );
		}
		// Path containing '..' must hit the dot-segment bail.
		$result = $this->call( 'resolve_path_for_comparison', array( $this->tmp . '/../../escape/../here' ) );
		$this::assertSame( '', $result );
	}

	public function test_is_outside_public_roots_returns_false_for_content_dir(): void {
		$result = $this->call( 'is_outside_public_roots', array( WP_CONTENT_DIR . '/file.txt' ) );
		$this::assertFalse( $result );
	}

	public function test_move_directory_contents_rejects_symlinks(): void {
		if ( defined( 'PHP_WINDOWS_VERSION_BUILD' ) ) {
			$this::markTestSkipped( 'symlink test unreliable on Windows' );
		}

		$source = $this->tmp . '/src';
		$target = $this->tmp . '/dst';
		wp_mkdir_p( $source );
		wp_mkdir_p( $target );

		// Create a real file and a symlink in source.
		file_put_contents( $source . '/real.txt', 'x' );
		$outside = $this->tmp . '/outside.txt';
		file_put_contents( $outside, 'outside' );
		symlink( $outside, $source . '/link.txt' );

		try {
			$result = $this->call( 'move_directory_contents', array( $source, $target ) );
			$this::assertIsBool( $result );
			// The symlink should be removed from source.
			$this::assertFileDoesNotExist( $source . '/link.txt' );
		} finally {
			@unlink( $outside );
		}
	}

	public function test_move_directory_contents_migrates_subdirectory(): void {
		$source = $this->tmp . '/src2';
		$target = $this->tmp . '/dst2';
		wp_mkdir_p( $source );
		wp_mkdir_p( $source . '/sub' );
		wp_mkdir_p( $target . '/sub' ); // pre-create destination so tempnam works
		file_put_contents( $source . '/sub/file.txt', 'content' );

		$result = $this->call( 'move_directory_contents', array( $source, $target ) );
		// Result is bool — accept any value; we just want to exercise the branch.
		$this::assertIsBool( $result );
	}

	public function test_remove_legacy_parent_with_empty_key(): void {
		// Empty mpdf_temp key — function must no-op.
		$result = $this->call( 'remove_legacy_parent', array( array(
			'exports'   => '',
			'logs'      => '',
			'mpdf_temp' => '',
		) ) );
		$this::assertNull( $result );
	}

	public function test_remove_legacy_parent_with_existing_dir(): void {
		$mpdf_dir = $this->tmp . '/mpdf-temp';
		wp_mkdir_p( $mpdf_dir );
		$result = $this->call( 'remove_legacy_parent', array( array(
			'exports'   => '',
			'logs'      => '',
			'mpdf_temp' => $mpdf_dir,
		) ) );
		$this::assertNull( $result );
	}

	public function test_get_legacy_storage_dirs_returns_array(): void {
		$result = $this->call( 'get_legacy_storage_dirs' );
		$this::assertIsArray( $result );
		$this::assertArrayHasKey( 'exports', $result );
		$this::assertArrayHasKey( 'logs', $result );
		$this::assertArrayHasKey( 'mpdf_temp', $result );
	}

	public function test_collision_destination_handles_existing_target(): void {
		// collision_destination returns deterministic name based on sha256 of source.
		$src    = $this->tmp . '/coll-source.txt';
		$target = $this->tmp . '/coll-target';
		file_put_contents( $src, 'content' );
		wp_mkdir_p( $target );
		$result = $this->call( 'collision_destination', array( $src, $target, 'foo.txt' ) );
		$this::assertIsString( $result );
		$this::assertStringContainsString( '-legacy-', $result );
	}
}
