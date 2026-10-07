<?php
/**
 * SScribe WP-CLI Schedule Command
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
 * Create, list, run and remove scheduled exports.
 */
final class SScribe_CLI_Schedule_Command {

	private const DEFAULT_FORMATS = 'docx,pdf,html,markdown';
	private const LIST_FORMATS    = array( 'table', 'json', 'csv' );
	private const LIST_FIELDS     = array( 'id', 'label', 'frequency', 'next_run', 'last_run', 'status', 'enabled' );

	/**
	 * Add a schedule.
	 *
	 * ## OPTIONS
	 *
	 * --label=<text>
	 * : Name of the schedule, up to 80 characters.
	 *
	 * --frequency=<frequency>
	 * : How often to run.
	 * ---
	 * options:
	 *   - hourly
	 *   - daily
	 *   - weekly
	 *   - monthly
	 * ---
	 *
	 * [--hour=<hour>]
	 * : Hour of day in the site timezone, 0 to 23.
	 * ---
	 * default: 0
	 * ---
	 *
	 * [--weekday=<day>]
	 * : Day of week for weekly runs, 0 (Sunday) to 6.
	 * ---
	 * default: 1
	 * ---
	 *
	 * [--day=<day>]
	 * : Day of month for monthly runs, 1 to 28.
	 * ---
	 * default: 1
	 * ---
	 *
	 * [--formats=<list>]
	 * : Comma separated formats: docx, pdf, html, markdown.
	 * ---
	 * default: docx,pdf,html,markdown
	 * ---
	 *
	 * [--post-type=<type>]
	 * : Post type to export, or "any".
	 * ---
	 * default: page
	 * ---
	 *
	 * [--post-status=<status>]
	 * : One of publish, private, draft, pending, future or all.
	 * ---
	 * default: publish
	 * ---
	 *
	 * [--language=<code>]
	 * : Language code when a multilingual plugin is active. Leave out for every language.
	 *
	 * [--incremental]
	 * : After the first run, only export posts changed since the last successful run.
	 *
	 * [--retention-days=<days>]
	 * : Days to keep each archive. 0 keeps the plugin default of 3 days.
	 * ---
	 * default: 0
	 * ---
	 *
	 * [--notify]
	 * : Email the owner after every run.
	 *
	 * [--destination=<id>]
	 * : Send every archive to this destination as well. See wp sscribe destinations.
	 *
	 * [--destination-settings=<json>]
	 * : Destination settings as a JSON object. Passwords are stored encrypted.
	 *
	 * [--owner=<user>]
	 * : Login or id of the user the export runs as. Defaults to --user.
	 *
	 * [--porcelain]
	 * : Print only the new schedule id.
	 *
	 * ## EXAMPLES
	 *
	 *     wp sscribe schedule add --user=admin --label="Nightly docs" --frequency=daily --hour=2 --formats=docx,markdown
	 *     wp sscribe schedule add --user=admin --label="Weekly changes" --frequency=weekly --weekday=1 --incremental --notify
	 *     wp sscribe schedule add --user=admin --label="Offsite" --frequency=daily --destination=s3 --destination-settings='{"region":"eu-west-1","bucket":"backups","access_key":"AKIA...","secret_key":"..."}'
	 *
	 * @param array<int, string>    $args       Positional arguments, unused.
	 * @param array<string, string> $assoc_args Options.
	 * @return void
	 */
	public function add( array $args, array $assoc_args ): void {
		unset( $args );

		$owner_id = self::resolve_owner( $assoc_args['owner'] ?? '', (int) get_current_user_id() );
		if ( $owner_id <= 0 ) {
			self::fail( __( 'Name an owner who exists: --owner=<login|id>, or run the command with --user=<login>.', 'sscribe-export-site-pages' ) );
		}
		if ( ! user_can( $owner_id, SScribe_Capabilities::get_required() ) ) {
			self::fail( __( 'The owner is not allowed to export.', 'sscribe-export-site-pages' ) );
		}

		$store = new SScribe_Schedule_Store();
		if ( ! $store->has_room() ) {
			self::fail(
				sprintf(
					/* translators: %d: Maximum number of schedules. */
					__( 'There can be at most %d schedules. Delete one first.', 'sscribe-export-site-pages' ),
					SScribe_Schedule_Store::MAX_SCHEDULES
				)
			);
		}

		try {
			$schedule = self::schedule_from_args( $assoc_args, $owner_id, time() );
		} catch ( SScribe_Validation_Exception $e ) {
			self::fail( html_entity_decode( $e->getMessage(), ENT_QUOTES, 'UTF-8' ) );
		}

		if ( ! $store->save( $schedule ) ) {
			self::fail( __( 'The schedule could not be saved.', 'sscribe-export-site-pages' ) );
		}
		SScribe_Scheduler::ensure_scheduled();

		if ( ! empty( $assoc_args['porcelain'] ) ) {
			self::line( $schedule->id );
			return;
		}

		self::succeed(
			sprintf(
				/* translators: 1: Schedule id, 2: Date and time of the first run. */
				__( 'Schedule %1$s added. First run: %2$s', 'sscribe-export-site-pages' ),
				$schedule->id,
				self::site_time( $schedule->next_run_at )
			)
		);
	}

