<?php
/**
 * SScribe Logger
 *
 * @package SScribe_Export_Site_Pages
 * @license GPL v2 or later
 * @link    https://www.gnu.org/licenses/gpl-2.0.html
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once SSCRIBE_PLUGIN_DIR . 'includes/interfaces/interface-sscribe-logger.php';
require_once SSCRIBE_PLUGIN_DIR . 'includes/traits/trait-sscribe-logger-common.php';
require_once SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-settings.php';

/**
 * Main logger implementation for SScribe plugin.
 */
class SScribe_Logger implements SScribe_Logger_Interface {

	use SScribe_Logger_Common;

	/**
	 * Singleton instances storage.
	 *
	 * @var array<string, self>
	 */
	private static array $instances = array();

	/**
	 * Whether the per-request reset hook has been wired in.
	 *
	 * Long-running PHP processes (PHP-FPM, wp-cli daemon mode) keep
	 * static state across requests. The first instance() call in a
	 * new request wires an init hook that resets the singleton so
	 * state from a prior request cannot bleed into the next one.
	 *
	 * @var bool
	 */
	private static bool $request_reset_hooked = false;

	/**
	 * Current session ID for log context.
	 *
	 * @var string|null
	 */
	protected ?string $session_id = null;

	/**
	 * Log message buffer.
	 *
	 * @var array
	 */
	protected array $buffer = array();

	/**
	 * Log directory path.
	 *
	 * @var string
	 */
	protected readonly string $log_dir;

	/**
	 * Whether file logging has a valid private destination.
	 *
	 * @var bool
	 */
	protected readonly bool $storage_available;

	/**
	 * Whether this logger writes files.
	 *
	 * @var bool
	 */
	protected readonly bool $enabled;

	/**
	 * Safe filename prefix.
	 *
	 * @var string
	 */
	protected readonly string $prefix;

	/**
	 * Protected log directories, keyed by canonical directory path.
	 *
	 * @var array<string, bool>
	 */
	private static array $protected_log_dirs = array();

	/**
	 * Log level priority mapping.
	 *
	 * @var array<string, int>
	 */
	private const LEVEL_PRIORITY = array(
		self::LEVEL_DEBUG     => 0,
		self::LEVEL_INFO      => 1,
		self::LEVEL_NOTICE    => 2,
		self::LEVEL_WARNING   => 3,
		self::LEVEL_ERROR     => 4,
		self::LEVEL_CRITICAL  => 5,
		self::LEVEL_ALERT     => 6,
		self::LEVEL_EMERGENCY => 7,
	);

	/**
	 * Get logger instance.
	 *
	 * @param bool   $enabled Whether logging is enabled.
	 * @param string $prefix  Log file prefix.
	 * @param array  $options Logger options.
	 * @return SScribe_Logger_Interface
	 */
	public static function instance( bool $enabled = true, string $prefix = 'sscribe', array $options = array() ): SScribe_Logger_Interface {
		if ( ! self::$request_reset_hooked && function_exists( 'add_action' ) ) {
			add_action( 'init', array( self::class, 'reset_instance' ), 0 );
			self::$request_reset_hooked = true;
		}

		$effective_enabled = $enabled || self::is_logging_enabled();
		$encoded_options   = wp_json_encode( $options );
		$key               = $prefix . '_' . ( $effective_enabled ? '1' : '0' ) . '_' . md5( false !== $encoded_options ? $encoded_options : '' );

		if ( ! isset( self::$instances[ $key ] ) ) {
			$use_enhanced = self::should_use_enhanced();

			if ( $use_enhanced && class_exists( 'SScribe_Logger_Enhanced' ) ) {
				$options['enabled'] = $effective_enabled;
				$options['prefix']  = $prefix;
				if ( defined( 'SSCRIBE_DB_LOGGING' ) && SSCRIBE_DB_LOGGING ) {
					$options['enable_db'] = true;
				}
				self::$instances[ $key ] = new SScribe_Logger_Enhanced( $options );
			} else {
				self::$instances[ $key ] = new self( $effective_enabled, $prefix );
			}

			if ( count( self::$instances ) > 10 ) {
				array_shift( self::$instances );
			}
		}

		return self::$instances[ $key ];
	}

