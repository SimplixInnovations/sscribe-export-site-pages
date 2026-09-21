<?php
/**
 * SScribe Fatal Handler
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
 * Captures fatal-class PHP errors that occur during SScribe operations
 * and persists a sanitized operational record so failures are traceable
 * even when no catch block ran.
 *
 * The handler:
 *   - inspects error_get_last() at shutdown;
 *   - keeps only fatal categories (E_ERROR, E_PARSE, E_CORE_ERROR,
 *     E_COMPILE_ERROR, E_USER_ERROR);
 *   - only records when the error originated from a file inside this
 *     plugin or from a file path that touches the request context;
 *   - sanitizes the message (no raw request bodies, no token leaks);
 *   - delegates the actual write to {@see SScribe_Operational_Logger}.
 */
final class SScribe_Fatal_Handler {

	/**
	 * Plugin root directory used to filter fatal scope. Set by {@see self::boot()}.
	 *
	 * @var string
	 */
	private static string $plugin_root = '';

	/**
	 * Whether the shutdown handler has been registered for this request.
	 *
	 * @var bool
	 */
	private static bool $registered = false;

	/**
	 * Mark the plugin root and install the shutdown handler.
	 *
	 * Safe to call once per request. Idempotent.
	 *
	 * @param string $plugin_root Absolute filesystem path to the plugin root.
	 */
	public static function boot( string $plugin_root ): void {
		if ( self::$registered ) {
			return;
		}

		self::$plugin_root = rtrim( $plugin_root, '/' ) . '/';
		register_shutdown_function( array( self::class, 'capture' ) );
		self::$registered = true;
	}

	/**
	 * Inspect the last error and, when fatal-class and in-scope, persist it.
	 */
	public static function capture(): void {
		if ( ! class_exists( 'SScribe_Operational_Logger' ) ) {
			return;
		}

		$last = error_get_last();
		if ( ! is_array( $last ) ) {
			return;
		}

		$type = isset( $last['type'] ) ? (int) $last['type'] : 0;
		if ( ! self::is_fatal_category( $type ) ) {
			return;
		}

		$file = isset( $last['file'] ) ? (string) $last['file'] : '';
		if ( ! self::is_in_scope( $file ) ) {
			return;
		}

		$message = isset( $last['message'] ) ? (string) $last['message'] : '';
		$line    = isset( $last['line'] ) ? (int) $last['line'] : 0;

		$recorded = \SScribe_Operational_Logger::record(
			\SScribe_Operational_Logger::LEVEL_CRITICAL,
			'PHP fatal during SScribe operation',
			array(
				'php_error_type' => self::type_label( $type ),
				'file'           => '' !== $file ? basename( $file ) : '',
				'line'           => $line,
				'error_message'  => self::sanitize_message( $message ),
			)
		);

		// This callback is a PHP shutdown function registered after WordPress
		// core's shutdown_action_hook(). The normal WordPress shutdown action
		// (and therefore the operational logger's regular flush) may already
		// have completed by the time capture() runs. Persist the scoped fatal
		// immediately so it cannot remain stranded in the in-memory buffer.
		if ( $recorded ) {
			\SScribe_Operational_Logger::flush();
		}
	}

	/**
	 * Whether the given error type belongs to a fatal category.
	 *
	 * @param int $type PHP error type constant.
	 * @return bool True when fatal-class.
	 */
	private static function is_fatal_category( int $type ): bool {
		$fatal = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR;
		return 0 !== ( $type & $fatal );
	}

	/**
	 * Decide whether the fatal originated inside the plugin scope.
	 *
	 * Files inside the plugin root are always attributable to SScribe.
	 * A fatal in core or another plugin is attributed only while WordPress
	 * is handling an AJAX action whose canonical action name begins with
	 * sscribe_. This prevents unrelated admin-ajax.php failures from being
	 * recorded as SScribe operational incidents.
	 *
	 * @param string $file Absolute file path.
	 * @return bool True when the fatal is in scope.
	 */
	private static function is_in_scope( string $file ): bool {
		if ( '' === $file ) {
			return false;
		}
		if ( '' !== self::$plugin_root && str_starts_with( $file, self::$plugin_root ) ) {
			return true;
		}
		if ( ! function_exists( 'wp_doing_ajax' ) || ! wp_doing_ajax() ) {
			return false;
		}

		$action = $_REQUEST['action'] ?? null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only attribution at shutdown; authorization already occurred in the original request.
		if ( ! is_string( $action ) ) {
			return false;
		}

		$action = sanitize_key( wp_unslash( $action ) );
		return str_starts_with( $action, 'sscribe_' );
	}

	/**
	 * Strip control chars, redact common secret patterns, and bound length.
	 *
	 * @param string $message Raw PHP error message.
	 * @return string Sanitized, bounded message.
	 */
	private static function sanitize_message( string $message ): string {
		$message = trim( $message );
		$message = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $message ) ?? '';

		if ( strlen( $message ) > 240 ) {
			$message = substr( $message, 0, 240 ) . '...';
		}

		$patterns = array(
			'/\b[A-Za-z0-9+\/=]{32,}\b/' => '<redacted>',
			'/\bbearer\s+[^\s,;]+/i'     => 'bearer <redacted>',
			'/\bnonce=[^&\s]+/i'         => 'nonce=<redacted>',
		);
		$message = preg_replace( array_keys( $patterns ), array_values( $patterns ), $message ) ?? $message;

		return $message;
	}

	/**
	 * Translate a PHP error type bitmask into a stable human label.
	 *
	 * @param int $type Bitmask.
	 * @return string One of: fatal_error, parse_error, core_error, compile_error, user_error, unknown_fatal.
	 */
	private static function type_label( int $type ): string {
		if ( 0 !== ( $type & E_PARSE ) ) {
			return 'parse_error';
		}
		if ( 0 !== ( $type & E_CORE_ERROR ) ) {
			return 'core_error';
		}
		if ( 0 !== ( $type & E_COMPILE_ERROR ) ) {
			return 'compile_error';
		}
		if ( 0 !== ( $type & E_USER_ERROR ) ) {
			return 'user_error';
		}
		if ( 0 !== ( $type & E_ERROR ) ) {
			return 'fatal_error';
		}
		return 'unknown_fatal';
	}

	/**
	 * Test-only hook to reset registration state between PHPUnit runs.
	 */
	public static function reset_for_testing(): void {
		self::$registered  = false;
		self::$plugin_root = '';
	}
}
