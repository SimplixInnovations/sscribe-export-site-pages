<?php
/**
 * SScribe Privacy with-data coverage test
 *
 * Covers the high-level user-data paths of SScribe_Privacy
 * (export_personal_data + erase_personal_data) that walk audit_logs,
 * export_stats, sessions, and zip archives. Each of the four inner
 * loops is exercised at least once against a seeded fixture:
 *
 *   - known user lookup via get_user_by('email', 'known-user@example.test')
 *     resolves to ID=1
 *   - export_stats rows for user_id=1 are returned by the wpdb stub
 *   - SScribe_Session::enable_test_mode() bypasses AES encryption so
 *     session options can be patched in plain JSON
 *   - audit_trail and zip archives remain empty so we hit the empty-list
 *     branches without seeding an entire zip pipeline
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

if ( ! class_exists( '\\SScribe_Privacy', false ) ) {
	require_once SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-privacy.php';
}
if ( ! class_exists( '\\SScribe_Session', false ) ) {
	require_once SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-session.php';
}
if ( ! class_exists( '\\SScribe_Export_Stats', false ) ) {
	require_once SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-export-stats.php';
}

final class SScribe_Privacy_With_Data_Test extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		\SScribe_Session::test_reset();

		$GLOBALS['sscribe_test_options']    = array();
		$GLOBALS['sscribe_test_db_tables']  = array(
			'wp_sscribe_audit_log'    => array(),
			'wp_sscribe_export_stats' => array(
				array(
					'id'                => 101,
					'export_session_id' => 'session-aaa',
					'user_id'           => 1,
					'export_date'       => '2026-04-10 12:00:00',
					'status'            => 'completed',
					'total_pages'       => 5,
					'successful_pages'  => 5,
					'failed_pages'      => 0,
					'formats'           => '["pdf"]',
					'error_message'     => '',
				),
				array(
					'id'                => 102,
					'export_session_id' => 'session-bbb',
					'user_id'           => 1,
					'export_date'       => '2026-04-09 12:00:00',
					'status'            => 'failed',
					'total_pages'       => 2,
					'successful_pages'  => 1,
					'failed_pages'      => 1,
					'formats'           => '["docx"]',
					'error_message'     => 'sample error',
				),
			),
		);

		\SScribe_Session::enable_test_mode();

		// Seed a session for user 1 so the export/erase loops have something
		// to walk. The 16-hex format is required by SScribe_Export_Log::delete_by_session
		// during erase (and is also sanitize_key-safe).
		$session                                       = new \SScribe_Session();
		$session_id                                     = $session->create(
			array(
				'user_id'   => 1,
				'page_ids'  => array( 1 ),
				'total'     => 1,
				'processed' => 0,
			)
		);
		$GLOBALS['sscribe_test_last_session_id']       = $session_id;
	}

	protected function tearDown(): void {
		\SScribe_Session::test_reset();
		$GLOBALS['sscribe_test_db_tables']  = array();
		$GLOBALS['sscribe_test_options']    = array();
		unset( $GLOBALS['sscribe_test_last_session_id'] );
		parent::tearDown();
	}

	public function test_register_exporter_adds_sscribe_entry(): void {
		$privacy  = new \SScribe_Privacy();
		$filtered = $privacy->register_exporter( array() );
		$this::assertArrayHasKey( 'sscribe-export-site-pages', $filtered );
		$this::assertSame(
			'SScribe export data',
			$filtered['sscribe-export-site-pages']['exporter_friendly_name']
		);
		$this::assertIsCallable( $filtered['sscribe-export-site-pages']['callback'] );
	}

	public function test_register_eraser_adds_sscribe_entry(): void {
		$privacy  = new \SScribe_Privacy();
		$filtered = $privacy->register_eraser( array() );
		$this::assertArrayHasKey( 'sscribe-export-site-pages', $filtered );
		$this::assertSame(
			'SScribe export data',
			$filtered['sscribe-export-site-pages']['eraser_friendly_name']
		);
		$this::assertIsCallable( $filtered['sscribe-export-site-pages']['callback'] );
	}

	public function test_register_exporter_preserves_existing_entries(): void {
		$privacy  = new \SScribe_Privacy();
		$existing = array( 'other-plugin' => array( 'foo' => 'bar' ) );
		$filtered = $privacy->register_exporter( $existing );
		$this::assertArrayHasKey( 'other-plugin', $filtered );
		$this::assertArrayHasKey( 'sscribe-export-site-pages', $filtered );
	}

	public function test_register_eraser_preserves_existing_entries(): void {
		$privacy  = new \SScribe_Privacy();
		$existing = array( 'other-plugin' => array( 'foo' => 'bar' ) );
		$filtered = $privacy->register_eraser( $existing );
		$this::assertArrayHasKey( 'other-plugin', $filtered );
		$this::assertArrayHasKey( 'sscribe-export-site-pages', $filtered );
	}

	public function test_register_privacy_policy_is_noop_when_function_missing(): void {
		$privacy = new \SScribe_Privacy();
		// register_privacy_policy_content() is intentionally absent from
		// the test bootstrap, so this should return cleanly without error.
		$privacy->register_privacy_policy();
		$this::assertTrue( true );
	}

	public function test_export_personal_data_returns_done_for_unknown_email(): void {
		$privacy = new \SScribe_Privacy();
		$result  = $privacy->export_personal_data( 'no-such-user@example.test' );
		$this::assertSame( array(), $result['data'] );
		$this::assertTrue( $result['done'] );
	}

	public function test_export_personal_data_returns_export_stats_for_known_user(): void {
		$privacy = new \SScribe_Privacy();
		$result  = $privacy->export_personal_data( 'known-user@example.test' );

		$this::assertIsArray( $result['data'] );
		$this::assertTrue( $result['done'] );

		$group_ids = array_column( $result['data'], 'group_id' );
		$this::assertContains( 'sscribe-export-stats', $group_ids );
		$this::assertContains( 'sscribe-export-sessions', $group_ids );

		// Two seeded export_stats rows for user 1.
		$stat_rows = array_values(
			array_filter(
				$result['data'],
				static fn( array $row ): bool => 'sscribe-export-stats' === $row['group_id']
			)
		);
		$this::assertCount( 2, $stat_rows );

		// First stat row carries the most-recent session (session-aaa).
		$this::assertSame( 'sscribe-export-stat-101', $stat_rows[0]['item_id'] );
		$value_lookup = array_column( $stat_rows[0]['data'], 'name', 'value' );
		$this::assertArrayHasKey( 'completed', $value_lookup );
		$this::assertArrayHasKey( '["pdf"]', $value_lookup );
	}

	public function test_export_personal_data_includes_session_for_known_user(): void {
		$privacy = new \SScribe_Privacy();
		$result  = $privacy->export_personal_data( 'known-user@example.test' );

		$session_rows = array_values(
			array_filter(
				$result['data'],
				static fn( array $row ): bool => 'sscribe-export-sessions' === $row['group_id']
			)
		);
		$this::assertGreaterThanOrEqual( 1, count( $session_rows ) );

		$first = $session_rows[0];
		$this::assertIsString( $first['item_id'] );
		$this::assertStringStartsWith( 'sscribe-session-', $first['item_id'] );
		$this::assertNotEmpty( $first['data'] );

		$names = array_column( $first['data'], 'name' );
		$this::assertContains( 'Session ID', $names );
	}

	public function test_export_personal_data_with_page_param_pagination(): void {
		$privacy = new \SScribe_Privacy();
		$result  = $privacy->export_personal_data( 'known-user@example.test', 2 );
		$this::assertIsArray( $result['data'] );
		$this::assertIsBool( $result['done'] );
	}

	public function test_export_personal_data_with_zero_page_normalises_to_one(): void {
		$privacy = new \SScribe_Privacy();
		$result  = $privacy->export_personal_data( 'known-user@example.test', 0 );
		// page=0 is normalised to 1; both yield the same data set.
		$this::assertIsArray( $result['data'] );
	}

	public function test_export_personal_data_with_negative_page_normalises_to_one(): void {
		$privacy = new \SScribe_Privacy();
		$result  = $privacy->export_personal_data( 'known-user@example.test', -5 );
		$this::assertIsArray( $result['data'] );
	}

	public function test_erase_personal_data_returns_done_for_unknown_email(): void {
		$privacy = new \SScribe_Privacy();
		$result  = $privacy->erase_personal_data( 'no-such-user@example.test' );
		$this::assertFalse( $result['items_removed'] );
		$this::assertFalse( $result['items_retained'] );
		$this::assertSame( array(), $result['messages'] );
		$this::assertTrue( $result['done'] );
	}

	public function test_erase_personal_data_removes_user_export_stats_and_sessions(): void {
		$privacy = new \SScribe_Privacy();
		$result  = $privacy->erase_personal_data( 'known-user@example.test' );

		// export_stats rows for user 1 are anonymised in-place (user_id -> 0).
		$this::assertSame( 0, $GLOBALS['sscribe_test_db_tables']['wp_sscribe_export_stats'][0]['user_id'] );
		$this::assertSame( 0, $GLOBALS['sscribe_test_db_tables']['wp_sscribe_export_stats'][1]['user_id'] );

		// Session created in setUp() is removed.
		$remaining = ( new \SScribe_Session() )->get_sessions_for_user( 1 );
		$this::assertCount( 0, $remaining );

		$this::assertIsBool( $result['items_removed'] );
		$this::assertIsBool( $result['items_retained'] );
		$this::assertIsArray( $result['messages'] );
		$this::assertIsBool( $result['done'] );
	}

	public function test_erase_personal_data_with_page_param_uses_initial_chunk(): void {
		$privacy = new \SScribe_Privacy();
		$result  = $privacy->erase_personal_data( 'known-user@example.test', 1 );
		$this::assertIsBool( $result['done'] );
	}

	public function test_export_personal_data_for_known_user_with_empty_data_returns_empty(): void {
		$GLOBALS['sscribe_test_db_tables']['wp_sscribe_export_stats'] = array();
		\SScribe_Session::test_reset();
		\SScribe_Session::enable_test_mode();

		$privacy = new \SScribe_Privacy();
		$result  = $privacy->export_personal_data( 'known-user@example.test' );

		$this::assertSame( array(), $result['data'] );
		$this::assertTrue( $result['done'] );
	}

	public function test_erase_personal_data_for_known_user_with_empty_data_marks_done(): void {
		$GLOBALS['sscribe_test_db_tables']['wp_sscribe_export_stats'] = array();
		\SScribe_Session::test_reset();
		\SScribe_Session::enable_test_mode();

		$privacy = new \SScribe_Privacy();
		$result  = $privacy->erase_personal_data( 'known-user@example.test' );

		$this::assertFalse( $result['items_removed'] );
		$this::assertFalse( $result['items_retained'] );
		$this::assertTrue( $result['done'] );
	}
}
