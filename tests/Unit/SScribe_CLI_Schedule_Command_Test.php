<?php
/**
 * Unit tests for the argument helpers of the schedule WP-CLI command.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SScribe_CLI_Schedule_Command_Test extends TestCase {

	protected function tearDown(): void {
		unset( $GLOBALS['sscribe_test_users'] );
		parent::tearDown();
	}

	public function test_dashed_options_map_to_schedule_fields(): void {
		$now = gmmktime( 10, 0, 0, 10, 8, 2026 );

		$schedule = \SScribe_CLI_Schedule_Command::schedule_from_args(
			array(
				'label'          => 'Weekly changes',
				'frequency'      => 'weekly',
				'hour'           => '6',
				'weekday'        => '5',
				'day'            => '12',
				'formats'        => 'markdown',
				'post-type'      => 'post',
				'post-status'    => 'all',
				'language'       => 'de',
				'incremental'    => true,
				'retention-days' => '14',
				'notify'         => true,
			),
			7,
			$now
		);

		$this::assertSame( 'Weekly changes', $schedule->label );
		$this::assertSame( 'weekly', $schedule->frequency );
		$this::assertSame( 6, $schedule->hour );
		$this::assertSame( 5, $schedule->weekday );
		$this::assertSame( 12, $schedule->day_of_month );
		$this::assertSame( array( 'markdown' ), $schedule->formats );
		$this::assertSame( 'post', $schedule->post_type );
		$this::assertSame( 'all', $schedule->post_status );
		$this::assertSame( 'de', $schedule->language );
		$this::assertTrue( $schedule->incremental );
		$this::assertTrue( $schedule->notify );
		$this::assertSame( 14, $schedule->retention_days );
		$this::assertSame( 7, $schedule->owner_user_id );
		$this::assertSame( $now, $schedule->created_at );
		$this::assertGreaterThan( $now, $schedule->next_run_at );
	}

	public function test_formats_default_to_every_format(): void {
		$schedule = \SScribe_CLI_Schedule_Command::schedule_from_args(
			array(
				'label'     => 'Nightly',
				'frequency' => 'daily',
				'formats'   => ' ',
			),
			7,
			time()
		);

		$this::assertSame( array( 'docx', 'pdf', 'html', 'markdown' ), $schedule->formats );
		$this::assertFalse( $schedule->incremental );
	}

	public function test_invalid_options_raise_a_validation_error(): void {
		$this->expectException( \SScribe_Validation_Exception::class );

		\SScribe_CLI_Schedule_Command::schedule_from_args(
			array(
				'label'     => 'Nightly',
				'frequency' => 'daily',
				'hour'      => '25',
			),
			7,
			time()
		);
	}

	public function test_owner_defaults_to_the_current_user_and_accepts_an_id(): void {
		$GLOBALS['sscribe_test_users'] = array( 12 => new \WP_User( 12 ) );

		$this::assertSame( 3, \SScribe_CLI_Schedule_Command::resolve_owner( '', 3 ) );
		$this::assertSame( 12, \SScribe_CLI_Schedule_Command::resolve_owner( '12', 3 ) );
		$this::assertSame( 0, \SScribe_CLI_Schedule_Command::resolve_owner( '404', 3 ) );
		$this::assertSame( 0, \SScribe_CLI_Schedule_Command::resolve_owner( 'nobody', 3 ) );
	}

	public function test_list_rows_are_ordered_by_next_run(): void {
		$later = \SScribe_Schedule::from_array( self::fields( 'Later', 2000000000 ) );
		$soon  = \SScribe_Schedule::from_array( self::fields( 'Soon', 1900000000 ) );
		$off   = \SScribe_Schedule::from_array( array_merge( self::fields( 'Off', 1800000000 ), array( 'enabled' => false ) ) );

		$rows = \SScribe_CLI_Schedule_Command::list_rows(
			array(
				$later->id => $later,
				$soon->id  => $soon,
				$off->id   => $off,
			)
		);

		$this::assertSame( array( 'Off', 'Soon', 'Later' ), array_column( $rows, 'label' ) );
		$this::assertSame( array( 'id', 'label', 'frequency', 'next_run', 'last_run', 'status', 'enabled' ), array_keys( $rows[0] ) );
		$this::assertSame( '', $rows[0]['next_run'] );
		$this::assertSame( 'no', $rows[0]['enabled'] );
		$this::assertSame( 'never', $rows[1]['status'] );
		$this::assertNotSame( '', $rows[1]['next_run'] );
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function fields( string $label, int $next_run_at ): array {
		return array(
			'label'         => $label,
			'frequency'     => 'daily',
			'formats'       => 'docx',
			'owner_user_id' => 7,
			'next_run_at'   => $next_run_at,
		);
	}
}
