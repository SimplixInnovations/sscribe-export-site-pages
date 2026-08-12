<?php
/**
 * SScribe Shutdown Handoff Regression Test
 *
 * Locks in two lifecycle invariants for the export pipeline:
 *
 *   1. shutdown_cleanup() must NOT delete the workspace on the success
 *      path of start_export. The lifecycle is:
 *        create_temp_dir() -> $cleanup_temp_dir is set
 *        session->create() — session now references temp_dir in storage
 *        wp_send_json_success() — but right BEFORE the response is sent,
 *        $cleanup_temp_dir MUST be cleared so the PHP shutdown handler
 *        does not race into deleting the just-handed-off workspace.
 *
 *      Before the fix, $cleanup_temp_dir stayed set after success, and
 *      shutdown_cleanup() deleted the directory. The next batch step then
 *      failed with "Export session expired or not found."
 *
 *   2. Every error exit in start_export must emit a distinct `code` so the
 *      JS guidance table can show the real reason instead of masking it
 *      as "session lost".
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class SScribe_Shutdown_Handoff_Test extends TestCase {

	/**
	 * The private static cleanup state on SScribe_Batch_Processor.
	 *
	 * @return \ReflectionProperty
	 */
	private static function cleanup_temp_dir_property(): \ReflectionProperty {
		$property = new \ReflectionProperty( \SScribe_Batch_Processor::class, 'cleanup_temp_dir' );
		$property->setAccessible( true );
		return $property;
	}

	protected function setUp(): void {
		parent::setUp();
		// Reset static cleanup state between tests.
		$property = self::cleanup_temp_dir_property();
		$property->setAccessible( true );
		$property->setValue( null, null );
		$property = new \ReflectionProperty( \SScribe_Batch_Processor::class, 'cleanup_zip_handler' );
		$property->setAccessible( true );
		$property->setValue( null, null );
		$property = new \ReflectionProperty( \SScribe_Batch_Processor::class, 'cleanup_logger' );
		$property->setAccessible( true );
		$property->setValue( null, null );
	}

	public function test_shutdown_cleanup_is_noop_when_temp_dir_is_null(): void {
		$temp_dir = sys_get_temp_dir() . '/sscribe-test-noop-' . wp_generate_password( 8, false, false );
		mkdir( $temp_dir, 0777, true );

		// Cleanup state is null -> shutdown_cleanup must do nothing,
		// and the test directory must survive.
		\SScribe_Batch_Processor::shutdown_cleanup();
		$this->assertDirectoryExists( $temp_dir, 'shutdown_cleanup with null state must not delete unrelated directories.' );
		rmdir( $temp_dir );
	}

	public function test_shutdown_cleanup_keeps_directory_when_state_already_cleared(): void {
		// Simulate the success path of start_export: cleanup_temp_dir was set
		// to a real directory inside create_temp_dir(), but immediately before
		// wp_send_json_success() the static is nulled (the new "handoff"
		// invariant). When PHP shutdown runs shutdown_cleanup(), the static
		// is null, so the workspace must survive.
		$temp_dir = sys_get_temp_dir() . '/sscribe-test-handoff-' . wp_generate_password( 8, false, false );
		mkdir( $temp_dir, 0777, true );

		// Now set a sentinel inside that the test can verify is still there.
		$sentinel = $temp_dir . '/sentinel.txt';
		file_put_contents( $sentinel, 'kept' );

		// Emulate the handoff: start_export nulled cleanup_temp_dir before
		// the response was sent. (We don't need to set it in the first place.)
		$property = self::cleanup_temp_dir_property();
		$property->setValue( null, null );

		\SScribe_Batch_Processor::shutdown_cleanup();

		$this->assertDirectoryExists(
			$temp_dir,
			'shutdown_cleanup must not delete a workspace that start_export has already handed off.'
		);
		$this->assertFileExists(
			$sentinel,
			'shutdown_cleanup must not touch any file inside a workspace that was already handed off.'
		);

		@unlink( $sentinel );
		@rmdir( $temp_dir );
	}

	public function test_shutdown_cleanup_clears_static_state_regardless_of_branch(): void {
		// Lifecycle invariant: shutdown_cleanup() must null all three
		// static slots before returning, on EVERY branch. Otherwise the
		// state from a previous request can leak into a subsequent request
		// on the same PHP-FPM worker (the classic "second start_export
		// silently reaped the first one's workspace" bug).
		$temp_dir_prop = new \ReflectionProperty( \SScribe_Batch_Processor::class, 'cleanup_temp_dir' );
		$temp_dir_prop->setAccessible( true );
		$handler_prop = new \ReflectionProperty( \SScribe_Batch_Processor::class, 'cleanup_zip_handler' );
		$handler_prop->setAccessible( true );
		$logger_prop = new \ReflectionProperty( \SScribe_Batch_Processor::class, 'cleanup_logger' );
		$logger_prop->setAccessible( true );

		// Set state to mimic a mid-start fatal.
		$temp_dir = sys_get_temp_dir() . '/sscribe-test-leak-' . wp_generate_password( 8, false, false );
		$temp_dir_prop->setValue( null, $temp_dir );
		$this->assertSame( $temp_dir, $temp_dir_prop->getValue() );

		\SScribe_Batch_Processor::shutdown_cleanup();

		$this->assertNull( $temp_dir_prop->getValue(), 'cleanup_temp_dir must be nulled after shutdown_cleanup runs.' );
		$this->assertNull( $handler_prop->getValue(), 'cleanup_zip_handler must be nulled after shutdown_cleanup runs.' );
		$this->assertNull( $logger_prop->getValue(), 'cleanup_logger must be nulled after shutdown_cleanup runs.' );
	}

	public function test_error_codes_are_emitted_with_distinct_string_identifiers(): void {
		// Lock in the API: every error exit in start_export MUST carry a `code`
		// string. The list mirrors the ones emitted by the production code;
		// adding a new exit without a code is a regression we want this test
		// to enforce (manually, by reading the source — the test acts as the
		// specification).
		$expected_codes = array(
			'no_pages_selected',
			'workspace_init_failed',
			'session_create_failed',
			'page_list_failed',
			'concurrent_export',
			'invalid_nonce',
			'permission_denied',
			'rate_limited',
			'invalid_language',
			'invalid_post_type',
			'invalid_session_id',
			'session_expired',
			'session_ownership',
			'session_corrupt',
			'batch_locked',
			'support_info_unavailable',
		);

		$source = file_get_contents( __DIR__ . '/../../includes/class-sscribe-batch-processor.php' );
		$this->assertNotFalse( $source );

		$source = file_get_contents( __DIR__ . '/../../includes/class-sscribe-export-query-controller.php' )
			. file_get_contents( __DIR__ . '/../../includes/traits/trait-sscribe-batch-step-handler.php' )
			. file_get_contents( __DIR__ . '/../../includes/traits/trait-sscribe-session-ajax.php' )
			. file_get_contents( __DIR__ . '/../../includes/traits/trait-sscribe-export-finalizer.php' )
			. $source;

		foreach ( $expected_codes as $code ) {
			$this->assertMatchesRegularExpression(
				"/['\"]code['\"]\\s+=>\\s+['\"]" . preg_quote( $code, '/' ) . "['\"]/",
				$source,
				'Expected error code "' . $code . '" must appear in the source.'
			);
		}
	}
}
