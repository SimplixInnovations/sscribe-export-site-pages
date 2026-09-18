<?php
/**
 * SScribe Filesystem dirlist + is_within coverage test
 *
 * Targets additional helpers of SScribe_Filesystem:
 *
 *   - dirlist()           : returns array|false, skips dotfiles + index.php
 *   - is_within_allowed_directory() : false for empty inputs, true inside, false outside
 *   - is_path_safe_for_read() : returns ALLOWED for a file under export_dir
 *   - normalize_path()    : dot/dotdot collapse + windows backslash
 *   - get_method()        : returns string identifier
 *   - is_wp_filesystem()  : returns bool
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

final class SScribe_Filesystem_DirList_IsWithin_Test extends TestCase {

	private \SScribe_Filesystem $fs;
	private ReflectionClass $ref;
	private string $tmp;

	protected function setUp(): void {
		parent::setUp();
		// Reset static $last_error.
		$this->ref = new ReflectionClass( '\\SScribe_Filesystem' );
		$prop = $this->ref->getProperty( 'last_error' );
		$prop->setValue( null, '' );

		$this->fs  = new \SScribe_Filesystem();
		$this->tmp = sys_get_temp_dir() . '/sscribe-fs2-' . bin2hex( random_bytes( 4 ) );
		wp_mkdir_p( $this->tmp );
	}

	protected function tearDown(): void {
		if ( is_dir( $this->tmp ) ) {
			foreach ( scandir( $this->tmp ) ?: array() as $e ) {
				if ( '.' === $e || '..' === $e ) {
					continue;
				}
				$path = $this->tmp . DIRECTORY_SEPARATOR . $e;
				is_dir( $path ) ? array_map( 'unlink', glob( $path . '/*' ) ?: array() ) : @unlink( $path );
				@rmdir( $path );
			}
			@rmdir( $this->tmp );
		}
		parent::tearDown();
	}

	private function call( string $name, array $args = array() ): mixed {
		$m = $this->ref->getMethod( $name );
		return $m->invokeArgs( $this->fs, $args );
	}

	public function test_dirlist_returns_array_for_valid_dir(): void {
		file_put_contents( $this->tmp . '/file.txt', 'x' );
		wp_mkdir_p( $this->tmp . '/sub' );
		$result = $this->fs->dirlist( $this->tmp );
		$this::assertIsArray( $result );
		$this::assertArrayHasKey( 'file.txt', $result );
		$this::assertArrayHasKey( 'sub', $result );
	}

	public function test_dirlist_skips_index_php(): void {
		file_put_contents( $this->tmp . '/index.php', '<?php // silence' );
		file_put_contents( $this->tmp . '/data.txt', 'x' );
		$result = $this->fs->dirlist( $this->tmp );
		$this::assertArrayNotHasKey( 'index.php', $result );
		$this::assertArrayHasKey( 'data.txt', $result );
	}

	public function test_dirlist_skips_dotfiles(): void {
		file_put_contents( $this->tmp . '/.hidden', 'x' );
		file_put_contents( $this->tmp . '/visible.txt', 'x' );
		$result = $this->fs->dirlist( $this->tmp );
		$this::assertArrayNotHasKey( '.hidden', $result );
	}

	public function test_dirlist_returns_false_for_non_dir(): void {
		$result = $this->fs->dirlist( $this->tmp . '/missing-dir' );
		$this::assertFalse( $result );
	}

	public function test_is_within_allowed_directory_rejects_empty(): void {
		$this::assertFalse( $this->fs->is_within_allowed_directory( '', $this->tmp ) );
		$this::assertFalse( $this->fs->is_within_allowed_directory( $this->tmp, '' ) );
	}

	public function test_is_within_allowed_directory_true_for_subpath(): void {
		file_put_contents( $this->tmp . '/inside.txt', 'x' );
		$result = $this->fs->is_within_allowed_directory( $this->tmp . '/inside.txt', $this->tmp );
		$this::assertTrue( $result );
	}

	public function test_is_within_allowed_directory_false_for_outside(): void {
		$outside = sys_get_temp_dir() . '/outside-' . bin2hex( random_bytes( 4 ) ) . '.txt';
		file_put_contents( $outside, 'x' );
		try {
			$result = $this->fs->is_within_allowed_directory( $outside, $this->tmp );
			$this::assertFalse( $result );
		} finally {
			@unlink( $outside );
		}
	}

	public function test_normalize_path_collapses_dots(): void {
		$result = $this->call( 'normalize_path', array( '/a/./b/../c' ) );
		$this::assertIsString( $result );
		$this::assertStringContainsString( '/a/c', $result );
	}

	public function test_normalize_path_handles_backslashes(): void {
		$result = $this->call( 'normalize_path', array( '\\foo\\bar' ) );
		$this::assertIsString( $result );
		$this::assertStringContainsString( '/foo/bar', $result );
	}

	public function test_get_method_returns_string(): void {
		$result = $this->fs->get_method();
		$this::assertIsString( $result );
		$this::assertNotSame( '', $result );
	}

	public function test_is_wp_filesystem_returns_bool(): void {
		$result = $this->fs->is_wp_filesystem();
		$this::assertIsBool( $result );
		$this::assertFalse( $result ); // unit env has no wp_filesystem
	}

	public function test_class_has_expected_methods(): void {
		$this::assertTrue( method_exists( \SScribe_Filesystem::class, 'dirlist' ) );
		$this::assertTrue( method_exists( \SScribe_Filesystem::class, 'is_within_allowed_directory' ) );
		$this::assertTrue( method_exists( \SScribe_Filesystem::class, 'exists' ) );
		$this::assertTrue( method_exists( \SScribe_Filesystem::class, 'is_dir' ) );
		$this::assertTrue( method_exists( \SScribe_Filesystem::class, 'is_writable' ) );
	}
}
