<?php
/**
 * SScribe Logger
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once SSCRIBE_PLUGIN_DIR . 'includes/interfaces/interface-sscribe-logger.php';
require_once SSCRIBE_PLUGIN_DIR . 'includes/traits/trait-sscribe-logger-common.php';

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
	 * Current request ID for log correlation.
	 *
	 * @var string|null
	 */
	private static ?string $request_id = null;

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
	 * Shutdown handler registration flag.
	 *
	 * @var bool
	 */
	private bool $shutdown_registered = false;

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
		$key = $prefix . '_' . ( $effective_enabled ? '1' : '0' ) . '_' . md5( wp_json_encode( $options ) );

		if ( ! isset( self::$instances[ $key ] ) ) {
			$use_enhanced = self::should_use_enhanced();

			if ( $use_enhanced && class_exists( 'SScribe_Logger_Enhanced' ) ) {
				self::$instances[ $key ] = new SScribe_Logger_Enhanced( $options );
			} else {
				self::$instances[ $key ] = new self( $effective_enabled, $prefix );
			}
		}

		return self::$instances[ $key ];
	}

	/**
	 * Check if logging is effectively enabled.
	 *
	 * @return bool True if logging is enabled via constant or settings option.
	 */
	public static function is_logging_enabled(): bool {
		return ( defined( 'SSCRIBE_DEBUG' ) && SSCRIBE_DEBUG ) || SScribe_Settings::is_debug_enabled();
	}

	/**
	 * Determine if enhanced logger should be used.
	 *
	 * @return bool True if enhanced logger should be loaded.
	 */
	private static function should_use_enhanced(): bool {
		if ( class_exists( 'QM_Collector' ) && ! ( defined( 'QM_DISABLED' ) && QM_DISABLED ) && is_admin() ) {
			return true;
		}

		if ( defined( 'SSCRIBE_DB_LOGGING' ) && SSCRIBE_DB_LOGGING ) {
			return true;
		}

		if ( ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'SSCRIBE_DEBUG' ) && SSCRIBE_DEBUG ) || SScribe_Settings::is_debug_enabled() ) {
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

		if ( $this->enabled ) {
			$this->shutdown_registered = true;
			add_action( 'shutdown', array( $this, 'flush' ) );
		}
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
	private function get_log_file(): string {
		if ( ! file_exists( $this->log_dir ) ) {
			SScribe_Security::protect_directory( $this->log_dir );
		}
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
		$this->error( 'CRITICAL: ' . $message, $data );
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
	 * Internal log handler.
	 *
	 * @param string $level   Log level.
	 * @param string $message  Log message.
	 * @param array  $data     Additional context data.
	 */
	private function log_internal( string $level, string $message, array $data = array() ): void {
		if ( ! $this->enabled ) {
			return;
		}

		$data        = array_merge( $this->get_context_enrichment(), $data );
		$timestamp   = gmdate( 'Y-m-d H:i:s' );
		$level_upper = strtoupper( $level );
		$entry       = "[{$timestamp}] [{$level_upper}] {$message}";

		if ( ! empty( $data ) ) {
			$entry .= ' | ' . wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		}

		$this->buffer[] = $entry;
	}

	/**
	 * Get context enrichment data.
	 *
	 * @return array Context data with plugin info.
	 */
	private function get_context_enrichment(): array {
		$context = array(
			'plugin_version' => defined( 'SSCRIBE_VERSION' ) ? (string) SSCRIBE_VERSION : 'unknown',
			'php_version'    => PHP_VERSION,
			'memory_usage'   => size_format( memory_get_usage( true ) ),
			'request_id'     => self::get_request_id(),
		);

		if ( null !== $this->session_id ) {
			$context['session_id'] = $this->session_id;
		}

		return $context;
	}

	/**
	 * Get or generate request ID.
	 *
	 * @return string Request identifier.
	 */
	private static function get_request_id(): string {
		if ( null === self::$request_id ) {
			self::$request_id = substr( md5( microtime( true ) . (string) random_int( 0, PHP_INT_MAX ) ), 0, 12 );
		}

		return self::$request_id;
	}

	/**
	 * Flush log buffer to file.
	 */
	public function flush(): void {
		if ( empty( $this->buffer ) || ! $this->enabled ) {
			return;
		}

		$log_file = $this->get_log_file();

		if ( file_exists( $log_file ) && filesize( $log_file ) >= self::MAX_LOG_FILE_SIZE ) {
			$rotated_file = $this->log_dir . '/' . $this->prefix . '_debug_' . gmdate( 'Y-m-d_H-i-s' ) . '.log';
			rename( $log_file, $rotated_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Safe filesystem rename for log rotation.

			$warning_entry = sprintf(
				"[%s] [WARNING] Log file exceeded %s bytes — rotated to %s\n",
				gmdate( 'Y-m-d H:i:s' ),
				size_format( self::MAX_LOG_FILE_SIZE ),
				basename( $rotated_file )
			);
			file_put_contents( $log_file, $warning_entry, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Required for debug logging per plugin requirements.
		}

		$content = implode( PHP_EOL, $this->buffer ) . PHP_EOL;

		$result = file_put_contents( $log_file, $content, FILE_APPEND | LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Required for debug logging per plugin requirements.
		if ( false === $result ) {
			error_log( 'SScribe_Logger: Failed to flush log to ' . $log_file ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Reporting flush failure when file_put_contents fails; no better alternative in production.
		}

		$this->buffer = array();
	}

	/**
	 * Get all log entries.
	 *
	 * @return array Log entries from file and buffer.
	 */
	public function get_logs(): array {
		if ( ! $this->enabled ) {
			return array();
		}

		$file_entries = array();
		$log_file     = $this->get_log_file();

		if ( file_exists( $log_file ) ) {
			$contents = file_get_contents( $log_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Safe filesystem read.
			if ( $contents ) {
				$file_entries = explode( PHP_EOL, trim( $contents ) );
			}
		}

		return array_merge( $file_entries, $this->buffer );
	}

	/**
	 * Clear log buffer and delete log file.
	 */
	public function clear_logs(): void {
		$this->buffer = array();
		if ( ! $this->enabled ) {
			return;
		}
		$log_file = $this->get_log_file();
		if ( file_exists( $log_file ) ) {
			wp_delete_file( $log_file );
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
					if ( wp_delete_file( $file ) ) {
						++$deleted;
					}
				}
			}
		}

		return $deleted;
	}
}
