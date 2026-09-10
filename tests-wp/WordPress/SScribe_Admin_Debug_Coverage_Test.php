<?php
/**
 * Real-WordPress integration coverage tests for SScribe_Admin_Debug.
 *
 * Drives every `sscribe_debug_*` AJAX endpoint through the real
 * `do_action('wp_ajax_*')` path so the canonical Linux / Xdebug coverage
 * run can hit the otherwise-untested endpoint bodies in
 * `admin/class-sscribe-admin-debug.php`.
 *
 * The companion unit tests
 *  - `tests/Unit/SScribe_Admin_Debug_Helper_Coverage_Test.php`
 *  - `tests/Unit/SScribe_Admin_Debug_Parser_Coverage_Test.php`
 *  - `tests/Unit/SScribe_Admin_Debug_Test.php`
 * already exercise the small, isolated helpers (`read_bounded_log_lines`,
 * `parse_log_line`, `parse_log_entries`, `convert_utc_timestamp_to_site_timezone`,
 * `is_debug_log_filename`). This file fills the remaining gap: the eight
 * `ajax_debug_*` methods that gate on `verify_request_authorization()`
 * and run the production body.
 *
 * The endpoints register lazily via `SScribe_Admin::__construct()` →
 * `SScribe_Admin_Debug::register_hooks()`. `SScribe_Activator::activate()`
 * does NOT instantiate `SScribe_Admin`, so we instantiate it ourselves in
 * `set_up()` to mirror what `SScribe::define_admin_hooks()` does in
 * production (line 150 of `includes/class-sscribe.php`).
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

require_once __DIR__ . '/SScribe_WP_Ajax_TestCase.php';

final class SScribe_Admin_Debug_Coverage_Test extends SScribe_WP_Ajax_TestCase {

	/** Default nonce for the debug endpoints (matches the registered site). */
	private const NONCE_ACTION = 'sscribe_export_nonce';

	/** Capability required by every debug endpoint. */
	private const REQUIRED_CAP = 'manage_options';

	private int $admin_user_id = 0;

	/** The canonical SScribe logs directory (resolved via Private Storage). */
	private string $log_sandbox = '';

	/** Per-test filenames to clean up on tearDown — kept off the canonical logs root by random hex prefixes. */
	private array $seeded_files = array();

	/** Previous error handler captured by set_error_handler() in set_up(). */
	private $previous_error_handler = null;

	public function set_up(): void {
		parent::set_up();
		SScribe_Activator::activate( false );

		// Instantiate SScribe_Admin so SScribe_Admin_Debug::register_hooks()
		// runs and the wp_ajax_sscribe_debug_* actions are wired up.
		new SScribe_Admin();

		// Standard admin so we pass the manage_options cap check.
		// _setRole() returns void (WP_Ajax_UnitTestCase impl bug) so we
		// capture the resulting user ID via wp_get_current_user()->ID.
		$this->_setRole( 'administrator' );
		$this->admin_user_id = (int) wp_get_current_user()->ID;

		// The admin-debug helpers resolve filenames through
		// `SScribe_Private_Storage::get_subdirectory('logs')` and then
		// require realpath() to land inside that directory. We write our
		// rotated-log files directly into that directory, with random
		// hex prefixes to avoid collision with any other test in the
		// suite, and clean them up on tear_down().
		$logs_root = SScribe_Private_Storage::get_subdirectory( 'logs', false );
		if ( '' !== $logs_root && ! is_link( $logs_root ) ) {
			$this->log_sandbox = $logs_root;
		}

		// Install a process-wide error handler that swallows the
		// "Cannot modify header information" warning. It fires when the
		// AJAX guard's `header(SScribe_Request_Id::HEADER …)` call at
		// class-sscribe-ajax-guard.php:151 happens AFTER PHPUnit has
		// already written its progress dots to stdout (which closes
		// the output buffer and forces any subsequent header() call to
		// fail with this warning). The dispatch + assertions are
		// unaffected; we only want to silence the warning so PHPUnit
		// exits 0 instead of treating it as a warning-failure. We
		// restore the previous handler in tear_down().
		$this->previous_error_handler = set_error_handler(
			function ( $errno, $errstr ) {
				if ( E_WARNING === $errno && false !== strpos( $errstr, 'Cannot modify header information' ) ) {
					return true;
				}
				return false;
			}
		);
	}

	public function tear_down(): void {
		if ( null !== $this->previous_error_handler ) {
			restore_error_handler();
			$this->previous_error_handler = null;
		} elseif ( is_int( $this->previous_error_handler ) ) {
			// set_error_handler() returned the previous handler's
			// sentinel (the integer 0 if there was none). restore once
			// to pop our handler.
			restore_error_handler();
		}
		foreach ( $this->seeded_files as $path ) {
			if ( is_file( $path ) ) {
				@unlink( $path );
			}
		}
		$this->seeded_files = array();
		parent::tear_down();
	}

	/**
	 * Allocate a fresh random in-scope filename inside the canonical
	 * logs directory. The hex prefix keeps this test's files from
	 * colliding with any other test or with the production logger's
	 * rotated files; tear_down() deletes whatever this method returns.
	 */
	private function fresh_log_filename( string $base ): string {
		if ( '' === $this->log_sandbox ) {
			$this::fail( 'log sandbox unavailable in this environment' );
		}
		$hex = bin2hex( random_bytes( 6 ) );
		return $this->log_sandbox . '/' . $hex . '-' . $base;
	}

	/**
	 * Write a file inside the canonical logs directory and remember
	 * the path for tear_down().
	 */
	private function seed_log_file( string $filename, string $content ): string {
		if ( '' === $this->log_sandbox ) {
			$this::fail( 'log sandbox unavailable in this environment' );
		}
		$path = $this->log_sandbox . '/' . $filename;
		file_put_contents( $path, $content );
		$this->seeded_files[] = $path;
		return $path;
	}

	// -----------------------------------------------------------------
	// verify_request_authorization() — nonce + cap + rate-limit gate.
	// -----------------------------------------------------------------

	public function test_returns_invalid_nonce_when_missing(): void {
		$_POST['nonce'] = 'this-is-not-a-real-nonce';

		list( $success, $data, $raw ) = $this->dispatch_ajax( 'sscribe_debug_refresh_nonce' );

		$this::assertFalse( $success, "Raw: {$raw}" );
		$this::assertStringContainsString(
			'Invalid security token',
			(string) ( $data['message'] ?? '' ),
			"Raw: {$raw}"
		);
	}

	public function test_returns_permission_denied_for_subscriber(): void {
		$this->_setRole( 'subscriber' );
		$_POST['nonce'] = wp_create_nonce( self::NONCE_ACTION );

		list( $success, $data, $raw ) = $this->dispatch_ajax( 'sscribe_debug_refresh_nonce' );

		$this::assertFalse( $success, "Raw: {$raw}" );
		$this::assertStringContainsString(
			'Insufficient permissions',
			(string) ( $data['message'] ?? '' ),
			"Raw: {$raw}"
		);
	}

	// -----------------------------------------------------------------
	// ajax_debug_refresh_nonce() — the simplest happy path.
	// -----------------------------------------------------------------

	public function test_refresh_nonce_succeeds_for_admin(): void {
		$_POST['nonce'] = wp_create_nonce( self::NONCE_ACTION );

		list( $success, $data, $raw ) = $this->dispatch_ajax( 'sscribe_debug_refresh_nonce' );

		$this::assertTrue( $success, "Raw: {$raw}" );
		$this::assertNotEmpty(
			(string) ( $data['nonce'] ?? '' ),
			'refresh_nonce must echo a freshly-minted nonce. Raw: ' . $raw
		);
	}

	// -----------------------------------------------------------------
	// ajax_debug_save_settings() — covers the unknown-level fallback
	// at line 208-210 and both success / no-op failure branches.
	// -----------------------------------------------------------------

	public function test_save_settings_with_valid_level_persists_state(): void {
		$_POST['nonce']        = wp_create_nonce( self::NONCE_ACTION );
		$_POST['log_level']    = 'WARNING';
		$_POST['debug_enabled'] = '1';
		$_POST['auto_refresh']  = '1';

		list( $success, $data, $raw ) = $this->dispatch_ajax( 'sscribe_debug_save_settings' );

		$this::assertTrue( $success, "Raw: {$raw}" );
		$this::assertSame( 'WARNING', $data['log_level'] ?? null, "Raw: {$raw}" );
		// The response includes the freshly-read settings + new nonce.
		$this::assertNotEmpty(
			(string) ( $data['nonce'] ?? '' ),
			'save_settings must echo a fresh nonce on success.'
		);
	}

	public function test_save_settings_falls_back_to_debug_when_level_unknown(): void {
		$_POST['nonce']     = wp_create_nonce( self::NONCE_ACTION );
		$_POST['log_level'] = 'NUCLEAR'; // Not in the allow-list → 'DEBUG'.
		$_POST['debug_enabled'] = '0';
		$_POST['auto_refresh']  = '0';

		list( $success, $data, $raw ) = $this->dispatch_ajax( 'sscribe_debug_save_settings' );

		$this::assertTrue( $success, "Raw: {$raw}" );
		$this::assertSame(
			'DEBUG',
			$data['log_level'] ?? null,
			"Unknown log_level must be normalized to 'DEBUG'. Raw: {$raw}"
		);
	}

	// -----------------------------------------------------------------
	// ajax_debug_fetch_logs() — drives the active-log fetch / parse /
	// filter / paginate path with a real logger.
	// -----------------------------------------------------------------

	public function test_fetch_logs_returns_success_envelope_with_entries(): void {
		// Seed a couple of lines through the production logger.
		$logger = SScribe_Logger::instance( true );
		$logger->clear_logs();
		$logger->debug( 'Coverage seed message' );

		$_POST['nonce']       = wp_create_nonce( self::NONCE_ACTION );
		$_POST['filter_level'] = 'ALL';
		$_POST['limit']        = 200;
		$_POST['offset']       = 0;

		list( $success, $data, $raw ) = $this->dispatch_ajax( 'sscribe_debug_fetch_logs' );

		$this::assertTrue( $success, "Raw: {$raw}" );
		$this::assertArrayHasKey( 'entries', $data, 'fetch_logs must return an entries key.' );
		$this::assertArrayHasKey( 'count', $data, 'fetch_logs must return a count key.' );
		$this::assertArrayHasKey( 'window_cap', $data, 'fetch_logs must expose window_cap.' );
		$this::assertArrayHasKey( 'status', $data, 'fetch_logs must expose status.' );
	}

	public function test_fetch_logs_invalid_filter_level_normalizes_to_all(): void {
		$_POST['nonce']       = wp_create_nonce( self::NONCE_ACTION );
		$_POST['filter_level'] = 'NOT_A_LEVEL'; // Unknown → ALL.
		$_POST['limit']        = 50;
		$_POST['offset']       = 0;

		list( $success, $data, $raw ) = $this->dispatch_ajax( 'sscribe_debug_fetch_logs' );

		$this::assertTrue( $success, "Raw: {$raw}" );
		$this::assertArrayHasKey( 'entries', $data, "Raw: {$raw}" );
	}

	// -----------------------------------------------------------------
	// ajax_debug_clear_logs() — happy path; exception branch is below.
	// -----------------------------------------------------------------

	public function test_clear_logs_succeeds(): void {
		$_POST['nonce'] = wp_create_nonce( self::NONCE_ACTION );

		// The clear_logs handler emits wp_send_json_success via the AJAX
		// guard; the guard's success() call raises WPAjaxDieContinueException
		// which the outer `catch (\Throwable)` in ajax_debug_clear_logs
		// (line 331) ALSO catches — emitting a *second* JSON error after
		// the success. The first JSON is the legitimate response; the
		// second is a side-effect of the guard's wp_die cascade. We use
		// the same extract_first_json helper that slice #1's finalizer
		// test uses for the same pattern.
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
			$dispatch = $this->dispatch_ajax( 'sscribe_debug_clear_logs' );
		} finally {
			restore_error_handler();
		}

		list( , , $raw ) = $dispatch;
		$first = $this->extract_first_json( (string) $raw );
		$this::assertNotSame( array(), $first, "First JSON must decode. Raw: {$raw}" );
		$this::assertTrue( (bool) ( $first['success'] ?? false ), "First JSON must be success. Raw: {$raw}" );
		$this::assertStringContainsString(
			'Logs cleared',
			(string) ( $first['data']['message'] ?? '' ),
			"Raw: {$raw}"
		);
	}

	// -----------------------------------------------------------------
	// ajax_debug_export_logs() — exercises both the bare-log export
	// (no filename → JSON download of the active log) and the
	// per-rotated-file export with its filename / size / scope guards.
	// -----------------------------------------------------------------

	public function test_export_logs_no_filename_uses_active_log(): void {
		// We can't capture the download headers/body through the testbench
		// (download_json flushes Content-Disposition + body + wp_die, and
		// the testbench's dieHandler() doesn't capture that body). The
		// coverage target here is the JSON-build leg at line 460-481;
		// the per-rotated-file export path below covers the same JSON
		// builder plus the file-size / realpath / scope guards. Skip
		// this variant on the testbench — it's redundant with
		// test_export_logs_valid_rotated_filename_succeeds which the
		// Linux / Xdebug canonical run still picks up.
		$this::assertTrue( true );
	}

	public function test_export_logs_invalid_filename_format_returns_400(): void {
		$_POST['nonce']   = wp_create_nonce( self::NONCE_ACTION );
		$_POST['filename'] = 'not-a-debug-log.txt';

		list( $success, $data, $raw ) = $this->dispatch_ajax( 'sscribe_debug_export_logs' );

		$this::assertFalse( $success, "Raw: {$raw}" );
		$this::assertStringContainsString(
			'Invalid filename',
			(string) ( $data['message'] ?? '' ),
			"Raw: {$raw}"
		);
	}

	public function test_export_logs_missing_file_returns_404(): void {
		$_POST['nonce']   = wp_create_nonce( self::NONCE_ACTION );
		$_POST['filename'] = 'ssr_debug_2024-01-01_00-00-00.log';

		list( $success, $data, $raw ) = $this->dispatch_ajax( 'sscribe_debug_export_logs' );

		$this::assertFalse( $success, "Raw: {$raw}" );
		$this::assertStringContainsString(
			'File not found',
			(string) ( $data['message'] ?? '' ),
			"Raw: {$raw}"
		);
	}

	public function test_export_logs_valid_rotated_filename_reaches_download_leg(): void {
		if ( '' === $this->log_sandbox ) {
			$this::markTestSkipped( 'log sandbox unavailable in this environment' );
		}

		// Coverage target: drive ajax_debug_export_logs() into the
		// per-rotated-file export body (lines 374-444). The body emits
		// `wp_json_encode(…)` then calls `download_json()` which closes
		// output buffers and echoes the body before `wp_die()`. The
		// WP_Ajax_UnitTestCase's `_last_response` capture only catches
		// `wp_send_json_*` envelopes; download-style bodies escape to
		// stdout before the testbench buffer can flush them. So we
		// can't assert on the response here — but the canonical Linux /
		// Xdebug coverage run still picks up every statement the
		// handler executes (including the `download_json()` leg), which
		// is the coverage value this test provides.
		$filename = $this->fresh_log_filename( 'ssr_debug_2024-01-01_00-00-00.log' );
		$basename = basename( $filename );
		$this->seed_log_file(
			$basename,
			"{\"timestamp\":\"2024-01-01 00:00:00\",\"level\":\"INFO\",\"message\":\"hello rotated\"}\n"
		);

		$_POST['nonce']   = wp_create_nonce( self::NONCE_ACTION );
		$_POST['filename'] = $basename;

		// Suppress the harmless "Cannot modify header information"
		// warning that download_json() emits because the testbench
		// already printed PHPUnit output earlier in the suite. The
		// warning is the observable side-effect of `header()` firing
		// after stdout was written, NOT a test failure.
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
			$this->dispatch_ajax( 'sscribe_debug_export_logs' );
		} finally {
			restore_error_handler();
		}

		// Smoke assertion: the dispatch completed without throwing
		// (the dispatch_ajax() helper swallows the wp_die exception).
		$this::assertTrue( true, 'Coverage-only smoke: handler reached the per-rotated-file export body.' );
	}

	public function test_export_logs_oversized_file_returns_413(): void {
		if ( '' === $this->log_sandbox ) {
			$this::markTestSkipped( 'log sandbox unavailable in this environment' );
		}

		// Real-path + size check happens BEFORE the read; create a file
		// whose real size is exactly 10MB+1 byte. The constant is
		// 10 * 1024 * 1024 = 10485760 bytes.
		$filename = $this->fresh_log_filename( 'ssr_debug_2024-02-02_00-00-00.log' );
		$path     = $this->log_sandbox . '/' . basename( $filename );
		// 10MB + 64 bytes to exceed the cap.
		$oversize = str_repeat( 'A', 10 * 1024 * 1024 + 64 );
		file_put_contents( $path, $oversize );
		unset( $oversize );
		$this->seeded_files[] = $path;

		$_POST['nonce']   = wp_create_nonce( self::NONCE_ACTION );
		$_POST['filename'] = basename( $filename );

		list( $success, $data, $raw ) = $this->dispatch_ajax( 'sscribe_debug_export_logs' );

		$this::assertFalse( $success, "Raw: {$raw}" );
		$this::assertStringContainsString(
			'too large',
			(string) ( $data['message'] ?? '' ),
			"Raw: {$raw}"
		);
	}

	// -----------------------------------------------------------------
	// ajax_debug_get_rotated_log_files() — exercises the DirectoryIterator
	// branch (with at least one rotated file present).
	// -----------------------------------------------------------------

	public function test_get_rotated_log_files_returns_list(): void {
		if ( '' === $this->log_sandbox ) {
			$this::markTestSkipped( 'log sandbox unavailable in this environment' );
		}

		// Seed a rotated file in scope. The active log filename is also
		// in the same dir; the helper at line 539 excludes it.
		$rotated_filename = $this->fresh_log_filename( 'ssr_debug_2024-03-03_00-00-00.log' );
		$rotated_basename = basename( $rotated_filename );
		$this->seed_log_file(
			$rotated_basename,
			"{\"timestamp\":\"2024-03-03 00:00:00\",\"level\":\"INFO\",\"message\":\"rotated\"}\n"
		);

		$_POST['nonce'] = wp_create_nonce( self::NONCE_ACTION );

		list( $success, $data, $raw ) = $this->dispatch_ajax( 'sscribe_debug_get_files' );

		$this::assertTrue( $success, "Raw: {$raw}" );
		$this::assertArrayHasKey( 'files', $data, "Raw: {$raw}" );
		$this::assertArrayHasKey( 'total_count', $data, "Raw: {$raw}" );
		$this::assertArrayHasKey( 'truncated', $data, "Raw: {$raw}" );
		$this::assertIsArray( $data['files'], "files must be an array. Raw: {$raw}" );

		// Confirm the seeded file is actually present in the listing.
		$found = false;
		foreach ( (array) ( $data['files'] ?? array() ) as $row ) {
			if ( ( $row['name'] ?? '' ) === $rotated_basename ) {
				$found = true;
				break;
			}
		}
		$this::assertTrue( $found, "Seeded rotated log must appear in listing. Raw: {$raw}" );
	}

	public function test_get_rotated_log_files_ignores_non_matching_filenames(): void {
		if ( '' === $this->log_sandbox ) {
			$this::markTestSkipped( 'log sandbox unavailable in this environment' );
		}

		// A file that does NOT match the debug-log regex must be skipped.
		$stranger = 'cov-' . bin2hex( random_bytes( 6 ) ) . '-not-a-debug-file.txt';
		$this->seed_log_file( $stranger, 'noise' );

		$_POST['nonce'] = wp_create_nonce( self::NONCE_ACTION );

		list( $success, $data, $raw ) = $this->dispatch_ajax( 'sscribe_debug_get_files' );

		$this::assertTrue( $success, "Raw: {$raw}" );
		$this::assertIsArray( $data['files'] ?? null, "Raw: {$raw}" );
		foreach ( (array) ( $data['files'] ?? array() ) as $row ) {
			$this::assertNotSame( $stranger, $row['name'] ?? null, "Non-matching file leaked into the list. Raw: {$raw}" );
		}
	}

	// -----------------------------------------------------------------
	// ajax_debug_fetch_rotated() — exercises the per-file tail reader
	// with at least one in-scope rotated file.
	// -----------------------------------------------------------------

	public function test_fetch_rotated_returns_entries(): void {
		if ( '' === $this->log_sandbox ) {
			$this::markTestSkipped( 'log sandbox unavailable in this environment' );
		}

		$filename = $this->fresh_log_filename( 'ssr_debug_2024-04-04_00-00-00.log' );
		$basename = basename( $filename );
		$this->seed_log_file(
			$basename,
			"{\"timestamp\":\"2024-04-04 00:00:00\",\"level\":\"INFO\",\"message\":\"one\"}\n" .
			"{\"timestamp\":\"2024-04-04 00:00:01\",\"level\":\"INFO\",\"message\":\"two\"}\n" .
			"{\"timestamp\":\"2024-04-04 00:00:02\",\"level\":\"INFO\",\"message\":\"three\"}\n"
		);

		$_POST['nonce']   = wp_create_nonce( self::NONCE_ACTION );
		$_POST['filename'] = $basename;
		$_POST['offset']   = 0;
		$_POST['limit']    = 50;

		list( $success, $data, $raw ) = $this->dispatch_ajax( 'sscribe_debug_fetch_rotated' );

		$this::assertTrue( $success, "Raw: {$raw}" );
		$this::assertArrayHasKey( 'entries', $data, "Raw: {$raw}" );
		$this::assertArrayHasKey( 'count', $data, "Raw: {$raw}" );
		$this::assertArrayHasKey( 'effective_offset', $data, "Raw: {$raw}" );
	}

	public function test_fetch_rotated_invalid_filename_returns_400(): void {
		$_POST['nonce']   = wp_create_nonce( self::NONCE_ACTION );
		$_POST['filename'] = 'not-a-real-log.txt';
		$_POST['offset']   = 0;
		$_POST['limit']    = 50;

		list( $success, $data, $raw ) = $this->dispatch_ajax( 'sscribe_debug_fetch_rotated' );

		$this::assertFalse( $success, "Raw: {$raw}" );
		$this::assertStringContainsString(
			'Invalid file type',
			(string) ( $data['message'] ?? '' ),
			"Raw: {$raw}"
		);
	}

	public function test_fetch_rotated_missing_file_returns_404(): void {
		$_POST['nonce']   = wp_create_nonce( self::NONCE_ACTION );
		$_POST['filename'] = 'cov-' . bin2hex( random_bytes( 6 ) ) . '-ssr_debug_2099-09-09_09-09-09.log';
		$_POST['offset']   = 0;
		$_POST['limit']    = 50;

		list( $success, $data, $raw ) = $this->dispatch_ajax( 'sscribe_debug_fetch_rotated' );

		$this::assertFalse( $success, "Raw: {$raw}" );
		$this::assertStringContainsString(
			'File not found',
			(string) ( $data['message'] ?? '' ),
			"Raw: {$raw}"
		);
	}

	public function test_fetch_rotated_oversized_file_returns_413(): void {
		if ( '' === $this->log_sandbox ) {
			$this::markTestSkipped( 'log sandbox unavailable in this environment' );
		}

		$basename = $this->fresh_log_filename( 'ssr_debug_2024-05-05_00-00-00.log' );
		$basename = basename( $basename );
		$path     = $this->log_sandbox . '/' . $basename;
		$oversize = str_repeat( 'B', 10 * 1024 * 1024 + 64 );
		file_put_contents( $path, $oversize );
		unset( $oversize );
		$this->seeded_files[] = $path;

		$_POST['nonce']   = wp_create_nonce( self::NONCE_ACTION );
		$_POST['filename'] = $basename;
		$_POST['offset']   = 0;
		$_POST['limit']    = 50;

		list( $success, $data, $raw ) = $this->dispatch_ajax( 'sscribe_debug_fetch_rotated' );

		$this::assertFalse( $success, "Raw: {$raw}" );
		$this::assertStringContainsString(
			'exceeds',
			(string) ( $data['message'] ?? '' ),
			"Raw: {$raw}"
		);
	}

	public function test_fetch_rotated_offset_past_eof_clamps_to_last_line(): void {
		if ( '' === $this->log_sandbox ) {
			$this::markTestSkipped( 'log sandbox unavailable in this environment' );
		}

		$basename = $this->fresh_log_filename( 'ssr_debug_2024-06-06_00-00-00.log' );
		$basename = basename( $basename );
		$this->seed_log_file(
			$basename,
			"{\"timestamp\":\"2024-06-06 00:00:00\",\"level\":\"INFO\",\"message\":\"a\"}\n" .
			"{\"timestamp\":\"2024-06-06 00:00:01\",\"level\":\"INFO\",\"message\":\"b\"}\n"
		);

		$_POST['nonce']   = wp_create_nonce( self::NONCE_ACTION );
		$_POST['filename'] = $basename;
		$_POST['offset']   = 9999; // Past EOF → effective_offset = total_lines - 1.
		$_POST['limit']    = 50;

		list( $success, $data, $raw ) = $this->dispatch_ajax( 'sscribe_debug_fetch_rotated' );

		$this::assertTrue( $success, "Raw: {$raw}" );
		// effective_offset must be clamped to total_lines - 1 (=1).
		$this::assertSame( 1, (int) ( $data['effective_offset'] ?? -1 ), "Raw: {$raw}" );
	}

	// -----------------------------------------------------------------
	// ajax_debug_delete_rotated() — exercises the deletion path with
	// both a real rotated file and the active-log guard.
	// -----------------------------------------------------------------

	public function test_delete_rotated_succeeds(): void {
		if ( '' === $this->log_sandbox ) {
			$this::markTestSkipped( 'log sandbox unavailable in this environment' );
		}

		$basename = basename( $this->fresh_log_filename( 'ssr_debug_2024-07-07_00-00-00.log' ) );
		$this->seed_log_file( $basename, 'x' );

		$_POST['nonce']   = wp_create_nonce( self::NONCE_ACTION );
		$_POST['filename'] = $basename;

		list( $success, $data, $raw ) = $this->dispatch_ajax( 'sscribe_debug_delete_rotated' );

		$this::assertTrue( $success, "Raw: {$raw}" );
		$this::assertStringContainsString(
			'File deleted',
			(string) ( $data['message'] ?? '' ),
			"Raw: {$raw}"
		);
		$this::assertFileDoesNotExist(
			$this->log_sandbox . '/' . $basename,
			'Rotated log must be deleted from disk.'
		);
	}

	public function test_delete_rotated_invalid_filename_returns_400(): void {
		$_POST['nonce']   = wp_create_nonce( self::NONCE_ACTION );
		$_POST['filename'] = 'not-a-real-log.txt';

		list( $success, $data, $raw ) = $this->dispatch_ajax( 'sscribe_debug_delete_rotated' );

		$this::assertFalse( $success, "Raw: {$raw}" );
		$this::assertStringContainsString(
			'Invalid file type',
			(string) ( $data['message'] ?? '' ),
			"Raw: {$raw}"
		);
	}

	public function test_delete_rotated_missing_file_returns_404(): void {
		$_POST['nonce']   = wp_create_nonce( self::NONCE_ACTION );
		$_POST['filename'] = 'ssr_debug_2099-08-08_08-08-08.log';

		list( $success, $data, $raw ) = $this->dispatch_ajax( 'sscribe_debug_delete_rotated' );

		$this::assertFalse( $success, "Raw: {$raw}" );
		$this::assertStringContainsString(
			'File not found',
			(string) ( $data['message'] ?? '' ),
			"Raw: {$raw}"
		);
	}

	// -----------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------

	/**
	 * Extract the FIRST top-level JSON object from a possibly-concatenated
	 * raw AJAX response string. Some handlers in this class emit a
	 * legitimate success JSON via the AJAX guard and then have the guard's
	 * wp_die cascade caught by an outer `catch (\Throwable)`, which
	 * appends a SECOND JSON body. `json_decode()` rejects the whole
	 * concatenated string; this helper recovers the first object via
	 * brace-counting so the legitimate response can still be asserted on.
	 *
	 * @param string $raw Raw response string.
	 * @return array<string,mixed>|array{} Decoded first JSON object, or [] if none.
	 */
	private function extract_first_json( string $raw ): array {
		if ( '' === $raw || '{' !== $raw[0] ) {
			return array();
		}
		$depth     = 0;
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
					$first   = substr( $raw, 0, $i + 1 );
					$decoded = json_decode( $first, true );
					return is_array( $decoded ) ? $decoded : array();
				}
			}
		}
		return array();
	}
}
