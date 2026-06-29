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
	 * Active lock token registered for shutdown cleanup.
	 *
	 * @var string|null
	 */
	private static ?string $shutdown_lock_token = null;

	/**
	 * Active lock session ID registered for shutdown cleanup.
	 *
	 * @var string|null
	 */
	private static ?string $shutdown_session_id = null;

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
	 * Falls back to set_transient() with retry loop for disk-based caching.
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
		$lock_key     = 'sscribe_lock_' . $session_id;
		$lock_token   = wp_generate_password( 32, false );
		$current_time = time();
		$using_cache  = wp_using_ext_object_cache();

		// Check for stale lock first (non-atomic read is acceptable for staleness check).
		$existing_lock = $using_cache
			? wp_cache_get( $lock_key, 'transient' )
			: get_transient( $lock_key );

		if ( false !== $existing_lock && is_string( $existing_lock ) ) {
			$lock_parts = explode( '|', $existing_lock );
			$lock_time  = isset( $lock_parts[0] ) ? (int) $lock_parts[0] : 0;
			$lock_age   = $current_time - $lock_time;

			if ( $lock_age > $stale_threshold ) {
				// Stale lock detected : overwrite and verify ownership.
				$new_value = $current_time . '|' . $lock_token;
				if ( $using_cache ) {
					wp_cache_set( $lock_key, $new_value, 'transient', $lock_ttl );
				} else {
					set_transient( $lock_key, $new_value, $lock_ttl );
				}
				// Re-read to confirm OUR value was stored (reduces race condition window).
				$stored = $using_cache
					? wp_cache_get( $lock_key, 'transient' )
					: get_transient( $lock_key );
				if ( is_string( $stored ) && $stored === $new_value ) {
					$this->logger->debug(
						'Overwrote stale lock',
						array(
							'session_id' => $session_id,
							'lock_age'   => $lock_age,
						)
					);
					return $lock_token;
				}
				// Another process overwrote us : they win.
				$this->logger->debug(
					'Stale lock overwrite lost race',
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

			// Atomic lock acquisition with retry loop.
		for ( $attempt = 1; $attempt <= 3; ++$attempt ) {
			if ( $using_cache ) {
				// wp_cache_add() is atomic : only succeeds if key does not exist.
				if ( wp_cache_add( $lock_key, $current_time . '|' . $lock_token, 'transient', $lock_ttl ) ) {
					$this->register_shutdown_cleanup( $session_id, $lock_token );
					return $lock_token;
				}
			} elseif ( set_transient( $lock_key, $current_time . '|' . $lock_token, $lock_ttl ) ) {
				// Verify we actually own the lock: set_transient() on non-cache
				// backends uses INSERT...ON DUPLICATE KEY UPDATE, which ALWAYS
				// overwrites : so two concurrent processes both get `true`.
				// Re-read to confirm OUR value was stored.
				$stored = get_transient( $lock_key );
				if ( is_string( $stored ) && $stored === $current_time . '|' . $lock_token ) {
					$this->register_shutdown_cleanup( $session_id, $lock_token );
					return $lock_token;
				}
			}

			usleep( 50000 ); // 50 ms delay before retry.
		}

		$this->logger->warning(
			'Lock transient unavailable after 3 attempts, aborting batch',
			array( 'session_id' => $session_id )
		);
		return null;
	}

	/**
	 * Register shutdown handler to release lock on fatal error.
	 *
	 * Uses register_shutdown_function() to ensure the lock is released
	 * if the PHP script terminates unexpectedly (fatal error, timeout, etc.).
	 *
	 * @param string $session_id Session identifier.
	 * @param string $lock_token Lock token to release on shutdown.
	 * @return void
	 */
	private function register_shutdown_cleanup( string $session_id, string $lock_token ): void {
		self::$shutdown_session_id = $session_id;
		self::$shutdown_lock_token = $lock_token;

		register_shutdown_function( array( self::class, 'shutdown_cleanup_handler' ) );
	}

	/**
	 * Shutdown handler : releases lock if script was killed by fatal error.
	 *
	 * This static method is called by register_shutdown_function() when PHP terminates.
	 * It checks whether the script exited normally or due to a fatal error by comparing
	 * the last error against known fatal error patterns.
	 *
	 * Token verification: before deleting the lock we re-read the stored value
	 * and confirm OUR token still owns it. If a different process has since
	 * taken over the lock (e.g. a stale-claim after our TTL expired), we must
	 * NOT delete it : that would wipe the legitimate holder's lock and open
	 * a window for duplicate concurrent processing.
	 *
	 * @return void
	 */
	public static function shutdown_cleanup_handler(): void {
		$session_id = self::$shutdown_session_id;
		$lock_token = self::$shutdown_lock_token;

		// If either is null, no lock was acquired : nothing to clean up.
		if ( null === $session_id || null === $lock_token ) {
			return;
		}

		$lock_key    = 'sscribe_lock_' . $session_id;
		$using_cache = wp_using_ext_object_cache();

		// Verify the stored lock still belongs to THIS shutdown's token before
		// deleting. The lock may have been claimed by a new process if our TTL
		// expired before PHP actually shut down.
		$raw = $using_cache
			? wp_cache_get( $lock_key, 'transient' )
			: get_transient( $lock_key );

		if ( is_string( $raw ) ) {
			$parts  = explode( '|', $raw );
			$stored = $parts[1] ?? '';
			if ( ! hash_equals( $lock_token, $stored ) ) {
				// Different process owns the lock now : leave it alone.
				self::$shutdown_session_id = null;
				self::$shutdown_lock_token = null;
				return;
			}
		}

		// Safe to delete: either the stored value is missing/expired, or it
		// still matches our token. Using both wp_cache_delete (Redis/Memcached)
		// and delete_transient (DB) ensures the lock is cleared regardless of
		// the backend in use.
		if ( $using_cache ) {
			wp_cache_delete( $lock_key, 'transient' );
		}
		delete_transient( $lock_key );

		// Clear the static state so the handler doesn't run again.
		self::$shutdown_session_id = null;
		self::$shutdown_lock_token = null;
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

		$lock_key    = 'sscribe_lock_' . $session_id;
		$using_cache = wp_using_ext_object_cache();

		$raw = $using_cache
			? wp_cache_get( $lock_key, 'transient' )
			: get_transient( $lock_key );

		if ( false === $raw || ! is_string( $raw ) ) {
			return true;
		}

		$parts  = explode( '|', $raw );
		$stored = $parts[1] ?? '';

		if ( ! hash_equals( $lock_token, $stored ) ) {
			return false;
		}

		if ( $using_cache ) {
			wp_cache_delete( $lock_key, 'transient' );
		}
		delete_transient( $lock_key );

		// Clear shutdown handler state since lock was explicitly released.
		if ( self::$shutdown_session_id === $session_id ) {
			self::$shutdown_session_id = null;
			self::$shutdown_lock_token = null;
		}

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
			$user_id_json    = '%' . $wpdb->esc_like( '"user_id":' . $user_id ) . '%';
			$prefix_len      = strlen( self::SESSION_PREFIX );

			// Query with user_id filter pushed into SQL : avoids fetching all sessions then filtering in PHP.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cleanup.
			$sessions = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT o.option_name, o.option_value FROM {$wpdb->options} o
					WHERE o.option_name LIKE %s
					AND o.autoload = 'no'
					AND EXISTS (
						SELECT 1 FROM {$wpdb->options} m
						WHERE m.option_name = CONCAT(%s, SUBSTRING(o.option_name, %d))
						AND m.autoload = 'no'
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
