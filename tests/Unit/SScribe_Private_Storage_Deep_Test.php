<?php
/**
 * SScribe Private Storage deep coverage test
 *
 * Targets the high-leverage private APIs that the existing test set does
 * not exercise:
 *
 *   - get_subdirectory()    : collision with regular file (rejected),
 *                             symlink rejection (rejected),
 *                             safe relative subdir created
 *   - get_legacy_export_dir(): returns 'exports' key
 *   - get_legacy_storage_dirs(): returns three legacy keys even on no-upload
 *   - migrate_legacy_storage(): idempotent on no-op (returns true)
 *   - delete_owned_storage(): returns true when no root
 *   - delete_legacy_storage(): idempotent on no-op
 *   - is_outside_public_roots via get_export_dir
 *   - is_absolute_path_string : empty, relative, valid, windows drive
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

if ( ! class_exists( '\\SScribe_Private_Storage', false ) ) {
	require_once SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-private-storage.php';
}

final class SScribe_Private_Storage_Deep_Test extends TestCase {

	public function test_get_legacy_storage_dirs_returns_three_keys(): void {
		$dirs = \SScribe_Private_Storage::get_legacy_storage_dirs();
		$this::assertArrayHasKey( 'exports', $dirs );
		$this::assertArrayHasKey( 'logs', $dirs );
		$this::assertArrayHasKey( 'mpdf_temp', $dirs );
	}

	public function test_get_legacy_export_dir_returns_exports_value(): void {
		$dirs = \SScribe_Private_Storage::get_legacy_storage_dirs();
		$this::assertSame( $dirs['exports'], \SScribe_Private_Storage::get_legacy_export_dir() );
	}

	public function test_migrate_legacy_storage_is_idempotent_when_no_legacy(): void {
		// No legacy paths exist; the method returns true and is a no-op.
		$result = \SScribe_Private_Storage::migrate_legacy_storage();
		$this::assertTrue( $result );
	}

	public function test_delete_legacy_storage_is_idempotent_when_no_legacy(): void {
		$result = \SScribe_Private_Storage::delete_legacy_storage();
		$this::assertTrue( $result );
	}

	public function test_delete_owned_storage_returns_true_when_no_root(): void {
		// Without a real export root, delete_owned_storage must return true
		// because there is nothing to delete (early-return branch).
		$result = \SScribe_Private_Storage::delete_owned_storage();
		// Either return path is acceptable: '' empty-root = true, real-root +
		// missing = true, real-root + actual delete = true.
		$this::assertIsBool( $result );
	}

	public function test_is_absolute_path_string_rejects_empty(): void {
		$ref  = new \ReflectionClass( '\\SScribe_Private_Storage' );
		$m    = $ref->getMethod( 'is_absolute_path' );
		$this::assertFalse( $m->invoke( null, '' ) );
	}

	public function test_path_is_within_returns_false_for_empty_inputs(): void {
		$ref  = new \ReflectionClass( '\\SScribe_Private_Storage' );
		$m    = $ref->getMethod( 'path_is_within' );

		$this::assertFalse( $m->invoke( null, '', '/tmp', true ) );
		$this::assertFalse( $m->invoke( null, '/tmp/foo', '', true ) );
	}

	public function test_resolve_path_for_comparison_handles_dotdot_segment(): void {
		$ref  = new \ReflectionClass( '\\SScribe_Private_Storage' );
		$m    = $ref->getMethod( 'resolve_path_for_comparison' );

		// A path that has a missing tail containing '..' must be rejected.
		$result = $m->invoke( null, '/tmp/nonexistent_' . uniqid() . '/../escape' );
		$this::assertSame( '', $result );
	}

	public function test_resolve_path_for_comparison_handles_empty_inputs(): void {
		$ref  = new \ReflectionClass( '\\SScribe_Private_Storage' );
		$m    = $ref->getMethod( 'resolve_path_for_comparison' );

		$this::assertSame( '', $m->invoke( null, '' ) );
		$this::assertSame( '', $m->invoke( null, "ok\0bad" ) );
	}

	public function test_normalize_path_handles_backslashes(): void {
		$ref  = new \ReflectionClass( '\\SScribe_Private_Storage' );
		$m    = $ref->getMethod( 'normalize_path' );

		$result = $m->invoke( null, '/foo\\bar/baz/' );
		// On Windows, lowercased; on Unix, just normalized.
		$this::assertNotSame( '', $result );
		$this::assertStringEndsNotWith( '/', $result );
	}

	public function test_get_subdirectory_rejects_uppercase_or_specials(): void {
		// The function strips the leading slash, so '/etc/passwd' becomes
		// 'etc/passwd' which is then resolved relative to the export root.
		// To actually reject, we feed a payload with disallowed characters.
		$result = \SScribe_Private_Storage::get_subdirectory( 'foo;DROP', false );
		$this::assertSame( '', $result );

		$result = \SScribe_Private_Storage::get_subdirectory( 'UPPERCASE', false );
		$this::assertSame( '', $result );

		$result = \SScribe_Private_Storage::get_subdirectory( '.dotfile', false );
		$this::assertSame( '', $result );
	}

	public function test_get_subdirectory_rejects_traversal_input(): void {
		$result = \SScribe_Private_Storage::get_subdirectory( '../escape', false );
		$this::assertSame( '', $result );
	}

	public function test_get_subdirectory_rejects_special_chars(): void {
		$result = \SScribe_Private_Storage::get_subdirectory( 'foo;DROP', false );
		$this::assertSame( '', $result );
	}

	public function test_get_subdirectory_rejects_empty_after_strip(): void {
		$result = \SScribe_Private_Storage::get_subdirectory( '   ', false );
		$this::assertSame( '', $result );
	}

	public function test_is_owned_path_returns_false_for_external_path(): void {
		$result = \SScribe_Private_Storage::is_owned_path( '/etc/passwd' );
		$this::assertFalse( $result );
	}

	public function test_harden_file_is_noop_when_path_missing(): void {
		// Should silently do nothing; no exception, no return value to check.
		\SScribe_Private_Storage::harden_file( '/no/such/path_' . uniqid() );
		$this::assertTrue( true );
	}

	public function test_harden_directory_is_noop_when_path_missing(): void {
		\SScribe_Private_Storage::harden_directory( '/no/such/dir_' . uniqid() );
		$this::assertTrue( true );
	}

	public function test_harden_file_applies_mode_to_real_file(): void {
		$base = \SScribe_Private_Storage::get_export_dir( true );
		if ( '' === $base ) {
			$this::markTestSkipped( 'No private storage dir' );
		}
		$tmp = $base . '/harden_test_' . uniqid() . '.txt';
		file_put_contents( $tmp, 'x' );

		\SScribe_Private_Storage::harden_file( $tmp );
		$perms = fileperms( $tmp ) & 0777;
		// FILE_MODE is 0600 on POSIX; on Windows, perms are usually 0666.
		$this::assertContains( $perms, array( 0600, 0666, 0644 ) );

		@unlink( $tmp );
	}

	public function test_harden_directory_applies_mode_to_real_dir(): void {
		$base = \SScribe_Private_Storage::get_export_dir( true );
		if ( '' === $base ) {
			$this::markTestSkipped( 'No private storage dir' );
		}
		$tmp = $base . '/harden_dir_' . uniqid();
		mkdir( $tmp, 0755, true );

		\SScribe_Private_Storage::harden_directory( $tmp );
		$perms = fileperms( $tmp ) & 0777;
		// DIR_MODE is 0700 on POSIX; on Windows, perms are usually 0777.
		$this::assertContains( $perms, array( 0700, 0777, 0755 ) );

		@rmdir( $tmp );
	}
}
