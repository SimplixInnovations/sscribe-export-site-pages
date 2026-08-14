<?php
/**
 * SScribe Admin
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
 * Admin functionality for SScribe plugin.
 */
class SScribe_Admin {

	/**
	 * Page collector service.
	 *
	 * @var SScribe_Page_Collector
	 */
	private SScribe_Page_Collector $collector;

	/**
	 * SEO reader service.
	 *
	 * @var SScribe_SEO_Reader
	 */
	private SScribe_SEO_Reader $seo_reader;

	/**
	 * Zip handler service.
	 *
	 * @var SScribe_Zip_Handler
	 */
	private readonly SScribe_Zip_Handler $zip_handler;

	/**
	 * Debug console handler.
	 *
	 * @var SScribe_Admin_Debug
	 */
	private SScribe_Admin_Debug $debug;

	/**
	 * Initialize the admin interface.
	 *
	 * @param SScribe_Page_Collector|null $collector   Page collector.
	 * @param SScribe_SEO_Reader|null     $seo_reader  SEO reader.
	 * @param SScribe_Zip_Handler|null    $zip_handler Zip handler.
	 */
	public function __construct(
		?SScribe_Page_Collector $collector = null,
		?SScribe_SEO_Reader $seo_reader = null,
		?SScribe_Zip_Handler $zip_handler = null
	) {
		$this->collector   = $collector ?? new SScribe_Page_Collector();
		$this->seo_reader  = $seo_reader ?? new SScribe_SEO_Reader();
		$this->zip_handler = $zip_handler ?? new SScribe_Zip_Handler();

		$this->debug = new SScribe_Admin_Debug();

		$this->debug->register_hooks();
	}

	/**
	 * Add the admin menu page.
	 */
	public function add_admin_menu(): void {
		add_menu_page(
			__( 'SScribe Export', 'sscribe-export-site-pages' ),
			__( 'SScribe Export', 'sscribe-export-site-pages' ),
			$this->get_required_capability(),
			'sscribe-export',
			array( $this, 'render_admin_page' ),
			'dashicons-media-document',
			58
		);
	}

	/**
	 * Get the capability required to access export functions.
	 *
	 * @return string Capability name.
	 */
	private function get_required_capability(): string {
		return SScribe_Capabilities::get_required();
	}

	/**
	 * Get a nonce for download actions.
	 *
	 * @return string Nonce value.
	 */
	private function get_download_nonce(): string {
		return wp_create_nonce( 'sscribe_download' );
	}

	/**
	 * Redirect to the plugin page after activation.
	 */
	public function maybe_redirect_after_activation(): void {
		if ( wp_doing_ajax() || ! is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}

		if ( ! get_transient( 'sscribe_activation_redirect' ) ) {
			return;
		}

		if ( ! current_user_can( $this->get_required_capability() ) ) {
			delete_transient( 'sscribe_activation_redirect' );
			return;
		}

		delete_transient( 'sscribe_activation_redirect' );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Redirect guard only checks activation flow markers.
		$activate_multi = isset( $_GET['activate-multi'] ) && is_string( $_GET['activate-multi'] ) ? sanitize_key( wp_unslash( $_GET['activate-multi'] ) ) : '';
		if ( '1' === $activate_multi ) {
			return;
		}

		$this->redirect_to_plugin_page();
	}

	/**
	 * Redirect to the plugin's main admin page.
	 */
	protected function redirect_to_plugin_page(): void {
		wp_safe_redirect( admin_url( 'admin.php?page=sscribe-export' ) );
		exit;
	}

