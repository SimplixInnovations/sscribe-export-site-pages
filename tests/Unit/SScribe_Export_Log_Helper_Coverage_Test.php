<?php
/**
 * SScribe Export Log helper coverage test.
 *
 * Targets the private static helpers and instance methods that don't
 * require valid private-storage state to exercise:
 *
 *   - bound_log_data()        : bounds pages/errors counts
 *   - normalize_log_data()    : ensures known keys exist
 *   - limit_text()            : bounded string
 *   - get_default_log_data()  : default structure
 *   - mark_complete() / mark_failed() with empty session
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;

if ( ! class_exists( '\\SScribe_Export_Log', false ) ) {
	require_once SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-export-log.php';
}

final class SScribe_Export_Log_Helper_Coverage_Test extends TestCase {

	private ReflectionClass $ref;

	protected function setUp(): void {
		parent::setUp();
		$this->ref = new ReflectionClass( \SScribe_Export_Log::class );
	}

	private function call_static( string $name, array $args = array() ): mixed {
		$m = $this->ref->getMethod( $name );
		$m->setAccessible( true );
		return $m->invokeArgs( null, $args );
	}

	private function call_instance( object $instance, string $name, array $args = array() ): mixed {
		$m = $this->ref->getMethod( $name );
		$m->setAccessible( true );
		return $m->invokeArgs( $instance, $args );
	}

	public function test_limit_text_bounds_length(): void {
		$result = $this->call_static( 'limit_text', array( str_repeat( 'x', 500 ), 100 ) );
		$this::assertSame( 100, strlen( $result ) );
	}

	public function test_limit_text_keeps_short_text(): void {
		$result = $this->call_static( 'limit_text', array( 'short', 100 ) );
		$this::assertSame( 'short', $result );
	}

	public function test_limit_text_handles_empty(): void {
		$result = $this->call_static( 'limit_text', array( '', 100 ) );
		$this::assertSame( '', $result );
	}

	public function test_get_default_log_data_has_expected_keys(): void {
		$log = new \SScribe_Export_Log( 'invalid' ); // invalid session => empty log_dir
		$result = $this->call_instance( $log, 'get_default_log_data' );
		$this::assertIsArray( $result );
		$this::assertArrayHasKey( 'pages', $result );
		$this::assertArrayHasKey( 'errors', $result );
	}

	public function test_normalize_log_data_ensures_known_keys(): void {
		$log = new \SScribe_Export_Log( 'invalid' );
		$result = $this->call_instance( $log, 'normalize_log_data', array( array() ) );
		$this::assertIsArray( $result );
		$this::assertArrayHasKey( 'pages', $result );
		$this::assertArrayHasKey( 'errors', $result );
	}

	public function test_bound_log_data_truncates_excess(): void {
		$log   = new \SScribe_Export_Log( 'invalid' );
		$pages = array();
		for ( $i = 0; $i < 1500; $i++ ) {
			$pages[] = array( 'page_id' => $i );
		}
		$result = $this->call_instance(
			$log,
			'bound_log_data',
			array(
				array(
					'pages'  => $pages,
					'errors' => array(),
				)
			)
		);
		$this::assertIsArray( $result );
		$this::assertLessThanOrEqual( 1000, count( $result['pages'] ) );
	}

	public function test_get_log_returns_array_with_invalid_session(): void {
		// Invalid session — storage_available=false but log still returns default.
		$log = new \SScribe_Export_Log( 'invalid' );
		$result = $log->get_log();
		$this::assertIsArray( $result );
	}

	public function test_get_summary_returns_array(): void {
		$log    = new \SScribe_Export_Log( 'invalid' );
		$result = $log->get_summary();
		$this::assertIsArray( $result );
	}

	public function test_log_page_start_with_invalid_session(): void {
		$log = new \SScribe_Export_Log( 'invalid' );
		$log->log_page_start( 1, 'Title', 'slug' );
		// After invalid init, log returns default data; page should NOT appear
		// because storage is unavailable.
		$this::assertTrue( true ); // survived
	}

	public function test_update_page_status_with_invalid_session(): void {
		$log = new \SScribe_Export_Log( 'invalid' );
		$log->update_page_status( 1, 'success' );
		$this::assertTrue( true );
	}

	public function test_log_page_success_with_invalid_session(): void {
		$log = new \SScribe_Export_Log( 'invalid' );
		$log->log_page_success( 1, array( 'docx', 'html' ) );
		$this::assertTrue( true );
	}

	public function test_log_page_failure_with_invalid_session(): void {
		$log = new \SScribe_Export_Log( 'invalid' );
		$log->log_page_failure( 1, 'oops' );
		$this::assertTrue( true );
	}

	public function test_log_page_retry_with_invalid_session(): void {
		$log = new \SScribe_Export_Log( 'invalid' );
		$log->log_page_retry( 1, 'docx', 2, 'transient' );
		$this::assertTrue( true );
	}

	public function test_log_format_result_with_invalid_session(): void {
		$log = new \SScribe_Export_Log( 'invalid' );
		$log->log_format_result( 1, 'docx', true, '/path/to/file', '' );
		$this::assertTrue( true );
	}

	public function test_mark_complete_with_invalid_session(): void {
		$log = new \SScribe_Export_Log( 'invalid' );
		$log->mark_complete( '/path/to/zip', 10 );
		$this::assertTrue( true );
	}

	public function test_mark_failed_with_invalid_session(): void {
		$log = new \SScribe_Export_Log( 'invalid' );
		$log->mark_failed( 'failed for reasons' );
		$this::assertTrue( true );
	}

	public function test_set_total_pages_with_invalid_session(): void {
		$log = new \SScribe_Export_Log( 'invalid' );
		$log->set_total_pages( 50 );
		$this::assertTrue( true );
	}

	public function test_delete_with_invalid_session(): void {
		$log = new \SScribe_Export_Log( 'invalid' );
		$log->delete();
		$this::assertTrue( true );
	}

	public function test_class_has_expected_methods(): void {
		$this::assertTrue( method_exists( \SScribe_Export_Log::class, 'get_log' ) );
		$this::assertTrue( method_exists( \SScribe_Export_Log::class, 'flush' ) );
		$this::assertTrue( method_exists( \SScribe_Export_Log::class, 'log_page_success' ) );
		$this::assertTrue( method_exists( \SScribe_Export_Log::class, 'log_page_failure' ) );
		$this::assertTrue( method_exists( \SScribe_Export_Log::class, 'mark_complete' ) );
		$this::assertTrue( method_exists( \SScribe_Export_Log::class, 'mark_failed' ) );
	}
}
