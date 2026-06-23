<?php
/**
 * SScribe Batch Session Helpers Trait unit test
 *
 * Locks in the shared rate-limit / capability helpers used by
 * SScribe_Session via the SScribe_Session_AJAX trait.
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once SSCRIBE_PLUGIN_DIR . 'includes/traits/trait-sscribe-batch-session-helpers.php';

class SScribe_Batch_Session_Helpers_Trait_Test extends TestCase {

	/**
	 * The trait must expose both `get_required_capability()` and
	 * `check_rate_limit()` to the using class. Verify via reflection
	 * (the methods are protected, so we cannot call them from the
	 * test class directly).
	 */
	public function test_trait_methods_are_inherited_by_using_class(): void {
		$ref = new \ReflectionClass( SScribe_Batch_Session_Helpers_Stub::class );

		$this->assertTrue(
			$ref->hasMethod( 'get_required_capability' ),
			'get_required_capability() is missing from the using class'
		);
		$this->assertTrue(
			$ref->hasMethod( 'check_rate_limit' ),
			'check_rate_limit() is missing from the using class'
		);

		// The methods come from the trait and are inherited by the
		// using class. PHP reports them on the using class itself
		// (traits are inlined), so we just confirm the names exist.
		$this->assertNotNull( $ref->getMethod( 'get_required_capability' ) );
		$this->assertNotNull( $ref->getMethod( 'check_rate_limit' ) );
	}
}

/**
 * Minimal stub used by the trait test. Composes the trait and
 * supplies a no-op `get_rate_limiter()` to satisfy the trait's
 * dependency.
 */
class SScribe_Batch_Session_Helpers_Stub {
	use \SScribe_Batch_Session_Helpers;

	private function get_rate_limiter(): \SScribe_Export_Rate_Limiter {
		return new \SScribe_Export_Rate_Limiter();
	}
}
