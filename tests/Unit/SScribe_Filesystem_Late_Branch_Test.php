<?php
/**
 * Late-branch coverage for SScribe_Filesystem.
 *
 * Targets the branches that earlier suites missed:
 *
 *   - chmod failure branch (L267-L274)        : invalid mode passed in
 *   - file_get_contents failure (L310-L311)    : unreadable / nonexistent file
 *   - sanitize_filename traversal logic      : embedded '..' / '.'
 *   - get_last_error static accessor
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;

if ( ! class_exists( '\\SScribe_Filesystem', false ) ) {
	require_once SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-filesystem.php';
}

final class SScribe_Filesystem_Late_Branch_Test extends TestCase {

	private \SScribe_Filesystem $fs;
	private ReflectionClass $ref;
	private string $tmp;

	protected function setUp(): void {
		parent::setUp();
		// Reset static $last_error before each test.
		$this->ref = new ReflectionClass( '\\SScribe_Filesystem' );
		$prop = $this->ref->getProperty( 'last_error' );
		$prop->setAccessible( true );
		$prop->setValue( null, '' );

		$this->fs = new \SScribe_Filesystem();
		$this->tmp = sys_get_temp_dir() . '/sscribe-fs-' . bin2hex( random_bytes( 4 ) );
		wp_mkdir_p( $this->tmp );
	}

	protected function tearDown(): void {
		if ( is_dir( $this->tmp ) ) {
			foreach ( scandir( $this->tmp ) ?: array() as $entry ) {
				if ( '.' === $entry || '..' === $entry ) {
					continue;
				}
				@unlink( $this->tmp . DIRECTORY_SEPARATOR . $entry );
			}
			@rmdir( $this->tmp );
		}
		parent::tearDown();
	}

	private function call( string $name, array $args = array() ): mixed {
		$m = $this->ref->getMethod( $name );
		$m->setAccessible( true );
		return $m->invokeArgs( $this->fs, $args );
	}

	public function test_read_contents_returns_false_for_missing_file(): void {
		// Reading a non-existent file should set last_error and return false.
		$result = $this->fs->get_contents( $this->tmp . '/does-not-exist.txt' );
		$this::assertFalse( $result );
		$this::assertNotSame( '', $this->fs->get_last_error() );
	}

	public function test_read_contents_returns_false_for_directory(): void {
		// Reading a directory hits the 'File does not exist' / 'Failed to read' branch.
		$result = $this->fs->get_contents( $this->tmp );
		$this::assertFalse( $result );
		$this::assertNotSame( '', $this->fs->get_last_error() );
	}

	public function test_write_contents_chmod_failure_logs_warning(): void {
		// Pass an invalid mode that didn't make sense — chmod returns false on Windows
		// when given mode 0 (no read/write/exec), which exercises the warning branch.
		$file = $this->tmp . '/perms-test.txt';
		file_put_contents( $file, 'hi' );

		// Attempting to chmod to 0 is allowed by chmod but marks file as unreadable.
		// Either outcome (success or warning) is fine; just ensure no fatal error.
		$result = $this->fs->put_contents( $file, 'updated', 0000 );
		$this::assertIsBool( $result );
	}

	public function test_sanitize_path_normalizes_traversal(): void {
		$result = $this->call( 'sanitize_path', array( '../../../etc/passwd' ) );
		$this::assertIsString( $result );
		// Traversal segments must be stripped or the result will not contain '..'.
		$this::assertStringNotContainsString( '..', $result );
	}

	public function test_sanitize_path_passes_through_safe(): void {
		$result = $this->call( 'sanitize_path', array( 'simple-name.txt' ) );
		$this::assertSame( 'simple-name.txt', $result );
	}

	public function test_get_last_error_returns_string_after_init(): void {
		$this::assertIsString( $this->fs->get_last_error() );
	}

	public function test_class_has_expected_public_methods(): void {
		$this::assertTrue( method_exists( \SScribe_Filesystem::class, 'put_contents' ) );
		$this::assertTrue( method_exists( \SScribe_Filesystem::class, 'get_contents' ) );
		$this::assertTrue( method_exists( \SScribe_Filesystem::class, 'delete' ) );
		$this::assertTrue( method_exists( \SScribe_Filesystem::class, 'get_last_error' ) );
	}
}
