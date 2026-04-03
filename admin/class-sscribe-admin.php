<?php
/**
 * Admin interface for SScribe.
 *
 * @package SScribe
 */

declare(strict_types=1);

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SScribe_Admin
 *
 * Handles admin menu registration, page rendering, and asset enqueuing.
 */
class SScribe_Admin {


	/**
	 * Page collector instance.
	 *
	 * @var SScribe_Page_Collector
	 */
	private SScribe_Page_Collector $collector;

	/**
	 * SEO reader instance.
	 *
	 * @var SScribe_SEO_Reader
	 */
	private SScribe_SEO_Reader $seo_reader;

	/**
	 * Constructor.
	 *
	 * @param SScribe_Page_Collector|null $collector   Page collector instance.
	 * @param SScribe_SEO_Reader|null     $seo_reader  SEO reader instance.
	 */
	public function __construct(
		?SScribe_Page_Collector $collector = null,
		?SScribe_SEO_Reader $seo_reader = null
	) {
		$this->collector  = $collector ?? new SScribe_Page_Collector();
		$this->seo_reader = $seo_reader ?? new SScribe_SEO_Reader();
	}

	/**
	 * Register admin menu.
	 *
	 * @return void
	 */
	public function add_admin_menu(): void {
		add_management_page(
			__( 'SScribe Export', 'sscribe-export-site-pages' ),
			__( 'SScribe Export', 'sscribe-export-site-pages' ),
			apply_filters( 'sscribe_export_capability', 'manage_options' ),
			'sscribe-export',
			array( $this, 'render_admin_page' )
		);
	}

