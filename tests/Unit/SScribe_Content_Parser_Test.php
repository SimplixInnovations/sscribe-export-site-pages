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

    public function test_parse_handles_empty_string(): void
    {
        $result = $this->parser->parse('');

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function test_parse_handles_null(): void
    {
        $result = $this->parser->parse(null);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function test_parse_extracts_paragraph(): void
    {
        $html = '<p>Hello World</p>';
        $result = $this->parser->parse($html);

        $this->assertNotEmpty($result);
        $this->assertSame('paragraph', $result[0]['type']);
    }

    public function test_parse_extracts_heading(): void
    {
        $html = '<h1>Title</h1>';
        $result = $this->parser->parse($html);

        $this->assertNotEmpty($result);
        $this->assertSame('heading', $result[0]['type']);
        $this->assertSame(1, $result[0]['level']);
    }

    public function test_parse_extracts_list(): void
    {
        $html = '<ul><li>Item 1</li><li>Item 2</li></ul>';
        $result = $this->parser->parse($html);

        $this->assertNotEmpty($result);
        $this->assertSame('list', $result[0]['type']);
    }
}