	/**
	 * Load admin CSS and JS on the export page.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public function enqueue_admin_assets( string $hook_suffix ): void {

		if ( 'toplevel_page_sscribe-export' !== $hook_suffix ) {
			return;
		}

		$tokens_css_version = file_exists( SSCRIBE_PLUGIN_DIR . 'admin/css/sscribe-tokens.css' )
			? filemtime( SSCRIBE_PLUGIN_DIR . 'admin/css/sscribe-tokens.css' )
			: SSCRIBE_VERSION;

		$css_version = file_exists( SSCRIBE_PLUGIN_DIR . 'admin/css/sscribe-admin.css' )
			? filemtime( SSCRIBE_PLUGIN_DIR . 'admin/css/sscribe-admin.css' )
			: SSCRIBE_VERSION;

		$js_version = file_exists( SSCRIBE_PLUGIN_DIR . 'admin/js/sscribe-admin.js' )
			? filemtime( SSCRIBE_PLUGIN_DIR . 'admin/js/sscribe-admin.js' )
			: SSCRIBE_VERSION;

		$debug_css_version = file_exists( SSCRIBE_PLUGIN_DIR . 'admin/css/sscribe-debug-console.css' )
			? filemtime( SSCRIBE_PLUGIN_DIR . 'admin/css/sscribe-debug-console.css' )
			: SSCRIBE_VERSION;

		$debug_js_version = file_exists( SSCRIBE_PLUGIN_DIR . 'admin/js/sscribe-debug-console.js' )
			? filemtime( SSCRIBE_PLUGIN_DIR . 'admin/js/sscribe-debug-console.js' )
			: SSCRIBE_VERSION;

		// filemtime() returns false on read-only/mtime-stripped production filesystems
		// (Pantheon, Object Cache Pro, read-only WPEngine mounts). Fall back to the
		// plugin version so wp_enqueue_style still receives a valid scalar.
		$tokens_css_version = $tokens_css_version ?: SSCRIBE_VERSION;
		$css_version        = $css_version ?: SSCRIBE_VERSION;
		$js_version         = $js_version ?: SSCRIBE_VERSION;
		$debug_css_version  = $debug_css_version ?: SSCRIBE_VERSION;
		$debug_js_version   = $debug_js_version ?: SSCRIBE_VERSION;

		wp_enqueue_style(
			'sscribe-tokens',
			SSCRIBE_PLUGIN_URL . 'admin/css/sscribe-tokens.css',
			array(),
			$tokens_css_version
		);

		wp_enqueue_style(
			'sscribe-admin',
			SSCRIBE_PLUGIN_URL . 'admin/css/sscribe-admin.css',
			array( 'sscribe-tokens' ),
			$css_version
		);

		wp_enqueue_script(
			'sscribe-admin',
			SSCRIBE_PLUGIN_URL . 'admin/js/sscribe-admin.js',
			array( 'jquery' ),
			$js_version,
			true
		);

		// The debug controls must remain usable while logging is disabled so
		// an authorized administrator can enable it from this screen.
		if ( current_user_can( 'manage_options' ) ) {
			wp_enqueue_style(
				'sscribe-debug-console',
				SSCRIBE_PLUGIN_URL . 'admin/css/sscribe-debug-console.css',
				array( 'sscribe-admin' ),
				$debug_css_version
			);

			wp_enqueue_script(
				'sscribe-debug-console',
				SSCRIBE_PLUGIN_URL . 'admin/js/sscribe-debug-console.js',
				array( 'jquery', 'sscribe-admin' ),
				$debug_js_version,
				true
			);
		}

		add_action( 'admin_print_footer_scripts', array( $this, 'print_localized_data' ), 0 );
	}

	/**
	 * Print localized JS data via wp_add_inline_script() instead of
	 * echoing a raw <script> tag.
	 *
	 * WordPress's wp_localize_script() outputs inline <script> tags
	 * without a CSP nonce attribute. wp_add_inline_script() is the
	 * recommended path because its output flows through
	 * wp_inline_script_attributes, which the site can filter to add a
	 * CSP nonce for free. Hooks at priority 0 so it fires before
	 * wp_print_footer_scripts (priority 20).
	 */
	public function print_localized_data(): void {
		$screen = get_current_screen();
		if ( ! $screen || 'toplevel_page_sscribe-export' !== $screen->id ) {
			return;
		}

		$encoded_data = wp_json_encode( $this->build_localized_data(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS );
		$body         = 'var sscribe_data = ' . ( false !== $encoded_data ? $encoded_data : '{}' ) . ';';

		wp_add_inline_script( 'sscribe-admin', $body, 'before' );
	}

	/**
	 * Build the localized data array for print_localized_data().
	 *
	 * Extracted so both the printer and any tests can construct the same
	 * payload without duplicating the 100+ string entries.
	 *
	 * @return array<string, mixed>
	 */
	private function build_localized_data(): array {
		$post_type_strings = $this->build_post_type_strings();

		return array(
			'ajaxurl'         => admin_url( 'admin-ajax.php' ),
			'nonce'           => wp_create_nonce( 'sscribe_export_nonce' ),
			'download_nonce'  => $this->get_download_nonce(),
			'health_nonce'    => current_user_can( SScribe_Capabilities::get_health_required() ) ? wp_create_nonce( 'sscribe_health_nonce' ) : '',
			'icons_url'       => SSCRIBE_PLUGIN_URL . 'assets/icons/',
			'auto_download'   => (bool) apply_filters( 'sscribe_auto_download_on_complete', false ),

			'refresh_interval' => min( 300000, max( 5000, (int) apply_filters( 'sscribe_debug_refresh_interval_ms', 10000 ) ) ),
			'strings'        => array(
				'starting'               => __( 'Starting export...', 'sscribe-export-site-pages' ),
				'processing'             => __( 'Processing...', 'sscribe-export-site-pages' ),
				'complete'               => __( 'Export complete!', 'sscribe-export-site-pages' ),
				'error'                  => __( 'An error occurred. Please try again.', 'sscribe-export-site-pages' ),
				'download'               => __( 'Download ZIP', 'sscribe-export-site-pages' ),
				'generating'             => __( 'Generating documents...', 'sscribe-export-site-pages' ),
				'confirm_export'         => __( 'Start exporting pages?', 'sscribe-export-site-pages' ),
				'confirm_delete'         => __( 'Delete this export file?', 'sscribe-export-site-pages' ),
				'auto_delete'            => __( 'This file will be automatically deleted in 72 hours for security.', 'sscribe-export-site-pages' ),
				'cancel'                 => __( 'Cancel Export', 'sscribe-export-site-pages' ),
				'cancelling'             => __( 'Cancelling...', 'sscribe-export-site-pages' ),
				'loading_log'            => __( 'Loading log...', 'sscribe-export-site-pages' ),
				'log_not_found'          => __( 'Log not found.', 'sscribe-export-site-pages' ),
				'log_load_failed'        => __( 'Failed to load log.', 'sscribe-export-site-pages' ),
				'delete_failed'          => __( 'Failed to delete export.', 'sscribe-export-site-pages' ),
				'delete_success'         => __( 'Export deleted.', 'sscribe-export-site-pages' ),
				'history_empty'          => __( 'Your recent export packages will appear here.', 'sscribe-export-site-pages' ),
				'history_caption'        => __( 'Recent export packages', 'sscribe-export-site-pages' ),
				'history_col_select'     => __( 'Select', 'sscribe-export-site-pages' ),
				'history_col_export'     => __( 'Export', 'sscribe-export-site-pages' ),
				'history_col_actions'    => __( 'Actions', 'sscribe-export-site-pages' ),
				'estimated_time'         => __( 'Estimated time:', 'sscribe-export-site-pages' ),
				'minutes'                => __( 'minutes', 'sscribe-export-site-pages' ),
				'minute'                 => __( 'minute', 'sscribe-export-site-pages' ),
				'seconds'                => __( 'seconds', 'sscribe-export-site-pages' ),
				'sec_remaining'          => __( 'sec remaining', 'sscribe-export-site-pages' ),
				/* translators: %1$d: minutes, %2$d: seconds */
				'min_sec_remaining'      => __( '%1$d min %2$d sec remaining', 'sscribe-export-site-pages' ),
				'hour_suffix'            => __( 'h', 'sscribe-export-site-pages' ),
				'minute_suffix'          => __( 'm', 'sscribe-export-site-pages' ),
				'log_total'              => __( 'Total:', 'sscribe-export-site-pages' ),
				'log_pages'              => __( 'pages', 'sscribe-export-site-pages' ),
				'log_page'              => __( 'page', 'sscribe-export-site-pages' ),
				'log_success_label'      => __( 'Success:', 'sscribe-export-site-pages' ),
				'log_failed_label'       => __( 'Failed:', 'sscribe-export-site-pages' ),
				'log_page_details'       => __( 'Page Details', 'sscribe-export-site-pages' ),
				'log_col_id'             => __( 'ID', 'sscribe-export-site-pages' ),
				'log_col_title'          => __( 'Title', 'sscribe-export-site-pages' ),
				'log_col_status'         => __( 'Status', 'sscribe-export-site-pages' ),
				'log_col_time'           => __( 'Time', 'sscribe-export-site-pages' ),
				'log_col_formats'        => __( 'Formats', 'sscribe-export-site-pages' ),
				'log_unknown'            => __( 'Unknown', 'sscribe-export-site-pages' ),
				'log_errors'             => __( 'Errors', 'sscribe-export-site-pages' ),
				'log_seconds_suffix'     => __( 's', 'sscribe-export-site-pages' ),
				'packaging'              => __( 'Packaging files into ZIP archive...', 'sscribe-export-site-pages' ),
				'download_tooltip'       => __( 'Download this export', 'sscribe-export-site-pages' ),
				'log_tooltip'            => __( 'View export log', 'sscribe-export-site-pages' ),
				'delete_tooltip'         => __( 'Delete this export', 'sscribe-export-site-pages' ),
				'support_title'          => __( 'Support Information', 'sscribe-export-site-pages' ),
				'err_permission'         => __( 'Your WordPress user role does not have the required capability (sscribe_export). Please contact your site administrator to grant export permissions, or log in with an Administrator account.', 'sscribe-export-site-pages' ),
				'err_session_expired'    => __( 'The export session was lost : this typically happens when the PHP session or database connection timed out. Click "Try Again" to start a fresh export. If this keeps happening, ask your hosting provider to increase the PHP max_execution_time (recommended: 120s or higher).', 'sscribe-export-site-pages' ),
				'err_data_corrupted'     => __( 'The encrypted export session data could not be verified. Clear the session and start a new export. If this repeats, check database health and available storage.', 'sscribe-export-site-pages' ),
				'err_rate_limit'         => __( 'You have exceeded the request rate limit. Please wait about 1 minute and then try again.', 'sscribe-export-site-pages' ),
				'err_no_pages'           => __( 'No pages match the selected language and status combination. Go back and verify your selection. If using WPML, ensure the selected language has pages assigned to it.', 'sscribe-export-site-pages' ),
				'err_zip'                => __( 'The server could not create the ZIP archive. Verify that the uploads directory is writable by WordPress, sufficient disk space is available, and the PHP ZIP extension is installed.', 'sscribe-export-site-pages' ),
				'err_timeout'            => __( 'The server took too long to respond. This usually happens with large pages or slow server hardware. The plugin processes pages individually and will resume from where it left off. If this keeps happening, ask your hosting provider to increase max_execution_time to at least 120 seconds.', 'sscribe-export-site-pages' ),
				'err_memory'             => __( 'The server ran out of PHP memory during export. Ask your hosting provider to increase the WordPress memory limit (WP_MEMORY_LIMIT) to at least 256M. You can also try exporting fewer pages at a time by selecting a specific language.', 'sscribe-export-site-pages' ),
				'err_connection'         => __( 'The connection to your server was interrupted. Check your internet connection and try again. If you are behind a proxy or CDN (e.g., Cloudflare), ensure AJAX requests are not being blocked or cached.', 'sscribe-export-site-pages' ),
				'err_invalid_lang'       => __( 'The selected language code is not recognized by WPML. Go back to step 1 and select a valid language. If you recently changed your WPML configuration, refresh this page first.', 'sscribe-export-site-pages' ),
				'err_in_progress'        => __( 'A previous export session is still active. Click "Try Again" to force-clear it and start fresh.', 'sscribe-export-site-pages' ),
				'err_500'                => __( 'Your server encountered an internal error (HTTP 500). Check your server\'s PHP error log for details. Common causes: a conflicting plugin, PHP memory limit too low, or a corrupted .htaccess file.', 'sscribe-export-site-pages' ),
				'err_403'                => __( 'The server rejected the request (HTTP 403 Forbidden). This is usually caused by a security plugin (e.g., Wordfence, Sucuri, iThemes Security) or server-level firewall blocking AJAX requests. Whitelist the SScribe AJAX actions in your security plugin settings.', 'sscribe-export-site-pages' ),
				'err_generic'            => __( 'Click "Try Again" to retry the export. If the problem continues: refresh the page, check your browser\'s developer console (F12), or contact your hosting provider to review PHP error logs.', 'sscribe-export-site-pages' ),
				'net_connection_lost'    => __( 'Connection lost : the server did not respond. Please check your internet connection and try again.', 'sscribe-export-site-pages' ),
				'net_403'                => __( 'Access denied (HTTP 403). A security plugin or firewall may be blocking this request.', 'sscribe-export-site-pages' ),
				'net_500'                => __( 'Internal server error (HTTP 500). The server encountered a problem : check your PHP error log for details.', 'sscribe-export-site-pages' ),
				'net_502'                => __( 'Bad gateway (HTTP 502). Your server or reverse proxy (Nginx/Cloudflare) is unavailable. Please wait a moment and try again.', 'sscribe-export-site-pages' ),
				'net_503'                => __( 'Service unavailable (HTTP 503). Your server is temporarily overloaded or under maintenance. Please wait a moment and try again.', 'sscribe-export-site-pages' ),
				'net_504'                => __( 'Gateway timeout (HTTP 504). The request took too long to process. Ask your hosting provider to increase the PHP max_execution_time.', 'sscribe-export-site-pages' ),
				'net_timeout'            => __( 'Request timed out : the server took too long to respond. This may happen with large exports. Please try again.', 'sscribe-export-site-pages' ),
				/* translators: %d: HTTP status code */
				'net_unknown'            => __( 'A network error occurred (HTTP %d). Please check your connection and try again.', 'sscribe-export-site-pages' ),
				'support_loading'        => __( 'Loading support information...', 'sscribe-export-site-pages' ),
				'support_error'          => __( 'Unable to load support information right now.', 'sscribe-export-site-pages' ),
				'support_copy_error'     => __( 'Copy failed. Try selecting the text manually.', 'sscribe-export-site-pages' ),
				'support_copy'           => __( 'Copy support info', 'sscribe-export-site-pages' ),
				'support_copied'         => __( 'Support information copied.', 'sscribe-export-site-pages' ),
				'support_refresh'        => __( 'Refresh', 'sscribe-export-site-pages' ),
				'support_generated'      => __( 'Generated', 'sscribe-export-site-pages' ),
				'support_debug'          => __( 'Debug mode may expose extra detail intended for administrators only.', 'sscribe-export-site-pages' ),
				'preflight_title'        => __( 'Export Readiness Check', 'sscribe-export-site-pages' ),
				'preflight_errors'       => __( 'Critical Issues', 'sscribe-export-site-pages' ),
				'preflight_warnings'     => __( 'Recommendations', 'sscribe-export-site-pages' ),
				'preflight_continue'     => __( 'Continue Anyway', 'sscribe-export-site-pages' ),
				'preflight_cancel'       => __( 'Cancel Export', 'sscribe-export-site-pages' ),
				'close'                  => __( 'Close', 'sscribe-export-site-pages' ),
				'rotated_view_label'     => __( 'View', 'sscribe-export-site-pages' ),
				'rotated_export_label'   => __( 'Export', 'sscribe-export-site-pages' ),
				'rotated_delete_label'   => __( 'Delete', 'sscribe-export-site-pages' ),
				'rotated_view'           => __( 'View rotated log', 'sscribe-export-site-pages' ),
				'rotated_export'         => __( 'Export rotated log', 'sscribe-export-site-pages' ),
				'rotated_delete'         => __( 'Delete rotated log', 'sscribe-export-site-pages' ),
				'generating_preview'     => __( 'Generating preview...', 'sscribe-export-site-pages' ),
				'preview_note'           => __( 'Preview is generated from the first page and may differ from the final export.', 'sscribe-export-site-pages' ),
				'preview_total_pages'    => __( 'Total pages:', 'sscribe-export-site-pages' ),
				'preview_format'         => __( 'Format:', 'sscribe-export-site-pages' ),
				'preview_language'       => __( 'Language:', 'sscribe-export-site-pages' ),
				'preview_status'         => __( 'Status:', 'sscribe-export-site-pages' ),
				'preview_estimated_time' => __( 'Estimated time:', 'sscribe-export-site-pages' ),
				'preview_file_size'      => __( 'Est. file size:', 'sscribe-export-site-pages' ),
				'preview_sample_title'   => __( 'Sample:', 'sscribe-export-site-pages' ),
				'preview_fallback_note'  => __( 'Only the first few pages are shown in the preview.', 'sscribe-export-site-pages' ),
				'summary_time_hint'      => __( 'See Preview for adaptive estimate', 'sscribe-export-site-pages' ),
				'loading_counts'         => __( 'Loading page counts...', 'sscribe-export-site-pages' ),
				'log_diagnostics'        => __( 'Diagnostics', 'sscribe-export-site-pages' ),
				'technical_details'      => __( 'Technical details', 'sscribe-export-site-pages' ),
				'fix_steps'              => __( 'Steps to fix:', 'sscribe-export-site-pages' ),
				'export_progress_prefix' => __( 'Export progress:', 'sscribe-export-site-pages' ),
				'format_docx'            => __( 'Word Document (DOCX)', 'sscribe-export-site-pages' ),
				'format_pdf'             => __( 'PDF Document', 'sscribe-export-site-pages' ),
				'format_html'            => __( 'HTML Page', 'sscribe-export-site-pages' ),
				'format_markdown'        => __( 'Markdown', 'sscribe-export-site-pages' ),
				'err_clear_session'      => __( 'Failed to clear the export session after multiple attempts. Please refresh the page and try again.', 'sscribe-export-site-pages' ),
				'export_cancelled'       => __( 'Export cancelled.', 'sscribe-export-site-pages' ),
				'export_complete_notice' => __( 'Export complete! You can start a new export now.', 'sscribe-export-site-pages' ),
				'click_again'            => __( 'Click again', 'sscribe-export-site-pages' ),
				'selected'               => __( 'selected', 'sscribe-export-site-pages' ),
				'calculating_time'       => __( 'Calculating...', 'sscribe-export-site-pages' ),
				'dismiss_notification'   => __( 'Dismiss notification', 'sscribe-export-site-pages' ),
				'preview_error'          => __( 'Failed to generate preview.', 'sscribe-export-site-pages' ),
				'download_unavailable'   => __( 'Download unavailable.', 'sscribe-export-site-pages' ),
				/* translators: %1$d: current page number, %2$d: total pages */
				'progress_pages'         => __( 'Processing %1$d of %2$d pages', 'sscribe-export-site-pages' ),
				'err_cancel_failed'      => __( 'Could not confirm cancellation : the server may still be processing. Reload the page before starting a new export.', 'sscribe-export-site-pages' ),

				'bulk_delete_confirm'    => __( 'Delete all selected exports? This cannot be undone.', 'sscribe-export-site-pages' ),
				'bulk_delete_label'      => __( 'Delete selected', 'sscribe-export-site-pages' ),
				'bulk_delete_title'      => __( 'Delete selected exports?', 'sscribe-export-site-pages' ),
				'bulk_delete_desc'       => __( 'All selected exports will be permanently removed from the server. ZIP files in your downloads folder will not be affected.', 'sscribe-export-site-pages' ),
				'bulk_delete_cancelled'  => __( 'Bulk delete cancelled.', 'sscribe-export-site-pages' ),
				'bulk_delete_started'    => __( 'Deleting selected exports...', 'sscribe-export-site-pages' ),

				'post_type_page'         => __( 'Pages', 'sscribe-export-site-pages' ),
				'post_type_post'         => __( 'Posts', 'sscribe-export-site-pages' ),
				'post_type_any'          => __( 'All types', 'sscribe-export-site-pages' ),

				'status_publish'         => __( 'Published', 'sscribe-export-site-pages' ),
				'status_draft'           => __( 'Draft', 'sscribe-export-site-pages' ),
				'status_private'         => __( 'Private', 'sscribe-export-site-pages' ),
				'status_future'          => __( 'Scheduled', 'sscribe-export-site-pages' ),
				'status_pending'         => __( 'Pending', 'sscribe-export-site-pages' ),
				'status_all'             => __( 'All', 'sscribe-export-site-pages' ),

				/* translators: 1: page count, 2: "pages" label */
				'live_region_ready'      => __( '%1$d %2$s ready for export', 'sscribe-export-site-pages' ),
				'live_region_no_pages'   => __( 'No pages match selected options. Export button is disabled.', 'sscribe-export-site-pages' ),
				/* translators: 1: prefix (e.g. "Export progress:"), 2: percentage */
				'live_region_progress'   => __( '%1$s %2$d%%', 'sscribe-export-site-pages' ),
			) + $post_type_strings,
		);
	}

	/**
	 * Build dynamic `post_type_<slug>` strings for every selectable post type.
	 *
	 * Falls back to `Page`/`Post` when the collector is unavailable so the
	 * admin JS always has a label it can resolve for the common case.
	 *
	 * @return array<string, string>
	 */
	private function build_post_type_strings(): array {
		$strings = array(
			'post_type_any' => __( 'All types', 'sscribe-export-site-pages' ),
		);

		$registered = function_exists( 'get_post_types' )
			? get_post_types( array( 'public' => true ), 'objects' )
			: array();

		if ( ! is_array( $registered ) ) {
			$registered = array();
		}

		unset( $registered['attachment'] );

		$selectable = $this->collector->get_selectable_post_types();

		foreach ( $selectable as $slug ) {
			$slug = (string) $slug;
			if ( '' === $slug || isset( $strings[ 'post_type_' . $slug ] ) ) {
				continue;
			}
			$label = '';
			if ( isset( $registered[ $slug ] ) && isset( $registered[ $slug ]->labels->singular_name ) ) {
				$label = (string) $registered[ $slug ]->labels->singular_name;
			}
			if ( '' === $label ) {
				$label = ucfirst( $slug );
			}
			$strings[ 'post_type_' . $slug ] = $label;
		}

		if ( ! isset( $strings['post_type_page'] ) ) {
			$strings['post_type_page'] = __( 'Pages', 'sscribe-export-site-pages' );
		}
		if ( ! isset( $strings['post_type_post'] ) ) {
			$strings['post_type_post'] = __( 'Posts', 'sscribe-export-site-pages' );
		}

		return $strings;
	}

	/**
	 * Build the per-CPT row data the admin UI iterates over for the
	 * post-type radio cards. Each row carries slug, label, icon slug, count,
	 * and a boolean indicating whether it is the synthetic `any` aggregate.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function build_selectable_type_rows(): array {
		$icons = array(
			'page' => 'file-text',
			'post' => 'article',
		);

		$selectable = $this->collector->get_selectable_post_types();

		$registered = function_exists( 'get_post_types' )
			? get_post_types( array( 'public' => true ), 'objects' )
			: array();

		$rows = array();
		$any_count = 0;
		foreach ( $selectable as $slug ) {
			$slug = (string) $slug;
			if ( '' === $slug ) {
				continue;
			}
			$count = (int) $this->collector->get_page_count_only( '', 'publish', $slug );
			$label = '';
			if ( isset( $registered[ $slug ] ) && isset( $registered[ $slug ]->labels->singular_name ) ) {
				$label = (string) $registered[ $slug ]->labels->singular_name;
			}
			if ( '' === $label ) {
				$label = ucfirst( $slug );
			}
			$rows[] = array(
				'slug'    => $slug,
				'label'   => $label,
				'icon'    => isset( $icons[ $slug ] ) ? $icons[ $slug ] : 'file-text',
				'count'   => $count,
				'is_any'  => false,
			);
			$any_count += $count;
		}

		$rows[] = array(
			'slug'   => 'any',
			'label'  => __( 'All types', 'sscribe-export-site-pages' ),
			'icon'   => 'copy',
			'count'  => $any_count,
			'is_any' => true,
		);

		return $rows;
	}

	/**
	 * Render the main admin export page.
	 */
	public function render_admin_page(): void {

		$cache_key        = 'sscribe_admin_page_data_v' . SSCRIBE_VERSION . '_' . get_current_blog_id();
		$cached_page_data = get_transient( $cache_key );

		$sscribe_selectable_types = array();

		if ( is_array( $cached_page_data ) ) {
			$sscribe_wpml_active         = $cached_page_data['wpml_active'] ?? false;
			$sscribe_languages           = $cached_page_data['languages'] ?? array();
			$sscribe_total_pages_all     = $cached_page_data['total_pages_all'] ?? 0;
			$sscribe_total_posts_all     = $cached_page_data['total_posts_all'] ?? 0;
			$sscribe_total_either_all    = $sscribe_total_pages_all + $sscribe_total_posts_all;
			$sscribe_status_counts       = $cached_page_data['status_counts'] ?? array();
			$sscribe_selectable_types    = $cached_page_data['selectable_types'] ?? $this->build_selectable_type_rows();
		} else {

			$sscribe_wpml_active = $this->collector->is_wpml_active();
			$sscribe_languages   = $this->collector->get_wpml_languages();

			$sscribe_total_pages_all = $this->collector->get_page_count_only( '', 'publish' );
			$sscribe_total_posts_all = $this->collector->get_page_count_only( '', 'publish', 'post' );

			$default_language      = '';
			$sscribe_status_counts = $this->collector->get_post_status_counts( $default_language );

			if ( $sscribe_wpml_active && ! empty( $sscribe_languages ) ) {
				foreach ( $sscribe_languages as &$lang ) {
					if ( isset( $lang['code'] ) ) {
						$lang['page_count'] = $this->collector->get_page_count_only( $lang['code'], 'publish' );
					}
				}
				unset( $lang );
			}

			$sscribe_total_either_all = $sscribe_total_pages_all + $sscribe_total_posts_all;

			$sscribe_selectable_types = $this->build_selectable_type_rows();

			set_transient(
				$cache_key,
				array(
					'wpml_active'      => $sscribe_wpml_active,
					'languages'        => $sscribe_languages,
					'total_pages_all'  => $sscribe_total_pages_all,
					'total_posts_all'  => $sscribe_total_posts_all,
					'status_counts'    => $sscribe_status_counts,
					'selectable_types' => $sscribe_selectable_types,
				),
				60
			);
		}

		$sscribe_debug_info = array();

		$sscribe_is_debug             = current_user_can( 'manage_options' );
		$sscribe_can_view_health      = current_user_can( SScribe_Capabilities::get_health_required() );
		$sscribe_debug_logging_active = $sscribe_is_debug && ( SSCRIBE_DEBUG || SScribe_Settings::is_debug_enabled() );

		if ( $sscribe_debug_logging_active ) {

			$blog_id            = is_multisite() ? get_current_blog_id() : 0;
			$debug_cache_key    = 'sscribe_debug_info_' . $blog_id . '_' . get_current_user_id();
			$sscribe_debug_info = get_transient( $debug_cache_key );

			if ( false === $sscribe_debug_info ) {
				$sscribe_debug_info = $this->gather_debug_info( $sscribe_wpml_active, $sscribe_languages, $sscribe_total_pages_all, $sscribe_status_counts );
				set_transient( $debug_cache_key, $sscribe_debug_info, 30 );
			}
		}

		$upload_dir = wp_upload_dir();
		$export_dir = ! empty( $upload_dir['error'] ) || empty( $upload_dir['basedir'] )
			? ''
			: trailingslashit( (string) $upload_dir['basedir'] ) . 'sscribe-exports/';

		$sscribe_export_index = array();
		$sscribe_export_rows  = array();
		if ( class_exists( 'SScribe_Zip_Handler' ) ) {
			$sscribe_zip_handler  = SScribe_Container::instance()->get( SScribe_Zip_Handler::class );
			$sscribe_export_rows  = $sscribe_zip_handler->list_export_entries();
			$sscribe_export_index = array_keys( $sscribe_export_rows );
		}

		$sscribe_recent_exports = ! empty( $sscribe_export_index )
			? $this->build_recent_exports( $sscribe_export_rows, $export_dir, $sscribe_wpml_active, $sscribe_languages, get_current_user_id() )
			: array();

		$sscribe_preflight_warnings = $this->gather_preflight_warnings(
			$sscribe_wpml_active,
			$sscribe_languages,
			$sscribe_total_pages_all,
			$export_dir
		);

		include SSCRIBE_PLUGIN_DIR . 'admin/partials/sscribe-admin-display.php';
	}

	/**
	 * Build recent export data for the admin history table.
	 *
	 * @param array  $export_index Export index option value.
	 * @param string $export_dir Export directory path.
	 * @param bool   $wpml_active Whether WPML is active.
	 * @param array  $languages WPML language metadata.
	 * @param int    $user_id Current user ID.
	 * @return array Recent export rows.
	 */
	private function build_recent_exports( array $export_index, string $export_dir, bool $wpml_active, array $languages, int $user_id ): array {
		uasort(
			$export_index,
			static function ( $a, $b ): int {
				$a_time = is_array( $a ) ? (int) ( $a['created_at'] ?? 0 ) : 0;
				$b_time = is_array( $b ) ? (int) ( $b['created_at'] ?? 0 ) : 0;

				return $b_time <=> $a_time;
			}
		);

		$language_map = array();
		foreach ( $languages as $language ) {
			if ( ! is_array( $language ) || empty( $language['code'] ) ) {
				continue;
			}
			$language_map[ sanitize_key( (string) $language['code'] ) ] = $language;
		}

		$recent_exports = array();

		foreach ( $export_index as $filename => $data ) {
			if ( ! is_array( $data ) ) {
				continue;
			}

			if ( ! isset( $data['user_id'] ) || (int) $data['user_id'] !== $user_id ) {
				continue;
			}

			$filename = basename( sanitize_file_name( (string) $filename ) );
			if ( '' === $filename || 'zip' !== strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) ) ) {
				continue;
			}

			$file_path = $export_dir . $filename;
			if ( is_link( $file_path ) || ! is_file( $file_path ) ) {
				continue;
			}

			$real_path = realpath( $file_path );
			$real_dir  = realpath( $export_dir );
			if ( false === $real_path || false === $real_dir ) {
				continue;
			}
			$safe_dir = rtrim( $real_dir, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR;
			if ( ! str_starts_with( $real_path, $safe_dir ) || ! is_file( $real_path ) ) {
				continue;
			}

			$lang_code = sanitize_key( (string) ( $data['lang_code'] ?? '' ) );

			if ( '' === $lang_code ) {
				$lang_code = 'all';
			}

			$lang_name = sanitize_text_field( (string) ( $data['lang_name'] ?? '' ) );
			$flag_url  = esc_url_raw( (string) ( $data['flag_url'] ?? '' ) );

			if ( 'all' === $lang_code ) {
				$lang_name = __( 'All Languages', 'sscribe-export-site-pages' );
			} elseif ( $wpml_active && isset( $language_map[ $lang_code ] ) ) {
				$lang_name = sanitize_text_field( (string) ( $language_map[ $lang_code ]['name'] ?? strtoupper( $lang_code ) ) );
				$flag_url  = esc_url_raw( (string) ( $language_map[ $lang_code ]['flag_url'] ?? $flag_url ) );
			} elseif ( '' === $lang_name ) {
				$lang_name = strtoupper( $lang_code );
			}

			$file_time = filemtime( $real_path );
			$file_size = filesize( $real_path );
			if ( false === $file_time || false === $file_size ) {
				continue;
			}

			$recent_exports[] = array(
				'filename'  => $filename,
				'url'       => $this->zip_handler->get_ajax_download_url( $filename ),
				'time'      => isset( $data['created_at'] ) ? (int) $data['created_at'] : $file_time,
				'size'      => $file_size,
				'lang_code' => $lang_code,
				'flag_url'  => $flag_url,
				'lang_name' => $lang_name,
			);

			if ( count( $recent_exports ) >= 10 ) {
				break;
			}
		}

		return $recent_exports;
	}

