<?php
/**
 * SScribe Container NotFound Exception.
 *
 * Thrown **exclusively** by {@see SScribe_Container::get()} /
 * `::resolve()` when the requested service id has no registered factory
 * : i.e. it is the SScribe-container equivalent of the Psr\Container
 * `NotFoundExceptionInterface`. Catching this specific class is the
 * supported way to distinguish "the service is not registered" from
 * other container failures.
 *
 * Distinct from {@see SScribe_Container_Exception}, which is thrown for
 * infrastructure-level container failures (circular dependencies,
 * factories returning non-objects, factories throwing during
 * construction, duplicate registrations). Catching the two classes
 * individually lets callers react differently:
 *
 *     try {
 *         $svc = $container->get( 'sscribe.foo' );
 *     } catch ( SScribe_Container_NotFound_Exception $e ) {
 *         // service is not registered : caller can decide to register,
 *         // fall back to a default, or surface a user-facing message.
 *     } catch ( SScribe_Container_Exception $e ) {
 *         // factory blew up : surface as an unrecoverable internal error.
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
 * @since   1.1.3
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'SScribe_Container_NotFound_Exception', false ) ) {
	/**
	 * Runtime exception for "service id not registered" lookups.
	 *
	 * Thrown by {@see SScribe_Container::get()} / `::resolve()` when a
	 * requested service id has no registered factory. Distinct from
	 * {@see SScribe_Container_Exception} (reserved for infrastructure
	 * failures).
	 */
	final class SScribe_Container_NotFound_Exception extends \RuntimeException {
	}
}
