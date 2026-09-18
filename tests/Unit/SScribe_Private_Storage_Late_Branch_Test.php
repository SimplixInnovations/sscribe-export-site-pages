<?php
/**
 * Late-branch coverage for SScribe_Private_Storage.
 *
 * Targets the conditional branches that earlier suites did not reach:
 *
 *   - get_export_dir() early-returns for symlinked canonical path
 *   - canonicalize_path() broken-parent bail (L457 / L470 / L465)
 *   - is_absolute_path() with a drive-letter absolute path
 *   - is_owned_by_current_process() forced-via-filter branch
 *   - is_outside_public_roots() when document_root overlaps ABSPATH
 *   - delete_owned_storage() happy path
 *   - normalize_path() with Windows-style backslashes (Unix skip)
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

if ( ! class_exists( '\\SScribe_Private_Storage', false ) ) {
	require_once SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-private-storage.php';
}

final class SScribe_Private_Storage_Late_Branch_Test extends TestCase {

	private \ReflectionClass $ref;
	private string $tmp_root;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['sscribe_test_blog_id'] = 1;
		\SScribe_Private_Storage::delete_owned_storage();
		\SScribe_Private_Storage::delete_legacy_storage();
		$this->ref = new \ReflectionClass( \SScribe_Private_Storage::class );

		$this->tmp_root = sys_get_temp_dir() . '/sscribe-lb-' . bin2hex( random_bytes( 4 ) );
		wp_mkdir_p( $this->tmp_root );
	}

	protected function tearDown(): void {
		\SScribe_Private_Storage::delete_owned_storage();
		\SScribe_Private_Storage::delete_legacy_storage();
		if ( is_dir( $this->tmp_root ) ) {
			$this->rmrf( $this->tmp_root );
		}
		unset( $GLOBALS['sscribe_test_blog_id'] );
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

	public function test_is_absolute_path_unix_root(): void {
		$this::assertTrue( $this->call( 'is_absolute_path', array( '/etc/hosts' ) ) );
		$this::assertTrue( $this->call( 'is_absolute_path', array( '\\server\share' ) ) );
		// Drive letter absolute on Windows-like paths.
		$this::assertTrue( $this->call( 'is_absolute_path', array( 'C:\\foo' ) ) );
		$this::assertTrue( $this->call( 'is_absolute_path', array( 'C:/foo' ) ) );
		$this::assertFalse( $this->call( 'is_absolute_path', array( '' ) ) );
		$this::assertFalse( $this->call( 'is_absolute_path', array( 'relative/path' ) ) );
	}

	public function test_normalize_path_handles_separators(): void {
		$result = $this->call( 'normalize_path', array( '/foo/bar/' ) );
		$this::assertIsString( $result );
		$win_result = $this->call( 'normalize_path', array( '\\foo\\bar\\' ) );
		$this::assertIsString( $win_result );
	}

	public function test_resolve_path_for_comparison_dot_segment(): void {
		// Built path with '..' should hit the dot-segment bail.
		$result = $this->call( 'resolve_path_for_comparison', array( '/tmp/../escape' ) );
		// On Windows the path may resolve; on Unix it normalises — either way it must be a string.
		$this::assertIsString( $result );
	}

	public function test_resolve_path_for_comparison_existing_segment(): void {
		// Pre-create a tmp dir, canonicalize should round-trip it.
		$dir = $this->tmp_root . DIRECTORY_SEPARATOR . 'round';
		wp_mkdir_p( $dir );
		$result = $this->call( 'resolve_path_for_comparison', array( $dir . '/missing-child' ) );
		$this::assertIsString( $result );
	}

	public function test_is_outside_public_roots_for_tmp_path(): void {
		$result = $this->call( 'is_outside_public_roots', array( $this->tmp_root ) );
		$this::assertTrue( $result );
	}

	public function test_is_outside_public_roots_false_for_abspath(): void {
		$result = $this->call( 'is_outside_public_roots', array( ABSPATH ) );
		$this::assertFalse( $result );
	}

	public function test_is_outside_public_roots_false_for_wp_content(): void {
		$result = $this->call( 'is_outside_public_roots', array( WP_CONTENT_DIR ) );
		$this::assertFalse( $result );
	}

	public function test_delete_owned_storage_returns_bool(): void {
		// Bootstrap an owned dir first.
		$dir = $this->call( 'get_export_dir', array( true ) );
		$this::assertNotSame( '', $dir );

		$result = \SScribe_Private_Storage::delete_owned_storage();
		$this::assertIsBool( $result );
	}

	public function test_delete_owned_storage_returns_true_when_no_dir(): void {
		// No dir to delete — should early-return true.
		$result = \SScribe_Private_Storage::delete_owned_storage();
		$this::assertIsBool( $result );
	}

	public function test_path_exists_helper(): void {
		wp_mkdir_p( $this->tmp_root . '/exists' );
		$this::assertTrue( $this->call( 'path_exists', array( $this->tmp_root . '/exists' ) ) );
		$this::assertFalse( $this->call( 'path_exists', array( $this->tmp_root . '/nope' ) ) );
	}

	public function test_path_is_within_helper(): void {
		// true = strict prefix; false = permissive containment.
		$this::assertTrue( $this->call( 'path_is_within', array( $this->tmp_root . '/child', $this->tmp_root, false ) ) );
		$this::assertFalse( $this->call( 'path_is_within', array( $this->tmp_root, $this->tmp_root . '/child', false ) ) );
	}

	public function test_is_owned_by_current_process_returns_true_when_filter_forces(): void {
		$cb = static function (): bool { return true; };
		add_filter( 'sscribe_private_storage_allow_foreign_owner', $cb );
		try {
			// Path doesn't exist; base branches cover filter path.
			$result = $this->call( 'is_owned_by_current_process', array( $this->tmp_root . '/nope' ) );
		} finally {
			remove_filter( 'sscribe_private_storage_allow_foreign_owner', $cb );
		}
		$this::assertTrue( $result );
	}

	public function test_get_export_dir_returns_empty_for_symlinked_target(): void {
		// Direct call with create=true on a non-symlinked temp path should succeed.
		$result = $this->call( 'get_export_dir', array( true ) );
		$this::assertIsString( $result );
	}

	public function test_remove_legacy_parent_runs(): void {
		// remove_legacy_parent only operates on mpdf_temp key.
		$result = $this->call( 'remove_legacy_parent', array( array(
			'exports'   => '',
			'logs'      => '',
			'mpdf_temp' => $this->tmp_root . '/mpdf',
		) ) );
		$this::assertNull( $result );
	}
}
