<?php
/**
 * SScribe Upgrader
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
 * Handles database schema upgrades and migrations.
 */
class SScribe_Upgrader {

	private const SCHEMA_VERSION_OPTION = 'sscribe_schema_version';
	private const FAILURE_COUNT_OPTION  = 'sscribe_upgrade_failures';
	private const NEXT_ATTEMPT_OPTION   = 'sscribe_upgrade_next_attempt';
	private const LAST_ERROR_OPTION     = 'sscribe_upgrade_last_error';

	/**
	 * Check and run any pending database migrations.
	 */
	public static function maybe_upgrade(): void {
		$installed_version = get_option( self::SCHEMA_VERSION_OPTION, '0' );
		$installed_version = is_scalar( $installed_version ) ? (string) $installed_version : '0';

		if ( version_compare( $installed_version, SSCRIBE_VERSION, '>=' ) ) {
			return;
		}

		// Never turn a failed migration into repeated DDL/filesystem work on
		// anonymous frontend traffic. Admin/AJAX/cron/CLI contexts are enough
		// to converge an updated installation before plugin operations proceed.
		if ( ! self::should_attempt_upgrade() ) {
			return;
		}

		$next_attempt = (int) get_option( self::NEXT_ATTEMPT_OPTION, 0 );
		if ( $next_attempt > time() ) {
			return;
		}

		// Honor the legacy transient lock during rolling updates from older builds.
		if ( false !== get_transient( 'sscribe_upgrade_lock' ) ) {
			return;
		}

		$lock_manager = new SScribe_Export_Lock_Manager();
		$lock_name    = 'upgrade';
		$lock_token   = $lock_manager->acquire_lock( $lock_name, 20 * MINUTE_IN_SECONDS, 19 * MINUTE_IN_SECONDS );
		if ( null === $lock_token ) {
			return;
		}

		try {
			try {
				self::run_migrations( $installed_version );

				delete_transient( 'sscribe_admin_page_data_v2_' . $installed_version );
				delete_transient( 'sscribe_wpml_languages' );
				update_option( self::SCHEMA_VERSION_OPTION, SSCRIBE_VERSION, false );
				update_option( 'sscribe_version', SSCRIBE_VERSION, false );
				self::clear_upgrade_failure_state();
			} catch ( \Throwable $e ) {
				self::record_upgrade_failure( $e );
			}
		} finally {
			$lock_manager->release_lock( $lock_name, $lock_token );
		}
	}

	/**
	 * Limit upgrade work to operational contexts that may legitimately mutate
	 * plugin state. WP-CLI is included for explicit maintenance commands.
	 */
	private static function should_attempt_upgrade(): bool {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return true;
		}
		if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
			return true;
		}
		if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
			return true;
		}
		return function_exists( 'is_admin' ) && is_admin();
	}

	/**
	 * Record a failed upgrade and delay the next attempt exponentially.
	 *
	 * The delay starts at one minute and is capped at six hours. The failure
	 * counter is capped so corrupt/stale installations cannot overflow math or
	 * create unbounded option values.
	 *
	 * @param \Throwable $error Upgrade failure being recorded.
	 */
	private static function record_upgrade_failure( \Throwable $error ): void {
		$failures = min( 10, max( 0, (int) get_option( self::FAILURE_COUNT_OPTION, 0 ) ) + 1 );
		$exponent = min( 8, $failures - 1 );
		$delay    = min( 6 * HOUR_IN_SECONDS, MINUTE_IN_SECONDS * ( 2 ** $exponent ) );
		$reference = substr( hash( 'sha256', get_class( $error ) . '|' . $error->getMessage() ), 0, 12 );

		update_option( self::FAILURE_COUNT_OPTION, $failures, false );
		update_option( self::NEXT_ATTEMPT_OPTION, time() + $delay, false );
		update_option(
			self::LAST_ERROR_OPTION,
			array(
				'message'      => 'The database upgrade did not complete and will be retried.',
				'reference'    => $reference,
				'time'         => gmdate( 'Y-m-d H:i:s \U\T\C' ),
				'failures'     => $failures,
				'retry_after'  => $delay,
			),
			false
		);

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'SScribe upgrade error [' . $reference . ']: ' . $error->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug-only error logging for upgrade failures.
		}
	}

	/**
	 * Remove retry/error state after successful convergence.
	 */
	private static function clear_upgrade_failure_state(): void {
		delete_option( self::FAILURE_COUNT_OPTION );
		delete_option( self::NEXT_ATTEMPT_OPTION );
		delete_option( self::LAST_ERROR_OPTION );
	}

	/**
	 * Execute database migrations from a specific version.
	 *
	 * The canonical CREATE TABLE declarations are intentionally replayed
	 * through dbDelta() for every pending plugin upgrade. This avoids
	 * database-engine-specific SHOW/ALTER introspection and keeps MySQL plus
	 * WordPress SQLite Database Integration on one convergence path.
	 *
	 * @param string $from_version Version to migrate from.
	 * @throws \RuntimeException When a required schema helper/change fails.
	 */
	private static function run_migrations( string $from_version ): void {
		// Fresh installs and upgrades must share one dbDelta declaration. Keeping
		// a second CREATE TABLE copy here previously allowed schema drift and
		// reintroduced database-engine-specific migration bugs.
		SScribe_Activator::create_database_tables( false );

		if ( version_compare( $from_version, '1.1.1', '<' ) ) {
			$metrics = get_option( 'sscribe_export_metrics', array() );
			if ( isset( $metrics['formats'] ) && is_array( $metrics['formats'] ) ) {
				$migrated      = false;
				$known_formats = array( 'docx', 'pdf', 'html', 'markdown' );
				foreach ( array_keys( $metrics['formats'] ) as $key ) {
					if ( false === strpos( $key, '_' ) && in_array( $key, $known_formats, true ) ) {
						$new_key = $key . '_page';
						if ( ! isset( $metrics['formats'][ $new_key ] ) ) {
							$metrics['formats'][ $new_key ] = $metrics['formats'][ $key ];
						}
						unset( $metrics['formats'][ $key ] );
						$migrated = true;
					}
				}
				if ( $migrated ) {
					update_option( 'sscribe_export_metrics', $metrics, false );
				}
			}
		}

		if ( version_compare( $from_version, '2.0.0', '<' ) && ! SScribe_Private_Storage::migrate_legacy_storage() ) {
			throw new \RuntimeException( 'Failed while migrating export artifacts to private storage.' );
		}
	}


}
