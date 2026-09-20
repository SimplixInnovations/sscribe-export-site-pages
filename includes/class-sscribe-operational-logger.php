<?php
/**
 * SScribe Operational Logger
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
 * Always-on operational error channel for SScribe.
 *
 * Distinct from the verbose debug logger: this channel is never gated by
 * SSCRIBE_DEBUG or the user-visible Debug toggle. It only ever accepts
 * ERROR, CRITICAL, and fatal-class events so an operator can answer
 * "what failed on production?" even when verbose debug logging was off
 * at the time of the failure.
 *
 * Storage: a single rolling file under the private SScribe logs directory.
 * The file is rotated when it exceeds {@see self::MAX_FILE_SIZE} bytes and
 * old rotated copies are pruned by {@see self::prune()}.
 */
final class SScribe_Operational_Logger {

	public const LEVEL_ERROR    = 'error';
	public const LEVEL_CRITICAL = 'critical';

	private const ALLOWED_LEVELS = array(
		self::LEVEL_ERROR    => true,
		self::LEVEL_CRITICAL => true,
	);

	public const MAX_FILE_SIZE       = 262144;
	public const RETAIN_ROTATED      = 5;
	public const LOG_FILE_PREFIX     = 'sscribe_ops_';
	public const SUB_DIR             = 'logs';

	/**
	 * Whether the per-request reset hook has been wired in.
	 *
	 * Long-running PHP processes (PHP-FPM, wp-cli) keep static state across
	 * requests. The first call in a new request wires an init hook that
	 * clears the cached buffer so entries from a prior request cannot leak.
	 *
	 * @var bool
	 */
	private static bool $reset_hooked = false;

	/**
	 * Recursion/idempotency guard for the shutdown flush.
	 *
	 * Class-scoped state avoids dynamic globals and keeps Plugin Check's
	 * global-prefix contract intact.
	 *
	 * @var bool
	 */
	private static bool $shutdown_flush_in_progress = false;

	/**
	 * Pending entries written at shutdown.
	 *
	 * @var array<int, string>
	 */
	private static array $buffer = array();

	/**
	 * Reset the in-memory buffer between PHPUnit tests that share a process.
	 *
	 * @return void
	 */
	public static function reset_for_testing(): void {
		self::$buffer = array();
		self::$shutdown_flush_in_progress = false;
	}

	/**
	 * Decode the buffered entries for unit-test assertions.
	 *
	 * Production never calls this. The unit suite uses it to verify
	 * that record() emitted the expected level, message, and context
	 * without having to parse the rotated JSONL log file on disk.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function events_for_testing(): array {
		$decoded = array();
		foreach ( self::$buffer as $line ) {
			$entry = json_decode( (string) $line, true );
			if ( is_array( $entry ) ) {
				$decoded[] = $entry;
			}
		}
		return $decoded;
	}

	/**
	 * Register the per-request reset hook on first call.
	 *
	 * Phase 20: register the SHUTDOWN flush as early as possible so a fatal
	 * error occurring AFTER init still has a durable persistence path.
	 * Previously the init hook was the only safety net — but if init
	 * already passed before the first record, the buffer was never
	 * guaranteed to reach disk before PHP exited.
	 */
	private static function ensure_reset_hooked(): void {
		if ( self::$reset_hooked ) {
			return;
		}
		if ( function_exists( 'add_action' ) ) {
			add_action( 'init', array( self::class, 'flush' ), 0 );
			add_action( 'shutdown', array( self::class, 'flush_on_shutdown' ), PHP_INT_MAX );
		}
		self::$reset_hooked = true;
	}

	/**
	 * Phase 20: shutdown hook that flushes the buffer AND captures
	 * the most recent fatal/parse error if one occurred during the
	 * request. Recursive fatal logging is guarded by a static lock.
	 *
	 * @return void
	 */
	public static function flush_on_shutdown(): void {
		if ( self::$shutdown_flush_in_progress ) {
			return;
		}
		self::$shutdown_flush_in_progress = true;

		// Capture the most recent fatal, if any. error_get_last() can
		// return E_NOTICE / E_WARNING from the live request - filter
		// to the categories that genuinely indicate a fatal.
		$last = function_exists( 'error_get_last' ) ? error_get_last() : null;
		if ( is_array( $last ) && isset( $last['type'] ) ) {
			$fatal_types = array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR );
			if ( in_array( (int) $last['type'], $fatal_types, true ) ) {
				$basename = isset( $last['file'] ) ? basename( (string) $last['file'] ) : '';
				$line     = isset( $last['line'] ) ? (int) $last['line'] : 0;
				$message  = isset( $last['message'] ) ? (string) $last['message'] : 'PHP fatal error';
				self::record(
					self::LEVEL_CRITICAL,
					$message,
					array(
						'category' => 'fatal',
						'file'     => $basename,
						'line'     => $line,
					)
				);
			}
		}