	/**
	 * Gather pre-flight warnings to surface above the Generate Package button.
	 *
	 * Each warning row carries:
	 *  - 'code'     short machine identifier
	 *  - 'icon'     svg icon name from the assets/icons bundle
	 *  - 'severity' info | warning
	 *  - 'message'  short heading
	 *  - 'detail'   optional secondary line with remediation hint
	 *
	 * @param bool   $wpml_active     Whether WPML is active.
	 * @param array  $languages       Configured WPML languages.
	 * @param int    $total_pages_all Total published pages.
	 * @param string $export_dir      Export directory path.
	 * @return array<int, array<string, string>> List of warning rows.
	 */
	private function gather_preflight_warnings( bool $wpml_active, array $languages, int $total_pages_all, string $export_dir ): array {
		$warnings = array();

		if ( $wpml_active && empty( $languages ) ) {
			$warnings[] = array(
				'code'     => 'wpml_no_languages',
				'icon'     => 'info',
				'severity' => 'info',
				'message'  => __( 'WPML is active but no languages are configured.', 'sscribe-export-site-pages' ),
				'detail'   => __( 'Add at least one secondary language in WPML -> Languages before exporting to produce a multilingual package.', 'sscribe-export-site-pages' ),
			);
		}

		if ( 0 === (int) $total_pages_all ) {
			$warnings[] = array(
				'code'     => 'no_pages',
				'icon'     => 'info',
				'severity' => 'info',
				'message'  => __( 'No published pages are available to export.', 'sscribe-export-site-pages' ),
				'detail'   => __( 'Publish at least one page, or expand the post-type filter on the left to include drafts and private posts.', 'sscribe-export-site-pages' ),
			);
		}

		if ( '' === $export_dir || ( ! is_dir( $export_dir ) && ! wp_mkdir_p( $export_dir ) ) ) {
			$warnings[] = array(
				'code'     => 'export_dir_unwritable',
				'icon'     => 'warning',
				'severity' => 'warning',
				'message'  => __( 'The exports directory could not be created.', 'sscribe-export-site-pages' ),
				'detail'   => __( 'Check that your uploads folder is writable, then refresh this page.', 'sscribe-export-site-pages' ),
			);
		}

		$memory_limit_raw = (string) ini_get( 'memory_limit' );
		$memory_limit     = wp_convert_hr_to_bytes( $memory_limit_raw );
		if ( $memory_limit > 0 && $memory_limit < 256 * MB_IN_BYTES ) {
			$sscribe_memory_message = sprintf(
				/* translators: %s: current PHP memory_limit (e.g. "128M") */
				__( 'PHP memory limit is %s; large exports may run out of memory.', 'sscribe-export-site-pages' ),
				$memory_limit_raw
			);
			$warnings[]             = array(
				'code'     => 'memory_low',
				'icon'     => 'warning',
				'severity' => 'warning',
				'message'  => $sscribe_memory_message,
				'detail'   => __( 'Ask your host to raise memory_limit to at least 256M for sites with hundreds of pages or images.', 'sscribe-export-site-pages' ),
			);
		}

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$warnings[] = array(
				'code'     => 'wp_debug_on',
				'icon'     => 'info',
				'severity' => 'info',
				'message'  => __( 'WP_DEBUG is enabled on this site.', 'sscribe-export-site-pages' ),
				'detail'   => __( 'Keep WP_DEBUG disabled on production sites except while actively troubleshooting.', 'sscribe-export-site-pages' ),
			);
		}

