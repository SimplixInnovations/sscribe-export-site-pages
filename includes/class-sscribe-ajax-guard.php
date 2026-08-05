<?php
/**
 * SScribe AJAX Guard
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
 * Guard for sanitizing AJAX responses and cleaning output buffers.
 */
class SScribe_AJAX_Guard {

	private const LOG_PREVIEW_MAX = 2000;

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

		if ( defined( 'SSCRIBE_DEBUG' ) && SSCRIBE_DEBUG ) {
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
		if ( function_exists( 'ini_set' ) ) {
			$disabled_functions = array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) );
			if ( ! in_array( 'ini_set', $disabled_functions, true ) ) {
				// phpcs:ignore WordPress.PHP.IniSet.Risky, Squiz.PHP.DiscouragedFunctions.Discouraged
				@ini_set( $key, $value );
				return true;
			}
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

		if ( defined( 'WP_TESTS_DOMAIN' ) || defined( 'SSCRIBE_TESTING' ) ) {
			return;
		}

		if ( '' === $extraneous && 0 === $start_level ) {
			return;
		}

		$action = self::resolve_action_name();
		$length = strlen( $extraneous );

		if ( defined( 'SSCRIBE_DEBUG' ) && SSCRIBE_DEBUG ) {
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
						basename( $frame['file'] ?? 'unknown' ),
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
		$bytes = max( 0, $bytes );
		if ( $bytes < 1024 ) {
			return $bytes . ' B';
		}

		$units = array( 'KB', 'MB', 'GB', 'TB' );
		$value = (float) $bytes;
		$i     = 0;
		$max_i = count( $units ) - 1;

		while ( $value >= 1024.0 && $i < $max_i ) {
			$value /= 1024.0;
			++$i;
		}

		if ( $i >= 3 && $value > 64.0 ) {
			return 'N/A (overflow)';
		}

		return sprintf( '%.2f %s', $value, $units[ $i ] );
	}

	/**
	 * Wrap a callable so the standard SScribe nonce + capability checks
	 * run before it is invoked.
	 *
	 * Use this at the action registration site to remove the duplicated
	 * `check_ajax_referer` + `current_user_can` boilerplate from every
	 * AJAX handler:
	 *
	 *     // Before : 12 lines of boilerplate per handler:
	 *     add_action( 'wp_ajax_sscribe_foo', [ $this, 'ajax_foo' ] );
	 *     public function ajax_foo(): void {
	 *         if ( ! check_ajax_referer( 'sscribe_export_nonce', 'nonce', false ) ) {
	 *             self::error( [ 'message' => 'Security check failed.' ], 403 );
	 *         }
	 *         if ( ! current_user_can( $this->get_required_capability() ) ) {
	 *             self::error( [ 'message' => 'Permission denied.' ], 403 );
	 *         }
	 *         // ... actual handler logic ...
	 *     }
	 *
	 *     // After : registration site owns the guard:
	 *     add_action(
	 *         'wp_ajax_sscribe_foo',
	 *         SScribe_AJAX_Guard::with_guard( [ $this, 'ajax_foo' ], $this->get_required_capability() )
	 *     );
	 *     public function ajax_foo(): void {
	 *         // ... actual handler logic only ...
	 *     }
	 *
	 * The wrapped callable receives the same arguments WordPress passes to
	 * the underlying action. If either guard fails, an error JSON response
	 * is emitted via {@see self::error()} and the wrapped callable is
	 * never invoked : no need to `exit` or `return` in your handler.
	 *
	 * @param callable $handler    The actual AJAX handler to guard.
	 * @param string   $capability Capability the current user must have
	 *                             (typically the result of
	 *                             {@see SScribe_Capabilities::get_required_capability()}).
	 * @param string   $nonce_name Nonce action name (default
	 *                             `'sscribe_export_nonce'`).
	 * @param string   $nonce_arg  Request key holding the nonce
	 *                             (default `'nonce'`).
	 * @return callable Wrapped callable suitable for `add_action()`.
	 */
	public static function with_guard(
		callable $handler,
		string $capability,
		string $nonce_name = 'sscribe_export_nonce',
		string $nonce_arg = 'nonce'
	): callable {
		return static function ( ...$args ) use ( $handler, $capability, $nonce_name, $nonce_arg ): void {
			if ( ! check_ajax_referer( $nonce_name, $nonce_arg, false ) ) {
				self::error(
					array(
						'code'    => 'invalid_nonce',
						'message' => __( 'Security check failed.', 'sscribe-export-site-pages' ),
					),
					403
				);
			}
			if ( ! current_user_can( $capability ) ) {
				self::error(
					array(
						'code'    => 'permission_denied',
						'message' => __( 'Permission denied.', 'sscribe-export-site-pages' ),
					),
					403
				);
			}
			$handler( ...$args );
		};
	}
}
