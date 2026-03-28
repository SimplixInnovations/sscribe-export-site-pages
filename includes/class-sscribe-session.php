<?php
/**
 * Database-based session storage for export operations.
 *
 * Uses wp_options table with autoload=false for session storage.
 * This replaces file-based storage to comply with WordPress.org
 * repository guidelines that prohibit direct filesystem writes.
 *
 * @package SScribe
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SScribe_Session
 *
 * Manages export session data using WordPress options API.
 */
class SScribe_Session {

	/**
	 * Logger instance.
	 *
	 * @var SScribe_Logger
	 */
	private readonly SScribe_Logger $logger;

	/**
	 * Constructor.
	 *
	 * @param string $option_prefix Option name prefix for session storage.
	 */
	public function __construct(
		private readonly string $option_prefix = 'sscribe_session_'
	) {
		$this->logger = new SScribe_Logger( defined( 'SSCRIBE_DEBUG' ) && SSCRIBE_DEBUG );
	}

	/**
	 * Create a new session with the provided data.
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

		$option_name = $this->get_option_name( $session_id );
		$json        = wp_json_encode( $data, JSON_UNESCAPED_UNICODE );

		if ( false === $json ) {
			$this->logger->error(
				'Failed to encode session data to JSON',
				array(
					'json_error' => json_last_error_msg(),
				)
			);
			return '';
		}

		$result = update_option( $option_name, $json, false );

		if ( ! $result ) {
			$this->logger->error(
				'Failed to create session',
				array(
					'session_id' => $session_id,
				)
			);
			return '';
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
		$json        = get_option( $option_name );

		if ( false === $json ) {
			return null;
		}

		$data = json_decode( $json, true );

		if ( JSON_ERROR_NONE !== json_last_error() ) {
			$this->logger->error(
				'Failed to decode session data',
				array(
					'session_id' => $session_id,
					'json_error' => json_last_error_msg(),
				)
			);
			return null;
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
		$merged['updated_at'] = time();

		$option_name = $this->get_option_name( $session_id );
		$json        = wp_json_encode( $merged, JSON_UNESCAPED_UNICODE );

		if ( false === $json ) {
			$this->logger->error(
				'Failed to encode session data for update',
				array(
					'session_id' => $session_id,
					'json_error' => json_last_error_msg(),
				)
			);
			return false;
		}

		return update_option( $option_name, $json, false );
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
	 * @param string $session_id The session identifier.
	 * @return bool True if valid, false otherwise.
	 */
	public function validate( string $session_id ): bool {
		$data = $this->get( $session_id );

		if ( null === $data ) {
			return false;
		}

		$required_keys = array( 'page_ids', 'total', 'processed' );

		foreach ( $required_keys as $key ) {
			if ( ! isset( $data[ $key ] ) ) {
				return false;
			}
		}

		if ( ! is_array( $data['page_ids'] ) ) {
			return false;
		}

		if ( count( $data['page_ids'] ) !== (int) $data['total'] ) {
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

		$options = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s AND autoload = 'no'",
				$pattern
			)
		);

		$deleted = 0;

		foreach ( $options as $option ) {
			$data = json_decode( $option->option_value, true );

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
	 * Check if a user has an active session.
	 *
	 * @param int $user_id User ID to check.
	 * @return bool True if user has an active session.
	 */
	public function has_active_session( int $user_id ): bool {
		global $wpdb;

		$pattern = $wpdb->esc_like( $this->option_prefix ) . '%';
		$now     = time();
		$max_age = 60;

		$options = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE %s AND autoload = 'no'",
				$pattern
			)
		);

		foreach ( $options as $option ) {
			$data = json_decode( $option->option_value, true );

			if ( ! is_array( $data ) ) {
				continue;
			}

			if ( isset( $data['user_id'] ) && (int) $data['user_id'] === $user_id ) {
				if ( isset( $data['created_at'] ) && ( $now - $data['created_at'] ) < $max_age ) {
					if ( isset( $data['processed'], $data['total'] ) ) {
						$processed = (int) $data['processed'];
						$total     = (int) $data['total'];
						if ( $processed < $total && empty( $data['cancelled'] ) ) {
							return true;
						}
					}
				}
			}
		}

		return false;
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

		$options = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s AND autoload = 'no'",
				$pattern
			)
		);

		$deleted = 0;

		foreach ( $options as $option ) {
			$data = json_decode( $option->option_value, true );

			if ( ! is_array( $data ) ) {
				continue;
			}

			if ( isset( $data['user_id'] ) && (int) $data['user_id'] === $user_id ) {
				if ( delete_option( $option->option_name ) ) {
					++$deleted;
				}
			}
		}

		return $deleted;
	}

	/**
	 * Get the option name for a session.
	 *
	 * @param string $session_id The session identifier.
	 * @return string Option name.
	 */
	private function get_option_name( string $session_id ): string {
		return $this->option_prefix . $session_id;
	}

	/**
	 * Get the storage type identifier.
	 *
	 * @return string Storage type.
	 */
	public function get_storage_type(): string {
		return 'database';
	}
}
