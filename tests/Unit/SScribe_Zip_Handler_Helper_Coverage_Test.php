<?php
/**
 * SScribe Zip Handler helper coverage test
 *
 * Targets the pure private helpers of SScribe_Zip_Handler:
 *
 *   - normalize_zip_filename()       : accept, reject empty, reject .exe, reject traversal
 *   - is_safe_source_file()         : symlink / non-file rejection
 *   - resolve_temp_directory()       : empty / non-dir rejection
 *   - generate_dl_token()            : returns 32-char hex
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;

if ( ! class_exists( '\\SScribe_Zip_Handler', false ) ) {
	require_once SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-zip-handler.php';
}

final class SScribe_Zip_Handler_Helper_Coverage_Test extends TestCase {

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

	public function test_normalize_zip_filename_accepts_valid(): void {
		$result = $this->call( 'normalize_zip_filename', array( 'export_2025-01-01.zip' ) );
		$this::assertSame( 'export_2025-01-01.zip', $result );
	}

	public function test_normalize_zip_filename_rejects_empty(): void {
		$this::assertSame( '', $this->call( 'normalize_zip_filename', array( '' ) ) );
		$this::assertSame( '', $this->call( 'normalize_zip_filename', array( '   ' ) ) );
	}

	public function test_normalize_zip_filename_rejects_non_zip_extension(): void {
		$this::assertSame( '', $this->call( 'normalize_zip_filename', array( 'evil.exe' ) ) );
		$this::assertSame( '', $this->call( 'normalize_zip_filename', array( 'malware.php' ) ) );
	}

	public function test_normalize_zip_filename_rejects_traversal(): void {
		$this::assertSame( '', $this->call( 'normalize_zip_filename', array( '../etc/passwd.zip' ) ) );
		$this::assertSame( '', $this->call( 'normalize_zip_filename', array( '/etc/passwd.zip' ) ) );
	}

	public function test_normalize_zip_filename_rejects_oversize(): void {
		// 205-char filename exceeds the 204 limit.
		$long = str_repeat( 'a', 201 ) . '.zip';
		$this::assertSame( '', $this->call( 'normalize_zip_filename', array( $long ) ) );
	}

	public function test_normalize_zip_filename_rejects_disallowed_chars(): void {
		$this::assertSame( '', $this->call( 'normalize_zip_filename', array( 'foo bar.zip' ) ) );
		$this::assertSame( '', $this->call( 'normalize_zip_filename', array( "foo'bar.zip" ) ) );
	}

	public function test_resolve_temp_directory_rejects_empty(): void {
		$this::assertSame( '', $this->call( 'resolve_temp_directory', array( '' ) ) );
	}

	public function test_resolve_temp_directory_rejects_non_dir(): void {
		$this::assertSame( '', $this->call( 'resolve_temp_directory', array( '/nonexistent-' . bin2hex( random_bytes( 4 ) ) ) ) );
	}

	public function test_resolve_temp_directory_rejects_symlink(): void {
		if ( defined( 'PHP_WINDOWS_VERSION_BUILD' ) ) {
			$this::markTestSkipped( 'symlink test unreliable on Windows' );
		}
		$target = sys_get_temp_dir() . '/zh-target-' . bin2hex( random_bytes( 4 ) );
		$link   = sys_get_temp_dir() . '/zh-link-' . bin2hex( random_bytes( 4 ) );
		mkdir( $target );
		symlink( $target, $link );
		try {
			$this::assertSame( '', $this->call( 'resolve_temp_directory', array( $link ) ) );
		} finally {
			unlink( $link );
			rmdir( $target );
		}
	}

	public function test_generate_dl_token_returns_32_hex(): void {
		$tok = $this->call( 'generate_dl_token' );
		$this::assertIsString( $tok );
		$this::assertSame( 32, strlen( $tok ) );
		$this::assertSame( 1, preg_match( '/^[a-f0-9]{32}$/', $tok ) );
	}

	public function test_generate_dl_token_unique_per_call(): void {
		$a = $this->call( 'generate_dl_token' );
		$b = $this->call( 'generate_dl_token' );
		$this::assertNotSame( $a, $b );
	}

	public function test_is_safe_source_file_rejects_symlink(): void {
		if ( defined( 'PHP_WINDOWS_VERSION_BUILD' ) ) {
			$this::markTestSkipped( 'symlink test unreliable on Windows' );
		}
		$target = sys_get_temp_dir() . '/zh-sf-' . bin2hex( random_bytes( 4 ) ) . '.txt';
		$link   = $target . '.link';
		file_put_contents( $target, 'x' );
		symlink( $target, $link );
		$root = dirname( $target );
		try {
			$this::assertFalse( $this->call( 'is_safe_source_file', array( $link, $root ) ) );
		} finally {
			unlink( $link );
			unlink( $target );
		}
	}

	public function test_is_safe_source_file_rejects_non_file(): void {
		// Directory is not a regular file.
		$root = sys_get_temp_dir();
		$this::assertFalse( $this->call( 'is_safe_source_file', array( $root, $root ) ) );
	}

	public function test_class_has_expected_public_methods(): void {
		$this::assertTrue( method_exists( \SScribe_Zip_Handler::class, 'create_zip' ) );
		$this::assertTrue( method_exists( \SScribe_Zip_Handler::class, 'is_available' ) );
		$this::assertTrue( method_exists( \SScribe_Zip_Handler::class, 'list_export_entries' ) );
	}

	public function test_consume_dl_token_rejects_empty(): void {
		$result = $this->zh->consume_dl_token( 'foo.zip', '' );
		$this::assertFalse( $result );
	}

	public function test_get_export_entry_returns_null_for_invalid_filename(): void {
		$result = $this->zh->get_export_entry( '../invalid.zip' );
		$this::assertNull( $result );
	}
}
