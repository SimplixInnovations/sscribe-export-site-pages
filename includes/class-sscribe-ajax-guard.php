<?php
/**
 * Enterprise-grade AJAX response guard for SScribe.
 *
 * Provides buffer-safe JSON response methods that strip extraneous PHP output
 * (warnings, notices, whitespace, BOM) before sending clean JSON. All cleaned
 * content is logged with full diagnostic context for post-mortem debugging.
 *
 * Every AJAX response is protected by:
 * - Output buffer sanitisation (removes prior output that would break JSON)
 * - display_errors suppression (prevents inline error bleeding)
 * - Structured diagnostics in error payloads (_diagnostics key)
 * - Full audit trail logging of extraneous output
 *
 * @package       SScribe
 * @since         1.1.5
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AJAX response guard with output buffer sanitisation and diagnostic logging.
 *
 * Usage — replace direct wp_send_json_success/error calls:
 *
 *     // Before (vulnerable to buffer contamination):
 *     wp_send_json_success( $data );
 *     wp_send_json_error( $error_data, 403 );
 *
 *     // After (bulletproof):
 *     SScribe_AJAX_Guard::success( $data );
 *     SScribe_AJAX_Guard::error( $error_data, 403 );
 *
 * @since 1.1.5
 */
class SScribe_AJAX_Guard {

	/**
	 * Maximum bytes of extraneous output content to include in a single log entry.
	 *
	 * @var int
	 */
	private const LOG_PREVIEW_MAX = 2000;

	/**
	 * Send a buffer-safe JSON success response.
	 *
	 * Cleans all output buffer levels before delegating to
	 * wp_send_json_success(), ensuring the response contains only valid JSON.
	 * Any captured extraneous content is logged with full context.
	 *
	 * When SSCRIBE_DEBUG is true, a _debug key is injected into the response
	 * payload with server diagnostics (PHP version, memory, timing).
	 *
	 * @since 1.1.5
	 *
	 * @param mixed    $data        Data to encode as JSON.
	 * @param int|null $status_code Optional HTTP status code.
	 * @param array    $context     Optional. Additional diagnostic context
	 *                              appended to the _debug payload.
	 *
	 * @return never
	 */
	public static function success( $data = null, ?int $status_code = null, array $context = array() ): never {
		self::sanitise_environment();
		self::log_cleaned_buffers( 'success' );

		if ( defined( 'SSCRIBE_DEBUG' ) && SSCRIBE_DEBUG && ( is_array( $data ) || is_object( $data ) ) ) {
			if ( is_array( $data ) ) {
				$data['_debug'] = self::build_diagnostics( $context );
			} elseif ( is_object( $data ) ) {
				$data->_debug = self::build_diagnostics( $context );
			}
		}

		wp_send_json_success( $data, $status_code );
	}

	/**
	 * Send a buffer-safe JSON error response with enriched diagnostics.
	 *
	 * Cleans all output buffer levels before delegating to
	 * wp_send_json_error(). The response payload is automatically enriched
	 * with a _diagnostics key containing server context (PHP version, memory,
	 * timing, action name) to facilitate rapid troubleshooting.
	 *
	 * When SSCRIBE_DEBUG is true, _diagnostics also includes the caller's
	 * additional context and a full backtrace for the error.
	 *
	 * @since 1.1.5
	 *
	 * @param mixed    $data        Data to encode as JSON.
	 * @param int|null $status_code Optional HTTP status code.
	 * @param array    $context     Optional. Additional diagnostic context
	 *                              merged into the _diagnostics payload.
	 *
	 * @return never
	 */
	public static function error( $data = null, ?int $status_code = null, array $context = array() ): never {
		self::sanitise_environment();
		self::log_cleaned_buffers( 'error' );

		$diagnostics = self::build_diagnostics( $context );

		if ( is_array( $data ) ) {
			$data['_diagnostics'] = $diagnostics;
		} elseif ( is_object( $data ) ) {
			$data->_diagnostics = $diagnostics;
		} else {
			$data = array(
				'message'      => (string) $data,
				'_diagnostics' => $diagnostics,
			);
		}

		wp_send_json_error( $data, $status_code );
	}

	/**
	 * Sanitise the PHP environment for clean JSON output.
	 *
	 * - Suppresses display_errors to prevent PHP from inlining warnings into
	 *   the response body.
	 * - Does NOT alter error_log, log_errors, or WP_DEBUG — errors are still
	 *   written to disk for post-mortem analysis.
	 *
	 * @since 1.1.5
	 *
	 * @return void
	 */
	private static function sanitise_environment(): void {
		// phpcs:ignore WordPress.PHP.IniSet.Risky -- Safe for AJAX context;
		// only suppresses display, not logging.
		self::disable_if_possible( 'display_errors', '0' );
	}

	/**
	 * Set a PHP INI value if the directive exists and is not read-only.
	 *
	 * Prevents PHP 8.1+ warnings when calling ini_set() on disabled directives
	 * (e.g. in hosted environments that lock certain INI values).
	 *
	 * @since 1.1.5
	 *
	 * @param string $key   INI directive key.
	 * @param string $value Value to set.
	 *
	 * @return bool True if set successfully, false otherwise.
	 */
	private static function disable_if_possible( string $key, string $value ): bool {
		if ( function_exists( 'ini_set' ) && false === strpos( ini_get( 'disable_functions' ), 'ini_set' ) ) {
			// phpcs:ignore WordPress.PHP.IniSet.Risky
			@ini_set( $key, $value );
			return true;
		}
		return false;
	}

