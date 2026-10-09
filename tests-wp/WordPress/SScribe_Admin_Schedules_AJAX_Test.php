<?php
/**
 * Schedules tab AJAX endpoints on a real WordPress.
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

require_once __DIR__ . '/SScribe_WP_Ajax_TestCase.php';

final class SScribe_Admin_Schedules_AJAX_Test extends SScribe_WP_Ajax_TestCase {

	public function set_up(): void {
		parent::set_up();
		SScribe_Activator::activate( false );
		delete_option( SScribe_Schedule_Store::OPTION );
		if ( ! has_action( 'wp_ajax_sscribe_schedules_list' ) ) {
			( new SScribe_Admin_Schedules() )->register_hooks();
		}
	}

	public function tear_down(): void {
		delete_option( SScribe_Schedule_Store::OPTION );
		parent::tear_down();
	}

	public function test_administrator_can_create_list_toggle_and_delete_a_schedule(): void {
		$this->_setRole( 'administrator' );
		$_POST['nonce']    = wp_create_nonce( 'sscribe_export_nonce' );
		$_POST['schedule'] = wp_json_encode(
			array(
				'label'     => 'Weekly archive',
				'frequency' => 'weekly',
				'hour'      => 6,
				'weekday'   => 1,
				'formats'   => array( 'docx', 'pdf' ),
				'post_type' => 'page',
			)
		);

		list( $success, $data, $raw ) = $this->dispatch_ajax( 'sscribe_schedule_save' );
		$this::assertTrue( $success, "Creating a schedule must succeed. Raw: {$raw}" );
		$id = (string) $data['schedule']['id'];
		$this::assertMatchesRegularExpression( '/^sch_[a-f0-9]{12}$/', $id );
		$this::assertSame( get_current_user_id(), (int) $data['schedule']['owner_user_id'] );
		$this::assertNotFalse( wp_next_scheduled( SScribe_Scheduler::TICK_HOOK ), 'Saving a schedule arms the cron tick.' );

		unset( $_POST['schedule'] );
		list( $success, $data ) = $this->dispatch_ajax( 'sscribe_schedules_list' );
		$this::assertTrue( $success );
		$this::assertCount( 1, $data['schedules'] );
		$this::assertSame( 'Weekly archive', $data['schedules'][0]['label'] );
		$this::assertNotSame( '', $data['meta']['timezone'] );

		$_POST['id']      = $id;
		$_POST['enabled'] = '0';
		list( $success, $data ) = $this->dispatch_ajax( 'sscribe_schedule_toggle' );
		$this::assertTrue( $success );
		$this::assertFalse( $data['schedule']['enabled'] );

		unset( $_POST['enabled'] );
		list( $success ) = $this->dispatch_ajax( 'sscribe_schedule_delete' );
		$this::assertTrue( $success );
		$this::assertSame( array(), ( new SScribe_Schedule_Store() )->all() );
	}

	public function test_editor_without_manage_options_is_refused(): void {
		$this->_setRole( 'editor' );
		$_POST['nonce']    = wp_create_nonce( 'sscribe_export_nonce' );
		$_POST['schedule'] = wp_json_encode( array( 'label' => 'Nope', 'frequency' => 'daily', 'formats' => array( 'html' ) ) );

		list( $success, $data, $raw ) = $this->dispatch_ajax( 'sscribe_schedule_save' );

		$this::assertFalse( $success, "Editors must not manage schedules. Raw: {$raw}" );
		$this::assertSame( array(), ( new SScribe_Schedule_Store() )->all() );
		$this::assertArrayHasKey( 'message', $data );
	}

	public function test_missing_nonce_is_refused(): void {
		$this->_setRole( 'administrator' );
		unset( $_POST['nonce'] );

		list( $success ) = $this->dispatch_ajax( 'sscribe_schedules_list' );

		$this::assertFalse( $success );
	}
}
