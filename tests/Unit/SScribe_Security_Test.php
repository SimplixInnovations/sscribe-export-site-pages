<?php
/**
 * SScribe Security Unit Test
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests;

use PHPUnit\Framework\TestCase;
use SScribe_Security;

final class SScribe_Security_Test extends TestCase {



	private string $temp_dir;

	protected function setUp(): void {
		$upload_dir = wp_upload_dir();
		$this->temp_dir = $upload_dir['basedir'] . '/sscribe_security_test_' . uniqid();
		wp_mkdir_p( $this->temp_dir );
	}

	protected function tearDown(): void {
		if ( is_dir( $this->temp_dir ) ) {
			SScribe_Security::delete_directory( $this->temp_dir );
		}
	}

	public function test_protect_directory_creates_htaccess(): void {
		$protected_dir = $this->temp_dir . '/protected';
		SScribe_Security::protect_directory( $protected_dir );

		$this->assertFileExists( $protected_dir . '/.htaccess' );

		$content = file_get_contents( $protected_dir . '/.htaccess' );
		$this->assertStringContainsString( 'Options -Indexes', $content );
		$this->assertStringContainsString( 'Require all denied', $content );
		$this->assertStringContainsString( 'Deny from all', $content );
	}

	public function test_protect_directory_creates_index_php(): void {
		$protected_dir = $this->temp_dir . '/protected';
		SScribe_Security::protect_directory( $protected_dir );

		$this->assertFileExists( $protected_dir . '/index.php' );

		$content = file_get_contents( $protected_dir . '/index.php' );
		$this->assertStringContainsString( 'Silence is golden', $content );
	}

	public function test_protect_directory_does_not_overwrite_existing(): void {
		$protected_dir = $this->temp_dir . '/protected';
		mkdir( $protected_dir, 0755, true );
		file_put_contents( $protected_dir . '/.htaccess', 'custom content' );

		SScribe_Security::protect_directory( $protected_dir );

		// LOCK_EX always overwrites for TOCTOU security — existing content is replaced.
		$content = file_get_contents( $protected_dir . '/.htaccess' );
		$this->assertStringContainsString( 'Options -Indexes', $content );
		$this->assertStringContainsString( 'Require all denied', $content );
	}

	public function test_protect_directory_creates_parent_if_missing(): void {
		$nested_dir = $this->temp_dir . '/a/b/c';
		SScribe_Security::protect_directory( $nested_dir );

		$this->assertDirectoryExists( $nested_dir );
		$this->assertFileExists( $nested_dir . '/.htaccess' );
	}

	public function test_protect_directory_allows_fresh_upload_child_directory(): void {
		$upload_dir = wp_upload_dir();
		$target     = $upload_dir['basedir'] . '/sscribe-fresh-' . uniqid() . '/exports';

		SScribe_Security::protect_directory( $target );

		$this->assertDirectoryExists( $target );
		$this->assertFileExists( $target . '/.htaccess' );

		SScribe_Security::delete_directory( dirname( $target ) );
	}

	public function test_protect_directory_rejects_fresh_directory_outside_uploads(): void {
		$this->expectException( \InvalidArgumentException::class );

		$target = sys_get_temp_dir() . '/sscribe-outside-' . uniqid() . '/exports';

		SScribe_Security::protect_directory( $target );
	}

	public function test_delete_directory_removes_contents(): void {
		$target = $this->temp_dir . '/to_delete';
		mkdir( $target, 0755, true );
		file_put_contents( $target . '/file.txt', 'test' );
		mkdir( $target . '/subdir', 0755, true );
		file_put_contents( $target . '/subdir/nested.txt', 'nested' );

		$result = SScribe_Security::delete_directory( $target );

		$this->assertTrue( $result );
		$this->assertDirectoryDoesNotExist( $target );
		$this->assertFileDoesNotExist( $target . '/file.txt' );
	}

	public function test_delete_directory_returns_false_for_nonexistent(): void {
		$result = SScribe_Security::delete_directory( $this->temp_dir . '/does_not_exist' );
		$this->assertFalse( $result );
	}

	public function test_delete_directory_respects_depth_limit(): void {
		
		$target = $this->temp_dir . '/shallow';
		mkdir( $target, 0755, true );
		mkdir( $target . '/sub', 0755, true );
		file_put_contents( $target . '/sub/file.txt', 'test' );

		$result = SScribe_Security::delete_directory( $target, 5 );
		$this->assertTrue( $result );
		$this->assertDirectoryDoesNotExist( $target );
	}

	public function test_delete_directory_with_custom_depth(): void {
		$target = $this->temp_dir . '/nested';
		mkdir( $target, 0755, true );
		mkdir( $target . '/sub', 0755, true );
		file_put_contents( $target . '/sub/file.txt', 'test' );

		$result = SScribe_Security::delete_directory( $target, 10 );
		$this->assertTrue( $result );
		$this->assertDirectoryDoesNotExist( $target );
	}
}
