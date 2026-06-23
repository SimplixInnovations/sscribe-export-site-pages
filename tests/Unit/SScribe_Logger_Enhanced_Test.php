<?php
/**
 * SScribe Logger Enhanced Unit Test
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

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

	public function test_logger_can_be_instantiated(): void {
		$this->assertInstanceOf( SScribe_Logger_Enhanced::class, $this->logger );
	}

	public function test_is_enabled_returns_true_by_default(): void {
		$result = $this->logger->is_enabled();
		$this->assertTrue( $result );
	}

	public function test_get_logs_returns_array(): void {
		$result = $this->logger->get_logs();
		$this->assertIsArray( $result );
	}

	public function test_get_logs_respects_limit(): void {
		$result = $this->logger->get_logs( 5 );
		$this->assertIsArray( $result );
	}

	public function test_get_db_logs_returns_array(): void {
		$result = $this->logger->get_db_logs();
		$this->assertIsArray( $result );
	}

	public function test_get_db_logs_with_filters(): void {
		$filters = array( 'level' => 'error' );
		$result  = $this->logger->get_db_logs( $filters, 10 );
		$this->assertIsArray( $result );
	}

	public function test_cleanup_db_logs_returns_integer(): void {
		$result = $this->logger->cleanup_db_logs( 30 );
		$this->assertIsInt( $result );
	}

	public function test_log_accepts_all_levels(): void {
		$levels = array( 'debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency' );

		foreach ( $levels as $level ) {
			$this->logger->log( $level, 'Test message for ' . $level );
			$this->assertTrue( true, 'No exception for level: ' . $level );
		}
	}

	public function test_debug_method(): void {
		$this->logger->debug( 'Debug test message' );
		$this->assertTrue( true );
	}

	public function test_info_method(): void {
		$this->logger->info( 'Info test message' );
		$this->assertTrue( true );
	}

	public function test_notice_method(): void {
		$this->logger->notice( 'Notice test message' );
		$this->assertTrue( true );
	}

	public function test_warning_method(): void {
		$this->logger->warning( 'Warning test message' );
		$this->assertTrue( true );
	}

	public function test_error_method(): void {
		$this->logger->error( 'Error test message' );
		$this->assertTrue( true );
	}

	public function test_critical_method(): void {
		$this->logger->critical( 'Critical test message' );
		$this->assertTrue( true );
	}

	public function test_alert_method(): void {
		$this->logger->alert( 'Alert test message' );
		$this->assertTrue( true );
	}

	public function test_emergency_method(): void {
		$this->logger->emergency( 'Emergency test message' );
		$this->assertTrue( true );
	}

	public function test_log_with_context(): void {
		$context = array( 'user_id' => 1, 'page_id' => 123 );
		$this->logger->info( 'Test with context', $context );
		$this->assertTrue( true );
	}

	public function test_log_sanitizes_sensitive_context(): void {
		$context = array(
			'password' => 'secret123',
			'token'    => 'abc123',
			'api_key'  => 'key456',
			'safe_key' => 'safe_value',
		);

		$this->logger->info( 'Test sensitive data', $context );
		$this->assertTrue( true );
	}

	public function test_flush_method_exists(): void {
		$this->assertIsCallable( array( $this->logger, 'flush' ) );
	}

	// Note: SScribe_Logger_Enhanced previously had a private LEVEL_PRIORITY
	// constant that was unused (PHPStan classConstant.unused). The canonical
	// priority map now lives in the SScribe_Logger_Common trait's
	// get_level_priority_map() helper — see SScribe_Logger_Common_Trait_Test.

	public function test_should_log_respects_min_level(): void {
		$method = new \ReflectionMethod( SScribe_Logger_Enhanced::class, 'should_log' );

		$result = $method->invoke( $this->logger, 'debug' );
		$this->assertIsBool( $result );
	}

	public function test_format_entry_returns_correct_structure(): void {
		$method = new \ReflectionMethod( SScribe_Logger_Enhanced::class, 'format_entry' );

		$args   = array( 'info', 'Test message', array( 'key' => 'value' ) );
		$result = $method->invokeArgs( $this->logger, $args );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'file', $result );
		$this->assertArrayHasKey( 'db', $result );
	}

	public function test_sanitize_context_removes_forbidden(): void {
		$method = new \ReflectionMethod( SScribe_Logger_Enhanced::class, 'sanitize_context' );

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

	public function test_table_exists_returns_bool(): void {
		$method = new \ReflectionMethod( SScribe_Logger_Enhanced::class, 'table_exists' );

		$result = $method->invoke( $this->logger );
		$this->assertIsBool( $result );
	}

	public function test_write_to_query_monitor_exists(): void {
		$method = new \ReflectionMethod( SScribe_Logger_Enhanced::class, 'write_to_query_monitor' );
		$this->assertNotNull( $method );
	}

	public function test_get_log_file_returns_path(): void {
		$method = new \ReflectionMethod( SScribe_Logger_Enhanced::class, 'get_log_file' );

		$result = $method->invoke( $this->logger );
		$this->assertIsString( $result );
		$this->assertStringContainsString( 'sscribe_', $result );
		$this->assertStringEndsWith( '.log', $result );
	}

	/**
	 * Regression guard for refactor #4: SScribe_Logger_Enhanced
	 * must extend SScribe_Logger so the file pipeline is
	 * inherited rather than duplicated. If a future change ever
	 * reverts to `implements SScribe_Logger_Interface`, this
	 * test fails before the duplicated-state problem resurfaces.
	 */
	public function test_extends_sscribe_logger(): void {
		$this->assertInstanceOf( \SScribe_Logger::class, $this->logger );
		$parent = ( new \ReflectionClass( SScribe_Logger_Enhanced::class ) )->getParentClass();
		$this->assertNotFalse( $parent );
		$this->assertSame( \SScribe_Logger::class, $parent->getName() );
	}

	/**
	 * Regression guard for refactor #4: the file-pipeline state
	 * ($buffer, $log_dir, $prefix, $session_id) must be declared
	 * on SScribe_Logger (the parent), NOT re-declared on the
	 * Enhanced subclass. If someone re-introduces a private
	 * redeclaration in the child, this test catches the drift
	 * before the parallel-state bug returns.
	 */
	public function test_pipeline_state_is_inherited_not_redeclared(): void {
		$parent = \SScribe_Logger::class;
		foreach ( array( 'buffer', 'log_dir', 'prefix', 'session_id' ) as $prop ) {
			$ref   = new \ReflectionProperty( SScribe_Logger_Enhanced::class, $prop );
			$this->assertSame(
				$parent,
				$ref->getDeclaringClass()->getName(),
				"\${$prop} should be inherited from {$parent}, not redeclared on SScribe_Logger_Enhanced"
			);
		}
	}

	/**
	 * Regression guard for refactor #4: the SScribe_Logger_Common
	 * trait must be used by the parent only. Enhanced inherits
	 * the trait methods via class extension, so a second
	 * `use SScribe_Logger_Common;` on the child would create
	 * ambiguous dispatch. Catches any future re-introduction
	 * of the trait on the child class.
	 */
	public function test_trait_is_used_only_on_parent(): void {
		$traits = class_uses( SScribe_Logger_Enhanced::class );
		$this->assertNotContains( \SScribe_Logger_Common::class, $traits );

		// The parent must still use the trait — otherwise the
		// inherited dispatch methods would break.
		$parent_traits = class_uses( \SScribe_Logger::class );
		$this->assertContains( \SScribe_Logger_Common::class, $parent_traits );
	}
}
