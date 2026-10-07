<?php
/**
 * Markdown front matter preset tests.
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SScribe_Markdown_Exporter;
use SScribe_Markdown_Front_Matter;

if ( ! class_exists( '\\SScribe_Markdown_Exporter', false ) ) {
	require_once SSCRIBE_PLUGIN_DIR . 'includes/exporters/class-sscribe-markdown-exporter.php';
}

final class SScribe_Markdown_Front_Matter_Test extends TestCase {
	private string $out_dir;

	protected function setUp(): void {
		parent::setUp();
		$this->out_dir = \SScribe_Private_Storage::get_subdirectory( 'sscribe-md-preset-' . uniqid() );
		if ( ! is_dir( $this->out_dir ) ) {
			mkdir( $this->out_dir, 0700, true );
		}
	}

	protected function tearDown(): void {
		foreach ( glob( $this->out_dir . '/*' ) ?: array() as $file ) {
			unlink( $file );
		}
		@rmdir( $this->out_dir );
		parent::tearDown();
	}

	private function fields(): array {
		return array(
			'title'          => 'Hello "World"',
			'slug'           => 'hello-world',
			'url'            => 'https://example.test/blog/hello-world/',
			'author'         => 'Jane',
			'published'      => '2026-10-01T08:30:00+02:00',
			'modified'       => 1791374400,
			'status'         => 'publish',
			'post_type'      => 'post',
			'language'       => 'en',
			'description'    => "A short\n description   with   spaces",
			'featured_image' => 'https://example.test/wp-content/uploads/hero.jpg',
			'canonical_url'  => 'https://example.test/canonical/',
			'post_id'        => 42,
		);
	}

	public function test_normalize_preset_falls_back_to_sscribe(): void {
		$this->assertSame( 'hugo', SScribe_Markdown_Front_Matter::normalize_preset( ' HUGO ' ) );
		$this->assertSame( 'sscribe', SScribe_Markdown_Front_Matter::normalize_preset( 'wordpress' ) );
		$this->assertSame( 'sscribe', SScribe_Markdown_Front_Matter::normalize_preset( null ) );
		$this->assertSame( 'none', SScribe_Markdown_Front_Matter::normalize_preset( 'none' ) );
		$this->assertCount( 6, SScribe_Markdown_Front_Matter::labels() );
	}

	public function test_hugo_preset_uses_hugo_keys_and_utc_dates(): void {
		$yaml = SScribe_Markdown_Front_Matter::render( 'hugo', $this->fields() );

		$this->assertStringStartsWith( "---\n", $yaml );
		$this->assertStringEndsWith( "---\n\n", $yaml );
		$this->assertStringContainsString( 'title: "Hello \\"World\\""', $yaml );
		$this->assertStringContainsString( 'date: 2026-10-01T06:30:00Z', $yaml, 'Published dates are converted to UTC.' );
		$this->assertStringContainsString( 'lastmod: 2026-10-07T12:00:00Z', $yaml );
		$this->assertStringContainsString( 'draft: false', $yaml );
		$this->assertStringContainsString( 'description: "A short description with spaces"', $yaml );
		$this->assertStringContainsString( "images:\n  - \"https://example.test/wp-content/uploads/hero.jpg\"", $yaml );
		$this->assertStringContainsString( 'canonicalURL: "https://example.test/canonical/"', $yaml );
		$this->assertStringContainsString( "sscribe:\n  source_url: \"https://example.test/blog/hello-world/\"\n  post_id: 42", $yaml );
	}

	public function test_jekyll_preset_uses_layout_permalink_and_jekyll_dates(): void {
		$yaml = SScribe_Markdown_Front_Matter::render( 'jekyll', $this->fields() );

		$this->assertStringContainsString( 'layout: post', $yaml );
		$this->assertStringContainsString( 'date: "2026-10-01 06:30:00 +0000"', $yaml );
		$this->assertStringContainsString( 'permalink: "/blog/hello-world/"', $yaml );
		$this->assertStringContainsString( 'excerpt: "A short description with spaces"', $yaml );
		$this->assertStringContainsString( 'published: true', $yaml );

		$page = SScribe_Markdown_Front_Matter::render( 'jekyll', array_merge( $this->fields(), array( 'post_type' => 'page', 'status' => 'draft' ) ) );
		$this->assertStringContainsString( 'layout: page', $page );
		$this->assertStringContainsString( 'published: false', $page );
	}

	public function test_astro_preset_uses_pubdate_and_hero_image(): void {
		$yaml = SScribe_Markdown_Front_Matter::render( 'astro', $this->fields() );

		$this->assertStringContainsString( 'pubDate: "2026-10-01T06:30:00Z"', $yaml );
		$this->assertStringContainsString( 'updatedDate: "2026-10-07T12:00:00Z"', $yaml );
		$this->assertStringContainsString( 'heroImage: "https://example.test/wp-content/uploads/hero.jpg"', $yaml );
		$this->assertStringContainsString( 'draft: false', $yaml );
	}

	public function test_obsidian_preset_uses_aliases_tags_and_source(): void {
		$yaml = SScribe_Markdown_Front_Matter::render( 'obsidian', $this->fields() );

		$this->assertStringContainsString( "aliases:\n  - \"hello-world\"", $yaml );
		$this->assertStringContainsString( "tags:\n  - \"wordpress\"\n  - \"post\"", $yaml );
		$this->assertStringContainsString( 'source: "https://example.test/blog/hello-world/"', $yaml );
		$this->assertStringContainsString( 'created: "2026-10-01T06:30:00Z"', $yaml );
	}

	public function test_unknown_or_unsafe_values_are_dropped_not_emitted(): void {
		$yaml = SScribe_Markdown_Front_Matter::render(
			'hugo',
			array(
				'title'          => "Bad\x00Control",
				'url'            => 'javascript:alert(1)',
				'featured_image' => 'data:image/png;base64,AAAA',
				'published'      => 'not a date',
			)
		);

		$this->assertStringContainsString( 'title: "BadControl"', $yaml );
		$this->assertStringNotContainsString( 'javascript', $yaml );
		$this->assertStringNotContainsString( 'images:', $yaml );
		$this->assertStringNotContainsString( 'date:', $yaml );
		$this->assertSame( '', SScribe_Markdown_Front_Matter::render( 'sscribe', $this->fields() ) );
		$this->assertSame( '', SScribe_Markdown_Front_Matter::render( 'none', $this->fields() ) );
	}

	public function test_exporter_writes_preset_front_matter_without_duplicate_title_heading(): void {
		$exporter = new SScribe_Markdown_Exporter();
		$exporter->apply_format_options( array( 'sscribe_md_frontmatter_preset' => 'hugo' ) );

		$result = $exporter->export(
			array(
				'id'           => 7,
				'title'        => 'Preset page',
				'slug'         => 'preset-page',
				'permalink'    => 'https://example.test/preset-page/',
				'content'      => '<p>Body text.</p>',
				'author'       => 'Jane',
				'language'     => 'en',
				'date_modified' => 'October 7, 2026',
			),
			$this->out_dir,
			1,
			1
		);

		$this->assertTrue( $result->is_success() );
		$markdown = (string) file_get_contents( $result->get_data()['path'] );
		$this->assertStringStartsWith( "---\ntitle: \"Preset page\"\n", $markdown );
		$this->assertStringNotContainsString( '# Preset page', $markdown, 'Static site generators render the title from front matter.' );
		$this->assertStringNotContainsString( '> https://example.test/preset-page/', $markdown );
		$this->assertStringContainsString( 'Body text.', $markdown );
	}

	public function test_exporter_default_preset_keeps_legacy_layout_and_none_skips_front_matter(): void {
		$exporter = new SScribe_Markdown_Exporter();
		$result   = $exporter->export( array( 'id' => 8, 'title' => 'Legacy', 'content' => '<p>Text</p>' ), $this->out_dir, 1, 1 );
		$legacy   = (string) file_get_contents( $result->get_data()['path'] );
		$this->assertStringContainsString( "---\ntitle: \"Legacy\"", $legacy );
		$this->assertStringContainsString( '# Legacy', $legacy );

		$exporter->apply_format_options( array( 'sscribe_md_frontmatter_preset' => 'none' ) );
		$result = $exporter->export( array( 'id' => 9, 'title' => 'Bare', 'content' => '<p>Text</p>' ), $this->out_dir, 2, 2 );
		$bare   = (string) file_get_contents( $result->get_data()['path'] );
		$this->assertStringStartsNotWith( '---', $bare );
		$this->assertStringContainsString( 'Text', $bare );
	}
}
