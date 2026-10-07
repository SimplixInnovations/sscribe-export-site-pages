<?php
/**
 * SScribe Schedule Store
 *
 * @package SScribe_Export_Site_Pages
 * @license GPL v2 or later
 * @link    https://www.gnu.org/licenses/gpl-2.0.html
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps schedules in one option that is not autoloaded.
 *
 * Writes are serialized with a short database lock so two cron requests
 * updating different schedules cannot overwrite each other.
 */
final class SScribe_Schedule_Store {

	public const OPTION        = 'sscribe_schedules';
	public const MAX_SCHEDULES = 20;

	private const LOCK_NAME      = 'schedule-store';
	private const LOCK_TTL       = 30;
	private const LOCK_ATTEMPTS  = 20;
	private const LOCK_WAIT_MS   = 100;

	/**
	 * Lock manager guarding writes.
	 *
	 * @var SScribe_Export_Lock_Manager
	 */
	private SScribe_Export_Lock_Manager $locks;

	/**
	 * Set up the store.
	 *
	 * @param SScribe_Export_Lock_Manager|null $locks Lock manager; a new one is made when null.
	 */
	public function __construct( ?SScribe_Export_Lock_Manager $locks = null ) {
		$this->locks = $locks ?? new SScribe_Export_Lock_Manager();
	}

	/**
	 * Every stored schedule, keyed by id.
	 *
	 * Rows that no longer validate are left out and logged.
	 *
	 * @return array<string, SScribe_Schedule>
	 */
	public function all(): array {
		$schedules = array();
		foreach ( $this->raw() as $key => $row ) {
			if ( ! is_array( $row ) ) {
				self::report_bad_row( (string) $key, 'not_an_array' );
				continue;
			}
			try {
				$schedule = SScribe_Schedule::from_array( $row );
			} catch ( SScribe_Validation_Exception $e ) {
				self::report_bad_row( (string) $key, $e->getMessage() );
				continue;
			}
			$schedules[ $schedule->id ] = $schedule;
		}

		return $schedules;
	}

	/**
	 * One schedule by id.
	 *
	 * @param string $id Schedule id.
	 * @return SScribe_Schedule|null
	 */
	public function get( string $id ): ?SScribe_Schedule {
		return $this->all()[ $id ] ?? null;
	}

	/**
	 * Add or replace a schedule.
	 *
	 * @param SScribe_Schedule $schedule Schedule to store.
	 * @return bool False when the limit is reached, the lock is busy or the write failed.
	 */
	public function save( SScribe_Schedule $schedule ): bool {
		return $this->mutate(
			static function ( array $rows ) use ( $schedule ): ?array {
				if ( ! isset( $rows[ $schedule->id ] ) && count( $rows ) >= self::MAX_SCHEDULES ) {
					return null;
				}
				$rows[ $schedule->id ] = $schedule->to_array();
				return $rows;
			}
		);
	}

	/**
	 * Remove a schedule.
	 *
	 * @param string $id Schedule id.
	 * @return bool False when the schedule does not exist or the write failed.
	 */
	public function delete( string $id ): bool {
		return $this->mutate(
			static function ( array $rows ) use ( $id ): ?array {
				if ( ! isset( $rows[ $id ] ) ) {
					return null;
				}
				unset( $rows[ $id ] );
				return $rows;
			}
		);
	}

	/**
	 * Enabled, idle schedules whose next run time has come, earliest first.
	 *
	 * @param int $now Current Unix time.
	 * @return list<SScribe_Schedule>
	 */
	public function due( int $now ): array {
		$due = array_values(
			array_filter(
				$this->all(),
				static fn( SScribe_Schedule $schedule ): bool => $schedule->enabled
					&& ! $schedule->is_running()
					&& $schedule->next_run_at > 0
					&& $schedule->next_run_at <= $now
			)
		);

		usort(
			$due,
			static fn( SScribe_Schedule $a, SScribe_Schedule $b ): int => $a->next_run_at <=> $b->next_run_at
		);

		return $due;
	}

	/**
	 * Whether another schedule can be added.
	 *
	 * @return bool
	 */
	public function has_room(): bool {
		return count( $this->raw() ) < self::MAX_SCHEDULES;
	}

	/**
	 * Stored rows as they are in the option.
	 *
	 * @return array<string|int, mixed>
	 */
	private function raw(): array {
		$rows = get_option( self::OPTION, array() );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Apply a change to the stored rows under the store lock.
	 *
	 * @param callable(array<string|int, mixed>): ?array<string|int, mixed> $change Returns the new rows, or null to abort.
	 * @return bool
	 */
	private function mutate( callable $change ): bool {
		$token = $this->acquire();
		if ( null === $token ) {
			SScribe_Logger::instance( SScribe_Logger::is_logging_enabled() )->warning( 'Schedule store is locked; change not saved' );
			return false;
		}

		try {
			$rows = $change( $this->raw() );
			if ( null === $rows ) {
				return false;
			}
			if ( update_option( self::OPTION, $rows, false ) ) {
				return true;
			}

			return get_option( self::OPTION, array() ) === $rows;
		} finally {
			$this->locks->release_lock( self::LOCK_NAME, $token );
		}
	}

	/**
	 * Take the store lock, waiting briefly when another request holds it.
	 *
	 * @return string|null Lock token, or null when it stayed busy.
	 */
	private function acquire(): ?string {
		for ( $attempt = 0; $attempt < self::LOCK_ATTEMPTS; $attempt++ ) {
			$token = $this->locks->acquire_lock( self::LOCK_NAME, self::LOCK_TTL, self::LOCK_TTL );
			if ( null !== $token ) {
				return $token;
			}
			usleep( self::LOCK_WAIT_MS * 1000 );
		}

		return null;
	}

	/**
	 * Log a stored row that could not be read.
	 *
	 * @param string $key    Row key.
	 * @param string $reason Why it was skipped.
	 * @return void
	 */
	private static function report_bad_row( string $key, string $reason ): void {
		SScribe_Logger::instance( SScribe_Logger::is_logging_enabled() )->warning(
			'Stored schedule skipped',
			array(
				'schedule_id' => sanitize_key( $key ),
				'reason'      => $reason,
			)
		);
	}
}