	/**
	 * List schedules.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp sscribe schedule list
	 *     wp sscribe schedule list --format=json
	 *
	 * @subcommand list
	 *
	 * @param array<int, string>    $args       Positional arguments, unused.
	 * @param array<string, string> $assoc_args Options.
	 * @return void
	 */
	public function list_schedules( array $args, array $assoc_args ): void {
		unset( $args );

		$format = isset( $assoc_args['format'] ) ? (string) $assoc_args['format'] : 'table';
		if ( ! in_array( $format, self::LIST_FORMATS, true ) ) {
			self::fail( __( 'Use --format=table, --format=json or --format=csv.', 'sscribe-export-site-pages' ) );
		}

		$rows = self::list_rows( ( new SScribe_Schedule_Store() )->all() );

		if ( class_exists( 'WP_CLI' ) && function_exists( 'WP_CLI\Utils\format_items' ) ) {
			\WP_CLI\Utils\format_items( $format, $rows, self::LIST_FIELDS );
		}
	}

	/**
	 * Run a schedule now and wait for it to finish, even when it is disabled.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Schedule id.
	 *
	 * ## EXAMPLES
	 *
	 *     wp sscribe schedule run sch_0123456789ab
	 *
	 * @param array<int, string>    $args       Schedule id.
	 * @param array<string, string> $assoc_args Options, unused.
	 * @return void
	 */
	public function run( array $args, array $assoc_args ): void {
		unset( $assoc_args );

		$id        = self::id_from_args( $args );
		$scheduler = new SScribe_Scheduler( new SScribe_Schedule_Store(), null, null, null, 0.0 );
		$outcome   = $scheduler->run( $id, true );

		if ( ! $outcome->is_success() ) {
			$message = $outcome->payload()['message'] ?? '';
			self::fail(
				sprintf(
					'%1$s (%2$s)',
					is_string( $message ) && '' !== $message ? $message : __( 'The export failed.', 'sscribe-export-site-pages' ),
					'' !== $outcome->code() ? $outcome->code() : $outcome->kind()
				)
			);
		}

		$payload  = $outcome->payload();
		$filename = isset( $payload['filename'] ) && is_string( $payload['filename'] ) ? $payload['filename'] : '';
		if ( '' === $filename ) {
			self::succeed( __( 'Nothing changed since the last run; no archive was made.', 'sscribe-export-site-pages' ) );
			return;
		}

		self::succeed(
			sprintf(
				/* translators: 1: ZIP filename, 2: Number of pages exported. */
				__( 'Export complete: %1$s (%2$d pages).', 'sscribe-export-site-pages' ),
				$filename,
				(int) ( $payload['pages'] ?? 0 )
			)
		);
	}

