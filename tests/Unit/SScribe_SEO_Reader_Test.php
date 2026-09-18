<?php
/**
 * SScribe SEO Reader Unit Test
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

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
        $this->assertArrayHasKey('meta_title', $result);
        $this->assertArrayHasKey('meta_description', $result);
        $this->assertArrayHasKey('focus_keyword', $result);
        $this->assertArrayHasKey('source', $result);
    }

    public function test_get_active_seo_plugins_returns_array(): void
    {
        $reader = new SScribe_SEO_Reader();
        $result = $reader->get_active_seo_plugins();

        $this->assertIsArray($result);
    }
}
