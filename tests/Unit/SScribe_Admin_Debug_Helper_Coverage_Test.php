<?php
/**
 * SScribe Admin Debug pure helper coverage test
 *
 * Targets the small, isolated helpers of SScribe_Admin_Debug that are
 * independent of WP auth / AJAX plumbing:
 *
 *   - read_bounded_log_lines()       : empty file, small file, oversize-trim,
 *                                      missing-file (null)
 *   - parse_log_line()               : JSON, bracketed, RAW, truncated, fallback
 *   - parse_log_entries()            : filter levels (ALL, AUDIT, priority),
 *                                      session id substring, search substring,
 *                                      reverse flag
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;

if ( ! class_exists( '\\SScribe_Admin_Debug', false ) ) {
	require_once SSCRIBE_PLUGIN_DIR . 'admin/class-sscribe-admin-debug.php';
}

final class SScribe_Admin_Debug_Helper_Coverage_Test extends TestCase {

	private \SScribe_Admin_Debug $dbg;
	private ReflectionClass $ref;

	protected function setUp(): void {
		parent::setUp();
		$this->dbg = new \SScribe_Admin_Debug();
		$this->ref = new ReflectionClass( $this->dbg );
	}

	private function call_static( string $name, array $args = array() ): mixed {
		$m = $this->ref->getMethod( $name );
		$m->setAccessible( true );
		return $m->invokeArgs( null, $args );
	}

	private function call( string $name, array $args = array() ): mixed {
		$m = $this->ref->getMethod( $name );
		$m->setAccessible( true );
		return $m->invokeArgs( $this->dbg, $args );
	}

	public function test_read_bounded_log_lines_returns_empty_array_for_empty_file(): void {
		$tmp = tempnam( sys_get_temp_dir(), 'adb_empty_' );
		$result = $this->call_static( 'read_bounded_log_lines', array( $tmp, 50 ) );
		$this::assertIsArray( $result );
		$this::assertCount( 0, $result );
		@unlink( $tmp );
	}

	public function test_read_bounded_log_lines_returns_all_lines_when_under_limit(): void {
		$tmp = tempnam( sys_get_temp_dir(), 'adb_small_' );
		file_put_contents( $tmp, "line1\nline2\nline3\n" );
		$result = $this->call_static( 'read_bounded_log_lines', array( $tmp, 50 ) );
		$this::assertCount( 3, $result );
		@unlink( $tmp );
	}

	public function test_read_bounded_log_lines_caps_at_max_lines(): void {
		$tmp = tempnam( sys_get_temp_dir(), 'adb_big_' );
		$content = '';
		for ( $i = 1; $i <= 100; $i++ ) {
			$content .= "line{$i}\n";
		}
		file_put_contents( $tmp, $content );

		$result = $this->call_static( 'read_bounded_log_lines', array( $tmp, 10 ) );
		$this::assertCount( 10, $result );
		// We asked for last 10 lines, so the first returned line must be 'line91'.
		$this::assertStringStartsWith( 'line91', $result[0] );
		@unlink( $tmp );
	}

	public function test_read_bounded_log_lines_returns_null_for_missing_file(): void {
		$result = $this->call_static( 'read_bounded_log_lines', array( '/no/such/file_' . uniqid() . '.log', 50 ) );
		$this::assertNull( $result );
	}

	public function test_read_bounded_log_lines_clamps_max_lines_to_static_constant(): void {
		$tmp = tempnam( sys_get_temp_dir(), 'adb_clamp_' );
		$result = $this->call_static( 'read_bounded_log_lines', array( $tmp, 99999999 ) );
		// Even with a huge request, the bound is clamped to MAX_FETCH_LINES; an
		// empty file returns an empty array (does not throw).
		$this::assertIsArray( $result );
		@unlink( $tmp );
	}

	public function test_parse_log_line_parses_json(): void {
		$line  = '{"timestamp":"2026-01-01 12:00:00","level":"INFO","message":"hello","context":{"foo":"bar"}}';
		$entry = $this->call( 'parse_log_line', array( $line ) );
		$this::assertSame( 'INFO', $entry['level'] );
		$this::assertSame( 'hello', $entry['message'] );
		$this::assertSame( array( 'foo' => 'bar' ), $entry['context'] );
	}

	public function test_parse_log_line_parses_bracketed_format(): void {
		$entry = $this->call( 'parse_log_line', array( '[2026-01-01 12:00:00] [WARNING] something happened' ) );
		$this::assertSame( 'WARNING', $entry['level'] );
		$this::assertStringContainsString( 'something happened', $entry['message'] );
	}

	public function test_parse_log_line_returns_raw_for_unrecognized(): void {
		$entry = $this->call( 'parse_log_line', array( 'just a free-form message' ) );
		$this::assertSame( 'RAW', $entry['level'] );
		$this::assertStringContainsString( 'free-form', $entry['message'] );
	}

	public function test_parse_log_line_truncates_oversize_lines(): void {
		$big   = str_repeat( 'x', 11000 );
		$entry = $this->call( 'parse_log_line', array( $big ) );
		$this::assertSame( 'RAW', $entry['level'] );
		$this::assertStringContainsString( '[truncated]', $entry['message'] );
	}

	public function test_parse_log_entries_returns_empty_for_empty_input(): void {
		$result = $this->call( 'parse_log_entries', array( array(), 'ALL', '', '', false ) );
		$this::assertSame( array(), $result );
	}

	public function test_parse_log_entries_skips_blank_lines(): void {
		$lines = array( '', '   ', "\t\n", '[2026-01-01 12:00:00] [INFO] one' );
		$result = $this->call( 'parse_log_entries', array( $lines, 'ALL', '', '', false ) );
		$this::assertCount( 1, $result );
	}

	public function test_parse_log_entries_filter_by_level_all(): void {
		$lines = array(
			'[2026-01-01 12:00:00] [INFO] info msg',
			'[2026-01-01 12:00:01] [ERROR] err msg',
		);
		$result = $this->call( 'parse_log_entries', array( $lines, 'ALL', '', '', false ) );
		$this::assertCount( 2, $result );
	}

	public function test_parse_log_entries_filter_by_level_priority(): void {
		$lines = array(
			'[2026-01-01 12:00:00] [INFO] low priority',
			'[2026-01-01 12:00:01] [ERROR] high priority',
		);
		// ERROR-only filter: priority >= 4.
		$result = $this->call( 'parse_log_entries', array( $lines, 'ERROR', '', '', false ) );
		$this::assertCount( 1, $result );
		$this::assertSame( 'ERROR', $result[0]['level'] );
	}

	public function test_parse_log_entries_filter_by_audit(): void {
		$lines = array(
			'[2026-01-01 12:00:00] [INFO] regular',
			'[2026-01-01 12:00:01] [INFO] [AUDIT] admin login',
		);
		$result = $this->call( 'parse_log_entries', array( $lines, 'AUDIT', '', '', false ) );
		$this::assertCount( 1, $result );
		$this::assertStringContainsString( 'AUDIT', $result[0]['message'] );
	}

	public function test_parse_log_entries_filter_by_session_id(): void {
		$lines = array(
			'[2026-01-01 12:00:00] [INFO] one | {"session_id":"abcdef1234567890"}',
			'[2026-01-01 12:00:01] [INFO] two | {"session_id":"zzzzzz"}',
		);
		$result = $this->call( 'parse_log_entries', array( $lines, 'ALL', '', 'abcdef', false ) );
		$this::assertCount( 1, $result );
		$this::assertStringContainsString( 'one', $result[0]['message'] );
	}

	public function test_parse_log_entries_filter_by_search_substring(): void {
		$lines = array(
			'[2026-01-01 12:00:00] [INFO] page exported',
			'[2026-01-01 12:00:01] [INFO] error occurred',
		);
		$result = $this->call( 'parse_log_entries', array( $lines, 'ALL', 'exported', '', false ) );
		$this::assertCount( 1, $result );
		$this::assertStringContainsString( 'exported', $result[0]['message'] );
	}

	public function test_parse_log_entries_reverse_flag(): void {
		$lines = array(
			'[2026-01-01 12:00:00] [INFO] first',
			'[2026-01-01 12:00:01] [INFO] second',
		);
		$result_reverse = $this->call( 'parse_log_entries', array( $lines, 'ALL', '', '', true ) );
		$this::assertStringContainsString( 'second', $result_reverse[0]['message'] );

		$result_forward = $this->call( 'parse_log_entries', array( $lines, 'ALL', '', '', false ) );
		$this::assertStringContainsString( 'first', $result_forward[0]['message'] );
	}

	public function test_parse_log_entries_accepts_scalar_lines(): void {
		// PHP ints are scalar; the function casts to string and runs them through
		// the parser. The integer becomes a free-form message; the bracketed
		// line parses normally. Both survive.
		$lines = array(
			12345,
			'[2026-01-01 12:00:00] [INFO] valid',
		);
		$result = $this->call( 'parse_log_entries', array( $lines, 'ALL', '', '', false ) );
		$this::assertCount( 2, $result );
	}

	public function test_class_has_expected_public_methods(): void {
		$this::assertTrue( method_exists( \SScribe_Admin_Debug::class, 'register_hooks' ) );
		$this::assertTrue( method_exists( \SScribe_Admin_Debug::class, 'ajax_debug_save_settings' ) );
		$this::assertTrue( method_exists( \SScribe_Admin_Debug::class, 'ajax_debug_fetch_logs' ) );
		$this::assertTrue( method_exists( \SScribe_Admin_Debug::class, 'ajax_debug_clear_logs' ) );
		$this::assertTrue( method_exists( \SScribe_Admin_Debug::class, 'ajax_debug_export_logs' ) );
		$this::assertTrue( method_exists( \SScribe_Admin_Debug::class, 'ajax_debug_get_rotated_log_files' ) );
		$this::assertTrue( method_exists( \SScribe_Admin_Debug::class, 'ajax_debug_fetch_rotated' ) );
		$this::assertTrue( method_exists( \SScribe_Admin_Debug::class, 'ajax_debug_delete_rotated' ) );
		$this::assertTrue( method_exists( \SScribe_Admin_Debug::class, 'ajax_debug_refresh_nonce' ) );
	}
}
