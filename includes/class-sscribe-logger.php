<?php

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once SSCRIBE_PLUGIN_DIR . 'includes/interfaces/interface-sscribe-logger.php';
require_once SSCRIBE_PLUGIN_DIR . 'includes/traits/trait-sscribe-logger-common.php';

class SScribe_Logger implements SScribe_Logger_Interface {

	use SScribe_Logger_Common;

	private static array $instances = array();

	private static ?string $request_id = null;

	private ?string $session_id = null;

	private array $buffer = array();

	private readonly string $log_dir;

	private bool $shutdown_registered = false;

	public static function instance( bool $enabled = true, string $prefix = 'sscribe', array $options = array() ): SScribe_Logger_Interface {

		$key = $prefix . '_' . ( $enabled ? '1' : '0' ) . '_' . md5( wp_json_encode( $options ) );

		if ( ! isset( self::$instances[ $key ] ) ) {

			$use_enhanced = self::should_use_enhanced();

			if ( $use_enhanced && class_exists( 'SScribe_Logger_Enhanced' ) ) {
				self::$instances[ $key ] = new SScribe_Logger_Enhanced( $options );
			} else {
				self::$instances[ $key ] = new self( $enabled, $prefix );
			}
		}

		return self::$instances[ $key ];
	}

	private static function should_use_enhanced(): bool {
		if ( class_exists( 'QM_Collector' ) && ! ( defined( 'QM_DISABLED' ) && QM_DISABLED ) && is_admin() ) {
			return true;
		}

		if ( defined( 'SSCRIBE_DB_LOGGING' ) && SSCRIBE_DB_LOGGING ) {
			return true;
		}

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'SSCRIBE_DEBUG' ) && SSCRIBE_DEBUG ) {
			return true;
		}

		return false;
	}

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

	public function __destruct() {
		$this->flush();
	}

	private function get_log_file(): string {
		if ( ! file_exists( $this->log_dir ) ) {
			SScribe_Security::protect_directory( $this->log_dir );
		}
		return $this->log_dir . '/' . $this->prefix . '_debug_' . gmdate( 'Y-m-d' ) . '.log';
	}

	public function is_enabled(): bool {
		return $this->enabled;
	}

	public function set_session_id( string $session_id ): void {
		$this->session_id = $session_id;
	}

	public function debug( string $message, array $data = array() ): void {
		$this->log_internal( 'debug', $message, $data );
	}

	public function info( string $message, array $data = array() ): void {
		$this->log_internal( 'info', $message, $data );
	}

	public function notice( string $message, array $data = array() ): void {
		$this->log_internal( 'notice', $message, $data );
	}

	public function warning( string $message, array $data = array() ): void {
		$this->log_internal( 'warning', $message, $data );
	}

	public function error( string $message, array $data = array() ): void {
		$this->log_internal( 'error', $message, $data );
	}

	public function critical( string $message, array $data = array() ): void {
		$this->error( 'CRITICAL: ' . $message, $data );
	}

	public function alert( string $message, array $data = array() ): void {
		$this->log_internal( 'alert', $message, $data );
	}

	public function emergency( string $message, array $data = array() ): void {
		$this->log_internal( 'emergency', $message, $data );
	}

	public function log( string $level, string $message, array $data = array() ): void {
		$this->log_internal( $level, $message, $data );
	}

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

	private static function get_request_id(): string {
		if ( null === self::$request_id ) {

			self::$request_id = substr( md5( microtime( true ) . (string) random_int( 0, PHP_INT_MAX ) ), 0, 12 );
		}

		return self::$request_id;
	}

	public function flush(): void {
		if ( empty( $this->buffer ) || ! $this->enabled ) {
			return;
		}

		$log_file = $this->get_log_file();

		if ( file_exists( $log_file ) && filesize( $log_file ) >= self::MAX_LOG_FILE_SIZE ) {
			$rotated_file = $this->log_dir . '/' . $this->prefix . '_debug_' . gmdate( 'Y-m-d_H-i-s' ) . '.log';
			// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Safe filesystem rename for log rotation.

			rename( $log_file, $rotated_file );

			$warning_entry = sprintf(
				"[%s] [WARNING] Log file exceeded %s bytes — rotated to %s\n",
				gmdate( 'Y-m-d H:i:s' ),
				size_format( self::MAX_LOG_FILE_SIZE ),
				basename( $rotated_file )
			);
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Required for debug logging per plugin requirements.

			file_put_contents( $log_file, $warning_entry, LOCK_EX );
		}

		$content = implode( PHP_EOL, $this->buffer ) . PHP_EOL;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Required for debug logging per plugin requirements.

		$result = file_put_contents( $log_file, $content, FILE_APPEND | LOCK_EX );
		if ( false === $result ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Reporting flush failure when file_put_contents fails; no better alternative in production.

			error_log( 'SScribe_Logger: Failed to flush log to ' . $log_file );
		}

		$this->buffer = array();
	}

	public function get_logs(): array {
		if ( ! $this->enabled ) {
			return array();
		}

		$file_entries = array();
		$log_file     = $this->get_log_file();

		if ( file_exists( $log_file ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Safe filesystem read.

			$contents = file_get_contents( $log_file );
			if ( $contents ) {
				$file_entries = explode( PHP_EOL, trim( $contents ) );
			}
		}

		return array_merge( $file_entries, $this->buffer );
	}

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
