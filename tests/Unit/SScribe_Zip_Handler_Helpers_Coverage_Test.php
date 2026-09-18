<?php
/**
 * SScribe Zip Handler helper coverage test
 *
 * Targets the private helpers and small public methods of SScribe_Zip_Handler:
 *
 *   - normalize_zip_filename()  : empty, oversize, bad extension, path,
 *                                leading dot, valid
 *   - is_safe_source_file()     : missing file, real file in scope, symlink
 *   - resolve_temp_directory()  : empty, missing dir, real scope, basename
 *   - cleanup_expired()         : returns int
 *   - delete_export()           : returns false for malformed, true otherwise
 *   - is_available()            : returns bool
 *   - get_export_dir()          : returns string
 *   - get_export_entry()        : returns null for malformed
 *   - list_export_entries()     : returns array
 *   - get_ajax_download_url()   : returns string
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;

if ( ! class_exists( '\\SScribe_Zip_Handler', false ) ) {
	require_once SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-zip-handler.php';
}

final class SScribe_Zip_Handler_Helpers_Coverage_Test extends TestCase {

	private \SScribe_Zip_Handler $zh;
	private ReflectionClass $ref;

	protected function setUp(): void {
		parent::setUp();
		$this->zh  = new \SScribe_Zip_Handler();
		$this->ref = new ReflectionClass( $this->zh );
	}

	private function call( string $name, array $args = array() ): mixed {
		$m = $this->ref->getMethod( $name );
		return $m->invokeArgs( $this->zh, $args );
	}

	public function test_normalize_zip_filename_rejects_empty(): void {
		$result = $this->call( 'normalize_zip_filename', array( '' ) );
		$this::assertSame( '', $result );
	}

	public function test_normalize_zip_filename_rejects_oversize(): void {
		$long = str_repeat( 'a', 210 ) . '.zip';
		$result = $this->call( 'normalize_zip_filename', array( $long ) );
		$this::assertSame( '', $result );
	}

	public function test_normalize_zip_filename_rejects_non_zip_extension(): void {
		$result = $this->call( 'normalize_zip_filename', array( 'archive.tar' ) );
		$this::assertSame( '', $result );
	}

	public function test_normalize_zip_filename_rejects_path_separator(): void {
		$result = $this->call( 'normalize_zip_filename', array( 'path/to/file.zip' ) );
		$this::assertSame( '', $result );
	}

	public function test_normalize_zip_filename_rejects_leading_dot(): void {
		$result = $this->call( 'normalize_zip_filename', array( '.hidden.zip' ) );
		$this::assertSame( '', $result );
	}

	public function test_normalize_zip_filename_accepts_valid_basename(): void {
		$result = $this->call( 'normalize_zip_filename', array( 'valid-export-2026.zip' ) );
		$this::assertNotSame( '', $result );
	}

	public function test_is_safe_source_file_rejects_missing(): void {
		$result = $this->call( 'is_safe_source_file', array( '/no/such/file.txt', sys_get_temp_dir() ) );
		$this::assertFalse( $result );
	}

	public function test_is_safe_source_file_rejects_symlink(): void {
		$tmp = sys_get_temp_dir() . '/zh_link_src_' . uniqid();
		$dst = sys_get_temp_dir() . '/zh_link_dst_' . uniqid();
		file_put_contents( $tmp, 'x' );
		@symlink( $tmp, $dst );

		$result = $this->call( 'is_safe_source_file', array( $dst, sys_get_temp_dir() ) );
		$this::assertFalse( $result );

		@unlink( $dst );
		@unlink( $tmp );
	}

	public function test_is_safe_source_file_accepts_real_file_in_scope(): void {
		$tmp = sys_get_temp_dir() . '/zh_real_' . uniqid() . '.txt';
		file_put_contents( $tmp, 'x' );

		$result = $this->call( 'is_safe_source_file', array( $tmp, sys_get_temp_dir() ) );
		$this::assertTrue( $result );

		@unlink( $tmp );
	}

	public function test_is_safe_source_file_rejects_outside_scope(): void {
		// Use SSCRIBE private storage as the scope; file outside it must be rejected.
		$base = \SScribe_Private_Storage::get_export_dir( false );
		if ( '' === $base ) {
			$this::markTestSkipped( 'No private storage dir' );
		}
		$tmp = sys_get_temp_dir() . '/zh_outside_' . uniqid() . '.txt';
		file_put_contents( $tmp, 'x' );

		$result = $this->call( 'is_safe_source_file', array( $tmp, $base ) );
		$this::assertFalse( $result );

		@unlink( $tmp );
	}

	public function test_resolve_temp_directory_rejects_empty(): void {
		$result = $this->call( 'resolve_temp_directory', array( '' ) );
		$this::assertSame( '', $result );
	}

	public function test_resolve_temp_directory_rejects_missing_dir(): void {
		$result = $this->call( 'resolve_temp_directory', array( '/no/such/dir_' . uniqid() ) );
		$this::assertSame( '', $result );
	}

	public function test_resolve_temp_directory_rejects_non_temp_basename(): void {
		$tmp = sys_get_temp_dir() . '/not-temp-' . uniqid();
		mkdir( $tmp, 0755, true );

		$result = $this->call( 'resolve_temp_directory', array( $tmp ) );
		$this::assertSame( '', $result );

		@rmdir( $tmp );
	}

	public function test_get_export_dir_returns_string(): void {
		$dir = $this->zh->get_export_dir();
		$this::assertIsString( $dir );
	}

	public function test_is_available_returns_bool(): void {
		$result = $this->zh->is_available();
		$this::assertIsBool( $result );
	}

	public function test_cleanup_expired_returns_int(): void {
		$result = $this->zh->cleanup_expired();
		$this::assertIsInt( $result );
	}

	public function test_delete_export_returns_false_for_malformed(): void {
		$result = $this->zh->delete_export( '../escape.zip', 1 );
		$this::assertFalse( $result );
	}

	public function test_get_export_entry_returns_null_for_malformed(): void {
		$result = $this->zh->get_export_entry( '../escape.zip' );
		$this::assertNull( $result );
	}

	public function test_list_export_entries_returns_array(): void {
		$result = $this->zh->list_export_entries();
		$this::assertIsArray( $result );
	}

	public function test_get_ajax_download_url_returns_string(): void {
		$result = $this->zh->get_ajax_download_url( 'clean-name.zip' );
		$this::assertIsString( $result );
	}

	public function test_class_has_expected_public_methods(): void {
		$this::assertTrue( method_exists( \SScribe_Zip_Handler::class, 'create_zip' ) );
		$this::assertTrue( method_exists( \SScribe_Zip_Handler::class, 'cleanup_expired' ) );
		$this::assertTrue( method_exists( \SScribe_Zip_Handler::class, 'delete_export' ) );
		$this::assertTrue( method_exists( \SScribe_Zip_Handler::class, 'rotate_dl_token' ) );
		$this::assertTrue( method_exists( \SScribe_Zip_Handler::class, 'consume_dl_token' ) );
	}
}
