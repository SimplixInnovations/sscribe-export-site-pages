<?php
/**
 * SScribe Schedule
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
 * A recurring export: what to export, when, for whom, and how the last run went.
 *
 * Instances never change. Use with() to get an updated copy.
 */
final class SScribe_Schedule {

	public const FREQUENCIES      = array( 'hourly', 'daily', 'weekly', 'monthly' );
	public const RUN_STATUSES     = array( '', 'success', 'failed', 'running' );
	public const MAX_LABEL_LENGTH = 80;
	public const MAX_RETENTION    = 3650;

	private const ID_PATTERN      = '/^sch_[a-f0-9]{12}$/D';
	private const MAX_ERROR_CHARS = 500;
	private const SEARCH_DAYS     = 400;

	/**
	 * Schedule id, "sch_" followed by 12 hex characters.
	 *
	 * @var string
	 */
	public readonly string $id;

	/**
	 * Name shown to people.
	 *
	 * @var string
	 */
	public readonly string $label;

	/**
	 * Language code, empty for every language.
	 *
	 * @var string
	 */
	public readonly string $language;

	/**
	 * Post status to export.
	 *
	 * @var string
	 */
	public readonly string $post_status;

	/**
	 * Post type to export.
	 *
	 * @var string
	 */
	public readonly string $post_type;

	/**
	 * Formats to export.
	 *
	 * @var list<string>
	 */
	public readonly array $formats;

	/**
	 * Per-format options keyed by option name.
	 *
	 * @var array<string, mixed>
	 */
	public readonly array $format_options;

	/**
	 * One of FREQUENCIES.
	 *
	 * @var string
	 */
	public readonly string $frequency;

	/**
	 * Hour of day in the site timezone, 0 to 23.
	 *
	 * @var int
	 */
	public readonly int $hour;

	/**
	 * Day of week for weekly schedules, 0 (Sunday) to 6.
	 *
	 * @var int
	 */
	public readonly int $weekday;

	/**
	 * Day of month for monthly schedules, 1 to 28.
	 *
	 * @var int
	 */
	public readonly int $day_of_month;

	/**
	 * User the export runs as and who owns the archives.
	 *
	 * @var int
	 */
	public readonly int $owner_user_id;

	/**
	 * Whether runs after the first only export posts changed since the last success.
	 *
	 * @var bool
	 */
	public readonly bool $incremental;

	/**
	 * Days to keep archives, 0 for the plugin default.
	 *
	 * @var int
	 */
	public readonly int $retention_days;

	/**
	 * Whether to email the owner after each run.
	 *
	 * @var bool
	 */
	public readonly bool $notify;

	/**
	 * Whether the schedule runs on its own.
	 *
	 * @var bool
	 */
	public readonly bool $enabled;

	/**
	 * When the schedule was created.
	 *
	 * @var int
	 */
	public readonly int $created_at;

	/**
	 * Start time of the last successful run; the incremental watermark.
	 *
	 * @var int
	 */
	public readonly int $last_run_at;

	/**
	 * One of RUN_STATUSES.
	 *
	 * @var string
	 */
	public readonly string $last_run_status;

	/**
	 * ZIP filename produced by the last successful run.
	 *
	 * @var string
	 */
	public readonly string $last_run_file;

	/**
	 * Message from the last failed run.
	 *
	 * @var string
	 */
	public readonly string $last_error;

	/**
	 * When the schedule is next due.
	 *
	 * @var int
	 */
	public readonly int $next_run_at;

	/**
	 * Export session of the run in progress, if any.
	 *
	 * @var string
	 */
	public readonly string $running_session;

	/**
	 * When the run in progress started, 0 when idle.
	 *
	 * @var int
	 */
	public readonly int $run_started_at;

	/**
	 * Build a schedule from already validated values.
	 *
	 * @param array<string, mixed> $values Validated values keyed by property name.
	 */
	private function __construct( array $values ) {
		$this->id              = (string) $values['id'];
		$this->label           = (string) $values['label'];
		$this->language        = (string) $values['language'];
		$this->post_status     = (string) $values['post_status'];
		$this->post_type       = (string) $values['post_type'];
		$this->formats         = array_values( array_map( 'strval', (array) $values['formats'] ) );
		$this->format_options  = (array) $values['format_options'];
		$this->frequency       = (string) $values['frequency'];
		$this->hour            = (int) $values['hour'];
		$this->weekday         = (int) $values['weekday'];
		$this->day_of_month    = (int) $values['day_of_month'];
		$this->owner_user_id   = (int) $values['owner_user_id'];
		$this->incremental     = (bool) $values['incremental'];
		$this->retention_days  = (int) $values['retention_days'];
		$this->notify          = (bool) $values['notify'];
		$this->enabled         = (bool) $values['enabled'];
		$this->created_at      = (int) $values['created_at'];
		$this->last_run_at     = (int) $values['last_run_at'];
		$this->last_run_status = (string) $values['last_run_status'];
		$this->last_run_file   = (string) $values['last_run_file'];
		$this->last_error      = (string) $values['last_error'];
		$this->next_run_at     = (int) $values['next_run_at'];
		$this->running_session = (string) $values['running_session'];
		$this->run_started_at  = (int) $values['run_started_at'];
	}

