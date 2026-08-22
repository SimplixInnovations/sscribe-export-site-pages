<?php
/**
 * SScribe Export Lock Manager
 *
 * @package SScribe_Export_Site_Pages
 * @license GPL v2 or later
 * @link    https://www.gnu.org/licenses/gpl-2.0.html
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages atomic locks for concurrent export prevention.
 */
class SScribe_Export_Lock_Manager {
	/**
	 * Prefix for database-backed locks when no persistent object cache exists.
	 */
	private const OPTION_PREFIX = 'sscribe_export_lock_';

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
	 * Uses add_option() for atomic database-backed acquisition. Keeping one
	 * authoritative store also permits release through a conditional delete,
	 * so an expired owner cannot delete a successor's lock.
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
		$sanitized = sanitize_key( $session_id );
		if ( '' === $sanitized || strlen( $sanitized ) > 64 || ! hash_equals( $session_id, $sanitized ) ) {
			return null;
		}
		$session_id = $sanitized;
		$lock_ttl        = max( 5, min( 1800, $lock_ttl ) );
		$stale_threshold = max( 1, min( $lock_ttl, $stale_threshold ) );

		$lock_key      = 'sscribe_lock_' . $session_id;
		$option_key    = self::OPTION_PREFIX . $session_id;
		$lock_token    = wp_generate_password( 32, false );
		$current_time  = time();
		$lock_value    = $current_time . '|' . $lock_token . '|' . ( $current_time + $lock_ttl );
		$existing_lock = get_option( $option_key, false );
		$is_legacy     = false;
		if ( false === $existing_lock ) {
			$existing_lock = get_transient( $lock_key );
			$is_legacy     = false !== $existing_lock;
		}

		if ( false !== $existing_lock ) {
			$lock_parts = is_string( $existing_lock ) ? explode( '|', $existing_lock, 3 ) : array();
			$lock_time  = isset( $lock_parts[0] ) && ctype_digit( $lock_parts[0] ) ? (int) $lock_parts[0] : 0;
			$existing_token = isset( $lock_parts[1] ) ? trim( $lock_parts[1] ) : '';
			$expires        = isset( $lock_parts[2] ) && ctype_digit( $lock_parts[2] ) ? (int) $lock_parts[2] : 0;
			$lock_age       = $current_time - $lock_time;
			$is_expired     = $expires > 0
				? $expires <= $current_time
				: $lock_age > $stale_threshold;

			if ( 0 === $lock_time || '' === $existing_token || $is_expired || $lock_age < -300 ) {
				if ( $is_legacy ) {
					delete_transient( $lock_key );
				} elseif ( ! is_string( $existing_lock ) || ! $this->delete_owned_option_lock( $option_key, $existing_lock ) ) {
					$this->logger->debug(
						'Stale lock changed before reclamation; acquisition aborted',
						array( 'session_id' => $session_id )
					);
					return null;
				}
				delete_transient( $lock_key );
				$this->logger->debug(
					'Removed stale lock before atomic reacquisition',
					array(
						'session_id' => $session_id,
						'lock_age'   => $lock_age,
					)
				);
			} else {
				$this->logger->debug(
					'Batch is already processing concurrently',
					array( 'session_id' => $session_id )
				);
				return null;
			}
		}

		for ( $attempt = 1; $attempt <= 3; ++$attempt ) {
			if ( add_option( $option_key, $lock_value, '', false ) ) {
				delete_transient( $lock_key );
				$stored = get_option( $option_key, false );
				if ( is_string( $stored ) && hash_equals( $lock_value, $stored ) ) {
					return $lock_token;
				}
			}

			usleep( 50000 );
		}

