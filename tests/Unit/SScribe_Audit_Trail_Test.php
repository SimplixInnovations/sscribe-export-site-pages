<?php
/**
 * SScribe Audit Trail Unit Test
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

class SScribe_Audit_Trail_Test extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['sscribe_test_db_tables']['wp_sscribe_audit_log'] = array();
		$GLOBALS['sscribe_test_db_schema']['wp_sscribe_audit_log'] = array(
			'id',
			'event',
			'user_id',
			'ip_address',
			'user_agent',
			'request_uri',
			'context',
			'session_id',
			'timestamp',
		);
		$GLOBALS['sscribe_test_db_indexes']['wp_sscribe_audit_log'] = array(
			'event',
			'user_id',
			'session_id',
			'timestamp',
		);
	}

	protected function tearDown(): void {
		unset(
			$GLOBALS['sscribe_test_db_tables']['wp_sscribe_audit_log'],
			$GLOBALS['sscribe_test_db_schema']['wp_sscribe_audit_log'],
			$GLOBALS['sscribe_test_db_indexes']['wp_sscribe_audit_log']
		);
		parent::tearDown();
	}

	public function test_log_returns_true_when_table_exists(): void {
		$audit = new \SScribe_Audit_Trail();
		$result = $audit->log( \SScribe_Audit_Trail::EVENT_EXPORT_STARTED, array( 'session_id' => 'sess_1' ) );
		$this->assertTrue( $result );
	}

	public function test_log_returns_false_when_table_missing(): void {
		unset( $GLOBALS['sscribe_test_db_tables']['wp_sscribe_audit_log'] );
		$audit  = new \SScribe_Audit_Trail();
		$result = $audit->log( \SScribe_Audit_Trail::EVENT_EXPORT_STARTED );
		$this->assertFalse( $result );
	}

	public function test_log_stores_event(): void {
		$audit = new \SScribe_Audit_Trail();
		$audit->log( \SScribe_Audit_Trail::EVENT_EXPORT_STARTED, array( 'session_id' => 'sess_2' ) );

		$this->assertCount( 1, $GLOBALS['sscribe_test_db_tables']['wp_sscribe_audit_log'] );
	}

	public function test_log_stores_multiple_events(): void {
		$audit = new \SScribe_Audit_Trail();
		$audit->log( \SScribe_Audit_Trail::EVENT_EXPORT_STARTED, array( 'session_id' => 'sess_3' ) );
		$audit->log( \SScribe_Audit_Trail::EVENT_EXPORT_COMPLETED, array( 'session_id' => 'sess_3' ) );

		$this->assertCount( 2, $GLOBALS['sscribe_test_db_tables']['wp_sscribe_audit_log'] );
	}

	public function test_log_redacts_sensitive_data(): void {
		$audit = new \SScribe_Audit_Trail();
		$audit->log( \SScribe_Audit_Trail::EVENT_DOWNLOAD, array(
			'session_id' => 'sess_4',
			'nonce'      => 'secret-value-123',
		) );

		$inserted = end( $GLOBALS['sscribe_test_db_tables']['wp_sscribe_audit_log'] );
		$context  = json_decode( $inserted['context'], true );
		$this->assertEquals( '[REDACTED]', $context['nonce'] );
	}

	public function test_get_logs_returns_filtered_results(): void {
		$audit = new \SScribe_Audit_Trail();
		$audit->log( \SScribe_Audit_Trail::EVENT_EXPORT_STARTED, array( 'session_id' => 'sess_5' ) );
		$audit->log( \SScribe_Audit_Trail::EVENT_EXPORT_COMPLETED, array( 'session_id' => 'sess_5' ) );

		// The fake wpdb returns empty for generic SELECT queries
		$logs = $audit->get_logs( array( 'session_id' => 'sess_5' ) );
		$this->assertIsArray( $logs );
	}

	public function test_get_logs_empty_when_disabled(): void {
		unset( $GLOBALS['sscribe_test_db_tables']['wp_sscribe_audit_log'] );
		$audit = new \SScribe_Audit_Trail();
		$logs  = $audit->get_logs();
		$this->assertIsArray( $logs );
		$this->assertEmpty( $logs );
	}

	public function test_get_event_counts_returns_array(): void {
		$audit = new \SScribe_Audit_Trail();
		$audit->log( \SScribe_Audit_Trail::EVENT_EXPORT_STARTED );
		$audit->log( \SScribe_Audit_Trail::EVENT_EXPORT_STARTED );

		$counts = $audit->get_event_counts();
		$this->assertIsArray( $counts );
	}

	public function test_get_event_counts_empty_when_disabled(): void {
		unset( $GLOBALS['sscribe_test_db_tables']['wp_sscribe_audit_log'] );
		$audit  = new \SScribe_Audit_Trail();
		$counts = $audit->get_event_counts();
		$this->assertIsArray( $counts );
		$this->assertEmpty( $counts );
	}

	public function test_cleanup_removes_old_entries(): void {
		$audit  = new \SScribe_Audit_Trail();
		$result = $audit->cleanup( 90 );
		$this->assertIsInt( $result );
	}

	public function test_cleanup_zero_when_disabled(): void {
		unset( $GLOBALS['sscribe_test_db_tables']['wp_sscribe_audit_log'] );
		$audit  = new \SScribe_Audit_Trail();
		$result = $audit->cleanup( 90 );
		$this->assertEquals( 0, $result );
	}

	public function test_erase_user_data_anonymizes(): void {
		$audit  = new \SScribe_Audit_Trail();
		$result = $audit->erase_user_data( 1 );
		$this->assertIsInt( $result );
		$this->assertEquals( 0, $result ); // No matching records
	}

	public function test_erase_user_data_zero_id(): void {
		$audit  = new \SScribe_Audit_Trail();
		$result = $audit->erase_user_data( 0 );
		$this->assertEquals( 0, $result );
	}

	public function test_event_constants_are_defined(): void {
		$this->assertEquals( 'export_started', \SScribe_Audit_Trail::EVENT_EXPORT_STARTED );
		$this->assertEquals( 'export_completed', \SScribe_Audit_Trail::EVENT_EXPORT_COMPLETED );
		$this->assertEquals( 'download', \SScribe_Audit_Trail::EVENT_DOWNLOAD );
		$this->assertEquals( 'delete_export', \SScribe_Audit_Trail::EVENT_DELETE );
		$this->assertEquals( 'permission_denied', \SScribe_Audit_Trail::EVENT_PERMISSION_DENIED );
		$this->assertEquals( 'invalid_nonce', \SScribe_Audit_Trail::EVENT_INVALID_NONCE );
	}

	/**
	 * Regression for the L3 audit redaction hardening: WordPress auth
	 * cookies and PHP session IDs must never reach the audit table
	 * verbatim. The previous redact list covered `nonce`, `token`, and
	 * friends but let through cookie-shaped keys like `cookie`,
	 * `set_cookie`, `wordpress_logged_in_*`, `wordpress_sec_*`,
	 * `php_session`, and `phpsessid`. The fix extended the forbidden
	 * list; this test locks the invariants in.
	 */
	public function test_log_redacts_cookie_shaped_keys(): void {
		$audit = new \SScribe_Audit_Trail();
		$audit->log(
			\SScribe_Audit_Trail::EVENT_DOWNLOAD,
			array(
				'session_id'              => 'sess_x',
				'cookie'                  => 'wordpress_logged_in_admin=secret|12345',
				'set_cookie'              => 'wp_settings=1; path=/',
				'wordpress_logged_in_key' => 'wordpress_logged_in_admin=secret|12345',
				'wordpress_sec_value'     => 'wordpress_sec_admin=other|67890',
				'php_session_dump'        => 'PHPSESSID=abc123def456',
				'phpsessid'               => 'abc123def456',
			)
		);

		$inserted = end( $GLOBALS['sscribe_test_db_tables']['wp_sscribe_audit_log'] );
		$context  = json_decode( $inserted['context'], true );

		$this->assertSame( '[REDACTED]', $context['cookie'] ?? null, '`cookie` key must be redacted.' );
		$this->assertSame( '[REDACTED]', $context['set_cookie'] ?? null, '`set_cookie` key must be redacted.' );
		$this->assertSame( '[REDACTED]', $context['wordpress_logged_in_key'] ?? null, 'Keys matching `wordpress_logged_*` must be redacted.' );
		$this->assertSame( '[REDACTED]', $context['wordpress_sec_value'] ?? null, 'Keys matching `wordpress_sec_*` must be redacted.' );
		$this->assertSame( '[REDACTED]', $context['php_session_dump'] ?? null, 'Keys matching `php_session*` must be redacted.' );
		$this->assertSame( '[REDACTED]', $context['phpsessid'] ?? null, '`phpsessid` key must be redacted.' );

		// session_id is whitelisted (it's the legitimate trail key).
		$this->assertSame( 'sess_x', $context['session_id'] ?? null, '`session_id` is not a sensitive key and must remain intact.' );
	}
}