	/**
	 * Build a schedule from loosely typed input such as stored options or CLI arguments.
	 *
	 * @param array<string, mixed> $data Schedule fields.
	 * @return self
	 * @throws SScribe_Validation_Exception When a field is missing or out of range.
	 */
	public static function from_array( array $data ): self {
		$job = SScribe_Export_Job::from_array(
			array(
				'language'       => $data['language'] ?? '',
				'post_status'    => $data['post_status'] ?? 'publish',
				'post_type'      => $data['post_type'] ?? 'page',
				'formats'        => $data['formats'] ?? array(),
				'format_options' => $data['format_options'] ?? array(),
			)
		);

		return new self(
			array(
				'id'              => self::clean_id( $data['id'] ?? '' ),
				'label'           => self::clean_label( $data['label'] ?? '' ),
				'language'        => $job->language,
				'post_status'     => '' !== $job->post_status ? $job->post_status : 'publish',
				'post_type'       => '' !== $job->post_type ? $job->post_type : 'page',
				'formats'         => self::check_formats( $job->formats ),
				'format_options'  => $job->format_options,
				'frequency'       => self::clean_frequency( $data['frequency'] ?? '' ),
				'hour'            => self::int_in_range( $data, 'hour', 0, 0, 23 ),
				'weekday'         => self::int_in_range( $data, 'weekday', 1, 0, 6 ),
				'day_of_month'    => self::int_in_range( $data, 'day_of_month', 1, 1, 28 ),
				'owner_user_id'   => self::int_in_range( $data, 'owner_user_id', 0, 1, PHP_INT_MAX ),
				'incremental'     => self::to_bool( $data['incremental'] ?? false ),
				'retention_days'  => self::int_in_range( $data, 'retention_days', 0, 0, self::MAX_RETENTION ),
				'notify'          => self::to_bool( $data['notify'] ?? false ),
				'enabled'         => self::to_bool( $data['enabled'] ?? true ),
				'created_at'      => self::timestamp( $data['created_at'] ?? null, time() ),
				'last_run_at'     => self::timestamp( $data['last_run_at'] ?? null, 0 ),
				'last_run_status' => self::clean_status( $data['last_run_status'] ?? '' ),
				'last_run_file'   => self::clean_file( $data['last_run_file'] ?? '' ),
				'last_error'      => self::clean_error( $data['last_error'] ?? '' ),
				'next_run_at'     => self::timestamp( $data['next_run_at'] ?? null, 0 ),
				'running_session' => sanitize_key( self::scalar_string( $data['running_session'] ?? '' ) ),
				'run_started_at'  => self::timestamp( $data['run_started_at'] ?? null, 0 ),
			)
		);
	}

	/**
	 * Every field as a plain array, suitable for storage.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'id'              => $this->id,
			'label'           => $this->label,
			'language'        => $this->language,
			'post_status'     => $this->post_status,
			'post_type'       => $this->post_type,
			'formats'         => $this->formats,
			'format_options'  => $this->format_options,
			'frequency'       => $this->frequency,
			'hour'            => $this->hour,
			'weekday'         => $this->weekday,
			'day_of_month'    => $this->day_of_month,
			'owner_user_id'   => $this->owner_user_id,
			'incremental'     => $this->incremental,
			'retention_days'  => $this->retention_days,
			'notify'          => $this->notify,
			'enabled'         => $this->enabled,
			'created_at'      => $this->created_at,
			'last_run_at'     => $this->last_run_at,
			'last_run_status' => $this->last_run_status,
			'last_run_file'   => $this->last_run_file,
			'last_error'      => $this->last_error,
			'next_run_at'     => $this->next_run_at,
			'running_session' => $this->running_session,
			'run_started_at'  => $this->run_started_at,
		);
	}

	/**
	 * A copy with some fields changed. The id never changes.
	 *
	 * @param array<string, mixed> $changes Fields to change.
	 * @return self
	 * @throws SScribe_Validation_Exception When a changed field is invalid.
	 */
	public function with( array $changes ): self {
		$changes['id'] = $this->id;

		return self::from_array( array_merge( $this->to_array(), $changes ) );
	}

	/**
	 * Whether a run is in progress.
	 *
	 * @return bool
	 */
	public function is_running(): bool {
		return 'running' === $this->last_run_status || '' !== $this->running_session;
	}

