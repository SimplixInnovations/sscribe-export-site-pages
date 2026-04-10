<?php
/**
 * Lightweight service container for SScribe.
 *
 * Provides lazy instantiation, singleton binding, and factory-based
 * dependency injection without the overhead of a full DI framework.
 *
 * @package SScribe
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SScribe_Container
 *
 * Simple service container supporting singleton and factory bindings.
 */
class SScribe_Container {

	/**
	 * Singleton instance.
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
	 * Get the global container instance.
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
	 * Reset the container (primarily for testing).
	 *
	 * @return void
	 *
	 * @throws \RuntimeException If called outside test contexts.
	 */
	public static function reset(): void {
		if ( ! defined( 'WP_TESTS_DOMAIN' ) && ! defined( 'SSCRIBE_TESTING' ) ) {
			throw new \RuntimeException( 'SScribe_Container::reset() is only available in test contexts.' );
		}

		self::$instance = null;
	}

	/**
	 * Bind a factory callback for lazy resolution.
	 *
	 * @param string   $key     Service identifier.
	 * @param callable $factory Factory that returns the service.
	 * @return self
	 */
	public function bind( string $key, callable $factory ): self {
		$this->factories[ $key ] = $factory;
		return $this;
	}

	/**
	 * Bind a shared singleton service.
	 *
	 * @param string   $key     Service identifier.
	 * @param callable $factory Factory that returns the service.
	 * @return self
	 */
	public function singleton( string $key, callable $factory ): self {
		$this->bind( $key, $factory );
		$this->singletons[ $key ] = true;
		return $this;
	}

	/**
	 * Register a concrete instance directly.
	 *
	 * @param string $key      Service identifier.
	 * @param object $instance The instance to store.
	 * @return self
	 */
	public function set( string $key, object $instance ): self {
		$this->resolved[ $key ]   = $instance;
		$this->singletons[ $key ] = true;
		return $this;
	}

	/**
	 * Resolve a service by key.
	 *
	 * @param string $key Service identifier.
	 * @return object
	 * @throws \RuntimeException If no binding registered for key.
	 */
	public function resolve( string $key ): object {
		// Return cached singleton if available.
		if ( isset( $this->resolved[ $key ] ) ) {
			return $this->resolved[ $key ];
		}

		if ( ! isset( $this->factories[ $key ] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output.
			throw new \RuntimeException( "No binding registered for: {$key}" );
		}

		$instance = ( $this->factories[ $key ] )( $this );

		// Cache if singleton.
		if ( isset( $this->singletons[ $key ] ) ) {
			$this->resolved[ $key ] = $instance;
		}

		return $instance;
	}

	/**
	 * Check if a service is bound.
	 *
	 * @param string $key Service identifier.
	 * @return bool
	 */
	public function has( string $key ): bool {
		return isset( $this->factories[ $key ] ) || isset( $this->resolved[ $key ] );
	}

	/**
	 * Get a typed service (convenience wrapper).
	 *
	 * @param string $key Service identifier.
	 * @return object
	 */
	public function get( string $key ): object {
		return $this->resolve( $key );
	}
}
