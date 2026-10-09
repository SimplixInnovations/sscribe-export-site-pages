<?php
declare(strict_types=1);

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SScribe_Admin_Schedules_Test extends TestCase {

	private const USER_ID = 5;

	protected function setUp(): void {
		parent::setUp();
		unset( $GLOBALS['sscribe_test_options'][ \SScribe_Schedule_Store::OPTION ] );
		$GLOBALS['sscribe_test_current_user_can']               = true;
		$GLOBALS['sscribe_test_current_user_id']                = self::USER_ID;
		$GLOBALS['sscribe_test_user_caps'][ self::USER_ID ]     = array( 'sscribe_export', 'manage_options' );
		$_POST                                                  = array( 'nonce' => 'test-nonce' );
	}

	protected function tearDown(): void {
		unset(
			$GLOBALS['sscribe_test_options'][ \SScribe_Schedule_Store::OPTION ],
			$GLOBALS['sscribe_test_current_user_can'],
			$GLOBALS['sscribe_test_current_user_id'],
			$GLOBALS['sscribe_test_user_caps'][ self::USER_ID ]
		);
		$_POST = array();
		parent::tearDown();
	}

	/**
	 * Run a handler and decode the JSON it sent.
	 *
	 * @param callable $handler AJAX method.
	 * @return array<string, mixed>
	 */
	private function dispatch( callable $handler ): array {
		ob_start();
		try {
			$handler();
		} catch ( \RuntimeException $e ) {
			// The test stubs throw after sending the JSON response.
			unset( $e );
		}
		$decoded = json_decode( (string) ob_get_clean(), true );
		$this::assertIsArray( $decoded, 'Handler must send JSON.' );
		return $decoded;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function valid_input(): array {
		return array(
			'label'        => 'Nightly handover',
			'frequency'    => 'daily',
			'hour'         => 3,
			'formats'      => array( 'html', 'markdown' ),
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'fields_mode'  => 'all',
			'compliance'   => 1,
			'incremental'  => 1,
			'destinations' => array(
				array(
					'id'       => 's3',
					'settings' => array(
						'region'     => 'us-east-1',
						'bucket'     => 'handover',
						'access_key' => 'AKIA',
						'secret_key' => 'very-secret',
					),
				),
			),
		);
	}

	public function test_save_creates_a_schedule_and_seals_the_secret(): void {
		$_POST['schedule'] = wp_json_encode( $this->valid_input() );

		$response = $this->dispatch( array( new \SScribe_Admin_Schedules(), 'ajax_save' ) );

		$this::assertTrue( $response['success'], wp_json_encode( $response ) );
		$schedule = $response['data']['schedule'];
		$this::assertSame( 'Nightly handover', $schedule['label'] );
		$this::assertSame( self::USER_ID, $schedule['owner_user_id'] );
		$this::assertSame( 'all', $schedule['format_options']['sscribe_include_fields'] );
		$this::assertSame( '1', $schedule['format_options']['sscribe_compliance_mode'] );
		$this::assertTrue( $schedule['incremental'] );
		$this::assertGreaterThan( time(), $schedule['next_run_at'] );
		$this::assertSame( \SScribe_Destination_Settings::MASK, $schedule['destinations'][0]['settings']['secret_key'], 'Secrets are masked in responses.' );

		$stored = ( new \SScribe_Schedule_Store() )->get( $schedule['id'] );
		$this::assertNotNull( $stored );
		$this::assertTrue( \SScribe_Secret_Store::is_sealed( $stored->destinations[0]['settings']['secret_key'] ) );
	}

	public function test_edit_keeps_a_masked_secret_and_applies_other_changes(): void {
		$controller = new \SScribe_Admin_Schedules();
		$schedule   = $controller->schedule_from_input( $this->valid_input(), null );
		$store      = new \SScribe_Schedule_Store();
		$this::assertTrue( $store->save( $schedule ) );
		$sealed = $schedule->destinations[0]['settings']['secret_key'];

		$input                                            = $this->valid_input();
		$input['id']                                      = $schedule->id;
		$input['label']                                   = 'Renamed';
		$input['destinations'][0]['settings']['bucket']     = 'other-bucket';
		$input['destinations'][0]['settings']['secret_key'] = \SScribe_Destination_Settings::MASK;
		$_POST['schedule']                                = wp_json_encode( $input );

		$response = $this->dispatch( array( $controller, 'ajax_save' ) );

		$this::assertTrue( $response['success'], wp_json_encode( $response ) );
		$updated = $store->get( $schedule->id );
		$this::assertSame( 'Renamed', $updated->label );
		$this::assertSame( 'other-bucket', $updated->destinations[0]['settings']['bucket'] );
		$this::assertSame( $sealed, $updated->destinations[0]['settings']['secret_key'], 'A masked secret keeps the stored value.' );
		$this::assertSame( $schedule->created_at, $updated->created_at );
		$this::assertCount( 1, $store->all() );
	}

	public function test_save_reports_the_invalid_field(): void {
		$input              = $this->valid_input();
		$input['frequency'] = 'fortnightly';
		$_POST['schedule']  = wp_json_encode( $input );

		$response = $this->dispatch( array( new \SScribe_Admin_Schedules(), 'ajax_save' ) );

		$this::assertFalse( $response['success'] );
		$this::assertSame( 'frequency', $response['data']['field'] );
		$this::assertSame( array(), ( new \SScribe_Schedule_Store() )->all() );
	}

	public function test_toggle_pauses_then_enables_with_a_fresh_next_run(): void {
		$controller = new \SScribe_Admin_Schedules();
		$schedule   = $controller->schedule_from_input( $this->valid_input(), null );
		( new \SScribe_Schedule_Store() )->save( $schedule );

		$_POST['id']      = $schedule->id;
		$_POST['enabled'] = '0';
		$paused           = $this->dispatch( array( $controller, 'ajax_toggle' ) );
		$this::assertTrue( $paused['success'] );
		$this::assertFalse( $paused['data']['schedule']['enabled'] );

		$_POST['enabled'] = '1';
		$enabled          = $this->dispatch( array( $controller, 'ajax_toggle' ) );
		$this::assertTrue( $enabled['data']['schedule']['enabled'] );
		$this::assertGreaterThan( time(), $enabled['data']['schedule']['next_run_at'] );
	}

	public function test_delete_removes_the_schedule_and_unknown_ids_are_404(): void {
		$controller = new \SScribe_Admin_Schedules();
		$schedule   = $controller->schedule_from_input( $this->valid_input(), null );
		( new \SScribe_Schedule_Store() )->save( $schedule );

		$_POST['id'] = $schedule->id;
		$this::assertTrue( $this->dispatch( array( $controller, 'ajax_delete' ) )['success'] );
		$this::assertSame( array(), ( new \SScribe_Schedule_Store() )->all() );

		$_POST['id'] = 'sch_000000000000';
		$this::assertFalse( $this->dispatch( array( $controller, 'ajax_delete' ) )['success'] );
	}

	public function test_run_reports_the_scheduler_outcome(): void {
		$fake = new class() {
			public function run( string $id, bool $force ): \SScribe_Export_Outcome {
				unset( $id, $force );
				return \SScribe_Export_Outcome::ok(
					array(
						'filename' => 'nightly.zip',
						'pages'    => 4,
					)
				);
			}
		};
		$controller = new \SScribe_Admin_Schedules( static fn() => $fake );
		$schedule   = $controller->schedule_from_input( $this->valid_input(), null );
		( new \SScribe_Schedule_Store() )->save( $schedule );
		$_POST['id'] = $schedule->id;

		$response = $this->dispatch( array( $controller, 'ajax_run' ) );

		$this::assertTrue( $response['success'] );
		$this::assertSame( 'nightly.zip', $response['data']['filename'] );
		$this::assertStringContainsString( 'nightly.zip', $response['data']['message'] );
	}

	public function test_requests_without_the_capability_are_refused(): void {
		$GLOBALS['sscribe_test_current_user_can'] = false;
		$_POST['schedule']                        = wp_json_encode( $this->valid_input() );

		$response = $this->dispatch( array( new \SScribe_Admin_Schedules(), 'ajax_save' ) );

		$this::assertFalse( $response['success'] );
		$this::assertSame( array(), ( new \SScribe_Schedule_Store() )->all() );
	}

	public function test_list_payload_describes_destinations_and_limits(): void {
		$payload = ( new \SScribe_Admin_Schedules() )->list_payload();

		$this::assertSame( array(), $payload['schedules'] );
		$this::assertSame( \SScribe_Schedule_Store::MAX_SCHEDULES, $payload['meta']['max_schedules'] );
		$ids = array_column( $payload['meta']['destinations'], 'id' );
		$this::assertContains( 's3', $ids );
		$this::assertContains( 'directory', $ids );
		$s3 = $payload['meta']['destinations'][ array_search( 's3', $ids, true ) ];
		$this::assertSame( 'password', $s3['fields']['secret_key']['type'] );
		$this::assertArrayHasKey( 'all', $payload['meta']['fields_modes'] );
	}
}