	/**
	 * Archive lifetime in seconds.
	 *
	 * @param int $default_days Days used when the schedule keeps the plugin default.
	 * @return int
	 */
	public function retention_seconds( int $default_days = 3 ): int {
		$days = $this->retention_days > 0 ? $this->retention_days : max( 1, $default_days );

		return $days * DAY_IN_SECONDS;
	}

	/**
	 * The next time the schedule is due, strictly after a moment.
	 *
	 * Times are worked out on the wall clock of the given timezone, so a
	 * daily 09:00 run stays at 09:00 across daylight saving changes. An
	 * hour skipped by a clock change runs at the first valid time after it.
	 *
	 * @param int    $after    Unix timestamp to search from.
	 * @param string $timezone Timezone name or offset; empty uses the site timezone.
	 * @return int Unix timestamp.
	 */
	public function compute_next_run( int $after, string $timezone = '' ): int {
		$zone  = self::zone( $timezone );
		$local = ( new DateTimeImmutable( '@' . $after ) )->setTimezone( $zone );

		if ( 'hourly' === $this->frequency ) {
			$candidate = $local->setTime( (int) $local->format( 'G' ), 0, 0 )->getTimestamp();
			while ( $candidate <= $after ) {
				$candidate += HOUR_IN_SECONDS;
			}
			return $candidate;
		}

		$year  = (int) $local->format( 'Y' );
		$month = (int) $local->format( 'n' );
		$day   = (int) $local->format( 'j' );

		for ( $offset = 0; $offset <= self::SEARCH_DAYS; $offset++ ) {
			$date = $local->setDate( $year, $month, $day + $offset );
			if ( ! $this->runs_on( $date ) ) {
				continue;
			}
			$candidate = $date->setTime( $this->hour, 0, 0 )->getTimestamp();
			if ( $candidate > $after ) {
				return $candidate;
			}
		}

		return $after + DAY_IN_SECONDS;
	}

	/**
	 * The export job for the next run.
	 *
	 * Incremental schedules only export posts changed since the start of
	 * the last successful run.
	 *
	 * @return SScribe_Export_Job
	 */
	public function to_job(): SScribe_Export_Job {
		return new SScribe_Export_Job(
			$this->language,
			$this->post_status,
			$this->post_type,
			$this->formats,
			$this->format_options,
			$this->incremental && $this->last_run_at > 0 ? $this->last_run_at : 0,
			$this->id
		);
	}

	/**
	 * Whether the schedule runs on a given local date.
	 *
	 * @param DateTimeImmutable $date Local date.
	 * @return bool
	 */
	private function runs_on( DateTimeImmutable $date ): bool {
		if ( 'weekly' === $this->frequency ) {
			return (int) $date->format( 'w' ) === $this->weekday;
		}
		if ( 'monthly' === $this->frequency ) {
			return (int) $date->format( 'j' ) === $this->day_of_month;
		}

		return true;
	}

	/**
	 * Resolve a timezone name, falling back to the site timezone.
	 *
	 * @param string $timezone Timezone name or offset.
	 * @return DateTimeZone
	 */
	private static function zone( string $timezone ): DateTimeZone {
		if ( '' !== $timezone ) {
			try {
				return new DateTimeZone( $timezone );
			} catch ( \Exception $e ) {
				unset( $e );
			}
		}

		return wp_timezone();
	}

	/**
	 * Validate or generate the id.
	 *
	 * @param mixed $value Raw id.
	 * @return string
	 * @throws SScribe_Validation_Exception When a given id has the wrong shape.
	 */
	private static function clean_id( mixed $value ): string {
		$id = strtolower( self::scalar_string( $value ) );
		if ( '' === $id ) {
			return 'sch_' . bin2hex( random_bytes( 6 ) );
		}
		if ( 1 !== preg_match( self::ID_PATTERN, $id ) ) {
			throw new SScribe_Validation_Exception( esc_html__( 'The schedule id is not valid.', 'sscribe-export-site-pages' ), 'id', 'format' );
		}

		return $id;
	}

	/**
	 * Validate the label.
	 *
	 * @param mixed $value Raw label.
	 * @return string
	 * @throws SScribe_Validation_Exception When the label is empty or too long.
	 */
	private static function clean_label( mixed $value ): string {
		$label = trim( sanitize_text_field( self::scalar_string( $value ) ) );
		if ( '' === $label ) {
			throw new SScribe_Validation_Exception( esc_html__( 'Give the schedule a label.', 'sscribe-export-site-pages' ), 'label', 'required' );
		}
		if ( mb_strlen( $label ) > self::MAX_LABEL_LENGTH ) {
			throw new SScribe_Validation_Exception(
				esc_html(
					sprintf(
						/* translators: %d: Maximum number of characters. */
						__( 'The schedule label can be at most %d characters long.', 'sscribe-export-site-pages' ),
						self::MAX_LABEL_LENGTH
					)
				),
				'label',
				'max_length'
			);
		}

		return $label;
	}

