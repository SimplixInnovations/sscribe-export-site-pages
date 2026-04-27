<?php
/**
 * Database-based session storage for export operations.
 *
 * Uses wp_options table with autoload=false for session storage.
 * Session data is JSON-encoded for cross-compatibility and to prevent
 * PHP object injection attacks that can occur with serialized data.
 *
 * @package SScribe
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SScribe_Session
 *
 * Manages export session data using WordPress options API.
 * Stores data as JSON (not PHP serialized) to prevent object injection.
 */
class SScribe_Session {

	/**
	 * Batch size for session cleanup scans.
	 */
	private const SESSION_CLEANUP_BATCH = 100;

	/**
	 * In-memory active session cache by user ID.
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

	/**
	 * Constructor.
	 *
	 * @param string $option_prefix Option name prefix for session storage.
	 */
	public function __construct(
		private readonly string $option_prefix = 'sscribe_session_'
	) {
		$this->logger = SScribe_Logger::instance( defined( 'SSCRIBE_DEBUG' ) && SSCRIBE_DEBUG );
	}

	/**
	 * Build the full option name for a session.
	 *
	 * @param string $session_id Session ID.
	 * @return string Option name.
	 */
	private function get_option_name( string $session_id ): string {
		return $this->option_prefix . $session_id;
	}

