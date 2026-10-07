<?php
/**
 * Content robustness matrix: page builders, embeds, shortcodes, code, images.
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SScribe_Content_Parser;

final class SScribe_Content_Robustness_Matrix_Test extends TestCase {
	private SScribe_Content_Parser $parser;

	protected function setUp(): void {
		parent::setUp();
		$this->parser = new SScribe_Content_Parser();
	}

	private function fixture( string $name ): string {
		$path = dirname( __DIR__ ) . '/fixtures/content/' . $name . '.html';
		$this->assertFileExists( $path );
		return (string) file_get_contents( $path );
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function parse( string $name ): array {
		return $this->flatten( $this->parser->parse( $this->fixture( $name ) ) );
	}

	/**
	 * @param array<int,mixed> $elements Parsed elements.
	 * @return array<int,array<string,mixed>>
	 */
	private function flatten( array $elements ): array {
		$flat = array();
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			if ( isset( $element['type'] ) ) {
				$flat[] = $element;
			} else {
				foreach ( $this->flatten( $element ) as $nested ) {
					$flat[] = $nested;
				}
			}
		}
		return $flat;
	}

	/**
	 * @param array<int,array<string,mixed>> $elements Elements.
	 * @return array<int,array<string,mixed>>
	 */
	private function of_type( array $elements, string $type ): array {
		return array_values( array_filter( $elements, static fn( array $e ): bool => $type === $e['type'] ) );
	}

	/**
	 * @param array<int,array<string,mixed>> $elements Elements.
	 * @return array<int,string>
	 */
	private function texts( array $elements ): array {
		return array_map( static fn( array $e ): string => (string) ( $e['content'] ?? '' ), $elements );
	}

	/**
	 * @param array<int,array<string,mixed>> $elements Elements.
	 */
	private function all_text( array $elements ): string {
		$chunks = array();
		foreach ( $elements as $element ) {
			if ( isset( $element['content'] ) && is_string( $element['content'] ) ) {
				$chunks[] = $element['content'];
			} elseif ( isset( $element['content'] ) && is_array( $element['content'] ) ) {
				$chunks[] = $this->all_text( $this->flatten( $element['content'] ) );
			}
			if ( isset( $element['summary'] ) && is_string( $element['summary'] ) ) {
				$chunks[] = $element['summary'];
			}
			foreach ( (array) ( $element['runs'] ?? array() ) as $run ) {
				if ( ! empty( $run['link'] ) ) {
					$chunks[] = (string) $run['link'];
				}
			}
			if ( ! empty( $element['link'] ) ) {
				$chunks[] = (string) $element['link'];
			}
			foreach ( (array) ( $element['items'] ?? array() ) as $item ) {
				foreach ( (array) ( $item['runs'] ?? array() ) as $run ) {
					$chunks[] = (string) ( $run['text'] ?? '' );
					if ( ! empty( $run['link'] ) ) {
						$chunks[] = (string) $run['link'];
					}
				}
				if ( ! empty( $item['children'] ) ) {
					$chunks[] = $this->all_text( array( array( 'type' => 'list', 'items' => $item['children'] ) ) );
				}
			}
			if ( isset( $element['rows'] ) && is_array( $element['rows'] ) ) {
				foreach ( $element['rows'] as $row ) {
					foreach ( (array) ( $row['cells'] ?? $row ) as $cell ) {
						$chunks[] = is_array( $cell ) ? (string) ( $cell['content'] ?? '' ) : (string) $cell;
					}
				}
			}
		}
		return implode( "\n", $chunks );
	}

	/**
	 * @param array<int,array<string,mixed>> $elements Elements.
	 */
	private function assert_no_empty_blocks( array $elements ): void {
		foreach ( $elements as $element ) {
			if ( in_array( $element['type'], array( 'paragraph', 'heading', 'blockquote' ), true ) ) {
				$this->assertNotSame( '', trim( (string) $element['content'] ), 'No empty ' . $element['type'] . ' blocks may be emitted.' );
			}
		}
	}

	public function test_elementor_page_keeps_content_and_drops_chrome(): void {
		$elements = $this->parse( 'elementor' );
		$this->assert_no_empty_blocks( $elements );

		$headings = $this->of_type( $elements, 'heading' );
		$this->assertCount( 1, $headings );
		$this->assertSame( 2, $headings[0]['level'] );
		$this->assertSame( 'Our Services', $headings[0]['content'] );

		$paragraphs = $this->texts( $this->of_type( $elements, 'paragraph' ) );
		$this->assertContains( 'We build fast and accessible websites for teams that care.', $paragraphs );

		$lists = $this->of_type( $elements, 'list' );
		$this->assertCount( 1, $lists );

		$buttons = $this->of_type( $elements, 'button' );
		$this->assertCount( 1, $buttons, 'Exactly one Elementor button (the icon-only link must not become a button or a paragraph).' );
		$this->assertSame( 'Contact us', $buttons[0]['content'] );
		$this->assertSame( 'https://example.test/contact/', $buttons[0]['url'] );

		$images = $this->of_type( $elements, 'image' );
		$this->assertCount( 1, $images );
		$this->assertSame( 'https://example.test/wp-content/uploads/2026/10/team-1024x683.jpg', $images[0]['src'] );
		$this->assertSame( 'The team at work', $images[0]['alt'] );

		$all = $this->all_text( $elements );
		$this->assertStringNotContainsString( 'SVG image', $all, 'Decorative icon SVGs must be dropped, not announced.' );
		$this->assertStringNotContainsString( 'elementor', $all );
		$this->assertStringNotContainsString( '{', $all, 'Scoped CSS must never leak into text.' );
	}

	public function test_divi_page_keeps_modules_and_turns_embedded_iframe_into_link(): void {
		$elements = $this->parse( 'divi' );
		$this->assert_no_empty_blocks( $elements );

		$headings = $this->texts( $this->of_type( $elements, 'heading' ) );
		$this->assertSame( array( 'Why choose us', 'Fast delivery', 'Do you offer support?' ), $headings );

		$paragraphs = $this->texts( $this->of_type( $elements, 'paragraph' ) );
		$this->assertContains( 'Twenty years of experience, one promise.', $paragraphs );
		$this->assertContains( 'Most projects ship in six weeks.', $paragraphs );
		$this->assertContains( 'Yes, every plan includes a year of support.', $paragraphs );

		$buttons = $this->of_type( $elements, 'button' );
		$this->assertCount( 1, $buttons );
		$this->assertSame( 'Get a quote', $buttons[0]['content'] );

		$all = $this->all_text( $elements );
		$this->assertNotContains( 'E', $paragraphs, 'The Divi icon-font glyph must not be exported as text.' );
		$this->assertStringContainsString( 'https://www.youtube.com/embed/dQw4w9WgXcQ', $all, 'An embedded iframe must survive as a link, never be silently dropped.' );
		$this->assertStringContainsString( 'Product tour', $all );
	}

	public function test_wpbakery_page_keeps_content_and_drops_separators(): void {
		$elements = $this->parse( 'wpbakery' );
		$this->assert_no_empty_blocks( $elements );

		$this->assertSame( array( 'Pricing that scales' ), $this->texts( $this->of_type( $elements, 'heading' ) ) );
		$this->assertContains( 'Start free, upgrade when you need more.', $this->texts( $this->of_type( $elements, 'paragraph' ) ) );

		$lists = $this->of_type( $elements, 'list' );
		$this->assertCount( 1, $lists );
		$this->assertSame( 'numbered', $lists[0]['style'] );

		$buttons = $this->of_type( $elements, 'button' );
		$this->assertCount( 1, $buttons );
		$this->assertSame( 'Compare plans', $buttons[0]['content'] );

		$images = array_merge( $this->of_type( $elements, 'image' ), $this->of_type( $elements, 'figure' ) );
		$this->assertCount( 1, $images );
		$this->assertSame( 'Plan comparison', $images[0]['alt'] );
	}

	public function test_beaver_and_bricks_pages_keep_content_and_buttons(): void {
		$elements = $this->parse( 'beaver-bricks' );
		$this->assert_no_empty_blocks( $elements );

		$this->assertSame( array( 'Beaver heading', 'Bricks heading' ), $this->texts( $this->of_type( $elements, 'heading' ) ) );

		$paragraphs = $this->texts( $this->of_type( $elements, 'paragraph' ) );
		$this->assertContains( 'Rich text from Beaver Builder with a documentation link.', $paragraphs );
		$this->assertContains( 'Plain text element from Bricks.', $paragraphs );
		$this->assertContains( 'Rich text from Bricks with bold.', $paragraphs );

		$buttons = $this->of_type( $elements, 'button' );
		$this->assertSame( array( 'Start now', 'Bricks button' ), $this->texts( $buttons ) );
	}

	public function test_gutenberg_blocks_map_to_document_structure(): void {
		$elements = $this->parse( 'gutenberg' );
		$this->assert_no_empty_blocks( $elements );

		$headings = $this->of_type( $elements, 'heading' );
		$this->assertSame( array( 'Welcome to the handbook', 'Getting started' ), $this->texts( $headings ) );

		$lists = $this->of_type( $elements, 'list' );
		$this->assertCount( 1, $lists, 'The nested list belongs inside its parent list, not alongside it.' );
		$this->assertStringContainsString( '#general', $this->all_text( $lists ) );

		$tables = $this->of_type( $elements, 'table' );
		$this->assertCount( 1, $tables );
		$this->assertStringContainsString( 'Onboarding', $this->all_text( $tables ) );
		$this->assertStringContainsString( '2 days', $this->all_text( $tables ), 'tfoot rows must be exported.' );

		$quotes = $this->of_type( $elements, 'blockquote' );
		$this->assertCount( 2, $quotes, 'The pull quote and the tweet are both quotes.' );
		$this->assertStringContainsString( 'Simplicity is the soul of efficiency.', $quotes[0]['content'] );

		$code = $this->of_type( $elements, 'code' );
		$this->assertGreaterThanOrEqual( 2, count( $code ) );
		$this->assertStringContainsString( "function greet(name) {\n  return `Hello, \${name}`;\n}", $code[0]['content'] );
		$this->assertStringContainsString( 'if (a < b && c > d) {}', $code[0]['content'], 'Entities inside code must decode.' );
		$this->assertStringContainsString( "Line one\n    indented line two", $code[1]['content'], 'Preformatted text keeps its indentation.' );

		$buttons = $this->of_type( $elements, 'button' );
		$this->assertSame( array( 'Apply now' ), $this->texts( $buttons ) );

		$this->assertCount( 1, $this->of_type( $elements, 'horizontal_rule' ) );

		$images = array_merge( $this->of_type( $elements, 'image' ), $this->of_type( $elements, 'figure' ) );
		$image_srcs = array_map( static fn( array $e ): string => (string) ( $e['src'] ?? $e['image']['src'] ?? '' ), $images );
		$this->assertContains( 'https://example.test/wp-content/uploads/2026/10/hero.jpg', $image_srcs, 'Cover background images are content.' );
		$this->assertContains( 'https://example.test/wp-content/uploads/2026/10/office-1024x768.jpg', $image_srcs );
		$this->assertContains( 'https://example.test/wp-content/uploads/2026/10/g1.jpg', $image_srcs, 'Gallery images must each be exported.' );
		$this->assertContains( 'https://example.test/wp-content/uploads/2026/10/g2.jpg', $image_srcs );

		$all = $this->all_text( $elements );
		$this->assertStringContainsString( 'The office in spring', $all );
		$this->assertStringContainsString( 'What is the dress code?', $all );
		$this->assertStringContainsString( 'Whatever you are comfortable in.', $all );
		$this->assertStringContainsString( 'https://www.youtube.com/embed/abc123XYZ', $all, 'A YouTube embed must survive as a link.' );
		$this->assertStringContainsString( 'Watch the welcome video', $all );
		$this->assertStringContainsString( 'We are hiring!', $all );
		$this->assertStringContainsString( 'https://twitter.com/example/status/1234567890', $all );
		$this->assertStringContainsString( 'Inline wp_enqueue_script() should stay readable, and so should a Ctrl key.', $all );
		$this->assertStringNotContainsString( 'widgets.js', $all );
	}

	public function test_embeds_become_links_and_are_never_silently_dropped(): void {
		$elements = $this->parse( 'embeds' );
		$this->assert_no_empty_blocks( $elements );
		$all = $this->all_text( $elements );

		foreach (
			array(
				'https://www.youtube.com/embed/xyz789',
				'https://player.vimeo.com/video/123456789?h=abc',
				'https://example.test/wp-content/uploads/2026/10/demo.mp4',
				'https://example.test/wp-content/uploads/2026/10/podcast-episode-1.mp3',
				'https://www.google.com/maps/embed?pb=!1m18!1m12',
				'https://example.test/wp-content/uploads/2026/10/brochure.pdf',
			) as $url
		) {
			$this->assertStringContainsString( $url, $all, "Embedded media {$url} must be exported as a link." );
		}
		$this->assertStringContainsString( 'Launch video', $all, 'The iframe title is the best label we have.' );
		$this->assertStringNotContainsString( 'inline', $all, 'srcdoc iframes carry no URL and must be dropped quietly.' );

		$linked = array();
		foreach ( $this->of_type( $elements, 'paragraph' ) as $paragraph ) {
			foreach ( (array) ( $paragraph['runs'] ?? array() ) as $run ) {
				if ( ! empty( $run['link'] ) ) {
					$linked[] = $run['link'];
				}
			}
		}
		$this->assertContains( 'https://www.youtube.com/embed/xyz789', $linked, 'Embed URLs must be real links, not bare text.' );

		$texts = $this->texts( $this->of_type( $elements, 'paragraph' ) );
		$this->assertSame( 'Watch the launch:', $texts[0] );
		$this->assertSame( 'And a closing paragraph.', end( $texts ) );
	}

	public function test_unrendered_shortcodes_are_stripped_but_bracket_prose_survives(): void {
		$elements = $this->parse( 'shortcodes' );
		$this->assert_no_empty_blocks( $elements );
		$all = $this->all_text( $elements );

		$this->assertStringNotContainsString( '[contact-form-7', $all );
		$this->assertStringNotContainsString( '[acf', $all );
		$this->assertStringNotContainsString( '[et_pb_', $all );
		$this->assertStringNotContainsString( '[/et_pb_', $all );
		$this->assertStringNotContainsString( '[gallery', $all );
		$this->assertStringNotContainsString( '[vc_', $all );
		$this->assertStringNotContainsString( '[embed]', $all );

		$this->assertStringContainsString( 'Real text that must survive.', $all );
		$this->assertStringContainsString( 'WPBakery leftover text.', $all );
		$this->assertStringContainsString( 'https://www.youtube.com/watch?v=leftover123', $all, 'The URL inside a leftover embed shortcode is the content.' );
		$this->assertStringContainsString( 'Venue:', $all );

		$this->assertStringContainsString( '[sic]', $all );
		$this->assertStringContainsString( '[1]', $all );
		$this->assertStringContainsString( '[UPDATE]', $all );
		$this->assertStringContainsString( '[Password Protected Content]', $all );
		$this->assertStringContainsString( '[USD]', $all );
		$this->assertStringContainsString( '[EUR]', $all );
	}

	public function test_syntax_highlighter_output_exports_as_plain_code_with_line_breaks(): void {
		$elements = $this->parse( 'code' );
		$code     = $this->of_type( $elements, 'code' );
		$this->assertCount( 5, $code );

		$this->assertSame( "function total(\$items) {\n    return array_sum(\$items);\n}", $code[0]['content'], 'Prism token spans must collapse to plain code.' );
		$this->assertSame( "const x = 1;\nconsole.log(x && true);", $code[1]['content'] );
		$this->assertSame( "npm install\nnpm run build", $code[2]['content'] );
		$this->assertSame( "line one\nline two", $code[3]['content'], 'CRLF inside code must be normalized.' );
		$this->assertSame( "<ul>\n  <li>Item</li>\n</ul>", $code[4]['content'] );

		$paragraphs = $this->texts( $this->of_type( $elements, 'paragraph' ) );
		$this->assertContains( 'Entities inside code: <div class="x"> must decode.', $paragraphs, 'Inline code stays inline within its paragraph.' );
	}

	public function test_lazy_srcset_picture_and_noscript_images_resolve_to_one_real_source_each(): void {
		$elements = $this->parse( 'images' );
		$images   = $this->of_type( $elements, 'image' );
		$srcs     = array_map( static fn( array $e ): string => (string) $e['src'], $images );

		$this->assertSame(
			array(
				'https://example.test/wp-content/uploads/2026/10/lazy.jpg',
				'https://example.test/wp-content/uploads/2026/10/native.jpg',
				'https://example.test/wp-content/uploads/2026/10/pic.jpg',
				'https://example.test/wp-content/uploads/2026/10/noscript.jpg',
				'https://example.test/wp-content/uploads/2026/10/full-300.jpg',
			),
			$srcs
		);
		foreach ( $srcs as $src ) {
			$this->assertStringStartsNotWith( 'data:', $src, 'Placeholder data URIs must never be exported.' );
		}
		$this->assertStringNotContainsString( 'Broken', $this->all_text( $elements ) );
	}
}
