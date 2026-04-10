<?php
/**
 * Runtime shims for vendor-prefixed dependencies.
 *
 * Some prefixed packages can reference namespaced wrappers for global PHP
 * functions (for example SScribeVendor\Safe\class_alias()). Provide safe
 * passthrough shims so prefixed autoload files work consistently.
 *
 * @package SScribe
 */

declare(strict_types=1);

namespace SScribeVendor\Safe;

if ( ! \function_exists( __NAMESPACE__ . '\\class_alias' ) ) {
	/**
	 * Pass-through shim for class_alias in prefixed namespace.
	 *
	 * @param string $class_name Original class name.
	 * @param string $alias      Alias class name.
	 * @param bool   $autoload   Whether to autoload original class.
	 *
	 * @return bool
	 */
	function class_alias( string $class_name, string $alias, bool $autoload = true ): bool {
		return \class_alias( $class_name, $alias, $autoload );
	}
}
