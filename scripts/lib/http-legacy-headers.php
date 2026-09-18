<?php
/**
 * Legacy HTTP response-header reader for PHP < 8.5.
 *
 * This file is loaded ONLY when http_get_last_response_headers() is
 * unavailable (see sscribe_http_get()). It intentionally references the
 * predefined $http_response_header variable, which PHP 8.5 deprecates.
 * Keeping that reference in this isolated file — never loaded on PHP
 * 8.5+ — preserves the fallback for older runtimes without tripping
 * the deprecation where the modern API exists.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

if ( ! function_exists( 'sscribe_legacy_response_status' ) ) {
	/**
	 * Extract the HTTP status code from the last fopen-wrapper response.
	 *
	 * @return int Status code, or 0 when unavailable.
	 */
	function sscribe_legacy_response_status(): int {
		$headers = isset( $http_response_header ) && is_array( $http_response_header ) ? $http_response_header : array();
		if ( isset( $headers[0] ) && preg_match( '/\s(\d{3})\s/', $headers[0], $m ) ) {
			return (int) $m[1];
		}
		return 0;
	}
}
