<?php
/**
 * Register all actions and filters for the plugin.
 *
 * @package SScribe
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SScribe_Loader
 *
 * Maintains a list of all hooks registered by the plugin and registers
 * them with the WordPress API when run() is called.
 */
class SScribe_Loader {


	/**
	 * Array of actions registered with WordPress.
	 *
	 * @var array
	 */
	protected $actions;

	/**
	 * Array of filters registered with WordPress.
	 *
	 * @var array
	 */
	protected $filters;

	/**
	 * Initialize the collections.
	 */
	public function __construct() {
		 $this->actions = array();
		$this->filters  = array();
	}

	/**
	 * Add an action to the collection.
	 *
	 * @param string $hook          The name of the WordPress action.
	 * @param object $component     A reference to the instance of the object.
	 * @param string $callback      The name of the function definition.
	 * @param int    $priority      Optional. The priority. Default 10.
	 * @param int    $accepted_args Optional. Number of arguments. Default 1.
	 */
	public function add_action( $hook, $component, $callback, $priority = 10, $accepted_args = 1 ) {
		$this->actions = $this->add( $this->actions, $hook, $component, $callback, $priority, $accepted_args );
	}

	/**
	 * Add a filter to the collection.
	 *
	 * @param string $hook          The name of the WordPress filter.
	 * @param object $component     A reference to the instance of the object.
	 * @param string $callback      The name of the function definition.
	 * @param int    $priority      Optional. The priority. Default 10.
	 * @param int    $accepted_args Optional. Number of arguments. Default 1.
	 */
	public function add_filter( $hook, $component, $callback, $priority = 10, $accepted_args = 1 ) {
		$this->filters = $this->add( $this->filters, $hook, $component, $callback, $priority, $accepted_args );
	}

	/**
	 * Utility function for registering hooks into a single collection.
	 *
	 * @param array  $hooks         The collection of hooks (actions or filters).
	 * @param string $hook          The name of the WordPress hook.
	 * @param object $component     A reference to the instance of the object.
	 * @param string $callback      The name of the function definition.
	 * @param int    $priority      The priority.
	 * @param int    $accepted_args The number of arguments.
	 * @return array The collection of hooks with the new hook added.
	 */
	private function add( $hooks, $hook, $component, $callback, $priority, $accepted_args ) {
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
	public function run() {
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
