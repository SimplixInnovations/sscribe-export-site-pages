<?php
/**
 * SScribe_Loader unit test
 *
 * Locks in the hook-registration surface that the loader exposes to the
 * rest of the plugin:
 *   - add_action stores entries with the expected shape
 *   - add_filter stores entries with the expected shape
 *   - run() registers all queued filters + actions against WP
 *   - add_guarded_ajax_action wraps via SScribe_AJAX_Guard and registers
 *     the wrapped handler with add_action()
 *
 * The run() output is asserted by reading the global $wp_filter stash
 * populated by the fake-wp add_action/add_filter stubs.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

if ( ! class_exists( '\\SScribe_Loader' ) ) {
	require_once SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-loader.php';
}

if ( ! class_exists( '\\SScribe_AJAX_Guard' ) ) {
	require_once SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-ajax-guard.php';
}

final class SScribe_Loader_Test extends TestCase {

	private \SScribe_Loader $loader;
	private Loader_Stub_Component $component;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['sscribe_test_actions'] = array();
		$GLOBALS['sscribe_test_filters'] = array();
		$GLOBALS['sscribe_test_current_filter'] = '';
		$this->loader   = new \SScribe_Loader();
		$this->component = new Loader_Stub_Component();
	}

	// ==================================================================
	// add_action / add_filter
	// ==================================================================

	public function test_add_action_records_entry_with_priority_and_args(): void {
		$this->loader->add_action( 'init', $this->component, 'do_thing', 20, 2 );
		$actions = $this->read_collection( 'actions' );

		$this::assertCount( 1, $actions );
		$this::assertSame( 'init', $actions[0]['hook'] );
		$this::assertSame( $this->component, $actions[0]['component'] );
		$this::assertSame( 'do_thing', $actions[0]['callback'] );
		$this::assertSame( 20, $actions[0]['priority'] );
		$this::assertSame( 2, $actions[0]['accepted_args'] );
	}

	public function test_add_filter_records_entry_with_defaults(): void {
		$this->loader->add_filter( 'the_content', $this->component, 'transform' );
		$filters = $this->read_collection( 'filters' );

		$this::assertCount( 1, $filters );
		$this::assertSame( 'the_content', $filters[0]['hook'] );
		$this::assertSame( 10, $filters[0]['priority'] );
		$this::assertSame( 1, $filters[0]['accepted_args'] );
	}

	// ==================================================================
	// run()
	// ==================================================================

	public function test_run_registers_filters_then_actions(): void {
		$this->loader->add_filter( 'a_filter', $this->component, 'transform' );
		$this->loader->add_action( 'an_action', $this->component, 'do_thing' );

		$this->loader->run();

		$this::assertNotEmpty( $GLOBALS['sscribe_test_filters'] );
		$this::assertNotEmpty( $GLOBALS['sscribe_test_actions'] );
		$this::assertSame( 'a_filter', $GLOBALS['sscribe_test_filters'][0]['hook'] );
		$this::assertSame( 'an_action', $GLOBALS['sscribe_test_actions'][0]['hook'] );
	}

	public function test_run_with_no_hooks_is_a_noop(): void {
		// Should not throw on an empty loader.
		$this->loader->run();
		$this::assertSame( array(), $GLOBALS['sscribe_test_filters'] );
		$this::assertSame( array(), $GLOBALS['sscribe_test_actions'] );
	}

	// ==================================================================
	// add_guarded_ajax_action()
	// ==================================================================

	public function test_add_guarded_ajax_action_registers_wrapped_handler(): void {
		if ( ! class_exists( '\\SScribe_AJAX_Guard' ) ) {
			$this::markTestSkipped( 'SScribe_AJAX_Guard unavailable.' );
		}
		$this->loader->add_guarded_ajax_action(
			'wp_ajax_sscribe_test',
			$this->component,
			'do_thing',
			'manage_options',
			'sscribe_test_nonce',
			'_ajax_nonce',
			10,
			1
		);

		$this::assertNotEmpty( $GLOBALS['sscribe_test_actions'] );
		$registered = $GLOBALS['sscribe_test_actions'][0];
		$this::assertSame( 'wp_ajax_sscribe_test', $registered['hook'] );
		$this::assertSame( 10, $registered['priority'] );
		$this::assertSame( 1, $registered['accepted_args'] );
		// The wrapped callback is a Closure, not the original string.
		$this::assertIsCallable( $registered['callback'] );
	}

	/**
	 * Read the protected $actions or $filters collection via reflection.
	 */
	private function read_collection( string $prop ): array {
		$ref = new \ReflectionClass( $this->loader );
		$p   = $ref->getProperty( $prop );
		return $p->getValue( $this->loader );
	}
}

class Loader_Stub_Component {

	public function do_thing(): void {}

	public function transform( string $x ): string {
		return $x;
	}
}
