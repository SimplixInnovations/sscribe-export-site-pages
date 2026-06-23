<?php
/**
 * SScribe Container NotFound Exception.
 *
 * Thrown by {@see SScribe_Container::get()} / `::resolve()` when a
 * service id has no registered factory. Implements
 * {@see \Psr\Container\NotFoundExceptionInterface} so PSR-11-aware
 * callers can catch it generically.
 *
 * @package SScribe_Export_Site_Pages
 * @subpackage Container
 * @since   1.1.1
 */

declare(strict_types=1);

use Psr\Container\NotFoundExceptionInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'SScribe_Container_NotFound_Exception', false ) ) {
	/**
	 * Runtime exception for "service not found" lookups in the SScribe
	 * container. Implements {@see NotFoundExceptionInterface} so callers
	 * written against PSR-11 can `catch` it generically.
	 */
	final class SScribe_Container_NotFound_Exception extends \RuntimeException implements NotFoundExceptionInterface {
	}
}
