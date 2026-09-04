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

if ( ! function_exists( 'wp_suspend_cache_invalidation' ) ) {

	/**
	 * WordPress stub for PHPStan.
	 *
	 * @param bool $suspend Whether to suspend or resume cache invalidation.
	 * @return bool Always returns true.
	 */
	function wp_suspend_cache_invalidation( bool $suspend = true ): bool {
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

if ( ! defined( 'MB_IN_BYTES' ) ) {
	/**
	 * PHP memory constant stub for PHPStan.
	 * Equals 1048576 bytes (one megabyte).
	 */
	define( 'MB_IN_BYTES', 1048576 );
}

if ( ! defined( 'SSCRIBE_PRIVATE_STORAGE_DIR' ) ) {
	/**
	 * Plugin-private storage override stub for PHPStan.
	 * Operators can pin SSCRIBE_PRIVATE_STORAGE_DIR in wp-config.php to
	 * relocate the private export tree. PHPStan treats the constant as
	 * undefined because it is declared at runtime by WordPress bootstrap.
	 */
	define( 'SSCRIBE_PRIVATE_STORAGE_DIR', '' );
}

if ( ! defined( 'SSCRIBE_PLUGIN_DIR' ) ) {
	/**
	 * Plugin directory stub for PHPStan.
	 * The real value is defined in sscribe-export-site-pages.php at
	 * runtime; PHPStan cannot resolve it through file analysis alone
	 * because it depends on WordPress's `plugin_dir_path()`.
	 */
	define( 'SSCRIBE_PLUGIN_DIR', __DIR__ . '/' );
}
