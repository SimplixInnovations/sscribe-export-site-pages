<?php
/**
 * SScribe Admin Debug parser coverage test
 *
 * Targets the pure private helpers of SScribe_Admin_Debug:
 *
 *   - parse_log_line()    : JSON / bracketed / RAW / truncated / fallback
 *   - parse_log_entries() : filter level (ALL, AUDIT, priority), session
 *                           id substring, search substring, reverse flag
 *   - convert_utc_timestamp_to_site_timezone()
 *   - is_debug_log_filename()
 *
 * The pure functions are reachable through reflection; the AJAX endpoints
 * route through verify_request_authorization() which is locked down and
 * are intentionally out of scope here.
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

if ( ! class_exists( '\\SScribe_Helpers', false ) && defined( 'SSCRIBE_PLUGIN_DIR' ) ) {
	require_once SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-helpers.php';
}

final class SScribe_Admin_Debug_Parser_Coverage_Test extends TestCase {

	private \SScribe_Admin_Debug $debug;
	private ReflectionClass $ref;

	protected function setUp(): void {
		parent::setUp();
		$this->debug = new \SScribe_Admin_Debug();
		$this->ref   = new ReflectionClass( $this->debug );
	}

	/**
	 * @param string $name Method name.
	 * @param array  $args Invocation arguments.
	 */
	private function call( string $name, array $args = array() ): mixed {
		$m = $this->ref->getMethod( $name );
		$m->setAccessible( true );
		return $m->invokeArgs( $this->debug, $args );
	}

	private function call_static( string $name, array $args = array() ): mixed {
		$m = $this->ref->getMethod( $name );
		$m->setAccessible( true );
		return $m->invokeArgs( null, $args );
	}

	public function test_parse_log_line_truncates_oversized_lines(): void {
		$big   = str_repeat( 'x', 10500 );
		$entry = $this->call( 'parse_log_line', array( $big ) );

		$this::assertSame( 'RAW', $entry['level'] );
		$this::assertStringContainsString( '[truncated]', $entry['message'] );
		$this::assertSame( array(), $entry['context'] );
	}

	public function test_parse_log_line_handles_json_input(): void {
		$line  = json_encode( array(
			'timestamp' => '2026-01-01 12:00:00',
			'level'     => 'info',
			'message'   => 'Hello world',
			'context'   => array( 'user_id' => 42 ),
		) );
		$entry = $this->call( 'parse_log_line', array( $line ) );

		$this::assertSame( 'INFO', $entry['level'] );
		$this::assertSame( 'Hello world', $entry['message'] );
		$this::assertSame( array( 'user_id' => 42 ), $entry['context'] );
		$this::assertNotSame( '', $entry['timestamp'] );
	}

	public function test_parse_log_line_handles_json_with_time_key(): void {
		$line  = json_encode( array(
			'time'    => '2026-01-01 12:00:00',
			'message' => 'alt-key entry',
		) );
		$entry = $this->call( 'parse_log_line', array( $line ) );

		$this::assertSame( 'INFO', $entry['level'] );
		$this::assertSame( 'alt-key entry', $entry['message'] );
	}

	public function test_parse_log_line_handles_bracketed_with_json_context(): void {
		$line  = '[2026-01-01 12:00:00] [NOTICE] user did something | {"page_id":7}';
		$entry = $this->call( 'parse_log_line', array( $line ) );

		$this::assertSame( 'NOTICE', $entry['level'] );
		$this::assertSame( 'user did something', $entry['message'] );
		$this::assertSame( array( 'page_id' => 7 ), $entry['context'] );
	}

	public function test_parse_log_line_handles_bracketed_without_context(): void {
		$line  = '[2026-01-01 12:00:00] [WARNING] simple message';
		$entry = $this->call( 'parse_log_line', array( $line ) );

		$this::assertSame( 'WARNING', $entry['level'] );
		$this::assertSame( 'simple message', $entry['message'] );
		$this::assertSame( array(), $entry['context'] );
	}

	public function test_parse_log_line_falls_back_to_raw(): void {
		$line  = 'just plain text without timestamp';
		$entry = $this->call( 'parse_log_line', array( $line ) );

		$this::assertSame( 'RAW', $entry['level'] );
		$this::assertSame( 'just plain text without timestamp', $entry['message'] );
		$this::assertSame( '', $entry['timestamp'] );
	}

	public function test_parse_log_entries_skips_empty_lines(): void {
		$entries = $this->call( 'parse_log_entries', array(
			array( '', '  ', 'plain entry' ),
			'ALL',
			'',
		) );
		$this::assertCount( 1, $entries );
	}

	public function test_parse_log_entries_filter_audit_only(): void {
		$entries = $this->call( 'parse_log_entries', array(
			array(
				'AUDIT event happened',
				'regular notice',
				'[AUDIT] login event',
			),
			'AUDIT',
			'',
		) );

		$this::assertCount( 1, $entries );
	}

	public function test_parse_log_entries_filter_by_level_priority(): void {
		$entries = $this->call( 'parse_log_entries', array(
			array(
				'[2026-01-01 12:00:00] [DEBUG] low priority',
				'[2026-01-01 12:00:00] [INFO] mid priority',
				'[2026-01-01 12:00:00] [ERROR] high priority',
			),
			'ERROR',
			'',
		) );

		$this::assertCount( 1, $entries );
		$this::assertSame( 'ERROR', $entries[0]['level'] );
	}

	public function test_parse_log_entries_filter_all_includes_everything(): void {
		$entries = $this->call( 'parse_log_entries', array(
			array(
				'DEBUG low',
				'INFO mid',
				'ERROR high',
			),
			'ALL',
			'',
		) );

		$this::assertCount( 3, $entries );
	}

	public function test_parse_log_entries_filter_by_session_id(): void {
		$entries = $this->call( 'parse_log_entries', array(
			array(
				'[2026-01-01 12:00:00] [INFO] one | {"session_id":"abcdef1234567890"}',
				'[2026-01-01 12:00:01] [INFO] two | {"session_id":"9999999999999999"}',
			),
			'ALL',
			'',
			'abcdef1234567890',
		) );

		$this::assertCount( 1, $entries );
	}

	public function test_parse_log_entries_filter_by_search(): void {
		$entries = $this->call( 'parse_log_entries', array(
			array(
				'message contains unicorn',
				'message contains horse',
			),
			'ALL',
			'unicorn',
		) );

		$this::assertCount( 1, $entries );
	}

	public function test_parse_log_entries_filter_by_search_insensitive(): void {
		$entries = $this->call( 'parse_log_entries', array(
			array(
				'message contains UNICORN',
				'message contains horse',
			),
			'ALL',
			'unicorn',
		) );

		$this::assertCount( 1, $entries );
	}

	public function test_parse_log_entries_respects_reverse_flag_false(): void {
		$entries = $this->call( 'parse_log_entries', array(
			array( 'first', 'second', 'third' ),
			'ALL',
			'',
			'',
			false,
		) );

		$this::assertCount( 3, $entries );
		$this::assertSame( 'first', $entries[0]['message'] );
		$this::assertSame( 'third', $entries[2]['message'] );
	}

	public function test_parse_log_entries_reverse_by_default(): void {
		$entries = $this->call( 'parse_log_entries', array(
			array( 'first', 'second', 'third' ),
			'ALL',
			'',
		) );

		$this::assertCount( 3, $entries );
		$this::assertSame( 'third', $entries[0]['message'] );
		$this::assertSame( 'first', $entries[2]['message'] );
	}

	public function test_convert_utc_timestamp_returns_empty_for_empty(): void {
		$result = $this->call( 'convert_utc_timestamp_to_site_timezone', array( '' ) );
		$this::assertSame( '', $result );
	}

	public function test_convert_utc_timestamp_formats_iso_input(): void {
		$result = $this->call( 'convert_utc_timestamp_to_site_timezone', array( '2026-01-01 12:00:00' ) );
		$this::assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $result );
	}

	public function test_convert_utc_timestamp_falls_back_on_invalid(): void {
		$result = $this->call( 'convert_utc_timestamp_to_site_timezone', array( 'not-a-date' ) );
		// Invalid input must return the raw value unchanged.
		$this::assertSame( 'not-a-date', $result );
	}

	public function test_is_debug_log_filename_accepts_valid_form(): void {
		$this::assertTrue( $this->call_static( 'is_debug_log_filename', array( 'site_debug_2026-01-01.log' ) ) );
		$this::assertTrue( $this->call_static( 'is_debug_log_filename', array( 'site_debug_2026-01-01_12-30-45.log' ) ) );
	}

	public function test_is_debug_log_filename_rejects_invalid_form(): void {
		$this::assertFalse( $this->call_static( 'is_debug_log_filename', array( '' ) ) );
		$this::assertFalse( $this->call_static( 'is_debug_log_filename', array( '../etc/passwd' ) ) );
		$this::assertFalse( $this->call_static( 'is_debug_log_filename', array( 'notdebug_2026-01-01.log' ) ) );
		$this::assertFalse( $this->call_static( 'is_debug_log_filename', array( 'site_debug_2026_01_01.log' ) ) );
		$this::assertFalse( $this->call_static( 'is_debug_log_filename', array( 'site_debug_01-01-2026.log' ) ) );
	}

	public function test_class_has_expected_ajax_endpoints(): void {
		$this::assertTrue( method_exists( \SScribe_Admin_Debug::class, 'ajax_debug_fetch_logs' ) );
		$this::assertTrue( method_exists( \SScribe_Admin_Debug::class, 'ajax_debug_clear_logs' ) );
		$this::assertTrue( method_exists( \SScribe_Admin_Debug::class, 'ajax_debug_export_logs' ) );
		$this::assertTrue( method_exists( \SScribe_Admin_Debug::class, 'register_hooks' ) );
	}

	public function test_get_debug_capability_returns_manage_options(): void {
		$result = $this->call_static( 'get_debug_capability' );
		$this::assertSame( 'manage_options', $result );
	}
}