		return $warnings;
	}

	/**
	 * Gather debug environment info for the Debug tab.
	 *
	 * @param bool  $wpml_active       Whether WPML is active.
	 * @param array $languages         Language list.
	 * @param int   $total_pages_all   Total published pages.
	 * @param array $status_counts     Post status counts.
	 * @return array Debug information.
	 */
	private function gather_debug_info( bool $wpml_active, array $languages, int $total_pages_all, array $status_counts ): array {
		$sscribe_debug_info                    = array();
		$sscribe_debug_info['languages_count'] = count( $languages );
		$sscribe_debug_info['total_pages_all'] = $total_pages_all;
		$sscribe_debug_info['status_counts']   = $status_counts;
		$sscribe_debug_info['duplicate_slugs'] = array();

		if ( $wpml_active && ! empty( $languages ) ) {
			$all_page_ids_by_lang = array();

			foreach ( $languages as $lang ) {
				$lang_code = $lang['code'];

				// get_post_status_counts() returns a single GROUP BY
				// post_status query result that already contains the
				// 'publish' count. Reading it again from
				// get_page_count_only() is a redundant per-language
				// query (N+1 across WPML languages). Derive the
				// published count from the same array we just got.
				$status_breakdown = $this->collector->get_post_status_counts( $lang_code );

				$sscribe_debug_info['language_details'][ $lang_code ] = array(
					'name'             => $lang['name'],
					'page_count'       => $lang['page_count'] ?? 0,
					'status_breakdown' => $status_breakdown,
					'published_count'  => isset( $status_breakdown['publish'] ) ? (int) $status_breakdown['publish'] : 0,
				);

				$page_ids = $this->collector->get_page_ids( $lang_code, 'publish', 'page', 50 );
				$sscribe_debug_info['language_details'][ $lang_code ]['published_page_ids'] = $page_ids;
				$all_page_ids_by_lang[ $lang_code ] = $page_ids;
			}

			$all_slugs    = array();
			$all_flat_ids = array();
			foreach ( $all_page_ids_by_lang as $lang_code => $ids ) {
				foreach ( $ids as $pid ) {
					$all_flat_ids[] = array(
						'id'   => $pid,
						'lang' => $lang_code,
					);
				}
			}

			$all_id_list = array_column( $all_flat_ids, 'id' );
			if ( count( $all_id_list ) > 200 ) {
				$all_id_list = array_slice( $all_id_list, 0, 200 );
			}
			$posts_by_id = array();
			if ( ! empty( $all_id_list ) ) {
				$batch_posts = get_posts(
					array(
						'post__in'               => $all_id_list,
						'post_type'              => 'page',
						'post_status'            => 'any',
						'posts_per_page'         => 200,
						'no_found_rows'          => true,
						'update_post_meta_cache' => false,
						'update_post_term_cache' => false,
						'orderby'                => 'post__in',
					)
				);

				if ( is_array( $batch_posts ) && ! empty( $batch_posts ) ) {
					foreach ( $batch_posts as $post ) {
						$posts_by_id[ $post->ID ] = $post;
					}
				}
			}

			foreach ( $all_flat_ids as $entry ) {
				$pid  = $entry['id'];
				$lc   = $entry['lang'];
				$post = $posts_by_id[ $pid ] ?? null;
				if ( $post ) {
					$slug = $post->post_name;
					if ( ! isset( $all_slugs[ $slug ] ) ) {
						$all_slugs[ $slug ] = array();
					}
					$all_slugs[ $slug ][] = array(
						'id'    => $pid,
						'lang'  => $lc,
						'title' => $post->post_title,
					);
				}
			}
			$sscribe_debug_info['duplicate_slugs'] = array_filter(
				$all_slugs,
				function ( $items ) {
					return count( $items ) > 1;
				}
			);
		}

		$sscribe_debug_info['server'] = array(
			'php_version'         => PHP_VERSION,
			'memory_limit'        => ini_get( 'memory_limit' ),
			'max_execution_time'  => ini_get( 'max_execution_time' ),
			'upload_max_filesize' => ini_get( 'upload_max_filesize' ),
			'post_max_size'       => ini_get( 'post_max_size' ),
		);

		$sscribe_debug_info['wordpress'] = array(
			'version' => get_bloginfo( 'version' ),
			'locale'  => get_locale(),
		);

		return $sscribe_debug_info;
	}

	/**
	 * Add quick action links to the plugin entry.
	 *
	 * @param array $links Existing plugin action links.
	 * @return array Modified links array.
	 */
	public function add_plugin_action_links( array $links ): array {
		$plugin_links = array(
			'<a href="' . esc_url( admin_url( 'admin.php?page=sscribe-export' ) ) . '">' . esc_html__( 'Export Pages', 'sscribe-export-site-pages' ) . '</a>',
		);
		return array_merge( $plugin_links, $links );
	}
}
