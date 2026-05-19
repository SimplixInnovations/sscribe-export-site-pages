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

	private static ?self $instance = null;

	private array $factories = array();

	private array $resolved = array();

	private array $singletons = array();

	private array $resolving = array();

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public static function reset(): void {
		if ( ! defined( 'WP_TESTS_DOMAIN' ) && ! defined( 'SSCRIBE_TESTING' ) ) {
			throw new \RuntimeException( 'SScribe_Container::reset() is only available in test contexts.' );
		}

		self::$instance = null;
	}

	public function bind( string $key, callable $factory ): self {
		$this->factories[ $key ] = $factory;
		return $this;
	}

	public function singleton( string $key, callable $factory ): self {
		$this->bind( $key, $factory );
		$this->singletons[ $key ] = true;
		return $this;
	}

	public function set( string $key, object $instance ): self {
		$this->resolved[ $key ]   = $instance;
		$this->singletons[ $key ] = true;
		return $this;
	}

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

	public function has( string $key ): bool {
		return isset( $this->factories[ $key ] ) || isset( $this->resolved[ $key ] );
	}

	public function get( string $key ): object {
		return $this->resolve( $key );
	}
}
