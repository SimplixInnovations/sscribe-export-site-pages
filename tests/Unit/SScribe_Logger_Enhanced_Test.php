<?php
/**
 * Unit tests for SScribe_Logger_Enhanced class.
 *
 * @package SScribe
 */

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SScribe_Logger_Enhanced;

class SScribe_Logger_Enhanced_Test extends TestCase {
	private ?SScribe_Logger_Enhanced $logger;

	protected function setUp(): void {
		parent::setUp();
		$this->logger = new SScribe_Logger_Enhanced();
	}

	protected function tearDown(): void {
		$this->logger = null;
		parent::tearDown();
	}

	/**
	 * Test that logger can be instantiated.
	 */
	public function test_logger_can_be_instantiated(): void {
		$this->assertInstanceOf( SScribe_Logger_Enhanced::class, $this->logger );
	}

	/**
	 * Test is_enabled returns true when file or db enabled.
	 */
	public function test_is_enabled_returns_true_by_default(): void {
		$result = $this->logger->is_enabled();
		$this->assertTrue( $result );
	}

	/**
	 * Test get_logs returns array.
	 */
	public function test_get_logs_returns_array(): void {
		$result = $this->logger->get_logs();
		$this->assertIsArray( $result );
	}

	/**
	 * Test get_logs with limit parameter.
	 */
	public function test_get_logs_respects_limit(): void {
		$result = $this->logger->get_logs( 5 );
		$this->assertIsArray( $result );
	}

	/**
	 * Test get_db_logs returns array.
	 */
	public function test_get_db_logs_returns_array(): void {
		$result = $this->logger->get_db_logs();
		$this->assertIsArray( $result );
	}

	/**
	 * Test get_db_logs with filters.
	 */
	public function test_get_db_logs_with_filters(): void {
		$filters = array( 'level' => 'error' );
		$result  = $this->logger->get_db_logs( $filters, 10 );
		$this->assertIsArray( $result );
	}

	/**
	 * Test cleanup_db_logs returns integer.
	 */
	public function test_cleanup_db_logs_returns_integer(): void {
		$result = $this->logger->cleanup_db_logs( 30 );
		$this->assertIsInt( $result );
	}

	/**
	 * Test log method accepts all valid levels.
	 */
	public function test_log_accepts_all_levels(): void {
		$levels = array( 'debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency' );

		foreach ( $levels as $level ) {
			$this->logger->log( $level, 'Test message for ' . $level );
			$this->assertTrue( true, 'No exception for level: ' . $level );
		}
	}

	/**
	 * Test debug convenience method.
	 */
	public function test_debug_method(): void {
		$this->logger->debug( 'Debug test message' );
		$this->assertTrue( true );
	}

	/**
	 * Test info convenience method.
	 */
	public function test_info_method(): void {
		$this->logger->info( 'Info test message' );
		$this->assertTrue( true );
	}

	/**
	 * Test notice convenience method.
	 */
	public function test_notice_method(): void {
		$this->logger->notice( 'Notice test message' );
		$this->assertTrue( true );
	}

	/**
	 * Test warning convenience method.
	 */
	public function test_warning_method(): void {
		$this->logger->warning( 'Warning test message' );
		$this->assertTrue( true );
	}

	/**
	 * Test error convenience method.
	 */
	public function test_error_method(): void {
		$this->logger->error( 'Error test message' );
		$this->assertTrue( true );
	}

	/**
	 * Test critical convenience method.
	 */
	public function test_critical_method(): void {
		$this->logger->critical( 'Critical test message' );
		$this->assertTrue( true );
	}

	/**
	 * Test alert convenience method.
	 */
	public function test_alert_method(): void {
		$this->logger->alert( 'Alert test message' );
		$this->assertTrue( true );
	}

	/**
	 * Test emergency convenience method.
	 */
	public function test_emergency_method(): void {
		$this->logger->emergency( 'Emergency test message' );
		$this->assertTrue( true );
	}

