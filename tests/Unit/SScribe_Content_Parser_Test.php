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
}
