<?php
/**
 * SScribe Container Exception.
 *
 * Thrown by {@see SScribe_Container} for general container errors
 * such as circular dependencies or factories returning non-objects.
 * Implements {@see \Psr\Container\ContainerExceptionInterface} so
 * PSR-11-aware callers can catch it generically.
 *
 * @package SScribe_Export_Site_Pages
 * @subpackage Container
 * @since   1.1.1
 */

declare(strict_types=1);

use Psr\Container\ContainerExceptionInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'SScribe_Container_Exception', false ) ) {
	/**
	 * Runtime exception for general container errors (e.g. circular
	 * dependencies, factories returning non-objects). Implements
	 * {@see ContainerExceptionInterface} for PSR-11 generic catches.
	 */
	final class SScribe_Container_Exception extends \RuntimeException implements ContainerExceptionInterface {
	}
}
