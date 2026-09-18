<?php
/**
 * SScribe Vendor Bootstrap coverage test.
 *
 * Targets:
 *   - is_available() : returns bool
 *   - require()      : idempotent loader, returns bool
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;

if ( ! class_exists( '\\SScribe_Vendor_Bootstrap', false ) ) {
	require_once SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-vendor-bootstrap.php';
}

final class SScribe_Vendor_Bootstrap_Coverage_Test extends TestCase {

	private ReflectionClass $ref;

	protected function setUp(): void {
		parent::setUp();
		$this->ref = new ReflectionClass( \SScribe_Vendor_Bootstrap::class );

		// Reset $loaded to false between tests so each can re-exercise require().
		$prop = $this->ref->getProperty( 'loaded' );
		$prop->setValue( null, false );
	}

	private function reset_loaded(): void {
		$prop = $this->ref->getProperty( 'loaded' );
		$prop->setValue( null, false );
	}

	public function test_is_available_returns_true_when_autoloader_present(): void {
		// vendor/autoload.php is present in the unit env.
		$result = \SScribe_Vendor_Bootstrap::is_available();
		$this::assertIsBool( $result );
		$this::assertTrue( $result );
	}

	public function test_require_returns_bool_and_loads(): void {
		$result = \SScribe_Vendor_Bootstrap::require();
		$this::assertIsBool( $result );
		// vendor-prefixed/autoload.php takes priority in shipping layout.
		$this::assertTrue( $result );
		// Constant must be defined after a successful require.
		$this::assertTrue( defined( 'SSCRIBE_VENDOR_AUTOLOADED' ) );
	}

	public function test_require_is_idempotent_when_already_loaded(): void {
		\SScribe_Vendor_Bootstrap::require();
		$this->reset_loaded(); // forcibly reset to test the early-return path
		// Manually set $loaded back to true so the early-return branch fires.
		$prop = $this->ref->getProperty( 'loaded' );
		$prop->setValue( null, true );

		$result = \SScribe_Vendor_Bootstrap::require();
		// Early-return when $loaded && constant is set => true.
		$this::assertTrue( $result );
	}

	public function test_class_has_expected_methods(): void {
		$this::assertTrue( method_exists( \SScribe_Vendor_Bootstrap::class, 'is_available' ) );
		$this::assertTrue( method_exists( \SScribe_Vendor_Bootstrap::class, 'require' ) );
	}
}