	/**
	 * Reset the singleton instance.
	 *
	 * Use this after changing debug settings to ensure a fresh logger
	 * is created with the updated enabled state.
	 *
	 * @param string $prefix Optional prefix to reset specific instance.
	 */
	public static function reset_instance( string $prefix = '' ): void {
		if ( '' === $prefix ) {
			self::$instances = array();
		} else {
			foreach ( array_keys( self::$instances ) as $key ) {
				if ( str_starts_with( $key, $prefix . '_' ) ) {
					unset( self::$instances[ $key ] );
				}
			}
		}
	}

	/**
	 * Check if logging is effectively enabled.
	 *
	 * @return bool True if logging is enabled via constant or settings option.
	 */
	public static function is_logging_enabled(): bool {
		return ( SSCRIBE_DEBUG ) || SScribe_Settings::is_debug_enabled();
	}

	/**
	 * Determine if enhanced logger should be used.
	 *
	 * @return bool True if enhanced logger should be loaded.
	 */
	private static function should_use_enhanced(): bool {

		if ( class_exists( 'QM_Collector' ) && ! ( defined( 'QM_DISABLED' ) && QM_DISABLED ) && ( is_admin() || wp_doing_ajax() ) ) {
			return true;
		}

		if ( defined( 'SSCRIBE_DB_LOGGING' ) && SSCRIBE_DB_LOGGING ) {
			return true;
		}

		return false;
	}

	/**
	 * Canonical log directory name under the private export root.
	 *
	 * Used by every code path that reads, writes, clears, or cleans up
	 * log files. Single source of truth : keeps cleanup in lockstep
	 * with the directory the logger actually writes to.
	 */
	public const LOG_DIR_NAME = 'logs';

	/**
	 * Construct the logger.
	 *
	 * @param bool   $enabled Whether logging is enabled.
	 * @param string $prefix  Log file prefix.
	 */
	public function __construct( bool $enabled = true, string $prefix = 'sscribe' ) {
		$clean_prefix            = substr( sanitize_key( $prefix ), 0, 40 );
		$this->enabled           = $enabled;
		$this->prefix            = '' !== $clean_prefix ? $clean_prefix : 'sscribe';
		$candidate_dir           = SScribe_Private_Storage::get_subdirectory( self::LOG_DIR_NAME );
		$this->storage_available = '' !== $candidate_dir && ! is_link( $candidate_dir );
		$this->log_dir           = $this->storage_available ? $candidate_dir : '';

		add_action( 'shutdown', array( $this, 'flush' ) );
	}

	/**
	 * Destructor - flushes log buffer.
	 */
	public function __destruct() {
		$this->flush();
	}

	/**
	 * Get log file path.
	 *
	 * @return string Full path to log file.
	 */
	public function get_log_file(): string {
		if ( ! $this->storage_available || is_link( $this->log_dir ) ) {
			return '';
		}

		if ( empty( self::$protected_log_dirs[ $this->log_dir ] ) ) {
			SScribe_Security::protect_directory( $this->log_dir );
			self::$protected_log_dirs[ $this->log_dir ] = true;
		}
		if ( ! is_dir( $this->log_dir ) || is_link( $this->log_dir ) ) {
			return '';
		}
		$log_file = $this->log_dir . '/' . $this->prefix . '_debug_' . gmdate( 'Y-m-d' ) . '.log';
		return is_link( $log_file ) ? '' : $log_file;
	}

	/**
	 * Check if logger is enabled.
	 *
	 * @return bool
	 */
	public function is_enabled(): bool {
		return $this->enabled;
	}

	/**
	 * Generic log method.
	 *
	 * @param string $level   Log level.
	 * @param string $message  Log message.
	 * @param array  $data     Additional context data.
	 */
	public function log( string $level, string $message, array $data = array() ): void {
		$this->log_internal( $level, $message, $data );
	}

