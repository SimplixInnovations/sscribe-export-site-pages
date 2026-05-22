<?php
/**
 * SScribe Session
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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
		$this->logger = SScribe_Logger::instance( defined( 'SSCRIBE_DEBUG' ) && SSCRIBE_DEBUG );
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

			$result = add_option( $option_name, $encoded_data, '', 'no' );

			if ( $result ) {

				if ( isset( $data['user_id'] ) ) {
					set_transient( 'sscribe_active_sid_' . $data['user_id'], $session_id, 300 );
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
			return $data;
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

		// Use exponential back-off: 100ms, 200ms, 400ms, 800ms, 1600ms
		$base_delay = 100000; // 100ms in microseconds

		for ( $lock_attempt = 1; $lock_attempt <= 5; ++$lock_attempt ) {
			if ( $using_cache ) {
				if ( wp_cache_add( $lock_key, time(), 'transient', $lock_ttl ) ) {
					$lock_acquired = true;
					break;
				}
			} elseif ( set_transient( $lock_key, time(), $lock_ttl ) ) {
				$lock_acquired = true;
				break;
			}

			// Exponential back-off: base_delay * 2^(attempt-1)
			$delay = $base_delay * ( 2 ** ( $lock_attempt - 1 ) );
			usleep( $delay );
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
				$this->logger->error(
					'Failed to read existing session for update',
					array( 'session_id' => $session_id )
				);
				return false;
			}

			$merged               = array_merge( $existing, $data );
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
				if ( update_option( $option_name, $encoded_data, false ) ) {
					if ( isset( $merged['user_id'] ) ) {
						unset( self::$active_session_cache[ (int) $merged['user_id'] ] );
						delete_transient( 'sscribe_active_sid_' . (int) $merged['user_id'] );
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

			return false;

		} finally {

			if ( $using_cache ) {
				wp_cache_delete( $lock_key, 'transient' );
			}
			delete_transient( $lock_key );
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

		return delete_option( $option_name );
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
			'page_ids'   => 'is_array',
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

		if ( $data['session_id'] !== $session_id ) {
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

		$pattern = $wpdb->esc_like( $this->option_prefix ) . '%';
		$now     = time();

		$deleted = 0;
		$cursor  = '';

		do {
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
				$data = $this->decode_session_value( $option->option_value );

				if ( ! is_array( $data ) ) {

					if ( is_string( $option->option_value ) && str_starts_with( $option->option_value, 'a:' ) ) {
						delete_option( $option->option_name );
						++$deleted;
					}
					$cursor = $option->option_name;
					continue;
				}

				$last_activity = isset( $data['updated_at'] )
					? max( $data['created_at'], $data['updated_at'] )
					: $data['created_at'];

				$status = $data['status'] ?? '';
				$age    = $now - $last_activity;
				if ( 'finalizing' === $status && $age < HOUR_IN_SECONDS ) {
					$cursor = $option->option_name;
					continue;
				}

				if ( isset( $data['created_at'] ) && ( $now - $last_activity ) > $max_age_seconds ) {
					if ( delete_option( $option->option_name ) ) {
						++$deleted;
					}
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
			$data = $this->decode_session_value( $option->option_value );

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
	 * Get all sessions for a user.
	 *
	 * @param int $user_id User ID.
	 * @return array
	 */
	public function get_sessions_for_user( int $user_id ): array {
		if ( $user_id <= 0 ) {
			return array();
		}

		global $wpdb;

		$pattern = $wpdb->esc_like( $this->option_prefix ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Privacy export needs a complete scan of session options.
		$options = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s AND autoload = 'no'",
				$pattern
			)
		);

		$sessions = array();

		foreach ( $options as $option ) {
			$data = $this->decode_session_value( $option->option_value ?? '' );

			if ( ! is_array( $data ) ) {
				continue;
			}

			if ( isset( $data['user_id'] ) && (int) $data['user_id'] === $user_id ) {
				$sessions[] = $data;
			}
		}

		usort(
			$sessions,
			static function ( array $left, array $right ): int {
				return (int) ( $right['updated_at'] ?? 0 ) <=> (int) ( $left['updated_at'] ?? 0 );
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
	 * Check if a user has an active session.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public function has_active_session( int $user_id ): bool {
		if ( isset( self::$active_session_cache[ $user_id ] ) ) {
			return self::$active_session_cache[ $user_id ];
		}

		$cache_key = 'sscribe_active_sid_' . $user_id;
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			self::$active_session_cache[ $user_id ] = ! empty( $cached );
			return self::$active_session_cache[ $user_id ];
		}

		global $wpdb;

		$pattern = $wpdb->esc_like( $this->option_prefix ) . '%';

		$has_active = false;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Session check scans all options; caching not applicable for existence check.
		$options = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE %s AND autoload = 'no'",
				$pattern
			)
		);

		foreach ( $options as $option ) {
			$data = $this->decode_session_value( $option->option_value ?? '' );

			if ( ! is_array( $data ) ) {
				continue;
			}

			if ( isset( $data['user_id'] ) && (int) $data['user_id'] === $user_id ) {
				$status = $data['status'] ?? '';
				if ( in_array( $status, array( 'processing', 'pending', 'finalizing' ), true ) ) {
					$processed = (int) ( $data['processed'] ?? 0 );
					$total     = (int) ( $data['total'] ?? 0 );
					if ( $processed < $total && empty( $data['cancelled'] ) ) {
						$has_active = true;
						break;
					}
				}
			}
		}

		self::$active_session_cache[ $user_id ] = $has_active;
		set_transient( $cache_key, $has_active ? '1' : '0', 5 );

		return $has_active;
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

			if ( update_option( (string) $option->option_name, $encoded_data, false ) ) {
				++$migrated;
				$this->logger->info(
					'Migrated legacy serialized session to JSON storage',
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
	 * @param mixed $raw Raw option value.
	 * @return array|null Decoded data or null.
	 */
	private function decode_session_value( mixed $raw ): ?array {
		if ( ! is_string( $raw ) ) {
			return null;
		}

		$data = json_decode( $raw, true );

		if ( is_array( $data ) ) {
			return $data;
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

		if ( ! update_option( $this->get_option_name( $session_id ), $encoded_data, false ) ) {
			$this->logger->warning(
				'Failed to persist migrated legacy session',
				array( 'session_id' => $session_id )
			);
			return null;
		}

		$this->logger->debug(
			'Migrated legacy serialized session to JSON storage',
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
	 * Get the session storage type identifier.
	 *
	 * @return string
	 */
	public function get_storage_type(): string {
		return 'database-json';
	}
}
