<?php
/**
 * Export manifest unit tests.
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SScribe_Export_Manifest;

class SScribe_Export_Manifest_Test extends TestCase {
	private string $temp_dir;

	protected function setUp(): void {
		parent::setUp();
		$this->temp_dir = sys_get_temp_dir() . '/sscribe-manifest-' . uniqid();
		mkdir( $this->temp_dir, 0700, true );
		mkdir( $this->temp_dir . '/EN', 0700, true );
	}

	protected function tearDown(): void {
		foreach ( glob( $this->temp_dir . '/**/*' ) ?: array() as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file );
			}
		}
		foreach ( glob( $this->temp_dir . '/.*' ) ?: array() as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file );
			}
		}
		foreach ( glob( $this->temp_dir . '/*' ) ?: array() as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file );
			} elseif ( is_dir( $file ) ) {
				@rmdir( $file );
			}
		}
		@rmdir( $this->temp_dir );
		parent::tearDown();
	}

	private function sample_record( array $overrides = array() ): array {
		return array_merge(
			array(
				'file'      => 'EN/P001-Hello.docx',
				'format'    => 'docx',
				'lang'      => 'en',
				'post_id'   => 12,
				'post_type' => 'page',
				'title'     => 'Hello & “World”',
				'url'       => 'https://example.test/hello/',
				'modified'  => '2026-10-07T12:00:00+00:00',
			),
			$overrides
		);
	}

	public function test_record_appends_jsonl_line_to_sidecar(): void {
		$this->assertTrue( SScribe_Export_Manifest::record( $this->temp_dir, $this->sample_record() ) );

		$sidecar = $this->temp_dir . '/' . SScribe_Export_Manifest::SIDECAR_NAME;
		$this->assertFileExists( $sidecar );
		$lines = array_values( array_filter( explode( "\n", (string) file_get_contents( $sidecar ) ) ) );
		$this->assertCount( 1, $lines );
		$decoded = json_decode( $lines[0], true );
		$this->assertSame( 'EN/P001-Hello.docx', $decoded['file'] );
		$this->assertSame( 12, $decoded['post_id'] );
	}

	public function test_sidecar_is_a_dotfile_so_format_globs_ignore_it(): void {
		$this->assertStringStartsWith( '.', SScribe_Export_Manifest::SIDECAR_NAME );
		$this->assertStringEndsWith( '.jsonl', SScribe_Export_Manifest::SIDECAR_NAME );
	}

	public function test_load_returns_records_keyed_by_file_with_later_lines_winning(): void {
		SScribe_Export_Manifest::record( $this->temp_dir, $this->sample_record( array( 'title' => 'First try' ) ) );
		SScribe_Export_Manifest::record( $this->temp_dir, $this->sample_record( array( 'title' => 'Retry' ) ) );
		SScribe_Export_Manifest::record( $this->temp_dir, $this->sample_record( array( 'file' => 'EN/P001-Hello.pdf', 'format' => 'pdf' ) ) );

		$records = SScribe_Export_Manifest::load( $this->temp_dir );

		$this->assertCount( 2, $records );
		$this->assertSame( 'Retry', $records['EN/P001-Hello.docx']['title'] );
		$this->assertSame( 'pdf', $records['EN/P001-Hello.pdf']['format'] );
	}

	public function test_load_skips_corrupt_lines_and_rejects_unsafe_paths(): void {
		$sidecar = $this->temp_dir . '/' . SScribe_Export_Manifest::SIDECAR_NAME;
		file_put_contents(
			$sidecar,
			"not json\n" .
			wp_json_encode( $this->sample_record( array( 'file' => '../../etc/passwd' ) ) ) . "\n" .
			wp_json_encode( $this->sample_record() ) . "\n"
		);

		$records = SScribe_Export_Manifest::load( $this->temp_dir );

		$this->assertCount( 1, $records );
		$this->assertArrayHasKey( 'EN/P001-Hello.docx', $records );
	}

	public function test_load_returns_empty_array_when_no_sidecar(): void {
		$this->assertSame( array(), SScribe_Export_Manifest::load( $this->temp_dir ) );
	}

	public function test_record_rejects_record_without_file_or_format(): void {
		$this->assertFalse( SScribe_Export_Manifest::record( $this->temp_dir, array( 'format' => 'docx' ) ) );
		$this->assertFalse( SScribe_Export_Manifest::record( $this->temp_dir, array( 'file' => 'EN/x.docx' ) ) );
		$this->assertFileDoesNotExist( $this->temp_dir . '/' . SScribe_Export_Manifest::SIDECAR_NAME );
	}

	public function test_build_produces_schema_with_checksums_and_totals(): void {
		file_put_contents( $this->temp_dir . '/EN/P001-Hello.docx', 'docx bytes' );
		file_put_contents( $this->temp_dir . '/EN/P001-Hello.pdf', 'pdf bytes!' );
		SScribe_Export_Manifest::record( $this->temp_dir, $this->sample_record() );
		SScribe_Export_Manifest::record( $this->temp_dir, $this->sample_record( array( 'file' => 'EN/P001-Hello.pdf', 'format' => 'pdf' ) ) );

		$zip_entries = array(
			array( 'source_path' => $this->temp_dir . '/EN/P001-Hello.docx', 'zip_path' => 'DOCX/P001-Hello.docx', 'lang_code' => 'EN', 'size' => 10 ),
			array( 'source_path' => $this->temp_dir . '/EN/P001-Hello.pdf', 'zip_path' => 'PDF/P001-Hello.pdf', 'lang_code' => 'EN', 'size' => 10 ),
			array( 'source_path' => $this->temp_dir . '/EN/orphan.pdf', 'zip_path' => 'PDF/orphan.pdf', 'lang_code' => 'EN', 'size' => 0 ),
		);

		$manifest = SScribe_Export_Manifest::build(
			$this->temp_dir,
			$zip_entries,
			array(
				'session_id' => 'sess-1',
				'formats'    => array( 'docx', 'pdf' ),
				'languages'  => array( 'en' ),
			)
		);

		$this->assertSame( SScribe_Export_Manifest::SCHEMA, $manifest['schema'] );
		$this->assertSame( SSCRIBE_VERSION, $manifest['generator']['version'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $manifest['export']['created_utc'] );
		$this->assertSame( 'sess-1', $manifest['export']['session_id'] );
		$this->assertSame( array( 'docx', 'pdf' ), $manifest['export']['formats'] );

		$this->assertCount( 3, $manifest['files'] );
		$docx = $manifest['files'][0];
		$this->assertSame( 'DOCX/P001-Hello.docx', $docx['path'] );
		$this->assertSame( 'docx', $docx['format'] );
		$this->assertSame( hash( 'sha256', 'docx bytes' ), $docx['sha256'] );
		$this->assertSame( 10, $docx['bytes'] );
		$this->assertSame( 12, $docx['post']['id'] );
		$this->assertSame( 'https://example.test/hello/', $docx['post']['url'] );
		$this->assertSame( 'Hello & “World”', $docx['post']['title'] );

		$orphan = $manifest['files'][2];
		$this->assertSame( 'PDF/orphan.pdf', $orphan['path'] );
		$this->assertSame( 'pdf', $orphan['format'], 'format must be inferred from extension when no sidecar record exists' );
		$this->assertNull( $orphan['post'] );
		$this->assertNull( $orphan['sha256'] );

		$this->assertSame( 3, $manifest['totals']['files'] );
		$this->assertSame( 1, $manifest['totals']['posts'] );
		$this->assertSame( 20, $manifest['totals']['bytes'] );
		$this->assertSame( array( 'docx' => 1, 'pdf' => 2 ), $manifest['totals']['by_format'] );
	}

	public function test_to_json_is_pretty_unescaped_and_round_trips(): void {
		$manifest = array( 'schema' => SScribe_Export_Manifest::SCHEMA, 'files' => array( array( 'path' => 'PDF/صفحة.pdf', 'post' => array( 'url' => 'https://example.test/a/b' ) ) ) );

		$json = SScribe_Export_Manifest::to_json( $manifest );

		$this->assertStringContainsString( "\n", $json );
		$this->assertStringContainsString( 'PDF/صفحة.pdf', $json );
		$this->assertStringContainsString( 'https://example.test/a/b', $json );
		$this->assertStringNotContainsString( '\\/', $json );
		$this->assertSame( $manifest, json_decode( $json, true ) );
	}

	public function test_render_index_markdown_lists_posts_grouped_with_links_and_escapes_pipes(): void {
		$manifest = array(
			'schema'    => SScribe_Export_Manifest::SCHEMA,
			'generator' => array( 'name' => 'SScribe Export Site Pages', 'version' => '2.1.0' ),
			'site'      => array( 'name' => 'Example | Site', 'url' => 'https://example.test' ),
			'export'    => array( 'created_utc' => '2026-10-07T12:00:00Z', 'session_id' => 's', 'formats' => array( 'docx', 'pdf' ), 'languages' => array( 'en', 'ar' ) ),
			'files'     => array(
				array( 'path' => 'DOCX/P001-Hello.docx', 'format' => 'docx', 'lang' => 'en', 'bytes' => 10, 'sha256' => str_repeat( 'a', 64 ), 'post' => array( 'id' => 12, 'type' => 'page', 'title' => 'Hello | World', 'url' => 'https://example.test/hello/', 'modified_utc' => '2026-10-01T00:00:00Z' ) ),
				array( 'path' => 'PDF/P001-Hello.pdf', 'format' => 'pdf', 'lang' => 'en', 'bytes' => 10, 'sha256' => str_repeat( 'b', 64 ), 'post' => array( 'id' => 12, 'type' => 'page', 'title' => 'Hello | World', 'url' => 'https://example.test/hello/', 'modified_utc' => '2026-10-01T00:00:00Z' ) ),
				array( 'path' => 'PDF/orphan.pdf', 'format' => 'pdf', 'lang' => 'en', 'bytes' => 0, 'sha256' => null, 'post' => null ),
			),
			'totals'    => array( 'files' => 3, 'posts' => 1, 'bytes' => 20, 'by_format' => array( 'docx' => 1, 'pdf' => 2 ) ),
		);

		$md = SScribe_Export_Manifest::render_index_markdown( $manifest );

		$this->assertStringStartsWith( '# ', $md );
		$this->assertStringContainsString( 'Example \\| Site', $md );
		$this->assertStringContainsString( '2026-10-07T12:00:00Z', $md );
		$this->assertStringContainsString( '| Hello \\| World |', $md );
		$this->assertStringContainsString( '[DOCX](DOCX/P001-Hello.docx)', $md );
		$this->assertStringContainsString( '[PDF](PDF/P001-Hello.pdf)', $md );
		$this->assertStringContainsString( 'https://example.test/hello/', $md );
		$this->assertStringContainsString( 'PDF/orphan.pdf', $md, 'files without a source record must still be listed' );
		$this->assertSame( 1, substr_count( $md, '| 12 |' ), 'one row per post, not per file' );
		$this->assertStringContainsString( 'manifest.json', $md );
		$this->assertStringNotContainsString( '<', $md );
	}

	public function test_entry_names_are_reserved_constants(): void {
		$this->assertSame( 'manifest.json', SScribe_Export_Manifest::JSON_ENTRY );
		$this->assertSame( 'INDEX.md', SScribe_Export_Manifest::INDEX_ENTRY );
	}
}
