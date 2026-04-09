<?php
/**
 * Plugin-owned runtime autoloader.
 *
 * Decouples SScribe runtime class loading from Composer so production builds
 * can load prefixed vendor dependencies without relying on raw vendor/autoload.php.
 *
 * @package SScribe
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

spl_autoload_register(
	static function ( string $class_name ): void {
		if ( 0 !== strpos( $class_name, 'SScribe_' ) ) {
			return;
		}

		$relative = strtolower( str_replace( '_', '-', substr( $class_name, 8 ) ) );
		$paths    = array();

		if ( str_ends_with( $class_name, '_Interface' ) ) {
			$interface = strtolower( str_replace( '_', '-', substr( $class_name, 8, -10 ) ) );
			$paths[]   = SSCRIBE_PLUGIN_DIR . 'includes/interfaces/interface-sscribe-' . $interface . '.php';
			$paths[]   = SSCRIBE_PLUGIN_DIR . 'includes/exporters/interface-sscribe-' . $interface . '.php';
		} elseif ( str_contains( $class_name, '_Exception' ) || 'SScribe_Exception' === $class_name ) {
			$paths[] = SSCRIBE_PLUGIN_DIR . 'includes/exceptions/class-sscribe-' . $relative . '.php';
		} elseif ( str_ends_with( $class_name, '_Exporter' ) || str_contains( $class_name, '_Exporter_' ) ) {
			$paths[] = SSCRIBE_PLUGIN_DIR . 'includes/exporters/class-sscribe-' . $relative . '.php';
			$paths[] = SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-' . $relative . '.php';
		} elseif ( 'SScribe_Admin' === $class_name ) {
			$paths[] = SSCRIBE_PLUGIN_DIR . 'admin/class-sscribe-admin.php';
		} else {
			$paths[] = SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-' . $relative . '.php';
		}

		foreach ( $paths as $path ) {
			if ( file_exists( $path ) ) {
				require_once $path;
				return;
			}
		}
	}
);
