<?php
/**
 * Core plugin orchestrator.
 *
 * @package SScribe
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SScribe
 *
 * Registers all hooks and bootstraps the plugin.
 */
class SScribe {


	/**
	 * The loader that registers all hooks.
	 *
	 * @var SScribe_Loader
	 */
	protected $loader;

	/**
	 * Plugin version.
	 *
	 * @var string
	 */
	protected $version;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->version = defined( 'SSCRIBE_VERSION' ) ? SSCRIBE_VERSION : '1.0.0';
		$this->loader = new SScribe_Loader();

		$this->define_admin_hooks();
		$this->define_ajax_hooks();
		$this->define_cron_hooks();
	}

	/**
	 * Register admin-specific hooks.
	 *
	 * @return void
	 */
	private function define_admin_hooks() {
		$admin = new SScribe_Admin();

		$this->loader->add_action( 'admin_menu', $admin, 'add_admin_menu' );
		$this->loader->add_action( 'admin_enqueue_scripts', $admin, 'enqueue_admin_assets' );
		$this->loader->add_filter( 'plugin_action_links_' . SSCRIBE_PLUGIN_BASENAME, $admin, 'add_plugin_action_links' );
	}

	/**
	 * Register AJAX hooks.
	 *
	 * @return void
	 */
	private function define_ajax_hooks() {
		$batch = new SScribe_Batch_Processor();

		$this->loader->add_action( 'wp_ajax_sscribe_start_export', $batch, 'ajax_start_export' );
		$this->loader->add_action( 'wp_ajax_sscribe_process_batch', $batch, 'ajax_process_batch' );
		$this->loader->add_action( 'wp_ajax_sscribe_download', $batch, 'ajax_download' );
		$this->loader->add_action( 'wp_ajax_sscribe_get_status_counts', $batch, 'ajax_get_status_counts' );
		$this->loader->add_action( 'wp_ajax_sscribe_cancel_export', $batch, 'ajax_cancel_export' );
	}

	/**
	 * Register cron hooks.
	 *
	 * @return void
	 */
	private function define_cron_hooks() {
		$zip = new SScribe_Zip_Handler();
		$this->loader->add_action( 'sscribe_cleanup_exports', $zip, 'cleanup_expired' );

		$this->loader->add_action( 'sscribe_cleanup_sessions', $this, 'cleanup_sessions' );
	}

	/**
	 * Cleanup expired session files.
	 *
	 * @return void
	 */
	public function cleanup_sessions() {
		$session = new SScribe_Session();
		$session->cleanup_expired( 4 * HOUR_IN_SECONDS );
	}

	/**
	 * Run the loader to execute all hooks.
	 *
	 * @return void
	 */
	public function run() {
		$this->loader->run();
	}
}