	/**
	 * Internal logging method with buffering.
	 *
	 * @param string $level   Log level.
	 * @param string $message Log message.
	 * @param array  $data    Additional context data.
	 */
	private function log_internal( string $level, string $message, array $data = array() ): void {
		if ( ! $this->is_enabled() ) {
			return;
		}

		$level = strtolower( $level );
		if ( ! isset( self::LEVEL_PRIORITY[ $level ] ) ) {
			$level = self::LEVEL_INFO;
		}

		$configured_level = SScribe_Settings::get_debug_log_level();
		if ( 'ALL' !== $configured_level ) {
			$priorities          = array(
				'DEBUG'    => 0,
				'INFO'     => 1,
				'NOTICE'   => 2,
				'WARNING'  => 3,
				'ERROR'    => 4,
				'CRITICAL' => 5,
			);
			$configured_priority = $priorities[ $configured_level ] ?? 0;
			$entry_priority      = $priorities[ strtoupper( $level ) ] ?? 0;
			if ( $entry_priority < $configured_priority ) {
				return;
			}
		}

		$entry = $this->format_entry( $level, $message, $data );

		$this->buffer[] = $entry;

		if ( count( $this->buffer ) >= 50 ) {
			$this->flush();
		}

		if ( self::LEVEL_PRIORITY[ $level ] >= self::LEVEL_PRIORITY[ self::LEVEL_CRITICAL ] ) {
			$this->flush();
		}
	}

	/**
	 * Format log entry.
	 *
	 * @param string $level   Log level.
	 * @param string $message Log message.
	 * @param array  $data    Additional context data.
	 * @return string Formatted log entry.
	 */
	private function format_entry( string $level, string $message, array $data = array() ): string {
		$message     = $this->sanitize_log_message( $message );
		$data        = $this->sanitize_log_context( array_merge( $this->get_context_enrichment(), $data ) );
		$timestamp   = gmdate( 'Y-m-d H:i:s' );
		$level_upper = strtoupper( $level );
		$entry       = "[{$timestamp}] [{$level_upper}] {$message}";

		if ( ! empty( $data ) ) {
			$entry .= ' | ' . wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		}

		return $entry;
	}

