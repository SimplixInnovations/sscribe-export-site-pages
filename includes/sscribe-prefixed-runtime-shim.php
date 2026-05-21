<?php
/**
 * SScribe Prefixed Runtime Shim
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribeVendor\Safe;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! \function_exists( __NAMESPACE__ . '\\class_alias' ) ) {

	/**
	 * Safe wrapper for class_alias within prefixed namespace.
	 *
	 * @param string $class_name Original class name.
	 * @param string $alias      Alias class name.
	 * @param bool   $autoload   Whether to autoload.
	 * @return bool True on success.
	 */
	function class_alias( string $class_name, string $alias, bool $autoload = true ): bool {
		return \class_alias( $class_name, $alias, $autoload );
	}
}