	/**
	 * Create a new session with the provided data.
	 *
	 * Uses add_option() instead of update_option() so that:
	 * - Retries of an already-created session don't silently return false.
	 * - The option is always truly new on creation.
	 *
	 * @param array $data Session data to store.
	 * @return string Session ID on success, empty string on failure.
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

			// Use add_option() — always true for a fresh insert, never false for "no change".
			$result = add_option( $option_name, $encoded_data, '', 'no' );

			if ( $result ) {
				// Success - break out of retry loop.
				break;
			}

			// Collision detected - retry with new session_id.
			$this->logger->warning(
				'Session ID collision detected, retrying',
				array(
					'session_id' => $session_id,
					'attempt'    => $attempt + 1,
					'max'        => $max_retries,
				)
			);
			++$attempt;

			// If this was the last attempt, log final failure.
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

		if ( isset( $data['user_id'] ) ) {
			unset( self::$active_session_cache[ (int) $data['user_id'] ] );
			delete_transient( 'sscribe_active_session_' . (int) $data['user_id'] );
		}

		return $session_id;
	}

	/**
	 * Get session data by ID.
	 *
	 * @param string $session_id The session identifier.
	 * @return array|null Session data or null if not found.
	 */
	public function get( string $session_id ): ?array {
		$session_id = sanitize_key( $session_id );

		if ( empty( $session_id ) || 16 !== strlen( $session_id ) ) {
			return null;
		}

		$option_name = $this->get_option_name( $session_id );

		// Bypass object caching to prevent reading stale session data across rapid AJAX requests.
		wp_cache_delete( $option_name, 'options' );

		$raw = get_option( $option_name );

		if ( false === $raw ) {
			return null;
		}

		if ( is_array( $raw ) ) {
			$data = $raw;
		} elseif ( is_string( $raw ) ) {
			$data = json_decode( $raw, true );

			if ( ! is_array( $data ) ) {
				$data = $this->migrate_legacy_session( $session_id, $raw );
			}
		} else {
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
	 * @param string $session_id The session identifier.
	 * @param array  $data       Data to merge with existing session.
	 * @return bool True on success, false on failure.
	 */
	public function update( string $session_id, array $data ): bool {
		$session_id = sanitize_key( $session_id );

		if ( empty( $session_id ) || 16 !== strlen( $session_id ) ) {
			return false;
		}

		$option_name = $this->get_option_name( $session_id );
		wp_cache_delete( $option_name, 'options' );

		$existing = $this->get( $session_id );

		if ( null === $existing ) {
			$this->logger->error(
				'Failed to read existing session for update',
				array(
					'session_id' => $session_id,
				)
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
					delete_transient( 'sscribe_active_session_' . (int) $merged['user_id'] );
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
	}

	/**
	 * Delete a session by ID.
	 *
	 * @param string $session_id The session identifier.
	 * @return bool True on success, false on failure.
	 */
	public function delete( string $session_id ): bool {
		$session_id = sanitize_key( $session_id );

		if ( empty( $session_id ) ) {
			return false;
		}

		$option_name = $this->get_option_name( $session_id );

		return delete_option( $option_name );
	}

	/**
	 * Validate session integrity and schema.
	 *
	 * Requires: page_ids (array), total (int), processed (int), session_id (string), user_id (int).
	 *
	 * @param string $session_id The session identifier.
	 * @return bool True if valid, false otherwise.
	 */
	public function validate( string $session_id ): bool {
		$data = $this->get( $session_id );

		if ( ! is_array( $data ) ) {
			return false;
		}

		// All required keys must exist with correct types.
		// Note: user_id is optional for backward compatibility (sessions created before security fix).
		$required = array(
			'page_ids'   => 'is_array',
			'total'      => 'is_int',
			'processed'  => 'is_int',
			'session_id' => 'is_string',
		);

		foreach ( $required as $key => $check ) {
			if ( ! isset( $data[ $key ] ) || ! $check( $data[ $key ] ) ) {
				$this->logger->error( "Session validation failed for key: {$key}", array( 'session_id' => $session_id ) );
				return false;
			}
		}

		// user_id validation (optional but must be int if present).
		if ( isset( $data['user_id'] ) && ! is_int( $data['user_id'] ) ) {
			$this->logger->error( 'Session user_id is not an integer', array( 'user_id' => $data['user_id'] ) );
			return false;
		}

		// Logic checks.
		if ( $data['total'] < 0 || $data['processed'] < 0 ) {
			return false;
		}

		// Session ID mismatch indicates potential tampering.
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

		// Bounds checks: Sanity limits to prevent abuse (but be lenient for active processing).
		// Allow processed to exceed total by a small margin (handles race conditions during updates).
		if ( $data['total'] > 100000 ) {
			$this->logger->error( 'Session total exceeds maximum limit', array( 'total' => $data['total'] ) );
			return false;
		}
		// Only fail if processed is significantly larger than total (indicates corruption).
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
	 * @param int $max_age_seconds Maximum age in seconds. Default 4 hours.
	 * @return int Number of sessions deleted.
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
					$cursor = $option->option_name;
					continue;
				}

				if ( isset( $data['created_at'] ) && ( $now - $data['created_at'] ) > $max_age_seconds ) {
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
	 * Clear all sessions for a specific user.
	 *
	 * @param int $user_id User ID.
	 * @return int Number of sessions deleted.
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

		delete_transient( 'sscribe_active_session_' . $user_id );

		return $deleted;
	}

	/**
	 * Get all sessions associated with a specific user.
	 *
	 * @param int $user_id User ID.
	 * @return array<int, array<string, mixed>>
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
	 * Delete all sessions associated with a specific user.
	 *
	 * @param int $user_id User ID.
	 * @return int Number of deleted sessions.
	 */
	public function delete_sessions_for_user( int $user_id ): int {
		return $this->clear_user_sessions( $user_id );
	}

	/**
	 * Check if a user has an active export session.
	 *
	 * An "active" session is one that was started within the last 60 seconds
	 * and has not yet completed all pages.
	 *
	 * Note: The $recently_started_window is intentionally short (60s) because
	 * this guard only prevents START of duplicate exports, not the ongoing batch.
	 *
	 * Uses a short-lived cache (5 seconds) to reduce database load on repeated checks.
	 *
	 * @param int $user_id User ID to check.
	 * @return bool True if user has an active session.
	 */
	public function has_active_session( int $user_id ): bool {
		if ( isset( self::$active_session_cache[ $user_id ] ) ) {
			return self::$active_session_cache[ $user_id ];
		}

		$cache_key = 'sscribe_active_session_' . $user_id;
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			self::$active_session_cache[ $user_id ] = (bool) $cached;
			return self::$active_session_cache[ $user_id ];
		}

		global $wpdb;

		$pattern                 = $wpdb->esc_like( $this->option_prefix ) . '%';
		$now                     = time();
		$recently_started_window = 60; // seconds — only guards against duplicate export starts.

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
				if ( isset( $data['created_at'] ) && ( $now - (int) $data['created_at'] ) < $recently_started_window ) {
					if ( isset( $data['processed'], $data['total'] ) ) {
						$processed = (int) $data['processed'];
						$total     = (int) $data['total'];
						if ( $processed < $total && empty( $data['cancelled'] ) ) {
							$has_active = true;
							break;
						}
					}
				}
			}
		}

		self::$active_session_cache[ $user_id ] = $has_active;
		set_transient( $cache_key, $has_active ? '1' : '0', 5 );

		return $has_active;
	}

	/**
	 * Migrate all legacy serialized sessions to JSON storage.
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

			$data = maybe_unserialize( $option->option_value );

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
	 * Decode a session option value stored as JSON.
	 *
	 * @param mixed $raw Raw option_value from database.
	 * @return array|null Decoded session array or null on failure.
	 */
	private function decode_session_value( mixed $raw ): ?array {
		if ( ! is_string( $raw ) ) {
			return null;
		}

		// Try JSON first (current format).
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
	 * Migrate a legacy serialized session to JSON storage.
	 *
	 * @param string $session_id Session identifier.
	 * @param string $raw        Raw option value.
	 * @return array|null Migrated session data or null on failure.
	 */
	private function migrate_legacy_session( string $session_id, string $raw ): ?array {
		if ( ! preg_match( '/^a:\d+:\{/', $raw ) ) {
			return null;
		}

		$data = maybe_unserialize( $raw );

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
	 * Sign a session identifier.
	 *
	 * @param string $session_id Session identifier.
	 * @return string Signature hash.
	 */
	private function sign_session_id( string $session_id ): string {
		return hash_hmac( 'sha256', $session_id, $this->get_signing_key() );
	}

	/**
	 * Verify a session signature.
	 *
	 * @param string $session_id Session identifier.
	 * @param string $signature  Stored signature.
	 * @return bool True when valid.
	 */
	private function verify_session_signature( string $session_id, string $signature ): bool {
		return hash_equals( $this->sign_session_id( $session_id ), $signature );
	}

	/**
	 * Get the session signing key.
	 *
	 * @return string Signing key material.
	 * @throws \RuntimeException If no signing key material is available.
	 */
	private function get_signing_key(): string {
		if ( defined( 'AUTH_SALT' ) && '' !== AUTH_SALT ) {
			return AUTH_SALT;
		}

		if ( defined( 'SECURE_AUTH_KEY' ) && '' !== SECURE_AUTH_KEY ) {
			return SECURE_AUTH_KEY;
		}

		if ( defined( 'DB_PASSWORD' ) && '' !== DB_PASSWORD ) {
			return DB_PASSWORD;
		}

		// Fail securely: no signing key material available.
		throw new \RuntimeException(
			'SScribe session signing key unavailable. Define AUTH_SALT, SECURE_AUTH_KEY, or DB_PASSWORD in wp-config.php.'
		);
	}

	/**
	 * Get the storage type identifier.
	 *
	 * @return string Storage type.
	 */
	public function get_storage_type(): string {
		return 'database-json';
	}
}
