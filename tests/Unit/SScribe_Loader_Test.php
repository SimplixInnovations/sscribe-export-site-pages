<?php
/**
 * Unit tests for SScribe_Loader class.
 *
 * @package SScribe
 */

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SScribe_Loader;
use ReflectionClass;
use stdClass;

class SScribe_Loader_Test extends TestCase
{
    private $loader;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loader = new SScribe_Loader();
    }

    public function test_can_instantiate_loader(): void
    {
        $this->assertInstanceOf(SScribe_Loader::class, $this->loader);
    }

    public function test_add_action_stores_action(): void
    {
        $component = new stdClass();
        $this->loader->add_action('init', $component, 'callback');

        $reflection = new ReflectionClass($this->loader);
        $property = $reflection->getProperty('actions');
        $property->setAccessible(true);
        $actions = $property->getValue($this->loader);

        $this->assertCount(1, $actions);
        $this->assertSame('init', $actions[0]['hook']);
    }

    public function test_add_filter_stores_filter(): void
    {
        $component = new stdClass();
        $this->loader->add_filter('the_content', $component, 'callback');

        $reflection = new ReflectionClass($this->loader);
        $property = $reflection->getProperty('filters');
        $property->setAccessible(true);
        $filters = $property->getValue($this->loader);

        $this->assertCount(1, $filters);
        $this->assertSame('the_content', $filters[0]['hook']);
    }

    public function test_add_action_with_priority(): void
    {
        $component = new stdClass();
        $this->loader->add_action('init', $component, 'callback', 20);

        $reflection = new ReflectionClass($this->loader);
        $property = $reflection->getProperty('actions');
        $property->setAccessible(true);
        $actions = $property->getValue($this->loader);

        $this->assertSame(20, $actions[0]['priority']);
    }

    public function test_add_action_with_accepted_args(): void
    {
        $component = new stdClass();
        $this->loader->add_action('init', $component, 'callback', 10, 3);

        $reflection = new ReflectionClass($this->loader);
        $property = $reflection->getProperty('actions');
        $property->setAccessible(true);
        $actions = $property->getValue($this->loader);

        $this->assertSame(3, $actions[0]['accepted_args']);
    }
}
