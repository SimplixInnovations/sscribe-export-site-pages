<?php
/**
 * SScribe Main Plugin Class
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main plugin class that initializes and coordinates all services.
 */
class SScribe {

	/**
	 * Plugin loader instance.
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
	 * Initialize the plugin.
	 */
	public function __construct() {
		$this->version = SSCRIBE_VERSION;
		$this->loader  = new SScribe_Loader();
	}

	/**
	 * Register plugin services in the container.
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
				$c->get( SScribe_SEO_Reader::class ),
				$c->get( SScribe_Zip_Handler::class )
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
	 */
	private function define_admin_hooks(): void {
		$container = SScribe_Container::instance();
		$admin     = $container->get( SScribe_Admin::class );

		add_action( 'admin_menu', array( $admin, 'add_admin_menu' ) );
		add_action( 'admin_init', array( $admin, 'maybe_redirect_after_activation' ) );
		add_action( 'admin_enqueue_scripts', array( $admin, 'enqueue_admin_assets' ) );
		add_action( 'admin_init', array( $admin, 'maybe_send_csp_headers' ) );
		add_action( 'admin_notices', array( $this, 'render_vendor_dependency_notice' ) );
		add_action( 'save_post', array( $this, 'invalidate_admin_page_cache' ) );
		add_filter( 'plugin_action_links_' . SSCRIBE_PLUGIN_BASENAME, array( $admin, 'add_plugin_action_links' ) );
	}

	/**
	 * Display admin notice for missing vendor dependencies.
	 */
	public function render_vendor_dependency_notice(): void {
		if ( ! current_user_can( apply_filters( 'sscribe_export_capability', 'manage_options' ) ) ) {
			return;
		}

		static $missing = null;
		if ( null === $missing ) {
			$diagnostics = new SScribe_Diagnostics();
			$missing     = $diagnostics->check_vendor_dependencies();
		}

		if ( empty( $missing ) ) {
			return;
		}

		$message = sprintf(
			/* translators: %s: missing dependency class list. */

			__( 'SScribe is missing required vendor dependencies: %s. Run composer install in the plugin directory to restore export functionality.', 'sscribe-export-site-pages' ),
			implode( ', ', $missing )
		);

		echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
	}

	/**
	 * Clear admin page cache when posts change.
	 *
	 * @param int $post_id Post ID that was saved.
	 */
	/**
	 * Invalidate admin page cache when posts are saved.
	 *
	 * @param int $post_id Post ID that was saved.
	 */
	public function invalidate_admin_page_cache( int $post_id ): void {
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		$post_type = get_post_type( $post_id );
		if ( ! $post_type || ! in_array( $post_type, array( 'page', 'post' ), true ) ) {
			return;
		}

		$cache_key = 'sscribe_admin_page_data_v' . SSCRIBE_VERSION;
		delete_transient( $cache_key );
	}

	/**
	 * Register AJAX hooks for background processing.
	 */
	private function define_ajax_hooks(): void {
		$container = SScribe_Container::instance();
		$batch     = $container->get( SScribe_Batch_Processor::class );

		$this->loader->add_action( 'wp_ajax_sscribe_start_export', $batch, 'ajax_start_export' );
		$this->loader->add_action( 'wp_ajax_sscribe_process_batch', $batch, 'ajax_process_batch' );
		$this->loader->add_action( 'wp_ajax_sscribe_finalize_export', $batch, 'ajax_finalize_export' );
		$this->loader->add_action( 'wp_ajax_sscribe_download', $batch, 'ajax_download' );
		$this->loader->add_action( 'wp_ajax_sscribe_refresh_download_nonce', $batch, 'ajax_refresh_download_nonce' );
		$this->loader->add_action( 'wp_ajax_sscribe_get_status_counts', $batch, 'ajax_get_status_counts' );
		$this->loader->add_action( 'wp_ajax_sscribe_cancel_export', $batch, 'ajax_cancel_export' );
		$this->loader->add_action( 'wp_ajax_sscribe_delete_export', $batch, 'ajax_delete_export' );
		$this->loader->add_action( 'wp_ajax_sscribe_get_export_log', $batch, 'ajax_get_export_log' );
		$this->loader->add_action( 'wp_ajax_sscribe_clear_session', $batch, 'ajax_clear_session' );
		$this->loader->add_action( 'wp_ajax_sscribe_preflight_check', $batch, 'ajax_preflight_check' );
		$this->loader->add_action( 'wp_ajax_sscribe_get_export_preview', $batch, 'ajax_get_export_preview' );
		$this->loader->add_action( 'wp_ajax_sscribe_get_recent_exports', $batch, 'ajax_get_recent_exports' );
		$this->loader->add_action( 'wp_ajax_sscribe_get_support_info', $batch, 'ajax_get_support_info' );
		$this->loader->add_action( 'wp_ajax_sscribe_health_check', $batch, 'ajax_health_check' );
		$this->loader->add_action( 'wp_ajax_nopriv_sscribe_health_check', $batch, 'ajax_health_check' );
	}

	/**
	 * Register scheduled task hooks.
	 */
	private function define_cron_hooks(): void {
		$container = SScribe_Container::instance();
		$zip       = $container->get( SScribe_Zip_Handler::class );
		$this->loader->add_action( 'sscribe_cleanup_exports', $zip, 'cleanup_expired' );

		$this->loader->add_action( 'sscribe_cleanup_sessions', $this, 'cleanup_sessions' );
	}

	/**
	 * Register privacy-related hooks for data handling.
	 */
	private function define_privacy_hooks(): void {
		$privacy = new SScribe_Privacy();

		$this->loader->add_action( 'admin_init', $privacy, 'register_privacy_policy' );
		$this->loader->add_filter( 'wp_privacy_personal_data_exporters', $privacy, 'register_exporter' );
		$this->loader->add_filter( 'wp_privacy_personal_data_erasers', $privacy, 'register_eraser' );
	}

	/**
	 * Clean up expired sessions and old log entries.
	 */
	public function cleanup_sessions(): void {

		if ( ! get_transient( 'sscribe_cron_sessions_lock' ) ) {
			set_transient( 'sscribe_cron_sessions_lock', true, 2 * MINUTE_IN_SECONDS );

			$session = SScribe_Container::instance()->get( SScribe_Session::class );
			$session->cleanup_expired( 24 * HOUR_IN_SECONDS );

			SScribe_Logger::cleanup_old_logs( 7 );

			delete_transient( 'sscribe_cron_sessions_lock' );
		}
	}

	/**
	 * Start the plugin by registering all hooks and running the loader.
	 */
	public function run(): void {

		require_once SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-upgrader.php';
		SScribe_Upgrader::maybe_upgrade();

		$this->init_i18n();
		$this->register_services();
		$this->define_admin_hooks();
		$this->define_ajax_hooks();
		$this->define_cron_hooks();
		$this->define_privacy_hooks();

		$this->loader->run();
	}

	/**
	 * Initialize internationalization support.
	 *
	 * Note: load_plugin_textdomain() is kept for self-hosted installs.
	 * WordPress.org-hosted plugins auto-load translations since WP 4.6.
	 */
	private function init_i18n(): void {
		add_action(
			'init',
			static function () {
				// phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound -- Required for self-hosted/non-WP.org installs.
				load_plugin_textdomain(
					'sscribe-export-site-pages',
					false,
					dirname( SSCRIBE_PLUGIN_BASENAME ) . '/languages'
				);
			}
		);
	}
}
