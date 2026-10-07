<?php
/**
 * llms.txt rendering tests.
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SScribe_Export_Manifest;

final class SScribe_Export_Manifest_Llms_Test extends TestCase {

	private function manifest(): array {
		return array(
			'schema'    => SScribe_Export_Manifest::SCHEMA,
			'generator' => array( 'name' => 'SScribe Export Site Pages', 'version' => '2.1.0' ),
			'site'      => array( 'name' => 'Example  Site', 'url' => 'https://example.test/' ),
			'export'    => array( 'created_utc' => '2026-10-08T09:00:00Z', 'session_id' => 's', 'formats' => array( 'docx', 'markdown' ), 'languages' => array( 'en', 'ar' ) ),
			'files'     => array(
				array( 'path' => 'DOCX/EN/P001-Hello.docx', 'format' => 'docx', 'lang' => 'en', 'bytes' => 1, 'sha256' => null, 'post' => array( 'id' => 1, 'type' => 'page', 'title' => 'Hello [World]', 'url' => 'https://example.test/hello/', 'modified_utc' => '', 'excerpt' => "A  short\nsummary <b>here</b>" ) ),
				array( 'path' => 'MARKDOWN/EN/P001-Hello.md', 'format' => 'markdown', 'lang' => 'en', 'bytes' => 1, 'sha256' => null, 'post' => array( 'id' => 1, 'type' => 'page', 'title' => 'Hello [World]', 'url' => 'https://example.test/hello/', 'modified_utc' => '', 'excerpt' => 'A short summary here' ) ),
				array( 'path' => 'MARKDOWN/AR/P002-صفحة.md', 'format' => 'markdown', 'lang' => 'ar', 'bytes' => 1, 'sha256' => null, 'post' => array( 'id' => 2, 'type' => 'page', 'title' => 'صفحة', 'url' => 'https://example.test/ar/page/', 'modified_utc' => '' ) ),
				array( 'path' => 'MARKDOWN/EN/orphan.md', 'format' => 'markdown', 'lang' => 'en', 'bytes' => 1, 'sha256' => null, 'post' => null ),
			),
			'totals'    => array( 'files' => 4, 'posts' => 2, 'bytes' => 4, 'by_format' => array( 'docx' => 1, 'markdown' => 3 ) ),
		);
	}

	public function test_llms_txt_lists_each_post_once_per_language_with_excerpt(): void {
		$txt = SScribe_Export_Manifest::render_llms_txt( $this->manifest() );

		$this->assertStringStartsWith( "# Example Site\n\n> Content export of https://example.test/ generated 2026-10-08T09:00:00Z.", $txt );
		$this->assertStringContainsString( "## Pages (ar)\n\n- [صفحة](https://example.test/ar/page/)\n", $txt );
		$this->assertStringContainsString( "## Pages (en)\n\n- [Hello \\[World\\]](https://example.test/hello/): A short summary here\n", $txt );
		$this->assertSame( 1, substr_count( $txt, 'https://example.test/hello/' ), 'DOCX and Markdown of the same post collapse to one entry.' );
		$this->assertStringNotContainsString( 'orphan', $txt );
		$this->assertStringNotContainsString( '<b>', $txt );
		$this->assertStringEndsWith( "\n", $txt );
	}

	public function test_llms_full_concatenates_markdown_bodies_without_front_matter(): void {
		$bodies = array(
			'MARKDOWN/EN/P001-Hello.md' => "---\ntitle: \"Hello\"\nslug: \"hello\"\n---\n\n# Hello\n\nBody one.\n",
			'MARKDOWN/AR/P002-صفحة.md'  => "نص عربي.\n",
			'MARKDOWN/EN/orphan.md'     => "---\n---\n",
		);

		$full = SScribe_Export_Manifest::render_llms_full( $this->manifest(), static fn( string $path ): string => $bodies[ $path ] ?? '' );

		$this->assertStringStartsWith( "# Hello [World]\n\nSource: https://example.test/hello/\n\n# Hello\n\nBody one.", $full );
		$this->assertStringContainsString( "\n\n---\n\n# صفحة\n\nSource: https://example.test/ar/page/\n\nنص عربي.", $full );
		$this->assertStringNotContainsString( 'slug: "hello"', $full, 'Front matter is stripped.' );
		$this->assertStringNotContainsString( 'orphan', $full, 'Empty documents are skipped.' );
		$this->assertStringEndsWith( "\n", $full );
	}

	public function test_llms_full_is_empty_without_markdown_documents(): void {
		$manifest          = $this->manifest();
		$manifest['files'] = array( $manifest['files'][0] );

		$this->assertSame( '', SScribe_Export_Manifest::render_llms_full( $manifest, static fn( string $path ): string => 'x' ) );
	}

	public function test_sidecar_excerpt_is_stripped_collapsed_and_bounded(): void {
		$dir = sys_get_temp_dir() . '/sscribe-llms-' . uniqid();
		mkdir( $dir, 0700, true );
		SScribe_Export_Manifest::record(
			$dir,
			array(
				'file'    => 'EN/x.md',
				'format'  => 'markdown',
				'excerpt' => '<p>Long   ' . str_repeat( 'word ', 100 ) . '</p>',
			)
		);
		$records = SScribe_Export_Manifest::load( $dir );
		unlink( $dir . '/' . SScribe_Export_Manifest::SIDECAR_NAME );
		rmdir( $dir );

		$excerpt = $records['EN/x.md']['excerpt'];
		$this->assertStringStartsWith( 'Long word word', $excerpt );
		$this->assertLessThanOrEqual( 300, mb_strlen( $excerpt ) );
		$this->assertStringNotContainsString( '<p>', $excerpt );
	}
}
