<?php
/**
 * Lock management for SScribe batch export processing.
 *
 * Provides transient-based distributed locking with token ownership
 * verification to prevent race conditions between concurrent AJAX
 * batch requests. Lock format: "timestamp|token".
 *
 * @package       SScribe
 * @since         1.1.3
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lock manager for batch export processing.
 *
 * Implements a token-based distributed lock using WordPress transients.
 * Each lock acquisition returns a unique token; the caller must present
 * the same token to release the lock. This prevents one HTTP request
 * from releasing another request's lock.
 *
 * @since 1.1.3
 */
class SScribe_Export_Lock_Manager {

	/**
	 * Logger instance for lock debug events.
	 *
	 * @var SScribe_Logger_Interface
	 * @since 1.1.3
	 */
	private readonly SScribe_Logger_Interface $logger;

	/**
	 * Constructor.
	 *
	 * @since 1.1.3
	 *
	 * @param SScribe_Logger_Interface|null $logger Logger instance. Falls
	 *                                              back to the default logger
	 *                                              if omitted.
	 */
	public function __construct( ?SScribe_Logger_Interface $logger = null ) {
		$this->logger = $logger ?? SScribe_Logger::instance();
	}

	/**
	 * Attempt to acquire a processing lock for the given session.
	 *
	 * Uses a timestamp|token format stored as a WordPress transient with
	 * a configurable TTL. Detects stale locks (exceeded the stale threshold)
	 * and replaces them atomically.
	 *
	 * @since 1.1.3
	 *
	 * @param string $session_id      The export session ID to lock.
	 * @param int    $lock_ttl        Lock TTL in seconds. Default 45.
	 *                                Filterable via {@see 'sscribe_lock_ttl'}.
	 * @param int    $stale_threshold Age in seconds after which a lock is
	 *                                considered stale and eligible for
	 *                                forced takeover. Default 35.
	 *                                Filterable via {@see 'sscribe_lock_stale_threshold'}.
	 *
	 * @return string|null Lock token on success, null if the lock could not
	 *                     be acquired (another process holds it).
	 */
	public function acquire_lock(
		string $session_id,
		int $lock_ttl = 45,
		int $stale_threshold = 35
	): ?string {
		$lock_key      = 'sscribe_lock_' . $session_id;
		$lock_token    = wp_generate_password( 32, false );
		$existing_lock = get_transient( $lock_key );
		$current_time  = time();

		if ( $existing_lock ) {
			$lock_parts = explode( '|', $existing_lock );
			$lock_time  = isset( $lock_parts[0] ) ? (int) $lock_parts[0] : 0;
			$lock_age   = $current_time - $lock_time;

			if ( $lock_age > $stale_threshold ) {
				/*
				 * Stale lock — delete first to force an atomic INSERT
				 * on the following set_transient(). Without the delete,
				 * set_transient() calls update_option() which is NOT
				 * atomic, allowing two concurrent requests to both
				 * succeed and corrupt the export.
				 */
				delete_transient( $lock_key );

				$this->logger->debug(
					'Detected stale lock, attempting atomic acquisition',
					array(
						'session_id' => $session_id,
						'lock_age'   => $lock_age,
						'lock_ttl'   => $lock_ttl,
					)
				);

				// Attempt to set a new lock.
				if ( set_transient( $lock_key, $current_time . '|' . $lock_token, $lock_ttl ) ) {
					return $lock_token;
				}

				// Another process acquired the lock between our delete and set.
				$this->logger->debug(
					'Lock acquisition failed — another process won the race',
					array( 'session_id' => $session_id )
				);

				return null;
			}

			// Active lock exists — reject.
			$this->logger->debug(
				'Batch is already processing concurrently',
				array( 'session_id' => $session_id )
			);

			return null;
		}

		// No existing lock — acquire.
		if ( set_transient( $lock_key, $current_time . '|' . $lock_token, $lock_ttl ) ) {
			return $lock_token;
		}

		// Transient storage unavailable.
		$this->logger->warning(
			'Lock transient unavailable, aborting batch',
			array( 'session_id' => $session_id )
		);

		return null;
	}

