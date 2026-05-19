<?php

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

class SScribe_Filesystem_Test extends TestCase {

	private string $test_dir;

	protected function setUp(): void {
		parent::setUp();
		$this->test_dir = sys_get_temp_dir() . '/sscribe-filesystem-test-' . uniqid();
		mkdir( $this->test_dir, 0755, true );
	}

	protected function tearDown(): void {
		if ( is_dir( $this->test_dir ) ) {
			$this->deleteDirectory( $this->test_dir );
		}
		parent::tearDown();
	}

	private function deleteDirectory( string $dir ): void {
		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \RecursiveDirectoryIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $items as $item ) {
			if ( $item->isDir() ) {
				rmdir( $item->getRealPath() );
			} else {
				unlink( $item->getRealPath() );
			}
		}

		rmdir( $dir );
	}

	public function test_put_contents_creates_file(): void {
		$fs     = new \SScribe_Filesystem();
		$file   = $this->test_dir . '/test.txt';
		$result = $fs->put_contents( $file, 'Hello World' );

		$this->assertTrue( $result );
		$this->assertFileExists( $file );
		$this->assertEquals( 'Hello World', file_get_contents( $file ) );
	}

	public function test_get_contents_reads_file(): void {
		$fs   = new \SScribe_Filesystem();
		$file = $this->test_dir . '/test-read.txt';
		file_put_contents( $file, 'Test content' );

		$contents = $fs->get_contents( $file );

		$this->assertEquals( 'Test content', $contents );
	}

	public function test_get_contents_returns_false_for_missing_file(): void {
		$fs   = new \SScribe_Filesystem();
		$file = $this->test_dir . '/nonexistent.txt';

		$result = $fs->get_contents( $file );

		$this->assertFalse( $result );
	}

	public function test_delete_removes_file(): void {
		$fs   = new \SScribe_Filesystem();
		$file = $this->test_dir . '/to-delete.txt';
		file_put_contents( $file, 'Delete me' );

		$result = $fs->delete( $file );

		$this->assertTrue( $result );
		$this->assertFileDoesNotExist( $file );
	}

	public function test_exists_checks_file_existence(): void {
		$fs   = new \SScribe_Filesystem();
		$file = $this->test_dir . '/exists.txt';
		file_put_contents( $file, 'I exist' );

		$this->assertTrue( $fs->exists( $file ) );
		$this->assertFalse( $fs->exists( $this->test_dir . '/not-exists.txt' ) );
	}

	public function test_is_dir_checks_directory(): void {
		$fs     = new \SScribe_Filesystem();
		$subdir = $this->test_dir . '/subdir';
		mkdir( $subdir );

		$this->assertTrue( $fs->is_dir( $subdir ) );
		$this->assertFalse( $fs->is_dir( $this->test_dir . '/nonexistent' ) );
	}

	public function test_is_writable_checks_write_permissions(): void {
		$fs = new \SScribe_Filesystem();

		$this->assertTrue( $fs->is_writable( $this->test_dir ) );
	}

	public function test_copy_copies_file(): void {
		$fs       = new \SScribe_Filesystem();
		$source   = $this->test_dir . '/source.txt';
		$dest     = $this->test_dir . '/dest.txt';
		file_put_contents( $source, 'Copy me' );

		$result = $fs->copy( $source, $dest );

		$this->assertTrue( $result );
		$this->assertFileExists( $dest );
		$this->assertEquals( 'Copy me', file_get_contents( $dest ) );
	}

	public function test_move_renames_file(): void {
		$fs     = new \SScribe_Filesystem();
		$source = $this->test_dir . '/move-source.txt';
		$dest   = $this->test_dir . '/move-dest.txt';
		file_put_contents( $source, 'Move me' );

		$result = $fs->move( $source, $dest );

		$this->assertTrue( $result );
		$this->assertFileDoesNotExist( $source );
		$this->assertFileExists( $dest );
		$this->assertEquals( 'Move me', file_get_contents( $dest ) );
	}

	public function test_mkdir_creates_directory(): void {
		$fs     = new \SScribe_Filesystem();
		$subdir = $this->test_dir . '/newdir/subdir';

		$result = $fs->mkdir( $subdir );

		$this->assertTrue( $result );
		$this->assertDirectoryExists( $subdir );
	}
}
