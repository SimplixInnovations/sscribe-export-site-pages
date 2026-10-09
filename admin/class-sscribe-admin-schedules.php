<?php
/**
 * Schedules tab: AJAX handlers for scheduled exports and their destinations.
 *
 * @package SScribe_Export_Site_Pages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lets administrators manage scheduled exports from the plugin screen.
 */
class SScribe_Admin_Schedules {

	public const CAPABILITY = 'manage_options';

	private const MAX_JSON_BYTES = 65536;

	/**
	 * Builds the scheduler used by the run action; replaceable in tests.
	 *
	 * @var callable|null
	 */
	private $scheduler_factory;

	/**
	 * Wire the controller.
	 *
	 * @param callable|null $scheduler_factory Returns an SScribe_Scheduler for a store.
	 */
	public function __construct( ?callable $scheduler_factory = null ) {
		$this->scheduler_factory = $scheduler_factory;
	}

	/**
	 * Register the AJAX actions.
	 */
	public function register_hooks(): void {
		add_action( 'wp_ajax_sscribe_schedules_list', array( $this, 'ajax_list' ) );
		add_action( 'wp_ajax_sscribe_schedule_save', array( $this, 'ajax_save' ) );
		add_action( 'wp_ajax_sscribe_schedule_delete', array( $this, 'ajax_delete' ) );
		add_action( 'wp_ajax_sscribe_schedule_toggle', array( $this, 'ajax_toggle' ) );
		add_action( 'wp_ajax_sscribe_schedule_run', array( $this, 'ajax_run' ) );
	}

	/**
	 * Whether the current user may manage schedules.
	 *
	 * @return bool
	 */
	public static function current_user_can_manage(): bool {
		return current_user_can( self::CAPABILITY );
	}

	/**
	 * AJAX: every schedule plus the metadata the form needs.
	 */
	public function ajax_list(): void {
		if ( ! $this->verify_request_authorization( 'export_read' ) ) {
			return;
		}
		SScribe_AJAX_Guard::success( $this->list_payload() );
	}

	/**
	 * AJAX: create or update a schedule.
	 */
	public function ajax_save(): void {
		if ( ! $this->verify_request_authorization( 'export_write' ) ) {
			return;
		}
		$input = $this->read_json( 'schedule' );
		if ( null === $input ) {
			SScribe_AJAX_Guard::error( array( 'message' => __( 'The schedule could not be read.', 'sscribe-export-site-pages' ) ), 400 );
			return;
		}
		$store    = new SScribe_Schedule_Store();
		$existing = isset( $input['id'] ) && is_string( $input['id'] ) ? $store->get( $input['id'] ) : null;
		if ( null === $existing && ! $store->has_room() ) {
			SScribe_AJAX_Guard::error(
				array(
					'message' => sprintf(
						/* translators: %d: Maximum number of schedules. */
						__( 'There can be at most %d schedules. Delete one first.', 'sscribe-export-site-pages' ),
						SScribe_Schedule_Store::MAX_SCHEDULES
					),
				),
				409
			);
			return;
		}
		try {
			$schedule = $this->schedule_from_input( $input, $existing );
		} catch ( SScribe_Validation_Exception $e ) {
			SScribe_AJAX_Guard::error(
				array(
					'message' => html_entity_decode( $e->getMessage(), ENT_QUOTES, 'UTF-8' ),
					'field'   => $e->get_field(),
				),
				400
			);
			return;
		}
		if ( ! $store->save( $schedule ) ) {
			SScribe_AJAX_Guard::error( array( 'message' => __( 'The schedule could not be saved.', 'sscribe-export-site-pages' ) ), 500 );
			return;
		}
		SScribe_Scheduler::ensure_scheduled();
		SScribe_AJAX_Guard::success(
			array(
				'schedule' => $this->present( $schedule ),
				'message'  => null === $existing
					? __( 'Schedule added.', 'sscribe-export-site-pages' )
					: __( 'Schedule updated.', 'sscribe-export-site-pages' ),
			)
		);
	}

	/**
	 * AJAX: delete a schedule. Archives it made are kept until they expire.
	 */
	public function ajax_delete(): void {
		if ( ! $this->verify_request_authorization( 'export_write' ) ) {
			return;
		}
		$id = $this->read_id();
		if ( '' === $id || ! ( new SScribe_Schedule_Store() )->delete( $id ) ) {
			SScribe_AJAX_Guard::error( array( 'message' => __( 'No schedule with that id.', 'sscribe-export-site-pages' ) ), 404 );
			return;
		}
		SScribe_AJAX_Guard::success( array( 'message' => __( 'Schedule deleted.', 'sscribe-export-site-pages' ) ) );
	}

