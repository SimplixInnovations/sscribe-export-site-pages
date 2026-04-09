<?php
/**
 * Core plugin orchestrator.
 *
 * @package SScribe
 */

declare(strict_types=1);

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
	protected SScribe_Loader $loader;

	/**
	 * Plugin version.
	 *
	 * @var string
	 */
	protected string $version;

	/**
	 * Constructor.
	 *
	 * Only assigns dependencies - no side effects.
	 * This allows the class to be instantiated safely in tests.
	 */
	public function __construct() {
		$this->version = SSCRIBE_VERSION;
		$this->loader  = new SScribe_Loader();
	}

	/**
	 * Register all services in the container for dependency injection.
	 *
	 * @return void
	 */
	private function register_services(): void {
		$container = SScribe_Container::instance();
		$debug     = defined( 'SSCRIBE_DEBUG' ) && SSCRIBE_DEBUG;

		$container->singleton( SScribe_Logger::class, fn() => SScribe_Logger::instance( $debug ) );
		$container->singleton( SScribe_Content_Parser::class, fn() => new SScribe_Content_Parser() );
		$container->singleton( SScribe_SEO_Reader::class, fn() => new SScribe_SEO_Reader() );
		$container->singleton( SScribe_Zip_Handler::class, fn() => new SScribe_Zip_Handler() );
		$container->singleton( SScribe_Page_Collector::class, fn() => new SScribe_Page_Collector() );
		$container->singleton( SScribe_Session::class, fn() => new SScribe_Session() );
		$container->singleton( SScribe_Filesystem::class, fn() => new SScribe_Filesystem() );

		$container->singleton(
			SScribe_Exporter::class,
			fn( SScribe_Container $c ) => new SScribe_Exporter( $c->get( SScribe_Content_Parser::class ) )
		);

		$container->singleton(
			SScribe_HTML_Exporter::class,
			fn( SScribe_Container $c ) => new SScribe_HTML_Exporter(
				$c->get( SScribe_Logger::class ),
				$c->get( SScribe_Filesystem::class )
			)
		);

		$container->singleton(
			SScribe_DOCX_Exporter::class,
			fn( SScribe_Container $c ) => new SScribe_DOCX_Exporter(
				$c->get( SScribe_Exporter::class ),
				$c->get( SScribe_Logger::class )
			)
		);

		$container->singleton(
			SScribe_PDF_Exporter::class,
			fn( SScribe_Container $c ) => new SScribe_PDF_Exporter(
				$c->get( SScribe_HTML_Exporter::class ),
				$c->get( SScribe_Logger::class ),
				$c->get( SScribe_Filesystem::class )
			)
		);

		$container->singleton(
			SScribe_Markdown_Exporter::class,
			fn( SScribe_Container $c ) => new SScribe_Markdown_Exporter(
				$c->get( SScribe_Logger::class ),
				$c->get( SScribe_Filesystem::class )
			)
		);

		$container->singleton(
			SScribe_Admin::class,
			fn( SScribe_Container $c ) => new SScribe_Admin(
				$c->get( SScribe_Page_Collector::class ),
				$c->get( SScribe_SEO_Reader::class )
			)
		);

		$container->singleton(
			SScribe_Batch_Processor::class,
			fn( SScribe_Container $c ) => new SScribe_Batch_Processor(
				$c->get( SScribe_Page_Collector::class ),
				$c->get( SScribe_Zip_Handler::class ),
				$c->get( SScribe_Session::class ),
				$c->get( SScribe_Logger::class )
			)
		);
	}

	/**
	 * Register admin-specific hooks.
	 *
	 * @return void
	 */
	private function define_admin_hooks(): void {
		$container = SScribe_Container::instance();
		$admin     = $container->get( SScribe_Admin::class );

		$this->loader->add_action( 'admin_menu', $admin, 'add_admin_menu' );
		$this->loader->add_action( 'admin_enqueue_scripts', $admin, 'enqueue_admin_assets' );
		$this->loader->add_action( 'admin_init', $admin, 'maybe_send_csp_headers' );
		$this->loader->add_filter( 'plugin_action_links_' . SSCRIBE_PLUGIN_BASENAME, $admin, 'add_plugin_action_links' );
	}

	/**
	 * Register AJAX hooks.
	 *
	 * @return void
	 */
	private function define_ajax_hooks(): void {
		$container = SScribe_Container::instance();
		$batch     = $container->get( SScribe_Batch_Processor::class );

		$this->loader->add_action( 'wp_ajax_sscribe_start_export', $batch, 'ajax_start_export' );
		$this->loader->add_action( 'wp_ajax_sscribe_process_batch', $batch, 'ajax_process_batch' );
		$this->loader->add_action( 'wp_ajax_sscribe_download', $batch, 'ajax_download' );
		$this->loader->add_action( 'wp_ajax_sscribe_get_status_counts', $batch, 'ajax_get_status_counts' );
		$this->loader->add_action( 'wp_ajax_sscribe_cancel_export', $batch, 'ajax_cancel_export' );
		$this->loader->add_action( 'wp_ajax_sscribe_delete_export', $batch, 'ajax_delete_export' );
		$this->loader->add_action( 'wp_ajax_sscribe_get_export_log', $batch, 'ajax_get_export_log' );
		$this->loader->add_action( 'wp_ajax_sscribe_clear_session', $batch, 'ajax_clear_session' );
		$this->loader->add_action( 'wp_ajax_sscribe_preflight_check', $batch, 'ajax_preflight_check' );
		$this->loader->add_action( 'wp_ajax_sscribe_get_export_preview', $batch, 'ajax_get_export_preview' );
		$this->loader->add_action( 'wp_ajax_sscribe_get_recent_exports', $batch, 'ajax_get_recent_exports' );
		$this->loader->add_action( 'wp_ajax_sscribe_get_support_info', $batch, 'ajax_get_support_info' );
	}

	/**
	 * Register cron hooks.
	 *
	 * @return void
	 */
	private function define_cron_hooks(): void {
		$container = SScribe_Container::instance();
		$zip       = $container->get( SScribe_Zip_Handler::class );
		$this->loader->add_action( 'sscribe_cleanup_exports', $zip, 'cleanup_expired' );

		$this->loader->add_action( 'sscribe_cleanup_sessions', $this, 'cleanup_sessions' );
	}

	/**
	 * Register privacy-related hooks.
	 *
	 * @return void
	 */
	private function define_privacy_hooks(): void {
		$privacy = new SScribe_Privacy();

		$this->loader->add_action( 'admin_init', $privacy, 'register_privacy_policy' );
		$this->loader->add_filter( 'wp_privacy_personal_data_exporters', $privacy, 'register_exporter' );
		$this->loader->add_filter( 'wp_privacy_personal_data_erasers', $privacy, 'register_eraser' );
	}

	/**
	 * Cleanup expired session data from the database.
	 *
	 * @return void
	 */
	public function cleanup_sessions(): void {
		$session = SScribe_Container::instance()->get( SScribe_Session::class );
		$session->cleanup_expired( 24 * HOUR_IN_SECONDS );

		SScribe_Logger::cleanup_old_logs( 7 );
	}

	/**
	 * Run the loader to execute all hooks.
	 *
	 * This method performs all initialization and hook registration.
	 * It should be called after instantiation to activate the plugin.
	 *
	 * @return void
	 */
	public function run(): void {
		$this->init_i18n();
		$this->register_services();
		$this->define_admin_hooks();
		$this->define_ajax_hooks();
		$this->define_cron_hooks();
		$this->define_privacy_hooks();

		$this->loader->run();
	}

	/**
	 * Initialize internationalization.
	 *
	 * @return void
	 */
	private function init_i18n(): void {
		load_plugin_textdomain(
			'sscribe-export-site-pages',
			false,
			dirname( SSCRIBE_PLUGIN_BASENAME ) . '/languages'
		);
	}
}