		$this->logger->warning(
			'Export lock unavailable after 3 attempts, aborting batch',
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

		$sanitized = sanitize_key( $session_id );
		if ( '' === $sanitized || ! hash_equals( $session_id, $sanitized ) ) {
			return false;
		}

		$lock_key   = 'sscribe_lock_' . $sanitized;
		$option_key = self::OPTION_PREFIX . $sanitized;
		$raw        = get_option( $option_key, false );
		$is_legacy  = false;
		if ( false === $raw ) {
			$raw = get_transient( $lock_key );
			$is_legacy = false !== $raw;
		}

		if ( false === $raw || ! is_string( $raw ) ) {
			return true;
		}

		$parts  = explode( '|', $raw );
		$stored = $parts[1] ?? '';

		if ( ! hash_equals( $lock_token, $stored ) ) {
			return false;
		}

		if ( $is_legacy ) {
			delete_transient( $lock_key );
			return true;
		}

		if ( ! $this->delete_owned_option_lock( $option_key, $raw ) ) {
			return false;
		}

		delete_transient( $lock_key );
		return true;
	}

	/**
	 * Delete a database-backed lock only if its complete observed value still
	 * owns the row. This keeps both stale reclamation and release from deleting
	 * a successor acquired between observation and deletion.
	 *
	 * @param string $option_key Lock option name.
	 * @param string $lock_value Complete observed lock value.
	 * @return bool Whether the exact owned row was deleted.
	 */
	private function delete_owned_option_lock( string $option_key, string $lock_value ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Ownership-conditional deletion cannot be expressed through delete_option().
		$deleted = $wpdb->delete(
			$wpdb->options,
			array(
				'option_name'  => $option_key,
				'option_value' => $lock_value,
			),
			array( '%s', '%s' )
		);
		wp_cache_delete( $option_key, 'options' );

		return 1 === $deleted;
	}

	/**
	 * Remove a session lock during an explicit session cleanup.
	 *
	 * This deliberately bypasses token ownership and must only be used after
	 * the corresponding session has been selected for deletion by trusted code.
	 *
	 * @param string $session_id Session identifier.
	 * @return bool Whether the identifier was valid and cleanup was attempted.
	 */
	public function discard_lock( string $session_id ): bool {
		$sanitized = sanitize_key( $session_id );
		if ( '' === $sanitized || ! hash_equals( $session_id, $sanitized ) ) {
			return false;
		}

		$lock_key = 'sscribe_lock_' . $sanitized;
		delete_option( self::OPTION_PREFIX . $sanitized );
		delete_transient( $lock_key );

		return true;
	}

	/**
	 * Clean up expired database-backed locks.
	 *
	 * Persistent object-cache locks expire through their cache TTL and are not
	 * stored in the options table.
	 *
	 * @return int Number of expired locks deleted.
	 */
	public function cleanup_expired_locks(): int {
		global $wpdb;

		$now                  = time();
		$lock_timeout_pattern = $wpdb->esc_like( '_transient_timeout_sscribe_lock_' ) . '%';
		$option_pattern       = $wpdb->esc_like( self::OPTION_PREFIX ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded cleanup of expired plugin-owned transient rows.
		$expired_locks = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d",
				$lock_timeout_pattern,
				$now
			)
		);

		$deleted = 0;
		foreach ( $expired_locks as $expired ) {
			$session_id = str_replace( '_transient_timeout_sscribe_lock_', '', $expired->option_name );
			if ( $this->discard_lock( $session_id ) ) {
				++$deleted;
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded cleanup of plugin-owned option locks.
		$database_locks = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_name ASC LIMIT %d",
				$option_pattern,
				500
			)
		);
		foreach ( (array) $database_locks as $lock ) {
			$option_name = (string) ( $lock->option_name ?? '' );
			$session_id  = substr( $option_name, strlen( self::OPTION_PREFIX ) );
			if ( '' === $session_id || self::OPTION_PREFIX . sanitize_key( $session_id ) !== $option_name ) {
				continue;
			}

			$parts     = explode( '|', (string) ( $lock->option_value ?? '' ), 3 );
			$lock_time = isset( $parts[0] ) && ctype_digit( $parts[0] ) ? (int) $parts[0] : 0;
			$expires   = isset( $parts[2] ) && ctype_digit( $parts[2] ) ? (int) $parts[2] : 0;
			if ( 0 === $lock_time || ( $expires > 0 ? $expires <= $now : $now - $lock_time > 600 ) || $now - $lock_time < -300 ) {
				if ( delete_option( $option_name ) ) {
					++$deleted;
				}
			}
		}

		return $deleted;
	}
}
