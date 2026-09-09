<?php
/**
 * Branch-coverage tests for SScribe_Security is_path_in_scope() edge cases.
 *
 * Complements SScribe_Security_Branches_Test by exercising the
 * dangling-symlink branch (security.php line 254). The branch fires
 * when realpath() returns false but is_link() returns true —
 * the path is a symlink whose target does not exist. is_path_in_scope
 * must refuse because the future target may escape plugin-owned
 * storage once it materialises.
 *
 * Uses real symlinks on a real temp directory. The test is
 * skipped on platforms where symlink() is unavailable.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

if ( ! class_exists( '\\SScribe_Security' ) ) {
	require_once dirname( __DIR__, 2 ) . '/includes/class-sscribe-security.php';
}

final class SScribe_Security_Dangling_Symlink_Test extends TestCase {

	/** @var string */
	private string $temp_dir = '';

	protected function setUp(): void {
		parent::setUp();
		if ( ! function_exists( 'symlink' ) ) {
			$this::markTestSkipped( 'symlink() not available on this platform.' );
		}
		$this->temp_dir = sys_get_temp_dir() . '/sscribe-dangling-' . uniqid( '', true );
		mkdir( $this->temp_dir, 0700, true );
	}

	protected function tearDown(): void {
		if ( is_dir( $this->temp_dir ) ) {
			$this->rmdir_recursive( $this->temp_dir );
		}
		parent::tearDown();
	}

	private function rmdir_recursive( string $dir ): void {
		$items = @scandir( $dir );
		if ( false === $items ) {
			return;
		}
		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $dir . DIRECTORY_SEPARATOR . $item;
			if ( is_dir( $path ) && ! is_link( $path ) ) {
				$this->rmdir_recursive( $path );
			} else {
				@unlink( $path );
			}
		}
		@rmdir( $dir );
	}

	/**
	 * Exercise is_path_in_scope's dangling-symlink branch
	 * (security.php line 254). A symlink whose target does not
	 * exist produces realpath() === false and is_link() === true.
	 * The validator must refuse it.
	 */
	public function test_is_path_in_scope_rejects_dangling_symlink(): void {
		$link_path = $this->temp_dir . '/dangling';
		$target    = $this->temp_dir . '/never-created-target';
		if ( ! @symlink( $target, $link_path ) ) {
			$this::markTestSkipped( 'Symbolic links unavailable in this environment (Windows without Developer Mode).' );
		}

		// Pre-conditions for the branch:
		$this::assertTrue( is_link( $link_path ) );
		$this::assertFalse( realpath( $link_path ) );

		$reflection = new \ReflectionClass( \SScribe_Security::class );
		$method     = $reflection->getMethod( 'is_path_in_scope' );
		$result     = $method->invoke( null, $link_path );

		$this::assertFalse(
			$result,
			'is_path_in_scope must refuse a dangling symlink because its future target may escape plugin-owned storage.'
		);
	}
}
