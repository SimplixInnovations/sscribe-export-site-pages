<?php
/**
 * SScribe Container
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SScribe_Container {

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
	 * @throws \RuntimeException If service not found or circular dependency.
	 */
	public function resolve( string $key ): object {

		if ( isset( $this->resolved[ $key ] ) ) {
			return $this->resolved[ $key ];
		}

		if ( ! isset( $this->factories[ $key ] ) ) {
			throw new \RuntimeException( 'Service not registered in container.' );
		}

		if ( isset( $this->resolving[ $key ] ) ) {
			throw new \RuntimeException(
				sprintf(
					/* translators: %s: Service identifier causing circular dependency. */

					'Circular dependency detected in container while resolving: %s',
					$key // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message, not browser output.
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
			throw new \RuntimeException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message, not browser output.
				'Container factory returned non-object type: ' . gettype( $instance )
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
	 * Get a resolved service (alias for resolve).
	 *
	 * @param string $key Service identifier.
	 * @return object
	 */
	public function get( string $key ): object {
		return $this->resolve( $key );
	}
}
