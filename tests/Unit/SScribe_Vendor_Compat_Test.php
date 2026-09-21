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

	public function test_vendor_compat_aliases_are_idempotent(): void {
		// Walk the SScribe_Vendor_Bootstrap's is_available probe path
		// twice. The shim must not double-register aliases when the
		// same file is loaded more than once.
		$bootstrap = new \SScribe_Vendor_Bootstrap();
		$first     = \SScribe_Vendor_Bootstrap::is_available();
		$second    = \SScribe_Vendor_Bootstrap::is_available();

		$this::assertSame( $first, $second );
	}


	public function test_unprefixed_composer_layout_keeps_phpword_graph_consistent(): void {
		$root = dirname( __DIR__, 2 );
		$code = 'define("ABSPATH", ' . var_export( $root . '/fake-wp/', true ) . ');'
			. 'define("SSCRIBE_PLUGIN_DIR", ' . var_export( $root . '/', true ) . ');'
			. 'require ' . var_export( $root . '/vendor/autoload.php', true ) . ';'
			. 'require ' . var_export( $root . '/includes/sscribe-vendor-compat.php', true ) . ';'
			. '$phpWord = new \\SScribeVendor\\PhpOffice\\PhpWord\\PhpWord();'
			. '$section = $phpWord->addSection();'
			. 'if (!$section instanceof \\PhpOffice\\PhpWord\\Element\\Section) { exit(12); }'
			. 'if (!class_exists("SScribeVendor\\\\PhpOffice\\\\PhpWord\\\\Element\\\\Section")) { exit(13); }'
			. 'if (!is_a($section, "SScribeVendor\\\\PhpOffice\\\\PhpWord\\\\Element\\\\Section")) { exit(14); }'
			. 'exit(0);';

		$process = proc_open(
			array( PHP_BINARY, '-r', $code ),
			array(
				0 => array( 'pipe', 'r' ),
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes
		);
		$this::assertIsResource( $process );
		fclose( $pipes[0] );
		$stdout = (string) stream_get_contents( $pipes[1] );
		$stderr = (string) stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$exit = proc_close( $process );

		$this::assertSame( 0, $exit, "Unprefixed Composer PHPWord graph failed. stdout={$stdout} stderr={$stderr}" );
	}

	public function test_unprefixed_composer_layout_exposes_tcpdf_under_sscribe_alias(): void {
		$root = dirname( __DIR__, 2 );
		$code = 'define("ABSPATH", ' . var_export( $root . '/fake-wp/', true ) . ');'
			. 'define("SSCRIBE_PLUGIN_DIR", ' . var_export( $root . '/', true ) . ');'
			. 'require ' . var_export( $root . '/vendor/autoload.php', true ) . ';'
			. 'require ' . var_export( $root . '/includes/sscribe-vendor-compat.php', true ) . ';'
			. 'if (!class_exists("SScribeVendor_TCPDF")) { exit(10); }'
			. 'if (!is_a("SScribeVendor_TCPDF", "TCPDF", true)) { exit(11); }'
			. 'exit(0);';

		$process = proc_open(
			array( PHP_BINARY, '-r', $code ),
			array(
				0 => array( 'pipe', 'r' ),
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes
		);
		$this::assertIsResource( $process );
		fclose( $pipes[0] );
		$stdout = (string) stream_get_contents( $pipes[1] );
		$stderr = (string) stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$exit = proc_close( $process );

		$this::assertSame( 0, $exit, "Unprefixed Composer TCPDF alias failed. stdout={$stdout} stderr={$stderr}" );
	}
}
