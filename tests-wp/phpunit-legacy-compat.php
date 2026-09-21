<?php
/**
 * PHPUnit 11 compatibility symbols required by the WordPress 6.1 test suite.
 *
 * WordPress 6.1's wp-phpunit compatibility layer aliases several PHPUnit 6
 * symbols that were removed in modern PHPUnit. These definitions exist only
 * in the real-WordPress testbench and are loaded before wp-phpunit.
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace PHPUnit\Framework\Error {
	if ( ! class_exists( Deprecated::class, false ) ) {
		class Deprecated extends \PHPUnit\Framework\Exception {}
	}

	if ( ! class_exists( Notice::class, false ) ) {
		class Notice extends \PHPUnit\Framework\Exception {}
	}

	if ( ! class_exists( Warning::class, false ) ) {
		class Warning extends \PHPUnit\Framework\Exception {}
	}
}

namespace PHPUnit\Framework {
	if ( ! class_exists( Warning::class, false ) ) {
		class Warning extends Exception {}
	}

	if ( ! interface_exists( TestListener::class, false ) ) {
		interface TestListener {}
	}
}
