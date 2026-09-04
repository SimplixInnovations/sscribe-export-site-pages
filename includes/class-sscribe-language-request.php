<?php
/**
 * SScribe Language Request Boundary
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
 * Translates the browser-only all-languages sentinel at the request boundary.
 *
 * Count endpoints intentionally retain __all__ as a transport key because
 * their response maps use that exact sentinel. Export Preview and Start,
 * however, use the collector's internal empty-string convention for "no
 * language restriction". Keeping the translation here gives both endpoints
 * one interpretation without teaching lower-level collectors about UI state.
 */
final class SScribe_Language_Request {

	/**
	 * Instance callback used by the hook loader.
	 */
	public function normalize_for_export_endpoint(): void {
		self::normalize_current_request();
	}

	/**
	 * Normalize the current Preview/Start request in both PHP request arrays.
	 *
	 * Non-string values are deliberately left untouched so the guarded endpoint
	 * validator can reject malformed input rather than receiving a coerced value.
	 */
	public static function normalize_current_request(): void {
		$action = $_REQUEST['action'] ?? ( $_POST['action'] ?? null ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Boundary normalization occurs before the guarded nonce/capability handler.

		if ( ! is_string( $action ) || ! in_array( $action, array( 'sscribe_get_export_preview', 'sscribe_start_export' ), true ) ) {
			return;
		}

		$language = $_POST['language'] ?? null; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Boundary normalization occurs before the guarded nonce/capability handler.
		if ( '__all__' !== $language ) {
			return;
		}

		$_POST['language']    = ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Normalizes transport data for the guarded endpoint.
		$_REQUEST['language'] = ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Keep WordPress request mirrors consistent for downstream readers.
	}
}
