<?php
/**
 * SScribe Container.
 *
 * @package SScribe_Export_Site_Pages
 * @license GPL v2 or later
 * @link    https://www.gnu.org/licenses/gpl-2.0.html
 */

declare(strict_types=1);

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runtime exception for "service not found" lookups in the SScribe
 * container. Implements {@see NotFoundExceptionInterface} so callers
 * written against PSR-11 can `catch` it generically.
 */
final class SScribe_Container_NotFound_Exception extends \RuntimeException implements NotFoundExceptionInterface {
}

/**
 * Runtime exception for general container errors (e.g. circular
 * dependencies, factories returning non-objects). Implements
 * {@see ContainerExceptionInterface} for PSR-11 generic catches.
 */
final class SScribe_Container_Exception extends \RuntimeException implements ContainerExceptionInterface {
}

/**
 * Dependency injection container implementing PSR-11 ContainerInterface.
 *
 * This is a thin registry with a singleton lifecycle, used internally to
 * wire up the batch processor, exporters, and helper services. It is
 * **not** a general-purpose DI framework — keep it simple, keep it
 * internal. The interface is exposed so third-party extensions can be
 * written against PSR-11 (`get( $id )`, `has( $id )`) and remain
 * portable across PSR-11 containers.
 *
 * ## Resolution rules
 *
 *  - {@see SScribe_Container::get()} returns a previously resolved
 *    singleton if one is cached, otherwise calls the bound factory
 *    exactly once.
 *  - Factories that are bound with {@see SScribe_Container::singleton()}
 *    are memoized; factories bound with {@see SScribe_Container::bind()}
 *    produce a fresh instance on every call.
 *  - Factories receive the container instance as their single argument
 *    so they can resolve sibling services.
 *  - Circular dependencies throw {@see SScribe_Container_Exception}.
 *  - Unknown service IDs throw {@see SScribe_Container_NotFound_Exception}.
 *
 * ## Why PSR-11
 *
 *  - Interop with Pimple, PHP-DI, Symfony DI, and the dozens of WP
 *    plugins that already type-hint `ContainerInterface`.
 *  - Removes the need for a custom "register_services" knowledge of
 *    the container's internal layout (see `extension-points.md`).
 *  - Makes the container usable from PSR-11-aware middleware (e.g.
 *    a future REST controller that needs the same session manager
 *    the AJAX handlers use).
 *
 * @package SScribe_Export_Site_Pages
 * @subpackage Container
 * @since   1.0.0
 * @api     stable
 *
 * @see     https://www.php-fig.org/psr/psr-11/ PSR-11: Container Interface
 */
final class SScribe_Container implements ContainerInterface {

	/**
	 * Container singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Registered factory callbacks.
	 *
	 * @var array<string, callable>
	 */
	private array $factories = array();

	/**
	 * Resolved singleton instances.
	 *
	 * @var array<string, object>
	 */
	private array $resolved = array();

	/**
	 * Keys marked as singletons.
	 *
	 * @var array<string, bool>
	 */
	private array $singletons = array();

	/**
	 * Keys currently being resolved (circular dependency guard).
	 *
	 * @var array<string, bool>
	 */
	private array $resolving = array();

	/**
	 * Get the singleton container instance.
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Reset the container (test-only).
	 *
	 * @return void
	 * @throws \RuntimeException If called outside test context.
	 */
	public static function reset(): void {
		if ( ! defined( 'WP_TESTS_DOMAIN' ) && ! defined( 'SSCRIBE_TESTING' ) ) {
			throw new \RuntimeException( 'SScribe_Container::reset() is only available in test contexts.' );
		}

		self::$instance = null;
	}

	/**
	 * Bind a service key to a factory callback.
	 *
	 * @param string   $key     Service identifier.
	 * @param callable $factory Factory callback.
	 * @return self
	 */
	public function bind( string $key, callable $factory ): self {
		$this->factories[ $key ] = $factory;
		return $this;
	}

	/**
	 * Register a singleton factory.
	 *
	 * @param string   $key     Service identifier.
	 * @param callable $factory Factory callback.
	 * @return self
	 */
	public function singleton( string $key, callable $factory ): self {
		$this->bind( $key, $factory );
		$this->singletons[ $key ] = true;
		return $this;
	}

	/**
	 * Set a pre-instantiated service instance.
	 *
	 * @param string $key      Service identifier.
	 * @param object $instance Service instance.
	 * @return self
	 */
	public function set( string $key, object $instance ): self {
		$this->resolved[ $key ]   = $instance;
		$this->singletons[ $key ] = true;
		return $this;
	}

	/**
	 * Resolve a service from the container.
	 *
	 * @param string $key Service identifier.
	 * @return object
	 * @throws SScribe_Container_NotFound_Exception If the service is not
	 *         registered (PSR-11 NotFoundExceptionInterface).
	 * @throws SScribe_Container_Exception On a circular dependency or a
	 *         factory that returns a non-object (PSR-11
	 *         ContainerExceptionInterface).
	 */
	public function resolve( string $key ): object {

		if ( isset( $this->resolved[ $key ] ) ) {
			return $this->resolved[ $key ];
		}

		if ( ! isset( $this->factories[ $key ] ) ) {
			throw new SScribe_Container_NotFound_Exception(
				sprintf(
					/* translators: %s: Service identifier that was not registered. */
					'Service "%s" is not registered in the container.',
					$key
				)
			);
		}

		if ( isset( $this->resolving[ $key ] ) ) {
			throw new SScribe_Container_Exception(
				sprintf(
					/* translators: %s: Service identifier causing circular dependency. */
					'Circular dependency detected while resolving container service: %s',
					$key
				)
			);
		}

		$this->resolving[ $key ] = true;

		try {
			$instance = ( $this->factories[ $key ] )( $this );
		} finally {
			unset( $this->resolving[ $key ] );
		}

		if ( ! is_object( $instance ) ) {
			throw new SScribe_Container_Exception(
				sprintf(
					/* translators: 1: Service identifier, 2: PHP type returned. */
					'Container factory for "%1$s" returned non-object type: %2$s',
					$key,
					gettype( $instance )
				)
			);
		}

		if ( isset( $this->singletons[ $key ] ) ) {
			$this->resolved[ $key ] = $instance;
		}

		return $instance;
	}

	/**
	 * Check if a service is registered.
	 *
	 * @param string $key Service identifier.
	 * @return bool
	 */
	public function has( string $key ): bool {
		return isset( $this->factories[ $key ] ) || isset( $this->resolved[ $key ] );
	}

	/**
	 * Get a resolved service (PSR-11 entry point).
	 *
	 * @param string $key Service identifier.
	 * @return mixed The resolved service instance.
	 * @throws SScribe_Container_NotFound_Exception If the service is not
	 *         registered.
	 * @throws SScribe_Container_Exception On a circular dependency or a
	 *         factory error.
	 */
	public function get( string $key ): mixed {
		return $this->resolve( $key );
	}
}
