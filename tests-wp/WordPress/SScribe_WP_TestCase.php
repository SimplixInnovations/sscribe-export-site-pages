<?php
/**
 * Compatibility base class for SScribe WP-integration tests.
 *
 * Extends wp-phpunit's WP_UnitTestCase and adds:
 *   - `getName( bool $with_args = true )` — was removed in PHPUnit 11
 *     but wp-phpunit's abstract-testcase.php still calls it from
 *     expectDeprecated(). This shim returns the same value PHPUnit 9's
 *     getName() did so legacy annotations parsing paths keep working.
 *   - `dataName()` mirror so data-provider-aware tests keep functioning.
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

/**
 * Base class all SScribe real-WordPress integration tests should extend.
 *
 * Using this instead of WP_UnitTestCase directly means the PHPUnit 11
 * compat shims are available automatically — no per-test `use` or
 * method overrides.
 */
abstract class SScribe_WP_TestCase extends WP_UnitTestCase {

	/**
	 * Returns the currently executing test method's name.
	 *
	 * PHPUnit <= 9 signature: `getName( bool $with_args = true )`.
	 * PHPUnit 11 has `name()` instead. We accept the optional bool to
	 * keep calls like `$this->getName( false )` working without warnings.
	 *
	 * @param bool $with_args Whether to include the data-set suffix. We ignore it.
	 * @return string The current test method name.
	 */
	public function getName( bool $with_args = true ): string {
		$method = (string) $this->name();
		if ( ! $with_args ) {
			return $method;
		}
		$ds = '';
		if ( method_exists( $this, 'dataName' ) ) {
			$name = (string) $this->dataName();
			if ( '' !== $name ) {
				$ds = '(' . $name . ')';
			}
		}
		return '' === $ds ? $method : $method . $ds;
	}

	protected function checkRequirements(): void {
		\PHPUnit\Framework\TestCase::checkRequirements();
	}
}
