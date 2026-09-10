<?php
/**
 * SScribe Export Log static helpers coverage test
 *
 * Targets the public static helpers of SScribe_Export_Log:
 *
 *   - get_log_by_session() rejects malformed session IDs
 *   - get_log_by_filename() rejects malformed ZIP filenames
 *   - delete_by_session() returns 0 for malformed session IDs
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

if ( ! class_exists( '\\SScribe_Export_Log', false ) ) {
	require_once SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-export-log.php';
}

final class SScribe_Export_Log_Static_Coverage_Test extends TestCase {

	public function test_get_log_by_session_rejects_malformed_session_id(): void {
		$this::assertNull( \SScribe_Export_Log::get_log_by_session( 'too-short' ) );
		$this::assertNull( \SScribe_Export_Log::get_log_by_session( '' ) );
		$this::assertNull( \SScribe_Export_Log::get_log_by_session( 'NOT-HEX-12345678' ) );
		$this::assertNull( \SScribe_Export_Log::get_log_by_session( str_repeat( 'a', 17 ) ) );
	}

	public function test_get_log_by_session_returns_null_for_unknown_session(): void {
		$this::assertNull( \SScribe_Export_Log::get_log_by_session( '0123456789abcdef' ) );
	}

	public function test_get_log_by_filename_rejects_malformed_filenames(): void {
		$this::assertNull( \SScribe_Export_Log::get_log_by_filename( '' ) );
		$this::assertNull( \SScribe_Export_Log::get_log_by_filename( 'no-extension' ) );
		$this::assertNull( \SScribe_Export_Log::get_log_by_filename( '../escaped.zip' ) );
		$this::assertNull( \SScribe_Export_Log::get_log_by_filename( 'has space.zip' ) );
		$this::assertNull( \SScribe_Export_Log::get_log_by_filename( 'with/path.zip' ) );
	}

	public function test_get_log_by_filename_rejects_non_zip_extension(): void {
		$this::assertNull( \SScribe_Export_Log::get_log_by_filename( 'archive.tar' ) );
		$this::assertNull( \SScribe_Export_Log::get_log_by_filename( 'archive.pdf' ) );
	}

	public function test_get_log_by_filename_returns_null_when_log_dir_unavailable(): void {
		// Without a real log dir, returns null. Just exercise the early-return branch.
		$this::assertNull( \SScribe_Export_Log::get_log_by_filename( 'clean-archive.zip' ) );
	}

	public function test_delete_by_session_returns_false_for_malformed_id(): void {
		$this::assertFalse( \SScribe_Export_Log::delete_by_session( 'too-short' ) );
		$this::assertFalse( \SScribe_Export_Log::delete_by_session( '' ) );
		$this::assertFalse( \SScribe_Export_Log::delete_by_session( 'INVALID-1234567890' ) );
	}

	public function test_delete_by_session_runs_without_error_for_unknown_session(): void {
		// Either path is acceptable: returns true when the file is already
		// absent (deletion is a no-op success) or false when storage is
		// unavailable. Both paths exercise the same code branches.
		$result = \SScribe_Export_Log::delete_by_session( '0123456789abcdef' );
		$this::assertIsBool( $result );
	}

	public function test_delete_session_method_class_exists(): void {
		$this::assertTrue( method_exists( \SScribe_Export_Log::class, 'delete_by_session' ) );
	}
}