	/**
	 * Release a processing lock if the caller owns it.
	 *
	 * Token ownership verification prevents one request from releasing
	 * another request's lock, which would cause race conditions.
	 *
	 * @since 1.1.3
	 *
	 * @param string      $session_id The export session ID.
	 * @param string|null $lock_token The token returned by
	 *                                {@see acquire_lock()}. If null,
	 *                                the lock is not released.
	 *
	 * @return bool True if the lock was released, false if the caller
	 *              does not own the lock or it doesn't exist.
	 */
	public function release_lock( string $session_id, ?string $lock_token ): bool {
		if ( null === $lock_token ) {
			return false;
		}

		$lock_key = 'sscribe_lock_' . $session_id;
		$lock     = get_transient( $lock_key );

		if ( ! $lock ) {
			// Lock already released or never acquired.
			return true;
		}

		$lock_parts     = explode( '|', $lock );
		$stored_token   = $lock_parts[1] ?? '';

		// Only release if we own the lock (token matches).
		if ( $lock_token === $stored_token ) {
			delete_transient( $lock_key );
			return true;
		}

		return false;
	}

	/**
	 * Clean up locks, optionally scoped to a user.
	 *
	 * When called with a user ID, removes ALL locks for that user's
	 * sessions and deletes the corresponding session options. When
	 * called without a user ID, only removes EXPIRED locks (those
	 * whose transient timeout has passed).
	 *
	 * @since 1.1.3
	 *
	 * @param int|null    $user_id             User ID to clean up locks
	 *                                         for. Null to only clean
	 *                                         expired locks.
	 * @param string|null $current_session_id Optional session ID to
	 *                                        preserve (skip deletion).
	 *                                        Used when force-clearing
	 *                                        locks while keeping the
	 *                                        current session.
	 *
	 * @return array{deleted: int, preserved: int} Count of deleted and
	 *                                            preserved locks.
	 */
	public function cleanup_user_locks( ?int $user_id = null, ?string $current_session_id = null ): array {
		global $wpdb;

		$deleted   = 0;
		$preserved = 0;

		if ( null !== $user_id ) {
			$session_pattern = $wpdb->esc_like( 'sscribe_session_' ) . '%';

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cleanup.
			$sessions = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s AND autoload = 'no'",
					$session_pattern
				)
			);

			foreach ( $sessions as $session_row ) {
				// Try JSON first (current storage format).
				$data = json_decode( $session_row->option_value, true );

				/*
				 * SECURITY: Do NOT use maybe_unserialize() here — it enables
				 * object injection. Legacy PHP-serialized sessions that fail
				 * JSON decode are skipped intentionally. They will be cleaned
				 * up naturally by SScribe_Session::cleanup_expired() (4-hour
				 * expiry).
				 */
				if ( ! is_array( $data ) ) {
					continue;
				}

				if ( isset( $data['user_id'] ) && (int) $data['user_id'] === $user_id ) {
					$sid = $data['session_id'] ?? '';

					// Preserve the current session if requested.
					if ( null !== $current_session_id && $sid === $current_session_id ) {
						++$preserved;
						continue;
					}

					if ( '' !== $sid ) {
						delete_transient( 'sscribe_lock_' . $sid );
						++$deleted;
					}
					delete_option( $session_row->option_name );
				}
			}
		} else {
			// Only delete expired locks when no user_id is specified.
			$now                  = time();
			$lock_timeout_pattern = $wpdb->esc_like( '_transient_timeout_sscribe_lock_' ) . '%';

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cleanup.
			$expired_locks = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d",
					$lock_timeout_pattern,
					$now
				)
			);

			foreach ( $expired_locks as $expired ) {
				$session_id = str_replace( '_transient_timeout_sscribe_lock_', '', $expired->option_name );
				delete_transient( 'sscribe_lock_' . $session_id );
				++$deleted;
			}
		}

		return array(
			'deleted'   => $deleted,
			'preserved' => $preserved,
		);
	}
}
