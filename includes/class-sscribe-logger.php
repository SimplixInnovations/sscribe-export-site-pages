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
	 * Current session ID for log context.
	 *
	 * @var string|null
	 */
	private ?string $session_id = null;

	/**
	 * Log message buffer.
	 *
	 * @var array
	 */
	private array $buffer = array();

	/**
	 * Log directory path.
	 *
	 * @var string
	 */
	private readonly string $log_dir;

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
		$effective_enabled = $enabled || self::is_logging_enabled();
		$encoded_options   = wp_json_encode( $options );
		$key               = $prefix . '_' . ( $effective_enabled ? '1' : '0' ) . '_' . md5( false !== $encoded_options ? $encoded_options : '' );

		if ( ! isset( self::$instances[ $key ] ) ) {
			$use_enhanced = self::should_use_enhanced();

			if ( $use_enhanced && class_exists( 'SScribe_Logger_Enhanced' ) ) {
				$options['enabled'] = $effective_enabled;
				$options['prefix']  = $prefix;
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

		if ( ( defined( 'WP_DEBUG' ) && WP_DEBUG && SSCRIBE_DEBUG ) || SScribe_Settings::is_debug_enabled() ) {
			return true;
		}

		return false;
	}

	/**
	 * Constructor.
	 *
	 * @param bool   $enabled Whether logging is enabled.
	 * @param string $prefix  Log file prefix.
	 */
	public function __construct(
		private readonly bool $enabled = true,
		private readonly string $prefix = 'sscribe'
	) {
		$upload_dir    = wp_upload_dir();
		$this->log_dir = $upload_dir['basedir'] . '/sscribe-logs';

		// Always register shutdown hook — flush() gates on empty buffer and $this->enabled internally.
		// This ensures logs are written even if the logger was disabled at construction but
		// became enabled mid-request (e.g., after settings toggle via AJAX).
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
		// Always ensure the log directory is protected, even if it already exists.
		// This handles cases where the directory was created by an older version
		// or without proper protection.
		SScribe_Security::protect_directory( $this->log_dir );
		return $this->log_dir . '/' . $this->prefix . '_debug_' . gmdate( 'Y-m-d' ) . '.log';
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
	 * Set current session ID for log context.
	 *
	 * @param string $session_id Session identifier.
	 */
	public function set_session_id( string $session_id ): void {
		$this->session_id = $session_id;
	}

	/**
	 * Log debug message.
	 *
	 * @param string $message Log message.
	 * @param array  $data     Additional context data.
	 */
	public function debug( string $message, array $data = array() ): void {
		$this->log_internal( 'debug', $message, $data );
	}

	/**
	 * Log info message.
	 *
	 * @param string $message Log message.
	 * @param array  $data     Additional context data.
	 */
	public function info( string $message, array $data = array() ): void {
		$this->log_internal( 'info', $message, $data );
	}

	/**
	 * Log notice message.
	 *
	 * @param string $message Log message.
	 * @param array  $data     Additional context data.
	 */
	public function notice( string $message, array $data = array() ): void {
		$this->log_internal( 'notice', $message, $data );
	}

	/**
	 * Log warning message.
	 *
	 * @param string $message Log message.
	 * @param array  $data     Additional context data.
	 */
	public function warning( string $message, array $data = array() ): void {
		$this->log_internal( 'warning', $message, $data );
	}

	/**
	 * Log error message.
	 *
	 * @param string $message Log message.
	 * @param array  $data     Additional context data.
	 */
	public function error( string $message, array $data = array() ): void {
		$this->log_internal( 'error', $message, $data );
	}

	/**
	 * Log critical message.
	 *
	 * @param string $message Log message.
	 * @param array  $data     Additional context data.
	 */
	public function critical( string $message, array $data = array() ): void {
		$this->log_internal( 'critical', $message, $data );
	}

	/**
	 * Log alert message.
	 *
	 * @param string $message Log message.
	 * @param array  $data     Additional context data.
	 */
	public function alert( string $message, array $data = array() ): void {
		$this->log_internal( 'alert', $message, $data );
	}

	/**
	 * Log emergency message.
	 *
	 * @param string $message Log message.
	 * @param array  $data     Additional context data.
	 */
	public function emergency( string $message, array $data = array() ): void {
		$this->log_internal( 'emergency', $message, $data );
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

		// Apply log level filtering if configured.
		$configured_level = SScribe_Settings::get_debug_log_level();
		if ( 'ALL' !== $configured_level && defined( 'SScribe_Settings::LEVEL_PRIORITY' ) ) {
			$priorities = array(
				'DEBUG'   => 0,
				'INFO'    => 1,
				'NOTICE'  => 2,
				'WARNING' => 3,
				'ERROR'   => 4,
				'CRITICAL' => 5,
			);
			$configured_priority = $priorities[ $configured_level ] ?? 0;
			$entry_priority       = $priorities[ strtoupper( $level ) ] ?? 0;
			if ( $entry_priority < $configured_priority ) {
				return;
			}
		}

		$entry = $this->format_entry( $level, $message, $data );

		$this->buffer[] = $entry;

		// Flush when buffer reaches 50 entries to prevent memory bloat.
		if ( count( $this->buffer ) >= 50 ) {
			$this->flush();
		}

		// Also flush on critical and above to ensure important logs aren't lost.
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
		$data        = array_merge( $this->get_context_enrichment(), $data );
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

		$log_file = $this->get_log_file();
		$content  = implode( PHP_EOL, $this->buffer ) . PHP_EOL;

		// Check file size AFTER content is ready to write, so we account for the actual write size.
		// This prevents writing oversized files when buffer content exceeds the limit.
		$current_size = file_exists( $log_file ) ? filesize( $log_file ) : 0;
		$content_size = strlen( $content );

		if ( $current_size > 0 && ( $current_size + $content_size ) > self::MAX_LOG_FILE_SIZE ) {
			$rotated_file = $this->log_dir . '/' . $this->prefix . '_debug_' . gmdate( 'Y-m-d_H-i-s' ) . '.log';
			$rotated = rename( $log_file, $rotated_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Safe filesystem rename for log rotation.
			if ( $rotated ) {
				$warning_entry = sprintf(
					"[%s] [WARNING] Log file exceeded %s bytes — rotated to %s\n",
					gmdate( 'Y-m-d H:i:s' ),
					size_format( self::MAX_LOG_FILE_SIZE ),
					basename( $rotated_file )
				);
				file_put_contents( $log_file, $warning_entry, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Required for debug logging per plugin requirements.
			}
			// If rename failed (e.g., file locked), fall through — the log entry will be
			// written to the existing file even if it exceeds the size limit.
		}

		$result = file_put_contents( $log_file, $content, FILE_APPEND | LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Required for debug logging per plugin requirements.
		if ( false === $result ) {
			error_log( 'SScribe_Logger: Failed to flush log to ' . $log_file ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Reporting flush failure when file_put_contents fails; no better alternative in production.
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
		// Do NOT gate on $this->enabled — log files may exist from previous sessions
		// where logging was enabled. The debug tab needs to show historical logs even
		// if the logger is currently disabled.

		$file_entries = array();
		$log_file     = $this->get_log_file();

		if ( file_exists( $log_file ) ) {
			if ( $limit > 0 ) {
				// Use SplFileObject to read only the tail of the file without loading
				// the entire file into memory. This prevents OOM on large log files.
				try {
					$file = new SplFileObject( $log_file, 'r' );
					$file->seek( PHP_INT_MAX );
					$total_lines = $file->key();

					$start = $total_lines > $limit ? $total_lines - $limit + 1 : 0;
					$file->seek( $start );

					while ( ! $file->eof() ) {
						$line = $file->current();
						$file->next();
						if ( '' !== trim( $line ) ) {
							$file_entries[] = rtrim( $line, "\r\n" );
						}
					}
					unset( $file );
				} catch ( Exception $e ) {
					// Fallback to full read if SplFileObject fails.
					$contents = file_get_contents( $log_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
					if ( $contents ) {
						$contents       = str_replace( "\r\n", "\n", $contents );
						$contents       = str_replace( "\r", "\n", $contents );
						$file_entries   = explode( "\n", trim( $contents ) );
					}
				}
			} else {
				$contents = file_get_contents( $log_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Safe filesystem read.
				if ( $contents ) {
					$contents = str_replace( "\r\n", "\n", $contents );
					$contents = str_replace( "\r", "\n", $contents );
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
		// Always delete files regardless of enabled state — users expect files gone
		// when they click "Clear Logs", even if logging is currently disabled.
		$upload_dir = wp_upload_dir();
		$log_dir    = $upload_dir['basedir'] . '/sscribe-logs';

		if ( ! is_dir( $log_dir ) ) {
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
		$upload_dir = wp_upload_dir();
		$log_dir    = $upload_dir['basedir'] . '/sscribe-logs';

		if ( ! is_dir( $log_dir ) ) {
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
