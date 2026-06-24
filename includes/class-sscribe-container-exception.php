<?php
/**
 * SScribe Container Exception.
 *
 * Thrown by {@see SScribe_Container} for general container errors
 * such as circular dependencies or factories returning non-objects.
 * Extends {@see \RuntimeException} directly — does not implement any
 * external interface — so the class loads cleanly on production
 * WordPress installs that have no Composer autoloader registered for
 * the Psr\Container namespace. Generic `\RuntimeException` catches
 * continue to work.
 *
 * @package SScribe_Export_Site_Pages
 * @subpackage Container
 * @since   1.1.1
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'SScribe_Container_Exception', false ) ) {
	/**
	 * Runtime exception for general container errors (e.g. circular
	 * dependencies, factories returning non-objects).
	 */
	final class SScribe_Container_Exception extends \RuntimeException {
	}
}
