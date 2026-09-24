<?php
/**
 * SScribe Main Plugin Class
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
		$debug     = SSCRIBE_DEBUG;

		$container->singleton( SScribe_Logger::class, fn() => SScribe_Logger::instance( $debug ) );
		$container->singleton( SScribe_Content_Parser::class, fn() => new SScribe_Content_Parser() );
		$container->singleton( SScribe_SEO_Reader::class, fn() => new SScribe_SEO_Reader() );
		$container->singleton( SScribe_Zip_Handler::class, fn() => new SScribe_Zip_Handler() );
		$container->singleton( SScribe_Page_Collector::class, fn() => new SScribe_Page_Collector() );
		$container->singleton( SScribe_Session::class, fn() => new SScribe_Session() );
		$container->singleton( SScribe_Filesystem::class, fn() => new SScribe_Filesystem() );
		$container->singleton(
			SScribe_Export_Lock_Manager::class,
			fn( SScribe_Container $c ) => new SScribe_Export_Lock_Manager( $c->get( SScribe_Logger::class ) )
		);

		$container->bind(
			SScribe_Exporter::class,
			fn( SScribe_Container $c ) => new SScribe_Exporter( $c->get( SScribe_Content_Parser::class ) )
		);

		$container->bind(
			SScribe_HTML_Exporter::class,
			fn( SScribe_Container $c ) => new SScribe_HTML_Exporter(
				$c->get( SScribe_Logger::class ),
				$c->get( SScribe_Filesystem::class )
			)
		);

		$container->bind(
			SScribe_DOCX_Exporter::class,
			fn( SScribe_Container $c ) => new SScribe_DOCX_Exporter(
				$c->get( SScribe_Exporter::class ),
				$c->get( SScribe_Logger::class )
			)
		);

		$container->bind(
			SScribe_PDF_Exporter::class,
			fn( SScribe_Container $c ) => new SScribe_PDF_Exporter(
				$c->get( SScribe_HTML_Exporter::class ),
				$c->get( SScribe_Logger::class ),
				$c->get( SScribe_Filesystem::class )
			)
		);

		$container->bind(
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
			SScribe_Batch_File_Handler::class,
			fn( SScribe_Container $c ) => new SScribe_Batch_File_Handler(
				new SScribe_Export_Rate_Limiter(),
				$c->get( SScribe_Zip_Handler::class ),
				$c->get( SScribe_Logger::class ),
				new SScribe_Export_Auditor()
			)
		);

		$container->singleton(
			SScribe_Export_Query_Controller::class,
			fn( SScribe_Container $c ) => new SScribe_Export_Query_Controller(
				new SScribe_Export_Rate_Limiter(),
				new SScribe_Diagnostics(),
				$c->get( SScribe_Page_Collector::class ),
				$c->get( SScribe_Logger::class ),
				$c->get( SScribe_Zip_Handler::class ),
				new SScribe_Adaptive_Metrics(),
				new SScribe_Export_Error_Handler()
			)
		);

		$container->singleton(
			SScribe_Batch_Processor::class,
			fn( SScribe_Container $c ) => new SScribe_Batch_Processor(
				$c->get( SScribe_Page_Collector::class ),
				$c->get( SScribe_Zip_Handler::class ),
				$c->get( SScribe_Session::class ),
				$c->get( SScribe_Logger::class ),
				$c->get( SScribe_Batch_File_Handler::class ),
				$c->get( SScribe_Export_Query_Controller::class )
			)
		);
	}

	/**
	 * Register admin-specific hooks.
	 */
	private function define_admin_hooks(): void {
		$container = SScribe_Container::instance();
		$admin     = $container->get( SScribe_Admin::class );

		$this->loader->add_action( 'admin_menu', $admin, 'add_admin_menu' );
		$this->loader->add_action( 'admin_init', $admin, 'maybe_redirect_after_activation' );
		$this->loader->add_action( 'admin_enqueue_scripts', $admin, 'enqueue_admin_assets' );
		$this->loader->add_action( 'admin_notices', $this, 'render_vendor_dependency_notice' );
		$this->loader->add_filter( 'plugin_action_links_' . SSCRIBE_PLUGIN_BASENAME, $admin, 'add_plugin_action_links' );
	}

	/**
	 * Display admin notice for missing vendor dependencies.
	 */
	public function render_vendor_dependency_notice(): void {
		if ( ! current_user_can( SScribe_Capabilities::get_required() ) ) {
			return;
		}

		if ( ! function_exists( 'get_current_screen' ) ) {
			return;
		}
		$screen    = get_current_screen();
		$screen_id = $screen ? (string) $screen->id : '';
		if ( ! in_array( $screen_id, array( 'toplevel_page_sscribe-export', 'plugins', 'plugins-network' ), true ) ) {
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
	 * Invalidate admin page cache when posts are saved.
	 *
	 * Bumps the global content cache generation option. Stable transient keys
	 * store that generation inside their payload, while request/object caches
	 * are refreshed by the normal WordPress cache lifecycle. A generation
	 * mismatch invalidates content-derived values without creating a new
	 * wp_options transient key after every content mutation.
	 *
	 * @param int $post_id Post ID that was saved.
	 */
	public function invalidate_admin_page_cache( int $post_id ): void {
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		// Do not hard-code page/post here. Selectable custom post types may be
		// added through WordPress registration/filtering and are valid SScribe
		// export sources; any real post mutation can therefore invalidate the
		// shared content-derived caches.
		if ( ! get_post_type( $post_id ) ) {
			return;
		}

		$cache_key = 'sscribe_admin_page_data_v2_' . SSCRIBE_VERSION . '_' . get_current_blog_id();
		delete_transient( $cache_key );

		$this->bump_content_cache_generation();
	}

	/**
	 * Increment the global content cache generation counter.
	 *
	 * Any code that caches content derived from posts/pages should include
	 * the current generation (see SScribe_Collector::content_cache_generation())
	 * in its cache key so this counter acts as a global invalidation bus.
	 *
	 * @return int New generation value.
	 */
	public function bump_content_cache_generation(): int {
		$current = (int) get_option( 'sscribe_content_cache_generation', 1 );
		$next    = max( 1, $current + 1 );
		update_option( 'sscribe_content_cache_generation', $next, false );
		return $next;
	}

	/**
	 * Read the current content cache generation.
	 *
	 * @return int Current generation, defaulting to 1 if unset.
	 */
	public function get_content_cache_generation(): int {
		return max( 1, (int) get_option( 'sscribe_content_cache_generation', 1 ) );
	}

	/**
	 * Register content-mutation hooks that must remain active for every request
	 * type, including REST/front-end writes.
	 */
	private function define_content_hooks(): void {
		$this->loader->add_action( 'save_post', $this, 'invalidate_admin_page_cache' );
		$this->loader->add_action( 'trashed_post', $this, 'invalidate_admin_page_cache' );
		$this->loader->add_action( 'deleted_post', $this, 'invalidate_admin_page_cache' );
		$this->loader->add_action( 'untrashed_post', $this, 'invalidate_admin_page_cache' );
	}

	/**
	 * Register AJAX hooks for background processing.
	 */
	private function define_ajax_hooks(): void {
		$container = SScribe_Container::instance();

		// Debug endpoints are lightweight to register. Their filesystem/rate
		// limiter work happens only inside the callbacks when the AJAX hook fires.
		$debug = new SScribe_Admin_Debug();
		$debug->register_hooks();

		$batch_resolver = static function () use ( $container ): SScribe_Batch_Processor {
			$service = $container->get( SScribe_Batch_Processor::class );
			if ( ! $service instanceof SScribe_Batch_Processor ) {
				throw new LogicException( 'SScribe batch processor service is unavailable.' );
			}
			return $service;
		};

		$cap              = SScribe_Capabilities::get_required();
		$health_cap       = SScribe_Capabilities::get_health_required();
		$language_request = new SScribe_Language_Request();

		// Translate the UI-only __all__ sentinel before the guarded Preview/Start
		// callbacks read request data. The normalizer is guarded too: no SScribe
		// wp_ajax_* callback is permitted to run before nonce/capability checks.
		$this->loader->add_guarded_ajax_action(
			'wp_ajax_sscribe_start_export',
			$language_request,
			'normalize_for_export_endpoint',
			$cap,
			'sscribe_export_nonce',
			'nonce',
			1,
			0
		);
		$this->loader->add_guarded_ajax_action(
			'wp_ajax_sscribe_get_export_preview',
			$language_request,
			'normalize_for_export_endpoint',
			$cap,
			'sscribe_export_nonce',
			'nonce',
			1,
			0
		);

		$this->loader->add_guarded_lazy_ajax_action( 'wp_ajax_sscribe_start_export', $batch_resolver, 'ajax_start_export', $cap );
		$this->loader->add_guarded_lazy_ajax_action( 'wp_ajax_sscribe_process_batch', $batch_resolver, 'ajax_process_batch', $cap );
		$this->loader->add_guarded_lazy_ajax_action( 'wp_ajax_sscribe_finalize_export', $batch_resolver, 'ajax_finalize_export', $cap );
		$this->loader->add_guarded_lazy_ajax_action( 'wp_ajax_sscribe_download', $batch_resolver, 'ajax_download', $cap, 'sscribe_download' );
		$this->loader->add_guarded_lazy_ajax_action( 'wp_ajax_sscribe_get_status_counts', $batch_resolver, 'ajax_get_status_counts', $cap );
		$this->loader->add_guarded_lazy_ajax_action( 'wp_ajax_sscribe_get_all_status_counts', $batch_resolver, 'ajax_get_all_status_counts', $cap );
		$this->loader->add_guarded_lazy_ajax_action( 'wp_ajax_sscribe_cancel_export', $batch_resolver, 'ajax_cancel_export', $cap );
		$this->loader->add_guarded_lazy_ajax_action( 'wp_ajax_sscribe_delete_export', $batch_resolver, 'ajax_delete_export', $cap, 'sscribe_download' );
		$this->loader->add_guarded_lazy_ajax_action( 'wp_ajax_sscribe_get_export_log', $batch_resolver, 'ajax_get_export_log', $cap, 'sscribe_download' );
		$this->loader->add_guarded_lazy_ajax_action( 'wp_ajax_sscribe_clear_session', $batch_resolver, 'ajax_clear_session', $cap );
		$this->loader->add_guarded_lazy_ajax_action( 'wp_ajax_sscribe_preflight_check', $batch_resolver, 'ajax_preflight_check', $cap );
		$this->loader->add_guarded_lazy_ajax_action( 'wp_ajax_sscribe_get_export_preview', $batch_resolver, 'ajax_get_export_preview', $cap );
		$this->loader->add_guarded_lazy_ajax_action( 'wp_ajax_sscribe_get_recent_exports', $batch_resolver, 'ajax_get_recent_exports', $cap );
		$this->loader->add_guarded_lazy_ajax_action( 'wp_ajax_sscribe_get_support_info', $batch_resolver, 'ajax_get_support_info', $health_cap, 'sscribe_health_nonce' );
		$this->loader->add_guarded_lazy_ajax_action( 'wp_ajax_sscribe_check_active_session', $batch_resolver, 'ajax_check_active_session', $cap );
	}

	/**
	 * Register scheduled task hooks.
	 */
	private function define_cron_hooks(): void {
		$this->loader->add_action( 'sscribe_cleanup_exports', $this, 'cleanup_exports' );
		$this->loader->add_action( 'sscribe_cleanup_sessions', $this, 'cleanup_sessions' );
		$this->loader->add_action( 'sscribe_cleanup_sessions', $this, 'rotate_session_signing_key', 99 );
		$this->loader->add_action( 'sscribe_cleanup_audit_trail', $this, 'cleanup_audit_trail' );
	}

	/**
	 * Clean up expired export artifacts when the scheduled hook fires.
	 */
	public function cleanup_exports(): void {
		$zip = SScribe_Container::instance()->get( SScribe_Zip_Handler::class );
		if ( ! $zip instanceof SScribe_Zip_Handler ) {
			throw new LogicException( 'SScribe ZIP handler service is unavailable.' );
		}
		$zip->cleanup_expired();
	}

	/**
	 * Rotate the session signing key only when the scheduled hook fires.
	 */
	public function rotate_session_signing_key(): void {
		$session = SScribe_Container::instance()->get( SScribe_Session::class );
		if ( ! $session instanceof SScribe_Session ) {
			throw new LogicException( 'SScribe session service is unavailable.' );
		}
		$session->maybe_rotate_signing_key();
	}

	/**
	 * Register multisite lifecycle hooks.
	 */
	private function define_lifecycle_hooks(): void {
		$this->loader->add_action( 'wp_initialize_site', new SScribe_Activator(), 'activate_new_site' );
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
		$lock_manager = SScribe_Container::instance()->get( SScribe_Export_Lock_Manager::class );
		$lock_name    = 'cron-sessions';
		$lock_token   = $lock_manager->acquire_lock( $lock_name, 2 * MINUTE_IN_SECONDS, 110 );
		if ( null === $lock_token ) {
			return;
		}

		try {
			$session = SScribe_Container::instance()->get( SScribe_Session::class );
			$session->cleanup_expired( 24 * HOUR_IN_SECONDS );

			SScribe_Logger::cleanup_old_logs( 7 );

			require_once SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-export-log.php';
			SScribe_Export_Log::cleanup_old_logs( 72 );

			$lock_manager->cleanup_expired_locks();
		} finally {
			$lock_manager->release_lock( $lock_name, $lock_token );
		}
	}

	/**
	 * Clean up old audit and export-statistics entries on a daily schedule.
	 *
	 * Removes entries older than 90 days to prevent unbounded table growth.
	 */
	public function cleanup_audit_trail(): void {
		require_once SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-audit-trail.php';
		require_once SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-export-stats.php';
		$audit_trail   = new SScribe_Audit_Trail();
		$export_stats  = new SScribe_Export_Stats();
		$audit_deleted = $audit_trail->cleanup( 90 );
		$stats_deleted = $export_stats->cleanup( 365 );
		$deleted       = $audit_deleted + $stats_deleted;

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && $deleted > 0 ) {
			error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				sprintf( 'SScribe: Cleaned up %d old audit trail entries.', $deleted )
			);
		}
	}

	/**
	 * Start the plugin by registering all hooks and running the loader.
	 */
	public function run(): void {

		require_once SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-upgrader.php';
		SScribe_Upgrader::maybe_upgrade();

		require_once SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-request-id.php';
		require_once SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-operational-logger.php';
		require_once SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-fatal-handler.php';
		require_once SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-rate-limit-response.php';
		require_once SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-lock-response.php';
		SScribe_Fatal_Handler::boot( SSCRIBE_PLUGIN_DIR );

		\SScribe_Request_Id::current();

		$this->register_services();

		$is_ajax_request  = function_exists( 'wp_doing_ajax' ) && wp_doing_ajax();
		$is_admin_request = function_exists( 'is_admin' ) && is_admin();

		// Register hook names globally, but keep heavyweight service resolution
		// lazy. WordPress, WP-CLI, tests, and direct do_action() callers are then
		// free to dispatch the hooks without requiring request-mode globals.
		$this->define_content_hooks();
		$this->define_privacy_hooks();
		$this->define_ajax_hooks();
		$this->define_cron_hooks();
		$this->define_lifecycle_hooks();

		if ( $is_admin_request && ! $is_ajax_request ) {
			$this->define_admin_hooks();
		}

		$this->loader->run();
	}
}
