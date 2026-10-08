<?php
/**
 * Uploads-based private storage: site key, overrides, migration, archive names.
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class SScribe_Private_Storage_Uploads_Default_Test extends TestCase {

	private const OLD_SEGMENT = 'site-1-0123456789ab';

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['sscribe_test_blog_id'] = 1;
	}

	protected function tearDown(): void {
		unset( $GLOBALS['sscribe_test_blog_id'] );
		parent::tearDown();
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_site_key_is_generated_once_and_persisted(): void {
		delete_option( 'sscribe_storage_key' );

		$this->assertSame( '', \SScribe_Private_Storage::get_site_key( false ) );

		$key = \SScribe_Private_Storage::get_site_key();
		$this->assertMatchesRegularExpression( '/^[a-z0-9]{32}$/', $key );
		$this->assertSame( $key, get_option( 'sscribe_storage_key' ) );
		$this->assertSame( $key, \SScribe_Private_Storage::get_site_key() );
		$this->assertSame( $key, \SScribe_Private_Storage::get_site_key( false ) );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_corrupted_site_key_is_replaced(): void {
		update_option( 'sscribe_storage_key', '../not-a-key' );

		$key = \SScribe_Private_Storage::get_site_key();

		$this->assertMatchesRegularExpression( '/^[a-z0-9]{32}$/', $key );
		$this->assertSame( $key, get_option( 'sscribe_storage_key' ) );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_filter_override_replaces_uploads_and_is_reported_as_override(): void {
		$base = $this->make_temp_dir( 'override' );
		add_filter(
			'sscribe_private_storage_base_candidates',
			static fn(): array => array( $base )
		);

		try {
			$path = \SScribe_Private_Storage::get_export_dir();
			$this->assertSame( 'override', \SScribe_Private_Storage::get_storage_mode() );
			$this->assertStringStartsWith(
				$this->normalize( (string) realpath( $base ) ) . '/sscribe-export-site-pages/',
				$this->normalize( $path )
			);
			foreach ( \SScribe_Security::GUARD_FILES as $guard ) {
				$this->assertFileExists( $path . '/' . $guard );
			}
		} finally {
			$this->remove_tree( $base );
		}
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_previous_storage_is_moved_once_and_removed(): void {
		$base = $this->make_temp_dir( 'migrate' );
		add_filter(
			'sscribe_private_storage_base_candidates',
			static fn(): array => array( $base )
		);
		$old_leaf = $base . '/sscribe-export-site-pages/' . self::OLD_SEGMENT . '/sscribe-exports';
		wp_mkdir_p( $old_leaf . '/logs' );
		file_put_contents( $old_leaf . '/site-export.zip', 'archive bytes' );
		file_put_contents( $old_leaf . '/logs/sscribe_debug.log', 'log line' );
		file_put_contents( $old_leaf . '/.htaccess', 'Deny from all' );
		update_option( 'sscribe_export_index', array( 'site-export.zip' ) );
		delete_option( \SScribe_Storage_Migration::COMPLETED_OPTION );

		try {
			$this->assertTrue( \SScribe_Storage_Migration::run() );

			$target = \SScribe_Private_Storage::get_export_dir();
			$this->assertSame( 'archive bytes', file_get_contents( $target . '/site-export.zip' ) );
			$this->assertSame( 'log line', file_get_contents( $target . '/logs/sscribe_debug.log' ) );
			$this->assertDirectoryDoesNotExist( dirname( $old_leaf ) );
			$this->assertTrue( \SScribe_Storage_Migration::is_complete() );

			wp_mkdir_p( $old_leaf );
			file_put_contents( $old_leaf . '/site-export.zip', 'reappeared' );
			$this->assertTrue( \SScribe_Storage_Migration::run() );
			$this->assertFileExists( $old_leaf . '/site-export.zip', 'A completed migration must never repeat.' );
		} finally {
			$this->remove_tree( $base );
		}
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_unverified_shared_location_is_left_untouched(): void {
		$base = $this->make_temp_dir( 'foreign' );
		add_filter(
			'sscribe_private_storage_base_candidates',
			static fn(): array => array( $base )
		);
		$foreign_leaf = $base . '/sscribe-export-site-pages/' . self::OLD_SEGMENT . '/sscribe-exports';
		wp_mkdir_p( $foreign_leaf );
		file_put_contents( $foreign_leaf . '/other-install.zip', 'not ours' );
		update_option( 'sscribe_export_index', array( 'site-export.zip' ) );
		delete_option( \SScribe_Storage_Migration::COMPLETED_OPTION );

		try {
			$this->assertTrue( \SScribe_Storage_Migration::run() );
			$this->assertFileExists( $foreign_leaf . '/other-install.zip' );
			$this->assertFileDoesNotExist( \SScribe_Private_Storage::get_export_dir() . '/other-install.zip' );
		} finally {
			$this->remove_tree( $base );
		}
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_previous_uploads_fallback_is_moved_without_an_index_match(): void {
		$uploads  = \SScribe_Private_Storage::get_uploads_basedir();
		$old_site = $uploads . '/sscribe-export-site-pages/' . self::OLD_SEGMENT;
		wp_mkdir_p( $old_site . '/sscribe-exports/logs' );
		file_put_contents( $old_site . '/sscribe-exports/logs/export.json', '{}' );
		update_option( 'sscribe_export_index', array() );
		delete_option( \SScribe_Storage_Migration::COMPLETED_OPTION );

		try {
			$this->assertTrue( \SScribe_Storage_Migration::run() );
			$target = \SScribe_Private_Storage::get_export_dir();
			$this->assertFileExists( $target . '/logs/export.json' );
			$this->assertDirectoryDoesNotExist( $old_site );
			$this->assertDirectoryExists( dirname( $target ), 'The shared container keeps the current storage folder.' );
		} finally {
			\SScribe_Private_Storage::delete_owned_storage();
			$this->remove_tree( $old_site );
		}
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_migration_waits_when_current_storage_is_unavailable(): void {
		define( 'SSCRIBE_PRIVATE_STORAGE_DIR', sys_get_temp_dir() . '/sscribe-missing-' . uniqid() );
		delete_option( \SScribe_Storage_Migration::COMPLETED_OPTION );

		$this->assertFalse( \SScribe_Storage_Migration::run() );
		$this->assertFalse( \SScribe_Storage_Migration::is_complete(), 'Migration retries once storage becomes available.' );
	}

	public function test_fallback_archive_name_uses_a_32_hex_suffix(): void {
		$stem = \SScribe_Zip_Handler::fallback_zip_stem();

		$this->assertMatchesRegularExpression( '/^sscribe-export-\d{4}-\d{2}-\d{2}-\d{6}-[0-9a-f]{32}$/', $stem );
		$this->assertNotSame( $stem, \SScribe_Zip_Handler::fallback_zip_stem() );
	}

	public function test_collision_suffix_uses_sixteen_random_bytes_and_fits_the_name_limit(): void {
		$src = (string) file_get_contents( SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-zip-handler.php' );

		$this->assertStringContainsString( "substr( \$zip_stem, 0, 167 ) . '-' . bin2hex( random_bytes( 16 ) ) . '.zip'", $src );
		$this->assertStringNotContainsString( 'random_bytes( 3 )', $src );
		$this->assertStringNotContainsString( 'random_bytes( 4 )', $src );
		$this->assertLessThanOrEqual( 204, 167 + 1 + 32 + 4 );
	}

	private function make_temp_dir( string $label ): string {
		$dir = sys_get_temp_dir() . '/sscribe-storage-' . $label . '-' . uniqid();
		wp_mkdir_p( $dir );

		return $dir;
	}

	private function normalize( string $path ): string {
		$path = rtrim( str_replace( '\\', '/', $path ), '/' );

		return 'Windows' === PHP_OS_FAMILY ? strtolower( $path ) : $path;
	}

	private function remove_tree( string $path ): void {
		if ( ! is_dir( $path ) || is_link( $path ) ) {
			return;
		}
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $path, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $iterator as $entry ) {
			@chmod( $entry->getPathname(), 0700 );
			if ( $entry->isDir() && ! $entry->isLink() ) {
				@rmdir( $entry->getPathname() );
			} else {
				@unlink( $entry->getPathname() );
			}
		}
		@rmdir( $path );
	}
}
