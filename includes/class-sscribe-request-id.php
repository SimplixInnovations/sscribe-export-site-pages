<?php
/**
 * SScribe Request Correlation Identifier
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
 * Generates and exposes a per-request correlation identifier.
 *
 * A single id travels from the server-side request context into every
 * SScribe JSON response (success and error) and every operational
 * log record. The same id may be supplied by the client (request
 * header `X-SScribe-Request-Id`) so a single user-perceived operation
 * can be correlated across browser, server, and storage.
 *
 * The id format is `ssr_<16 lowercase hex chars>` and is always safe
 * to expose: it carries no secret material and is generated from
 * cryptographically-secure randomness when not supplied by the client.
 */
final class SScribe_Request_Id {

	public const HEADER       = 'X-SScribe-Request-Id';
	public const RESPONSE_KEY = 'request_id';
	public const PATTERN      = '/^ssr_[0-9a-f]{16}$/D';
	public const MAX_LENGTH   = 20;

	/**
	 * Cached request id for the current request lifecycle.
	 *
	 * @var string|null
	 */
	private static ?string $current = null;

	/**
	 * Reset the cached id. Useful between PHPUnit tests that share a process.
	 */
	public static function reset(): void {
		self::$current = null;
	}

	/**
	 * Return the current request id, generating one if needed.
	 *
	 * Honors a client-supplied value via HTTP header when present and
	 * well-formed; otherwise generates a fresh id from random_bytes().
	 */
	public static function current(): string {
		if ( null !== self::$current && '' !== self::$current ) {
			return self::$current;
		}

		$supplied = self::read_supplied_id();
		if ( null !== $supplied ) {
			self::$current = $supplied;
			return self::$current;
		}

		try {
			$random = bin2hex( random_bytes( 8 ) );
		} catch ( \Throwable $e ) {
			$random = bin2hex( (string) microtime( true ) );
			if ( 16 !== strlen( $random ) ) {
				$random = substr( str_pad( preg_replace( '/[^0-9a-f]/', '', $random ) ?? '', 16, '0' ), 0, 16 );
			}
		}

		self::$current = 'ssr_' . $random;
		return self::$current;
	}

	/**
	 * Validate and return a client-supplied request id, or null.
	 *
	 * @param mixed $value Raw value to validate.
	 * @return string|null Sanitized id or null when not well-formed.
	 */
	public static function sanitize( mixed $value ): ?string {
		if ( ! is_scalar( $value ) || is_bool( $value ) ) {
			return null;
		}
		$candidate = strtolower( substr( (string) $value, 0, self::MAX_LENGTH + 8 ) );
		if ( 1 !== preg_match( self::PATTERN, $candidate ) ) {
			return null;
		}
		return $candidate;
	}

	/**
	 * Read the X-SScribe-Request-Id request header, returning a valid id or null.
	 */
	private static function read_supplied_id(): ?string {
		if ( empty( $_SERVER ) ) {
			return null;
		}

		$candidates = array(
			'HTTP_X_SSCRIBE_REQUEST_ID',
			'X_SSCRIBE_REQUEST_ID',
		);

		foreach ( $candidates as $key ) {
			if ( ! isset( $_SERVER[ $key ] ) ) {
				continue;
			}
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Unsanitized on purpose; sanitize_text_field() runs immediately below.
			$raw = wp_unslash( $_SERVER[ $key ] );
			if ( ! is_scalar( $raw ) ) {
				continue;
			}
			$clean = sanitize_text_field( (string) $raw );
			$sanitized = self::sanitize( $clean );
			if ( null !== $sanitized ) {
				return $sanitized;
			}
		}

		return null;
	}
}
