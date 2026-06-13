<?php
/**
 * SScribe Batch Processor count_temp_dir_files() unit test.
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

class SScribe_Batch_Processor_Count_Temp_Dir_Files_Test extends TestCase {

	/**
	 * @var string Temporary directory used by each test case.
	 */
	private string $temp_dir = '';

	protected function setUp(): void {
		parent::setUp();
		$this->temp_dir = sys_get_temp_dir() . '/sscribe-batch-count-test-' . uniqid( '', true );
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
			if ( is_dir( $path ) ) {
				$this->rmdir_recursive( $path );
			} else {
				@unlink( $path );
			}
		}
		@rmdir( $dir );
	}

	/**
	 * Invoke the private count_temp_dir_files() method via a scoped callable.
	 */
	private function call_count_temp_dir_files( string $temp_dir ): int {
		$method = \Closure::bind(
			function ( string $temp_dir ) {
				$processor = new \SScribe_Batch_Processor();
				return $processor->count_temp_dir_files( $temp_dir ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.PrivateMethodFound
			},
			null,
			\SScribe_Batch_Processor::class
		);
		return (int) $method( $temp_dir );
	}

	public function test_returns_zero_for_missing_directory(): void {
		$missing = $this->temp_dir . '/does-not-exist';
		$this->assertSame( 0, $this->call_count_temp_dir_files( $missing ) );
	}

	public function test_returns_zero_for_empty_string(): void {
		$this->assertSame( 0, $this->call_count_temp_dir_files( '' ) );
	}

	public function test_returns_zero_for_empty_directory(): void {
		$this->assertSame( 0, $this->call_count_temp_dir_files( $this->temp_dir ) );
	}

	/**
	 * The critical regression test: under the per-language layout, files
	 * live one level deeper (e.g. <temp>/AR/P001-foo.docx). A flat glob
	 * would only see the language subdirs and under-count dramatically.
	 */
	public function test_counts_files_in_per_language_subdirectories(): void {
		// Build:
		//   <temp>/AR/P001-foo.docx
		//   <temp>/AR/P002-foo.pdf
		//   <temp>/EN/P003-bar.docx
		//   <temp>/EN/P004-bar.md
		//   <temp>/ALL/P005-baz.html
		// Expected count: 5
		$ar_dir = $this->temp_dir . '/AR';
		$en_dir = $this->temp_dir . '/EN';
		$all_dir = $this->temp_dir . '/ALL';
		mkdir( $ar_dir, 0700, true );
		mkdir( $en_dir, 0700, true );
		mkdir( $all_dir, 0700, true );

		file_put_contents( $ar_dir . '/P001-foo.docx', 'dummy' );
		file_put_contents( $ar_dir . '/P002-foo.pdf', 'dummy' );
		file_put_contents( $en_dir . '/P003-bar.docx', 'dummy' );
		file_put_contents( $en_dir . '/P004-bar.md', 'dummy' );
		file_put_contents( $all_dir . '/P005-baz.html', 'dummy' );

		$this->assertSame( 5, $this->call_count_temp_dir_files( $this->temp_dir ) );
	}

	/**
	 * Nested language subdirs (e.g. <temp>/AR-XYZ/...) must be recursed into
	 * as well — the implementation should not assume a single segment.
	 */
	public function test_counts_files_in_nested_subdirectories(): void {
		mkdir( $this->temp_dir . '/AR/legacy', 0700, true );
		mkdir( $this->temp_dir . '/EN', 0700, true );
		file_put_contents( $this->temp_dir . '/AR/legacy/P001-foo.docx', 'dummy' );
		file_put_contents( $this->temp_dir . '/EN/P002-bar.pdf', 'dummy' );

		$this->assertSame( 2, $this->call_count_temp_dir_files( $this->temp_dir ) );
	}

	/**
	 * Empty language subdirs are not present in real exports but must not
	 * be miscounted as files.
	 */
	public function test_does_not_count_subdirectories_as_files(): void {
		mkdir( $this->temp_dir . '/AR', 0700, true );
		mkdir( $this->temp_dir . '/EN', 0700, true );

		$this->assertSame( 0, $this->call_count_temp_dir_files( $this->temp_dir ) );
	}
}
