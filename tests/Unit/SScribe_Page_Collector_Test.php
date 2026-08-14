<?php
/**
 * SScribe Page Collector Unit Test
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

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

    /**
     * Regression test: when the chunked query uses `no_found_rows => true`,
     * `$query->max_num_pages` is 0. The previous loop terminated on
     * `$page <= $query->max_num_pages`, which exited after the first chunk
     * and silently dropped every page past the first 500. The loop must
     * instead drive its exit on the actual post count returned. This test
     * exercises 3 chunks of 100 IDs and asserts every ID is yielded.
     */
    public function test_get_page_ids_chunked_yields_all_pages_without_truncation(): void
    {
        $GLOBALS['sscribe_test_wp_query_chunks'] = array(
            range(1, 100),
            range(101, 200),
            range(201, 250),
        );
        $GLOBALS['sscribe_test_wp_query_calls'] = 0;

        $collector = new SScribe_Page_Collector();

        $all_ids = array();
        foreach ($collector->get_page_ids_chunked('en', 'publish', 'page', 100) as $chunk) {
            $all_ids = array_merge($all_ids, $chunk);
        }

        $this->assertCount(250, $all_ids, 'All 250 IDs across 3 chunks must be yielded.');
        $this->assertEquals(range(1, 250), $all_ids, 'IDs must be returned in order.');

        unset($GLOBALS['sscribe_test_wp_query_chunks'], $GLOBALS['sscribe_test_wp_query_calls']);
    }

    /**
     * Companion to the above: an empty result set must terminate cleanly
     * (no infinite loop) with zero IDs yielded.
     */
    public function test_get_page_ids_chunked_returns_empty_when_no_chunks(): void
    {
        $GLOBALS['sscribe_test_wp_query_chunks'] = array();
        $GLOBALS['sscribe_test_wp_query_calls'] = 0;

        $collector = new SScribe_Page_Collector();

        $all_ids = array();
        foreach ($collector->get_page_ids_chunked('en', 'publish', 'page', 100) as $chunk) {
            $all_ids = array_merge($all_ids, $chunk);
        }

        $this->assertSame(array(), $all_ids);

        unset($GLOBALS['sscribe_test_wp_query_chunks'], $GLOBALS['sscribe_test_wp_query_calls']);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['sscribe_test_registered_post_types']);
        parent::tearDown();
    }

    public function test_resolve_post_type_for_query_default_returns_first_selectable(): void
    {
        $collector = new SScribe_Page_Collector();

        $result = $collector->resolve_post_type_for_query('unknown_type');

        $this->assertSame('page', $result);
    }

    public function test_resolve_post_type_for_query_any_returns_array_containing_page_post(): void
    {
        $collector = new SScribe_Page_Collector();

        $result = $collector->resolve_post_type_for_query('any');

        $this->assertIsArray($result);
        $this->assertContains('page', $result);
        $this->assertContains('post', $result);
    }

    public function test_resolve_post_type_for_query_filters_via_sscribe_allowed_post_types(): void
    {
        $GLOBALS['sscribe_test_registered_post_types'] = array(
            'portfolio' => (object) array(
                'name'   => 'portfolio',
                'labels' => (object) array('singular_name' => 'Portfolio'),
                'public' => true,
            ),
        );

        $collector = new SScribe_Page_Collector();

        $allowed = array('page');
        $result  = $collector->resolve_post_type_for_query('portfolio', $allowed);

        $this->assertSame('page', $result);

        $allowed_with_portfolio = array('page', 'portfolio');
        $result2 = $collector->resolve_post_type_for_query('portfolio', $allowed_with_portfolio);

        $this->assertSame('portfolio', $result2);
    }
}
