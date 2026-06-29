<?php
/**
 * SScribe Container Exception.
 *
 * Thrown by {@see SScribe_Container} for **infrastructure-level** container
 * failures: circular dependencies detected at resolution time, factory
 * closures returning a non-object, factory closures throwing an exception
 * during construction, or attempts to register a service id that has
 * already been registered with a different factory.
 *
 * **NOT** thrown for missing services : that is the exclusive job of
 * {@see SScribe_Container_NotFound_Exception}. Code that needs to
 * distinguish "the service is not registered" from "the service factory
 * blew up" must catch both classes individually:
 *
 *     try {
 *         $svc = $container->get( 'sscribe.foo' );
 *     } catch ( SScribe_Container_NotFound_Exception $e ) {
 *         // service is not registered : caller can decide to register,
 *         // fall back to a default, or surface a user-facing message.
 *     } catch ( SScribe_Container_Exception $e ) {
 *         // factory blew up (circular dep, bad return, exception).
 *         // surface as an unrecoverable internal error.
 *     }
 *
 * Extends {@see \RuntimeException} directly : does not implement any
 * external interface : so the class loads cleanly on production
 * WordPress installs that have no Composer autoloader registered for
 * the Psr\Container namespace. Generic `\RuntimeException` catches
 * continue to work.
 *
 * @package SScribe_Export_Site_Pages
 * @subpackage Container
 * @since   1.1.2
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'SScribe_Container_Exception', false ) ) {
	/**
	 * Runtime exception for general / infrastructure container errors.
	 *
	 * Thrown by {@see SScribe_Container} when a service factory fails,
	 * a circular dependency is detected, or a registration invariant
	 * is violated. Distinct from {@see SScribe_Container_NotFound_Exception}
	 * which is reserved for "service id is not registered" lookups.
	 */
	final class SScribe_Container_Exception extends \RuntimeException {
	}
}
