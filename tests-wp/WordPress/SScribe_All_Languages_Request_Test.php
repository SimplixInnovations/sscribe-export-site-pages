<?php
/**
 * Real WordPress regressions for the All Languages request contract.
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

require_once __DIR__ . '/SScribe_WP_Ajax_TestCase.php';

final class SScribe_All_Languages_Request_Test extends SScribe_WP_Ajax_TestCase {

	public function set_up(): void {
		parent::set_up();
		SScribe_Activator::activate( false );
		$this->_setRole( 'administrator' );
	}

	public function test_all_languages_preview_reaches_real_preview_handler(): void {
		$page_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'All Languages Preview Fixture',
				'post_content'=> 'Preview fixture content.',
			)
		);
		$this::assertGreaterThan( 0, $page_id );

		$_POST = array(
			'action'       => 'sscribe_get_export_preview',
			'nonce'        => wp_create_nonce( 'sscribe_export_nonce' ),
			'language'     => '__all__',
			'post_status'  => 'publish',
			'post_type'    => 'page',
			'format'       => 'html',
			'formats'      => array( 'html' ),
		);

		list( $success, $data, $raw ) = $this->dispatch_ajax( 'sscribe_get_export_preview' );

		$this::assertTrue( $success, "All Languages Preview must not be rejected as invalid_language. Raw: {$raw}" );
		$this::assertNotSame( 'invalid_language', $data['code'] ?? null, "Raw: {$raw}" );
		$this::assertGreaterThanOrEqual( 1, (int) ( $data['total_pages'] ?? 0 ), "Raw: {$raw}" );
	}

	public function test_all_languages_start_export_creates_real_session(): void {
		$page_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'All Languages Start Fixture',
				'post_content'=> 'Start fixture content.',
			)
		);
		$this::assertGreaterThan( 0, $page_id );

		$_POST = array(
			'action'       => 'sscribe_start_export',
			'nonce'        => wp_create_nonce( 'sscribe_export_nonce' ),
			'language'     => '__all__',
			'post_status'  => 'publish',
			'post_type'    => 'page',
			'formats'      => array( 'html' ),
			'format_options' => array(),
		);

		list( $success, $data, $raw ) = $this->dispatch_ajax( 'sscribe_start_export' );

		$this::assertTrue( $success, "All Languages Start must get past language validation and create a session. Raw: {$raw}" );
		$this::assertNotSame( 'invalid_language', $data['code'] ?? null, "Raw: {$raw}" );
		$this::assertNotEmpty( $data['session_id'] ?? '', "Start must return a real session ID. Raw: {$raw}" );

		if ( ! empty( $data['session_id'] ) ) {
			$session = new SScribe_Session();
			$session->delete( (string) $data['session_id'] );
		}
	}
}
