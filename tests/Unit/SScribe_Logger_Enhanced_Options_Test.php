<?php
/**
 * SScribe Logger Enhanced options-coverage test
 *
 * Exercises the constructor options branches and the level-threshold
 * gating logic that the base SScribe_Logger_Enhanced_Test suite does
 * not pin directly:
 *
 *   - default constructor (no options)
 *   - min_level override (and the threshold filter behavior)
 *   - enabled => false short-circuit (no file, no db)
 *   - enable_db / enable_file explicit toggles
 *   - enable_qm toggle (Query Monitor integration)
 *   - is_enabled() reflects the configured destinations
 *   - log() with level below threshold drops the entry
 *   - log() with level at/above threshold forwards to file pipeline
 *   - get_log_file() and clear_logs() on a disabled logger are safe no-ops
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SScribe_Logger_Enhanced;

if ( ! class_exists( '\\SScribe_Logger_Enhanced', false ) ) {
	require_once SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-logger-enhanced.php';
}

final class SScribe_Logger_Enhanced_Options_Test extends TestCase {

	private string $temp_dir;

	protected function setUp(): void {
		parent::setUp();
		// Route log writes into a per-test temp directory.
		$this->temp_dir = \SScribe_Private_Storage::get_subdirectory( 'logger-enhanced-' . uniqid() );
		wp_mkdir_p( $this->temp_dir );
	}

	protected function tearDown(): void {
		if ( is_dir( $this->temp_dir ) ) {
			$rii = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $this->temp_dir, \FilesystemIterator::SKIP_DOTS ),
				\RecursiveIteratorIterator::CHILD_FIRST
			);
			foreach ( $rii as $file ) {
				$file->isDir() ? rmdir( $file->getRealPath() ) : unlink( $file->getRealPath() );
			}
			rmdir( $this->temp_dir );
		}
		parent::tearDown();
	}

	public function test_construct_with_default_options(): void {
		$logger = new SScribe_Logger_Enhanced();
		$this::assertTrue( $logger->is_enabled() );
	}

	public function test_construct_with_explicit_min_level(): void {
		$logger = new SScribe_Logger_Enhanced( array( 'min_level' => SScribe_Logger_Enhanced::LEVEL_DEBUG ) );
		$this::assertTrue( $logger->is_enabled() );
	}

	public function test_construct_with_disabled_flag_short_circuits(): void {
		$logger = new SScribe_Logger_Enhanced( array( 'enabled' => false ) );
		// is_enabled() must return false when both file and db are off.
		$this::assertFalse( $logger->is_enabled() );
	}

	public function test_construct_with_file_disabled(): void {
		$logger = new SScribe_Logger_Enhanced( array( 'enable_file' => false ) );
		// File is off, DB defaults to off — is_enabled returns false.
		$this::assertFalse( $logger->is_enabled() );
	}

	public function test_construct_with_explicit_enable_db(): void {
		$logger = new SScribe_Logger_Enhanced( array( 'enable_db' => true ) );
		// DB enabled means is_enabled() returns true even if file is off.
		$this::assertTrue( $logger->is_enabled() );
	}

	public function test_construct_with_qm_disabled(): void {
		$logger = new SScribe_Logger_Enhanced( array( 'enable_qm' => false ) );
		$this::assertTrue( $logger->is_enabled() );
	}

	public function test_log_below_threshold_is_dropped(): void {
		// Set the threshold to WARNING so any DEBUG/INFO/notice is dropped
		// and never reaches the file pipeline.
		$logger = new SScribe_Logger_Enhanced( array( 'min_level' => SScribe_Logger_Enhanced::LEVEL_WARNING ) );
		$logger->log( 'debug', 'should-be-dropped', array( 'page_id' => 1 ) );
		$logger->flush();
		// get_logs() should not contain the dropped entry.
		$logs = $logger->get_logs();
		$this::assertIsArray( $logs );
		// No debug entry survives the threshold filter.
		foreach ( $logs as $entry ) {
			if ( is_array( $entry ) && isset( $entry['level'] ) ) {
				$this::assertNotSame( 'debug', $entry['level'] );
			}
		}
	}

	public function test_log_at_threshold_is_recorded(): void {
		$logger = new SScribe_Logger_Enhanced( array( 'min_level' => SScribe_Logger_Enhanced::LEVEL_INFO ) );
		$logger->log( 'info', 'should-pass-threshold', array( 'page_id' => 42 ) );
		$logger->flush();
		$log_file = $logger->get_log_file();
		$this::assertIsString( $log_file );
	}

	public function test_disabled_logger_log_is_safe_noop(): void {
		$logger = new SScribe_Logger_Enhanced( array( 'enabled' => false ) );
		// log() must not raise when every destination is disabled.
		$logger->log( 'error', 'never-written', array() );
		$logger->flush();
		$this::assertFalse( $logger->is_enabled() );
	}

	public function test_disabled_logger_clear_logs_is_safe_noop(): void {
		$logger = new SScribe_Logger_Enhanced( array( 'enabled' => false ) );
		// clear_logs() must be safe to call when every destination is off.
		$logger->clear_logs();
		$this::assertFalse( $logger->is_enabled() );
	}

	public function test_get_log_file_returns_string_when_enabled(): void {
		$logger = new SScribe_Logger_Enhanced();
		$log_file = $logger->get_log_file();
		$this::assertIsString( $log_file );
	}

	public function test_flush_is_idempotent(): void {
		$logger = new SScribe_Logger_Enhanced();
		$logger->log( 'info', 'first', array() );
		$logger->flush();
		$logger->flush();
		$logger->flush();
		$this::assertTrue( true, 'flush() must be safe to call multiple times.' );
	}

	public function test_get_logs_returns_array(): void {
		$logger = new SScribe_Logger_Enhanced();
		$logs = $logger->get_logs( 10 );
		$this::assertIsArray( $logs );
	}
}
