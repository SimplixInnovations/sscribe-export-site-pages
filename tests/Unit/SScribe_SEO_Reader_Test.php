<?php
/**
 * Unit tests for SScribe_SEO_Reader class.
 *
 * @package SScribe
 */

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SScribe_SEO_Reader;

class SScribe_SEO_Reader_Test extends TestCase
{
    public function test_get_seo_data_returns_array(): void
    {
        $reader = new SScribe_SEO_Reader();
        $result = $reader->get_seo_data(1);

        $this->assertIsArray($result);
    }

    public function test_get_seo_data_has_required_keys(): void
    {
        $reader = new SScribe_SEO_Reader();
        $result = $reader->get_seo_data(1);

        $this->assertArrayHasKey('meta_title', $result);
        $this->assertArrayHasKey('meta_description', $result);
        $this->assertArrayHasKey('focus_keyword', $result);
        $this->assertArrayHasKey('source', $result);
    }

    public function test_get_seo_data_returns_empty_values_when_no_seo_plugin(): void
    {
        $reader = new SScribe_SEO_Reader();
        $result = $reader->get_seo_data(1);

        $this->assertSame('', $result['meta_title']);
        $this->assertSame('', $result['meta_description']);
        $this->assertSame('', $result['focus_keyword']);
        $this->assertSame('', $result['source']);
    }

    public function test_has_seo_plugin_returns_false_when_none_active(): void
    {
        $reader = new SScribe_SEO_Reader();
        $this->assertFalse($reader->has_seo_plugin());
    }

    public function test_get_active_seo_plugins_returns_empty_array(): void
    {
        $reader = new SScribe_SEO_Reader();
        $this->assertEmpty($reader->get_active_seo_plugins());
    }
}