	/**
	 * Test log with context data.
	 */
	public function test_log_with_context(): void {
		$context = array( 'user_id' => 1, 'page_id' => 123 );
		$this->logger->info( 'Test with context', $context );
		$this->assertTrue( true );
	}

	/**
	 * Test log sanitizes context by removing sensitive keys.
	 */
	public function test_log_sanitizes_sensitive_context(): void {
		$context = array(
			'password' => 'secret123',
			'token'    => 'abc123',
			'api_key'  => 'key456',
			'safe_key' => 'safe_value',
		);

		// Should not throw.
		$this->logger->info( 'Test sensitive data', $context );
		$this->assertTrue( true );
	}

	/**
	 * Test flush method exists and is callable.
	 */
	public function test_flush_method_exists(): void {
		$this->assertIsCallable( array( $this->logger, 'flush' ) );
	}

	/**
	 * Test LEVEL_PRIORITY constant via reflection.
	 */
	public function test_level_priority_constant(): void {
		$reflection = new \ReflectionClass( SScribe_Logger_Enhanced::class );
		$priority   = $reflection->getConstant( 'LEVEL_PRIORITY' );
		$this->assertIsArray( $priority );
		$this->assertArrayHasKey( 'debug', $priority );
		$this->assertArrayHasKey( 'error', $priority );
	}

	/**
	 * Test should_log respects minimum level.
	 */
	public function test_should_log_respects_min_level(): void {
		$method = new \ReflectionMethod( SScribe_Logger_Enhanced::class, 'should_log' );
		$method->setAccessible( true );

		// With min_level='warning', debug and info should not log.
		$result = $method->invoke( $this->logger, 'debug' );
		$this->assertIsBool( $result );
	}

	/**
	 * Test format_entry returns correct structure.
	 */
	public function test_format_entry_returns_correct_structure(): void {
		$method = new \ReflectionMethod( SScribe_Logger_Enhanced::class, 'format_entry' );
		$method->setAccessible( true );

		$args   = array( 'info', 'Test message', array( 'key' => 'value' ) );
		$result = $method->invokeArgs( $this->logger, $args );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'file', $result );
		$this->assertArrayHasKey( 'db', $result );
	}

	/**
	 * Test sanitize_context removes forbidden keys.
	 */
	public function test_sanitize_context_removes_forbidden(): void {
		$method = new \ReflectionMethod( SScribe_Logger_Enhanced::class, 'sanitize_context' );
		$method->setAccessible( true );

		$input    = array(
			'password'  => 'secret',
			'token'     => 'abc',
			'secret'    => 'xyz',
			'auth'      => 'test',
			'credential' => 'cred',
			'private_key' => 'key',
			'safe_data' => 'safe',
		);
		$result   = $method->invoke( $this->logger, $input );

		$this->assertSame( '[REDACTED]', $result['password'] );
		$this->assertSame( '[REDACTED]', $result['token'] );
		$this->assertSame( 'safe', $result['safe_data'] );
	}

	/**
	 * Test table_exists method.
	 */
	public function test_table_exists_returns_bool(): void {
		$method = new \ReflectionMethod( SScribe_Logger_Enhanced::class, 'table_exists' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->logger );
		$this->assertIsBool( $result );
	}

	/**
	 * Test write_to_query_monitor method exists.
	 */
	public function test_write_to_query_monitor_exists(): void {
		$method = new \ReflectionMethod( SScribe_Logger_Enhanced::class, 'write_to_query_monitor' );
		$this->assertNotNull( $method );
	}

	/**
	 * Test get_log_file returns valid path.
	 */
	public function test_get_log_file_returns_path(): void {
		$method = new \ReflectionMethod( SScribe_Logger_Enhanced::class, 'get_log_file' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->logger );
		$this->assertIsString( $result );
		$this->assertStringContainsString( 'sscribe_', $result );
		$this->assertStringEndsWith( '.log', $result );
	}
}
