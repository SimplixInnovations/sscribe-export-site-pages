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
		$session_id = sanitize_key( bin2hex( random_bytes( 8 ) ) );
		$session_id = strtolower( $session_id );

		$data['created_at'] = time();
		$data['session_id'] = $session_id;
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

		if ( ! $result ) {
			$this->logger->error(
				'Failed to create session (option already exists?)',
				array( 'session_id' => $session_id )
			);
			return '';
		}

		if ( isset( $data['user_id'] ) ) {
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

		// Decode JSON-encoded session data.
		$data = json_decode( $raw, true );

		if ( ! is_array( $data ) ) {
			// Legacy: fall back to PHP unserialization for backward compatibility.
			$data = maybe_unserialize( $raw );
		}

		return is_array( $data ) ? $data : null;
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
		$merged['updated_at'] = microtime( true );

		$option_name  = $this->get_option_name( $session_id );
		$encoded_data = wp_json_encode( $merged, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		if ( false === $encoded_data ) {
			$this->logger->error(
				'Failed to JSON-encode session update',
				array( 'session_id' => $session_id )
			);
			return false;
		}

		return update_option( $option_name, $encoded_data, false );
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
	 * Validate session integrity.
	 *
	 * Requires: page_ids (array), total (int), processed (int), session_id (string).
	 * The count of page_ids may legitimately differ from total if pages changed
	 * after the session was created (WPML cache refresh, etc.) — we only check types.
	 *
	 * @param string $session_id The session identifier.
	 * @return bool True if valid, false otherwise.
	 */
	public function validate( string $session_id ): bool {
		$data = $this->get( $session_id );

		if ( null === $data ) {
			return false;
		}

		// All required keys must exist.
		$required_keys = array( 'page_ids', 'total', 'processed', 'session_id' );

		foreach ( $required_keys as $key ) {
			if ( ! isset( $data[ $key ] ) ) {
				return false;
			}
		}

		// Type checks only — don't compare count vs total to avoid false invalids
		// when pages are added/removed after session creation (e.g., WPML refresh).
		if ( ! is_array( $data['page_ids'] ) ) {
			return false;
		}

		$total = (int) $data['total'];
		if ( $total < 0 ) {
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

			if ( isset( $data['created_at'] ) && ( $now - $data['created_at'] ) > $max_age_seconds ) {
				if ( delete_option( $option->option_name ) ) {
					++$deleted;
				}
			}
		}

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
		$cache_key = 'sscribe_active_session_' . $user_id;
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return (bool) $cached;
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

		set_transient( $cache_key, $has_active ? '1' : '0', 5 );

		return $has_active;
	}

	/**
	 * Decode a session option value, supporting both JSON (current) and
	 * PHP serialization (legacy backward compatibility).
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

		// Fall back to PHP unserialization (legacy sessions).
		$data = maybe_unserialize( $raw );

		return is_array( $data ) ? $data : null;
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