	/**
	 * Send Content-Security-Policy header on the SScribe admin page.
	 *
	 * Called on admin_init. CSP is sent only when the current request is for
	 * our specific admin page, to avoid affecting other admin pages.
	 *
	 * @return void
	 */
	public function maybe_send_csp_headers(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only page param check.
		if ( ! isset( $_GET['page'] ) || 'sscribe-export' !== sanitize_key( $_GET['page'] ) ) {
			return;
		}

		if ( headers_sent() ) {
			return;
		}

		// WordPress admin loads many inline scripts from core and other plugins.
		// We use Report-Only mode so violations are logged but nothing is blocked.
		// frame-ancestors and X-Frame-Options are enforced via separate headers.
		$policy = implode(
			'; ',
			array(
				"default-src 'self'",
				"script-src 'self' 'unsafe-inline'",
				"style-src 'self' https://fonts.googleapis.com 'unsafe-inline'",
				"font-src 'self' https://fonts.gstatic.com",
				"img-src 'self' data:",
				"object-src 'none'",
				"frame-ancestors 'self'",
				"base-uri 'self'",
				"form-action 'self'",
			)
		);

		header( 'Content-Security-Policy-Report-Only: ' . $policy );
		header( 'X-Frame-Options: SAMEORIGIN' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Referrer-Policy: strict-origin-when-cross-origin' );
	}

	/**
	 * Enqueue admin scripts and styles.
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_admin_assets( string $hook_suffix ): void {
		// Only load on our plugin page.
		if ( 'tools_page_sscribe-export' !== $hook_suffix ) {
			return;
		}

		// Admin CSS - use minified version in production.
		$css_file = ( defined( 'WP_DEBUG' ) && WP_DEBUG )
			? 'admin/css/sscribe-admin.css'
			: 'admin/css/sscribe-admin.min.css';

		wp_enqueue_style(
			'sscribe-admin',
			SSCRIBE_PLUGIN_URL . $css_file,
			array(),
			SSCRIBE_VERSION
		);

		// Admin JS.
		wp_enqueue_script(
			'sscribe-admin',
			SSCRIBE_PLUGIN_URL . 'admin/js/sscribe-admin.js',
			array( 'jquery' ),
			SSCRIBE_VERSION,
			true
		);

		// Localize script.
		wp_localize_script(
			'sscribe-admin',
			'sscribe_data',
			array(
				'ajaxurl'        => admin_url( 'admin-ajax.php' ),
				'nonce'          => wp_create_nonce( 'sscribe_export_nonce' ),
				'download_nonce' => wp_create_nonce( 'sscribe_download' ),
				'strings'        => array(
					'starting'           => __( 'Starting export...', 'sscribe-export-site-pages' ),
					'processing'         => __( 'Processing...', 'sscribe-export-site-pages' ),
					'complete'           => __( 'Export complete!', 'sscribe-export-site-pages' ),
					'error'              => __( 'An error occurred. Please try again.', 'sscribe-export-site-pages' ),
					'download'           => __( 'Download ZIP', 'sscribe-export-site-pages' ),
					'generating'         => __( 'Generating documents...', 'sscribe-export-site-pages' ),
					'confirm_export'     => __( 'Start exporting pages?', 'sscribe-export-site-pages' ),
					'confirm_delete'     => __( 'Delete this export file?', 'sscribe-export-site-pages' ),
					'auto_delete'        => __( 'This file will be automatically deleted in 72 hours for security.', 'sscribe-export-site-pages' ),
					'cancel'             => __( 'Cancel Export', 'sscribe-export-site-pages' ),
					'cancelling'         => __( 'Cancelling...', 'sscribe-export-site-pages' ),
					'loading_log'        => __( 'Loading log...', 'sscribe-export-site-pages' ),
					'log_not_found'      => __( 'Log not found.', 'sscribe-export-site-pages' ),
					'log_load_failed'    => __( 'Failed to load log.', 'sscribe-export-site-pages' ),
					'delete_failed'      => __( 'Failed to delete export.', 'sscribe-export-site-pages' ),
					'history_empty'      => __( 'Your recent export packages will appear here.', 'sscribe-export-site-pages' ),
					'estimated_time'     => __( 'Estimated time:', 'sscribe-export-site-pages' ),
					'minutes'            => __( 'minutes', 'sscribe-export-site-pages' ),
					'minute'             => __( 'minute', 'sscribe-export-site-pages' ),
					'seconds'            => __( 'seconds', 'sscribe-export-site-pages' ),
					'sec_remaining'      => __( 'sec remaining', 'sscribe-export-site-pages' ),
					// translators: %s: remaining seconds value.
					'min_sec_remaining'  => __( 'min %s sec remaining', 'sscribe-export-site-pages' ),
					'hour_suffix'        => 'h',
					'minute_suffix'      => 'm',
					'log_total'          => __( 'Total:', 'sscribe-export-site-pages' ),
					'log_pages'          => __( 'pages', 'sscribe-export-site-pages' ),
					'log_success_label'  => __( 'Success:', 'sscribe-export-site-pages' ),
					'log_failed_label'   => __( 'Failed:', 'sscribe-export-site-pages' ),
					'log_page_details'   => __( 'Page Details', 'sscribe-export-site-pages' ),
					'log_col_id'         => __( 'ID', 'sscribe-export-site-pages' ),
					'log_col_title'      => __( 'Title', 'sscribe-export-site-pages' ),
					'log_col_status'     => __( 'Status', 'sscribe-export-site-pages' ),
					'log_col_time'       => __( 'Time', 'sscribe-export-site-pages' ),
					'log_col_formats'    => __( 'Formats', 'sscribe-export-site-pages' ),
					'log_unknown'        => __( 'Unknown', 'sscribe-export-site-pages' ),
					'log_errors'         => __( 'Errors', 'sscribe-export-site-pages' ),
					'log_seconds_suffix' => 's',
					'log_no_duration'    => '-',
				),
			)
		);
	}

	/**
	 * Render the admin page.
	 *
	 * @return void
	 */
	public function render_admin_page(): void {
		// Gather data for the template.
		$sscribe_wpml_active = $this->collector->is_wpml_active();
		$sscribe_languages   = $this->collector->get_wpml_languages();

		// For the "All Languages" card: count published pages in ALL languages combined.
		$sscribe_total_pages_all = $this->collector->get_page_count_only( '', 'publish' );

		// For Page Status section: use empty string for all languages as default.
		$default_language      = '';
		$sscribe_status_counts = $this->collector->get_post_status_counts( $default_language );

		// Enrich languages with per-language page counts.
		if ( $sscribe_wpml_active && ! empty( $sscribe_languages ) ) {
			foreach ( $sscribe_languages as &$lang ) {
				$lang['page_count'] = $this->collector->get_page_count_only( $lang['code'], 'publish' );
			}
			unset( $lang );
		}

		// Gather debug info - gated behind SSCRIBE_DEBUG for security.
		// SECURITY WARNING: Debug mode exposes sensitive internal data including page IDs,
		// server configuration, and error details. NEVER enable in production.
		$sscribe_debug_info = array();
		$sscribe_is_debug   = defined( 'SSCRIBE_DEBUG' ) && SSCRIBE_DEBUG;

		// Log a warning if debug mode is enabled (helps catch accidental production enabling).
		if ( $sscribe_is_debug ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional debug warning.
			error_log( 'SScribe: Debug mode is ENABLED. This should NOT be enabled in production environments.' );
		}

		if ( $sscribe_is_debug ) {
			$sscribe_debug_info['wpml_active']     = $sscribe_wpml_active;
			$sscribe_debug_info['languages_count'] = count( $sscribe_languages );
			$sscribe_debug_info['total_pages_all'] = $sscribe_total_pages_all;
			$sscribe_debug_info['status_counts']   = $sscribe_status_counts;

			// Get detailed page info per language.
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

				// Check for duplicate slugs across languages using batched query.
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

				// Single batch query for all pages across all languages.
				$all_id_list = array_column( $all_flat_ids, 'id' );
				$posts_by_id = array();
				if ( ! empty( $all_id_list ) ) {
					// Use 'all' fields to get complete post objects in a single query (avoids N+1).
					$batch_posts = get_posts(
						array(
							'post__in'       => $all_id_list,
							'post_type'      => 'page',
							'post_status'    => 'any',
							'posts_per_page' => -1,
						)
					);
					foreach ( $batch_posts as $post ) {
						$posts_by_id[ $post->ID ] = $post;
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

			// Server info.
			$sscribe_debug_info['server'] = array(
				'php_version'         => PHP_VERSION,
				'memory_limit'        => ini_get( 'memory_limit' ),
				'max_execution_time'  => ini_get( 'max_execution_time' ),
				'upload_max_filesize' => ini_get( 'upload_max_filesize' ),
				'post_max_size'       => ini_get( 'post_max_size' ),
			);

			// WordPress info.
			$sscribe_debug_info['wordpress'] = array(
				'version' => get_bloginfo( 'version' ),
				'locale'  => get_locale(),
			);
		}

		// Gather recent exports.
		$sscribe_recent_exports = array();
		$upload_dir             = wp_upload_dir();
		$export_dir             = trailingslashit( $upload_dir['basedir'] ) . 'sscribe-exports/';

		if ( file_exists( $export_dir ) ) {
			$files = glob( $export_dir . 'sscribe-*.zip' );
			if ( $files ) {
				// Sort by modified time descending (newest first).
				usort(
					$files,
					function ( $a, $b ) {
						return filemtime( $b ) - filemtime( $a );
					}
				);

				foreach ( $files as $file ) {
					$filename = basename( $file );

					// Parse language code from new standardized filename format.
					$lang_code = 'all';
					if ( preg_match( '/^sscribe-export-([a-z0-9_-]+)-/i', $filename, $matches ) ) {
						$lang_code = $matches[1];
					}

					// Attempt to find matching WPML flag and name.
					$flag_url  = '';
					$lang_name = 'All Languages';
					if ( $sscribe_wpml_active && ! empty( $sscribe_languages ) ) {
						foreach ( $sscribe_languages as $l ) {
							if ( $l['code'] === $lang_code ) {
								$flag_url  = isset( $l['flag_url'] ) ? $l['flag_url'] : '';
								$lang_name = isset( $l['name'] ) ? $l['name'] : strtoupper( $lang_code );
								break;
							}
						}
					}

					$sscribe_recent_exports[] = array(
						'filename'  => $filename,
						'url'       => add_query_arg(
							array(
								'action' => 'sscribe_download',
								'file'   => sanitize_file_name( $filename ),
								'nonce'  => wp_create_nonce( 'sscribe_download' ),
							),
							admin_url( 'admin-ajax.php' )
						),
						'time'      => filemtime( $file ),
						'size'      => filesize( $file ),
						'lang_code' => $lang_code,
						'flag_url'  => $flag_url,
						'lang_name' => $lang_name,
					);
				}
			}
		}

		// Gather active SEO plugins for template.
		$sscribe_seo_plugins = $this->seo_reader->get_active_seo_plugins();

		include SSCRIBE_PLUGIN_DIR . 'admin/partials/sscribe-admin-display.php';
	}

	/**
	 * Add plugin action links.
	 *
	 * @param array $links Existing plugin links.
	 * @return array Updated plugin links.
	 */
	public function add_plugin_action_links( array $links ): array {
		$plugin_links = array(
			'<a href="' . esc_url( admin_url( 'tools.php?page=sscribe-export' ) ) . '">' . esc_html__( 'Export Pages', 'sscribe-export-site-pages' ) . '</a>',
		);
		return array_merge( $plugin_links, $links );
	}
}