		self::flush();
	}

	/**
	 * Record an operational error.
	 *
	 * @param string $level   One of {@see self::LEVEL_ERROR}, {@see self::LEVEL_CRITICAL}.
	 * @param string $message Short, human-readable description of the failure.
	 * @param array  $context Sanitized context - see class docblock for fields to include.
	 * @return bool True when the entry was accepted, false when dropped (wrong level).
	 */
	public static function record( string $level, string $message, array $context = array() ): bool {
		self::ensure_reset_hooked();

		$level = strtolower( trim( $level ) );
		if ( ! isset( self::ALLOWED_LEVELS[ $level ] ) ) {
			return false;
		}

		$entry = self::build_entry( $level, $message, $context );
		self::$buffer[] = $entry;

		if ( count( self::$buffer ) >= 25 ) {
			self::flush();
		}
		return true;
	}

	/**
	 * Build a sanitized operational record.
	 *
	 * @param string $level   Normalised level.
	 * @param string $message Sanitized message.
	 * @param array  $context Caller-supplied context, sanitized by {@see self::sanitize_context()}.
	 * @return string JSON-encoded log line.
	 */
	private static function build_entry( string $level, string $message, array $context ): string {
		$request_id = '';
		if ( class_exists( 'SScribe_Request_Id' ) ) {
			$request_id = \SScribe_Request_Id::current();
		}

		$base = array(
			'timestamp'   => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'level'       => $level,
			'request_id'  => $request_id,
			'message'     => self::sanitize_message( $message ),
			'php_version' => PHP_VERSION,
		);

		if ( function_exists( 'get_bloginfo' ) ) {
			$wp_version = get_bloginfo( 'version' );
			if ( is_string( $wp_version ) && '' !== $wp_version ) {
				$base['wp_version'] = $wp_version;
			}
		}

		$memory_usage = memory_get_usage( true );
		$memory_peak  = memory_get_peak_usage( true );
		$base['memory_usage'] = (int) $memory_usage;
		$base['memory_peak']  = (int) $memory_peak;

		$merged = array_merge( $base, self::sanitize_context( $context ) );

		return (string) wp_json_encode( $merged, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	/**
	 * Sanitize the human-readable message.
	 *
	 * @param string $message Raw message.
	 * @return string Trimmed, length-bounded, control-stripped message.
	 */
	private static function sanitize_message( string $message ): string {
		$message = trim( $message );
		$message = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $message ) ?? '';
		if ( strlen( $message ) > 280 ) {
			$message = substr( $message, 0, 280 );
		}
		return $message;
	}

	/**
	 * Filter the caller's context to a privacy-safe, bounded set of keys.
	 *
	 * Sensitive keys are stripped. Values are JSON-bounded. No raw POST
	 * payloads, no full request bodies, no absolute private paths.
	 *
	 * @param array $context Raw context.
	 * @return array Sanitized context.
	 */
	public static function sanitize_context( array $context ): array {
		$deny_keys = array(
			'nonce',
			'pass',
			'pwd',
			'auth',
			'request_body',
			'body',
			'post_body',
			'raw_post',
			'payload',
			'file_content',
			'page_content',
			'export_content',
			'raw_html',
		);
		$sensitive_parts = array(
			'password',
			'token',
			'secret',
			'credential',
			'private_key',
			'cookie',
			'bearer',
			'api_key',
			'apikey',
			'access_key',
			'session_key',
			'authorization',
		);

		$allowed = array();
		foreach ( $context as $key => $value ) {
			$key_lc = strtolower( (string) $key );
			$sensitive = in_array( $key_lc, $deny_keys, true );
			if ( ! $sensitive ) {
				foreach ( $sensitive_parts as $part ) {
					if ( str_contains( $key_lc, $part ) ) {
						$sensitive = true;
						break;
					}
				}
			}
			if ( $sensitive ) {
				continue;
			}
			if ( ! preg_match( '/^[a-zA-Z][a-zA-Z0-9_]{0,63}$/', (string) $key ) ) {
				continue;
			}

			if ( is_string( $value ) ) {
				$stripped = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value ) ?? '';
				if ( strlen( $stripped ) > 200 ) {
					$stripped = substr( $stripped, 0, 200 ) . '...';
				}
				$allowed[ $key ] = $stripped;
			} elseif ( is_int( $value ) || is_float( $value ) || is_bool( $value ) ) {
				$allowed[ $key ] = $value;
			} elseif ( is_null( $value ) ) {
				$allowed[ $key ] = null;
			} elseif ( is_array( $value ) ) {
				if ( count( $value ) <= 8 ) {
					$allowed[ $key ] = self::sanitize_context( $value );
				}
			}
		}

		return $allowed;
	}

	/**
	 * Write the pending buffer to private storage, rotating when over size.
	 */
	public static function flush(): void {
		if ( empty( self::$buffer ) ) {
			return;
		}

		$log_file = self::resolve_log_file();
		if ( '' === $log_file ) {
			self::$buffer = array();
			return;
		}

		$payload = implode( "\n", self::$buffer ) . "\n";
		self::$buffer = array();

		if ( file_exists( $log_file ) && filesize( $log_file ) + strlen( $payload ) > self::MAX_FILE_SIZE ) {
			self::rotate( $log_file );
		}

		$written = file_put_contents( $log_file, $payload, FILE_APPEND | LOCK_EX );
		if ( false === $written ) {
			// Operational-logger self-report uses error_log() rather than the
			// plugin's own SScribe_Logger because the failure path itself may
			// have broken the very logger we're trying to report.
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[SSCRIBE][OPS_LOGGER] Failed to append operational record to ' . basename( $log_file ) );
			return;
		}
		// Operational log is owner-read/write only (mode 0600). This is
		// intentional security hardening; shared-host deployments have
		// inherited umask issues that allow group/world reads if not set
		// explicitly.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod
		chmod( $log_file, 0600 );

		self::prune( $log_file );
	}

	/**
	 * Resolve the operational log file path, creating the directory if needed.
	 *
	 * @return string Absolute file path, or empty string when storage is unavailable.
	 */
	private static function resolve_log_file(): string {
		if ( ! class_exists( 'SScribe_Private_Storage' ) ) {
			return '';
		}
		$dir = \SScribe_Private_Storage::get_subdirectory( self::SUB_DIR, true );
		if ( '' === $dir || is_link( $dir ) || ! is_dir( $dir ) ) {
			return '';
		}
		return $dir . '/' . self::LOG_FILE_PREFIX . gmdate( 'Y-m-d' ) . '.log';
	}

	/**
	 * Move the current log file aside under a timestamped name.
	 *
	 * @param string $log_file Current log file.
	 */
	private static function rotate( string $log_file ): void {
		if ( ! file_exists( $log_file ) ) {
			return;
		}
		$rotated = dirname( $log_file ) . '/' . self::LOG_FILE_PREFIX . gmdate( 'Y-m-d_H-i-s' ) . '-' . bin2hex( random_bytes( 3 ) ) . '.log';
		// rename() is atomic on POSIX filesystems (WP_Filesystem::move()
		// is not); the @ suppresses only the rename permission warning.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
		@rename( $log_file, $rotated );
		// Restrict rotated log to owner-only access; see write() above.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod
		@chmod( $rotated, 0600 );
	}

	/**
	 * Remove rotated files older than the newest {@see self::RETAIN_ROTATED}.
	 *
	 * @param string $log_file Current log file.
	 */
	private static function prune( string $log_file ): void {
		$dir = dirname( $log_file );
		$pattern = $dir . '/' . self::LOG_FILE_PREFIX . '*.log';
		$files = glob( $pattern );
		if ( ! is_array( $files ) ) {
			return;
		}

		$rotated = array_values(
			array_filter(
				$files,
				static fn ( string $file ): bool => $file !== $log_file
			)
		);
		if ( count( $rotated ) <= self::RETAIN_ROTATED ) {
			return;
		}

		sort( $rotated );
		$excess = array_slice( $rotated, 0, count( $rotated ) - self::RETAIN_ROTATED );
		foreach ( $excess as $old ) {
			wp_delete_file( $old );
		}
	}
}
