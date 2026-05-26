<?php
/**
 * SScribe PHPStan Bootstrap
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

$base_dir          = __DIR__;
$vendor_autoload   = $base_dir . '/vendor/autoload.php';
$prefixed_autoload = $base_dir . '/vendor-prefixed/autoload.php';

if ( file_exists( $vendor_autoload ) ) {
	require_once $vendor_autoload;
} elseif ( file_exists( $prefixed_autoload ) ) {
	require_once $base_dir . '/includes/sscribe-prefixed-runtime-shim.php';
	require_once $prefixed_autoload;
}

$sscribe_phpstan_alias_prefixes = array(
	'SScribeVendor\\Mpdf\\'               => 'Mpdf\\',
	'SScribeVendor\\PhpOffice\\PhpWord\\' => 'PhpOffice\\PhpWord\\',
);

spl_autoload_register(
	static function ( string $fqcn ) use ( $sscribe_phpstan_alias_prefixes ): void {
		foreach ( $sscribe_phpstan_alias_prefixes as $prefixed_prefix => $raw_prefix ) {
			if ( ! str_starts_with( $fqcn, $prefixed_prefix ) ) {
				continue;
			}

			$raw_class = $raw_prefix . substr( $fqcn, strlen( $prefixed_prefix ) );

			if ( class_exists( $raw_class ) || interface_exists( $raw_class ) || trait_exists( $raw_class ) ) {
				class_alias( $raw_class, $fqcn );
			}

			return;
		}
	},
	true,
	true
);

if ( ! function_exists( 'is_user_logged_in' ) ) {

	/**
	 * WordPress stub for PHPStan.
	 *
	 * @return bool Always true in test context.
	 */
	function is_user_logged_in(): bool {
		return true;
	}
}

if ( ! function_exists( 'wp_cache_flush' ) ) {

	/**
	 * WordPress stub for PHPStan.
	 *
	 * @return bool Always true in test context.
	 */
	function wp_cache_flush(): bool {
		return true;
	}
}

if ( ! function_exists( 'wp_is_post_autosave' ) ) {

	/**
	 * WordPress stub for PHPStan.
	 *
	 * @param int $post_id Post ID.
	 * @return bool Always false in test context.
	 */
	function wp_is_post_autosave( int $post_id ): bool {
		return false;
	}
}

if ( ! function_exists( 'wp_is_post_revision' ) ) {

	/**
	 * WordPress stub for PHPStan.
	 *
	 * @param int $post_id Post ID.
	 * @return bool Always false in test context.
	 */
	function wp_is_post_revision( int $post_id ): bool {
		return false;
	}
}

if ( ! function_exists( 'register_setting' ) ) {

	/**
	 * WordPress stub for PHPStan.
	 *
	 * @param string $option_group Option group.
	 * @param string $option_name Option name.
	 * @param array  $args       Optional. Data used to describe the setting when registered with register_setting().
	 * @return bool Always true in test context.
	 */
	function register_setting( string $option_group, string $option_name, array $args = array() ): bool {
		return true;
	}
}

if ( ! function_exists( 'get_current_blog_id' ) ) {

	/**
	 * WordPress stub for PHPStan.
	 *
	 * @return int Always returns 1 in single site context.
	 */
	function get_current_blog_id(): int {
		return 1;
	}
}

if ( ! defined( 'FS_CHMOD_FILE' ) ) {
	/**
	 * WordPress filesystem constant stub for PHPStan.
	 * Default WordPress value is 0644 (owner read/write, world readable).
	 */
	define( 'FS_CHMOD_FILE', 0644 );
}
