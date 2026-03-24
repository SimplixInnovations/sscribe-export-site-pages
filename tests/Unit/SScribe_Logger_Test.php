<?php
/**
 * Logger tests.
 *
 * @package SScribe\Tests\Unit
 */

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

class SScribe_Logger_Test extends TestCase {

	public function test_is_enabled_returns_true_when_debug_is_true() {
		$logger = new \SScribe_Logger( true );
		$this->assertTrue( $logger->is_enabled() );
	}

	public function test_is_enabled_returns_false_when_debug_is_false() {
		$logger = new \SScribe_Logger( false );
		$this->assertFalse( $logger->is_enabled() );
	}

	public function test_debug_writes_to_log_file() {
		$log_dir = sys_get_temp_dir() . '/sscribe-test-' . uniqid();
		$logger = new \SScribe_Logger( true, $log_dir );
		
		$logger->debug( 'Test message', array( 'key' => 'value' ) );
		
		$this->assertFileExists( $logger->get_log_file() );
		$content = file_get_contents( $logger->get_log_file() );
		$this->assertStringContainsString( 'Test message', $content );
		$this->assertStringContainsString( '"key":"value"', $content );
		
		if ( file_exists( $logger->get_log_file() ) ) {
			unlink( $logger->get_log_file() );
		}
	}

	public function test_debug_does_not_write_when_disabled() {
		$log_dir = sys_get_temp_dir() . '/sscribe-test-' . uniqid();
		$logger = new \SScribe_Logger( false, $log_dir );
		
		$logger->debug( 'Test message' );
		
		$this->assertFalse( file_exists( $logger->get_log_file() ) );
	}
}
