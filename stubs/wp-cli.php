<?php
/**
 * PHPStan-only API stub for the parts of WP-CLI the plugin calls.
 *
 * WP-CLI provides the real classes when the plugin runs under `wp`. This
 * file is only scanned by PHPStan and is excluded from the release package.
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace {
	if ( ! class_exists( 'WP_CLI', false ) ) {
		class WP_CLI {
			/**
			 * @param string          $name     Command name.
			 * @param callable|string $callable Command class or callable.
			 * @param array<string, mixed> $args Registration arguments.
			 */
			public static function add_command( string $name, $callable, array $args = array() ): bool {
				return true;
			}

			public static function log( string $message ): void {
			}

			public static function line( string $message = '' ): void {
			}

			public static function success( string $message ): void {
			}

			public static function warning( string $message ): void {
			}

			/**
			 * @param string   $message Message.
			 * @param bool|int $exit    Exit code, or false to keep running.
			 */
			public static function error( string $message, $exit = true ): void {
			}
		}
	}
}

namespace WP_CLI\Utils {
	/**
	 * @param string                    $format Output format.
	 * @param array<int, array<mixed>>  $items  Rows.
	 * @param array<int, string>|string $fields Fields to show.
	 */
	function format_items( string $format, array $items, $fields ): void {
	}
}
