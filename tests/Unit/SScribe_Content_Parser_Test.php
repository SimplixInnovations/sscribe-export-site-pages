<?php
/**
 * SScribe Content Parser Unit Test
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SScribe_Content_Parser;

class SScribe_Content_Parser_Test extends TestCase
{
    private $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new SScribe_Content_Parser();
    }

    public function test_parse_returns_array(): void
    {
        $html = '<p>Test content</p>';
        $result = $this->parser->parse($html);

        $this->assertIsArray($result);
    }

    public function test_parse_heading(): void
    {
        $html = '<h1>Test Heading</h1>';
        $result = $this->parser->parse($html);

        $this->assertCount(1, $result);
        $this->assertSame('heading', $result[0]['type']);
        $this->assertSame(1, $result[0]['level']);
        $this->assertSame('Test Heading', $result[0]['content']);
    }

    public function test_parse_paragraph(): void
    {
        $html = '<p>Test paragraph</p>';
        $result = $this->parser->parse($html);

        $this->assertCount(1, $result);
        $this->assertSame('paragraph', $result[0]['type']);
    }

    public function test_parse_list(): void
    {
        $html = '<ul><li>Item 1</li><li>Item 2</li></ul>';
        $result = $this->parser->parse($html);

        $this->assertCount(1, $result);
        $this->assertSame('list', $result[0]['type']);
        $this->assertSame('bullet', $result[0]['style']);
    }

    public function test_parse_blockquote(): void
    {
        $html = '<blockquote>Quote text</blockquote>';
        $result = $this->parser->parse($html);

        $this->assertCount(1, $result);
        $this->assertSame('blockquote', $result[0]['type']);
    }

    public function test_parse_button_preserves_position_and_url(): void
    {
        $html = '<p>Before</p><div class="wp-block-button"><a class="wp-block-button__link" href="https://example.com/start">Start now</a></div><p>After</p>';
        $result = $this->parser->parse($html);

        $this->assertSame(array('paragraph', 'button', 'paragraph'), array_column($result, 'type'));
        $this->assertSame('Start now', $result[1]['content']);
        $this->assertSame('https://example.com/start', $result[1]['url']);
    }

public function test_url_to_local_path_rejects_sibling_upload_directories(): void
    {
        $uploadDir = wp_upload_dir();
        $uploadPath = realpath( $uploadDir['basedir'] );
        if ( false === $uploadPath ) {
            $this->markTestSkipped( 'Uploads directory is not available.' );
        }

        $parentDir = dirname( $uploadPath );
        $siblingDir = $parentDir . DIRECTORY_SEPARATOR . 'sscribe-upload-sibling-' . uniqid();
        wp_mkdir_p( $siblingDir );

        $siblingFile = $siblingDir . DIRECTORY_SEPARATOR . 'payload.png';
        file_put_contents( $siblingFile, 'fixture' );

        $url = rtrim( $uploadDir['baseurl'], '/' ) . '/../' . basename( $siblingDir ) . '/payload.png';

        $result = $this->parser->parse( '<img src="' . esc_url( $url ) . '" alt="test">' );

        $this->assertIsArray( $result );
        $this->assertNotEmpty( $result );
        $this->assertSame( 'image', $result[0]['type'] );
        $this->assertSame( '', $result[0]['local_path'] );

        if ( file_exists( $siblingFile ) ) {
            unlink( $siblingFile );
        }
        if ( is_dir( $siblingDir ) ) {
            @rmdir( $siblingDir );
        }
    }

    /**
     * Regression: protocol-relative URLs (//example.com/path) must resolve to
     * the same local path as their fully-qualified equivalent.
     * Before the fix, url_to_local_path() used stripos() against the upload
     * base URL which always has a scheme, so protocol-relative URLs never
     * matched and the image local_path was empty.
     */
    public function test_url_to_local_path_accepts_protocol_relative_urls(): void {
        $upload_dir = wp_upload_dir();
        $upload_url = $upload_dir['baseurl'];
        $upload_path = realpath( $upload_dir['basedir'] );

        if ( false === $upload_path || '' === $upload_url ) {
            $this->markTestSkipped( 'Uploads directory is not available.' );
        }

        $relative_image = $upload_path . '/sscribe-protocol-rel-' . uniqid() . '.png';
        $scheme = (string) wp_parse_url( $upload_url, PHP_URL_SCHEME );
        if ( '' === $scheme ) {
            $scheme = 'https';
        }
        $host = (string) wp_parse_url( $upload_url, PHP_URL_HOST );
        $protocol_relative_url = '//' . $host . substr( $upload_url, strlen( $scheme . '://' . $host ) ) . '/' . basename( $relative_image );

        // Create a real 1x1 transparent PNG so getimagesize() validates it
        // and the protocol-relative URL resolves to a real local path.
        $im = imagecreatetruecolor( 1, 1 );
        ob_start();
        imagepng( $im );
        $png_bytes = ob_get_clean();
        // imagedestroy() is deprecated in PHP 8.5+ (no effect since 8.0).
        // GD resources are released automatically when the test ends.
        file_put_contents( $relative_image, $png_bytes );

        $method = new \ReflectionMethod( \SScribe_Content_Parser::class, 'url_to_local_path' );
        // setAccessible(true) is deprecated in PHP 8.1+. Use Closure::bind
        // to read the value via a scoped callable instead.
        $ref = \Closure::bind(
            function ( $parser, $url ) {
                return $parser->url_to_local_path( $url );
            },
            null,
            \SScribe_Content_Parser::class
        );

        try {
            $resolved = $ref( $this->parser, $protocol_relative_url );
            $this->assertSame(
                realpath( $relative_image ),
                $resolved,
                'Protocol-relative URLs must resolve to the same local path as their fully-qualified equivalent'
            );
        } finally {
            if ( file_exists( $relative_image ) ) {
                unlink( $relative_image );
            }
        }
    }

    public function test_parse_figure_with_figcaption(): void {
        $html = '<figure><img src="test.png" alt="Test image"><figcaption>Caption for the image</figcaption></figure>';
        $result = $this->parser->parse( $html );

        $this->assertIsArray( $result );
        $this->assertNotEmpty( $result );
        $this->assertSame( 'figure', $result[0]['type'] );
        $this->assertStringContainsString( 'Test image', $result[0]['content'] );
        $this->assertStringContainsString( 'Caption for the image', $result[0]['content'] );
    }

    public function test_parse_figure_without_figcaption(): void {
        $html = '<figure><img src="photo.jpg" alt="A photo"></figure>';
        $result = $this->parser->parse( $html );

        $this->assertIsArray( $result );
        $this->assertNotEmpty( $result );
        $this->assertSame( 'figure', $result[0]['type'] );
        $this->assertStringContainsString( 'A photo', $result[0]['content'] );
    }

    public function test_parse_gutenberg_figure_block(): void {
        $html = '<!-- wp:image --><figure class="wp-block-image"><img src="gutenberg.png" alt="Gutenberg image"/><figcaption>Gutenberg caption</figcaption></figure><!-- /wp:image -->';
        $result = $this->parser->parse( $html );

        $this->assertIsArray( $result );
        $this->assertSame( 'figure', $result[0]['type'] );
        $this->assertStringContainsString( 'Gutenberg caption', $result[0]['content'] );
    }

    /**
     * Regression: <hr> must survive wp_kses() so the parse_node 'horizontal_rule'
     * case is reachable. Before the fix, KSES_ALLOWED_HTML lacked 'hr' and
     * wp_kses() stripped every <hr> before DOM parsing.
     */
    public function test_parse_horizontal_rule_not_stripped_by_kses(): void {
        $html = '<p>Above</p><hr><p>Below</p>';
        $result = $this->parser->parse( $html );

        $types = array_column( $result, 'type' );
        $this->assertContains( 'horizontal_rule', $types, '<hr> must reach the parser after wp_kses' );
    }

    /**
     * Regression: <details>/<summary> must survive wp_kses(). Before the fix,
     * KSES_ALLOWED_HTML lacked both tags and the parse_node 'details' case
     * was dead code.
     */
    public function test_parse_details_and_summary_not_stripped_by_kses(): void {
        $html = '<details><summary>More info</summary><p>Hidden content</p></details>';
        $result = $this->parser->parse( $html );

        $this->assertNotEmpty( $result );
        $this->assertSame( 'details', $result[0]['type'] );
        $this->assertSame( 'More info', $result[0]['summary'] );
    }

    /**
     * Regression: an <a> as a direct child of <li> must keep its href.
     * Before the fix, parse_list() called get_inline_runs(<a>) which only
     * walked text children, dropping the href attribute.
     */
    public function test_parse_list_preserves_href_on_anchor_child(): void {
        $html = '<ul><li>See <a href="https://example.com/docs">the docs</a> for details.</li></ul>';
        $result = $this->parser->parse( $html );

        $this->assertNotEmpty( $result );
        $this->assertSame( 'list', $result[0]['type'] );
        $items = $result[0]['items'];
        $this->assertCount( 1, $items );

        $links = array_filter(
            $items[0]['runs'],
            static function ( $run ) {
                return isset( $run['link'] );
            }
        );
        $this->assertNotEmpty( $links, '<a> as direct child of <li> must produce a run with link key' );
        $first = array_values( $links )[0];
        $this->assertSame( 'https://example.com/docs', $first['link'] );
    }

    /**
     * Regression: direct text inside a <div> (no wrapper <p>) must not be
     * silently dropped. Before the fix, the div case only iterated
     * XML_ELEMENT_NODE children and discarded text nodes.
     */
    public function test_parse_div_preserves_direct_text_children(): void {
        $html = '<div>Direct text content</div>';
        $result = $this->parser->parse( $html );

        $this->assertNotEmpty( $result );
        $found_text = false;
        foreach ( $result as $element ) {
            if ( isset( $element['content'] ) && false !== strpos( $element['content'], 'Direct text content' ) ) {
                $found_text = true;
                break;
            }
        }
        $this->assertTrue( $found_text, 'Direct text inside <div> must be parsed into an element' );
    }

    /**
     * Regression: pre-summary content must NOT be collected into the body.
     * Before the fix, the details case's else-if condition matched all
     * non-summary children even before the <summary> was seen, so malformed
     * pre-summary text/elements were mixed into the body.
     */
    public function test_parse_details_skips_pre_summary_content(): void {
        $html = '<details>orphan-before<summary>Click me</summary><p>Body</p></details>';
        $result = $this->parser->parse( $html );

        $this->assertNotEmpty( $result );
        $this->assertSame( 'details', $result[0]['type'] );
        $this->assertSame( 'Click me', $result[0]['summary'] );

        $body = $result[0]['content'];
        $this->assertCount( 1, $body, 'Only the post-summary <p> should be in the body' );
        $this->assertSame( 'paragraph', $body[0]['type'] );

        $body_texts = array_column( $body, 'content' );
        $this->assertNotContains( 'orphan-before', $body_texts );
    }

    /**
     * Regression: colspan/rowspan attributes from HTML tables must be
     * preserved in the parsed cell data so the DOCX renderer can size
     * cells correctly. Before the fix, the parser dropped these attributes.
     */
    public function test_parse_table_preserves_colspan_and_rowspan(): void {
        $html = '<table>'
            . '<tr><th colspan="2" rowspan="2">Header</th><th>Other</th></tr>'
            . '<tr><td>Data 1</td><td>Data 2</td></tr>'
            . '</table>';
        $result = $this->parser->parse( $html );

        $this->assertNotEmpty( $result );
        $this->assertSame( 'table', $result[0]['type'] );

        $first_row_cells = $result[0]['rows'][0]['cells'];
        $this->assertSame( 2, $first_row_cells[0]['colspan'], 'colspan must be preserved on the first cell' );
        $this->assertSame( 2, $first_row_cells[0]['rowspan'], 'rowspan must be preserved on the first cell' );
        $this->assertSame( 1, $first_row_cells[1]['colspan'], 'Cells without colspan default to 1' );
    }

    /**
     * Regression: a Gutenberg-style <figure> that wraps the <img> inside an
     * extra container (e.g. <div class="wp-block-image__container">) must
     * still extract the image, not silently drop it.
     */
    public function test_parse_figure_finds_img_in_nested_wrapper(): void {
        $html = '<figure class="wp-block-image">'
            . '<div class="wp-block-image__container"><img src="nested.png" alt="Nested image"/></div>'
            . '<figcaption>A nested caption</figcaption>'
            . '</figure>';
        $result = $this->parser->parse( $html );

        $this->assertNotEmpty( $result );
        $this->assertSame( 'figure', $result[0]['type'] );
        $this->assertSame( 'nested.png', $result[0]['src'] );
        $this->assertSame( 'Nested image', $result[0]['alt'] );
        $this->assertSame( 'A nested caption', $result[0]['caption'] );
    }
}
