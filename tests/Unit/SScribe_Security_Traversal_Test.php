<?php
/**
 * Regression tests for the security hardening in sScribe 2.0.0.
 *
 * Covers:
 *   - Finding 2 : parent-directory traversal rejection in is_path_in_scope
 *                 (preventing a `..` segment from bypassing the
 *                 literal-prefix containment check).
 *   - Finding 4 : cross-tenant symlink rejection in get_temp_dir when
 *                 the base is a foreign-owned directory on a shared /tmp.
 *   - Candidate 5 : symlinks whose target resolves inside the allowed
 *                   root are accepted (managed-mount staging).
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SScribe_Security_Traversal_Test extends TestCase {

	private string $temp_dir;

	protected function setUp(): void {
		$this->temp_dir = \SScribe_Private_Storage::get_subdirectory( 'security-traversal-' . uniqid() );
	}

	protected function tearDown(): void {
		if ( is_dir( $this->temp_dir ) ) {
			\SScribe_Security::delete_directory( $this->temp_dir );
		}
	}

	/**
	 * Finding 2: protect_directory() must reject any path whose
	 * canonical form leaves SScribe's private storage tree.
	 */
	public function test_protect_directory_rejects_parent_directory_traversal(): void {
		$base = $this->temp_dir;
		$escaped = $base . '/logs/../../../etc';

		$this->expectException( \InvalidArgumentException::class );
		\SScribe_Security::protect_directory( $escaped );
	}

	public function test_protect_directory_rejects_leading_parent_traversal(): void {
		$escaped = $this->temp_dir . '/../escape-' . uniqid();

		$this->expectException( \InvalidArgumentException::class );
		\SScribe_Security::protect_directory( $escaped );
	}

	public function test_protect_directory_rejects_trailing_parent_traversal(): void {
		$escaped = $this->temp_dir . '/child/..' . '/escape-' . uniqid();

		$this->expectException( \InvalidArgumentException::class );
		\SScribe_Security::protect_directory( $escaped );
	}

	/**
	 * Candidate 5: a symlink whose target resolves inside the allowed
	 * root must NOT be rejected outright — staging workflows symlink
	 * managed mounts into SScribe's private tree.
	 */
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	#[\PHPUnit\Framework\Attributes\PreserveGlobalState( false )]
	public function test_protect_directory_allows_symlink_whose_target_is_in_scope(): void {
		if ( ! function_exists( 'symlink' ) ) {
			$this->markTestSkipped( 'symlink() not available' );
		}

		$target = $this->temp_dir . '/sscribe-in-scope-' . uniqid();
		$link   = $this->temp_dir . '/sscribe-link-' . uniqid();
		mkdir( $target, 0755, true );

		if ( ! @symlink( $target, $link ) ) {
			rmdir( $target );
			$this->markTestSkipped( 'Could not create test symlink.' );
		}

		try {
			\SScribe_Security::protect_directory( $link );
			$this->assertDirectoryExists( $link );
			$this->assertFileExists( $link . '/.htaccess' );
		} finally {
			if ( is_link( $link ) ) {
				unlink( $link );
			}
			if ( is_dir( $target ) ) {
				rmdir( $target );
			}
		}
	}

	/**
	 * Candidate 5 (negative): a symlink whose target escapes the
	 * allowed root must still be rejected.
	 */
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	#[\PHPUnit\Framework\Attributes\PreserveGlobalState( false )]
	public function test_protect_directory_rejects_symlink_whose_target_is_out_of_scope(): void {
		if ( ! function_exists( 'symlink' ) ) {
			$this->markTestSkipped( 'symlink() not available' );
		}

		$outside = sys_get_temp_dir() . '/sscribe-outside-' . uniqid();
		$link    = $this->temp_dir . '/sscribe-evil-link-' . uniqid();
		mkdir( $outside, 0755, true );

		if ( ! @symlink( $outside, $link ) ) {
			rmdir( $outside );
			$this->markTestSkipped( 'Could not create test symlink.' );
		}

		try {
			$this->expectException( \InvalidArgumentException::class );
			\SScribe_Security::protect_directory( $link );
		} finally {
			if ( is_link( $link ) ) {
				unlink( $link );
			}
			if ( is_dir( $outside ) ) {
				rmdir( $outside );
			}
		}
	}
}