	/**
	 * AJAX: enable or disable a schedule.
	 */
	public function ajax_toggle(): void {
		if ( ! $this->verify_request_authorization( 'export_write' ) ) {
			return;
		}
		$store    = new SScribe_Schedule_Store();
		$schedule = $store->get( $this->read_id() );
		if ( null === $schedule ) {
			SScribe_AJAX_Guard::error( array( 'message' => __( 'No schedule with that id.', 'sscribe-export-site-pages' ) ), 404 );
			return;
		}
		$enabled = SScribe_AJAX_Guard::post_boolean( 'enabled', ! $schedule->enabled );
		$changes = array( 'enabled' => $enabled );
		if ( $enabled ) {
			$changes['next_run_at'] = $schedule->compute_next_run( time(), wp_timezone()->getName() );
		}
		$updated = $schedule->with( $changes );
		if ( ! $store->save( $updated ) ) {
			SScribe_AJAX_Guard::error( array( 'message' => __( 'The schedule could not be saved.', 'sscribe-export-site-pages' ) ), 500 );
			return;
		}
		if ( $enabled ) {
			SScribe_Scheduler::ensure_scheduled();
		}
		SScribe_AJAX_Guard::success(
			array(
				'schedule' => $this->present( $updated ),
				'message'  => $enabled
					? __( 'Schedule enabled.', 'sscribe-export-site-pages' )
					: __( 'Schedule paused.', 'sscribe-export-site-pages' ),
			)
		);
	}

	/**
	 * AJAX: run a schedule now. Long exports continue through WP-Cron.
	 */
	public function ajax_run(): void {
		if ( ! $this->verify_request_authorization( 'export_write' ) ) {
			return;
		}
		$store = new SScribe_Schedule_Store();
		$id    = $this->read_id();
		if ( null === $store->get( $id ) ) {
			SScribe_AJAX_Guard::error( array( 'message' => __( 'No schedule with that id.', 'sscribe-export-site-pages' ) ), 404 );
			return;
		}
		$scheduler = null !== $this->scheduler_factory ? ( $this->scheduler_factory )( $store ) : new SScribe_Scheduler( $store );
		$outcome   = $scheduler->run( $id, true );
		$payload   = $outcome->payload();
		$refreshed = $store->get( $id );
		$data      = array(
			'ok'       => $outcome->is_success(),
			'code'     => $outcome->code(),
			'filename' => isset( $payload['filename'] ) && is_string( $payload['filename'] ) ? $payload['filename'] : '',
			'pages'    => isset( $payload['pages'] ) && is_numeric( $payload['pages'] ) ? (int) $payload['pages'] : 0,
			'message'  => isset( $payload['message'] ) && is_string( $payload['message'] ) ? $payload['message'] : '',
			'schedule' => null !== $refreshed ? $this->present( $refreshed ) : null,
		);
		if ( ! $outcome->is_success() ) {
			if ( '' === $data['message'] ) {
				$data['message'] = __( 'The export failed.', 'sscribe-export-site-pages' );
			}
			SScribe_AJAX_Guard::error( $data, 'lock_conflict' === $outcome->kind() ? 409 : 500 );
			return;
		}
		if ( '' === $data['message'] ) {
			$data['message'] = '' === $data['filename']
				? __( 'Nothing changed since the last run; no archive was made.', 'sscribe-export-site-pages' )
				: sprintf(
					/* translators: 1: ZIP filename, 2: Number of pages exported. */
					__( 'Export complete: %1$s (%2$d pages).', 'sscribe-export-site-pages' ),
					$data['filename'],
					$data['pages']
				);
		}
		SScribe_AJAX_Guard::success( $data );
	}

	/**
	 * Everything the tab renders from.
	 *
	 * @return array<string, mixed>
	 */
	public function list_payload(): array {
		$store     = new SScribe_Schedule_Store();
		$collector = new SScribe_Page_Collector();
		$registry  = array();
		foreach ( SScribe_Destination_Registry::all() as $id => $class_name ) {
			$registry[] = array(
				'id'     => $id,
				'label'  => $class_name::label(),
				'fields' => SScribe_Destination_Registry::schema( $id ),
			);
		}
		$post_types = array();
		foreach ( $collector->get_selectable_post_types() as $slug => $label ) {
			$post_types[] = array(
				'slug'  => (string) $slug,
				'label' => $label,
			);
		}
		$languages = array();
		if ( $collector->is_multilingual_active() ) {
			foreach ( $collector->get_languages() as $row ) {
				if ( isset( $row['code'] ) ) {
					$languages[] = array(
						'code' => (string) $row['code'],
						'name' => (string) ( $row['name'] ?? $row['code'] ),
					);
				}
			}
		}

		return array(
			'schedules' => array_values( array_map( array( $this, 'present' ), $store->all() ) ),
			'meta'      => array(
				'formats'          => array( 'docx', 'pdf', 'html', 'markdown' ),
				'frequencies'      => SScribe_Schedule::FREQUENCIES,
				'post_types'       => $post_types,
				'languages'        => $languages,
				'destinations'     => $registry,
				'max_schedules'    => SScribe_Schedule_Store::MAX_SCHEDULES,
				'max_destinations' => SScribe_Destination_Settings::MAX_DESTINATIONS,
				'has_room'         => $store->has_room(),
				'timezone'         => wp_timezone()->getName(),
				'mask'             => SScribe_Destination_Settings::MASK,
				'fields_modes'     => SScribe_Custom_Fields::labels(),
			),
		);
	}

