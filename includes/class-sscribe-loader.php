<?php
/**
 * SScribe Loader
 *
 * @package SScribe_Export_Site_Pages
 * @license GPL v2 or later
 * @link    https://www.gnu.org/licenses/gpl-2.0.html
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and enqueues hooks with WordPress.
 */
class SScribe_Loader {

	/**
	 * Collection of action hooks.
	 *
	 * @var array
	 */
	protected array $actions;

	/**
	 * Collection of filter hooks.
	 *
	 * @var array
	 */
	protected array $filters;

	/**
	 * Initialize the loader.
	 */
	public function __construct() {
		$this->actions = array();
		$this->filters = array();
	}

	/**
	 * Register a new action hook.
	 *
	 * @param string $hook          WordPress action hook name.
	 * @param object $component     Component class instance.
	 * @param string $callback      Callback method name.
	 * @param int    $priority      Hook priority.
	 * @param int    $accepted_args Number of accepted arguments.
	 */
	public function add_action( string $hook, object $component, string $callback, int $priority = 10, int $accepted_args = 1 ): void {
		$this->actions = $this->add( $this->actions, $hook, $component, $callback, $priority, $accepted_args );
	}

	/**
	 * Register a new filter hook.
	 *
	 * @param string $hook          WordPress filter hook name.
	 * @param object $component     Component class instance.
	 * @param string $callback      Callback method name.
	 * @param int    $priority      Hook priority.
	 * @param int    $accepted_args Number of accepted arguments.
	 */
	public function add_filter( string $hook, object $component, string $callback, int $priority = 10, int $accepted_args = 1 ): void {
		$this->filters = $this->add( $this->filters, $hook, $component, $callback, $priority, $accepted_args );
	}

	/**
	 * Add a hook to the collection.
	 *
	 * @param array  $hooks         Existing hooks.
	 * @param string $hook          Hook name.
	 * @param object $component     Component instance.
	 * @param string $callback      Callback method.
	 * @param int    $priority      Hook priority.
	 * @param int    $accepted_args Accepted argument count.
	 * @return array Updated hooks array.
	 */
	private function add( array $hooks, string $hook, object $component, string $callback, int $priority, int $accepted_args ): array {
		$hooks[] = array(
			'hook'          => $hook,
			'component'     => $component,
			'callback'      => $callback,
			'priority'      => $priority,
			'accepted_args' => $accepted_args,
		);
		return $hooks;
	}

	/**
	 * Register all hooks with WordPress.
	 */
	public function run(): void {
		foreach ( $this->filters as $hook ) {
			add_filter(
				$hook['hook'],
				array( $hook['component'], $hook['callback'] ),
				$hook['priority'],
				$hook['accepted_args']
			);
		}

		foreach ( $this->actions as $hook ) {
			add_action(
				$hook['hook'],
				array( $hook['component'], $hook['callback'] ),
				$hook['priority'],
				$hook['accepted_args']
			);
		}
	}
}
