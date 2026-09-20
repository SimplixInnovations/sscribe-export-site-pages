<?php
/**
 * SScribe Vendor Bootstrap
 *
 * Single source of truth for probing and loading the vendor autoloader.
 *
 * Two autoload layouts are supported:
 *
 *  - `vendor-prefixed/autoload.php` (shipped to WP.org via the Strauss
 *    build pipeline), namespaced as `SScribeVendor\...`.
 *  - `vendor/autoload.php` (Composer-installed, used in dev / CI),
 *    namespaced as the upstream packages expose.
 *
 * Callers should NOT roll their own `file_exists` probe. Use
 * {@see SScribe_Vendor_Bootstrap::is_available()} for boolean checks
 * and {@see SScribe_Vendor_Bootstrap::require()} for the require path.
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
 * Vendor autoload probe + require helper.
 */
final class SScribe_Vendor_Bootstrap {

	/**
	 * PHP extensions required by bundled runtime libraries and export formats.
	 *
	 * @var array<int, string>
	 */
	private const REQUIRED_EXTENSIONS = array( 'curl', 'dom', 'gd', 'xml', 'zip' );

	/**
	 * Return required PHP extensions that are unavailable on this host.
	 *
	 * This check runs before the Composer autoloader so a missing extension is
	 * reported through WordPress instead of becoming a Composer platform fatal.
	 *
	 * @return array<int, string>
	 */
	public static function get_missing_extensions(): array {
		$missing = array();
		foreach ( self::REQUIRED_EXTENSIONS as $extension ) {
			if ( ! extension_loaded( $extension ) ) {
				$missing[] = $extension;
			}
		}
		return $missing;
	}

	/**
	 * Tracks whether {@see require()} has loaded the vendor autoloader
	 * in the current process.
	 *
	 * @var bool
	 */
	private static bool $loaded = false;

	/**
	 * True when either vendor-prefixed/autoload.php or vendor/autoload.php
	 * exists on disk relative to SSCRIBE_PLUGIN_DIR. Cheap probe: no
	 * `require` side effect.
	 *
	 * @return bool
	 */
	public static function is_available(): bool {
		if ( ! defined( 'SSCRIBE_PLUGIN_DIR' ) ) {
			return false;
		}

		return file_exists( SSCRIBE_PLUGIN_DIR . 'vendor-prefixed/autoload.php' )
			|| file_exists( SSCRIBE_PLUGIN_DIR . 'vendor/autoload.php' );
	}

	/**
	 * Require the first available autoloader and remember the outcome.
	 *
	 * Prefers `vendor-prefixed/autoload.php` (the Strauss-prefixed build
	 * shipped to WP.org) because its class names are guaranteed to be
	 * unique to this plugin. Falls back to the plain Composer autoloader
	 * (dev / CI), which requires `includes/sscribe-vendor-compat.php` to
	 * alias the upstream namespaces.
	 *
	 * Idempotent: subsequent calls are no-ops even if the first call did
	 * not find an autoloader on disk. Returns the same value as
	 * {@see is_available()} at the moment the first call resolved.
	 *
	 * @return bool True when an autoloader was successfully loaded.
	 */
	public static function require(): bool {
		if ( self::$loaded ) {
			return defined( 'SSCRIBE_VENDOR_AUTOLOADED' ) && SSCRIBE_VENDOR_AUTOLOADED;
		}
		self::$loaded = true;

		if ( ! defined( 'SSCRIBE_PLUGIN_DIR' ) ) {
			return false;
		}

		if ( file_exists( SSCRIBE_PLUGIN_DIR . 'vendor-prefixed/autoload.php' ) ) {
			require_once SSCRIBE_PLUGIN_DIR . 'vendor-prefixed/autoload.php';
			if ( ! defined( 'SSCRIBE_VENDOR_AUTOLOADED' ) ) {
				define( 'SSCRIBE_VENDOR_AUTOLOADED', true );
			}
			return true;
		}

		if ( file_exists( SSCRIBE_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
			require_once SSCRIBE_PLUGIN_DIR . 'vendor/autoload.php';
			if ( file_exists( SSCRIBE_PLUGIN_DIR . 'includes/sscribe-vendor-compat.php' ) ) {
				require_once SSCRIBE_PLUGIN_DIR . 'includes/sscribe-vendor-compat.php';
			}
			if ( ! defined( 'SSCRIBE_VENDOR_AUTOLOADED' ) ) {
				define( 'SSCRIBE_VENDOR_AUTOLOADED', true );
			}
			return true;
		}

		return false;
	}
}
