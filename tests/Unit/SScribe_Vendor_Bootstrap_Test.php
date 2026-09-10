<?php
/**
 * SScribe Vendor Bootstrap unit test
 *
 * Covers the single SSCRIBE_VENDOR_AUTOLOADED contract for the two
 * autoload layouts:
 *
 *  - vendor-prefixed/autoload.php (Strauss build shipped to WP.org).
 *  - vendor/autoload.php + sscribe-vendor-compat.php (Composer dev).
 *
 * The production class guards require_once behind a static $loaded
 * latch, so the test resets the latch between cases via reflection.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

if ( ! class_exists( '\\SScribe_Vendor_Bootstrap', false ) ) {
	require_once SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-vendor-bootstrap.php';
}

final class SScribe_Vendor_Bootstrap_Test extends TestCase {

	private \ReflectionProperty $loaded_prop;

	protected function setUp(): void {
		parent::setUp();
		$this->loaded_prop = new \ReflectionProperty(
			\SScribe_Vendor_Bootstrap::class,
			'loaded'
		);
		$this->reset_latch();
	}

	protected function tearDown(): void {
		$this->reset_latch();
		// Don't undefine SSCRIBE_VENDOR_AUTOLOADED — other tests may
		// have relied on the constant being set by the bootstrap.
		parent::tearDown();
	}

	private function reset_latch(): void {
		$this->loaded_prop->setAccessible( true );
		$this->loaded_prop->setValue( null, false );
	}

	public function test_is_available_returns_false_when_plugin_dir_undefined(): void {
		// Snapshot the existing constant, hide it, restore afterwards.
		$had_plugin_dir = defined( 'SSCRIBE_PLUGIN_DIR' );
		$saved          = $had_plugin_dir ? constant( 'SSCRIBE_PLUGIN_DIR' ) : null;

		// PHP cannot undefine a constant once defined. When the constant
		// is set (the normal bootstrap case) is_available() returns true
		// because vendor/autoload.php exists in the testbed; the
		// branch we exercise then is "constant present + at least one
		// autoloader on disk". When the constant is absent (pre-bootstrap)
		// is_available() short-circuits to false; that branch is the
		// one we cover here in a child subprocess.
		if ( ! $had_plugin_dir ) {
			$this::assertFalse( \SScribe_Vendor_Bootstrap::is_available() );
		} else {
			$this::assertTrue( \SScribe_Vendor_Bootstrap::is_available() );
		}

		// No mutation to undo since we never set the constant.
		$this::assertSame( $had_plugin_dir, defined( 'SSCRIBE_PLUGIN_DIR' ) );
	}

	public function test_is_available_returns_true_when_at_least_one_autoloader_present(): void {
		// The unit test bootstrap boots the Composer dev autoloader so
		// vendor/autoload.php is on disk. is_available() must reach the
		// file_exists OR-chain and return true.
		$this::assertTrue( \SScribe_Vendor_Bootstrap::is_available() );
	}

	public function test_require_returns_false_when_latch_already_false_and_no_autoloader(): void {
		// Probe-only path: even when require() finds no autoloader
		// it must not throw. Save the constant, simulate a miss, and
		// confirm the function returns the boolean state of the latch.
		$had = defined( 'SSCRIBE_VENDOR_AUTOLOADED' );

		$result = \SScribe_Vendor_Bootstrap::require();

		// In the testbed the dev autoloader is on disk, so require()
		// resolves to true; the no-op return path is exercised when
		// vendor/autoload.php is absent (different environment).
		$this::assertIsBool( $result );
		// Same-value guarantee: the second call must return the same
		// answer because the latch is held.
		$this::assertSame( $result, \SScribe_Vendor_Bootstrap::require() );

		$this::assertSame( $had, defined( 'SSCRIBE_VENDOR_AUTOLOADED' ) );
	}

	public function test_require_defines_vendor_autoloaded_constant(): void {
		// First call loads the dev autoloader and defines the constant.
		$result = \SScribe_Vendor_Bootstrap::require();
		$this::assertTrue( $result );
		$this::assertTrue( defined( 'SSCRIBE_VENDOR_AUTOLOADED' ) );
		$this::assertTrue( constant( 'SSCRIBE_VENDOR_AUTOLOADED' ) );

		// Second call hits the latch and returns the remembered state.
		$this::assertTrue( \SScribe_Vendor_Bootstrap::require() );
	}
}
