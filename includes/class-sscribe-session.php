<?php
/**
 * SScribe Session.
 *
 * @package SScribe_Export_Site_Pages
 * @license GPL v2 or later
 * @link    https://www.gnu.org/licenses/gpl-2.0.html
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once SSCRIBE_PLUGIN_DIR . 'includes/traits/trait-sscribe-batch-session-helpers.php';
require_once SSCRIBE_PLUGIN_DIR . 'includes/traits/trait-sscribe-session-ajax.php';

/**
 * Session management.
 *
 * Owns the data layer (CRUD, encryption, migration, active
 * detection) and, via the SScribe_Session_AJAX trait, the three
 * AJAX endpoints that mutate session state (check active, cancel,
 * clear).
 *
 * @package SScribe_Export_Site_Pages
 * @subpackage Session
 */
class SScribe_Session {

	use SScribe_Session_AJAX;

	private const SESSION_CLEANUP_BATCH = 100;

	private const SESSION_ID_LENGTH = 16;

	/**
	 * Seconds a session may sit untouched and still be treated as resumable.
	 */
	private const ACTIVE_SESSION_MAX_IDLE = 900;

	/**
	 * Test hook: bypass encryption when storing session data.
	 *
	 * @var bool
	 */
	private static bool $test_mode = false;

	/**
	 * Cache group used for per-user active-session-id lookups so the
	 * hot path can avoid a wp_options table hit on every AJAX poll.
	 *
	 * The persistent layer remains the `sscribe_active_sid_<uid>`
	 * transient (which survives across requests and is consulted by
	 * `get_active_session_data()` on cache misses). The object cache
	 * is a per-request short-circuit; expiry matches the transient.
	 *
	 * @var string
	 */
	private const ACTIVE_SID_CACHE_GROUP = 'sscribe_active_sid';

	/**
	 * Read the per-user active-session transient, short-circuiting via
	 * the object cache when present.
	 *
	 * @param int $user_id User ID.
	 * @return mixed|false Transient value (string sid or '0' marker), or
	 *                      false if not cached.
	 */
	private function get_active_sid_transient( int $user_id ) {
		$cache_key  = 'sscribe_active_sid_' . $user_id;
		$obj_key    = $cache_key;
		$obj_cached = wp_cache_get( $obj_key, self::ACTIVE_SID_CACHE_GROUP );
		if ( false !== $obj_cached ) {
			return $obj_cached;
		}

		$value = get_transient( $cache_key );
		wp_cache_set( $obj_key, $value, self::ACTIVE_SID_CACHE_GROUP, MINUTE_IN_SECONDS );
		return $value;
	}

	/**
	 * Write-through for the per-user active-session transient.
	 *
	 * Populates both layers (transient + object cache) so that the
	 * next read in the same request short-circuits via the object
	 * cache and doesn't re-touch wp_options.
	 *
	 * @param int    $user_id    User ID.
	 * @param string $session_id Session id (or '0' marker).
	 * @param int    $expiration Expiration in seconds.
	 * @return void
	 */
	private function set_active_sid_transient( int $user_id, string $session_id, int $expiration ): void {
		$cache_key = 'sscribe_active_sid_' . $user_id;
		set_transient( $cache_key, $session_id, $expiration );
		wp_cache_set( $cache_key, $session_id, self::ACTIVE_SID_CACHE_GROUP, min( $expiration, MINUTE_IN_SECONDS ) );
	}

	/**
	 * Invalidate both cache layers for the per-user active-session
	 * marker.
	 *
	 * @param int $user_id User ID.
	 * @return void
	 */
	private function delete_active_sid_transient( int $user_id ): void {
		$cache_key = 'sscribe_active_sid_' . $user_id;
		wp_cache_delete( $cache_key, self::ACTIVE_SID_CACHE_GROUP );
		delete_transient( $cache_key );
	}

	/**
	 * Cache group for the LIKE-scanned session-options index.
	 *
	 * The LIKE scan in `get_active_session_data()` walks every
	 * session option in `wp_options` to restore the user's active
	 * session when the per-user transient is cold. Caching the scan
	 * result for one minute via the object cache absorbs repeated
	 * polls from the same user (or batch admin page loads).
	 *
	 * @var string
	 */
	private const SESSION_INDEX_CACHE_GROUP = 'sscribe_session_index';

	/**
	 * Invalidate the LIKE-scan session index in both object cache and
	 * transient layers. Called whenever a session option is created,
	 * updated, or deleted.
	 *
	 * @return void
	 */
	private function invalidate_session_index(): void {
		wp_cache_delete( 'sscribe_session_options_index', self::SESSION_INDEX_CACHE_GROUP );
		delete_transient( 'sscribe_session_options_index' );
	}

	/**
	 * Run the LIKE scan over session options, cached in object cache
	 * for the lifetime of the request (and beyond when a persistent
	 * object cache is present).
	 *
	 * @return array<int, object> List of option rows (option_name, option_value).
	 */
	private function load_session_options_index(): array {
		$cached = wp_cache_get( 'sscribe_session_options_index', self::SESSION_INDEX_CACHE_GROUP );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;

		$pattern = $wpdb->esc_like( self::OPTION_PREFIX ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Scanned index is itself the cache for this hot read path.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
				$pattern
			)
		);

