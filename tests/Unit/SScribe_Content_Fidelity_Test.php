<?php
/**
 * Content fidelity regressions found in real-browser testing on WordPress 7.1.
 *
 * - DOCX dropped every block table, because WordPress wraps block tables
 *   (and pull quotes, embeds) in <figure> and the parser only kept images.
 * - Code samples lost attributes: "&lt;div class=&quot;box&quot;&gt;" came
 *   out as "<div>" in every format, because attribute stripping ran over
 *   page text as well as tags.
 * - Markdown dropped escaped text such as "&lt;tags&gt;", glued captions to
 *   their images, and kept tab indentation from gallery HTML, which Markdown
 *   shows as a code block.
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SScribe_Content_Fidelity_Test extends TestCase {

	private function html_to_markdown( string $html ): string {
		$method = \Closure::bind(
			function ( string $document ) {
				$exporter = new \SScribe_Markdown_Exporter();
				return $exporter->html_to_markdown( $document ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.PrivateMethodFound
			},
			null,
			\SScribe_Markdown_Exporter::class
		);
		return $method( $html );
	}

	public function test_block_table_inside_figure_is_parsed_as_a_table(): void {
		$parser = new \SScribe_Content_Parser();

		$result = $parser->parse( '<figure class="wp-block-table"><table><tbody><tr><td>Starter</td><td>$49</td></tr></tbody></table><figcaption>Prices</figcaption></figure>' );

		$types = array_column( $result, 'type' );
		$this::assertContains( 'table', $types );
		$this::assertStringContainsString( 'Prices', (string) json_encode( $result ) );
		$this::assertStringContainsString( 'Starter', (string) json_encode( $result ) );
	}

	public function test_pull_quote_inside_figure_is_kept(): void {
		$parser = new \SScribe_Content_Parser();

		$result = $parser->parse( '<figure class="wp-block-pullquote"><blockquote><p>Pull quote text</p></blockquote></figure>' );

		$this::assertSame( 'blockquote', $result[0]['type'] ?? '' );
		$this::assertStringContainsString( 'Pull quote text', (string) ( $result[0]['content'] ?? '' ) );
	}

	public function test_image_figure_is_still_a_figure(): void {
		$parser = new \SScribe_Content_Parser();

		$result = $parser->parse( '<figure><img src="https://example.com/a.png" alt="A"><figcaption>Cap</figcaption></figure>' );

		$this::assertSame( 'figure', $result[0]['type'] ?? '' );
		$this::assertSame( 'Cap', $result[0]['caption'] ?? '' );
	}

	public function test_attribute_stripping_leaves_escaped_code_text_alone(): void {
		$html = '<pre class="wp-block-code" style="color:red"><code>&lt;div class="box" style="x"&gt;</code></pre>';

		$result = \SScribe_Helpers::strip_page_builder_attributes( $html );

		$this::assertSame( '<pre><code>&lt;div class="box" style="x"&gt;</code></pre>', $result );
	}

	public function test_attribute_stripping_still_cleans_tags_and_style_blocks(): void {
		$html = '<style>.a{}</style><div class="elementor" data-elementor-type="x" id="elementor-1" style="a:b">Hi</div>';

		$this::assertSame( '<div>Hi</div>', \SScribe_Helpers::strip_page_builder_attributes( $html ) );
	}

	public function test_markdown_keeps_escaped_angle_brackets(): void {
		$md = $this->html_to_markdown( '<p>Use the &lt;div&gt; tag and <code>&lt;span&gt;</code>.</p><pre><code>&lt;p class="x"&gt;Hi &amp; bye&lt;/p&gt;</code></pre>' );

		$this::assertStringContainsString( 'Use the \\<div> tag and `<span>`.', $md );
		$this::assertStringContainsString( "```\n<p class=\"x\">Hi & bye</p>\n```", $md );
	}

	public function test_markdown_puts_caption_on_its_own_line(): void {
		$md = $this->html_to_markdown( '<figure><img src="https://example.com/a.png" alt="A"><figcaption>Our team</figcaption></figure><p>Next</p>' );

		$this::assertMatchesRegularExpression( '/\\)\\n\\n\\*Our team\\*\\n\\nNext/', $md );
	}

	public function test_markdown_drops_tab_indentation_from_source_html(): void {
		$md = $this->html_to_markdown( "<p>Intro</p><div>\n\t\t\t\t<a href=\"https://example.com/x\"><img src=\"https://example.com/t.png\" alt=\"T\"></a>\n</div>" );

		$this::assertStringNotContainsString( "\t", $md );
		$this::assertMatchesRegularExpression( '/^\[!\[T\]/m', $md );
	}

	/**
	 * The Preview dialog showed "&amp;" literally and glued list items
	 * together ("workshopsImplementation"). The sample must be plain text.
	 */
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	#[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
	public function test_preview_excerpt_is_plain_spaced_text(): void {
		if ( ! function_exists( 'wp_trim_words' ) ) {
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- Minimal stand-in for the core function in this isolated process.
			eval( 'function wp_trim_words( $text, $num_words = 55, $more = null ) { $words = preg_split( "/[\\s]+/", trim( $text ) ); return count( $words ) > $num_words ? implode( " ", array_slice( $words, 0, $num_words ) ) . $more : implode( " ", $words ); }' );
		}
		$controller = ( new \ReflectionClass( \SScribe_Export_Query_Controller::class ) )->newInstanceWithoutConstructor();
		$method     = new \ReflectionMethod( $controller, 'build_preview_excerpt' );

		$text = $method->invoke( $controller, '<ul><li>Strategy</li><li>Phone &amp; email</li></ul><p>Visit <a href="#">our site</a>.</p>' );

		$this::assertSame( 'Strategy Phone & email Visit our site.', $text );
	}

	public function test_html_export_head_carries_seo_description_canonical_and_robots(): void {
		$html = ( new \SScribe_HTML_Exporter() )->generate_html_string(
			array(
				'id'       => 3,
				'title'    => 'About',
				'content'  => '<p>Body</p>',
				'language' => 'en',
				'seo'      => array(
					'meta_description' => 'Short "summary" of the page',
					'canonical_url'    => 'https://example.com/about/',
					'noindex'          => true,
				),
			)
		);

		$head = substr( $html, 0, (int) strpos( $html, '</head>' ) );
		$this::assertStringContainsString( '<meta name="description" content="Short &quot;summary&quot; of the page">', $head );
		$this::assertStringContainsString( '<link rel="canonical" href="https://example.com/about/">', $head );
		$this::assertStringContainsString( '<meta name="robots" content="noindex">', $head );
	}

	public function test_html_export_description_falls_back_to_excerpt(): void {
		$html = ( new \SScribe_HTML_Exporter() )->generate_html_string(
			array(
				'id'      => 4,
				'title'   => 'Team',
				'content' => '<p>Body</p>',
				'excerpt' => 'Meet the team',
			)
		);

		$this::assertStringContainsString( '<meta name="description" content="Meet the team">', $html );
		$this::assertStringNotContainsString( 'name="robots"', $html );
	}
}