	/**
	 * A schedule as the browser sees it: secrets masked, times formatted.
	 *
	 * @param SScribe_Schedule $schedule Schedule.
	 * @return array<string, mixed>
	 */
	public function present( SScribe_Schedule $schedule ): array {
		$row                 = $schedule->to_array();
		$row['destinations'] = SScribe_Destination_Settings::redact( $schedule->destinations );
		$row['owner_login']  = $this->login( $schedule->owner_user_id );
		$row['next_run']     = $this->site_time( $schedule->next_run_at );
		$row['last_run']     = $this->site_time( $schedule->last_run_at );
		$row['is_running']   = $schedule->is_running();
		unset( $row['running_session'] );
		return $row;
	}

	/**
	 * Build a schedule from the submitted form.
	 *
	 * @param array<string, mixed>  $input    Decoded form.
	 * @param SScribe_Schedule|null $existing Schedule being edited.
	 * @return SScribe_Schedule
	 * @throws SScribe_Validation_Exception When a field is invalid.
	 */
	public function schedule_from_input( array $input, ?SScribe_Schedule $existing ): SScribe_Schedule {
		$now      = time();
		$owner_id = null !== $existing ? $existing->owner_user_id : get_current_user_id();
		if ( $owner_id <= 0 || ! user_can( $owner_id, SScribe_Capabilities::get_required() ) ) {
			throw new SScribe_Validation_Exception( esc_html__( 'The owner is not allowed to export.', 'sscribe-export-site-pages' ), 'owner_user_id', 'capability' );
		}
		$formats = isset( $input['formats'] ) && is_array( $input['formats'] ) ? array_map( 'strval', $input['formats'] ) : array();
		$data    = array(
			'label'          => $input['label'] ?? '',
			'frequency'      => $input['frequency'] ?? '',
			'hour'           => $input['hour'] ?? 0,
			'weekday'        => $input['weekday'] ?? 1,
			'day_of_month'   => $input['day_of_month'] ?? 1,
			'formats'        => $formats,
			'post_type'      => $input['post_type'] ?? 'page',
			'post_status'    => $input['post_status'] ?? 'publish',
			'language'       => $input['language'] ?? '',
			'format_options' => $this->format_options_from_input( $input ),
			'incremental'    => ! empty( $input['incremental'] ),
			'retention_days' => $input['retention_days'] ?? 0,
			'notify'         => ! empty( $input['notify'] ),
			'owner_user_id'  => $owner_id,
			'enabled'        => array_key_exists( 'enabled', $input ) ? ! empty( $input['enabled'] ) : ( null === $existing || $existing->enabled ),
			'destinations'   => $this->destinations_from_input( $input['destinations'] ?? array(), $existing ),
		);
		if ( null !== $existing ) {
			$data += array(
				'id'              => $existing->id,
				'created_at'      => $existing->created_at,
				'last_run_at'     => $existing->last_run_at,
				'last_run_status' => $existing->last_run_status,
				'last_run_file'   => $existing->last_run_file,
				'last_error'      => $existing->last_error,
				'last_delivery'   => $existing->last_delivery,
			);
		} else {
			$data['created_at'] = $now;
		}
		$schedule = SScribe_Schedule::from_array( $data );
		return $schedule->with( array( 'next_run_at' => $schedule->compute_next_run( $now, wp_timezone()->getName() ) ) );
	}

	/**
	 * Format options the form exposes.
	 *
	 * @param array<string, mixed> $input Decoded form.
	 * @return array<string, string>
	 */
	private function format_options_from_input( array $input ): array {
		$options = array();
		if ( isset( $input['fields_mode'] ) ) {
			$options['sscribe_include_fields'] = SScribe_Custom_Fields::normalize_mode( $input['fields_mode'] );
		}
		if ( ! empty( $input['compliance'] ) ) {
			$options['sscribe_compliance_mode'] = '1';
		}
		if ( isset( $input['docx_template'] ) && is_string( $input['docx_template'] ) && '' !== $input['docx_template'] ) {
			$options['sscribe_docx_template'] = sanitize_key( $input['docx_template'] );
		}
		if ( isset( $input['md_preset'] ) && is_string( $input['md_preset'] ) && '' !== $input['md_preset'] ) {
			$options['sscribe_md_frontmatter_preset'] = SScribe_Markdown_Front_Matter::normalize_preset( $input['md_preset'] );
		}
		return $options;
	}

