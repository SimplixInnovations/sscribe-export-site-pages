<?php
/**
 * SScribe ZIP Handler Unit Test
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SScribe_Zip_Handler;

class SScribe_Zip_Handler_Test extends TestCase {
	private ?SScribe_Zip_Handler $handler;
	private string $test_export_dir;

	protected function setUp(): void {
		parent::setUp();
		$this->handler        = new SScribe_Zip_Handler();
		$this->test_export_dir = sys_get_temp_dir() . '/sscribe-test-zip-' . uniqid();
	}

	protected function tearDown(): void {
		$this->handler = null;
		if ( is_dir( $this->test_export_dir ) ) {
			array_map( 'unlink', glob( $this->test_export_dir . '/**/*' ) ?: array() );
			@rmdir( $this->test_export_dir );
		}
		parent::tearDown();
	}

	public function test_handler_can_be_instantiated(): void {
		$this->assertInstanceOf( SScribe_Zip_Handler::class, $this->handler );
	}

	public function test_get_export_dir_returns_path(): void {
		$result = $this->handler->get_export_dir();
		$this->assertIsString( $result );
		$this->assertStringEndsWith( 'sscribe-exports', $result );
	}

	public function test_get_export_dir_creates_directory(): void {
		$result   = $this->handler->get_export_dir();
		$this->assertDirectoryExists( $result );

		if ( file_exists( $result . '/.htaccess' ) ) {
			$this->assertFileExists( $result . '/.htaccess' );
		}
		if ( file_exists( $result . '/index.html' ) ) {
			$this->assertFileExists( $result . '/index.html' );
		}
	}

	public function test_create_temp_dir_creates_directory(): void {
		$temp_dir = $this->handler->create_temp_dir();
		$this->assertIsString( $temp_dir );
		$this->assertDirectoryExists( $temp_dir );
		$this->assertStringContainsString( 'temp-', $temp_dir );
	}

	public function test_create_temp_dir_creates_unique_names(): void {
		$dir1 = $this->handler->create_temp_dir();
		$dir2 = $this->handler->create_temp_dir();
		$this->assertNotSame( $dir1, $dir2 );
	}

	public function test_delete_directory_removes_all(): void {

		$base_dir = $this->handler->get_export_dir();
		$test_dir = $base_dir . '/test-subdir-' . uniqid();
		wp_mkdir_p( $test_dir );
		file_put_contents( $test_dir . '/test.txt', 'content' );

		$this->assertDirectoryExists( $test_dir );

		$this->handler->delete_directory( $test_dir );

		$this->assertDirectoryDoesNotExist( $test_dir );
	}

	public function test_delete_directory_handles_missing_dir(): void {
		$method = new \ReflectionMethod( SScribe_Zip_Handler::class, 'delete_directory' );
		$result = $method->invoke( $this->handler, '/non-existent-dir' );

		$this->assertFalse( $result );
	}

	public function test_create_zip_returns_false_with_no_files(): void {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive extension not available' );
		}

		$source_dir = $this->handler->create_temp_dir();
		$result      = $this->handler->create_zip( $source_dir, 'test-zip', array( 'docx' ) );

		$this->assertFalse( $result );
	}

	public function test_create_zip_succeeds_with_files(): void {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive extension not available' );
		}

		$source_dir = $this->handler->create_temp_dir();
		// Files now live one level deeper, under the language subdir
		// (the batch processor writes to `$temp_dir/$LANG/page.ext`).
		wp_mkdir_p( $source_dir . '/EN' );
		file_put_contents( $source_dir . '/EN/P001-Test.docx', 'dummy content' );

		$result = $this->handler->create_zip( $source_dir, 'test-zip', array( 'docx' ) );

		$this->assertIsString( $result );
		$this->assertFileExists( $result );
		$this->assertStringEndsWith( '.zip', $result );

		if ( file_exists( $result ) ) {
			unlink( $result );
		}
	}

	public function test_create_zip_cleans_up_source(): void {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive extension not available' );
		}

		$source_dir = $this->handler->create_temp_dir();
		wp_mkdir_p( $source_dir . '/EN' );
		file_put_contents( $source_dir . '/EN/P001-Test.docx', 'dummy content' );

		$this->assertDirectoryExists( $source_dir );

		$result = $this->handler->create_zip( $source_dir, 'test-zip', array( 'docx' ) );

		$this->assertDirectoryDoesNotExist( $source_dir );

		if ( is_string( $result ) && file_exists( $result ) ) {
			unlink( $result );
		}
	}

	public function test_create_zip_with_multiple_formats(): void {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive extension not available' );
		}

		$source_dir = $this->handler->create_temp_dir();
		wp_mkdir_p( $source_dir . '/EN' );
		file_put_contents( $source_dir . '/EN/P001-Test.docx', 'docx content' );
		file_put_contents( $source_dir . '/EN/P001-Test.pdf', 'pdf content' );

		$zip_path = $this->handler->create_zip( $source_dir, 'test-multi', array( 'docx', 'pdf' ) );

		$this->assertIsString( $zip_path );
		$this->assertFileExists( $zip_path );

		if ( file_exists( $zip_path ) ) {
			unlink( $zip_path );
		}
	}

	/**
	 * Regression test for the per-language folder restructure: a
	 * multi-language export must place each page into `FORMAT/LANG/page.ext`
	 * inside the ZIP, with no filename-suffix gymnastics. The language
	 * is read from the parent directory name on disk, not from the
	 * filename.
	 */
	public function test_create_zip_groups_files_by_language_subdir(): void {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive extension not available' );
		}

		$source_dir = $this->handler->create_temp_dir();
		wp_mkdir_p( $source_dir . '/AR' );
		wp_mkdir_p( $source_dir . '/EN' );
		file_put_contents( $source_dir . '/AR/P001-Arabic.docx', 'arabic content' );
		file_put_contents( $source_dir . '/AR/P002-Arabic.docx', 'arabic content 2' );
		file_put_contents( $source_dir . '/EN/P001-English.docx', 'english content' );

		$zip_path = $this->handler->create_zip( $source_dir, 'test-lang-groups', array( 'docx' ), true );
		$this->assertIsString( $zip_path );
		$this->assertFileExists( $zip_path );

		// Open the ZIP and assert the language subfolders survived.
		$zip = new \ZipArchive();
		$zip->open( $zip_path );
		$entries = array();
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$entries[] = $zip->getNameIndex( $i );
		}
		$zip->close();

		$this->assertContains( 'DOCX/AR/P001-Arabic.docx', $entries, 'AR page should be in DOCX/AR/' );
		$this->assertContains( 'DOCX/AR/P002-Arabic.docx', $entries, 'AR page 2 should be in DOCX/AR/' );
		$this->assertContains( 'DOCX/EN/P001-English.docx', $entries, 'EN page should be in DOCX/EN/' );

		// Filename must NOT carry a language suffix anymore.
		foreach ( $entries as $entry ) {
			$this->assertDoesNotMatchRegularExpression(
				'#-AR\.docx$#',
				$entry,
				'Filenames should no longer carry the -AR suffix: ' . $entry
			);
			$this->assertDoesNotMatchRegularExpression(
				'#-EN\.docx$#',
				$entry,
				'Filenames should no longer carry the -EN suffix: ' . $entry
			);
		}

		if ( file_exists( $zip_path ) ) {
			unlink( $zip_path );
		}
	}

	/**
	 * Regression test for Audit N-3: verify_zip_integrity() must NOT reject
	 * a valid multi-format ZIP where the first entry is a directory entry.
	 *
	 * The old check rejected any ZIP whose first statIndex() entry had size 0
	 * — but directory entries always have size 0 by design, so any multi-format
	 * export (DOCX/, PDF/, etc.) starting with a directory entry was falsely
	 * flagged as "truncated or corrupted".
	 */
	public function test_verify_zip_integrity_accepts_zip_starting_with_directory_entry(): void {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive extension not available' );
		}

		$export_dir = $this->handler->get_export_dir();
		$zip_path   = $export_dir . '/sscribe-dir-entry-test-' . uniqid() . '.zip';

		// Build a ZIP whose first entry is an explicit directory entry,
		// followed by a real file. This is the exact shape multi-format
		// exports produce when DOCX/ or PDF/ directory entries come first.
		$zip = new \ZipArchive();
		$this->assertTrue( $zip->open( $zip_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) );
		$zip->addEmptyDir( 'DOCX' );
		$zip->addFromString( 'DOCX/P001-Test.docx', 'docx content' );
		$zip->close();

		$this->assertFileExists( $zip_path );

		$method = new \ReflectionMethod( SScribe_Zip_Handler::class, 'verify_zip_integrity' );
		$result = $method->invoke( $this->handler, $zip_path );

		$this->assertTrue(
			$result,
			'verify_zip_integrity() must accept a valid ZIP that begins with a directory entry (size 0).'
		);

		if ( file_exists( $zip_path ) ) {
			unlink( $zip_path );
		}
	}

	public function test_cleanup_expired_keeps_index_for_existing_zip_files(): void {
		$export_dir = $this->handler->get_export_dir();
		$filename   = 'sscribe-cleanup-test-' . uniqid() . '.zip';
		$file_path  = $export_dir . '/' . $filename;

		file_put_contents( $file_path, 'zip-fixture' );

		$exports              = get_option( 'sscribe_export_index', array() );
		$exports[ $filename ] = array(
			'created_at' => time(),
			'user_id'    => 1,
			'formats'    => array( 'docx' ),
			'lang_code'  => '',
			'lang_name'  => '',
			'flag_url'   => '',
		);
		update_option( 'sscribe_export_index', $exports, false );

		delete_transient( 'sscribe_cron_exports_lock' );
		$this->handler->cleanup_expired();

		$updated_exports = get_option( 'sscribe_export_index', array() );
		$this->assertArrayHasKey( $filename, $updated_exports );

		if ( file_exists( $file_path ) ) {
			unlink( $file_path );
		}
		unset( $updated_exports[ $filename ] );
		update_option( 'sscribe_export_index', $updated_exports, false );
	}
}
