<?php
/**
 * SScribe Export Log Unit Test
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests;

use PHPUnit\Framework\TestCase;
use SScribe_Export_Log;

final class SScribe_Export_Log_Test extends TestCase {



	private string $temp_dir;

	protected function setUp(): void {
		$this->temp_dir = sys_get_temp_dir() . '/sscribe_export_log_test_' . uniqid();
		mkdir( $this->temp_dir, 0755, true );
	}

	protected function tearDown(): void {
		if ( is_dir( $this->temp_dir ) ) {
			
			$files = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $this->temp_dir, \RecursiveDirectoryIterator::SKIP_DOTS ),
				\RecursiveIteratorIterator::CHILD_FIRST
			);
			foreach ( $files as $file ) {
				if ( $file->isDir() ) {
					rmdir( $file->getRealPath() );
				} else {
					unlink( $file->getRealPath() );
				}
			}
			rmdir( $this->temp_dir );
		}
	}

	public function test_init_creates_log_file(): void {
		$session = 'test-init-' . uniqid( '', true );
		$log     = new SScribe_Export_Log( $session );
		$log->set_total_pages( 5 );
		$log->flush();

		$log_file = $this->find_log_file( $session );
		$this->assertNotNull( $log_file, 'Log file should be created after flush' );
		if ( $log_file ) {
			$log->delete();
		}
	}

	public function test_log_page_start_and_success(): void {
		$session = 'test-success-' . uniqid( '', true );
		$log     = new SScribe_Export_Log( $session );
		$log->set_total_pages( 3 );

		$log->log_page_start( 1, 'Home', 'docx' );
		$log->log_page_success( 1, array( 'docx' ) );
		$log->flush();

		$log_file = $this->find_log_file( $session );
		$this->assertNotNull( $log_file );

		$data = json_decode( file_get_contents( $log_file ), true );
		$this->assertEquals( 1, $data['success'] );
		$this->assertEquals( 1, $data['processed'] );
		$log->delete();
	}

	public function test_log_page_failure(): void {
		$session = 'test-failure-' . uniqid( '', true );
		$log     = new SScribe_Export_Log( $session );
		$log->set_total_pages( 2 );

		$log->log_page_start( 1, 'Error Page', 'docx' );
		$log->log_page_failure( 1, 'Export failed: timeout' );
		$log->flush();

		$log_file = $this->find_log_file( $session );
		$data     = json_decode( file_get_contents( $log_file ), true );

		$this->assertEquals( 1, $data['failed'] );
		$this->assertNotEmpty( $data['errors'] );
		$log->delete();
	}

	public function test_buffered_writes_reduce_io(): void {
		$session = 'test-buffered-' . uniqid( '', true );
		$log     = new SScribe_Export_Log( $session );
		$log->set_total_pages( 10 );

		for ( $i = 1; $i <= 10; $i++ ) {
			$log->log_page_start( $i, "Page {$i}", 'docx' );
			$log->log_page_success( $i, array( 'docx' ) );
		}

		$log->flush();

		$log_file = $this->find_log_file( $session );
		$data     = json_decode( file_get_contents( $log_file ), true );

		$this->assertEquals( 10, $data['success'] );
		$this->assertEquals( 10, $data['processed'] );
		$log->delete();
	}

	public function test_mark_complete(): void {
		$session = 'test-complete-' . uniqid( '', true );
		$log     = new SScribe_Export_Log( $session );
		$log->set_total_pages( 1 );
		$log->mark_complete( '/path/to/export.zip', 1 );
		$log->flush();

		$log_file = $this->find_log_file( $session );
		$data     = json_decode( file_get_contents( $log_file ), true );

		$this->assertEquals( 'complete', $data['status'] );
		$this->assertNotNull( $data['completed_at'] );
		$log->delete();
	}

	public function test_delete_removes_log_file(): void {
		$session = 'test-delete-' . uniqid( '', true );
		$log     = new SScribe_Export_Log( $session );
		$log->set_total_pages( 1 );
		$log->flush();

		$log_file = $this->find_log_file( $session );
		$this->assertNotNull( $log_file );
		$this->assertFileExists( $log_file );

		$log->delete();
		$this->assertFileDoesNotExist( $log_file );
	}



	private function find_log_file( string $session_id ): ?string {
		$upload_dir = wp_upload_dir();
		$log_dir    = $upload_dir['basedir'] . '/sscribe-logs';
		$pattern    = $log_dir . '/export_*' . $session_id . '*.json';

		$matches = glob( $pattern );
		return ! empty( $matches ) ? $matches[0] : null;
	}
}