		$rows = is_array( $rows ) ? array_values(
			array_filter(
				$rows,
				static fn( object $row ): bool => null !== self::extract_session_id( (string) ( $row->option_name ?? '' ) )
			)
		) : array();
		wp_cache_set( 'sscribe_session_options_index', $rows, self::SESSION_INDEX_CACHE_GROUP, MINUTE_IN_SECONDS );
		return $rows;
	}

	/**
	 * Logger instance.
	 *
	 * @var SScribe_Logger_Interface
	 */
	private readonly SScribe_Logger_Interface $logger;

	/**
	 * Rate limiter (lazy : only resolved when an AJAX endpoint
	 * in the SScribe_Session_AJAX trait actually fires).
	 *
	 * @var SScribe_Export_Rate_Limiter|null
	 */
	private ?SScribe_Export_Rate_Limiter $rate_limiter = null;

	/**
	 * Export auditor (lazy : used by validate_session_ownership).
	 *
	 * @var SScribe_Export_Auditor|null
	 */
	private ?SScribe_Export_Auditor $auditor = null;

	/**
	 * ZIP handler (lazy : used by cleanup_cancelled_export).
	 *
	 * @var SScribe_Zip_Handler|null
	 */
	private ?SScribe_Zip_Handler $zip_handler = null;

	/**
	 * Lock manager (lazy : used by ajax_cancel_export's race
	 * fix and cleanup_user_locks).
	 *
	 * @var SScribe_Export_Lock_Manager|null
	 */
	private ?SScribe_Export_Lock_Manager $lock_manager = null;

	public const OPTION_PREFIX = 'sscribe_session_';

	/**
	 * Initialize the session handler.
	 */
	public function __construct() {
		$this->logger = SScribe_Logger::instance( SScribe_Logger::is_logging_enabled() );
	}

	/**
	 * Enable test mode: bypass AES-256-CBC encryption when storing session data.
	 *
	 * This allows unit tests to directly manipulate session options as plain JSON
	 * and have get() decode them correctly without decryption.
	 */
	public static function enable_test_mode(): void {
		self::$test_mode = true;
	}

	/**
	 * Disable test mode: restore AES-256-CBC encryption for production use.
	 */
	public static function disable_test_mode(): void {
		self::$test_mode = false;
	}

	/**
	 * Test-only: reset all session state : clears the static active-session cache,
	 * the test transients global, and all session options.
	 *
	 * Call this in setUp() to ensure a completely clean slate when tests share
	 * process state with SScribe_Session_Test (which does not call delete() in tearDown).
	 */
	public static function test_reset(): void {
		self::$test_mode           = false;

		if ( isset( $GLOBALS['sscribe_test_transients'] ) ) {
			$GLOBALS['sscribe_test_transients'] = array();
		}

		if ( isset( $GLOBALS['sscribe_test_options'] ) && is_array( $GLOBALS['sscribe_test_options'] ) ) {
			foreach ( $GLOBALS['sscribe_test_options'] as $key => $value ) {
				if ( null !== self::extract_session_id( (string) $key ) ) {
					unset( $GLOBALS['sscribe_test_options'][ $key ] );
				}
			}
		}
	}

	/**
	 * Build the option name for a session ID.
	 *
	 * @param string $session_id Session identifier.
	 * @return string
	 */
	private function get_option_name( string $session_id ): string {
		return self::OPTION_PREFIX . $session_id;
	}

	/**
	 * Extract a valid session ID from a plugin option name.
	 *
	 * @param string $option_name Option name to inspect.
	 * @return string|null The 16-character hexadecimal session ID.
	 */
	public static function extract_session_id( string $option_name ): ?string {
		if ( ! str_starts_with( $option_name, self::OPTION_PREFIX ) ) {
			return null;
		}

		$session_id = substr( $option_name, strlen( self::OPTION_PREFIX ) );
		return 1 === preg_match( '/^[a-f0-9]{16}$/D', $session_id ) ? $session_id : null;
	}

	/**
	 * Create a new session.
	 *
	 * @param array $data Session data.
	 * @return string Session ID or empty string on failure.
	 */
	public function create( array $data ): string {
		$max_retries = 5;
		$attempt     = 0;

		while ( $attempt < $max_retries ) {
			$session_id = sanitize_key( bin2hex( random_bytes( 8 ) ) );
			$session_id = strtolower( $session_id );

			$data['created_at'] = time();
			$data['session_id'] = $session_id;
			$data['_sig']       = $this->sign_session_id( $session_id );
			$data['updated_at'] = time();

			$encoded_data = wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

			if ( false === $encoded_data ) {
				$this->logger->error(
					'Failed to JSON-encode session data',
					array( 'session_id' => $session_id )
				);
				return '';
			}

			if ( isset( $data['user_id'] ) ) {
				$transient_key = 'sscribe_active_sid_' . $data['user_id'];
				$existing      = get_transient( $transient_key );
				if ( false !== $existing && '0' !== $existing && is_string( $existing ) ) {
					$existing_data = $this->get( $existing );
					if ( $existing_data && $this->is_active_session_data( $existing_data ) ) {
						$this->logger->warning(
							'Blocked duplicate session creation : user has active session',
							array(
								'user_id'          => $data['user_id'],
								'existing_session' => $existing,
								'blocked_attempt'  => $session_id,
							)
						);
						return '';
					}

					delete_transient( $transient_key );
				}
			}

			$encrypted_data = self::$test_mode ? $encoded_data : $this->encrypt_session_data( $encoded_data );
			$result = add_option( self::OPTION_PREFIX . $session_id, $encrypted_data, '', 'no' );
			if ( $result ) {
				$this->invalidate_session_index();
			}

			if ( $result ) {

				if ( isset( $data['user_id'] ) ) {

					$active_sid_user_id = (int) $data['user_id'];
					$this->set_active_sid_transient( $active_sid_user_id, $session_id, DAY_IN_SECONDS );

					if ( $this->get_active_sid_transient( $active_sid_user_id ) !== $session_id ) {
						$this->logger->error(
							'Active-session transient verification failed : rolling back',
							array(
								'user_id'    => $data['user_id'],
								'session_id' => $session_id,
							)
						);
						delete_option( self::OPTION_PREFIX . $session_id );
						++$attempt;
						continue;
					}
				}

				break;
			}

			$this->logger->warning(
				'Session ID collision detected, retrying',
				array(
					'session_id' => $session_id,
					'attempt'    => $attempt + 1,
					'max'        => $max_retries,
				)
			);
			++$attempt;

			if ( $attempt >= $max_retries ) {
				$this->logger->error(
					'Failed to create session after max retries (collision)',
					array(
						'attempts' => $max_retries,
						'data'     => array(
							'user_id' => $data['user_id'] ?? null,
							'action'  => $data['action'] ?? 'unknown',
						),
					)
				);
				return '';
			}
		}

		return $session_id;
	}

	/**
	 * Get session data by ID.
	 *
	 * @param string $session_id Session identifier.
	 * @return array|null Session data or null if not found.
	 */
	public function get( string $session_id ): ?array {
		$session_id = sanitize_key( $session_id );

		if ( empty( $session_id ) || self::SESSION_ID_LENGTH !== strlen( $session_id ) ) {
			$this->logger->debug(
				'Session lookup failed: invalid session_id length',
				array(
					'session_id'   => $session_id,
					'expected_len' => self::SESSION_ID_LENGTH,
					'actual_len'   => strlen( $session_id ),
				)
			);
			return null;
		}

		$option_name = $this->get_option_name( $session_id );

		wp_cache_delete( $option_name, 'options' );

		$raw = get_option( self::OPTION_PREFIX . $session_id );

		if ( false === $raw ) {
			$this->logger->debug(
				'Session not found in database',
				array(
					'session_id'  => $session_id,
					'option_name' => $option_name,
				)
			);
			return null;
		}

		if ( is_array( $raw ) ) {
			$data = $raw;
		} elseif ( is_string( $raw ) ) {

			$decrypted = $this->decrypt_session_data( $raw );

			if ( null !== $decrypted && false !== $decrypted ) {

				$data = json_decode( $decrypted, true );
				if ( ! is_array( $data ) ) {
					$this->logger->warning(
						'Decrypted session data is not valid JSON',
						array(
							'session_id' => $session_id,
							'json_error' => json_last_error_msg(),
						)
					);
					return null;
				}
			} else {

				$data = json_decode( $raw, true );

				if ( ! is_array( $data ) ) {
					$json_error = json_last_error_msg();
					$this->logger->warning(
						'JSON decode failed for session, attempting legacy migration',
						array(
							'session_id'  => $session_id,
							'json_error'  => $json_error,
							'raw_len'     => strlen( $raw ),
						)
					);
					$data = $this->migrate_legacy_session( $session_id, $raw );
				}
			}
		} else {
			$this->logger->warning(
				'Session option has unexpected type',
				array(
					'session_id' => $session_id,
					'raw_type'   => gettype( $raw ),
				)
			);
			return null;
		}

		if ( ! is_array( $data ) ) {
			return null;
		}

		if ( ! array_key_exists( '_sig', $data ) ) {
			$this->logger->notice(
				'Legacy session loaded without signature',
				array( 'session_id' => $session_id )
			);
			return null;
		}

		if ( ! is_string( $data['_sig'] ) || ! $this->verify_session_signature( $session_id, $data['_sig'] ) ) {
			$this->logger->warning(
				'Session signature verification failed',
				array( 'session_id' => $session_id )
			);
			return null;
		}

		return $data;
	}

	/**
	 * Update session data.
	 *
	 * @param string $session_id Session identifier.
	 * @param array  $data       Data to merge.
	 * @return bool
	 */
	public function update( string $session_id, array $data ): bool {
		$session_id = sanitize_key( $session_id );

		if ( empty( $session_id ) || self::SESSION_ID_LENGTH !== strlen( $session_id ) ) {
			return false;
		}

		$option_name = $this->get_option_name( $session_id );
		$lock_name   = 'update-' . $session_id;
		$lock_token  = $this->get_lock_manager()->acquire_lock( $lock_name, 10, 9 );

		if ( null === $lock_token ) {
			$this->logger->error(
				'Failed to acquire session lock (concurrent access)',
				array( 'session_id' => $session_id )
			);
			return false;
		}

		try {
			wp_cache_delete( $option_name, 'options' );

			$existing = $this->get( $session_id );

			if ( null === $existing ) {

				$this->logger->debug(
					'Session no longer exists during update : may have been cancelled',
					array( 'session_id' => $session_id )
				);
				return false;
			}

			$current_user_id = get_current_user_id();
			if ( $current_user_id > 0 && isset( $existing['user_id'] ) && (int) $existing['user_id'] !== $current_user_id ) {
				$this->logger->warning(
					'Session update denied: user ID mismatch',
					array(
						'session_id'    => $session_id,
						'session_owner' => $existing['user_id'],
						'current_user'  => $current_user_id,
					)
				);
				return false;
			}

			$merged   = $existing;
			$max_keys = array( 'processed', 'success', 'failed' );
			foreach ( $data as $key => $value ) {
				if ( isset( $existing[ $key ] ) && is_array( $existing[ $key ] ) && is_array( $value ) ) {
					$append_keys = array( 'structured_errors', 'page_log', 'error_categories' );
					if ( in_array( $key, $append_keys, true ) ) {
						$merged[ $key ] = array_slice(
							array_merge( $existing[ $key ], $value ),
							-500
						);
					} else {
						$merged[ $key ] = $value;
					}
				} elseif ( in_array( $key, $max_keys, true ) && is_int( $value ) && isset( $existing[ $key ] ) && is_int( $existing[ $key ] ) ) {
					$merged[ $key ] = max( $existing[ $key ], $value );
				} else {
					$merged[ $key ] = $value;
				}
			}
			$merged['_sig']       = $this->sign_session_id( $session_id );
			$merged['updated_at'] = time();

			$encoded_data = wp_json_encode( $merged, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

			if ( false === $encoded_data ) {
				$this->logger->error(
					'Failed to JSON-encode session update',
					array( 'session_id' => $session_id )
				);
				return false;
			}

			for ( $attempt = 1; $attempt <= 2; ++$attempt ) {
				$encrypted_data = $this->encrypt_session_data( $encoded_data );
				if ( update_option( self::OPTION_PREFIX . $session_id, $encrypted_data, false ) ) {
					$this->invalidate_session_index();

					if ( isset( $merged['user_id'] ) && isset( $merged['status'] ) && in_array( $merged['status'], array( 'complete', 'failed', 'cancelled' ), true ) ) {
						$this->delete_active_sid_transient( (int) $merged['user_id'] );
					} elseif ( isset( $merged['user_id'] ) ) {

						$this->set_active_sid_transient( (int) $merged['user_id'], $session_id, DAY_IN_SECONDS );
					}
					return true;
				}

				wp_cache_delete( $option_name, 'options' );

				$this->logger->warning(
					'Failed to update session option, retrying',
					array(
						'session_id' => $session_id,
						'attempt'    => $attempt,
						'max'        => 2,
					)
				);
			}

			$this->logger->error(
				'Session update failed after max retries : session may be in inconsistent state',
				array(
					'session_id'  => $session_id,
					'option_name' => $option_name,
				)
			);
			return false;

		} finally {
			$this->get_lock_manager()->release_lock( $lock_name, $lock_token );
		}
	}

	/**
	 * Delete a session.
	 *
	 * @param string $session_id Session identifier.
	 * @return bool
	 */
	public function delete( string $session_id ): bool {
		$session_id = sanitize_key( $session_id );

		if ( empty( $session_id ) || self::SESSION_ID_LENGTH !== strlen( $session_id ) ) {
			return false;
		}

		$option_name = $this->get_option_name( $session_id );

		$data = $this->get( $session_id );
		if ( is_array( $data ) && isset( $data['user_id'] ) ) {
			$this->delete_active_sid_transient( (int) $data['user_id'] );
		}

		$this->delete_page_ids( $session_id );
		$deleted = delete_option( self::OPTION_PREFIX . $session_id );
		if ( $deleted ) {
			$this->invalidate_session_index();
		}
		return $deleted;
	}

	/**
	 * Store page_ids in a separate transient to avoid bloating the session
	 * autoload with large page ID arrays.
	 *
	 * For arrays over the large-array threshold (500 IDs) we route the data
	 * through the object cache with a dedicated group instead of wp_options.
	 * The object cache stores keys in memory (Redis/Memcached) or in a
	 * dedicated site-options-style bucket where a 100k-ID payload is cheap
	 * instead of bloating wp_options. On hosts without persistent object
	 * cache we fall back to the transient path so behavior is preserved.
	 *
	 * @param string $session_id Session identifier.
	 * @param array  $page_ids   Array of page IDs.
	 * @return bool True on success.
	 */
	public function set_page_ids( string $session_id, array $page_ids ): bool {
		$session_id = sanitize_key( $session_id );
		if ( empty( $session_id ) ) {
			return false;
		}
		$transient_key = 'sscribe_page_ids_' . $session_id;
		$count         = count( $page_ids );
		$large_array   = $count > (int) apply_filters( 'sscribe_page_ids_large_threshold', 500 );
		if ( $large_array && wp_using_ext_object_cache() ) {
			return (bool) wp_cache_set(
				$transient_key,
				$page_ids,
				'sscribe_page_ids',
				72 * HOUR_IN_SECONDS
			);
		}
		return set_transient( $transient_key, $page_ids, 72 * HOUR_IN_SECONDS );
	}

	/**
	 * Retrieve page_ids from the separate transient.
	 *
	 * @param string $session_id Session identifier.
	 * @return array Empty array if not found.
	 */
	public function get_page_ids( string $session_id ): array {
		$session_id = sanitize_key( $session_id );
		if ( empty( $session_id ) ) {
			return array();
		}
		$transient_key = 'sscribe_page_ids_' . $session_id;
		if ( wp_using_ext_object_cache() ) {
			$cached = wp_cache_get( $transient_key, 'sscribe_page_ids' );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}
		$result = get_transient( $transient_key );
		return is_array( $result ) ? $result : array();
	}

	/**
	 * Delete the page_ids transient.
	 *
	 * @param string $session_id Session identifier.
	 * @return bool True on success.
	 */
	public function delete_page_ids( string $session_id ): bool {
		$session_id = sanitize_key( $session_id );
		if ( empty( $session_id ) ) {
			return false;
		}
		$transient_key = 'sscribe_page_ids_' . $session_id;
		if ( wp_using_ext_object_cache() ) {
			wp_cache_delete( $transient_key, 'sscribe_page_ids' );
		}
		return delete_transient( $transient_key );
	}

	/**
	 * Validate session data integrity.
	 *
	 * @param string $session_id Session identifier.
	 * @return bool
	 */
	public function validate( string $session_id ): bool {
		$data = $this->get( $session_id );

		if ( ! is_array( $data ) ) {
			return false;
		}

		$required = array(
			'total'      => 'is_numeric',
			'processed'  => 'is_numeric',
			'session_id' => 'is_string',
		);

		foreach ( $required as $key => $check ) {
			if ( ! isset( $data[ $key ] ) || ! $check( $data[ $key ] ) ) {
				$this->logger->error( "Session validation failed for key: {$key}", array( 'session_id' => $session_id ) );
				return false;
			}
		}

		$page_ids = $this->get_page_ids( $session_id );
		if ( empty( $page_ids ) ) {
			$page_ids = $data['page_ids'] ?? array();
		}
		if ( empty( $page_ids ) ) {
			$this->logger->error( 'Session validation failed: page_ids not found', array( 'session_id' => $session_id ) );
			return false;
		}

		$data['total']     = (int) $data['total'];
		$data['processed'] = (int) $data['processed'];

		if ( isset( $data['user_id'] ) && ! is_numeric( $data['user_id'] ) ) {
			$this->logger->error( 'Session user_id is not numeric', array( 'user_id' => $data['user_id'] ) );
			return false;
		}
		if ( isset( $data['user_id'] ) ) {
			$data['user_id'] = (int) $data['user_id'];
		}

		if ( $data['total'] < 0 || $data['processed'] < 0 ) {
			return false;
		}

		if ( ! hash_equals( (string) $data['session_id'], (string) $session_id ) ) {
			$this->logger->error(
				'Session ID mismatch',
				array(
					'expected' => $session_id,
					'actual'   => $data['session_id'],
				)
			);
			return false;
		}

		if ( $data['total'] > 100000 ) {
			$this->logger->error( 'Session total exceeds maximum limit', array( 'total' => $data['total'] ) );
			return false;
		}

		if ( $data['processed'] > $data['total'] + 10 ) {
			$this->logger->error(
				'Session processed significantly exceeds total (possible corruption)',
				array(
					'processed' => $data['processed'],
					'total'     => $data['total'],
				)
			);
			return false;
		}

		return true;
	}

	/**
	 * Clean up expired sessions.
	 *
	 * @param int $max_age_seconds Maximum age in seconds.
	 * @return int Number of deleted sessions.
	 */
	public function cleanup_expired( int $max_age_seconds = 14400 ): int {
		global $wpdb;

		$pattern     = $wpdb->esc_like( self::OPTION_PREFIX ) . '%';
		$now         = time();
		$start_time  = microtime( true );
		$max_seconds = 30;

		$deleted = 0;
		$cursor  = '';

		do {

			if ( ( microtime( true ) - $start_time ) > $max_seconds ) {
				break;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cleanup operation scans session options in bounded batches; caching not applicable.
			$options = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name > %s ORDER BY option_name ASC LIMIT %d",
					$pattern,
					$cursor,
					self::SESSION_CLEANUP_BATCH
				)
			);

			foreach ( $options as $option ) {
				$cursor     = (string) $option->option_name;
				$session_id = self::extract_session_id( $cursor );
				if ( null === $session_id ) {
					continue;
				}
				$data       = $this->decode_session_value( $option->option_value, $session_id );

				if ( ! is_array( $data ) ) {
					if ( is_string( $option->option_value ) && str_starts_with( $option->option_value, 'a:' ) ) {
						if ( delete_option( self::OPTION_PREFIX . $session_id ) ) {
							$this->delete_page_ids( $session_id );
							$this->get_lock_manager()->discard_lock( $session_id );
							++$deleted;
						}
					}
					continue;
				}

				$created_at    = (int) ( $data['created_at'] ?? 0 );
				$updated_at    = (int) ( $data['updated_at'] ?? $created_at );
				$last_activity = max( $created_at, $updated_at );

				if ( $last_activity <= 0 || $last_activity > $now ) {
					$last_activity = 0;
				}

				$status = $data['status'] ?? '';
				$age    = $now - $last_activity;
				if ( 'finalizing' === $status && $age < HOUR_IN_SECONDS ) {
					continue;
				}

				if ( isset( $data['created_at'] ) && $last_activity > 0 && ( $now - $last_activity ) > $max_age_seconds ) {
					if ( delete_option( self::OPTION_PREFIX . $session_id ) ) {
						$this->delete_page_ids( $session_id );
						$this->get_lock_manager()->discard_lock( $session_id );
						if ( isset( $data['user_id'] ) ) {
							$this->delete_active_sid_transient( (int) $data['user_id'] );
						}
						++$deleted;
					}
				} elseif ( ! isset( $data['created_at'] ) && delete_option( self::OPTION_PREFIX . $session_id ) ) {
					$this->delete_page_ids( $session_id );
					$this->get_lock_manager()->discard_lock( $session_id );
					++$deleted;
				}
			}
		} while ( ! empty( $options ) );

		if ( $deleted > 0 ) {
			$this->invalidate_session_index();
		}

		return $deleted;
	}

	/**
	 * Clear all sessions for a user.
	 *
	 * @param int $user_id User ID.
	 * @return int Number of deleted sessions.
	 */
	public function clear_user_sessions( int $user_id ): int {
		if ( $user_id <= 0 ) {
			return 0;
		}

		global $wpdb;

		$pattern = $wpdb->esc_like( self::OPTION_PREFIX ) . '%';
		$deleted = 0;
		$cursor  = '';
		$limit   = 500;

		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded privacy cleanup scan over plugin-owned options.
			$options = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name > %s ORDER BY option_name ASC LIMIT %d",
					$pattern,
					$cursor,
					$limit
				)
			) ?? array();

			$option_count = count( $options );
			foreach ( $options as $option ) {
				$cursor     = (string) $option->option_name;
				$session_id = self::extract_session_id( $cursor );
				if ( null === $session_id ) {
					continue;
				}
				$data = $this->decode_session_value( $option->option_value ?? '', $session_id );
				if ( is_array( $data ) && isset( $data['user_id'] ) && (int) $data['user_id'] === $user_id ) {
					if ( delete_option( self::OPTION_PREFIX . $session_id ) ) {
						$this->delete_page_ids( $session_id );
						$this->get_lock_manager()->discard_lock( $session_id );
						++$deleted;
					}
				}
			}
		} while ( $option_count === $limit );

		$this->delete_active_sid_transient( $user_id );
		if ( $deleted > 0 ) {
			$this->invalidate_session_index();
		}

		return $deleted;
	}

	/**
	 * Get sessions for a user with cursor-based, memory-bounded pagination.
	 *
	 * @param int $user_id User ID.
	 * @param int $limit   Maximum matches to return; zero returns all matches.
	 * @param int $offset  Number of matching sessions to skip.
	 * @return array Array of session data arrays.
	 */
	public function get_sessions_for_user( int $user_id, int $limit = 0, int $offset = 0 ): array {
		if ( $user_id <= 0 ) {
			return array();
		}
		$limit  = max( 0, min( 500, $limit ) );
		$offset = max( 0, $offset );

		if ( self::$test_mode && ! empty( $GLOBALS['sscribe_test_options'] ) ) {
			$test_sessions = array();
			foreach ( $GLOBALS['sscribe_test_options'] as $option_name => $option_value ) {
				$session_id = self::extract_session_id( (string) $option_name );
				if ( null === $session_id ) {
					continue;
				}
				$data       = $this->decode_session_value( $option_value, $session_id );
				if ( ! is_array( $data ) ) {
					continue;
				}
				if ( isset( $data['user_id'] ) && (int) $data['user_id'] === $user_id ) {
					$test_sessions[] = $data;
				}
			}

			if ( ! empty( $test_sessions ) ) {
				usort(
					$test_sessions,
					static function ( array $left, array $right ): int {
						return (int) ( $left['updated_at'] ?? 0 ) <=> (int) ( $right['updated_at'] ?? 0 );
					}
				);
				return 0 === $limit ? $test_sessions : array_slice( $test_sessions, $offset, $limit );
			}
		}

		global $wpdb;

		$pattern = $wpdb->esc_like( self::OPTION_PREFIX ) . '%';

		$sessions = array();
		$cursor   = '';
		$batch_limit = 500;
		$matches     = 0;
		$complete    = false;

		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Privacy export needs a complete scan of session options.
			$options = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name > %s ORDER BY option_name ASC LIMIT %d",
					$pattern,
					$cursor,
					$batch_limit
				)
			) ?? array();

			$option_count = count( $options );
			foreach ( $options as $option ) {
				$cursor     = (string) $option->option_name;
				$session_id = self::extract_session_id( $cursor );
				if ( null === $session_id ) {
					continue;
				}
				$data       = $this->decode_session_value( $option->option_value ?? '', $session_id );

				if ( ! is_array( $data ) ) {
					continue;
				}

				if ( isset( $data['user_id'] ) && (int) $data['user_id'] === $user_id ) {
					if ( $matches >= $offset ) {
						$sessions[] = $data;
						if ( $limit > 0 && count( $sessions ) >= $limit ) {
							$complete = true;
							break;
						}
					}
					++$matches;
				}
			}
		} while ( ! $complete && $option_count === $batch_limit );

		if ( 0 === $limit ) {
			usort(
				$sessions,
				static function ( array $left, array $right ): int {
					return (int) ( $left['updated_at'] ?? 0 ) <=> (int) ( $right['updated_at'] ?? 0 );
				}
			);
		}

		return $sessions;
	}

	/**
	 * Delete all sessions for a user (alias).
	 *
	 * @param int $user_id User ID.
	 * @return int Number of deleted sessions.
	 */
	public function delete_sessions_for_user( int $user_id ): int {
		return $this->clear_user_sessions( $user_id );
	}

	/**
	 * Get active session data for a user (if any).
	 *
	 * @param int $user_id User ID.
	 * @return array|null Session data array or null if no active session.
	 */
	public function get_active_session_data( int $user_id ): ?array {
		$cache_key  = 'sscribe_active_sid_' . $user_id;
		$cached_sid = $this->get_active_sid_transient( $user_id );

		if ( false !== $cached_sid && is_string( $cached_sid ) && '0' !== $cached_sid ) {
			$saved_sid = $cached_sid;
			$data      = $this->get( $saved_sid );

			if ( is_array( $data ) && $this->is_active_session_data( $data ) ) {
				$data['option_name'] = $this->get_option_name( $saved_sid );
				return $data;
			}

			$this->delete_active_sid_transient( $user_id );
		}

		$options = $this->load_session_options_index();

		foreach ( $options as $option ) {
			$session_id = self::extract_session_id( (string) $option->option_name );
			if ( null === $session_id ) {
				continue;
			}
			$data       = $this->decode_session_value( $option->option_value ?? '', $session_id );

			if ( ! is_array( $data ) ) {
				continue;
			}

			if ( isset( $data['user_id'] ) && (int) $data['user_id'] === $user_id ) {
				if ( $this->is_active_session_data( $data ) ) {
					$sid = $data['session_id'] ?? '';
					if ( '' !== $sid ) {
						$this->set_active_sid_transient( $user_id, $sid, 5 );
					}
					$data['option_name'] = $option->option_name;
					return $data;
				}
			}
		}

		$this->set_active_sid_transient( $user_id, '0', 5 );

		return null;
	}

	/**
	 * Determine whether decoded session data still represents active work.
	 *
	 * @param array $data Session data.
	 * @return bool True if the session is active.
	 */
	private function is_active_session_data( array $data ): bool {
		$status = $data['status'] ?? '';

		if ( ! in_array( $status, array( 'processing', 'pending', 'finalizing', 'completing' ), true ) ) {
			return false;
		}

		$processed = (int) ( $data['processed'] ?? 0 );
		$total     = (int) ( $data['total'] ?? 0 );

		if ( $processed >= $total || ! empty( $data['cancelled'] ) ) {
			return false;
		}

		// A session that has not been touched recently is a crashed run,
		// not resumable work. Restoring it would lock the admin UI behind
		// a session no request will ever advance again.
		$last_touched = (int) ( $data['updated_at'] ?? $data['created_at'] ?? 0 );
		return $last_touched > 0 && ( time() - $last_touched ) < self::ACTIVE_SESSION_MAX_IDLE;
	}

	/**
	 * Decode a session value from JSON.
	 *
	 * @param mixed       $raw       Raw option value.
	 * @param string|null $session_id Session ID (optional, needed for legacy migration).
	 * @return array|null Decoded data or null.
	 */
	public function decode_session_value( mixed $raw, ?string $session_id = null ): ?array {
		if ( ! is_string( $raw ) ) {
			return null;
		}

		$decrypted = $this->decrypt_session_data( $raw );

		if ( null !== $decrypted && false !== $decrypted ) {

			$data = json_decode( $decrypted, true );
			if ( is_array( $data ) ) {
				if ( null !== $session_id && ! $this->verify_decoded_signature( $session_id, $data ) ) {
					return null;
				}
				return $data;
			}
			return null;
		}

		$data = json_decode( $raw, true );

		if ( is_array( $data ) ) {
			if ( null !== $session_id && ! $this->verify_decoded_signature( $session_id, $data ) ) {
				return null;
			}
			return $data;
		}

		if ( null !== $session_id && preg_match( '/^a:\d+:\{/', $raw ) ) {
			$migrated = $this->migrate_legacy_session( $session_id, $raw );
			if ( null !== $migrated ) {
				return $migrated;
			}
		}

		$this->logger->warning(
			'Ignoring non-JSON session data during bulk session scan',
			array( 'json_error' => json_last_error_msg() )
		);

		return null;
	}

	/**
	 * Check that a decoded session payload carries a valid HMAC signature.
	 *
	 * @param string $session_id Session identifier.
	 * @param array  $data       Decoded session payload.
	 * @return bool True when the signature is present and matches.
	 */
	private function verify_decoded_signature( string $session_id, array $data ): bool {
		if ( ! array_key_exists( '_sig', $data ) || ! is_string( $data['_sig'] ) ) {
			return false;
		}
		return $this->verify_session_signature( $session_id, $data['_sig'] );
	}

	/**
	 * Migrate a single legacy serialized session.
	 *
	 * @param string $session_id Session identifier.
	 * @param string $raw        Raw serialized data.
	 * @return array|null Migrated data or null.
	 */
	private function migrate_legacy_session( string $session_id, string $raw ): ?array {
		if ( ! preg_match( '/^a:\d+:\{/', $raw ) ) {
			return null;
		}

		$data = unserialize( $raw, array( 'allowed_classes' => false ) );

		if ( ! is_array( $data ) ) {
			return null;
		}

		$data['_sig'] = $this->sign_session_id( $session_id );

		$encoded_data = wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		if ( false === $encoded_data ) {
			$this->logger->warning(
				'Failed to JSON-encode legacy session during migration',
				array( 'session_id' => $session_id )
			);
			return null;
		}

		$encrypted_data = $this->encrypt_session_data( $encoded_data );

		if ( ! update_option( self::OPTION_PREFIX . $session_id, $encrypted_data, false ) ) {
			$this->logger->warning(
				'Failed to persist migrated legacy session',
				array( 'session_id' => $session_id )
			);
			return null;
		}
		$this->invalidate_session_index();

		$this->logger->debug(
			'Migrated legacy serialized session to encrypted JSON storage',
			array( 'session_id' => $session_id )
		);

		return $data;
	}

	/**
	 * Sign a session ID with HMAC.
	 *
	 * @param string $session_id Session identifier.
	 * @return string
	 */
	private function sign_session_id( string $session_id ): string {
		return hash_hmac( 'sha256', $session_id, $this->get_signing_key() );
	}

	/**
	 * Verify a session signature.
	 *
	 * @param string $session_id Session identifier.
	 * @param string $signature  HMAC signature.
	 * @return bool
	 */
	private function verify_session_signature( string $session_id, string $signature ): bool {
		$current = $this->get_signing_key();
		if ( hash_equals( hash_hmac( 'sha256', $session_id, $current ), $signature ) ) {
			return true;
		}
		$previous = (string) get_option( 'sscribe_session_signing_key_prev', '' );
		return '' !== $previous && hash_equals( hash_hmac( 'sha256', $session_id, $previous ), $signature );
	}

	/**
	 * Rotate the HMAC signing key.
	 *
	 * The current key is preserved in the `sscribe_session_signing_key_prev`
	 * option so existing sessions remain verifiable while clients adopt the
	 * new key. The previous slot is reused on subsequent rotations, so the
	 * ring is always exactly two entries deep.
	 *
	 * @return bool True on success, false if a new key could not be generated.
	 */
	public function rotate_signing_key(): bool {
		$current = $this->get_signing_key();

		try {
			$candidate = bin2hex( random_bytes( 32 ) );
		} catch ( \Throwable $e ) {
			$this->logger->error(
				'Failed to generate SScribe session signing key during rotation',
				array( 'error' => $e->getMessage() )
			);
			return false;
		}

		update_option( 'sscribe_session_signing_key_prev', $current, false );
		update_option( 'sscribe_session_signing_key', $candidate, false );
		update_option( 'sscribe_session_signing_key_prev_rotated_at', time(), false );

		$this->logger->info( 'Rotated SScribe session signing key' );

		return true;
	}

	/**
	 * Run by the hourly cleanup cron to rotate the signing key no more
	 * than once every 30 days. Honors a `sscribe_session_rotation_days`
	 * filter for sites that want a faster cadence.
	 */
	public function maybe_rotate_signing_key(): void {
		$interval_days = (int) apply_filters( 'sscribe_session_rotation_days', 30 );
		if ( $interval_days < 1 ) {
			return;
		}

		$last_rotated = (int) get_option( 'sscribe_session_signing_key_prev_rotated_at', 0 );
		if ( $last_rotated > 0 && ( time() - $last_rotated ) < ( $interval_days * DAY_IN_SECONDS ) ) {
			return;
		}

		$this->rotate_signing_key();
	}

	/**
	 * Get the HMAC signing key.
	 *
	 * @return string
	 * @throws \RuntimeException When a fallback key cannot be persisted.
	 */
	private function get_signing_key(): string {
		$stored = (string) get_option( 'sscribe_session_signing_key', '' );
		if ( '' !== $stored ) {
			return $stored;
		}

		if ( defined( 'AUTH_SALT' ) && '' !== AUTH_SALT ) {
			if ( $this->bootstrap_signing_key( AUTH_SALT ) ) {
				return AUTH_SALT;
			}
		} elseif ( defined( 'SECURE_AUTH_KEY' ) && '' !== SECURE_AUTH_KEY ) {
			if ( $this->bootstrap_signing_key( SECURE_AUTH_KEY ) ) {
				return SECURE_AUTH_KEY;
			}
		} elseif ( defined( 'NONCE_SALT' ) && '' !== NONCE_SALT ) {
			if ( $this->bootstrap_signing_key( NONCE_SALT ) ) {
				return NONCE_SALT;
			}
		}

		try {
			$candidate = bin2hex( random_bytes( 32 ) );
		} catch ( \Throwable $e ) {
			throw new \RuntimeException( 'Unable to generate SScribe session signing key.' );
		}
		if ( add_option( 'sscribe_session_signing_key', $candidate, '', 'no' ) ) {
			return $candidate;
		}

		$secret = (string) get_option( 'sscribe_session_signing_key', '' );
		if ( '' === $secret ) {
			throw new \RuntimeException( 'Unable to persist the SScribe session signing key.' );
		}

		return $secret;
	}

	/**
	 * Persist a bootstrap signing key (typically a WordPress salt) so
	 * subsequent reads prefer the stable stored option and survive
	 * AUTH_SALT rotation. Returns true on success.
	 *
	 * @param string $candidate Bootstrap key material.
	 * @return bool
	 */
	private function bootstrap_signing_key( string $candidate ): bool {
		if ( '' === $candidate ) {
			return false;
		}
		if ( add_option( 'sscribe_session_signing_key', $candidate, '', 'no' ) ) {
			return true;
		}
		return '' !== (string) get_option( 'sscribe_session_signing_key', '' );
	}

	/**
	 * Encrypt session data using authenticated encryption.
	 *
	 * New writes go through {@see sodium_encrypt_session_data()}; the
	 * result is prefixed with the magic tag `s1:` so the decryptor can
	 * pick the right algorithm. If sodium is unavailable, AES-256-GCM
	 * writes use the `o1:` prefix. Legacy AES-CBC rows (no prefix) remain
	 * readable and are upgraded on their next write.
	 *
	 * @param string $data JSON-encoded session data.
	 * @return string Authenticated encrypted data.
	 * @throws \RuntimeException If neither encryption provider succeeds.
	 */
	private function encrypt_session_data( string $data ): string {
		try {
			return $this->sodium_encrypt_session_data( $data );
		} catch ( \Throwable $e ) {
			$this->logger->error(
				'Sodium session encryption failed; falling back to AES-256-GCM',
				array(
					'exception' => get_class( $e ),
					'message'   => $e->getMessage(),
				)
			);
		}

		return $this->openssl_gcm_encrypt_session_data( $data );
	}

	/**
	 * Decrypt session data according to its version prefix.
	 *
	 * The dispatch is prefix-based:
	 *  - `s1:` → sodium authenticated secretbox.
	 *  - (no prefix, base64 with 16+ byte payload) → legacy AES-256-CBC.
	 *  - (anything else) → null; the caller falls back to plain JSON.
	 *
	 * @param string $encrypted_data Stored session row.
	 * @return string|null Decrypted JSON, or null if no path succeeds.
	 */
	private function decrypt_session_data( string $encrypted_data ): ?string {

		if ( 0 === strpos( $encrypted_data, 's1:' ) ) {
			return $this->sodium_decrypt_session_data( $encrypted_data );
		}
		if ( 0 === strpos( $encrypted_data, 'o1:' ) ) {
			return $this->openssl_gcm_decrypt_session_data( $encrypted_data );
		}

		return $this->legacy_aes_decrypt_session_data( $encrypted_data );
	}

	/**
	 * Encrypt session data using libsodium's secretbox (XSalsa20-Poly1305).
	 *
	 * Output format: `"s1:" . base64url( nonce || ciphertext )` where
	 * the nonce is 24 bytes (the secretbox nonce size) and the
	 * ciphertext is 16 bytes longer than the plaintext (Poly1305 MAC).
	 *
	 * @param string $data JSON-encoded session data.
	 * @return string Prefixed, base64url-encoded sodium ciphertext.
	 * @throws \RuntimeException If sodium is unavailable or encryption fails.
	 */
	private function sodium_encrypt_session_data( string $data ): string {
		if ( ! function_exists( 'sodium_crypto_secretbox' ) ) {
			throw new \RuntimeException( 'libsodium (sodium_crypto_secretbox) is not available' );
		}

		$key   = $this->get_sodium_key();
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

		$ciphertext = sodium_crypto_secretbox( $data, $nonce, $key );

		return 's1:' . rtrim( strtr( base64_encode( $nonce . $ciphertext ), '+/', '-_' ), '=' );
	}

	/**
	 * Decrypt a sodium-prefixed session row.
	 *
	 * @param string $encrypted_data String starting with `s1:`.
	 * @return string|null Decrypted JSON, or null on any failure.
	 */
	private function sodium_decrypt_session_data( string $encrypted_data ): ?string {
		if ( ! function_exists( 'sodium_crypto_secretbox_open' ) ) {
			return null;
		}

		$b64 = substr( $encrypted_data, 3 );
		$raw = base64_decode( strtr( $b64, '-_', '+/' ), true );
		if ( false === $raw ) {
			return null;
		}

		$nonce_len = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
		if ( strlen( $raw ) < $nonce_len + 1 ) {
			return null;
		}

		$nonce     = substr( $raw, 0, $nonce_len );
		$cipher    = substr( $raw, $nonce_len );
		$plaintext = sodium_crypto_secretbox_open( $cipher, $nonce, $this->get_sodium_key() );
		if ( false === $plaintext ) {
			return null;
		}

		return $plaintext;
	}

	/**
	 * Get or create the 32-byte sodium secretbox key.
	 *
	 * The key is stored as a base64-encoded option (`autoload = no`,
	 * so it is not loaded on every WordPress request). A fresh key
	 * is generated with `sodium_crypto_secretbox_keygen()` on first
	 * use. Rotating the key invalidates all existing sodium-encrypted
	 * sessions : do that only as a deliberate recovery action.
	 *
	 * @return string 32 raw bytes.
	 * @throws \RuntimeException If libsodium is unavailable.
	 */
	private function get_sodium_key(): string {
		$stored = (string) get_option( 'sscribe_session_sodium_key', '' );
		if ( '' !== $stored ) {
			$key = base64_decode( $stored, true );
			if ( false !== $key && SODIUM_CRYPTO_SECRETBOX_KEYBYTES === strlen( $key ) ) {
				return $key;
			}
		}

		if ( ! function_exists( 'sodium_crypto_secretbox_keygen' ) ) {
			throw new \RuntimeException( 'libsodium (sodium_crypto_secretbox_keygen) is not available' );
		}

		$key     = sodium_crypto_secretbox_keygen();
		$encoded = base64_encode( $key );
		if ( add_option( 'sscribe_session_sodium_key', $encoded, '', 'no' ) ) {
			return $key;
		}

		// A concurrent first write won the add_option() race. Re-read and
		// use that key instead of returning an unpersisted key that no
		// future request could use to decrypt this session.
		$stored = (string) get_option( 'sscribe_session_sodium_key', '' );
		$key    = base64_decode( $stored, true );
		if ( false === $key || SODIUM_CRYPTO_SECRETBOX_KEYBYTES !== strlen( $key ) ) {
			throw new \RuntimeException( 'Unable to persist the SScribe session encryption key.' );
		}

		return $key;
	}

	/**
	 * Encrypt session data with authenticated AES-256-GCM.
	 *
	 * Output format: `o1:` followed by base64url(nonce || tag || ciphertext).
	 *
	 * @param string $data JSON-encoded session data.
	 * @return string Prefixed authenticated ciphertext.
	 * @throws \RuntimeException If OpenSSL GCM is unavailable or encryption fails.
	 */
	private function openssl_gcm_encrypt_session_data( string $data ): string {
		if ( ! function_exists( 'openssl_encrypt' ) || ! in_array( 'aes-256-gcm', openssl_get_cipher_methods(), true ) ) {
			throw new \RuntimeException( 'OpenSSL AES-256-GCM is not available.' );
		}

		$nonce      = random_bytes( 12 );
		$tag        = '';
		$ciphertext = openssl_encrypt(
			$data,
			'aes-256-gcm',
			$this->get_legacy_aes_key(),
			OPENSSL_RAW_DATA,
			$nonce,
			$tag,
			'',
			16
		);
		if ( false === $ciphertext || 16 !== strlen( $tag ) ) {
			throw new \RuntimeException( 'OpenSSL AES-256-GCM encryption failed.' );
		}

		return 'o1:' . rtrim( strtr( base64_encode( $nonce . $tag . $ciphertext ), '+/', '-_' ), '=' );
	}

	/**
	 * Decrypt an authenticated AES-256-GCM session row.
	 *
	 * @param string $encrypted_data String starting with `o1:`.
	 * @return string|null Decrypted JSON, or null when validation fails.
	 */
	private function openssl_gcm_decrypt_session_data( string $encrypted_data ): ?string {
		if ( ! function_exists( 'openssl_decrypt' ) ) {
			return null;
		}

		$encoded = substr( $encrypted_data, 3 );
		$padding = ( 4 - ( strlen( $encoded ) % 4 ) ) % 4;
		$raw     = base64_decode( strtr( $encoded, '-_', '+/' ) . str_repeat( '=', $padding ), true );
		if ( false === $raw || strlen( $raw ) < 29 ) {
			return null;
		}

		$nonce      = substr( $raw, 0, 12 );
		$tag        = substr( $raw, 12, 16 );
		$ciphertext = substr( $raw, 28 );
		$plaintext  = openssl_decrypt(
			$ciphertext,
			'aes-256-gcm',
			$this->get_legacy_aes_key(),
			OPENSSL_RAW_DATA,
			$nonce,
			$tag
		);

		return false === $plaintext ? null : $plaintext;
	}

	/**
	 * Legacy AES-256-CBC decryption.
	 *
	 * @param string $encrypted_data Base64-encoded encrypted data with IV prepended.
	 * @return string|null Decrypted JSON data, or null if decryption fails.
	 */
	private function legacy_aes_decrypt_session_data( string $encrypted_data ): ?string {
		if ( ! function_exists( 'openssl_decrypt' ) ) {
			return null;
		}

		$raw = base64_decode( $encrypted_data, true );

		if ( false === $raw || strlen( $raw ) < 16 ) {
			return null;
		}

		$iv     = substr( $raw, 0, 16 );
		$cipher = substr( $raw, 16 );
		$key    = $this->get_legacy_aes_key();

		$decrypted = openssl_decrypt( $cipher, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );

		if ( false === $decrypted ) {
			return null;
		}

		return $decrypted;
	}

	/**
	 * Derive the legacy AES-256 key from the signing key using SHA-256.
	 *
	 * @return string 32-byte encryption key.
	 */
	private function get_legacy_aes_key(): string {
		$stored = (string) get_option( 'sscribe_session_aes_key', '' );
		if ( '' !== $stored ) {
			$raw = base64_decode( $stored, true );
			if ( false !== $raw && 32 === strlen( $raw ) ) {
				return $raw;
			}
		}

		$derived = hash( 'sha256', $this->get_signing_key(), true );
		update_option( 'sscribe_session_aes_key', base64_encode( $derived ), false );

		return $derived;
	}

	/**
	 * Get the session storage type identifier.
	 *
	 * @return string
	 */
	public function get_storage_type(): string {
		return 'encrypted-json';
	}

	/**
	 * Get the logger (required by SScribe_Session_AJAX trait).
	 *
	 * @return SScribe_Logger_Interface
	 */
	protected function get_logger(): SScribe_Logger_Interface {
		return $this->logger;
	}

	/**
	 * Get the rate limiter (required by SScribe_Session_AJAX trait).
	 *
	 * @return SScribe_Export_Rate_Limiter
	 */
	protected function get_rate_limiter(): SScribe_Export_Rate_Limiter {
		return $this->rate_limiter ??= new SScribe_Export_Rate_Limiter();
	}

	/**
	 * Get the export auditor (required by SScribe_Session_AJAX trait).
	 *
	 * @return SScribe_Export_Auditor
	 */
	protected function get_auditor(): SScribe_Export_Auditor {
		return $this->auditor ??= new SScribe_Export_Auditor();
	}

	/**
	 * Get the ZIP handler (required by SScribe_Session_AJAX trait).
	 *
	 * @return SScribe_Zip_Handler
	 */
	protected function get_zip_handler(): SScribe_Zip_Handler {
		return $this->zip_handler ??= new SScribe_Zip_Handler();
	}

	/**
	 * Get the lock manager (required by SScribe_Session_AJAX trait).
	 *
	 * @return SScribe_Export_Lock_Manager
	 */
	protected function get_lock_manager(): SScribe_Export_Lock_Manager {
		return $this->lock_manager ??= new SScribe_Export_Lock_Manager( $this->logger );
	}
}
