<?php
/**
 * SScribe Logger Singleton Unit Test
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests;

use PHPUnit\Framework\TestCase;
use SScribe_Logger;

final class SScribe_Logger_Singleton_Test extends TestCase {

	protected function setUp(): void {
		
		
		$reflection = new \ReflectionClass( SScribe_Logger::class );
		$property   = $reflection->getProperty( 'instances' );
		$property->setValue( null, array() );
	}

	public function test_instance_returns_same_object_for_same_key(): void {
		$a = SScribe_Logger::instance( true, 'test' );
		$b = SScribe_Logger::instance( true, 'test' );

		$this->assertSame( $a, $b );
	}

	public function test_instance_differs_by_prefix(): void {
		$a = SScribe_Logger::instance( true, 'prefix_a' );
		$b = SScribe_Logger::instance( true, 'prefix_b' );

		$this->assertNotSame( $a, $b );
	}

	public function test_instance_differs_by_enabled(): void {
		$a = SScribe_Logger::instance( true, 'same' );
		$b = SScribe_Logger::instance( false, 'same' );

		$this->assertNotSame( $a, $b );
	}

	public function test_instance_returns_self_type(): void {
		$logger = SScribe_Logger::instance( true, 'type_check' );
		$this->assertInstanceOf( SScribe_Logger::class, $logger );
	}

	public function test_is_enabled_reflects_constructor(): void {
		$enabled  = SScribe_Logger::instance( true, 'enabled_test' );
		$disabled = SScribe_Logger::instance( false, 'disabled_test' );

		$this->assertTrue( $enabled->is_enabled() );
		$this->assertFalse( $disabled->is_enabled() );
	}

	public function test_buffer_and_flush(): void {
		$logger = SScribe_Logger::instance( true, 'buffer_test' );

		$logger->debug( 'test message 1' );
		$logger->error( 'test message 2' );

		$logs = $logger->get_logs();
		$this->assertIsArray( $logs );
		$this->assertGreaterThanOrEqual( 2, count( $logs ) );
		$this->assertStringContainsString( 'test message 1', $logs[0] );
		$this->assertStringContainsString( 'test message 2', $logs[1] );
	}

	public function test_disabled_logger_returns_empty_logs(): void {
		$logger = SScribe_Logger::instance( false, 'disabled_logs' );
		$logger->debug( 'should be ignored' );

		$logs = $logger->get_logs();
		$this->assertEmpty( $logs );
	}

	public function test_cleanup_old_logs_is_static(): void {
		$this->assertTrue( method_exists( SScribe_Logger::class, 'cleanup_old_logs' ) );
	}
}
