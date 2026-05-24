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
		'manage_options',
		'edit_pages',
		'edit_posts',
		'publish_pages',
		'publish_posts',
		'delete_pages',
		'export',
		'export_posts',
	);

	/**
	 * Get the required capability for exports.
	 *
	 * @return string
	 */
	public static function get_required(): string {
		$cap = (string) apply_filters( 'sscribe_export_capability', 'manage_options' );
		return self::is_allowed( $cap ) ? $cap : 'manage_options';
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
