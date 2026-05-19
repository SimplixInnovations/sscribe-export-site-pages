<?php
/**
 * SScribe Capabilities
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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

	public static function get_required(): string {
		$cap = (string) apply_filters( 'sscribe_export_capability', 'manage_options' );
		return self::is_allowed( $cap ) ? $cap : 'manage_options';
	}

	public static function is_allowed( string $capability ): bool {
		return in_array( $capability, self::ALLOWED, true );
	}

	public static function get_allowed_list(): array {
		return self::ALLOWED;
	}
}