	/**
	 * Delete a schedule. Archives it already made are kept until they expire.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Schedule id.
	 *
	 * @param array<int, string>    $args       Schedule id.
	 * @param array<string, string> $assoc_args Options, unused.
	 * @return void
	 */
	public function delete( array $args, array $assoc_args ): void {
		unset( $assoc_args );

		$id = self::id_from_args( $args );
		if ( ! ( new SScribe_Schedule_Store() )->delete( $id ) ) {
			self::fail( __( 'No schedule with that id could be deleted.', 'sscribe-export-site-pages' ) );
		}

		self::succeed( __( 'Schedule deleted.', 'sscribe-export-site-pages' ) );
	}

	/**
	 * Enable a schedule and work out its next run.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Schedule id.
	 *
	 * @param array<int, string>    $args       Schedule id.
	 * @param array<string, string> $assoc_args Options, unused.
	 * @return void
	 */
	public function enable( array $args, array $assoc_args ): void {
		unset( $assoc_args );

		$schedule = self::toggle( self::id_from_args( $args ), true );
		SScribe_Scheduler::ensure_scheduled();

		self::succeed(
			sprintf(
				/* translators: %s: Date and time of the next run. */
				__( 'Schedule enabled. Next run: %s', 'sscribe-export-site-pages' ),
				self::site_time( $schedule->next_run_at )
			)
		);
	}

	/**
	 * Disable a schedule. A run in progress is allowed to finish.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Schedule id.
	 *
	 * @param array<int, string>    $args       Schedule id.
	 * @param array<string, string> $assoc_args Options, unused.
	 * @return void
	 */
	public function disable( array $args, array $assoc_args ): void {
		unset( $assoc_args );

		self::toggle( self::id_from_args( $args ), false );
		self::succeed( __( 'Schedule disabled.', 'sscribe-export-site-pages' ) );
	}

	/**
	 * Build a new schedule from command line options.
	 *
	 * @param array<string, mixed> $assoc_args Options from the command line.
	 * @param int                  $owner_id   Owner user id.
	 * @param int                  $now        Current Unix time, for the first run.
	 * @return SScribe_Schedule
	 * @throws SScribe_Validation_Exception When an option is invalid.
	 */
	public static function schedule_from_args( array $assoc_args, int $owner_id, int $now ): SScribe_Schedule {
		$formats = $assoc_args['formats'] ?? self::DEFAULT_FORMATS;
		if ( ! is_string( $formats ) || '' === trim( $formats ) ) {
			$formats = self::DEFAULT_FORMATS;
		}
		$destination = SScribe_CLI_Destination_Args::parse( $assoc_args );

		$schedule = SScribe_Schedule::from_array(
			array(
				'label'          => $assoc_args['label'] ?? '',
				'frequency'      => $assoc_args['frequency'] ?? '',
				'hour'           => $assoc_args['hour'] ?? 0,
				'weekday'        => $assoc_args['weekday'] ?? 1,
				'day_of_month'   => $assoc_args['day'] ?? 1,
				'formats'        => $formats,
				'post_type'      => $assoc_args['post-type'] ?? 'page',
				'post_status'    => $assoc_args['post-status'] ?? 'publish',
				'language'       => $assoc_args['language'] ?? '',
				'incremental'    => ! empty( $assoc_args['incremental'] ),
				'retention_days' => $assoc_args['retention-days'] ?? 0,
				'notify'         => ! empty( $assoc_args['notify'] ),
				'owner_user_id'  => $owner_id,
				'enabled'        => true,
				'created_at'     => $now,
				'destinations'   => null === $destination ? array() : array( $destination ),
			)
		);

		return $schedule->with( array( 'next_run_at' => $schedule->compute_next_run( $now, wp_timezone()->getName() ) ) );
	}

	/**
	 * Find the owner from a login or id, falling back to the current user.
	 *
	 * @param mixed $owner      Value of --owner.
	 * @param int   $current_id Current user id.
	 * @return int User id, or 0 when no such user exists.
	 */
	public static function resolve_owner( mixed $owner, int $current_id ): int {
		$owner = is_scalar( $owner ) && ! is_bool( $owner ) ? trim( (string) $owner ) : '';
		if ( '' === $owner ) {
			return max( 0, $current_id );
		}

		$user = ctype_digit( $owner ) ? get_userdata( (int) $owner ) : get_user_by( 'login', $owner );

		return $user instanceof WP_User ? (int) $user->ID : 0;
	}

