<?php
/**
 * SScribe Filesystem WP_Filesystem branch coverage test
 *
 * Targets the WP_Filesystem-injected branches of SScribe_Filesystem:
 *
 *   - get_method()         : returns class name when fs is injected
 *   - is_wp_filesystem()   : returns true when fs is injected
 *   - put_contents()       : uses WP_Filesystem path when injected
 *   - get_contents()       : uses WP_Filesystem path when injected
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;

if ( ! class_exists( '\\SScribe_Filesystem', false ) ) {
	require_once SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-filesystem.php';
}

// Test-only WP_Filesystem subclass.
class Fake_WP_Filesystem_For_Branch_Test extends \WP_Filesystem_Base {
	public function __construct() {}
	public function put_contents( $file, $contents, $mode = false ): bool { return true; }
	public function get_contents( $file ): string|false { return 'wp-fs-content'; }
	public function dirlist( $path, $include_hidden = true, $recursive = false ): array|false { return array(); }
	public function delete( $file, $recursive = false, $type = false ): bool { return true; }
	public function exists( $path ): bool { return true; }
	public function is_dir( $path ): bool { return false; }
	public function is_file( $path ): bool { return true; }
	public function mkdir( $path, $chmod = false, $chown = false, $chgrp = false ): bool { return true; }
	public function rmdir( $path, $recursive = false ): bool { return true; }
	public function touch( $file, $time = 0, $atime = 0 ): bool { return true; }
	public function copy( $source, $destination, $overwrite = false, $mode = false ): bool { return true; }
	public function move( $source, $destination, $overwrite = false ): bool { return true; }
	public function chmod( $file, $mode = false, $recursive = false ): bool { return true; }
	public function owner( $file ): string|false { return 'wp-fs-owner'; }
	public function group( $file ): string|false { return 'wp-fs-group'; }
	public function size( $file ): int|false { return 0; }
	public function mtime( $file ): int|false { return 0; }
	public function atime( $file ): int|false { return 0; }
}

final class SScribe_Filesystem_WP_Branch_Test extends TestCase {

	private ReflectionClass $ref;
	private \SScribe_Filesystem $fs;

	protected function setUp(): void {
		parent::setUp();
		// Reset static $fs to null so we can inject it.
		$this->ref = new ReflectionClass( '\\SScribe_Filesystem' );
		$prop_fs = $this->ref->getProperty( 'fs' );
		$prop_fs->setAccessible( true );
		$prop_fs->setValue( null, null );

		$prop_err = $this->ref->getProperty( 'last_error' );
		$prop_err->setAccessible( true );
		$prop_err->setValue( null, '' );

		$this->fs = new \SScribe_Filesystem();
	}

	protected function tearDown(): void {
		// Always restore to null so other tests are unaffected.
		if ( $this->ref->hasProperty( 'fs' ) ) {
			$prop_fs = $this->ref->getProperty( 'fs' );
			$prop_fs->setAccessible( true );
			$prop_fs->setValue( null, null );
		}
		parent::tearDown();
	}

	private function inject_wp_filesystem(): void {
		$prop_fs = $this->ref->getProperty( 'fs' );
		$prop_fs->setValue( null, new Fake_WP_Filesystem_For_Branch_Test() );
	}

	public function test_is_wp_filesystem_returns_true_when_injected(): void {
		$this->inject_wp_filesystem();
		$this::assertTrue( $this->fs->is_wp_filesystem() );
	}

	public function test_get_method_returns_class_when_injected(): void {
		$this->inject_wp_filesystem();
		$result = $this->fs->get_method();
		$this::assertSame( Fake_WP_Filesystem_For_Branch_Test::class, $result );
	}

	public function test_put_contents_uses_wp_filesystem_when_injected(): void {
		$this->inject_wp_filesystem();
		$export_dir = \SScribe_Private_Storage::get_export_dir();
		if ( '' === $export_dir ) {
			$this::markTestSkipped( 'export dir unavailable in unit env' );
		}
		$result = $this->fs->put_contents( $export_dir . '/anywhere.txt', 'data' );
		$this::assertTrue( $result );
	}

	public function test_dirlist_uses_wp_filesystem_when_injected(): void {
		$this->inject_wp_filesystem();
		$export_dir = \SScribe_Private_Storage::get_export_dir();
		if ( '' === $export_dir ) {
			$this::markTestSkipped( 'export dir unavailable in unit env' );
		}
		$result = $this->fs->dirlist( $export_dir );
		$this::assertIsArray( $result );
	}

	public function test_get_contents_short_circuits_on_safety_reject(): void {
		// Documents the safety pre-check: even with WP_Filesystem injected,
		// reads outside the export dir are rejected before delegation.
		$this->inject_wp_filesystem();
		$result = $this->fs->get_contents( '/etc/passwd' );
		$this::assertFalse( $result );
	}
}
