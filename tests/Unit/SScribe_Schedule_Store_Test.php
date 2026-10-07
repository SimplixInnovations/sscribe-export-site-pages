<?php
/**
 * Unit tests for SScribe_Schedule_Store.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SScribe_Schedule_Store_Test extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		unset( $GLOBALS['sscribe_test_options'][ \SScribe_Schedule_Store::OPTION ], $GLOBALS['sscribe_test_update_option_failure'] );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['sscribe_test_options'][ \SScribe_Schedule_Store::OPTION ], $GLOBALS['sscribe_test_update_option_failure'] );
		parent::tearDown();
	}

	public function test_saved_schedule_can_be_read_back_and_is_not_autoloaded(): void {
		$store    = new \SScribe_Schedule_Store();
		$schedule = self::schedule( 'One' );

		$this::assertTrue( $store->save( $schedule ) );

		$this::assertSame( $schedule->to_array(), $store->get( $schedule->id )?->to_array() );
		$this::assertSame( array( $schedule->id ), array_keys( $store->all() ) );
		$this::assertFalse( $GLOBALS['sscribe_test_option_autoload'][ \SScribe_Schedule_Store::OPTION ] );
	}

	public function test_saving_again_replaces_the_schedule(): void {
		$store    = new \SScribe_Schedule_Store();
		$schedule = self::schedule( 'One' );
		$store->save( $schedule );

		$this::assertTrue( $store->save( $schedule->with( array( 'label' => 'Renamed' ) ) ) );

		$this::assertCount( 1, $store->all() );
		$this::assertSame( 'Renamed', $store->get( $schedule->id )?->label );
	}

	public function test_saving_an_unchanged_schedule_still_reports_success(): void {
		$store    = new \SScribe_Schedule_Store();
		$schedule = self::schedule( 'One' );
		$store->save( $schedule );
		$GLOBALS['sscribe_test_update_option_failure'] = \SScribe_Schedule_Store::OPTION;

		$this::assertTrue( $store->save( $schedule ) );
	}

	public function test_failed_write_is_reported(): void {
		$GLOBALS['sscribe_test_update_option_failure'] = \SScribe_Schedule_Store::OPTION;

		$this::assertFalse( ( new \SScribe_Schedule_Store() )->save( self::schedule( 'One' ) ) );
	}

	public function test_delete_removes_only_existing_schedules(): void {
		$store    = new \SScribe_Schedule_Store();
		$schedule = self::schedule( 'One' );
		$store->save( $schedule );

		$this::assertFalse( $store->delete( 'sch_000000000000' ) );
		$this::assertTrue( $store->delete( $schedule->id ) );
		$this::assertNull( $store->get( $schedule->id ) );
		$this::assertSame( array(), $store->all() );
	}

	public function test_no_more_than_twenty_schedules_can_be_added(): void {
		$store = new \SScribe_Schedule_Store();
		$first = null;
		for ( $i = 0; $i < \SScribe_Schedule_Store::MAX_SCHEDULES; $i++ ) {
			$schedule = self::schedule( 'Schedule ' . $i );
			$first    = $first ?? $schedule;
			$this::assertTrue( $store->save( $schedule ) );
		}

		$this::assertFalse( $store->has_room() );
		$this::assertFalse( $store->save( self::schedule( 'One too many' ) ) );
		$this::assertCount( \SScribe_Schedule_Store::MAX_SCHEDULES, $store->all() );
		$this::assertTrue( $store->save( $first->with( array( 'label' => 'Still editable' ) ) ) );
	}

	public function test_due_returns_enabled_idle_schedules_whose_time_has_come_earliest_first(): void {
		$store = new \SScribe_Schedule_Store();
		$now   = 1800000000;
		$later = self::schedule( 'Later', array( 'next_run_at' => $now - 10 ) );
		$early = self::schedule( 'Early', array( 'next_run_at' => $now - 100 ) );
		$store->save( $later );
		$store->save( $early );
		$store->save( self::schedule( 'Future', array( 'next_run_at' => $now + 10 ) ) );
		$store->save( self::schedule( 'Unscheduled', array( 'next_run_at' => 0 ) ) );
		$store->save(
			self::schedule(
				'Disabled',
				array(
					'next_run_at' => $now - 50,
					'enabled'     => false,
				)
			)
		);
		$store->save(
			self::schedule(
				'Running',
				array(
					'next_run_at'     => $now - 50,
					'last_run_status' => 'running',
				)
			)
		);
		$store->save(
			self::schedule(
				'Paused',
				array(
					'next_run_at'     => $now - 50,
					'running_session' => 'abc123',
				)
			)
		);

		$due = $store->due( $now );

		$this::assertSame( array( $early->id, $later->id ), array_map( static fn( \SScribe_Schedule $s ): string => $s->id, $due ) );
	}

	public function test_corrupt_rows_are_skipped(): void {
		$store    = new \SScribe_Schedule_Store();
		$schedule = self::schedule( 'Good' );
		$store->save( $schedule );
		$GLOBALS['sscribe_test_options'][ \SScribe_Schedule_Store::OPTION ]['broken']  = array( 'label' => 'No frequency' );
		$GLOBALS['sscribe_test_options'][ \SScribe_Schedule_Store::OPTION ]['scalar'] = 'nope';

		$this::assertSame( array( $schedule->id ), array_keys( $store->all() ) );
	}

	/**
	 * @param array<string, mixed> $overrides Fields to change.
	 */
	private static function schedule( string $label, array $overrides = array() ): \SScribe_Schedule {
		return \SScribe_Schedule::from_array(
			array_merge(
				array(
					'label'         => $label,
					'frequency'     => 'daily',
					'formats'       => 'markdown',
					'owner_user_id' => 7,
				),
				$overrides
			)
		);
	}
}
