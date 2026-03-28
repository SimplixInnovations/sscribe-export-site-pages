<?php
/**
 * Logger tests.
 *
 * @package SScribe\Tests\Unit
 */

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

class SScribe_Logger_Test extends TestCase
{

	protected function setUp(): void
	{
		parent::setUp();
		$GLOBALS['sscribe_test_options'] = array();
	}

	protected function tearDown(): void
	{
		// Clear the shared log file between tests to ensure isolation.
		(new \SScribe_Logger(true))->clear_logs();
		$GLOBALS['sscribe_test_options'] = array();
		parent::tearDown();
	}

	public function test_is_enabled_returns_true_when_debug_is_true()
	{
		$logger = new \SScribe_Logger(true);
		$this->assertTrue($logger->is_enabled());
	}

	public function test_is_enabled_returns_false_when_debug_is_false()
	{
		$logger = new \SScribe_Logger(false);
		$this->assertFalse($logger->is_enabled());
	}

	public function test_debug_writes_to_log()
	{
		$logger = new \SScribe_Logger(true);

		$logger->debug('Test message', array('key' => 'value'));

		$logs = $logger->get_logs();

		$this->assertIsArray($logs);
		$this->assertCount(1, $logs);
		$this->assertStringContainsString('Test message', $logs[0]);
		$this->assertStringContainsString('"key":"value"', $logs[0]);
	}

	public function test_debug_does_not_write_when_disabled()
	{
		$logger = new \SScribe_Logger(false);

		$logger->debug('Test message');

		$logs = $logger->get_logs();

		$this->assertEmpty($logs);
	}

	public function test_clear_logs()
	{
		$logger = new \SScribe_Logger(true);

		$logger->debug('Test message');
		$this->assertCount(1, $logger->get_logs());

		$logger->clear_logs();
		$this->assertEmpty($logger->get_logs());
	}

	public function test_error_log()
	{
		$logger = new \SScribe_Logger(true);

		$logger->error('Error message');

		$logs = $logger->get_logs();

		$this->assertStringContainsString('[ERROR]', $logs[0]);
		$this->assertStringContainsString('Error message', $logs[0]);
	}
}
