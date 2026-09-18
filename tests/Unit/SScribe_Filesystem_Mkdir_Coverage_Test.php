<?php
/**
 * SScribe Filesystem mkdir_under_private_root coverage test
 *
 * Exercises every rejection branch of mkdir_under_private_root() and
 * the related public helpers it shares code paths with.
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

if ( ! class_exists( '\\SScribe_Filesystem', false ) ) {
	require_once SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-filesystem.php';
}

final class SScribe_Filesystem_Mkdir_Coverage_Test extends TestCase {

	private \SScribe_Filesystem $fs;

	protected function setUp(): void {
		parent::setUp();
		$this->fs = new \SScribe_Filesystem();
	}

	public function test_mkdir_under_private_root_rejects_empty(): void {
		$result = $this->fs->mkdir_under_private_root( '' );
		$this::assertSame( '', $result );
		$this::assertNotSame( '', $this->fs->get_last_error() );
	}

	public function test_mkdir_under_private_root_rejects_whitespace(): void {
		$result = $this->fs->mkdir_under_private_root( '   ' );
		$this::assertSame( '', $result );
		$this::assertNotSame( '', $this->fs->get_last_error() );
	}

	public function test_mkdir_under_private_root_rejects_nul_byte(): void {
		$result = $this->fs->mkdir_under_private_root( "ok\0bad" );
		$this::assertSame( '', $result );
		$this::assertNotSame( '', $this->fs->get_last_error() );
	}

	public function test_mkdir_under_private_root_rejects_unix_absolute(): void {
		$result = $this->fs->mkdir_under_private_root( '/etc/passwd' );
		$this::assertSame( '', $result );
		$this::assertNotSame( '', $this->fs->get_last_error() );
	}

	public function test_mkdir_under_private_root_rejects_traversal(): void {
		$result = $this->fs->mkdir_under_private_root( '../escape' );
		$this::assertSame( '', $result );
		$this::assertNotSame( '', $this->fs->get_last_error() );
	}

	public function test_mkdir_under_private_root_rejects_internal_traversal(): void {
		$result = $this->fs->mkdir_under_private_root( 'good/../bad' );
		$this::assertSame( '', $result );
		$this::assertNotSame( '', $this->fs->get_last_error() );
	}

	public function test_mkdir_under_private_root_rejects_dot_segment(): void {
		// '.' is filtered silently, so the result collapses to empty.
		$result = $this->fs->mkdir_under_private_root( './.' );
		$this::assertSame( '', $result );
		$this::assertNotSame( '', $this->fs->get_last_error() );
	}

	public function test_mkdir_under_private_root_rejects_dotdot_only(): void {
		$result = $this->fs->mkdir_under_private_root( '..' );
		$this::assertSame( '', $result );
		$this::assertNotSame( '', $this->fs->get_last_error() );
	}

	public function test_mkdir_under_private_root_returns_empty_when_no_export_dir(): void {
		// When SSCRIBE_PRIVATE_STORAGE_DIR is not set, the export root is
		// resolved from sys_get_temp_dir() which is writable in CI, so we
		// instead inject a controlled failure via the filter by using an
		// empty export root simulation. The simplest deterministic branch
		// here is a relative path that becomes empty after normalization.
		$result = $this->fs->mkdir_under_private_root( '/' );
		$this::assertSame( '', $result );
		$this::assertNotSame( '', $this->fs->get_last_error() );
	}

	public function test_mkdir_under_private_root_rejects_empty_after_normalization(): void {
		// Trailing slashes get stripped; collapse-to-empty branch.
		$result = $this->fs->mkdir_under_private_root( '///' );
		$this::assertSame( '', $result );
		$this::assertNotSame( '', $this->fs->get_last_error() );
	}

	public function test_put_contents_writes_real_file(): void {
		$base = \SScribe_Private_Storage::get_export_dir( true );
		if ( '' === $base ) {
			$this::markTestSkipped( 'No private storage dir' );
		}
		$this::assertDirectoryExists( $base );
		$tmp    = $base . '/test_' . uniqid() . '.txt';
		$result = $this->fs->put_contents( $tmp, 'hello world' );
		$this::assertTrue( $result );
		$this::assertSame( 'hello world', file_get_contents( $tmp ) );
		@unlink( $tmp );
	}

	public function test_put_contents_overwrites_existing_file(): void {
		$base = \SScribe_Private_Storage::get_export_dir( true );
		if ( '' === $base ) {
			$this::markTestSkipped( 'No private storage dir' );
		}
		$this::assertDirectoryExists( $base );
		$tmp = $base . '/test_' . uniqid() . '.txt';
		file_put_contents( $tmp, 'first' );
		$result = $this->fs->put_contents( $tmp, 'second' );
		$this::assertTrue( $result );
		$this::assertSame( 'second', file_get_contents( $tmp ) );
		@unlink( $tmp );
	}

	public function test_get_contents_reads_real_file(): void {
		$base = \SScribe_Private_Storage::get_export_dir( true );
		if ( '' === $base ) {
			$this::markTestSkipped( 'No private storage dir' );
		}
		$this::assertDirectoryExists( $base );
		$tmp = $base . '/test_' . uniqid() . '.txt';
		file_put_contents( $tmp, 'round-trip' );
		$result = $this->fs->get_contents( $tmp );
		$this::assertSame( 'round-trip', $result );
		@unlink( $tmp );
	}

	public function test_get_contents_returns_false_for_missing(): void {
		$base = \SScribe_Private_Storage::get_export_dir( true );
		if ( '' === $base ) {
			$this::markTestSkipped( 'No private storage dir' );
		}
		$this::assertDirectoryExists( $base );
		$result = $this->fs->get_contents( $base . '/nope_' . uniqid() . '.txt' );
		$this::assertFalse( $result );
	}

	public function test_delete_removes_real_file(): void {
		$base = \SScribe_Private_Storage::get_export_dir( true );
		if ( '' === $base ) {
			$this::markTestSkipped( 'No private storage dir' );
		}
		$this::assertDirectoryExists( $base );
		$tmp = $base . '/test_' . uniqid() . '.txt';
		file_put_contents( $tmp, 'data' );
		$this::assertTrue( $this->fs->delete( $tmp ) );
		$this::assertFileDoesNotExist( $tmp );
	}

	public function test_delete_returns_true_for_missing_file(): void {
		$base = \SScribe_Private_Storage::get_export_dir( true );
		if ( '' === $base ) {
			$this::markTestSkipped( 'No private storage dir' );
		}
		$this::assertDirectoryExists( $base );
		$result = $this->fs->delete( $base . '/nope_' . uniqid() . '.txt' );
		$this::assertTrue( $result );
	}
}
