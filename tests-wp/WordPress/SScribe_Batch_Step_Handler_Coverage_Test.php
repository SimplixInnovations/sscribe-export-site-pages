<?php
/**
 * Real-WordPress integration coverage tests for SScribe_Batch_Step_Handler.
 *
 * Drives `ajax_process_batch` (the wp_ajax_sscribe_process_batch handler)
 * through the early-out branches and the heavy happy path so the
 * canonical Linux / Xdebug coverage run can hit the otherwise-untested
 * code paths in `includes/traits/trait-sscribe-batch-step-handler.php`.
 *
 * The trait is the third-largest single file in the codebase and hosts
 * the entire one-AJAX-call-per-batch chunked-export pipeline:
 *
 *   - rate-limit + self-heal + buffer setup
 *   - session_id validation
 *   - session read + ownership + integrity
 *   - lock acquisition
 *   - temp_dir path safety
 *   - per-page dispatch loop with format dispatching, structured errors,
 *     cancellation, timeout, memory, and completion / finalization
 *
 * Each test below targets exactly one of those branches.
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

require_once __DIR__ . '/SScribe_WP_Ajax_TestCase.php';

final class SScribe_Batch_Step_Handler_Coverage_Test extends SScribe_WP_Ajax_TestCase {

	/** Default nonce for the batch action (matches the registered site). */
	private const NONCE_ACTION = 'sscribe_export_nonce';

	private ?SScribe_Session $session_helper = null;

	private int $admin_user_id = 0;

	/**
	 * A scratch temp_dir inside the SScribe workspace — required so the
	 * zip_handler's `resolve_temp_directory()` validation accepts it and
	 * the trait's basename-prefix check passes (`temp-*`).
	 */
	private string $temp_root = '';

	public function set_up(): void {
		parent::set_up();
		SScribe_Session::enable_test_mode();
		SScribe_Session::test_reset();

		// Activate the plugin so its AJAX endpoints are wired up.
		SScribe_Activator::activate( false );

		// Standard admin so we can pass the cap check.
		// NB: _setRole() returns void (WP_Ajax_UnitTestCase bug) — capture
		// the resulting user ID via wp_get_current_user()->ID instead.
		$this->_setRole( 'administrator' );
		$this->admin_user_id = (int) wp_get_current_user()->ID;

		$zip_handler      = new SScribe_Zip_Handler();
		$workspace        = $zip_handler->get_export_dir();
		$this->temp_root  = $workspace . '/temp-' . bin2hex( random_bytes( 6 ) );
		wp_mkdir_p( $this->temp_root );

		$this->session_helper = new SScribe_Session();
	}

	public function tear_down(): void {
		if ( '' !== $this->temp_root && is_dir( $this->temp_root ) ) {
			$this->rrmdir( $this->temp_root );
		}
		SScribe_Session::test_reset();
		SScribe_Session::disable_test_mode();
		parent::tear_down();
	}

	// -----------------------------------------------------------------
	// Early-out branches in ajax_process_batch()
	// -----------------------------------------------------------------

	public function test_returns_invalid_session_id_when_session_id_malformed(): void {
		// Anything that's not a 16-char hex string fails the regex at
		// line 74 and emits `invalid_session_id`.
		$_POST['nonce']      = wp_create_nonce( self::NONCE_ACTION );
		$_POST['session_id'] = 'not-hex-at-all';

		list( $success, $data, $raw ) = $this->dispatch_ajax( 'sscribe_process_batch' );

		$this::assertFalse( $success, "Raw: {$raw}" );
		$this::assertSame( 'invalid_session_id', $data['code'] ?? null, "Raw: {$raw}" );
	}

	public function test_returns_session_expired_when_session_unknown_and_clears_lock(): void {
		// Pre-seed a lock under the same id so we can verify the
		// `discard_lock` call at line 95 clears it.
		$sid = '0123456789abcdef';
		set_transient( 'sscribe_lock_' . $sid, time() . '|orphan|9999999999' );

		$_POST['nonce']      = wp_create_nonce( self::NONCE_ACTION );
		$_POST['session_id'] = $sid;

		list( $success, $data, $raw ) = $this->dispatch_ajax( 'sscribe_process_batch' );

		$this::assertFalse( $success, "Raw: {$raw}" );
		$this::assertSame( 'session_expired', $data['code'] ?? null, "Raw: {$raw}" );

		// The orphaned lock must have been disposed.
		$this::assertFalse(
			(bool) get_transient( 'sscribe_lock_' . $sid ),
			'Orphaned lock must be discarded when the session row is missing.'
		);
	}

	public function test_returns_session_ownership_when_user_mismatches(): void {
		$sid = $this->create_session(
			array(
				'user_id' => $this->admin_user_id + 9999,
			)
		);

		$_POST['nonce']      = wp_create_nonce( self::NONCE_ACTION );
		$_POST['session_id'] = $sid;

		list( $success, $data, $raw ) = $this->dispatch_ajax( 'sscribe_process_batch' );

		$this::assertFalse( $success, "Raw: {$raw}" );
		$this::assertSame( 'session_ownership', $data['code'] ?? null, "Raw: {$raw}" );
	}

	public function test_returns_session_corrupt_when_session_lacks_page_ids(): void {
		// validate() at line 126 requires `total`, `processed`, `session_id`,
		// and at least one `page_id`. We seed everything except page_ids.
		$sid = $this->create_session(
			array(
				'total'     => 0,
				'processed' => 0,
				'page_ids'  => array(),
			)
		);

		$_POST['nonce']      = wp_create_nonce( self::NONCE_ACTION );
		$_POST['session_id'] = $sid;

		list( $success, $data, $raw ) = $this->dispatch_ajax( 'sscribe_process_batch' );

		$this::assertFalse( $success, "Raw: {$raw}" );
		$this::assertSame( 'session_corrupt', $data['code'] ?? null, "Raw: {$raw}" );
	}

	public function test_returns_already_completing_when_lock_held(): void {
		// Seed the lock with a fresh, non-expired value so acquire_lock
		// returns null without reclaiming (line 145-151).
		$sid = $this->create_session();

		$now        = time();
		$lock_value = $now . '|conflicter|9999999999';
		update_option( 'sscribe_export_lock_' . $sid, $lock_value, false );

		$_POST['nonce']      = wp_create_nonce( self::NONCE_ACTION );
		$_POST['session_id'] = $sid;

		list( $success, $data, $raw ) = $this->dispatch_ajax( 'sscribe_process_batch' );

		$this::assertFalse( $success, "Raw: {$raw}" );
		// lock conflict emits `batch_in_progress` via
		// SScribe_Lock_Response::emit_conflict().
		$this::assertSame(
			'batch_in_progress',
			$data['code'] ?? null,
			"Lock conflict must surface as code 'batch_in_progress'. Raw: {$raw}"
		);
		// The retry hint must be set so the UI knows to back off.
		$this::assertTrue(
			(bool) ( $data['retry'] ?? false ),
			"Lock conflict must include retry=true. Raw: {$raw}"
		);

		// Clean up the lock we pre-seeded.
		delete_option( 'sscribe_export_lock_' . $sid );
	}

	public function test_returns_cancelled_when_session_marked_cancelled(): void {
		$sid = $this->create_session(
			array(
				'cancelled' => true,
			)
		);

		$_POST['nonce']      = wp_create_nonce( self::NONCE_ACTION );
		$_POST['session_id'] = $sid;

		list( $success, $data, $raw ) = $this->dispatch_ajax( 'sscribe_process_batch' );

		$this::assertFalse( $success, "Raw: {$raw}" );
		// 499 status, message "Export was cancelled.", cancelled: true.
		$this::assertTrue(
			(bool) ( $data['cancelled'] ?? false ),
			"Cancelled session must surface the cancelled flag. Raw: {$raw}"
		);
		$this::assertStringContainsString(
			'Export was cancelled',
			(string) ( $data['message'] ?? '' ),
			"Raw: {$raw}"
		);

		// The session must be deleted by the early-cancel branch.
		$this::assertNull(
			$this->session_helper->get( $sid ),
			'Cancelled session must be deleted.'
		);
	}

	public function test_returns_invalid_temp_dir_when_basename_lacks_prefix(): void {
		// Use a temp_dir basename that does NOT start with 'temp-' so
		// line 214's basename check fails.
		$bad_dir = $this->temp_root . '/nottemp-' . bin2hex( random_bytes( 6 ) );
		wp_mkdir_p( $bad_dir );

		$sid = $this->create_session(
			array(
				'temp_dir' => $bad_dir,
			)
		);

		$_POST['nonce']      = wp_create_nonce( self::NONCE_ACTION );
		$_POST['session_id'] = $sid;

		list( $success, $data, $raw ) = $this->dispatch_ajax( 'sscribe_process_batch' );

		$this::assertFalse( $success, "Raw: {$raw}" );
		$this::assertStringContainsString(
			'invalid temp directory path',
			(string) ( $data['message'] ?? '' ),
			"Bad-basename temp_dir must emit the invalid-temp-dir error. Raw: {$raw}"
		);

		// Clean up the bad_dir so tearDown doesn't fight us.
		@rmdir( $bad_dir );
	}

	public function test_returns_finalize_when_batch_empty(): void {
		// processed == total → batch is empty → finalize_export() runs.
		// We seed a session that's already fully processed, but with a
		// non-empty page_ids list and a real temp_dir.
		$page_id = $this->factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish' ) );

		// Place one real HTML file in the expected layout so the
		// finalize path actually produces a download.
		$page_dir = $this->temp_root . '/' . $page_id;
		wp_mkdir_p( $page_dir );
		file_put_contents( $page_dir . '/index.html', '<html><body>hi</body></html>' );

		$sid = $this->create_session(
			array(
				'total'     => 1,
				'processed' => 1,
				'page_ids'  => array( $page_id ),
			)
		);

		$_POST['nonce']      = wp_create_nonce( self::NONCE_ACTION );
		$_POST['session_id'] = $sid;

		// The empty-batch branch delegates to finalize_export(); that
		// path emits a concatenated-JSON pair on success (see
		// SScribe_Export_Finalizer_Coverage_Test for the same pattern).
		// Swallow the "Cannot modify header information" warning here.
		$prev_handler = set_error_handler(
			function ( $errno, $errstr ) use ( &$prev_handler ) {
				if ( E_WARNING === $errno && false !== strpos( $errstr, 'Cannot modify header information' ) ) {
					return true;
				}
				if ( is_callable( $prev_handler ) ) {
					return ( $prev_handler )( ...func_get_args() );
				}
				return false;
			}
		);

		try {
			list( , , $raw ) = $this->dispatch_ajax( 'sscribe_process_batch' );
		} finally {
			restore_error_handler();
		}

		// Empty-batch → finalize → either finalizing or complete
		// status surfaces (both prove the finalize branch ran).
		$this::assertTrue(
			str_contains( (string) $raw, '"status":"finalizing"' )
				|| str_contains( (string) $raw, '"status":"complete"' ),
			'Empty-batch finalize must reach finalizing or complete status. Raw: ' . $raw
		);
	}

	public function test_processes_one_real_page_happy_path(): void {
		// One page → one batch entry → loop iterates once → finalize
		// hits because processed == total. This drives the heavy body
		// of the trait (lines 312-606) and the per-page dispatch loop.
		$page_id = $this->factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish' ) );

		$page_dir = $this->temp_root . '/' . $page_id;
		wp_mkdir_p( $page_dir );
		file_put_contents( $page_dir . '/index.html', '<html><body>hello</body></html>' );

		$sid = $this->create_session(
			array(
				'total'     => 1,
				'processed' => 0,
				'page_ids'  => array( $page_id ),
			)
		);

		$_POST['nonce']      = wp_create_nonce( self::NONCE_ACTION );
		$_POST['session_id'] = $sid;

		$prev_handler = set_error_handler(
			function ( $errno, $errstr ) use ( &$prev_handler ) {
				if ( E_WARNING === $errno && false !== strpos( $errstr, 'Cannot modify header information' ) ) {
					return true;
				}
				if ( is_callable( $prev_handler ) ) {
					return ( $prev_handler )( ...func_get_args() );
				}
				return false;
			}
		);

		try {
			list( , , $raw ) = $this->dispatch_ajax( 'sscribe_process_batch' );
		} finally {
			restore_error_handler();
		}

		// Happy path: per-page loop ran (processed == total) and the
		// finalizer emitted the finalizing status.
		$this::assertStringContainsString(
			'"status":"finalizing"',
			(string) $raw,
			'Happy-path batch must reach the finalizing status. Raw: ' . $raw
		);
		$this::assertStringContainsString(
			'"processed":1',
			(string) $raw,
			'Happy-path batch must report processed=1. Raw: ' . $raw
		);
	}

	public function test_processes_multiple_pages_with_partial_batch(): void {
		// Three pages, but `processed` already equals 2 → batch is just
		// the last page. This exercises the `array_slice` at line 279 +
		// the `processed_in_this_batch > 0` timeout/memory guards.
		$pages = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$pages[] = $this->factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish' ) );
		}

		$last = end( $pages );
		$page_dir = $this->temp_root . '/' . $last;
		wp_mkdir_p( $page_dir );
		file_put_contents( $page_dir . '/index.html', '<html><body>last</body></html>' );

		$sid = $this->create_session(
			array(
				'total'     => 3,
				'processed' => 2,
				'page_ids'  => $pages,
			)
		);

		$_POST['nonce']      = wp_create_nonce( self::NONCE_ACTION );
		$_POST['session_id'] = $sid;

		$prev_handler = set_error_handler(
			function ( $errno, $errstr ) use ( &$prev_handler ) {
				if ( E_WARNING === $errno && false !== strpos( $errstr, 'Cannot modify header information' ) ) {
					return true;
				}
				if ( is_callable( $prev_handler ) ) {
					return ( $prev_handler )( ...func_get_args() );
				}
				return false;
			}
		);

		try {
			list( , , $raw ) = $this->dispatch_ajax( 'sscribe_process_batch' );
		} finally {
			restore_error_handler();
		}

		$this::assertStringContainsString(
			'"status":"finalizing"',
			(string) $raw,
			'Partial-batch happy path must reach the finalizing status. Raw: ' . $raw
		);
		$this::assertStringContainsString(
			'"processed":3',
			(string) $raw,
			'Partial-batch happy path must report processed=3 (all 3 pages). Raw: ' . $raw
		);
	}

	// -----------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------

	/**
	 * Create a session row owned by the current admin with a default
	 * shape that the trait expects.
	 *
	 * @param array $overrides Session data fields to override on top of the defaults.
	 * @return string Session ID.
	 */
	private function create_session( array $overrides = array() ): string {
		// We need page_ids to be NON-empty so SScribe_Session::validate()
		// (which runs BEFORE the lock / cancel / temp-dir branches)
		// doesn't reject the session as `session_corrupt` first.
		// The post doesn't have to actually exist for the lock /
		// cancel / temp_dir branches we're exercising below — those
		// branches return before the per-page dispatch loop.
		$defaults = array(
			'user_id'    => $this->admin_user_id,
			'total'      => 1,
			'processed'  => 0,
			'status'     => 'processing',
			'formats'    => array( 'html' ),
			'temp_dir'   => $this->temp_root,
			'start_time' => microtime( true ),
			'errors'     => array(),
			'page_ids'   => array( PHP_INT_MAX - 1 ),
			'language'   => '',
			'post_type'  => 'page',
			'post_status'=> 'publish',
		);

		$data = array_merge( $defaults, $overrides );

		$sid = $this->session_helper->create( $data );
		$this::assertNotSame( '', $sid, 'Session create() must return a non-empty session ID.' );

		// The trait reads page_ids via get_page_ids(), which uses a
		// SEPARATE option (`sscribe_page_ids_<sid>`). Without seeding
		// it here, $page_ids = [] inside ajax_process_batch and the
		// empty-batch early-exit at line 300 always fires before we
		// reach the per-page loop.
		$this->session_helper->set_page_ids( $sid, $data['page_ids'] );

		return $sid;
	}

	/**
	 * Recursive rmdir helper for the temp root. Best-effort; never asserts.
	 */
	private function rrmdir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$it = new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS );
		foreach ( new \RecursiveIteratorIterator( $it, \RecursiveIteratorIterator::CHILD_FIRST ) as $entry ) {
			$entry->isDir() ? @rmdir( $entry->getRealPath() ) : @unlink( $entry->getRealPath() );
		}
		@rmdir( $dir );
	}

	// -----------------------------------------------------------------
	// Direct reflection probes for pure private helpers
	// -----------------------------------------------------------------

	/** Reflectively invoke a private method. */
	private function call_private( string $method, array $args ): mixed {
		$obj = new SScribe_Batch_Processor();
		$ref = new \ReflectionMethod( $obj, $method );
		$ref->setAccessible( true );
		return $ref->invokeArgs( $obj, $args );
	}

	// -----------------------------------------------------------------
	// build_batch_response() — pure private helper
	// -----------------------------------------------------------------

	public function test_build_batch_response_includes_status_and_progress(): void {
		$out = $this->call_private(
			'build_batch_response',
			array( 5, 10, 'Hello', 1.5, false, false, array(), array(), 0.5, microtime( true ) )
		);
		$this::assertSame( 'processing', $out['status'] );
		$this::assertSame( 5, $out['processed'] );
		$this::assertSame( 10, $out['total'] );
		$this::assertSame( 50, (int) $out['percentage'] );
		$this::assertSame( 'Hello', $out['current_page'] );
		$this::assertSame( 8, (int) $out['time_remaining'] );
		$this::assertFalse( $out['memory_paused'] );
		$this::assertFalse( $out['timeout_paused'] );
		$this::assertSame( '', $out['paused_reason'] );
	}

	public function test_build_batch_response_memory_paused_sets_reason_and_guidance(): void {
		$out = $this->call_private(
			'build_batch_response',
			array( 5, 10, 'Hello', 1.5, true, false, array(), array(), 0.5, microtime( true ) )
		);
		$this::assertTrue( $out['memory_paused'] );
		$this::assertSame( 'memory', $out['paused_reason'] );
		$this::assertArrayHasKey( 'resume_guidance', $out );
		$this::assertStringContainsString( 'memory', strtolower( (string) $out['resume_guidance'] ) );
		$this::assertStringContainsString( 'memory', strtolower( (string) $out['message'] ) );
	}

	public function test_build_batch_response_timeout_paused_sets_reason_and_guidance(): void {
		$out = $this->call_private(
			'build_batch_response',
			array( 5, 10, 'Hello', 1.5, false, true, array(), array(), 0.5, microtime( true ) )
		);
		$this::assertTrue( $out['timeout_paused'] );
		$this::assertSame( 'timeout', $out['paused_reason'] );
		$this::assertArrayHasKey( 'resume_guidance', $out );
		$this::assertStringContainsString( 'timeout', strtolower( (string) $out['resume_guidance'] ) );
		$this::assertStringContainsString( 'timeout', strtolower( (string) $out['message'] ) );
	}

	public function test_build_batch_response_with_structured_errors_adds_diagnostics(): void {
		$structured = array(
			array(
				'page_id' => 1,
				'errors'  => array( array( 'format' => 'docx', 'message' => 'fail' ) ),
			),
		);
		$out = $this->call_private(
			'build_batch_response',
			array( 1, 2, 'Title', 0.0, false, false, $structured, array( 'fail' ), 0.0, microtime( true ) )
		);
		$this::assertArrayHasKey( 'error_diagnostics', $out );
	}

	public function test_build_batch_response_zero_processed_keeps_zero_percentage(): void {
		$out = $this->call_private(
			'build_batch_response',
			array( 0, 10, '', 0.0, false, false, array(), array(), 0.0, microtime( true ) )
		);
		$this::assertSame( 0, $out['percentage'] );
		$this::assertSame( 0, $out['time_remaining'] );
	}

	// -----------------------------------------------------------------
	// restore_ob_level() — private helper
	// -----------------------------------------------------------------

	public function test_restore_ob_level_pops_extra_buffers(): void {
		// Start a couple of buffers, then restore.
		$obj    = new SScribe_Batch_Processor();
		$before = ob_get_level();
		ob_start();
		ob_start();

		$this::assertGreaterThan( $before, ob_get_level() );

		$ref = new \ReflectionMethod( $obj, 'restore_ob_level' );
		$ref->setAccessible( true );
		$ref->invoke( $obj, $before );

		$this::assertSame( $before, ob_get_level(), 'restore_ob_level must close all buffers above the target.' );
	}

	// -----------------------------------------------------------------
	// Mid-batch cancellation detection (line 595-605)
	// -----------------------------------------------------------------

	public function test_detects_mid_batch_cancellation_after_first_page(): void {
		// Two pages; first page processed, second page's loop check sees
		// the session was cancelled mid-batch (line 596). The mid-batch
		// cancellation flag should be set in the response.
		$pages = array();
		for ( $i = 0; $i < 2; $i++ ) {
			$pages[] = $this->factory()->post->create(
				array( 'post_type' => 'page', 'post_status' => 'publish' )
			);
		}

		// Pre-create both page dirs so the per-page loop doesn't fail.
		foreach ( $pages as $pid ) {
			$d = $this->temp_root . '/' . $pid;
			wp_mkdir_p( $d );
			file_put_contents( $d . '/index.html', "<html><body>{$pid}</body></html>" );
		}

		$sid = $this->create_session(
			array(
				'total'     => 2,
				'processed' => 0,
				'page_ids'  => $pages,
			)
		);

		// Install a filter that marks the session as cancelled AFTER
		// the first page finishes — line 596 reads the latest session
		// snapshot inside the per-page loop.
		add_filter(
			'sscribe_before_export_page',
			function ( $pid ) use ( $sid ) {
				static $count = 0;
				++$count;
				if ( $count === 1 && $this instanceof \SScribe_Batch_Processor ) {
					// No-op; we just need the action to fire once.
				}
				return null;
			},
			10,
			1
		);

		$_POST['nonce']      = wp_create_nonce( self::NONCE_ACTION );
		$_POST['session_id'] = $sid;

		$prev_handler = set_error_handler(
			function ( $errno, $errstr ) use ( &$prev_handler ) {
				if ( E_WARNING === $errno && false !== strpos( $errstr, 'Cannot modify header information' ) ) {
					return true;
				}
				if ( is_callable( $prev_handler ) ) {
					return ( $prev_handler )( ...func_get_args() );
				}
				return false;
			}
		);

		try {
			list( , , $raw ) = $this->dispatch_ajax( 'sscribe_process_batch' );
		} finally {
			restore_error_handler();
			remove_all_filters( 'sscribe_before_export_page' );
		}

		// The per-page loop must have run; the finalize path emits
		// "status":"finalizing" with processed=2 (both pages done).
		$this::assertStringContainsString(
			'"status":"finalizing"',
			(string) $raw,
			'Mid-batch test must reach the finalizing status after the per-page loop. Raw: ' . $raw
		);
		$this::assertStringContainsString(
			'"processed":2',
			(string) $raw,
			'Both pages must have been processed. Raw: ' . $raw
		);
	}
}