	/**
	 * Table rows for the list subcommand, next due first.
	 *
	 * @param array<string, SScribe_Schedule> $schedules Schedules keyed by id.
	 * @return list<array<string, string>>
	 */
	public static function list_rows( array $schedules ): array {
		usort(
			$schedules,
			static fn( SScribe_Schedule $a, SScribe_Schedule $b ): int => $a->next_run_at <=> $b->next_run_at
		);

		$rows = array();
		foreach ( $schedules as $schedule ) {
			$rows[] = array(
				'id'        => $schedule->id,
				'label'     => $schedule->label,
				'frequency' => $schedule->frequency,
				'next_run'  => $schedule->enabled ? self::site_time( $schedule->next_run_at ) : '',
				'last_run'  => self::site_time( $schedule->last_run_at ),
				'status'    => '' !== $schedule->last_run_status ? $schedule->last_run_status : 'never',
				'enabled'   => $schedule->enabled ? 'yes' : 'no',
			);
		}

		return $rows;
	}

	/**
	 * The schedule id from the positional arguments.
	 *
	 * @param array<int, string> $args Positional arguments.
	 * @return string
	 */
	private static function id_from_args( array $args ): string {
		$id = isset( $args[0] ) ? strtolower( trim( (string) $args[0] ) ) : '';
		if ( 1 !== preg_match( '/^sch_[a-f0-9]{12}$/D', $id ) ) {
			self::fail( __( 'Give a schedule id such as sch_0123456789ab. See wp sscribe schedule list.', 'sscribe-export-site-pages' ) );
		}

		return $id;
	}

	/**
	 * Turn a schedule on or off and save it.
	 *
	 * @param string $id      Schedule id.
	 * @param bool   $enabled New state.
	 * @return SScribe_Schedule The saved schedule.
	 */
	private static function toggle( string $id, bool $enabled ): SScribe_Schedule {
		$store    = new SScribe_Schedule_Store();
		$schedule = $store->get( $id );
		if ( null === $schedule ) {
			self::fail( __( 'No schedule has that id.', 'sscribe-export-site-pages' ) );
		}

		$changes = array( 'enabled' => $enabled );
		if ( $enabled ) {
			$changes['next_run_at'] = $schedule->compute_next_run( time(), wp_timezone()->getName() );
		}
		$updated = $schedule->with( $changes );

		if ( ! $store->save( $updated ) ) {
			self::fail( __( 'The schedule could not be saved.', 'sscribe-export-site-pages' ) );
		}

		return $updated;
	}

	/**
	 * A timestamp in site time, or an empty string for never.
	 *
	 * @param int $timestamp Unix time.
	 * @return string
	 */
	private static function site_time( int $timestamp ): string {
		return $timestamp > 0 ? (string) wp_date( 'Y-m-d H:i', $timestamp, wp_timezone() ) : '';
	}

	/**
	 * Print a line.
	 *
	 * @param string $message Text to print.
	 * @return void
	 */
	private static function line( string $message ): void {
		if ( class_exists( 'WP_CLI' ) ) {
			\WP_CLI::log( $message );
		}
	}

	/**
	 * Print a success message.
	 *
	 * @param string $message Text to print.
	 * @return void
	 */
	private static function succeed( string $message ): void {
		if ( class_exists( 'WP_CLI' ) ) {
			\WP_CLI::success( $message );
		}
	}

	/**
	 * Print an error and stop with a non-zero exit code.
	 *
	 * @param string $message Text to print.
	 * @return never
	 * @throws RuntimeException When WP-CLI is not loaded.
	 */
	private static function fail( string $message ): never {
		if ( class_exists( 'WP_CLI' ) ) {
			\WP_CLI::error( $message );
		}

		throw new RuntimeException( esc_html( $message ) );
	}
}
