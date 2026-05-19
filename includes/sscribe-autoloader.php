<?php

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

spl_autoload_register(
	static function ( string $class_name ): void {

		static $loaded  = array();
		static $missing = array();

		if ( isset( $loaded[ $class_name ] ) ) {
			return;
		}

		if ( isset( $missing[ $class_name ] ) ) {
			return;
		}

		if ( 'SScribe_Security' === $class_name ) {
			$file = SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-security.php';
			if ( file_exists( $file ) ) {
				require_once $file;
				$loaded[ $class_name ] = true;
			} else {
				$missing[ $class_name ] = true;
			}
			return;
		}

		if ( 'SScribe' === $class_name ) {
			$file = SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe.php';
			if ( file_exists( $file ) ) {
				require_once $file;
				$loaded[ $class_name ] = true;
			} else {
				$missing[ $class_name ] = true;
			}
			return;
		}

		if ( 0 !== strpos( $class_name, 'SScribe_' ) ) {
			$missing[ $class_name ] = true;
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
				$loaded[ $class_name ] = true;
				return;
			}
		}

		$missing[ $class_name ] = true;
	}
);
