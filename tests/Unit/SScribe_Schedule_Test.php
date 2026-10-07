<?php
/**
 * Unit tests for SScribe_Schedule.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SScribe_Schedule_Test extends TestCase {

	private const TZ = 'Europe/Berlin';

	public function test_minimal_input_gets_defaults_and_a_generated_id(): void {
		$schedule = \SScribe_Schedule::from_array( self::base() );

		$this::assertMatchesRegularExpression( '/^sch_[a-f0-9]{12}$/', $schedule->id );
		$this::assertSame( 'Nightly', $schedule->label );
		$this::assertSame( 'publish', $schedule->post_status );
		$this::assertSame( 'page', $schedule->post_type );
		$this::assertSame( array( 'docx', 'markdown' ), $schedule->formats );
		$this::assertSame( 0, $schedule->hour );
		$this::assertTrue( $schedule->enabled );
		$this::assertFalse( $schedule->incremental );
		$this::assertSame( '', $schedule->last_run_status );
		$this::assertFalse( $schedule->is_running() );
	}

	public function test_round_trip_through_array_keeps_every_field(): void {
		$schedule = \SScribe_Schedule::from_array(
			self::base(
				array(
					'frequency'       => 'weekly',
					'hour'            => '7',
					'weekday'         => 3,
					'incremental'     => 'yes',
					'retention_days'  => 14,
					'notify'          => 1,
					'enabled'         => 'false',
					'last_run_at'     => 1700000000,
					'last_run_status' => 'success',
					'last_run_file'   => 'site-export.zip',
				)
			)
		);

		$copy = \SScribe_Schedule::from_array( $schedule->to_array() );

		$this::assertSame( $schedule->to_array(), $copy->to_array() );
		$this::assertSame( 7, $copy->hour );
		$this::assertTrue( $copy->incremental );
		$this::assertTrue( $copy->notify );
		$this::assertFalse( $copy->enabled );
	}

	/**
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	public static function invalid_input(): array {
		return array(
			'unknown frequency'    => array( array( 'frequency' => 'yearly' ), 'frequency' ),
			'hour too large'       => array( array( 'hour' => 24 ), 'hour' ),
			'hour not a number'    => array( array( 'hour' => 'noon' ), 'hour' ),
			'weekday out of range' => array( array( 'weekday' => 7 ), 'weekday' ),
			'day 29'               => array( array( 'day_of_month' => 29 ), 'day_of_month' ),
			'no formats'           => array( array( 'formats' => '' ), 'formats' ),
			'unknown format'       => array( array( 'formats' => 'docx,xls' ), 'formats' ),
			'empty label'          => array( array( 'label' => '   ' ), 'label' ),
			'label too long'       => array( array( 'label' => str_repeat( 'a', 81 ) ), 'label' ),
			'missing owner'        => array( array( 'owner_user_id' => 0 ), 'owner_user_id' ),
			'malformed id'         => array( array( 'id' => 'sch_xyz' ), 'id' ),
			'negative retention'   => array( array( 'retention_days' => -1 ), 'retention_days' ),
		);
	}

	/**
	 * @param array<string, mixed> $override Field to break.
	 */
	#[DataProvider( 'invalid_input' )]
	public function test_invalid_input_is_rejected( array $override, string $field ): void {
		try {
			\SScribe_Schedule::from_array( self::base( $override ) );
			$this::fail( 'Expected a validation exception.' );
		} catch ( \SScribe_Validation_Exception $e ) {
			$this::assertSame( $field, $e->get_field() );
		}
	}

	public function test_label_of_eighty_multibyte_characters_is_accepted(): void {
		$label = str_repeat( 'é', 80 );

		$this::assertSame( $label, \SScribe_Schedule::from_array( self::base( array( 'label' => $label ) ) )->label );
	}

	public function test_with_returns_a_new_instance_and_keeps_the_id(): void {
		$original = \SScribe_Schedule::from_array( self::base() );

		$changed = $original->with(
			array(
				'label' => 'Renamed',
				'id'    => 'sch_000000000000',
			)
		);

		$this::assertNotSame( $original, $changed );
		$this::assertSame( 'Nightly', $original->label );
		$this::assertSame( 'Renamed', $changed->label );
		$this::assertSame( $original->id, $changed->id );
	}

	public function test_with_validates_the_changes(): void {
		$this->expectException( \SScribe_Validation_Exception::class );

		\SScribe_Schedule::from_array( self::base() )->with( array( 'frequency' => 'never' ) );
	}

	public function test_full_schedule_job_has_no_watermark(): void {
		$schedule = \SScribe_Schedule::from_array( self::base( array( 'last_run_at' => 1700000000 ) ) );

		$job = $schedule->to_job();

		$this::assertSame( 0, $job->modified_since );
		$this::assertSame( $schedule->id, $job->schedule_id );
		$this::assertSame( array( 'docx', 'markdown' ), $job->formats );
	}

	public function test_first_incremental_run_exports_everything(): void {
		$schedule = \SScribe_Schedule::from_array( self::base( array( 'incremental' => true ) ) );

		$this::assertSame( 0, $schedule->to_job()->modified_since );
	}

	public function test_later_incremental_runs_use_the_last_run_as_watermark(): void {
		$schedule = \SScribe_Schedule::from_array(
			self::base(
				array(
					'incremental' => true,
					'last_run_at' => 1700000000,
					'language'    => 'de',
				)
			)
		);

		$job = $schedule->to_job();

		$this::assertSame( 1700000000, $job->modified_since );
		$this::assertSame( 'de', $job->language );
	}

	public function test_daily_run_keeps_wall_clock_time_across_autumn_dst_change(): void {
		$schedule = self::with_frequency( 'daily', array( 'hour' => 9 ) );

		$next = $schedule->compute_next_run( self::local( '2026-10-24 10:00' ), self::TZ );

		$this::assertSame( '2026-10-25 09:00 CET', self::format( $next ) );
		$this::assertSame( gmmktime( 8, 0, 0, 10, 25, 2026 ), $next );
	}

	public function test_daily_run_keeps_wall_clock_time_across_spring_dst_change(): void {
		$schedule = self::with_frequency( 'daily', array( 'hour' => 9 ) );

		$next = $schedule->compute_next_run( self::local( '2026-03-28 10:00' ), self::TZ );

		$this::assertSame( '2026-03-29 09:00 CEST', self::format( $next ) );
	}

	public function test_daily_run_later_today_is_chosen_when_the_hour_is_still_ahead(): void {
		$schedule = self::with_frequency( 'daily', array( 'hour' => 18 ) );

		$this::assertSame( '2026-10-24 18:00 CEST', self::format( $schedule->compute_next_run( self::local( '2026-10-24 10:00' ), self::TZ ) ) );
	}

	public function test_next_run_is_strictly_after_the_given_moment(): void {
		$schedule = self::with_frequency( 'daily', array( 'hour' => 9 ) );

		$this::assertSame( '2026-10-25 09:00 CET', self::format( $schedule->compute_next_run( self::local( '2026-10-24 09:00' ), self::TZ ) ) );
	}

	public function test_hour_skipped_by_spring_dst_runs_at_the_next_valid_time(): void {
		$schedule = self::with_frequency( 'daily', array( 'hour' => 2 ) );

		$this::assertSame( '2026-03-29 03:00 CEST', self::format( $schedule->compute_next_run( self::local( '2026-03-28 12:00' ), self::TZ ) ) );
	}

	public function test_hourly_run_is_the_next_top_of_the_hour_during_autumn_dst_change(): void {
		$schedule = self::with_frequency( 'hourly' );
		$after    = gmmktime( 0, 30, 0, 10, 25, 2026 );

		$next = $schedule->compute_next_run( $after, self::TZ );

		$this::assertSame( gmmktime( 1, 0, 0, 10, 25, 2026 ), $next );
		$this::assertSame( 0, $next % HOUR_IN_SECONDS );
	}

	public function test_weekly_run_lands_on_the_configured_weekday_after_dst_change(): void {
		$schedule = self::with_frequency(
			'weekly',
			array(
				'weekday' => 1,
				'hour'    => 6,
			)
		);

		$this::assertSame( '2026-10-26 06:00 CET', self::format( $schedule->compute_next_run( self::local( '2026-10-23 12:00' ), self::TZ ) ) );
		$this::assertSame( '2026-11-02 06:00 CET', self::format( $schedule->compute_next_run( self::local( '2026-10-26 07:00' ), self::TZ ) ) );
	}

	public function test_monthly_run_lands_on_the_configured_day_across_dst_change(): void {
		$schedule = self::with_frequency(
			'monthly',
			array(
				'day_of_month' => 28,
				'hour'         => 0,
			)
		);

		$this::assertSame( '2026-03-28 00:00 CET', self::format( $schedule->compute_next_run( self::local( '2026-03-15 08:00' ), self::TZ ) ) );
		$this::assertSame( '2026-04-28 00:00 CEST', self::format( $schedule->compute_next_run( self::local( '2026-03-28 00:00' ), self::TZ ) ) );
	}

	public function test_invalid_timezone_falls_back_to_the_site_timezone(): void {
		$schedule = self::with_frequency( 'daily', array( 'hour' => 9 ) );

		$next = $schedule->compute_next_run( gmmktime( 10, 0, 0, 10, 24, 2026 ), 'Not/AZone' );

		$this::assertGreaterThan( gmmktime( 10, 0, 0, 10, 24, 2026 ), $next );
	}

	public function test_retention_seconds_uses_the_default_when_unset(): void {
		$this::assertSame( 3 * DAY_IN_SECONDS, \SScribe_Schedule::from_array( self::base() )->retention_seconds( 3 ) );
		$this::assertSame( 10 * DAY_IN_SECONDS, \SScribe_Schedule::from_array( self::base( array( 'retention_days' => 10 ) ) )->retention_seconds( 3 ) );
	}

	/**
	 * @param array<string, mixed> $overrides Fields to change.
	 * @return array<string, mixed>
	 */
	private static function base( array $overrides = array() ): array {
		return array_merge(
			array(
				'label'         => 'Nightly',
				'frequency'     => 'daily',
				'formats'       => 'docx,markdown',
				'owner_user_id' => 7,
			),
			$overrides
		);
	}

	/**
	 * @param array<string, mixed> $overrides Fields to change.
	 */
	private static function with_frequency( string $frequency, array $overrides = array() ): \SScribe_Schedule {
		return \SScribe_Schedule::from_array( self::base( array_merge( array( 'frequency' => $frequency ), $overrides ) ) );
	}

	private static function local( string $time ): int {
		return ( new \DateTimeImmutable( $time, new \DateTimeZone( self::TZ ) ) )->getTimestamp();
	}

	private static function format( int $timestamp ): string {
		return ( new \DateTimeImmutable( '@' . $timestamp ) )->setTimezone( new \DateTimeZone( self::TZ ) )->format( 'Y-m-d H:i T' );
	}
}
