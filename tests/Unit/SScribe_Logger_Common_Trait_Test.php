<?php
/**
 * SScribe Logger Common Trait unit test
 *
 * Locks in the shared level dispatch + session-id + threshold helpers
 * that the two logger implementations (SScribe_Logger and
 * SScribe_Logger_Enhanced) inherit via the trait. If a future refactor
 * changes these semantics, this test fails before the real loggers do.
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SScribe_Logger_Interface;

class SScribe_Logger_Common_Trait_Test extends TestCase {

	/**
	 * Both logger implementations should expose the same 8 PSR-3-style
	 * level methods (inherited from the trait).
	 */
	public function test_all_loggers_expose_level_methods(): void {
		$loggers = array(
			new \SScribe_Logger( true, 'trait_test_a' ),
			new \SScribe_Logger_Enhanced(),
		);

		$methods = array( 'debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency' );

		foreach ( $loggers as $logger ) {
			$this->assertInstanceOf( SScribe_Logger_Interface::class, $logger );
			foreach ( $methods as $method ) {
				$this->assertTrue(
					method_exists( $logger, $method ),
					get_class( $logger ) . " is missing shared method '{$method}'"
				);
			}
		}
	}

	/**
	 * `set_session_id()` is now defined once in the trait. Every
	 * logger must accept and store a session id.
	 */
	public function test_set_session_id_works_on_all_loggers(): void {
		$loggers = array(
			new \SScribe_Logger( true, 'trait_test_b' ),
			new \SScribe_Logger_Enhanced(),
		);

		foreach ( $loggers as $logger ) {
			$logger->set_session_id( 'sess-' . uniqid() );
			// If the method didn't error and the property now holds
			// a value, the trait's shared setter is wired correctly.
			// ReflectionProperty::isInitialized() does not require
			// the deprecated setAccessible() call.
			$ref = new \ReflectionProperty( $logger, 'session_id' );
			$this->assertTrue( $ref->isInitialized( $logger ) );
		}
	}

	/**
	 * The shared threshold helper must respect the standard PSR-3
	 * ordering: higher-severity levels pass when min_level is lower.
	 */
	public function test_level_meets_threshold(): void {
		// Error threshold: debug/info/notice should be filtered out;
		// error/critical/alert/emergency should pass.
		$this->assertFalse( self::call_threshold_helper( 'debug', 'error' ) );
		$this->assertFalse( self::call_threshold_helper( 'info', 'error' ) );
		$this->assertFalse( self::call_threshold_helper( 'notice', 'error' ) );
		$this->assertFalse( self::call_threshold_helper( 'warning', 'error' ) );
		$this->assertTrue( self::call_threshold_helper( 'error', 'error' ) );
		$this->assertTrue( self::call_threshold_helper( 'critical', 'error' ) );
		$this->assertTrue( self::call_threshold_helper( 'alert', 'error' ) );
		$this->assertTrue( self::call_threshold_helper( 'emergency', 'error' ) );

		// Debug threshold: everything passes.
		$this->assertTrue( self::call_threshold_helper( 'debug', 'debug' ) );
		$this->assertTrue( self::call_threshold_helper( 'emergency', 'debug' ) );
	}

	/**
	 * The shared priority map must have entries for all 8 PSR-3 levels.
	 */
	public function test_get_level_priority_map_covers_all_levels(): void {
		$map = self::call_priority_helper();
		$this->assertArrayHasKey( 'debug', $map );
		$this->assertArrayHasKey( 'info', $map );
		$this->assertArrayHasKey( 'notice', $map );
		$this->assertArrayHasKey( 'warning', $map );
		$this->assertArrayHasKey( 'error', $map );
		$this->assertArrayHasKey( 'critical', $map );
		$this->assertArrayHasKey( 'alert', $map );
		$this->assertArrayHasKey( 'emergency', $map );

		// Priorities must be strictly increasing in PSR-3 order.
		$this->assertLessThan( $map['info'], $map['debug'] );
		$this->assertLessThan( $map['error'], $map['warning'] );
		$this->assertLessThan( $map['emergency'], $map['alert'] );
	}

	/**
	 * Invoke the protected static `level_meets_threshold` helper via
	 * a Closure bound to the declaring class. Avoids the `setAccessible()`
	 * deprecation that ReflectionMethod::invoke() emits in PHP 8.1+.
	 *
	 * Note: the method lives in SScribe_Logger_Common and is reached
	 * here via inheritance (SScribe_Logger_Enhanced → SScribe_Logger →
	 * SScribe_Logger_Common). Rebind to the actual declaring class
	 * (the trait's using class) so PHP 8.5+ accepts the closure
	 * binding — binding to the lookup class is rejected as a no-op
	 * rebind once the closure's scope is already fixed.
	 */
	private static function call_threshold_helper( string $level, string $min_level ): bool {
		$ref      = new \ReflectionMethod( \SScribe_Logger_Enhanced::class, 'level_meets_threshold' );
		$scope    = $ref->getDeclaringClass()->getName();
		$closure  = \Closure::bind( $ref->getClosure( null ), null, $scope );
		return (bool) $closure( $level, $min_level );
	}

	/**
	 * Invoke the protected static `get_level_priority_map` helper.
	 */
	private static function call_priority_helper(): array {
		$ref      = new \ReflectionMethod( \SScribe_Logger_Enhanced::class, 'get_level_priority_map' );
		$scope    = $ref->getDeclaringClass()->getName();
		$closure  = \Closure::bind( $ref->getClosure( null ), null, $scope );
		return $closure();
	}
}
