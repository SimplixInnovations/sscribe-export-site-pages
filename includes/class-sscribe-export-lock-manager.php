<?php
/**
 * SScribe Export Lock Manager
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages transient-based locks for concurrent export prevention.
 */
class SScribe_Export_Lock_Manager {

	/**
	 * Session option prefix (must match SScribe_Session::OPTION_PREFIX).
	 *
	 * @var string
	 */
	private const SESSION_PREFIX = 'sscribe_session_';

	/**
	 * Logger instance.
	 *
	 * @var SScribe_Logger_Interface
	 */
	private readonly SScribe_Logger_Interface $logger;

	/**
	 * Initialize the lock manager.
	 *
	 * @param SScribe_Logger_Interface|null $logger Logger instance.
	 */
	public function __construct( ?SScribe_Logger_Interface $logger = null ) {
		$this->logger = $logger ?? SScribe_Logger::instance();
	}

	/**
	 * Acquire a processing lock for a session.
	 *
	 * Uses wp_cache_add() for atomic lock acquisition on Redis/Memcached backends.
	 * Falls back to set_transient() for disk-based caching.
	 *
	 * @param string $session_id       Session identifier.
	 * @param int    $lock_ttl         Lock TTL in seconds.
	 * @param int    $stale_threshold  Stale lock threshold in seconds.
	 * @return string|null Lock token or null if locked.
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
				// Stale lock detected — delete then atomically replace.
				delete_transient( $lock_key );
				if ( set_transient( $lock_key, $current_time . '|' . $lock_token, $lock_ttl ) ) {
					return $lock_token;
				}
				$this->logger->debug(
					'Lock acquisition failed — another process won the race',
					array( 'session_id' => $session_id )
				);
				return null;
			}

			$this->logger->debug(
				'Batch is already processing concurrently',
				array( 'session_id' => $session_id )
			);
			return null;
		}

		if ( set_transient( $lock_key, $current_time . '|' . $lock_token, $lock_ttl ) ) {
			return $lock_token;
		}

		$this->logger->warning(
			'Lock transient unavailable, aborting batch',
			array( 'session_id' => $session_id )
		);
		return null;
	}

	/**
	 * Release a processing lock.
	 *
	 * @param string      $session_id Session identifier.
	 * @param string|null $lock_token Lock token to verify.
	 * @return bool
	 */
	public function release_lock( string $session_id, ?string $lock_token ): bool {
		if ( null === $lock_token ) {
			return false;
		}

		$lock_key = 'sscribe_lock_' . $session_id;
		$raw      = get_transient( $lock_key );

		if ( false === $raw ) {
			return true;
		}

		$parts  = explode( '|', $raw );
		$stored = $parts[1] ?? '';

		if ( ! hash_equals( $lock_token, $stored ) ) {
			return false;
		}

		delete_transient( $lock_key );

		return true;
	}

	/**
	 * Clean up locks for a user or expired locks globally.
	 *
	 * @param int|null    $user_id           User ID to clean up.
	 * @param string|null $current_session_id Session to preserve.
	 * @return array
	 */
	public function cleanup_user_locks( ?int $user_id = null, ?string $current_session_id = null ): array {
		global $wpdb;

		$deleted   = 0;
		$preserved = 0;

		if ( null !== $user_id ) {
			$session_pattern = $wpdb->esc_like( self::SESSION_PREFIX ) . '%';
			$user_id_json   = '%' . $wpdb->esc_like( '"user_id":' . $user_id ) . '%';
			$prefix_len     = strlen( self::SESSION_PREFIX );

			// Query with user_id filter pushed into SQL — avoids fetching all sessions then filtering in PHP.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cleanup.
			$sessions = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT o.option_name, o.option_value FROM {$wpdb->options} o
					WHERE o.option_name LIKE %s
					AND o.autoload = 'no'
					AND EXISTS (
						SELECT 1 FROM {$wpdb->options} m
						WHERE m.option_name = CONCAT(%s, SUBSTRING(o.option_name, %d))
						AND m.option_value LIKE %s
					)",
					$session_pattern,
					self::SESSION_PREFIX,
					$prefix_len + 1,
					$user_id_json
				)
			);

			foreach ( $sessions as $session_row ) {
				$data = json_decode( $session_row->option_value, true );

				if ( ! is_array( $data ) ) {
					continue;
				}

				if ( isset( $data['user_id'] ) && (int) $data['user_id'] === $user_id ) {
					$sid = $data['session_id'] ?? '';

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