	/**
	 * Require at least one format and only supported ones.
	 *
	 * @param string[] $formats Cleaned format names.
	 * @return list<string>
	 * @throws SScribe_Validation_Exception When no format or an unknown one is given.
	 */
	private static function check_formats( array $formats ): array {
		if ( array() === $formats ) {
			throw new SScribe_Validation_Exception( esc_html__( 'Choose at least one format.', 'sscribe-export-site-pages' ), 'formats', 'required' );
		}
		foreach ( $formats as $format ) {
			if ( ! SScribe_Exporter_Factory::is_supported( $format ) ) {
				throw new SScribe_Validation_Exception(
					esc_html(
						sprintf(
							/* translators: %s: Format name. */
							__( 'Unknown format "%s". Use docx, pdf, html or markdown.', 'sscribe-export-site-pages' ),
							$format
						)
					),
					'formats',
					'supported'
				);
			}
		}

		return array_values( $formats );
	}

	/**
	 * Validate the frequency.
	 *
	 * @param mixed $value Raw frequency.
	 * @return string
	 * @throws SScribe_Validation_Exception When the frequency is unknown.
	 */
	private static function clean_frequency( mixed $value ): string {
		$frequency = strtolower( trim( self::scalar_string( $value ) ) );
		if ( ! in_array( $frequency, self::FREQUENCIES, true ) ) {
			throw new SScribe_Validation_Exception(
				esc_html__( 'Frequency must be hourly, daily, weekly or monthly.', 'sscribe-export-site-pages' ),
				'frequency',
				'allowed'
			);
		}

		return $frequency;
	}

	/**
	 * Read a whole number within bounds.
	 *
	 * @param array<string, mixed> $data     Input.
	 * @param string               $field    Field name.
	 * @param int                  $fallback Value used when the field is missing or empty.
	 * @param int                  $min      Smallest allowed value.
	 * @param int                  $max      Largest allowed value.
	 * @return int
	 * @throws SScribe_Validation_Exception When the value is not a whole number in range.
	 */
	private static function int_in_range( array $data, string $field, int $fallback, int $min, int $max ): int {
		$value = $data[ $field ] ?? null;
		if ( null === $value || '' === $value ) {
			$value = $fallback;
		}
		if ( is_string( $value ) && 1 === preg_match( '/^\s*-?\d+\s*$/', $value ) ) {
			$value = (int) trim( $value );
		}
		if ( ! is_int( $value ) || $value < $min || $value > $max ) {
			throw new SScribe_Validation_Exception(
				esc_html(
					sprintf(
						/* translators: %s: Field name. */
						__( 'The value of "%s" is out of range.', 'sscribe-export-site-pages' ),
						$field
					)
				),
				esc_html( $field ),
				'range'
			);
		}

		return $value;
	}

	/**
	 * Read a boolean from a bool, number or text such as "yes".
	 *
	 * @param mixed $value Raw value.
	 * @return bool
	 */
	private static function to_bool( mixed $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}

		return true === filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
	}

	/**
	 * Read a non-negative timestamp.
	 *
	 * @param mixed $value    Raw value.
	 * @param int   $fallback Value used when missing.
	 * @return int
	 */
	private static function timestamp( mixed $value, int $fallback ): int {
		if ( null === $value || '' === $value ) {
			return $fallback;
		}

		return is_numeric( $value ) ? max( 0, (int) $value ) : $fallback;
	}

	/**
	 * Keep a known run status.
	 *
	 * @param mixed $value Raw status.
	 * @return string
	 */
	private static function clean_status( mixed $value ): string {
		$status = self::scalar_string( $value );

		return in_array( $status, self::RUN_STATUSES, true ) ? $status : '';
	}

	/**
	 * Keep a bare ZIP filename.
	 *
	 * @param mixed $value Raw filename.
	 * @return string
	 */
	private static function clean_file( mixed $value ): string {
		$file = self::scalar_string( $value );

		return 1 === preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]{0,199}\.zip$/D', $file ) ? $file : '';
	}

	/**
	 * Keep a short, plain error message.
	 *
	 * @param mixed $value Raw message.
	 * @return string
	 */
	private static function clean_error( mixed $value ): string {
		$message = sanitize_text_field( self::scalar_string( $value ) );

		return mb_substr( $message, 0, self::MAX_ERROR_CHARS );
	}

	/**
	 * Turn a scalar into a trimmed string; anything else becomes empty.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private static function scalar_string( mixed $value ): string {
		if ( ! is_scalar( $value ) || is_bool( $value ) ) {
			return '';
		}

		return trim( (string) $value );
	}
}
