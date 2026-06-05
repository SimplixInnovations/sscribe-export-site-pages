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

/**
 * Session management.
 *
 * @package SScribe_Export_Site_Pages
 * @subpackage Session
 */
class SScribe_Session {

	private const SESSION_CLEANUP_BATCH = 100;

	private const SESSION_ID_LENGTH = 16;

	/**
	 * Cache of active sessions by user ID.
	 *
	 * @var array<int, bool>
	 */
	private static array $active_session_cache = array();

	/**
	 * Test hook: bypass encryption when storing session data.
	 *
	 * @var bool
	 */
	private static bool $test_mode = false;

	/**
	 * Logger instance.
	 *
	 * @var SScribe_Logger_Interface
	 */
	private readonly SScribe_Logger_Interface $logger;

	public const OPTION_PREFIX = 'sscribe_session_';

	/**
	 * Initialize the session handler.
	 *
	 * @param string $option_prefix Option name prefix.
	 */
	public function __construct(
		private readonly string $option_prefix = self::OPTION_PREFIX
	) {
		$this->logger = SScribe_Logger::instance( SScribe_Logger::is_logging_enabled(), 'sscribe_session' );
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
	 * Test-only: reset all session state — clears the static active-session cache,
	 * the test transients global, and all session options.
	 *
	 * Call this in setUp() to ensure a completely clean slate when tests share
	 * process state with SScribe_Session_Test (which does not call delete() in tearDown).
	 */
	public static function test_reset(): void {
		self::$active_session_cache = array();
		self::$test_mode           = false;

		// Clear test transients global (used by get_transient/set_transient mocks).
		if ( isset( $GLOBALS['sscribe_test_transients'] ) ) {
			$GLOBALS['sscribe_test_transients'] = array();
		}

		// Delete all session options from the test store.
		if ( isset( $GLOBALS['sscribe_test_options'] ) && is_array( $GLOBALS['sscribe_test_options'] ) ) {
			foreach ( $GLOBALS['sscribe_test_options'] as $key => $value ) {
				if ( str_starts_with( $key, self::OPTION_PREFIX ) ) {
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
		return $this->option_prefix . $session_id;
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

			$option_name  = $this->get_option_name( $session_id );
			$encoded_data = wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

			if ( false === $encoded_data ) {
				$this->logger->error(
					'Failed to JSON-encode session data',
					array( 'session_id' => $session_id )
				);
				return '';
			}

			// Atomic duplicate-session guard: check user's active-session transient
			// BEFORE writing the new option. This closes the TOCTOU window between
			// has_active_session() (called earlier) and actual option creation here.
			if ( isset( $data['user_id'] ) ) {
				$transient_key = 'sscribe_active_sid_' . $data['user_id'];
				$existing      = get_transient( $transient_key );
				if ( false !== $existing && '0' !== $existing && is_string( $existing ) ) {
					$existing_data = $this->get( $existing );
					if ( $existing_data && $this->is_active_session_data( $existing_data ) ) {
						$this->logger->warning(
							'Blocked duplicate session creation — user has active session',
							array(
								'user_id'          => $data['user_id'],
								'existing_session' => $existing,
								'blocked_attempt'  => $session_id,
							)
						);
						return '';
					}
					// Stale transient pointing to expired session — clear it.
					delete_transient( $transient_key );
				}
			}

			$encrypted_data = self::$test_mode ? $encoded_data : $this->encrypt_session_data( $encoded_data );
			$result = add_option( $option_name, $encrypted_data, '', 'no' );

			if ( $result ) {

				if ( isset( $data['user_id'] ) ) {
					set_transient( 'sscribe_active_sid_' . $data['user_id'], $session_id, 300 );
					// Verify the transient was actually stored. If not, the session
					// would be invisible to has_active_session(), allowing concurrent
					// exports to start. Delete the orphaned option and retry.
					if ( get_transient( 'sscribe_active_sid_' . $data['user_id'] ) !== $session_id ) {
						$this->logger->error(
							'Active-session transient verification failed — rolling back',
							array(
								'user_id'    => $data['user_id'],
								'session_id' => $session_id,
							)
						);
						delete_option( $option_name );
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

		$raw = get_option( $option_name );

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
			// Attempt decryption first — null/false return means decryption failed.
			$decrypted = $this->decrypt_session_data( $raw );

			if ( null !== $decrypted && false !== $decrypted ) {
				// Decryption succeeded — JSON decode the result.
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
				// Decryption failed — try plain JSON decode (legacy unencrypted or test fixtures).
				$data = json_decode( $raw, true );

				if ( ! is_array( $data ) ) {
					$json_error = json_last_error_msg();
					$this->logger->warning(
						'JSON decode failed for session, attempting legacy migration',
						array(
							'session_id'  => $session_id,
							'json_error'  => $json_error,
							'raw_len'     => strlen( $raw ),
							'raw_preview' => substr( $raw, 0, 100 ),
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
		$lock_key    = 'sscribe_update_lock_' . $session_id;

		$lock_acquired = false;
		$lock_ttl      = 10;
		$using_cache   = wp_using_ext_object_cache();

		// Use exponential back-off: 50ms, 100ms, 200ms, 400ms, 600ms, 800ms (~2.65s max total).
		$base_delay   = 50000; // 50ms in microseconds
		$max_attempts = 6;

		for ( $lock_attempt = 1; $lock_attempt <= $max_attempts; ++$lock_attempt ) {
			if ( $using_cache ) {
				if ( wp_cache_add( $lock_key, time(), 'transient', $lock_ttl ) ) {
					$lock_acquired = true;
					break;
				}
			} elseif ( set_transient( $lock_key, time(), $lock_ttl ) ) {
				$lock_acquired = true;
				break;
			}

			// Exponential back-off with jitter: base_delay * 2^(attempt-1) + random jitter.
			$delay  = $base_delay * ( 2 ** ( $lock_attempt - 1 ) );
			$jitter = wp_rand( 0, (int) ( $delay * 0.1 ) ); // 10% jitter.
			usleep( $delay + $jitter );
		}

		if ( ! $lock_acquired ) {
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
				// This is expected during cancellation - not an error condition.
				$this->logger->debug(
					'Session no longer exists during update — may have been cancelled',
					array( 'session_id' => $session_id )
				);
				return false;
			}

			// Validate that the current user owns this session to prevent
			// unauthorized session modification by other admin users.
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
				if ( update_option( $option_name, $encrypted_data, false ) ) {
					// Only delete the active-session transient when the session reaches a
					// terminal state (complete/failed/cancelled) to avoid breaking status polling.
					// Intermediate updates (processed count, progress) must preserve the transient.
					if ( isset( $merged['user_id'] ) && isset( $merged['status'] ) && in_array( $merged['status'], array( 'complete', 'failed', 'cancelled' ), true ) ) {
						unset( self::$active_session_cache[ (int) $merged['user_id'] ] );
						delete_transient( 'sscribe_active_sid_' . (int) $merged['user_id'] );
					} elseif ( isset( $merged['user_id'] ) ) {
						// Refresh the active-session transient TTL so that polling clients
						// don't cause unnecessary DB fallback queries when the user has an
						// active export in progress (M-03 fix). Only refresh when user_id
						// is present; anonymous sessions are not tracked via this transient.
						set_transient( 'sscribe_active_sid_' . (int) $merged['user_id'], $session_id, 300 );
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
				'Session update failed after max retries — session may be in inconsistent state',
				array(
					'session_id'  => $session_id,
					'option_name' => $option_name,
				)
			);
			return false;

		} finally {

			if ( $using_cache ) {
				wp_cache_delete( $lock_key, 'transient' );
			} else {
				delete_transient( $lock_key );
			}
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

		if ( empty( $session_id ) ) {
			return false;
		}

		$option_name = $this->get_option_name( $session_id );

		$data = $this->get( $session_id );
		if ( is_array( $data ) && isset( $data['user_id'] ) ) {
			delete_transient( 'sscribe_active_sid_' . $data['user_id'] );
			unset( self::$active_session_cache[ (int) $data['user_id'] ] );
		}

		$this->delete_page_ids( $session_id );
		return delete_option( $option_name );
	}

	/**
	 * Store page_ids in a separate transient to avoid bloating the session
	 * autoload with large page ID arrays.
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

		// page_ids may be stored in a separate transient OR embedded inline in the
		// session data. Check the transient first, then fall back to session data.
		$page_ids = $this->get_page_ids( $session_id );
		if ( empty( $page_ids ) ) {
			$page_ids = $data['page_ids'] ?? array();
		}
		if ( empty( $page_ids ) ) {
			$this->logger->error( 'Session validation failed: page_ids not found', array( 'session_id' => $session_id ) );
			return false;
		}

		// Normalize types locally. Note: this does NOT persist back to storage.
		// Callers that need typed values should cast on read, or call update()
		// after validate() if persistence is required.
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

		$pattern     = $wpdb->esc_like( $this->option_prefix ) . '%';
		$now         = time();
		$start_time  = microtime( true );
		$max_seconds = 30; // Safety limit to prevent cron timeout.

		$deleted = 0;
		$cursor  = '';

		do {
			// Enforce time limit — resume on next cron run if needed.
			if ( ( microtime( true ) - $start_time ) > $max_seconds ) {
				break;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cleanup operation scans session options in bounded batches; caching not applicable.
			$options = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s AND autoload = 'no' AND option_name > %s ORDER BY option_name ASC LIMIT %d",
					$pattern,
					$cursor,
					self::SESSION_CLEANUP_BATCH
				)
			);

			foreach ( $options as $option ) {
				$session_id = str_replace( $this->option_prefix, '', $option->option_name );
				$data       = $this->decode_session_value( $option->option_value, $session_id );

				if ( ! is_array( $data ) ) {
					if ( is_string( $option->option_value ) && str_starts_with( $option->option_value, 'a:' ) ) {
						delete_option( $option->option_name );
						++$deleted;
					}
					$cursor = $option->option_name;
					continue;
				}

				$last_activity = isset( $data['updated_at'] )
					? max( (int) $data['created_at'], (int) $data['updated_at'] )
					: (int) $data['created_at'];

				// Guard against corrupted session data with zero/negative timestamps.
				if ( $last_activity <= 0 || $last_activity > $now ) {
					$last_activity = 0;
				}

				$status = $data['status'] ?? '';
				$age    = $now - $last_activity;
				if ( 'finalizing' === $status && $age < HOUR_IN_SECONDS ) {
					$cursor = $option->option_name;
					continue;
				}

				if ( isset( $data['created_at'] ) && $last_activity > 0 && ( $now - $last_activity ) > $max_age_seconds ) {
					if ( delete_option( $option->option_name ) ) {
						++$deleted;
					}
				} elseif ( ! isset( $data['created_at'] ) ) {
					delete_option( $option->option_name );
					++$deleted;
				}

				$cursor = $option->option_name;
			}
		} while ( ! empty( $options ) );

		return $deleted;
	}

	/**
	 * Clear all sessions for a user.
	 *
	 * @param int $user_id User ID.
	 * @return int Number of deleted sessions.
	 */
	public function clear_user_sessions( int $user_id ): int {
		global $wpdb;

		$pattern = $wpdb->esc_like( $this->option_prefix ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cleanup operation scans all session options; caching not applicable for cleanup.
		$options = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s AND autoload = 'no'",
				$pattern
			)
		);

		$deleted = 0;

		foreach ( $options as $option ) {
			$session_id = str_replace( $this->option_prefix, '', $option->option_name );
			$data       = $this->decode_session_value( $option->option_value, $session_id );

			if ( ! is_array( $data ) ) {
				continue;
			}

			if ( isset( $data['user_id'] ) && (int) $data['user_id'] === $user_id ) {
				if ( delete_option( $option->option_name ) ) {
					++$deleted;
				}
			}
		}

		delete_transient( 'sscribe_active_sid_' . $user_id );
		unset( self::$active_session_cache[ $user_id ] );

		return $deleted;
	}

	/**
	 * Get all sessions for a user with cursor-based pagination to avoid loading
	 * all sessions into memory at once (lazy-load pattern). Each batch fetches up
	 * to 500 sessions and iterates until all matching sessions are retrieved.
	 *
	 * @param int $user_id User ID.
	 * @return array Array of session data arrays.
	 */
	public function get_sessions_for_user( int $user_id ): array {
		if ( $user_id <= 0 ) {
			return array();
		}

		// In test mode, also scan the test-options mock so that sessions created
		// via create() (which writes to $GLOBALS) are visible to this method.
		if ( self::$test_mode && ! empty( $GLOBALS['sscribe_test_options'] ) ) {
			$test_sessions = array();
			foreach ( $GLOBALS['sscribe_test_options'] as $option_name => $option_value ) {
				if ( ! str_starts_with( $option_name, $this->option_prefix ) ) {
					continue;
				}
				$session_id = str_replace( $this->option_prefix, '', $option_name );
				$data       = $this->decode_session_value( $option_value, $session_id );
				if ( ! is_array( $data ) ) {
					continue;
				}
				if ( isset( $data['user_id'] ) && (int) $data['user_id'] === $user_id ) {
					$test_sessions[] = $data;
				}
			}
			// If only test data exists (no real DB rows), return directly.
			if ( ! empty( $test_sessions ) ) {
				usort(
					$test_sessions,
					static function ( array $left, array $right ): int {
						return (int) ( $left['updated_at'] ?? 0 ) <=> (int) ( $right['updated_at'] ?? 0 );
					}
				);
				return $test_sessions;
			}
		}

		global $wpdb;

		$pattern = $wpdb->esc_like( $this->option_prefix ) . '%';

		$sessions = array();
		$cursor   = '';
		$limit    = 500;

		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Privacy export needs a complete scan of session options.
			$options = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s AND autoload = 'no' AND option_name > %s ORDER BY option_name ASC LIMIT %d",
					$pattern,
					$cursor,
					$limit
				)
			);

			$option_count = count( $options );
			foreach ( $options as $option ) {
				$session_id = str_replace( $this->option_prefix, '', $option->option_name );
				$data       = $this->decode_session_value( $option->option_value ?? '', $session_id );

				if ( ! is_array( $data ) ) {
					continue;
				}

				if ( isset( $data['user_id'] ) && (int) $data['user_id'] === $user_id ) {
					$sessions[] = $data;
				}
				$cursor = $option->option_name;
			}
		} while ( $option_count === $limit );

		usort(
			$sessions,
			static function ( array $left, array $right ): int {
				return (int) ( $left['updated_at'] ?? 0 ) <=> (int) ( $right['updated_at'] ?? 0 );
			}
		);

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
		global $wpdb;

		$cache_key  = 'sscribe_active_sid_' . $user_id;
		$cached_sid = get_transient( $cache_key );

		if ( false !== $cached_sid && is_string( $cached_sid ) && '0' !== $cached_sid ) {
			$saved_sid = $cached_sid;
			$data      = $this->get( $saved_sid );

			if ( is_array( $data ) && $this->is_active_session_data( $data ) ) {
				$data['option_name'] = $this->get_option_name( $saved_sid );
				return $data;
			}

			delete_transient( $cache_key );
		}

		$pattern = $wpdb->esc_like( $this->option_prefix ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Lightweight check for active session restoration.
		$options = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s AND autoload = 'no'",
				$pattern
			)
		);

		foreach ( $options as $option ) {
			$session_id = str_replace( $this->option_prefix, '', $option->option_name );
			$data       = $this->decode_session_value( $option->option_value ?? '', $session_id );

			if ( ! is_array( $data ) ) {
				continue;
			}

			if ( isset( $data['user_id'] ) && (int) $data['user_id'] === $user_id ) {
				if ( $this->is_active_session_data( $data ) ) {
					$sid = $data['session_id'] ?? '';
					if ( '' !== $sid ) {
						set_transient( $cache_key, $sid, 5 );
					}
					$data['option_name'] = $option->option_name;
					return $data;
				}
			}
		}

		set_transient( $cache_key, '0', 5 );

		return null;
	}

	/**
	 * Check if user has active session and return its data.
	 *
	 * @param int $user_id User ID.
	 * @return bool True if user has an active session.
	 */
	public function has_active_session( int $user_id ): bool {
		if ( self::$test_mode ) {
			return $this->has_active_session_uncached( $user_id );
		}

		$cache_key = 'sscribe_active_sid_' . $user_id;
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			if ( '0' === $cached ) {
				self::$active_session_cache[ $user_id ] = false;
				return false;
			}

			$cached_session = is_string( $cached ) ? $this->get( $cached ) : null;
			if ( is_array( $cached_session ) && $this->is_active_session_data( $cached_session ) ) {
				self::$active_session_cache[ $user_id ] = true;
				return true;
			}

			delete_transient( $cache_key );
			self::$active_session_cache[ $user_id ] = false;
		}

		return $this->has_active_session_uncached( $user_id );
	}

	/**
	 * Uncached active-session check — always queries storage directly.
	 * Used in test mode to avoid stale-cache blocking legitimate session creation.
	 *
	 * @param int $user_id User ID.
	 * @return bool True if the user has an active session.
	 */
	private function has_active_session_uncached( int $user_id ): bool {
		$transient_key = 'sscribe_active_sid_' . $user_id;
		$existing     = get_transient( $transient_key );

		if ( false === $existing || '0' === $existing ) {
			return false;
		}

		if ( ! is_string( $existing ) ) {
			return false;
		}

		$data = $this->get( $existing );

		if ( ! is_array( $data ) ) {
			delete_transient( $transient_key );
			return false;
		}

		if ( ! $this->is_active_session_data( $data ) ) {
			delete_transient( $transient_key );
			return false;
		}

		return true;
	}

	/**
	 * Determine whether decoded session data still represents active work.
	 *
	 * @param array $data Session data.
	 * @return bool True if the session is active.
	 */
	private function is_active_session_data( array $data ): bool {
		$status = $data['status'] ?? '';
		// 'completing' is added because finalize_export() sets status to 'completing'
		// before writing the ZIP. If the process crashes here, the session is stuck
		// in 'completing' but should still be treated as active to prevent a new export.
		if ( ! in_array( $status, array( 'processing', 'pending', 'finalizing', 'completing' ), true ) ) {
			return false;
		}

		$processed = (int) ( $data['processed'] ?? 0 );
		$total     = (int) ( $data['total'] ?? 0 );

		return $processed < $total && empty( $data['cancelled'] );
	}

	/**
	 * Migrate all legacy serialized sessions to JSON.
	 *
	 * @return int Number of migrated sessions.
	 */
	public function migrate_all_legacy_sessions(): int {
		global $wpdb;

		$pattern = $wpdb->esc_like( $this->option_prefix ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time migration scan across session options.
		$options = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s AND autoload = 'no'",
				$pattern
			)
		);

		$migrated = 0;

		foreach ( $options as $option ) {
			if ( ! is_string( $option->option_value ) ) {
				continue;
			}

			$data = json_decode( $option->option_value, true );

			if ( is_array( $data ) ) {
				continue;
			}

			$data = unserialize( $option->option_value, array( 'allowed_classes' => false ) );

			if ( ! is_array( $data ) ) {
				continue;
			}

			$session_id = str_replace( $this->option_prefix, '', (string) $option->option_name );
			if ( '' !== $session_id && ! isset( $data['_sig'] ) ) {
				$data['_sig'] = $this->sign_session_id( $session_id );
			}

			$encoded_data = wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

			if ( false === $encoded_data ) {
				continue;
			}

			$encrypted_data = $this->encrypt_session_data( $encoded_data );

			if ( update_option( (string) $option->option_name, $encrypted_data, false ) ) {
				++$migrated;
				$this->logger->info(
					'Migrated legacy serialized session to encrypted JSON storage',
					array(
						'option_name' => (string) $option->option_name,
						'session_id'  => $session_id,
					)
				);
			}
		}

		return $migrated;
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

		// Attempt decryption first — null/false return means decryption failed.
		$decrypted = $this->decrypt_session_data( $raw );

		if ( null !== $decrypted && false !== $decrypted ) {
			// Decryption succeeded — JSON decode the result.
			$data = json_decode( $decrypted, true );
			if ( is_array( $data ) ) {
				return $data;
			}
			return null;
		}

		// Decryption failed — try plain JSON decode (legacy unencrypted data or test fixtures).
		$data = json_decode( $raw, true );

		if ( is_array( $data ) ) {
			return $data;
		}

		// Attempt legacy PHP serialization migration if session_id is available.
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

		if ( ! update_option( $this->get_option_name( $session_id ), $encrypted_data, false ) ) {
			$this->logger->warning(
				'Failed to persist migrated legacy session',
				array( 'session_id' => $session_id )
			);
			return null;
		}

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
		return hash_equals( $this->sign_session_id( $session_id ), $signature );
	}

	/**
	 * Get the HMAC signing key.
	 *
	 * @return string
	 */
	private function get_signing_key(): string {
		if ( defined( 'AUTH_SALT' ) && '' !== AUTH_SALT ) {
			return AUTH_SALT;
		}

		if ( defined( 'SECURE_AUTH_KEY' ) && '' !== SECURE_AUTH_KEY ) {
			return SECURE_AUTH_KEY;
		}

		if ( defined( 'NONCE_SALT' ) && '' !== NONCE_SALT ) {
			return NONCE_SALT;
		}

		$secret = get_option( 'sscribe_session_signing_key', '' );
		if ( '' === $secret ) {
			$secret = bin2hex( random_bytes( 32 ) );
			add_option( 'sscribe_session_signing_key', $secret, '', 'no' );
		}

		return $secret;
	}

	/**
	 * Encrypt session data using AES-256-CBC.
	 *
	 * @param string $data JSON-encoded session data.
	 * @return string Base64-encoded encrypted data with IV prepended.
	 */
	private function encrypt_session_data( string $data ): string {
		$key    = $this->get_encryption_key();
		$iv_len = openssl_cipher_iv_length( 'aes-256-cbc' );
		$iv     = openssl_random_pseudo_bytes( $iv_len );

		$encrypted = openssl_encrypt( $data, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );

		if ( false === $encrypted ) {
			$this->logger->error( 'Failed to encrypt session data' );
			return $data;
		}

		// Prepend IV to ciphertext for storage.
		return base64_encode( $iv . $encrypted );
	}

	/**
	 * Decrypt session data using AES-256-CBC.
	 *
	 * @param string $encrypted_data Base64-encoded encrypted data with IV prepended.
	 * @return string|null Decrypted JSON data, or null if decryption fails.
	 */
	private function decrypt_session_data( string $encrypted_data ): ?string {
		$raw = base64_decode( $encrypted_data, true );

		if ( false === $raw || strlen( $raw ) < 16 ) {
			return null;
		}

		$iv     = substr( $raw, 0, 16 );
		$cipher = substr( $raw, 16 );
		$key    = $this->get_encryption_key();

		$decrypted = openssl_decrypt( $cipher, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );

		if ( false === $decrypted ) {
			return null;
		}

		return $decrypted;
	}

	/**
	 * Derive an encryption key from the signing key using SHA-256.
	 *
	 * @return string 32-byte encryption key.
	 */
	private function get_encryption_key(): string {
		return hash( 'sha256', $this->get_signing_key(), true );
	}

	/**
	 * Get the session storage type identifier.
	 *
	 * @return string
	 */
	public function get_storage_type(): string {
		return 'encrypted-json';
	}
}
