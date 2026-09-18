<?php
/**
 * SScribe Filesystem extra-coverage test
 *
 * Targets the helpers that the existing filesystem tests do not exercise:
 *
 *   - is_within_allowed_directory() : real + non-existing roots
 *   - dirlist() : empty dir, missing dir, dotfile skip
 *   - get_last_error() : cleared on new op, populated on failure
 *   - get_method() / is_wp_filesystem()
 *   - copy() / move() : destination outside scope is rejected
 *   - exists() / is_dir() / is_writable() with non-existing path
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

if ( ! class_exists( '\\SScribe_Filesystem', false ) ) {
	require_once SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-filesystem.php';
}

final class SScribe_Filesystem_Extra_Coverage_Test extends TestCase {

	private string $temp_dir;

	protected function setUp(): void {
		parent::setUp();
		$this->temp_dir = sys_get_temp_dir() . '/sscribe_fs_extra_' . uniqid();
		mkdir( $this->temp_dir, 0755, true );
	}

	protected function tearDown(): void {
		if ( is_dir( $this->temp_dir ) ) {
			foreach ( glob( $this->temp_dir . '/*' ) as $f ) {
				@unlink( $f );
			}
			@rmdir( $this->temp_dir );
		}
		parent::tearDown();
	}

	public function test_get_method_returns_string(): void {
		$fs = new \SScribe_Filesystem();
		$this::assertIsString( $fs->get_method() );
	}

	public function test_is_wp_filesystem_returns_bool(): void {
		$fs = new \SScribe_Filesystem();
		$this::assertIsBool( $fs->is_wp_filesystem() );
	}

	public function test_get_last_error_is_string_after_init(): void {
		// Reset the static $last_error so we can assert empty-on-init deterministically
		// (other tests in the suite share this static state).
		$ref = new \ReflectionClass( '\\SScribe_Filesystem' );
		$p   = $ref->getProperty( 'last_error' );
		$p->setValue( null, '' );

		$fs = new \SScribe_Filesystem();
		$this::assertSame( '', $fs->get_last_error() );
	}

	public function test_exists_returns_true_for_real_dir(): void {
		$fs = new \SScribe_Filesystem();
		$this::assertTrue( $fs->exists( $this->temp_dir ) );
	}

	public function test_exists_returns_false_for_nonexistent_path(): void {
		$fs = new \SScribe_Filesystem();
		$this::assertFalse( $fs->exists( $this->temp_dir . '/missing-' . uniqid() ) );
	}

	public function test_is_dir_returns_true_for_real_dir(): void {
		$fs = new \SScribe_Filesystem();
		$this::assertTrue( $fs->is_dir( $this->temp_dir ) );
	}

	public function test_is_dir_returns_false_for_nonexistent_path(): void {
		$fs = new \SScribe_Filesystem();
		$this::assertFalse( $fs->is_dir( $this->temp_dir . '/missing-' . uniqid() ) );
	}

	public function test_is_writable_returns_true_for_writable_dir(): void {
		$fs = new \SScribe_Filesystem();
		$this::assertTrue( $fs->is_writable( $this->temp_dir ) );
	}

	public function test_is_writable_returns_boolean_for_nonexistent_path(): void {
		$fs = new \SScribe_Filesystem();
		// PHP's is_writable returns true on Windows for missing paths inside
		// existing dirs; either result is acceptable, just need to exercise
		// the code path.
		$this::assertIsBool( $fs->is_writable( $this->temp_dir . '/missing-' . uniqid() ) );
	}

	public function test_dirlist_returns_empty_for_empty_dir(): void {
		$fs = new \SScribe_Filesystem();
		$result = $fs->dirlist( $this->temp_dir );
		$this::assertIsArray( $result );
		$this::assertEmpty( $result );
	}

	public function test_dirlist_returns_false_for_nonexistent_path(): void {
		$fs = new \SScribe_Filesystem();
		$this::assertFalse( $fs->dirlist( $this->temp_dir . '/missing-' . uniqid() ) );
	}

	public function test_dirlist_skips_dotfiles_and_index_php(): void {
		$fs = new \SScribe_Filesystem();
		// Create dotfile, regular file, and an index.php in temp_dir.
		file_put_contents( $this->temp_dir . '/.hidden', 'x' );
		file_put_contents( $this->temp_dir . '/visible.txt', 'x' );
		file_put_contents( $this->temp_dir . '/index.php', '<?php' );

		$result = $fs->dirlist( $this->temp_dir );
		$this::assertIsArray( $result );
		$this::assertArrayHasKey( 'visible.txt', $result );
		$this::assertArrayNotHasKey( '.hidden', $result );
		$this::assertArrayNotHasKey( 'index.php', $result );
	}

	public function test_is_within_allowed_directory_returns_false_for_empty_inputs(): void {
		$fs = new \SScribe_Filesystem();
		$this::assertFalse( $fs->is_within_allowed_directory( '', $this->temp_dir ) );
		$this::assertFalse( $fs->is_within_allowed_directory( $this->temp_dir, '' ) );
		$this::assertFalse( $fs->is_within_allowed_directory( '', '' ) );
	}

	public function test_is_within_allowed_directory_returns_true_when_path_under_root(): void {
		$fs = new \SScribe_Filesystem();
		$sub = $this->temp_dir . '/sub';
		mkdir( $sub, 0755, true );
		file_put_contents( $sub . '/file.txt', 'x' );
		$this::assertTrue( $fs->is_within_allowed_directory( $sub . '/file.txt', $this->temp_dir ) );
	}

	public function test_is_within_allowed_directory_returns_false_when_path_outside_root(): void {
		$fs = new \SScribe_Filesystem();
		$other = sys_get_temp_dir() . '/other_' . uniqid();
		mkdir( $other, 0755, true );
		file_put_contents( $other . '/file.txt', 'x' );

		$this::assertFalse( $fs->is_within_allowed_directory( $other . '/file.txt', $this->temp_dir ) );
		@unlink( $other . '/file.txt' );
		@rmdir( $other );
	}

	public function test_copy_rejects_destination_outside_scope(): void {
		$fs = new \SScribe_Filesystem();
		// Source in scope, destination way outside: should reject.
		file_put_contents( $this->temp_dir . '/src.txt', 'x' );
		$result = $fs->copy( $this->temp_dir . '/src.txt', sys_get_temp_dir() . '/evil-' . uniqid() . '.txt' );
		$this::assertFalse( $result );
		$this::assertNotSame( '', $fs->get_last_error() );
	}

	public function test_move_rejects_when_destination_outside_scope(): void {
		$fs = new \SScribe_Filesystem();
		// Source doesn't exist; check that rejection happens.
		$result = $fs->move( sys_get_temp_dir() . '/nope-' . uniqid() . '.txt', sys_get_temp_dir() . '/other-' . uniqid() . '.txt' );
		// Move should not silently succeed when src is outside scope.
		$this::assertFalse( $result );
	}
}
