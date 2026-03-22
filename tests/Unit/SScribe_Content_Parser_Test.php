<?php
/**
 * Unit tests for SScribe_Content_Parser class.
 *
 * @package SScribe
 */

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
}
