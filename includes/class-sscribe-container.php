<?php
/**
 * SScribe Container.
 *
 * @package SScribe_Export_Site_Pages
 * @license GPL v2 or later
 * @link    https://www.gnu.org/licenses/gpl-2.0.html
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-sscribe-container-exception.php';
require_once __DIR__ . '/class-sscribe-container-notfound-exception.php';

/**
 * Lightweight dependency injection container.
 *
 * This is a thin registry with a singleton lifecycle, used internally to
 * wire up the batch processor, exporters, and helper services. It is
 * **not** a general-purpose DI framework : keep it simple, keep it
 * internal.
 *
 * The public surface (`get( $id )`, `has( $id )`) is intentionally
 * compatible in shape with the PSR-11 `ContainerInterface` so third-party
 * extensions written against PSR-11 can adapt to it, but the container
 * does not `implements` the PSR-11 interface. The shipped plugin cannot
 * rely on a Composer autoloader for the `Psr\Container` namespace
 * (WordPress loads plugins without one), so a literal `implements`
 * clause would fatal at activation. Callers wanting strict PSR-11
 * compatibility can wrap any `SScribe_Container` in their own thin
 * adapter.
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
 *  - Both exception classes extend `\RuntimeException` directly, so
 *    generic `\RuntimeException` catches work for backwards compatibility.
 *
 * @package SScribe_Export_Site_Pages
 * @subpackage Container
 * @since   1.1.2
 * @api     stable
 */
final class SScribe_Container {

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
					'Service not registered in container. Key: "%s"',
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message; $key is an internal container id, not user-facing output.
					$key
				)
			);
		}

		if ( isset( $this->resolving[ $key ] ) ) {
			throw new SScribe_Container_Exception(
				sprintf(
					/* translators: %s: Service identifier causing circular dependency. */
					'Circular dependency detected while resolving container service: %s',
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message; $key is an internal container id, not user-facing output.
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
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message; $key is an internal container id, not user-facing output.
					$key,
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message; gettype() returns a PHP type name (a fixed set of strings), not user input.
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