	/**
	 * Flush log buffer to file.
	 */
	public function flush(): void {
		if ( empty( $this->buffer ) || ! $this->enabled ) {
			return;
		}
		if ( ! $this->storage_available ) {
			$this->buffer = array();
			return;
		}

		$log_file = $this->get_log_file();
		if ( '' === $log_file ) {
			$this->buffer = array();
			return;
		}
		$content  = implode( PHP_EOL, $this->buffer ) . PHP_EOL;

		$current_size = file_exists( $log_file ) ? filesize( $log_file ) : 0;
		$content_size = strlen( $content );

		if ( $current_size > 0 && ( $current_size + $content_size ) > self::MAX_LOG_FILE_SIZE ) {
			$rotated_file = $this->log_dir . '/' . $this->prefix . '_debug_' . gmdate( 'Y-m-d_H-i-s' ) . '-' . bin2hex( random_bytes( 4 ) ) . '.log';
			$rotated      = rename( $log_file, $rotated_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Safe filesystem rename for log rotation.
			if ( $rotated ) {
				$warning_entry = sprintf(
					"[%s] [WARNING] Log file exceeded %s bytes : rotated to %s\n",
					gmdate( 'Y-m-d H:i:s' ),
					size_format( self::MAX_LOG_FILE_SIZE ),
					basename( $rotated_file )
				);
				file_put_contents( $log_file, $warning_entry, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Required for debug logging per plugin requirements.
				chmod( $log_file, 0600 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Setting 0600 for log file security.
			}
		}

		$result = file_put_contents( $log_file, $content, FILE_APPEND | LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Required for debug logging per plugin requirements.
		if ( false === $result ) {
			error_log( 'SScribe_Logger: Failed to flush log to ' . $log_file ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Reporting flush failure when file_put_contents fails; no better alternative in production.
		}

		if ( false !== $result ) {
			chmod( $log_file, 0600 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Setting 0600 for log file security; only effective on Unix-like systems where debug logs are stored.
		}

		$this->buffer = array();
	}

	/**
	 * Get all log entries.
	 *
	 * @param int $limit Maximum number of lines to return (from tail). -1 for all.
	 * @return array Log entries from file and buffer.
	 */
	public function get_logs( int $limit = -1 ): array {
		if ( ! $this->storage_available ) {
			return $limit > 0 ? array_slice( $this->buffer, -$limit ) : $this->buffer;
		}

		$file_entries = array();
		$log_file     = $this->get_log_file();

		if ( '' !== $log_file && is_file( $log_file ) && ! is_link( $log_file ) ) {
			if ( $limit > 0 ) {

				try {
					$file = new SplFileObject( $log_file, 'r' );
					$file->seek( PHP_INT_MAX );
					$total_lines = $file->key();

					$start = $total_lines > $limit ? $total_lines - $limit : 0;
					$file->seek( $start );

					while ( ! $file->eof() ) {
						$line = $file->current();
						$file->next();
						if ( is_string( $line ) && '' !== trim( $line ) ) {
							$file_entries[] = rtrim( $line, "\r\n" );
						}
					}
					unset( $file );
				} catch ( Exception $e ) {

					$contents = file_get_contents( $log_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
					if ( $contents ) {
						$contents     = str_replace( "\r\n", "\n", $contents );
						$contents     = str_replace( "\r", "\n", $contents );
						$file_entries = explode( "\n", trim( $contents ) );

						if ( count( $file_entries ) > $limit ) {
							$file_entries = array_slice( $file_entries, -$limit );
						}
					}
				}
			} else {
				$contents = file_get_contents( $log_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Safe filesystem read.
				if ( $contents ) {
					$contents     = str_replace( "\r\n", "\n", $contents );
					$contents     = str_replace( "\r", "\n", $contents );
					$file_entries = explode( "\n", trim( $contents ) );
				}
			}
		}

		$all_entries = array_merge( $file_entries, $this->buffer );

		if ( $limit > 0 && count( $all_entries ) > $limit ) {
			return array_slice( $all_entries, -$limit );
		}

		return $all_entries;
	}

	/**
	 * Clear log buffer and delete all log files (including rotated).
	 */
	public function clear_logs(): void {
		$this->buffer = array();

		$log_dir = SScribe_Private_Storage::get_subdirectory( self::LOG_DIR_NAME, false );

		if ( ! is_dir( $log_dir ) || is_link( $log_dir ) ) {
			return;
		}

		$files = glob( $log_dir . '/' . $this->prefix . '_debug_*.log' );
		if ( is_array( $files ) ) {
			foreach ( $files as $file ) {
				if ( file_exists( $file ) ) {
					wp_delete_file( $file );
				}
			}
		}
	}

	/**
	 * Clean up log files older than specified days.
	 *
	 * @param int $max_age_days Maximum age in days.
	 * @return int Number of files deleted.
	 */
	public static function cleanup_old_logs( int $max_age_days = 7 ): int {
		$log_dir = SScribe_Private_Storage::get_subdirectory( self::LOG_DIR_NAME, false );

		if ( ! is_dir( $log_dir ) || is_link( $log_dir ) ) {
			return 0;
		}

		$files   = glob( $log_dir . '/*_debug_*.log' );
		$deleted = 0;
		$max_age = $max_age_days * DAY_IN_SECONDS;
		$now     = time();

		if ( is_array( $files ) ) {
			foreach ( $files as $file ) {
				$file_time = filemtime( $file );
				if ( $file_time && ( $now - $file_time ) > $max_age ) {
					wp_delete_file( $file );
					if ( ! file_exists( $file ) ) {
						++$deleted;
					}
				}
			}
		}

		return $deleted;
	}
}
