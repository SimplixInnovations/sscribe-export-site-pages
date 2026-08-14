<?php
/**
 * SScribe Capabilities
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
 * Capability management for export permissions.
 */
class SScribe_Capabilities {

	private const ALLOWED = array(
		'sscribe_export',
		'sscribe_health',
		'manage_options',
		'export',
	);

	/**
	 * Get the required capability for exports.
	 *
	 * @return string
	 */
	public static function get_required(): string {
		$cap = (string) apply_filters( 'sscribe_export_capability', 'sscribe_export' );
		return self::is_allowed( $cap ) ? $cap : 'sscribe_export';
	}

	/**
	 * Get the capability required for diagnostic health checks.
	 *
	 * @return string
	 */
	public static function get_health_required(): string {
		$cap = (string) apply_filters( 'sscribe_health_capability', 'sscribe_health' );
		return self::is_allowed( $cap ) ? $cap : 'sscribe_health';
	}

	/**
	 * Check if a capability is in the allowed list.
	 *
	 * @param string $capability Capability name.
	 * @return bool
	 */
	public static function is_allowed( string $capability ): bool {
		return in_array( $capability, self::ALLOWED, true );
	}

	/**
	 * Get the full list of allowed capabilities.
	 *
	 * @return array
	 */
	public static function get_allowed_list(): array {
		return self::ALLOWED;
	}
}
