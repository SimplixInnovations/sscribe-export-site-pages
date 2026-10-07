<?php
/**
 * ZIP handler manifest integration tests.
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SScribe_Export_Manifest;
use SScribe_Zip_Handler;
use ZipArchive;

class SScribe_Zip_Handler_Manifest_Test extends TestCase {
	private ?SScribe_Zip_Handler $handler;

	protected function setUp(): void {
		parent::setUp();
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive extension not available' );
		}
		$this->handler = new SScribe_Zip_Handler();
	}

	protected function tearDown(): void {
		$this->handler = null;
		unset( $GLOBALS['sscribe_test_current_user'] );
		parent::tearDown();
	}

	private function read_entry( string $zip_path, string $entry ): string {
		$zip = new ZipArchive();
		$this->assertTrue( $zip->open( $zip_path ) );
		$content = $zip->getFromName( $entry );
		$zip->close();
		$this->assertNotFalse( $content, "ZIP must contain {$entry}" );
		return (string) $content;
	}

	public function test_create_zip_embeds_manifest_and_index_with_checksums(): void {
		$source_dir = $this->handler->create_temp_dir();
		wp_mkdir_p( $source_dir . '/EN' );
		wp_mkdir_p( $source_dir . '/AR' );
		file_put_contents( $source_dir . '/EN/P001-Hello.docx', 'docx bytes' );
		file_put_contents( $source_dir . '/EN/P001-Hello.pdf', 'pdf bytes!' );
		file_put_contents( $source_dir . '/AR/P002-صفحة.pdf', 'arabic pdf' );

		SScribe_Export_Manifest::record(
			$source_dir,
			array( 'file' => 'EN/P001-Hello.docx', 'format' => 'docx', 'lang' => 'en', 'post_id' => 12, 'post_type' => 'page', 'title' => 'Hello', 'url' => 'https://example.test/hello/', 'modified' => '2026-10-01T00:00:00Z' )
		);
		SScribe_Export_Manifest::record(
			$source_dir,
			array( 'file' => 'EN/P001-Hello.pdf', 'format' => 'pdf', 'lang' => 'en', 'post_id' => 12, 'post_type' => 'page', 'title' => 'Hello', 'url' => 'https://example.test/hello/', 'modified' => '2026-10-01T00:00:00Z' )
		);
		SScribe_Export_Manifest::record(
			$source_dir,
			array( 'file' => 'AR/P002-صفحة.pdf', 'format' => 'pdf', 'lang' => 'ar', 'post_id' => 15, 'post_type' => 'page', 'title' => 'صفحة', 'url' => 'https://example.test/ar/page/', 'modified' => '2026-10-02T00:00:00Z' )
		);

		$zip_path = $this->handler->create_zip( $source_dir, 'manifest-zip', array( 'docx', 'pdf' ), true, array(), 'sess-manifest' );
		$this->assertIsString( $zip_path );
		$this->assertFileExists( $zip_path );

		$json     = $this->read_entry( $zip_path, SScribe_Export_Manifest::JSON_ENTRY );
		$manifest = json_decode( $json, true );
		$this->assertIsArray( $manifest );
		$this->assertSame( SScribe_Export_Manifest::SCHEMA, $manifest['schema'] );
		$this->assertSame( 'sess-manifest', $manifest['export']['session_id'] );
		$this->assertSame( array( 'docx', 'pdf' ), $manifest['export']['formats'] );
		$this->assertSame( 3, $manifest['totals']['files'] );
		$this->assertSame( 2, $manifest['totals']['posts'] );

		$by_path = array_column( $manifest['files'], null, 'path' );
		$this->assertArrayHasKey( 'DOCX/EN/P001-Hello.docx', $by_path );
		$this->assertArrayHasKey( 'PDF/AR/P002-صفحة.pdf', $by_path );
		$this->assertSame( hash( 'sha256', 'docx bytes' ), $by_path['DOCX/EN/P001-Hello.docx']['sha256'] );
		$this->assertSame( hash( 'sha256', 'arabic pdf' ), $by_path['PDF/AR/P002-صفحة.pdf']['sha256'] );
		$this->assertSame( 15, $by_path['PDF/AR/P002-صفحة.pdf']['post']['id'] );
		$this->assertSame( 'ar', $by_path['PDF/AR/P002-صفحة.pdf']['lang'] );

		$index = $this->read_entry( $zip_path, SScribe_Export_Manifest::INDEX_ENTRY );
		$this->assertStringContainsString( '[PDF](PDF/AR/P002-صفحة.pdf)', $index );
		$this->assertStringContainsString( 'https://example.test/hello/', $index );

		$zip = new ZipArchive();
		$zip->open( $zip_path );
		$names = array();
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$names[] = $zip->getNameIndex( $i );
		}
		$zip->close();
		$this->assertNotContains( SScribe_Export_Manifest::SIDECAR_NAME, $names, 'the JSONL sidecar must never ship inside the archive' );
		$this->assertContains( SScribe_Export_Manifest::LLMS_ENTRY, $names );
		$llms = $this->read_entry( $zip_path, SScribe_Export_Manifest::LLMS_ENTRY );
		$this->assertStringContainsString( '- [Hello](https://example.test/hello/)', $llms );
		$this->assertNotContains( SScribe_Export_Manifest::LLMS_FULL, $names, 'no Markdown documents, so no llms-full.txt' );

		wp_delete_file( $zip_path );
	}

	public function test_create_zip_still_writes_manifest_when_no_sidecar_exists(): void {
		$source_dir = $this->handler->create_temp_dir();
		wp_mkdir_p( $source_dir . '/EN' );
		file_put_contents( $source_dir . '/EN/P001-Test.docx', 'dummy content' );

		$zip_path = $this->handler->create_zip( $source_dir, 'no-sidecar', array( 'docx' ) );
		$this->assertIsString( $zip_path );

		$manifest = json_decode( $this->read_entry( $zip_path, SScribe_Export_Manifest::JSON_ENTRY ), true );
		$this->assertSame( 1, $manifest['totals']['files'] );
		$this->assertSame( 'docx', $manifest['files'][0]['format'] );
		$this->assertNull( $manifest['files'][0]['post'] );
		$this->assertSame( hash( 'sha256', 'dummy content' ), $manifest['files'][0]['sha256'] );

		wp_delete_file( $zip_path );
	}
}
