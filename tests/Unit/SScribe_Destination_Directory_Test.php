<?php
/**
 * Unit tests for SScribe_Destination_Directory.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SScribe_Destination_Directory_Test extends TestCase {

	private string $root = '';

	private string $target = '';

	private string $zip = '';

	protected function setUp(): void {
		parent::setUp();
		$this->root   = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sscribe-dest-dir-' . bin2hex( random_bytes( 4 ) );
		$this->target = $this->root . DIRECTORY_SEPARATOR . 'out';
		mkdir( $this->target, 0700, true );
		$this->zip = $this->root . DIRECTORY_SEPARATOR . 'source.zip';
		file_put_contents( $this->zip, str_repeat( 'zipdata', 100 ) );
	}

	protected function tearDown(): void {
		$this->remove( $this->root );
		parent::tearDown();
	}

	public function test_valid_directory_is_resolved(): void {
		$result = ( new \SScribe_Destination_Directory() )->validate( array( 'path' => $this->target . DIRECTORY_SEPARATOR ) );

		$this::assertTrue( $result->is_success(), (string) $result->get_error() );
		$this::assertSame( realpath( $this->target ), $result->get_data()['path'] );
		$this::assertFalse( $result->get_data()['overwrite'] );
	}

	public function test_relative_missing_and_empty_paths_are_rejected(): void {
		$destination = new \SScribe_Destination_Directory();

		$this::assertStringContainsString( 'absolute', (string) $destination->validate( array( 'path' => 'relative/dir' ) )->get_error() );
		$this::assertStringContainsString( 'does not exist', (string) $destination->validate( array( 'path' => $this->root . DIRECTORY_SEPARATOR . 'missing' ) )->get_error() );
		$this::assertTrue( $destination->validate( array() )->is_failure() );
		$this::assertTrue( $destination->validate( array( 'path' => $this->zip ) )->is_failure() );
	}

	public function test_plugin_directory_is_rejected(): void {
		$result = ( new \SScribe_Destination_Directory() )->validate( array( 'path' => SSCRIBE_PLUGIN_DIR . 'includes' ) );

		$this::assertStringContainsString( 'plugin folder', (string) $result->get_error() );
	}

	public function test_private_storage_is_rejected(): void {
		$export_dir = \SScribe_Private_Storage::get_export_dir( true );
		$this::assertNotSame( '', $export_dir );

		$result = ( new \SScribe_Destination_Directory() )->validate( array( 'path' => $export_dir ) );

		$this::assertStringContainsString( 'private storage', (string) $result->get_error() );
	}

	public function test_symlinked_directory_is_rejected(): void {
		$link = $this->root . DIRECTORY_SEPARATOR . 'link';
		if ( ! @symlink( $this->target, $link ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$this::markTestSkipped( 'Symbolic links are not available here.' );
		}

		$result = ( new \SScribe_Destination_Directory() )->validate( array( 'path' => $link ) );

		$this::assertStringContainsString( 'symbolic link', (string) $result->get_error() );
	}

	public function test_delivery_copies_the_archive_under_the_export_name_without_leftovers(): void {
		$result = ( new \SScribe_Destination_Directory() )->deliver( $this->zip, array( 'filename' => 'site-export.zip' ), array( 'path' => $this->target ) );

		$final = realpath( $this->target ) . DIRECTORY_SEPARATOR . 'site-export.zip';
		$this::assertTrue( $result->is_success(), (string) $result->get_error() );
		$this::assertSame( $final, $result->get_data() );
		$this::assertSame( file_get_contents( $this->zip ), file_get_contents( $final ) );
		$this::assertSame( array( 'site-export.zip' ), array_values( array_diff( scandir( $this->target ), array( '.', '..' ) ) ) );
	}

	public function test_existing_file_is_kept_unless_overwrite_is_set(): void {
		$existing = $this->target . DIRECTORY_SEPARATOR . 'site-export.zip';
		file_put_contents( $existing, 'old' );
		$destination = new \SScribe_Destination_Directory();

		$refused = $destination->deliver( $this->zip, array( 'filename' => 'site-export.zip' ), array( 'path' => $this->target ) );

		$this::assertTrue( $refused->is_failure() );
		$this::assertStringContainsString( 'already exists', (string) $refused->get_error() );
		$this::assertSame( 'old', file_get_contents( $existing ) );

		$replaced = $destination->deliver(
			$this->zip,
			array( 'filename' => 'site-export.zip' ),
			array(
				'path'      => $this->target,
				'overwrite' => true,
			)
		);

		$this::assertTrue( $replaced->is_success(), (string) $replaced->get_error() );
		$this::assertSame( file_get_contents( $this->zip ), file_get_contents( $existing ) );
	}

	public function test_missing_archive_fails_cleanly(): void {
		$result = ( new \SScribe_Destination_Directory() )->deliver( $this->root . '/gone.zip', array( 'filename' => 'gone.zip' ), array( 'path' => $this->target ) );

		$this::assertStringContainsString( 'missing', (string) $result->get_error() );
	}

	public function test_unsafe_filename_in_context_is_replaced(): void {
		$result = ( new \SScribe_Destination_Directory() )->deliver( $this->zip, array( 'filename' => '../escape.zip' ), array( 'path' => $this->target ) );

		$this::assertTrue( $result->is_success() );
		$this::assertSame( realpath( $this->target ), dirname( (string) $result->get_data() ) );
		$this::assertMatchesRegularExpression( '/^sscribe-export-\d{8}-\d{6}\.zip$/', basename( (string) $result->get_data() ) );
	}

	private function remove( string $path ): void {
		if ( is_link( $path ) || is_file( $path ) ) {
			@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return;
		}
		if ( ! is_dir( $path ) ) {
			return;
		}
		foreach ( array_diff( (array) scandir( $path ), array( '.', '..' ) ) as $child ) {
			$this->remove( $path . DIRECTORY_SEPARATOR . $child );
		}
		@rmdir( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}
}
