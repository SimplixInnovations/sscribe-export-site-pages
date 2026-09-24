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
		$session = $this->new_session_id();
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
		$session = $this->new_session_id();
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
		$session = $this->new_session_id();
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
		$session = $this->new_session_id();
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
		$session = $this->new_session_id();
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

	public function test_mark_complete_populates_durable_zip_index_without_transient_duplication(): void {
		$GLOBALS['sscribe_test_transients'] = array();
		$GLOBALS['sscribe_test_options']    = $GLOBALS['sscribe_test_options'] ?? array();
		$session                            = $this->new_session_id();
		$zip_basename                       = 'export-' . $session . '.zip';
		$transient_key                      = 'sscribe_zip_index_' . md5( $zip_basename );
		$option_key                         = 'sscribe_log_zip_' . md5( $zip_basename );

		$log = new SScribe_Export_Log( $session );
		$log->set_total_pages( 1 );
		$log->mark_complete( '/path/to/' . $zip_basename, 1 );
		$log->flush();

		$this->assertArrayNotHasKey(
			$transient_key,
			$GLOBALS['sscribe_test_transients'],
			'The removed transient layer must not recreate per-export wp_options rows.'
		);
		$this->assertSame(
			$session,
			$GLOBALS['sscribe_test_options'][ $option_key ] ?? null,
			'The bounded durable ZIP-to-session option must remain the no-object-cache lookup.'
		);

		$log->delete();
	}

	public function test_delete_removes_log_file(): void {
		$session = $this->new_session_id();
		$log     = new SScribe_Export_Log( $session );
		$log->set_total_pages( 1 );
		$log->flush();

		$log_file = $this->find_log_file( $session );
		$this->assertNotNull( $log_file );
		$this->assertFileExists( $log_file );

		$log->delete();
		$this->assertFileDoesNotExist( $log_file );
	}

	public function test_invalid_session_id_never_creates_a_log_file(): void {
		$log_dir = \SScribe_Private_Storage::get_subdirectory( 'logs' );
		$before     = glob( $log_dir . '/export_*.json' ) ?: array();

		$log = new SScribe_Export_Log( '../invalid-session' );
		$log->set_total_pages( 1 );
		$log->flush();

		$after = glob( $log_dir . '/export_*.json' ) ?: array();
		$this->assertSame( $before, $after );
	}

	public function test_error_history_and_messages_are_bounded(): void {
		$session = $this->new_session_id();
		$log     = new SScribe_Export_Log( $session );
		$message = str_repeat( 'sensitive-error-', 200 );

		for ( $i = 0; $i < 550; ++$i ) {
			$log->log_page_failure( 1, $message );
		}
		$log->flush();
		$data = $log->get_log();

		$this->assertCount( 500, $data['errors'] );
		$this->assertLessThanOrEqual( 1000, mb_strlen( $data['errors'][0]['message'] ) );
		$log->delete();
	}

	public function test_cleanup_removes_abandoned_processing_log_after_retention_period(): void {
		$session = $this->new_session_id();
		$log     = new SScribe_Export_Log( $session );
		$log->set_total_pages( 1 );
		$log->flush();
		$log_file = $this->find_log_file( $session );
		$this->assertNotNull( $log_file );

		$data           = json_decode( (string) file_get_contents( $log_file ), true );
		$data['status'] = 'processing';
		file_put_contents( $log_file, wp_json_encode( $data ) );
		touch( $log_file, time() - ( 73 * HOUR_IN_SECONDS ) );

		$this->assertGreaterThanOrEqual( 1, SScribe_Export_Log::cleanup_old_logs( 72 ) );
		$this->assertFileDoesNotExist( $log_file );
	}

	private function find_log_file( string $session_id ): ?string {
		$log_dir = \SScribe_Private_Storage::get_subdirectory( 'logs' );
		$pattern    = $log_dir . '/export_*' . $session_id . '*.json';

		$matches = glob( $pattern );
		return ! empty( $matches ) ? $matches[0] : null;
	}

	private function new_session_id(): string {
		return bin2hex( random_bytes( 8 ) );
	}
}
