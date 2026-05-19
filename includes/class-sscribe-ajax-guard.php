<?php
/**
 * Enterprise-grade AJAX response guard for SScribe.
 *
 * Provides buffer-safe JSON response methods that strip extraneous PHP output
 * (warnings, notices, whitespace, BOM) before sending clean JSON. All cleaned
 * content is logged with full diagnostic context for post-mortem debugging.
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
	 * @param mixed    $data        Data to encode as JSON.
	 * @param int|null $status_code Optional HTTP status code.
	 * @param array    $context     Optional. Additional diagnostic context.
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
	 * @param mixed    $data        Data to encode as JSON.
	 * @param int|null $status_code Optional HTTP status code.
	 * @param array    $context     Additional diagnostic context merged into the _diagnostics payload.
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
	 */
	private static function sanitise_environment(): void {
		// phpcs:ignore WordPress.PHP.IniSet.Risky -- Safe for AJAX context;
		// only suppresses display, not logging.
		self::disable_if_possible( 'display_errors', '0' );
	}

	/**
	 * Set a PHP INI value if the directive exists and is not read-only.
	 *
	 * @param string $key   INI directive key.
	 * @param string $value Value to set.
	 *
	 * @return bool True if set successfully.
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
	 * @param string $type Response classification ('success' | 'error').
	 */
	private static function log_cleaned_buffers( string $type ): void {
		$start_level = ob_get_level();
		$extraneous  = '';

		while ( ob_get_level() > 0 ) {
			$content    = ob_get_clean();
			$extraneous = ( false !== $content ? $content : '' ) . "\n" . $extraneous;
		}

		$extraneous = trim( $extraneous );

		while ( ob_get_level() < $start_level ) {
			ob_start();
		}

		if ( '' === $extraneous && 0 === $start_level ) {
			return;
		}

		$action = self::resolve_action_name();
		$length = strlen( $extraneous );

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
	 * @return string Sanitised action name, or 'unknown'.
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
