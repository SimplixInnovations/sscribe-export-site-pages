<?php
/**
 * SScribe Privacy Storage Unit Test
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class SScribe_Privacy_Storage_Test extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		// Wipe ALL session state first — SScribe_Session_Test does not call
		// delete() in tearDown, so sessions and transients from that class leak
		// into this test and block create() via has_active_session().
		SScribe_Session::test_reset();

		$GLOBALS['sscribe_test_options'] = array();
		$GLOBALS['sscribe_test_db_tables'] = array(
			'wp_sscribe_audit_log'    => array(
				array(
					'id'          => 1,
					'user_id'     => 7,
					'ip_address'  => '127.0.0.1',
					'user_agent'  => 'Test Agent',
					'request_uri' => '/wp-admin/admin-ajax.php',
					'context'     => '{"note":"keep"}',
				),
				array(
					'id'          => 2,
					'user_id'     => 8,
					'ip_address'  => '127.0.0.2',
					'user_agent'  => 'Other Agent',
					'request_uri' => '/wp-admin/admin.php',
					'context'     => '{"note":"other"}',
				),
			),
			'wp_sscribe_export_stats' => array(
				array(
					'id'                => 10,
					'export_session_id' => 'session-a',
					'user_id'           => 7,
					'export_date'       => '2026-04-10 12:00:00',
					'status'            => 'completed',
					'total_pages'       => 5,
					'successful_pages'  => 5,
					'failed_pages'      => 0,
					'formats'           => '["pdf"]',
					'error_message'     => 'sensitive error',
				),
				array(
					'id'                => 11,
					'export_session_id' => 'session-b',
					'user_id'           => 7,
					'export_date'       => '2026-04-09 12:00:00',
					'status'            => 'failed',
					'total_pages'       => 2,
					'successful_pages'  => 1,
					'failed_pages'      => 1,
					'formats'           => '["docx"]',
					'error_message'     => 'another error',
				),
				array(
					'id'                => 12,
					'export_session_id' => 'session-c',
					'user_id'           => 8,
					'export_date'       => '2026-04-08 12:00:00',
					'status'            => 'completed',
					'total_pages'       => 1,
					'successful_pages'  => 1,
					'failed_pages'      => 0,
					'formats'           => '["html"]',
					'error_message'     => '',
				),
			),
		);

		// Bypass AES-256-CBC encryption so tests can directly patch session
		// options as plain JSON and have get() decode them correctly.
		SScribe_Session::enable_test_mode();
	}

	protected function tearDown(): void {
		parent::tearDown();
		SScribe_Session::test_reset();
	}

	public function test_get_exports_by_user_returns_matching_rows_in_descending_date_order(): void {
		$stats   = new SScribe_Export_Stats();
		$exports = $stats->get_exports_by_user( 7, 10 );

		$this->assertCount( 2, $exports );
		$this->assertSame( 10, $exports[0]->id );
		$this->assertSame( 11, $exports[1]->id );
	}

	public function test_export_stats_erase_user_data_anonymizes_matching_rows_only(): void {
		$stats   = new SScribe_Export_Stats();
		$updated = $stats->erase_user_data( 7 );

		$this->assertSame( 2, $updated );
		$this->assertSame( 0, $GLOBALS['sscribe_test_db_tables']['wp_sscribe_export_stats'][0]['user_id'] );
		$this->assertSame( '', $GLOBALS['sscribe_test_db_tables']['wp_sscribe_export_stats'][0]['error_message'] );
		$this->assertSame( 8, $GLOBALS['sscribe_test_db_tables']['wp_sscribe_export_stats'][2]['user_id'] );
	}

	public function test_audit_trail_erase_user_data_redacts_personal_fields_only_for_matching_user(): void {
		$audit   = new SScribe_Audit_Trail();
		$updated = $audit->erase_user_data( 7 );

		$this->assertSame( 1, $updated );
		$this->assertSame( 0, $GLOBALS['sscribe_test_db_tables']['wp_sscribe_audit_log'][0]['user_id'] );
		$this->assertSame( '', $GLOBALS['sscribe_test_db_tables']['wp_sscribe_audit_log'][0]['ip_address'] );
		$this->assertSame( '{}', $GLOBALS['sscribe_test_db_tables']['wp_sscribe_audit_log'][0]['context'] );
		$this->assertSame( 8, $GLOBALS['sscribe_test_db_tables']['wp_sscribe_audit_log'][1]['user_id'] );
	}

	public function test_get_sessions_for_user_returns_only_matching_sessions_sorted_by_update_time(): void {
		SScribe_Session::test_reset();
		SScribe_Session::enable_test_mode();

		$session = new SScribe_Session();

		$older_session_id = $session->create(
			array(
				'user_id'   => 7,
				'page_ids'  => array( 1 ),
				'total'     => 1,
				'processed' => 0,
			)
		);

		// Create a newer session for user 7 to test sorting by updated_at.
		$session->create(
			array(
				'user_id'   => 7,
				'page_ids'  => array( 2 ),
				'total'     => 1,
				'processed' => 0,
			)
		);

		// Create a session for user 8 (should NOT appear in user-7 results).
		$session->create(
			array(
				'user_id'   => 8,
				'page_ids'  => array( 3 ),
				'total'     => 1,
				'processed' => 0,
			)
		);

		// Manually set updated_at values via direct option patches:
		// The older user-7 session gets updated_at=100, the newer gets updated_at=200.
		foreach ( $GLOBALS['sscribe_test_options'] as $option_name => $option_value ) {
			if ( 'sscribe_session_' . $older_session_id === $option_name ) {
				$decoded               = json_decode( $option_value, true );
				$decoded['updated_at'] = 100;
				$GLOBALS['sscribe_test_options'][ $option_name ] = wp_json_encode( $decoded );
				continue;
			}

			if ( str_starts_with( $option_name, 'sscribe_session_' ) ) {
				$decoded = json_decode( $option_value, true );
				if ( is_array( $decoded ) && 7 === (int) ( $decoded['user_id'] ?? 0 ) ) {
					$decoded['updated_at'] = 200;
					$GLOBALS['sscribe_test_options'][ $option_name ] = wp_json_encode( $decoded );
				}
			}
		}

		$sessions = $session->get_sessions_for_user( 7 );

		$this->assertCount( 2, $sessions );
		$this->assertSame( 7, $sessions[0]['user_id'] );
		$this->assertSame( $older_session_id, $sessions[0]['session_id'] );
		$this->assertGreaterThanOrEqual( $sessions[0]['updated_at'], $sessions[1]['updated_at'] );
	}

	public function test_delete_sessions_for_user_removes_only_matching_sessions(): void {
		$session = new SScribe_Session();

		$session->create(
			array(
				'user_id'   => 7,
				'page_ids'  => array( 1 ),
				'total'     => 1,
				'processed' => 0,
			)
		);

		$other_session_id = $session->create(
			array(
				'user_id'   => 8,
				'page_ids'  => array( 2 ),
				'total'     => 1,
				'processed' => 0,
			)
		);

		$deleted = $session->delete_sessions_for_user( 7 );

		$this->assertSame( 1, $deleted );
		$this->assertCount( 1, $session->get_sessions_for_user( 8 ) );
		$this->assertNotNull( $session->get( $other_session_id ) );
	}

	public function test_get_sessions_for_user_applies_limit_and_offset_after_sorting(): void {
		$session = new SScribe_Session();

		foreach ( array( 10, 20, 30 ) as $page_id ) {
			$session->create(
				array(
					'user_id'   => 7,
					'page_ids'  => array( $page_id ),
					'total'     => 1,
					'processed' => 0,
				)
			);
		}

		$all_sessions = $session->get_sessions_for_user( 7 );
		$page          = $session->get_sessions_for_user( 7, 1, 1 );

		$this->assertCount( 3, $all_sessions );
		$this->assertCount( 1, $page );
		$this->assertSame( $all_sessions[1]['session_id'], $page[0]['session_id'] );
	}
}
