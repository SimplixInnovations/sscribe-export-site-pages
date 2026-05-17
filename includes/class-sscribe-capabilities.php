<?php
/**
 * Centralized capability management for SScribe.
 *
 * @package SScribe
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SScribe_Capabilities
 *
 * Single source of truth for capability validation.
 * Both SScribe_Admin and SScribe_Batch_Processor delegate here.
 */
class SScribe_Capabilities {

	/**
	 * Allowed capabilities whitelist.
	 *
	 * @var array<int, string>
	 */
	private const ALLOWED = array(
		'manage_options',
		'edit_pages',
		'edit_posts',    // Supports post type export for post editors.
		'publish_pages',
		'publish_posts', // Supports post type export for post editors.
		'delete_pages',
		'export',
		'export_posts',  // Some WordPress configurations grant export and export_posts separately.
	);

	/**
	 * Get the required capability for SScribe operations.
	 *
	 * Applies the `sscribe_export_capability` filter, then validates
	 * the result against the allowed whitelist to prevent privilege escalation.
	 *
	 * @return string Valid WordPress capability slug.
	 */
	public static function get_required(): string {
		$cap = (string) apply_filters( 'sscribe_export_capability', 'manage_options' );
		return self::is_allowed( $cap ) ? $cap : 'manage_options';
	}

	/**
	 * Check if a capability is in the allowed whitelist.
	 *
	 * @param string $capability WordPress capability slug.
	 * @return bool
	 */
	public static function is_allowed( string $capability ): bool {
		return in_array( $capability, self::ALLOWED, true );
	}

	/**
	 * Get the full whitelist for display/debug purposes.
	 *
	 * @return array<int, string>
	 */
	public static function get_allowed_list(): array {
		return self::ALLOWED;
	}
}
