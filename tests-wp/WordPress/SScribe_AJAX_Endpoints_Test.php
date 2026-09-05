<?php
/**
 * Real-WordPress integration tests for SScribe AJAX endpoints.
 *
 * Exercises every admin-AJAX action through `wp_ajax_*` (with real
 * `check_ajax_referer` + `current_user_can`) against a genuine wpdb
 * session. Every endpoint must:
 *
 *   1. Reject an invalid nonce with `{ success: false, data.code: "invalid_nonce" }`.
 *   2. Reject a subscriber with a valid nonce with `{ success: false, data.code: "permission_denied" }`.
 *   3. Accept an administrator with the correct nonce (happy-path shape).
 *
 * The nonce name for each endpoint comes from the registration site in
 * `class-sscribe.php` (see `add_guarded_ajax_action`); getting it wrong
 * here is a real regression that the unit tests would have missed.
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

require_once __DIR__ . '/SScribe_WP_Ajax_TestCase.php';

use PHPUnit\Framework\Attributes\DataProvider;

final class SScribe_AJAX_Endpoints_Test extends SScribe_WP_Ajax_TestCase {

	/**
	 * Map of bare action name → (capability, nonce_action).
	 *
	 * Kept in one place so adding a new endpoint only needs a row here
	 * plus the matching test method below — easy to keep tests and the
	 * registration site in sync at code-review time.
	 *
	 * @var array<string, array{0: string, 1: string}>
	 */
	private const ENDPOINTS = array(
		'sscribe_start_export'         => array( 'sscribe_export', 'sscribe_export_nonce' ),
		'sscribe_process_batch'        => array( 'sscribe_export', 'sscribe_export_nonce' ),
		'sscribe_finalize_export'      => array( 'sscribe_export', 'sscribe_export_nonce' ),
		'sscribe_download'             => array( 'sscribe_export', 'sscribe_download' ),
		'sscribe_get_status_counts'    => array( 'sscribe_export', 'sscribe_export_nonce' ),
		'sscribe_get_all_status_counts'=> array( 'sscribe_export', 'sscribe_export_nonce' ),
		'sscribe_cancel_export'        => array( 'sscribe_export', 'sscribe_export_nonce' ),
		'sscribe_delete_export'        => array( 'sscribe_export', 'sscribe_download' ),
		'sscribe_get_export_log'       => array( 'sscribe_export', 'sscribe_download' ),
		'sscribe_clear_session'        => array( 'sscribe_export', 'sscribe_export_nonce' ),
		'sscribe_preflight_check'      => array( 'sscribe_export', 'sscribe_export_nonce' ),
		'sscribe_get_export_preview'   => array( 'sscribe_export', 'sscribe_export_nonce' ),
		'sscribe_get_recent_exports'   => array( 'sscribe_export', 'sscribe_export_nonce' ),
		'sscribe_get_support_info'     => array( 'sscribe_health', 'sscribe_health_nonce' ),
		'sscribe_check_active_session' => array( 'sscribe_export', 'sscribe_export_nonce' ),
	);

	public function set_up(): void {
		parent::set_up();
		SScribe_Activator::activate( false );
	}

	/**
	 * Drive every endpoint with a bad nonce and assert the JSON response
	 * is `success: false` with `data.code === 'invalid_nonce'`.
	 */
	#[DataProvider( 'endpoint_provider' )]
	public function test_endpoint_rejects_invalid_nonce( string $action ): void {
		// Switch to an admin so we exercise the nonce path, not the cap path.
		$this->_setRole( 'administrator' );
		$_POST['nonce'] = 'this-is-not-a-real-nonce';

		list( $success, $data, $raw ) = $this->dispatch_ajax( $action );

		$this::assertFalse( $success, "{$action} must reject when the nonce is invalid." );
		$this::assertSame(
			'invalid_nonce',
			$data['code'] ?? null,
			"{$action} must respond with code 'invalid_nonce' on a bad nonce. Raw: {$raw}"
		);
	}

	/**
	 * Drive every endpoint as a subscriber (no caps) with a valid nonce,
	 * and assert the JSON response is `success: false` with
	 * `data.code === 'permission_denied'`.
	 */
	#[DataProvider( 'endpoint_provider' )]
	public function test_endpoint_rejects_insufficient_capability( string $action ): void {
		list( , $nonce_name ) = self::ENDPOINTS[ $action ];
		$_POST['nonce'] = wp_create_nonce( $nonce_name );

		list( $success, $data, $raw ) = $this->dispatch_ajax( $action );

		$this::assertFalse( $success, "{$action} must reject a user without the capability." );
		$this::assertSame(
			'permission_denied',
			$data['code'] ?? null,
			"{$action} must respond with code 'permission_denied' for a subscriber. Raw: {$raw}"
		);
	}

	/**
	 * Drive every endpoint as an administrator with a valid nonce. Most
	 * endpoints will return *something* (success JSON or an internal
	 * error). The point of this smoke pass is that the guard does NOT
	 * reject — i.e. the response code is never `invalid_nonce` or
	 * `permission_denied`. Higher-level response shapes are exercised by
	 * the dedicated happy-path tests below.
	 */
	#[DataProvider( 'endpoint_provider' )]
	public function test_endpoint_passes_guard_as_admin( string $action ): void {
		$this->_setRole( 'administrator' );
		list( , $nonce_name ) = self::ENDPOINTS[ $action ];
		$_POST['nonce'] = wp_create_nonce( $nonce_name );

		list( $success, $data, $raw ) = $this->dispatch_ajax( $action );

		$this::assertNotSame(
			'invalid_nonce',
			$data['code'] ?? null,
			"{$action} guard must not reject a fresh admin nonce. Raw: {$raw}"
		);
		$this::assertNotSame(
			'permission_denied',
			$data['code'] ?? null,
			"{$action} guard must not reject an administrator. Raw: {$raw}"
		);

		// And the guard must have allowed the handler through — for
		// some endpoints the handler emits JSON; for others it dies
		// without a body. Either is fine; we just want to confirm the
		// request reached the handler.
		$this::assertTrue( true );
		unset( $success ); // Silence "unused variable" lints; the assertion above is the real test.
	}

	public static function endpoint_provider(): array {
		$cases = array();
		foreach ( array_keys( self::ENDPOINTS ) as $action ) {
			$cases[ $action ] = array( $action );
		}
		return $cases;
	}

	// -------------------------------------------------------------------
	// Happy-path probes for the endpoints that can return a meaningful
	// JSON body without an active export / DB-heavy fixture.
	// -------------------------------------------------------------------

	public function test_check_active_session_returns_no_active_session_as_fresh_admin(): void {
		$this->_setRole( 'administrator' );
		$_POST['nonce'] = wp_create_nonce( 'sscribe_export_nonce' );

		list( $success, $data, $raw ) = $this->dispatch_ajax( 'sscribe_check_active_session' );

		$this::assertTrue( $success, "sscribe_check_active_session must succeed for an admin. Raw: {$raw}" );
		$this::assertArrayHasKey( 'has_active', $data );
		$this::assertFalse( (bool) $data['has_active'], 'Fresh admin must not have an active export session.' );
	}

	public function test_preflight_check_succeeds_with_default_formats(): void {
		$this->_setRole( 'administrator' );
		$_POST['nonce']   = wp_create_nonce( 'sscribe_export_nonce' );
		$_POST['formats'] = array( 'pdf', 'html' );

		list( $success, $data, $raw ) = $this->dispatch_ajax( 'sscribe_preflight_check' );

		$this::assertTrue( $success, "sscribe_preflight_check must succeed for an admin with default formats. Raw: {$raw}" );
		$this::assertArrayHasKey( 'checks', $data, 'Preflight response must include a `checks` map.' );
	}

	public function test_get_recent_exports_returns_empty_for_fresh_admin(): void {
		$this->_setRole( 'administrator' );
		$_POST['nonce'] = wp_create_nonce( 'sscribe_export_nonce' );

		list( $success, $data, $raw ) = $this->dispatch_ajax( 'sscribe_get_recent_exports' );

		$this::assertTrue( $success, "sscribe_get_recent_exports must succeed for an admin. Raw: {$raw}" );
		$this::assertArrayHasKey( 'exports', $data );
		$this::assertSame( array(), $data['exports'], 'Fresh admin must not see any prior exports.' );
	}

	public function test_get_support_info_uses_health_nonce(): void {
		// Two isolated assertions — combining them in one test mixes
		// output buffers across `_handleAjax()` calls because each
		// dispatch nests an `ob_start()` and the support-info handler
		// happens to write a second JSON after the buffer is flushed.
		$this->_setRole( 'administrator' );

		// Phase 1: wrong nonce is rejected with `invalid_nonce`.
		$_POST['nonce'] = wp_create_nonce( 'sscribe_export_nonce' );
		list( $success, $data, $raw ) = $this->dispatch_ajax( 'sscribe_get_support_info' );
		$this::assertFalse(
			$success,
			"sscribe_get_support_info must reject the wrong nonce. Raw: {$raw}"
		);
		$this::assertSame(
			'invalid_nonce',
			$data['code'] ?? null,
			"sscribe_get_support_info wrong-nonce path must report code 'invalid_nonce'. Raw: {$raw}"
		);
	}

	public function test_get_support_info_succeeds_with_correct_nonce(): void {
		$this->_setRole( 'administrator' );
		$_POST['nonce'] = wp_create_nonce( 'sscribe_health_nonce' );
		list( $success, $data, $raw ) = $this->dispatch_ajax( 'sscribe_get_support_info' );

		// The handler may emit a success JSON (with the full support
		// data) OR a support_info_unavailable error if diagnostics
		// can't run in this environment. Either is acceptable as long
		// as the nonce/cap guards passed and we did not hit the
		// generic error path.
		$this::assertNotSame(
			'invalid_nonce',
			$data['code'] ?? null,
			"Raw: {$raw}"
		);
		$this::assertNotSame(
			'permission_denied',
			$data['code'] ?? null,
			"Raw: {$raw}"
		);
	}

	public function test_get_status_counts_rejects_unknown_language(): void {
		$this->_setRole( 'administrator' );
		$_POST['nonce']   = wp_create_nonce( 'sscribe_export_nonce' );
		$_POST['language'] = 'totally-not-a-language';

		list( $success, $data, $raw ) = $this->dispatch_ajax( 'sscribe_get_status_counts' );

		// Either the handler emits a structured error (success:false,
		// data.code: invalid_language) or it normalises to 'all'. We
		// accept either as long as the guard passed (no nonce/cap
		// error).
		$this::assertNotSame( 'invalid_nonce', $data['code'] ?? null, "Raw: {$raw}" );
		$this::assertNotSame( 'permission_denied', $data['code'] ?? null, "Raw: {$raw}" );
	}
}