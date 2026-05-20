<?php
/**
 * SScribe Admin
 *
 * @package SScribe_Export_Site_Pages
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
		if ( wp_doing_ajax() || ! is_admin() ) {
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
		if ( isset( $_GET['activate-multi'] ) && '1' === sanitize_key( (string) $_GET['activate-multi'] ) ) {
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
	 * Send Content Security Policy headers on the export page.
	 */
	public function maybe_send_csp_headers(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only page param check.
		if ( ! isset( $_GET['page'] ) || 'sscribe-export' !== sanitize_key( $_GET['page'] ) ) {
			return;
		}

		if ( headers_sent() ) {
			return;
		}

		$policy = implode(
			'; ',
			array(
				"default-src 'self'",
				"script-src 'self' 'unsafe-inline'",
				"style-src 'self' 'unsafe-inline'",
				"img-src 'self' data:",
				"font-src 'self' data:",
				"object-src 'none'",
				"frame-ancestors 'self'",
				"base-uri 'self'",
				"form-action 'self'",
			)
		);

		header( 'Content-Security-Policy: ' . $policy );
		header( 'X-Frame-Options: SAMEORIGIN' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Referrer-Policy: strict-origin-when-cross-origin' );
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

		wp_enqueue_style(
			'sscribe-admin',
			SSCRIBE_PLUGIN_URL . 'admin/css/sscribe-admin.css',
			array(),
			SSCRIBE_VERSION
		);

		$fonts_url     = SSCRIBE_PLUGIN_URL . 'assets/fonts/manrope/';
		$font_face_css = ''
			. '@font-face {'
			. 'font-family: \'Manrope\';'
			. 'src: url(\'' . $fonts_url . 'Manrope-Regular.ttf\') format(\'truetype\');'
			. 'font-weight: 400;'
			. 'font-style: normal;'
			. 'font-display: swap;'
			. '}'
			. '@font-face {'
			. 'font-family: \'Manrope\';'
			. 'src: url(\'' . $fonts_url . 'Manrope-Bold.ttf\') format(\'truetype\');'
			. 'font-weight: 700;'
			. 'font-style: normal;'
			. 'font-display: swap;'
			. '}'
			. '@font-face {'
			. 'font-family: \'Manrope\';'
			. 'src: url(\'' . $fonts_url . 'Manrope-Medium.ttf\') format(\'truetype\');'
			. 'font-weight: 500;'
			. 'font-style: normal;'
			. 'font-display: swap;'
			. '}'
			. '@font-face {'
			. 'font-family: \'Manrope\';'
			. 'src: url(\'' . $fonts_url . 'Manrope-Light.ttf\') format(\'truetype\');'
			. 'font-weight: 300;'
			. 'font-style: normal;'
			. 'font-display: swap;'
			. '}';
		wp_add_inline_style( 'sscribe-admin', $font_face_css );

		$js_file = 'admin/js/sscribe-admin.js';

		wp_enqueue_script(
			'sscribe-admin',
			SSCRIBE_PLUGIN_URL . $js_file,
			array( 'jquery' ),
			SSCRIBE_VERSION,
			true
		);

		wp_localize_script(
			'sscribe-admin',
			'sscribe_data',
			array(
				'ajaxurl'        => admin_url( 'admin-ajax.php' ),
				'nonce'          => wp_create_nonce( 'sscribe_export_nonce' ),
				'download_nonce' => $this->get_download_nonce(),
				'icons_url'      => SSCRIBE_PLUGIN_URL . 'assets/icons/',
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
					'estimated_time'         => __( 'Estimated time:', 'sscribe-export-site-pages' ),
					'minutes'                => __( 'minutes', 'sscribe-export-site-pages' ),
					'minute'                 => __( 'minute', 'sscribe-export-site-pages' ),
					'seconds'                => __( 'seconds', 'sscribe-export-site-pages' ),
					'sec_remaining'          => __( 'sec remaining', 'sscribe-export-site-pages' ),

					// translators: %1$d is minutes, %2$d is seconds.
					'min_sec_remaining'      => __( '%1$d min %2$d sec remaining', 'sscribe-export-site-pages' ),
					'hour_suffix'            => __( 'h', 'sscribe-export-site-pages' ),
					'minute_suffix'          => __( 'm', 'sscribe-export-site-pages' ),
					'log_total'              => __( 'Total:', 'sscribe-export-site-pages' ),
					'log_pages'              => __( 'pages', 'sscribe-export-site-pages' ),
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

					'err_permission'         => __( 'Your WordPress user role does not have the required capability (manage_options). Please contact your site administrator to grant export permissions, or log in with an Administrator account.', 'sscribe-export-site-pages' ),
					'err_session_expired'    => __( 'The export session was lost — this typically happens when the PHP session or database connection timed out. Click "Try Again" to start a fresh export. If this keeps happening, ask your hosting provider to increase the PHP max_execution_time (recommended: 120s or higher).', 'sscribe-export-site-pages' ),
					'err_data_corrupted'     => __( 'The session data in the database became invalid. This can happen if your database ran out of storage or a caching plugin (e.g., WP Rocket, W3 Total Cache) is caching wp_options. Exclude "sscribe_session_*" from object caching.', 'sscribe-export-site-pages' ),
					'err_rate_limit'         => __( 'You have exceeded the request rate limit (200 requests per minute). Please wait about 1 minute and then try again. This limit protects your server from overload.', 'sscribe-export-site-pages' ),
					'err_no_pages'           => __( 'No pages match the selected language and status combination. Go back and verify your selection. If using WPML, ensure the selected language has pages assigned to it.', 'sscribe-export-site-pages' ),
					'err_zip'                => __( 'The server could not create the ZIP archive. Common causes: the uploads directory is not writable (check folder permissions, should be 755), the server ran out of disk space, or the PHP zip extension is not installed. Contact your hosting provider if this persists.', 'sscribe-export-site-pages' ),
					'err_timeout'            => __( 'The server took too long to respond. This usually happens with large pages or slow server hardware. The plugin processes pages individually and will resume from where it left off. If this keeps happening, ask your hosting provider to increase max_execution_time to at least 120 seconds.', 'sscribe-export-site-pages' ),
					'err_memory'             => __( 'The server ran out of PHP memory during export. Ask your hosting provider to increase the WordPress memory limit (WP_MEMORY_LIMIT) to at least 256M. You can also try exporting fewer pages at a time by selecting a specific language.', 'sscribe-export-site-pages' ),
					'err_connection'         => __( 'The connection to your server was interrupted. Check your internet connection and try again. If you are behind a proxy or CDN (e.g., Cloudflare), ensure AJAX requests are not being blocked or cached.', 'sscribe-export-site-pages' ),
					'err_invalid_lang'       => __( 'The selected language code is not recognized by WPML. Go back to step 1 and select a valid language. If you recently changed your WPML configuration, refresh this page first.', 'sscribe-export-site-pages' ),
					'err_in_progress'        => __( 'A previous export session is still active. Click "Try Again" to force-clear it and start fresh.', 'sscribe-export-site-pages' ),
					'err_500'                => __( 'Your server encountered an internal error (HTTP 500). Check your server\'s PHP error log for details. Common causes: a conflicting plugin, PHP memory limit too low, or a corrupted .htaccess file.', 'sscribe-export-site-pages' ),
					'err_403'                => __( 'The server rejected the request (HTTP 403 Forbidden). This is usually caused by a security plugin (e.g., Wordfence, Sucuri, iThemes Security) or server-level firewall blocking AJAX requests. Whitelist the SScribe AJAX actions in your security plugin settings.', 'sscribe-export-site-pages' ),
					'err_generic'            => __( 'Click "Try Again" to retry the export. If the problem continues: refresh the page, check your browser\'s developer console (F12), or contact your hosting provider to review PHP error logs.', 'sscribe-export-site-pages' ),

					'net_connection_lost'    => __( 'Connection lost — the server did not respond. Please check your internet connection and try again.', 'sscribe-export-site-pages' ),
					'net_403'                => __( 'Access denied (HTTP 403). A security plugin or firewall may be blocking this request.', 'sscribe-export-site-pages' ),
					'net_500'                => __( 'Internal server error (HTTP 500). The server encountered a problem — check your PHP error log for details.', 'sscribe-export-site-pages' ),
					'net_502'                => __( 'Bad gateway (HTTP 502). Your server or reverse proxy (Nginx/Cloudflare) is unavailable. Please wait a moment and try again.', 'sscribe-export-site-pages' ),
					'net_503'                => __( 'Service unavailable (HTTP 503). Your server is temporarily overloaded or under maintenance. Please wait a moment and try again.', 'sscribe-export-site-pages' ),
					'net_504'                => __( 'Gateway timeout (HTTP 504). The request took too long to process. Ask your hosting provider to increase the PHP max_execution_time.', 'sscribe-export-site-pages' ),
					'net_timeout'            => __( 'Request timed out — the server took too long to respond. This may happen with large exports. Please try again.', 'sscribe-export-site-pages' ),

					// translators: %d is the HTTP status code.
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
					'log_diagnostics'        => __( 'Diagnostics', 'sscribe-export-site-pages' ),
					'technical_details'      => __( 'Technical details', 'sscribe-export-site-pages' ),
					'fix_steps'              => __( 'Steps to fix:', 'sscribe-export-site-pages' ),
					'export_progress_prefix' => __( 'Export progress:', 'sscribe-export-site-pages' ),
					'format_docx'            => __( 'Word Document (DOCX)', 'sscribe-export-site-pages' ),
					'format_pdf'             => __( 'PDF Document', 'sscribe-export-site-pages' ),
					'format_html'            => __( 'HTML Page', 'sscribe-export-site-pages' ),
					'format_markdown'        => __( 'Markdown', 'sscribe-export-site-pages' ),
				),
			)
		);
	}

	/**
	 * Render the main admin export page.
	 */
	public function render_admin_page(): void {

		$cache_key        = 'sscribe_admin_page_data_v' . SSCRIBE_VERSION;
		$cached_page_data = get_transient( $cache_key );

		if ( is_array( $cached_page_data ) ) {
			$sscribe_wpml_active     = $cached_page_data['wpml_active'] ?? false;
			$sscribe_languages       = $cached_page_data['languages'] ?? array();
			$sscribe_total_pages_all = $cached_page_data['total_pages_all'] ?? 0;
			$sscribe_status_counts   = $cached_page_data['status_counts'] ?? array();
		} else {

			$sscribe_wpml_active = $this->collector->is_wpml_active();
			$sscribe_languages   = $this->collector->get_wpml_languages();

			$sscribe_total_pages_all = $this->collector->get_page_count_only( '', 'publish' );

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

			set_transient(
				$cache_key,
				array(
					'wpml_active'     => $sscribe_wpml_active,
					'languages'       => $sscribe_languages,
					'total_pages_all' => $sscribe_total_pages_all,
					'status_counts'   => $sscribe_status_counts,
				),
				60
			);
		}

		$sscribe_debug_info = array();
		$sscribe_is_debug   = defined( 'SSCRIBE_DEBUG_PUBLIC' ) && SSCRIBE_DEBUG_PUBLIC;

		if ( $sscribe_is_debug ) {
			error_log( 'SScribe: Debug mode is ENABLED. This should NOT be enabled in production environments.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional debug warning.
		}

		if ( $sscribe_is_debug ) {

			$debug_cache_key    = 'sscribe_debug_info_' . get_current_user_id();
			$sscribe_debug_info = get_transient( $debug_cache_key );

			if ( false === $sscribe_debug_info ) {
				$sscribe_debug_info                    = array();
				$sscribe_debug_info['languages_count'] = count( $sscribe_languages );
				$sscribe_debug_info['total_pages_all'] = $sscribe_total_pages_all;
				$sscribe_debug_info['status_counts']   = $sscribe_status_counts;

				if ( $sscribe_wpml_active && ! empty( $sscribe_languages ) ) {
					$all_page_ids_by_lang = array();

					foreach ( $sscribe_languages as $lang ) {
						$lang_code = $lang['code'];
						$sscribe_debug_info['language_details'][ $lang_code ] = array(
							'name'             => $lang['name'],
							'page_count'       => $lang['page_count'] ?? 0,
							'status_breakdown' => $this->collector->get_post_status_counts( $lang_code ),
						);

						$page_ids = $this->collector->get_page_ids( $lang_code, 'publish' );
						$sscribe_debug_info['language_details'][ $lang_code ]['published_page_ids'] = $page_ids;
						$sscribe_debug_info['language_details'][ $lang_code ]['published_count']    = count( $page_ids );
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
					$posts_by_id = array();
					if ( ! empty( $all_id_list ) ) {

						$batch_posts = get_posts(
							array(
								'post__in'               => $all_id_list,
								'post_type'              => 'page',
								'post_status'            => 'any',
								'posts_per_page'         => -1,
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

				set_transient( $debug_cache_key, $sscribe_debug_info, 30 );
			}
		}

		$sscribe_recent_exports = array();
		$upload_dir             = wp_upload_dir();
		$export_dir             = trailingslashit( $upload_dir['basedir'] ) . 'sscribe-exports/';

		$download_nonce = $this->get_download_nonce();

		$export_index = get_option( 'sscribe_export_index', array() );
		$user_id      = get_current_user_id();

		if ( ! empty( $export_index ) ) {

			uasort(
				$export_index,
				function ( $a, $b ) {
					return ( $b['created_at'] ?? 0 ) <=> ( $a['created_at'] ?? 0 );
				}
			);

			$count = 0;
			foreach ( $export_index as $filename => $data ) {

				if ( isset( $data['user_id'] ) && (int) $data['user_id'] !== $user_id ) {
					continue;
				}

				$file_path = $export_dir . $filename;
				if ( ! file_exists( $file_path ) ) {
					continue;
				}

				$lang_code = $data['lang_code'] ?? 'all';
				$lang_name = $data['lang_name'] ?? 'All Languages';
				$flag_url  = $data['flag_url'] ?? '';

				if ( empty( $lang_code ) && $sscribe_wpml_active && ! empty( $sscribe_languages ) ) {

					if ( preg_match( '/-([A-Z]{2,3})-[A-Z]+\.zip$/', $filename, $matches ) ) {
						$lang_code = strtolower( $matches[1] );
					} else {
						$lang_code = 'all';
					}
				}

				if ( empty( $lang_name ) || 'All Languages' === $lang_name ) {
					if ( $sscribe_wpml_active && ! empty( $sscribe_languages ) && 'all' !== $lang_code ) {
						foreach ( $sscribe_languages as $l ) {
							if ( $l['code'] === $lang_code ) {
								$lang_name = $l['name'] ?? strtoupper( $lang_code );
								$flag_url  = $l['flag_url'] ?? $flag_url;
								break;
							}
						}
					}
					if ( empty( $lang_name ) || 'All Languages' === $lang_name ) {
						$lang_name = 'all' === $lang_code ? 'All Languages' : strtoupper( $lang_code );
					}
				}

				$sscribe_recent_exports[] = array(
					'filename'  => $filename,
					'url'       => $this->zip_handler->get_ajax_download_url( $filename ),
					'time'      => $data['created_at'] ?? filemtime( $file_path ),
					'size'      => filesize( $file_path ),
					'lang_code' => sanitize_key( $lang_code ),
					'flag_url'  => esc_url( $flag_url ),
					'lang_name' => esc_html( $lang_name ),
				);

				++$count;
				if ( $count >= 10 ) {
					break;
				}
			}
		}

		include SSCRIBE_PLUGIN_DIR . 'admin/partials/sscribe-admin-display.php';
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
