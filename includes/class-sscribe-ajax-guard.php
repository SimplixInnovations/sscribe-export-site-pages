<?php
/**
 * SScribe AJAX Guard
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Guard for sanitizing AJAX responses and cleaning output buffers.
 */
class SScribe_AJAX_Guard {

	private const LOG_PREVIEW_MAX = 2000;

	/**
	 * Send a successful AJAX response.
	 *
	 * @param mixed $data       Response data.
	 * @param int   $status_code HTTP status code.
	 * @param array $context     Additional context for diagnostics.
	 * @return never Never returns.
	 */
	/**
	 * Send a successful JSON response and terminate.
	 *
	 * @param mixed $data       Response data.
	 * @param int   $status_code HTTP status code.
	 * @param array $context    Additional context for diagnostics.
	 * @return never Never returns.
	 */
	public static function success( mixed $data = null, ?int $status_code = null, array $context = array() ): never {
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
		exit;
	}

	/**
	 * Send an error AJAX response.
	 *
	 * @param mixed $data       Response data or error message.
	 * @param int   $status_code HTTP status code.
	 * @param array $context     Additional context for diagnostics.
	 * @return never Never returns.
	 */
	public static function error( mixed $data = null, ?int $status_code = null, array $context = array() ): never {
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
		exit;
	}

	/**
	 * Sanitize PHP environment for AJAX responses.
	 */
	private static function sanitise_environment(): void {
		// phpcs:ignore WordPress.PHP.IniSet.Risky

		self::disable_if_possible( 'display_errors', '0' );
	}

	/**
	 * Attempt to disable a PHP configuration option.
	 *
	 * @param string $key   Configuration key.
	 * @param string $value Value to set.
	 * @return bool True if successful.
	 */
	private static function disable_if_possible( string $key, string $value ): bool {
		if ( function_exists( 'ini_set' ) && false === strpos( ini_get( 'disable_functions' ), 'ini_set' ) ) {
			// phpcs:ignore WordPress.PHP.IniSet.Risky, Squiz.PHP.DiscouragedFunctions.Discouraged
			@ini_set( $key, $value );
			return true;
		}
		return false;
	}

	/**
	 * Log any cleaned output buffers.
	 *
	 * @param string $type Response type (success or error).
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

		error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
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
					"[SSCRIBE][AJAX_BUFFER] ───── Extraneous content (%d bytes) ─────\n%s\n───── End extraneous content ─────",
					$length,
					$preview
				)
			);
		}

		if ( $length > self::LOG_PREVIEW_MAX ) {
			error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				sprintf(
					'[SSCRIBE][AJAX_BUFFER] Truncated %d excess bytes. Set SSCRIBE_AJAX_LOG_MAX to increase the preview limit.',
					$length - self::LOG_PREVIEW_MAX
				)
			);
		}
	}

	/**
	 * Get the current AJAX action name from request parameters.
	 *
	 * @return string Action name or 'unknown'.
	 */
	private static function resolve_action_name(): string {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing

		if ( isset( $_POST['action'] ) && is_string( $_POST['action'] ) ) {
			return sanitize_key( $_POST['action'] );
		}
		if ( isset( $_GET['action'] ) && is_string( $_GET['action'] ) ) {
			return sanitize_key( $_GET['action'] );
		}

		// phpcs:enable WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing

		return 'unknown';
	}

	/**
	 * Build diagnostics array for AJAX responses.
	 *
	 * @param array $context Additional context data.
	 * @return array Diagnostics data.
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
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- Debug-only feature guarded by SSCRIBE_DEBUG constant.
			$trace             = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 15 );
			$diag['backtrace'] = array_map(
				static function ( array $frame ): string {
					return sprintf(
						'%s%s(%s) called at %s:%d',
						$frame['class'] ?? '',
						$frame['type'] ?? '',
						$frame['function'],
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
	 * Format bytes into human-readable string.
	 *
	 * @param int $bytes Number of bytes.
	 * @return string Formatted string.
	 */
	private static function format_bytes( int $bytes ): string {
		if ( $bytes < 1024 ) {
			return $bytes . ' B';
		}

		$units      = array( 'KB', 'MB', 'GB', 'TB' );
		$unit_count = count( $units );
		$i          = 0;

		while ( $bytes >= 1024 && $i < $unit_count - 1 ) {
			$bytes /= 1024;
			++$i;
		}

		return sprintf( '%.2f %s', $bytes, $units[ $i ] );
	}
}
