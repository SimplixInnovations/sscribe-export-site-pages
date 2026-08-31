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

	/**
	 * Read and sanitize a scalar POST value.
	 *
	 * @param string $key        Request key.
	 * @param string $default    Default value.
	 * @param int    $max_length Maximum returned byte length.
	 * @return string Sanitized request value.
	 */
	public static function post_text( string $key, string $default = '', int $max_length = 200 ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized after scalar validation below.
		$value = isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : null;

		return self::sanitize_request_text( $value, $default, $max_length );
	}

	/**
	 * Read and sanitize a scalar GET value.
	 *
	 * @param string $key        Request key.
	 * @param string $default    Default value.
	 * @param int    $max_length Maximum returned byte length.
	 * @return string Sanitized request value.
	 */
	public static function get_text( string $key, string $default = '', int $max_length = 200 ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized after scalar validation below.
		$value = isset( $_GET[ $key ] ) ? wp_unslash( $_GET[ $key ] ) : null;

		return self::sanitize_request_text( $value, $default, $max_length );
	}

	/**
	 * Read a bounded integer POST value.
	 *
	 * @param string $key     Request key.
	 * @param int    $default Default value.
	 * @param int    $minimum Minimum accepted value.
	 * @param int    $maximum Maximum accepted value.
	 * @return int Validated integer.
	 */
	public static function post_integer(
		string $key,
		int $default = 0,
		int $minimum = 0,
		int $maximum = PHP_INT_MAX
	): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validated as an integer below.
		$value = isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : null;
		if ( ! is_scalar( $value ) || is_bool( $value ) ) {
			return min( $maximum, max( $minimum, $default ) );
		}

		$validated = filter_var( (string) $value, FILTER_VALIDATE_INT );
		if ( false === $validated ) {
			return min( $maximum, max( $minimum, $default ) );
		}

		return min( $maximum, max( $minimum, $validated ) );
	}

	/**
	 * Read a boolean POST value.
	 *
	 * @param string $key     Request key.
	 * @param bool   $default Default value for missing or malformed input.
	 * @return bool Validated boolean.
	 */
	public static function post_boolean( string $key, bool $default = false ): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validated as a boolean below.
		$value = isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : null;
		if ( ! is_scalar( $value ) || is_bool( $value ) ) {
			return $default;
		}

		$validated = filter_var( (string) $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );

		return is_bool( $validated ) ? $validated : $default;
	}

	/**
	 * Read an array POST value with an item-count bound.
	 *
	 * Values remain untrusted; callers must validate and sanitize each element
	 * for its specific destination.
	 *
	 * @param string $key       Request key.
	 * @param int    $max_items Maximum top-level item count.
	 * @return array Unslashed request array or an empty array.
	 */
	public static function post_array( string $key, int $max_items = 100 ): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Elements are intentionally validated by the destination-specific caller.
		$value = isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : null;
		if ( ! is_array( $value ) ) {
			return array();
		}

		return array_slice( $value, 0, max( 0, $max_items ), true );
	}

	/**
	 * Sanitize a request value without accepting arrays or objects.
	 *
	 * @param mixed  $value      Request value.
	 * @param string $default    Default value.
	 * @param int    $max_length Maximum returned byte length.
	 * @return string Sanitized value.
	 */
	private static function sanitize_request_text( mixed $value, string $default, int $max_length ): string {
		if ( ! is_scalar( $value ) || is_bool( $value ) ) {
			return $default;
		}

		$sanitized = sanitize_text_field( (string) $value );

		return substr( $sanitized, 0, max( 0, $max_length ) );
	}

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

		$request_id = SScribe_Request_Id::current();
		if ( function_exists( 'header' ) && ! headers_sent() ) {
			header( SScribe_Request_Id::HEADER . ': ' . $request_id );
		}

		if ( is_array( $data ) ) {
			if ( ! isset( $data[ SScribe_Request_Id::RESPONSE_KEY ] ) ) {
				$data[ SScribe_Request_Id::RESPONSE_KEY ] = $request_id;
			}
		} else {
			$data = array(
				SScribe_Request_Id::RESPONSE_KEY => $request_id,
				'value'                          => $data,
			);
		}

		if ( defined( 'SSCRIBE_DEBUG' ) && SSCRIBE_DEBUG && current_user_can( SScribe_Capabilities::get_health_required() ) ) {
			$diagnostics = self::build_diagnostics( $context );
			$data['_debug'] = $diagnostics;
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

		$request_id = SScribe_Request_Id::current();
		if ( function_exists( 'header' ) && ! headers_sent() ) {
			header( SScribe_Request_Id::HEADER . ': ' . $request_id );
		}

		if ( is_array( $data ) ) {
			if ( ! isset( $data[ SScribe_Request_Id::RESPONSE_KEY ] ) ) {
				$data[ SScribe_Request_Id::RESPONSE_KEY ] = $request_id;
			}
		} elseif ( ! is_object( $data ) ) {
			$data = array(
				'message' => (string) $data,
				SScribe_Request_Id::RESPONSE_KEY => $request_id,
			);
		}

		$effective_status = null !== $status_code ? (int) $status_code : 0;
		if ( $effective_status >= 500 && class_exists( 'SScribe_Operational_Logger' ) ) {
			$error_code = is_array( $data ) && isset( $data['code'] ) && is_string( $data['code'] )
				? $data['code']
				: 'ajax_5xx';
			$message    = self::extract_message( $data );
			\SScribe_Operational_Logger::record(
				\SScribe_Operational_Logger::LEVEL_ERROR,
				'AJAX 5xx response',
				array(
					'error_code' => $error_code,
					'http_status' => $effective_status,
					'ajax_action' => self::resolve_action_name(),
				)
			);
		}

		if ( defined( 'SSCRIBE_DEBUG' ) && SSCRIBE_DEBUG && current_user_can( SScribe_Capabilities::get_health_required() ) ) {
			$diagnostics = self::build_diagnostics( $context );

			if ( is_array( $data ) ) {
				$data['_diagnostics'] = $diagnostics;
			} else {
				$data = array(
					SScribe_Request_Id::RESPONSE_KEY => \SScribe_Request_Id::current(),
					'value'                          => $data,
					'_diagnostics'                   => $diagnostics,
				);
			}
		}

		wp_send_json_error( $data, $status_code );
		exit;
	}

	/**
	 * Extract a safe human-readable message from a response payload.
	 *
	 * Handles array payloads with a `message` key, scalar payloads, and
	 * objects with `__toString`. Falls back to 'unknown' for anything
	 * else so the operational logger never sees an unsafe cast.
	 *
	 * @param mixed $data Response data.
	 * @return string Human-readable message.
	 */
	private static function extract_message( mixed $data ): string {
		if ( is_array( $data ) && isset( $data['message'] ) && is_string( $data['message'] ) ) {
			return $data['message'];
		}
		if ( is_string( $data ) ) {
			return $data;
		}
		if ( is_scalar( $data ) ) {
			return (string) $data;
		}
		if ( is_object( $data ) && method_exists( $data, '__toString' ) ) {
			return (string) $data;
		}
		return 'unknown';
	}

	/**
	 * Sanitize PHP environment for AJAX responses.
	 */
	private static function sanitise_environment(): void {
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
		$buffer_level = ob_get_level();
		$extraneous   = '';
		if ( $buffer_level > 0 ) {
			$content = ob_get_contents();
			if ( false !== $content ) {
				$extraneous = trim( $content );
			}
			ob_clean();
		}

		if ( defined( 'WP_TESTS_DOMAIN' ) || defined( 'SSCRIBE_TESTING' ) ) {
			return;
		}

		if ( '' === $extraneous && 0 === $buffer_level ) {
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
					$buffer_level,
					PHP_VERSION,
					self::format_bytes( memory_get_usage( true ) ),
					gmdate( 'Y-m-d\TH:i:s\Z' )
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
			return sanitize_key( wp_unslash( $_POST['action'] ) );
		}
		if ( isset( $_GET['action'] ) && is_string( $_GET['action'] ) ) {
			return sanitize_key( wp_unslash( $_GET['action'] ) );
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
		$memory_limit = ini_get( 'memory_limit' );
		$diag = array(
			'php_version'  => PHP_VERSION,
			'memory_usage' => self::format_bytes( memory_get_usage( true ) ),
			'memory_limit' => false !== $memory_limit && '' !== $memory_limit ? $memory_limit : 'unlimited',
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
