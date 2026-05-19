<?php

declare(strict_types=1);

namespace SScribeVendor\Safe;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! \function_exists( __NAMESPACE__ . '\\class_alias' ) ) {

	function class_alias( string $class_name, string $alias, bool $autoload = true ): bool {
		return \class_alias( $class_name, $alias, $autoload );
	}
}
