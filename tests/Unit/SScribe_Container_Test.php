<?php
/**
 * SScribe Container Unit Test
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

class SScribe_Container_Test extends TestCase {

	protected function tearDown(): void {
		\SScribe_Container::reset();
		parent::tearDown();
	}

	public function test_instance_returns_singleton(): void {
		$first  = \SScribe_Container::instance();
		$second = \SScribe_Container::instance();
		$this->assertSame( $first, $second );
	}

	public function test_bind_and_resolve(): void {
		$container = \SScribe_Container::instance();
		$container->bind(
			'service_a',
			function () {
				return new \stdClass();
			}
		);

		$resolved = $container->resolve( 'service_a' );
		$this->assertInstanceOf( \stdClass::class, $resolved );
	}

	public function test_bind_returns_new_instance_each_time(): void {
		$container = \SScribe_Container::instance();
		$container->bind(
			'service_b',
			function () {
				return new \stdClass();
			}
		);

		$first  = $container->resolve( 'service_b' );
		$second = $container->resolve( 'service_b' );
		$this->assertNotSame( $first, $second );
	}

	public function test_singleton_returns_same_instance(): void {
		$container = \SScribe_Container::instance();
		$container->singleton(
			'service_c',
			function () {
				return new \stdClass();
			}
		);

		$first  = $container->resolve( 'service_c' );
		$second = $container->resolve( 'service_c' );
		$this->assertSame( $first, $second );
	}

	public function test_set_stores_pre_instantiated_instance(): void {
		$container = \SScribe_Container::instance();
		$instance  = new \stdClass();
		$instance->foo = 'bar';
		$container->set( 'service_d', $instance );

		$resolved = $container->resolve( 'service_d' );
		$this->assertSame( $instance, $resolved );
		$this->assertEquals( 'bar', $resolved->foo );
	}

	public function test_has_returns_false_for_unregistered(): void {
		$container = \SScribe_Container::instance();
		$this->assertFalse( $container->has( 'nonexistent' ) );
	}

	public function test_has_returns_true_for_registered(): void {
		$container = \SScribe_Container::instance();
		$container->bind(
			'service_e',
			function () {
				return new \stdClass();
			}
		);
		$this->assertTrue( $container->has( 'service_e' ) );
	}

	public function test_get_is_alias_for_resolve(): void {
		$container = \SScribe_Container::instance();
		$container->singleton(
			'service_f',
			function () {
				return new \stdClass();
			}
		);

		$this->assertInstanceOf( \stdClass::class, $container->get( 'service_f' ) );
	}

	public function test_resolve_throws_for_unregistered(): void {
		$container = \SScribe_Container::instance();
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Service not registered in container.' );
		$container->resolve( 'ghost' );
	}

	public function test_resolve_throws_for_circular_dependency(): void {
		$container = \SScribe_Container::instance();
		$container->bind(
			'circular_a',
			function ( $c ) {
				return $c->resolve( 'circular_b' );
			}
		);
		$container->bind(
			'circular_b',
			function ( $c ) {
				return $c->resolve( 'circular_a' );
			}
		);

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Circular dependency' );
		$container->resolve( 'circular_a' );
	}

	public function test_reset_clears_all(): void {
		$container = \SScribe_Container::instance();
		$container->singleton(
			'service_g',
			function () {
				return new \stdClass();
			}
		);
		$container->resolve( 'service_g' );
		\SScribe_Container::reset();

		$new_container = \SScribe_Container::instance();
		$this->assertFalse( $new_container->has( 'service_g' ) );
	}

	public function test_factory_receives_container(): void {
		$container = \SScribe_Container::instance();
		$received  = null;
		$container->bind(
			'service_h',
			function ( $c ) use ( &$received ) {
				$received = $c;
				return new \stdClass();
			}
		);

		$container->resolve( 'service_h' );
		$this->assertSame( $container, $received );
	}

	public function test_factory_non_object_throws(): void {
		$container = \SScribe_Container::instance();
		$container->bind(
			'service_i',
			function () {
				return 'string_not_object';
			}
		);

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'non-object' );
		$container->resolve( 'service_i' );
	}
}