	/**
	 * Destinations from the form, keeping stored secrets the form left masked.
	 *
	 * @param mixed                 $raw      Submitted list.
	 * @param SScribe_Schedule|null $existing Schedule being edited.
	 * @return list<array{id: string, settings: array<string, mixed>}>
	 * @throws SScribe_Validation_Exception When the list is malformed.
	 */
	private function destinations_from_input( mixed $raw, ?SScribe_Schedule $existing ): array {
		if ( ! is_array( $raw ) ) {
			$raw = array();
		}
		$stored = array();
		if ( null !== $existing ) {
			foreach ( $existing->destinations as $index => $entry ) {
				$stored[ $index ] = $entry;
			}
		}
		$merged = array();
		foreach ( array_values( $raw ) as $index => $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$settings = is_array( $entry['settings'] ?? null ) ? $entry['settings'] : array();
			$previous = $stored[ $index ] ?? null;
			$entry_id = (string) ( $entry['id'] ?? '' );
			if ( is_array( $previous ) && $previous['id'] === $entry_id ) {
				foreach ( $settings as $name => $value ) {
					if ( SScribe_Destination_Settings::MASK === $value && isset( $previous['settings'][ $name ] ) ) {
						$settings[ $name ] = $previous['settings'][ $name ];
					}
				}
			} else {
				$settings = array_filter( $settings, static fn( $value ): bool => SScribe_Destination_Settings::MASK !== $value );
			}
			$merged[] = array(
				'id'       => $entry['id'] ?? '',
				'settings' => $settings,
			);
		}
		return SScribe_Destination_Settings::for_storage( $merged );
	}

	/**
	 * Nonce, capability and rate limit, in that order.
	 *
	 * @param string $bucket Rate-limit bucket.
	 * @return bool
	 */
	private function verify_request_authorization( string $bucket ): bool {
		if ( ! check_ajax_referer( 'sscribe_export_nonce', 'nonce', false ) ) {
			SScribe_AJAX_Guard::error( array( 'message' => __( 'Invalid security token.', 'sscribe-export-site-pages' ) ), 403 );
			return false;
		}
		if ( ! self::current_user_can_manage() ) {
			SScribe_AJAX_Guard::error( array( 'message' => __( 'Insufficient permissions.', 'sscribe-export-site-pages' ) ), 403 );
			return false;
		}
		$rate_check = ( new SScribe_Export_Rate_Limiter() )->check_rate_limit( self::CAPABILITY, $bucket );
		if ( false === $rate_check ) {
			SScribe_AJAX_Guard::error( array( 'message' => __( 'Too many requests. Please wait a moment.', 'sscribe-export-site-pages' ) ), 429 );
			return false;
		}
		return true;
	}

	/**
	 * Schedule id from the request.
	 *
	 * @return string
	 */
	private function read_id(): string {
		return sanitize_key( SScribe_AJAX_Guard::post_text( 'id', '', 40 ) );
	}

	/**
	 * Decode a JSON object field from the request.
	 *
	 * @param string $key Request key.
	 * @return array<string, mixed>|null
	 */
	private function read_json( string $key ): ?array {
		$raw = SScribe_AJAX_Guard::post_text( $key, '', self::MAX_JSON_BYTES );
		if ( '' === $raw ) {
			return null;
		}
		$decoded = json_decode( $raw, true, 6 );
		if ( ! is_array( $decoded ) ) {
			return null;
		}
		return map_deep(
			$decoded,
			static fn( $value ) => is_string( $value ) ? sanitize_text_field( $value ) : $value
		);
	}

	/**
	 * A timestamp in the site's timezone, or empty.
	 *
	 * @param int $timestamp Unix time.
	 * @return string
	 */
	private function site_time( int $timestamp ): string {
		if ( $timestamp <= 0 ) {
			return '';
		}
		return wp_date(
			sanitize_text_field( (string) get_option( 'date_format', 'Y-m-d' ) ) . ' ' . sanitize_text_field( (string) get_option( 'time_format', 'H:i' ) ),
			$timestamp
		);
	}

	/**
	 * Login of a user id, or empty.
	 *
	 * @param int $user_id User id.
	 * @return string
	 */
	private function login( int $user_id ): string {
		$user = $user_id > 0 ? get_userdata( $user_id ) : false;
		return is_object( $user ) && isset( $user->user_login ) && is_string( $user->user_login ) ? $user->user_login : '';
	}
}
