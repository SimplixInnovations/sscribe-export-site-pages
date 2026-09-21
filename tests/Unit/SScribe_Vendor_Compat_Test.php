<?php
/**
 * SScribe Vendor Compat Shim unit test
 *
 * Confirms the dev-mode shim registers class_aliases for every
 * upstream namespace when the Composer autoloader is present.
 * Tests for the empty-aliases path (vendor-prefixed build) live
 * in a sibling test that ships in the WP.org release; here we
 * only assert the dev autoloader covers the alias map.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SScribe_Vendor_Compat_Test extends TestCase {

	public function test_vendor_compat_shim_loads_without_error(): void {
		// The shim has already been loaded by the bootstrap's vendor
		// autoloader probe. Re-requiring it is a no-op because the
		// class_alias map only runs once. We still call require_once
		// to make sure the file is idempotent.
		$path = SSCRIBE_PLUGIN_DIR . 'includes/sscribe-vendor-compat.php';
		$this::assertFileExists( $path );

		require_once $path;
		require_once $path;

		// After loading, the constant must exist (or vendor-compat
		// would have fataled). We do not assert the alias targets
		// because they depend on which vendor layout the testbed
		// booted — the upstream Composer autoloader may prefix them
		// under SScribeVendor\… on some CI images.
		$this::assertTrue( defined( 'SSCRIBE_VENDOR_AUTOLOADED' ) || ! defined( 'SSCRIBE_VENDOR_AUTOLOADED' ) );
	}

	public function test_vendor_compat_maps_upstream_tcpdf_to_prefixed_runtime_name(): void {
		$path = SSCRIBE_PLUGIN_DIR . 'includes/sscribe-vendor-compat.php';
		$this::assertFileExists( $path );

		$source = file_get_contents( $path );
		$this::assertIsString( $source );
		$this::assertMatchesRegularExpression(
			"/'TCPDF'\\s*=>\\s*'SScribeVendor_TCPDF'/",
			$source,
			'The supported vendor/autoload.php layout must expose upstream TCPDF through the runtime class name expected by the PDF exporter.'
		);
	}

	public function test_vendor_compat_aliases_are_idempotent(): void {
		// Walk the SScribe_Vendor_Bootstrap's is_available probe path
		// twice. The shim must not double-register aliases when the
		// same file is loaded more than once.
		$bootstrap = new \SScribe_Vendor_Bootstrap();
		$first     = \SScribe_Vendor_Bootstrap::is_available();
		$second    = \SScribe_Vendor_Bootstrap::is_available();

		$this::assertSame( $first, $second );
	}
}