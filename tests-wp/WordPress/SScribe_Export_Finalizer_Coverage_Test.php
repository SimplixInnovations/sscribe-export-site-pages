<?php
/**
 * Real-WordPress integration coverage tests for SScribe_Export_Finalizer.
 *
 * Drives `ajax_finalize_export` (the wp_ajax_sscribe_finalize_export
 * handler) through the full spectrum of early-out branches and into the
 * heavy `finalize_export()` private method, so the canonical Linux /
 * Xdebug coverage run can hit the otherwise-untested code paths in
 * `includes/traits/trait-sscribe-export-finalizer.php`.
 *
 * The trait's body is the second-largest slice of the batch processor
 * and contains most of the per-export error / packaging logic. Driving
 * it through real `_handleAjax()` calls exercises the trait's
 * collaborators (Session, Zip_Handler, Lock_Manager, Logger,
 * Diagnostics, Export_Log, Adaptive_Metrics, Auditor, Rate_Limiter) on
 * a real wpdb session, which is exactly the surface the production
 * AJAX pipeline uses.
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

require_once __DIR__ . '/SScribe_WP_Ajax_TestCase.php';

final class SScribe_Export_Finalizer_Coverage_Test extends SScribe_WP_Ajax_TestCase {

	/** Default nonce for the finalize action (matches the registered site). */
	private const NONCE_ACTION = 'sscribe_export_nonce';

	private ?SScribe_Session $session_helper = null;

	private int $admin_user_id = 0;

	/** A scratch temp_dir inside the SScribe workspace — required so the zip_handler's resolve_temp_directory() validation accepts it. */
	private string $temp_root = '';

	public function set_up(): void {
		parent::set_up();
		SScribe_Session::enable_test_mode();
		SScribe_Session::test_reset();

		// Activate the plugin so its AJAX endpoints are wired up.
		SScribe_Activator::activate( false );

		// Standard admin so we can pass the cap check.
		// NB: _setRole() returns void (the WP_Ajax_UnitTestCase implementation
		// just calls wp_set_current_user()), so we capture the resulting
		// user ID via wp_get_current_user()->ID instead.
		$this->_setRole( 'administrator' );
		$this->admin_user_id = (int) wp_get_current_user()->ID;

		// Create a temp dir inside the SScribe workspace. The zip_handler
		// rejects any source_dir whose realpath doesn't start with its
		// own export_dir — see SScribe_Zip_Handler::resolve_temp_directory()
		// for the validation rules.
		$zip_handler = new SScribe_Zip_Handler();
		$workspace   = $zip_handler->get_export_dir();
		$this->temp_root = $workspace . '/temp-' . bin2hex( random_bytes( 6 ) );
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
	// Early-out branches in ajax_finalize_export()
	// -----------------------------------------------------------------

	public function test_returns_invalid_session_id_when_session_id_missing(): void {
		$_POST['nonce']      = wp_create_nonce( self::NONCE_ACTION );
		$_POST['session_id'] = '';

		list( $success, $data, $raw ) = $this->dispatch_ajax( 'sscribe_finalize_export' );

		$this::assertFalse( $success, "ajax_finalize_export must reject empty session_id. Raw: {$raw}" );
		$this::assertSame( 'invalid_session_id', $data['code'] ?? null, "Raw: {$raw}" );
	}

	public function test_returns_session_expired_when_session_unknown(): void {
		$_POST['nonce']      = wp_create_nonce( self::NONCE_ACTION );
		$_POST['session_id'] = 'ssr_does_not_exist_' . uniqid();

		list( $success, $data, $raw ) = $this->dispatch_ajax( 'sscribe_finalize_export' );

		$this::assertFalse( $success, "ajax_finalize_export must reject an unknown session. Raw: {$raw}" );
		$this::assertSame( 'session_expired', $data['code'] ?? null, "Raw: {$raw}" );
	}

	public function test_returns_not_finalizing_for_pending_status(): void {
		$sid = $this->create_session(
			array(
				'status'   => 'pending',
				'temp_dir' => $this->temp_root,
			)
		);

		$_POST['nonce']      = wp_create_nonce( self::NONCE_ACTION );
		$_POST['session_id'] = $sid;

		list( $success, $data, $raw ) = $this->dispatch_ajax( 'sscribe_finalize_export' );

		$this::assertFalse( $success, "Raw: {$raw}" );
		$this::assertSame( 'not_finalizing', $data['code'] ?? null, "Raw: {$raw}" );
	}

	public function test_returns_session_cleared_for_pending_with_missing_temp_dir(): void {
		$sid = $this->create_session(
			array(
				'status'   => 'pending',
				'temp_dir' => $this->temp_root . '/never-existed-' . uniqid(),
			)
		);

		$_POST['nonce']      = wp_create_nonce( self::NONCE_ACTION );
		$_POST['session_id'] = $sid;

		list( $success, $data, $raw ) = $this->dispatch_ajax( 'sscribe_finalize_export' );

		// pending + missing temp_dir → automatic fail + 410 session_cleared.
		$this::assertFalse( $success, "Raw: {$raw}" );
		$this::assertSame( 'session_cleared', $data['code'] ?? null, "Raw: {$raw}" );

		// The session row must have been moved to 'failed' by the auto-clear.
		$fresh = $this->session_helper->get( $sid );
		$this::assertIsArray( $fresh, 'Session must still exist after auto-clear.' );
		$this::assertSame( 'failed', $fresh['status'] ?? '', 'Session must be marked failed.' );
	}

	public function test_returns_session_ownership_when_user_mismatches(): void {
		$sid = $this->create_session(
			array(
				'status'   => 'finalizing',
				'temp_dir' => $this->temp_root,
				'user_id'  => $this->admin_user_id + 9999,
			)
		);

		$_POST['nonce']      = wp_create_nonce( self::NONCE_ACTION );
		$_POST['session_id'] = $sid;

		list( $success, $data, $raw ) = $this->dispatch_ajax( 'sscribe_finalize_export' );

		$this::assertFalse( $success, "Raw: {$raw}" );
		$this::assertSame( 'session_ownership', $data['code'] ?? null, "Raw: {$raw}" );
	}

	public function test_returns_session_ownership_when_user_id_missing(): void {
		// create_session() always seeds a user_id via its defaults; for THIS
		// test we want the session row to have no user_id at all, so call
		// SScribe_Session::create() directly without that key.
		$sid = $this->session_helper->create(
			array(
				'total'      => 1,
				'processed'  => 0,
				'status'     => 'finalizing',
				'formats'    => array( 'html' ),
				'temp_dir'   => $this->temp_root,
				'start_time' => microtime( true ),
				'errors'     => array(),
				'page_ids'   => array(),
				'language'   => '',
				'post_type'  => 'page',
				'post_status' => 'publish',
			)
		);
		$this::assertNotSame( '', $sid, 'Session create() must return a non-empty session ID.' );

		$_POST['nonce']      = wp_create_nonce( self::NONCE_ACTION );
		$_POST['session_id'] = $sid;

		list( $success, $data, $raw ) = $this->dispatch_ajax( 'sscribe_finalize_export' );

		$this::assertFalse( $success, "Raw: {$raw}" );
		$this::assertSame( 'session_ownership', $data['code'] ?? null, "Raw: {$raw}" );
	}

	public function test_returns_not_finalizing_for_already_completed_status(): void {
		$sid = $this->create_session(
			array(
				'status'   => 'completed',
				'temp_dir' => $this->temp_root,
			)
		);

		$_POST['nonce']      = wp_create_nonce( self::NONCE_ACTION );
		$_POST['session_id'] = $sid;

		list( $success, $data, $raw ) = $this->dispatch_ajax( 'sscribe_finalize_export' );

		$this::assertFalse( $success, "Raw: {$raw}" );
		$this::assertSame( 'not_finalizing', $data['code'] ?? null, "Raw: {$raw}" );
	}

	public function test_returns_already_completing_for_recent_completing_since(): void {
		$sid = $this->create_session(
			array(
				'status'           => 'completing',
				'temp_dir'         => $this->temp_root,
				'completing_since' => time() - 5, // 5 seconds ago, well inside the lock TTL window.
			)
		);

		// Drop at least one file in temp_dir so count_temp_dir_files() returns >0
		// and lock_ttl is large enough that completing_since is "recent".
		file_put_contents( $this->temp_root . '/seed.txt', 'seed' );

		$_POST['nonce']      = wp_create_nonce( self::NONCE_ACTION );
		$_POST['session_id'] = $sid;

		list( $success, $data, $raw ) = $this->dispatch_ajax( 'sscribe_finalize_export' );

		$this::assertFalse( $success, "Raw: {$raw}" );
		$this::assertSame( 'already_completing', $data['code'] ?? null, "Raw: {$raw}" );
	}

	// -----------------------------------------------------------------
	// Happy paths through finalize_export() — exercise the heavy body.
	//
	// These tests emit *two* concatenated JSON responses when the inner
	// zero-files branch fires (the trait's outer catch also emits one).
	// We extract the FIRST JSON from the raw buffer using a non-strict
	// regex — that's the JSON the production browser actually receives.
	// -----------------------------------------------------------------

	public function test_drives_finalize_with_empty_temp_dir_to_no_files_error(): void {
		$page_id = $this->factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish' ) );

		// Session with a real, empty temp dir + finalizing status + at least one file format.
		$sid = $this->create_session(
			array(
				'status'      => 'finalizing',
				'temp_dir'    => $this->temp_root,
				'total'       => 1,
				'processed'   => 0,
				'formats'     => array( 'html' ),
				'start_time'  => microtime( true ) - 0.5,
				'page_ids'    => array( $page_id ),
				'language'    => '',
				'post_type'   => 'page',
				'post_status' => 'publish',
				'errors'      => array(),
			)
		);

		$_POST['nonce']      = wp_create_nonce( self::NONCE_ACTION );
		$_POST['session_id'] = $sid;

		list( , , $raw ) = $this->dispatch_ajax( 'sscribe_finalize_export' );

		// The empty-temp-dir path emits the "No files were generated"
		// guidance message — first JSON or second, the string is in the buffer.
		$this::assertStringContainsString(
			'No files were generated',
			(string) $raw,
			'Empty-temp-dir finalize must surface the no-files guidance. Raw: ' . $raw
		);
		// And the build_error_diagnostics_payload helper must have produced
		// the structured fix_steps for the empty-temp-dir path.
		$this::assertStringContainsString(
			'Select DOCX, HTML, or Markdown format instead of PDF-only',
			(string) $raw,
			'fix_steps must include the PDF fallback. Raw: ' . $raw
		);
	}

	public function test_drives_finalize_happy_path_with_html_files(): void {
		$page_id = $this->factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish' ) );

		// Place one real HTML file in the expected layout:
		//   <temp_dir>/<page_id>/.html
		// (matches the glob the finalizer uses at trait-sscribe-export-finalizer.php:288).
		$page_dir = $this->temp_root . '/' . $page_id;
		wp_mkdir_p( $page_dir );
		file_put_contents( $page_dir . '/index.html', '<html><body>hello</body></html>' );

		$sid = $this->create_session(
			array(
				'status'      => 'finalizing',
				'temp_dir'    => $this->temp_root,
				'total'       => 1,
				'processed'   => 1,
				'formats'     => array( 'html' ),
				'start_time'  => microtime( true ) - 1.0,
				'page_ids'    => array( $page_id ),
				'language'    => '',
				'post_type'   => 'page',
				'post_status' => 'publish',
				'errors'      => array(),
			)
		);

		$_POST['nonce']      = wp_create_nonce( self::NONCE_ACTION );
		$_POST['session_id'] = $sid;

		// The trait's outer `catch (\Throwable $e)` in ajax_finalize_export
		// also catches the wp_die exception from the success path's
		// wp_send_json_success, which appends a SECOND error JSON after the
		// first (legitimate) success JSON. The second wp_send_json_error
		// fires a "Cannot modify header information" warning because the
		// success path already sent the headers — PHPUnit counts that as a
		// test issue and exits 1 even though the assertions below pass.
		// Swallow JUST that warning here; everything else (real errors,
		// deprecations, etc.) keeps flowing through PHPUnit's handler.
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
			list( , , $raw ) = $this->dispatch_ajax( 'sscribe_finalize_export' );
		} finally {
			restore_error_handler();
		}

		// Extract the FIRST JSON for assertions — that's the one the browser
		// receives first and is the legitimate handler response.
		$first   = $this->extract_first_json( (string) $raw );
		$data    = $first['data'] ?? array();
		$success = (bool) ( $first['success'] ?? false );

		// Happy path: handler emits success with a download_url + filename.
		$this::assertTrue( $success, "Happy-path finalize must report success. Raw: {$raw}" );
		$this::assertSame( 'complete', $data['status'] ?? '', "Raw: {$raw}" );
		$this::assertArrayHasKey( 'download_url', $data, 'download_url must be set on success.' );
		$this::assertArrayHasKey( 'filename', $data, 'filename must be set on success.' );
		$this::assertSame( 1, (int) ( $data['pages'] ?? 0 ), 'pages must echo the session total.' );
		$this::assertSame( 100, (int) ( $data['percentage'] ?? -1 ), 'percentage must be 100 on success.' );

		// The session row must have been deleted on success.
		$fresh = $this->session_helper->get( $sid );
		$this::assertNull( $fresh, 'Session must be deleted after a successful finalize.' );
	}

	// -----------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------

	/**
	 * Create a session row owned by the current admin.
	 *
	 * @param array $overrides Session data fields to override on top of the defaults.
	 * @return string Session ID.
	 */
	private function create_session( array $overrides ): string {
		$defaults = array(
			'user_id'     => $this->admin_user_id,
			'total'       => 1,
			'processed'   => 0,
			'status'      => 'finalizing',
			'formats'     => array( 'html' ),
			'temp_dir'    => $this->temp_root,
			'start_time'  => microtime( true ),
			'errors'      => array(),
			'page_ids'    => array(),
			'language'    => '',
			'post_type'   => 'page',
			'post_status' => 'publish',
		);

		$data = array_merge( $defaults, $overrides );

		$sid = $this->session_helper->create( $data );
		$this::assertNotSame( '', $sid, 'Session create() must return a non-empty session ID.' );

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

	/**
	 * Extract the FIRST top-level JSON object from a possibly-concatenated
	 * raw AJAX response string. The trait-sscribe-export-finalizer's outer
	 * catch (line ~154 in ajax_finalize_export) catches the WPAjaxDie*
	 * exception that wp_send_json_* raises, so some code paths append a
	 * second JSON body after the legitimate first one — `json_decode()`
	 * refuses to parse the result, and dispatch_ajax() then returns an
	 * empty $data. This helper recovers the first object via regex.
	 *
	 * @param string $raw Raw response string from _last_response.
	 * @return array{success?: bool, data?: array<string,mixed>}|array{} Decoded first JSON, or [] if no object found.
	 */
	private function extract_first_json( string $raw ): array {
		if ( '' === $raw || '{' !== $raw[0] ) {
			return array();
		}
		// Find the matching closing brace for the first top-level object,
		// respecting string nesting. PHP's json_decode could do this but
		// also rejects trailing garbage; the regex scan tolerates the
		// concatenated-second-JSON artefact this test exposes.
		$depth = 0;
		$in_string = false;
		$escape    = false;
		$len       = strlen( $raw );
		for ( $i = 0; $i < $len; $i++ ) {
			$ch = $raw[ $i ];
			if ( $escape ) {
				$escape = false;
				continue;
			}
			if ( $in_string ) {
				if ( '\\' === $ch ) {
					$escape = true;
				} elseif ( '"' === $ch ) {
					$in_string = false;
				}
				continue;
			}
			if ( '"' === $ch ) {
				$in_string = true;
				continue;
			}
			if ( '{' === $ch ) {
				++$depth;
				continue;
			}
			if ( '}' === $ch ) {
				--$depth;
				if ( 0 === $depth ) {
					$first = substr( $raw, 0, $i + 1 );
					$decoded = json_decode( $first, true );
					return is_array( $decoded ) ? $decoded : array();
				}
			}
		}
		return array();
	}
}