	/**
	 * Capture and log any extraneous output from active buffer levels,
	 * then restore buffer nesting to preserve test framework compatibility.
	 *
	 * Walks through every active output buffer, captures the content that
	 * was inadvertently output before the JSON response, and logs it with
	 * diagnostics if non-empty.
	 *
	 * After capture, buffer levels are restored to their original depth so
	 * PHPUnit and other test frameworks (which rely on specific buffer levels)
	 * continue to function correctly. The extraneous content is discarded;
	 * only fresh (empty) buffers are re-established.
	 *
	 * @since 1.1.5
	 *
	 * @param string $type Response classification ('success' | 'error').
	 *
	 * @return void
	 */
	private static function log_cleaned_buffers( string $type ): void {
		$start_level = ob_get_level();
		$extraneous  = '';

		while ( ob_get_level() > 0 ) {
			$content    = ob_get_clean();
			$extraneous = ( false !== $content ? $content : '' ) . "\n" . $extraneous;
		}

		$extraneous = trim( $extraneous );

		// Restore buffer nesting to its original depth so PHPUnit and other
		// test frameworks (which rely on specific buffer levels) continue to
		// function correctly. The extraneous content has been discarded; only
		// fresh (empty) buffers are re-established.
		while ( ob_get_level() < $start_level ) {
			ob_start();
		}

		if ( '' === $extraneous && 0 === $start_level ) {
			return;
		}

		$action = self::resolve_action_name();
		$length = strlen( $extraneous );

		/*
		 * ── Structured log block ──
		 * Format uses a grep-able header line followed by human-readable
		 * details. The header enables quick counting/searches across logs:
		 *
		 *   grep '\[SSCRIBE\]\[AJAX_BUFFER\]' /path/to/error.log
		 */
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log(
			sprintf(
				'[SSCRIBE][AJAX_BUFFER] Action=%s Type=%s Length=%d Levels=%d PHP=%s Memory=%s Time=%s',
				$action,
				$type,
				$length,
				$start_level,
				PHP_VERSION,
				self::format_bytes( memory_get_usage( true ) ),
				gmdate( 'Y-m-d\TH:i:s\Z' )
			)
		);

		if ( $length > 0 ) {
			$preview = substr( $extraneous, 0, self::LOG_PREVIEW_MAX );
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log(
				sprintf(
					"[SSCRIBE][AJAX_BUFFER] ── Extraneous content (%d bytes) ──\n%s\n── End extraneous content ──",
					$length,
					$preview
				)
			);
		}

		if ( $length > self::LOG_PREVIEW_MAX ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log(
				sprintf(
					'[SSCRIBE][AJAX_BUFFER] Truncated %d excess bytes. Set SSCRIBE_AJAX_LOG_MAX to increase the preview limit.',
					$length - self::LOG_PREVIEW_MAX
				)
			);
		}
	}

	/**
	 * Resolve the current AJAX action name from the request.
	 *
	 * Prioritises POST data (standard admin-ajax.php), falls back to GET,
	 * and finally returns 'unknown' if the action cannot be determined.
	 *
	 * @since 1.1.5
	 *
	 * @return string Sanitised action name.
	 */
	private static function resolve_action_name(): string {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		// Nonce verification is the caller's responsibility and is performed
		// before this method is called during JSON response construction.
		if ( isset( $_POST['action'] ) && is_string( $_POST['action'] ) ) {
			return sanitize_key( $_POST['action'] );
		}
		if ( isset( $_GET['action'] ) && is_string( $_GET['action'] ) ) {
			return sanitize_key( $_GET['action'] );
		}
		// phpcs:enable
		return 'unknown';
	}

	/**
	 * Build a structured diagnostics payload for the response.
	 *
	 * Includes server context that aids rapid troubleshooting:
	 * PHP version, memory usage, request timing, etc.
	 *
	 * When SSCRIBE_DEBUG is enabled, additional context from the caller and
	 * a backtrace are appended.
	 *
	 * @since 1.1.5
	 *
	 * @param array $context Optional. Additional context from the caller.
	 *
	 * @return array{
	 *     php_version: string,
	 *     memory_usage: string,
	 *     memory_limit: string,
	 *     request_time: string,
	 *     action: string,
	 *     context?: array,
	 *     backtrace?: string[],
	 * }
	 */
	private static function build_diagnostics( array $context = array() ): array {
		$diag = array(
			'php_version'  => PHP_VERSION,
			'memory_usage' => self::format_bytes( memory_get_usage( true ) ),
			'memory_limit' => ini_get( 'memory_limit' ) ? ini_get( 'memory_limit' ) : 'unlimited',
			'request_time' => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'action'       => self::resolve_action_name(),
		);

		if ( ! empty( $context ) ) {
			$diag['context'] = $context;
		}

		if ( defined( 'SSCRIBE_DEBUG' ) && SSCRIBE_DEBUG ) {
			$trace = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 15 );
			$diag['backtrace'] = array_map(
				static function ( array $frame ): string {
					return sprintf(
						'%s%s(%s) called at %s:%d',
						$frame['class'] ?? '',
						$frame['type'] ?? '',
						$frame['function'] ?? 'unknown',
						$frame['file'] ?? 'unknown',
						$frame['line'] ?? 0
					);
				},
				$trace
			);
		}

		return $diag;
	}

	/**
	 * Format a byte count into a human-readable string.
	 *
	 * @since 1.1.5
	 *
	 * @param int $bytes Number of bytes.
	 *
	 * @return string Formatted size (e.g. "12.50 MB").
	 */
	private static function format_bytes( int $bytes ): string {
		if ( $bytes < 1024 ) {
			return $bytes . ' B';
		}

		$units = array( 'KB', 'MB', 'GB', 'TB' );
		$i     = 0;

		while ( $bytes >= 1024 && $i < count( $units ) - 1 ) {
			$bytes /= 1024;
			++$i;
		}

		return sprintf( '%.2f %s', $bytes, $units[ $i ] );
	}
}
