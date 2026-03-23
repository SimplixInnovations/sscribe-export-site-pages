<?php
/**
 * Unit tests for SScribe_Page_Collector class.
 *
 * @package SScribe
 */

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SScribe_Page_Collector;
use ReflectionMethod;

class SScribe_Page_Collector_Test extends TestCase
{
    public function test_get_page_ids_method_exists(): void
    {
        $collector = new SScribe_Page_Collector();

        $this->assertTrue(method_exists($collector, 'get_page_ids'));
    }

    public function test_get_page_ids_accepts_post_status_parameter(): void
    {
        $collector = new SScribe_Page_Collector();

        $reflection = new ReflectionMethod($collector, 'get_page_ids');
        $params = $reflection->getParameters();

        $status_param_exists = false;
        foreach ($params as $param) {
            if ($param->getName() === 'post_status') {
                $status_param_exists = true;
                break;
            }
        }

        $this->assertTrue($status_param_exists, 'get_page_ids should accept post_status parameter');
    }

    public function test_get_valid_post_statuses(): void
    {
        $collector = new SScribe_Page_Collector();

        $valid_statuses = $collector->get_valid_post_statuses();

        $this->assertIsArray($valid_statuses);
        $this->assertArrayHasKey('publish', $valid_statuses);
        $this->assertArrayHasKey('draft', $valid_statuses);
        $this->assertArrayHasKey('private', $valid_statuses);
        $this->assertArrayHasKey('future', $valid_statuses);
    }

    public function test_validate_post_status_returns_valid_status(): void
    {
        $collector = new SScribe_Page_Collector();

        $result = $collector->validate_post_status('publish');
        $this->assertEquals('publish', $result);
    }

    public function test_validate_post_status_returns_default_for_invalid(): void
    {
        $collector = new SScribe_Page_Collector();

        $result = $collector->validate_post_status('invalid_status');
        $this->assertEquals('publish', $result);
    }

    public function test_validate_post_status_handles_all(): void
    {
        $collector = new SScribe_Page_Collector();

        $result = $collector->validate_post_status('all');
        $this->assertEquals('any', $result);
    }
}
