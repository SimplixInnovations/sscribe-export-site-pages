<?php
/**
 * SScribe Container NotFound Exception.
 *
 * Thrown by {@see SScribe_Container::get()} / `::resolve()` when a
 * service id has no registered factory. Extends {@see \RuntimeException}
 * directly — does not implement any external interface — so the class
 * loads cleanly on production WordPress installs that have no Composer
 * autoloader registered for the Psr\Container namespace. Generic
 * `\RuntimeException` catches continue to work.
 *
 * @package SScribe_Export_Site_Pages
 * @subpackage Container
 * @since   1.1.1
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'SScribe_Container_NotFound_Exception', false ) ) {
	/**
	 * Runtime exception for "service not found" lookups in the SScribe
	 * container.
	 */
	final class SScribe_Container_NotFound_Exception extends \RuntimeException {
	}
}
