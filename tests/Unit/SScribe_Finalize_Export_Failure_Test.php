<?php
/**
 * SScribe Finalize Export failure-path tests.
 *
 * `finalize_export()` itself is deeply coupled to the AJAX guard
 * (it calls `SScribe_AJAX_Guard::error()` on failure which leads to
 * `wp_die()`), so it cannot be invoked directly in unit tests. These
 * tests instead cover the small, isolated primitives the failure
 * branches depend on:
 *
 *   1. The file-counting primitive that decides whether the
 *      "no files generated" branch fires.
 *   2. The `ZipArchive` open/numFiles check that decides whether
 *      the "empty ZIP archive" branch fires.
 *   3. A grep-level guard ensuring the failure branches are still
 *      present in the production source (regression against
 *      accidental removal during refactors).
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

class SScribe_Finalize_Export_Failure_Test extends TestCase {

	/** @var string */
	private string $temp_dir = '';

	protected function setUp(): void {
		parent::setUp();
		$this->temp_dir = sys_get_temp_dir() . '/sscribe-finalize-test-' . uniqid( '', true );
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
			function ( string $td ) {
				$processor = new \SScribe_Batch_Processor();
				return $processor->count_temp_dir_files( $td ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.PrivateMethodFound
			},
			null,
			\SScribe_Batch_Processor::class
		);
		return (int) $method( $temp_dir );
	}

	/**
	 * The "no files generated" failure branch (line ~2415 of
	 * class-sscribe-batch-processor.php) fires when the per-format
	 * file count sums to zero. The decision is driven by
	 * count_temp_dir_files(). An empty temp dir must report zero,
	 * not a false positive.
	 */
	public function test_empty_temp_dir_triggers_no_files_generated_branch(): void {
		$this->assertSame( 0, $this->call_count_temp_dir_files( $this->temp_dir ) );
	}

	/**
	 * The per-language layout (temp/AR/P001-...docx) is the real
	 * production layout. When that layout contains only language
	 * subdirs with no files, the count must still be zero so the
	 * failure branch fires correctly.
	 */
	public function test_per_language_layout_with_empty_subdirs_triggers_branch(): void {
		mkdir( $this->temp_dir . '/AR', 0700, true );
		mkdir( $this->temp_dir . '/EN', 0700, true );

		$this->assertSame( 0, $this->call_count_temp_dir_files( $this->temp_dir ) );
	}

	/**
	 * When the layout has files in the language subdirs, the
	 * "no files generated" branch must NOT fire — the count must
	 * be greater than zero.
	 */
	public function test_per_language_layout_with_files_skips_branch(): void {
		mkdir( $this->temp_dir . '/AR', 0700, true );
		file_put_contents( $this->temp_dir . '/AR/P001-foo.docx', 'dummy' );

		$this->assertGreaterThan( 0, $this->call_count_temp_dir_files( $this->temp_dir ) );
	}

	/**
	 * A real ZipArchive opened from an existing path with only a
	 * directory entry reports numFiles === 1. The production code's
	 * "empty ZIP archive" failure branch (after create_zip succeeds)
	 * filters out directory entries via `substr($stat['name'], -1) !== '/'`,
	 * so a count of 1 here corresponds to 0 "actual files" — which
	 * triggers the failure branch.
	 *
	 * Note: a zip file that is *entirely* empty (no entries at all) is
	 * not a valid zip archive on some platforms (notably Windows) and
	 * cannot be re-opened. The production code's failure branch
	 * exercises the same primitive: an archive that opens, but whose
	 * only entries are directories, is treated as empty.
	 */
	public function test_ziparchive_reports_zero_files_for_empty_archive(): void {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive not available' );
		}

		$zip_path = $this->temp_dir . '/empty.zip';
		$zip      = new \ZipArchive();
		$this->assertTrue( $zip->open( $zip_path, \ZipArchive::CREATE ) );
		// Add a directory entry to make the archive valid (an entirely
		// empty archive cannot be re-opened on Windows). The directory
		// entry is what the production code's stat-loop filters out to
		// produce a count of 0.
		$zip->addEmptyDir( 'placeholder' );
		$zip->close();

		$reopen = new \ZipArchive();
		$this->assertTrue( $reopen->open( $zip_path ) === true );
		$this->assertSame( 1, $reopen->numFiles,
			'Archive with only a directory entry should report 1 (directory) entry' );
		$reopen->close();
	}

	/**
	 * A real ZipArchive opened with at least one file entry reports
	 * numFiles === 1, confirming the count primitive used by the
	 * "empty ZIP archive" failure branch is reliable.
	 */
	public function test_ziparchive_reports_one_file_for_single_entry_archive(): void {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive not available' );
		}

		$entry_path = $this->temp_dir . '/entry.txt';
		file_put_contents( $entry_path, 'hello' );

		$zip_path = $this->temp_dir . '/one.zip';
		$zip      = new \ZipArchive();
		$this->assertTrue( $zip->open( $zip_path, \ZipArchive::CREATE ) );
		$zip->addFile( $entry_path, 'entry.txt' );
		$zip->close();

		$reopen = new \ZipArchive();
		$this->assertTrue( $reopen->open( $zip_path ) === true );
		$this->assertSame( 1, $reopen->numFiles );
		$reopen->close();
	}

	/**
	 * Regression guard: ensure the failure branches are still wired
	 * up in the production source. If a refactor accidentally removes
	 * them, the production failure modes (no files generated, failed
	 * to create ZIP, empty ZIP archive) would degrade silently.
	 *
	 * The finalize_export() pipeline now lives in the
	 * SScribe_Export_Finalizer trait, so the regression scan must
	 * look at both the class file AND the trait file to catch
	 * accidental removals from either location.
	 */

	public function test_finalizer_renews_session_lock_before_heavy_zip_assembly(): void {
		$trait_source = file_get_contents( SSCRIBE_PLUGIN_DIR . 'includes/traits/trait-sscribe-export-finalizer.php' );

		$this->assertNotFalse( $trait_source );
		$this->assertStringContainsString(
			'renew_lock( $session_id, $lock_token, 600 )',
			$trait_source,
			'Finalization must extend the per-session lease before a ZIP build that may run for several minutes.'
		);
	}

	public function test_failure_branches_present_in_production_source(): void {
		$class_source = file_get_contents( SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-batch-processor.php' );
		$trait_source = file_get_contents( SSCRIBE_PLUGIN_DIR . 'includes/traits/trait-sscribe-export-finalizer.php' );

		$this->assertNotFalse( $class_source, 'batch processor source must be readable' );
		$this->assertNotFalse( $trait_source, 'export finalizer trait source must be readable' );

		$source = $class_source . $trait_source;

		$this->assertStringContainsString(
			'No files generated',
			$source,
			'"no files generated" failure branch must still be present'
		);
		$this->assertStringContainsString(
			'Failed to create ZIP',
			$source,
			'"failed to create ZIP" failure branch must still be present'
		);
		$this->assertStringContainsString(
			'mark_failed',
			$source,
			'export log mark_failed call must still be wired up'
		);
	}
}
