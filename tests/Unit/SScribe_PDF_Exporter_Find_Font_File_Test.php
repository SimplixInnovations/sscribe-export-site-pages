<?php
/**
 * SScribe PDF Exporter — find_font_file() regression test
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

class SScribe_PDF_Exporter_Find_Font_File_Test extends TestCase {

	/**
	 * @var string Temporary font directory created for this test.
	 */
	private string $font_dir = '';

	/**
	 * @var string[] Files to clean up in tearDown.
	 */
	private array $created_files = array();

	protected function setUp(): void {
		parent::setUp();
		$this->font_dir = sys_get_temp_dir() . '/sscribe-test-fonts-' . uniqid( '', true );
		mkdir( $this->font_dir, 0700, true );
	}

	protected function tearDown(): void {
		foreach ( $this->created_files as $file ) {
			if ( file_exists( $file ) ) {
				@unlink( $file );
			}
		}
		if ( is_dir( $this->font_dir ) ) {
			$this->rmdir_recursive( $this->font_dir );
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
			if ( is_dir( $path ) ) {
				$this->rmdir_recursive( $path );
			} else {
				@unlink( $path );
			}
		}
		@rmdir( $dir );
	}

	/**
	 * Invoke the private find_font_file() method via a scoped callable.
	 */
	private function call_find_font_file( string $dir, string $pattern ): ?string {
		$method = \Closure::bind(
			function ( string $dir, string $pattern ) {
				$exporter = new \SScribe_PDF_Exporter();
				return $exporter->find_font_file( $dir, $pattern ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.PrivateMethodFound
			},
			null,
			\SScribe_PDF_Exporter::class
		);
		return $method( $dir, $pattern );
	}

	private function touch_font_file( string $name ): string {
		$path = $this->font_dir . '/' . $name;
		// 4 bytes is enough to be a real file; we never read it.
		file_put_contents( $path, "\x00\x01\x00\x00" );
		$this->created_files[] = $path;
		return $path;
	}

	/**
	 * Regression: find_font_file() previously called preg_quote() on the
	 * pattern, which turned the regex "manrope[-_]?regular" into the
	 * literal string "manrope\[\-\_\]\?regular" and made it impossible to
	 * match the actual Manrope-Regular.ttf filename. The function always
	 * returned null, and the caller's ?? 'Manrope-Regular.ttf' fallback
	 * masked the bug. This test pins the regex behavior so a future
	 * refactor that re-introduces preg_quote will fail loudly.
	 */
	public function test_find_font_file_matches_manrope_hyphenated_filename(): void {
		$this->touch_font_file( 'Manrope-Regular.ttf' );
		$this->touch_font_file( 'Manrope-Bold.ttf' );

		$result = $this->call_find_font_file( $this->font_dir, 'manrope[-_]?regular' );

		$this->assertSame( 'Manrope-Regular.ttf', $result );
	}

	/**
	 * Regression: the pattern is case-insensitive and matches both the
	 * hyphen and underscore variants the audit identifies as plausible.
	 */
	public function test_find_font_file_matches_underscore_variant(): void {
		$this->touch_font_file( 'Manrope_Bold.ttf' );
		$this->touch_font_file( 'Manrope_Light.ttf' );

		$result = $this->call_find_font_file( $this->font_dir, 'manrope[-_]?bold' );

		$this->assertSame( 'Manrope_Bold.ttf', $result );
	}

	/**
	 * The function must return null (not throw) when no file matches, so
	 * the caller's ?? 'fallback.ttf' can take over.
	 */
	public function test_find_font_file_returns_null_when_no_match(): void {
		$this->touch_font_file( 'Manrope-Regular.ttf' );

		$result = $this->call_find_font_file( $this->font_dir, 'manrope[-_]?nonexistent' );

		$this->assertNull( $result );
	}

	/**
	 * The function must return null when the directory is empty, not
	 * raise a warning. An empty directory is the bootstrap state before
	 * the first font is dropped in.
	 */
	public function test_find_font_file_returns_null_for_empty_directory(): void {
		$result = $this->call_find_font_file( $this->font_dir, 'manrope[-_]?regular' );

		$this->assertNull( $result );
	}

	/**
	 * Non-.ttf files in the directory must be ignored, even if the base
	 * name would otherwise match.
	 */
	public function test_find_font_file_ignores_non_ttf_files(): void {
		$this->touch_font_file( 'Manrope-Regular.otf' );
		$this->touch_font_file( 'manrope-regular-regular.txt' );

		$result = $this->call_find_font_file( $this->font_dir, 'manrope[-_]?regular' );

		$this->assertNull( $result );
	}
}
