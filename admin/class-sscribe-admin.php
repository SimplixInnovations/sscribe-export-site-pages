<?php
declare(strict_types=1);

/**
 * Admin interface for SScribe.
 *
 * @package SScribe
 */

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
	 */
	public function __construct() {
		$this->collector  = new SScribe_Page_Collector();
		$this->seo_reader = new SScribe_SEO_Reader();
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

		// Admin CSS.
		wp_enqueue_style(
			'sscribe-admin',
			SSCRIBE_PLUGIN_URL . 'admin/css/sscribe-admin.css',
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
					'starting'       => __( 'Starting export...', 'sscribe-export-site-pages' ),
					'processing'     => __( 'Processing...', 'sscribe-export-site-pages' ),
					'complete'       => __( 'Export complete!', 'sscribe-export-site-pages' ),
					'error'          => __( 'An error occurred. Please try again.', 'sscribe-export-site-pages' ),
					'download'       => __( 'Download ZIP', 'sscribe-export-site-pages' ),
					'generating'     => __( 'Generating documents...', 'sscribe-export-site-pages' ),
					'confirm_export' => __( 'Start exporting pages?', 'sscribe-export-site-pages' ),
					'confirm_delete' => __( 'Delete this export file?', 'sscribe-export-site-pages' ),
					'auto_delete'    => __( 'This file will be automatically deleted in 1 hour for security.', 'sscribe-export-site-pages' ),
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
		$wpml_active = $this->collector->is_wpml_active();
		$languages   = $this->collector->get_wpml_languages();

		// For the "All Languages" card: count published pages in ALL languages combined.
		$total_pages_all = $this->collector->get_page_count_only( '', 'publish' );

		// For Page Status section: use empty string for all languages as default.
		$default_language = '';
		$status_counts    = $this->collector->get_post_status_counts( $default_language );

		// Enrich languages with per-language page counts.
		if ( $wpml_active && ! empty( $languages ) ) {
			foreach ( $languages as &$lang ) {
				$lang['page_count'] = $this->collector->get_page_count_only( $lang['code'], 'publish' );
			}
			unset( $lang );
		}

		// Gather debug info - gated behind SSCRIBE_DEBUG for security.
		$sscribe_debug_info = array();
		$sscribe_is_debug   = defined( 'SSCRIBE_DEBUG' ) && SSCRIBE_DEBUG;

		if ( $sscribe_is_debug ) {
			$sscribe_debug_info['wpml_active']     = $wpml_active;
			$sscribe_debug_info['languages_count'] = count( $languages );
			$sscribe_debug_info['total_pages_all'] = $total_pages_all;
			$sscribe_debug_info['status_counts']   = $status_counts;

			// Get detailed page info per language.
			if ( $wpml_active && ! empty( $languages ) ) {
				foreach ( $languages as $lang ) {
					$lang_code = $lang['code'];
					$sscribe_debug_info['language_details'][ $lang_code ] = array(
						'name'             => $lang['name'],
						'page_count'       => $lang['page_count'] ?? 0,
						'status_breakdown' => $this->collector->get_post_status_counts( $lang_code ),
					);

					$page_ids = $this->collector->get_page_ids( $lang_code, 'publish' );
					$sscribe_debug_info['language_details'][ $lang_code ]['published_page_ids'] = $page_ids;
					$sscribe_debug_info['language_details'][ $lang_code ]['published_count']    = count( $page_ids );
				}

				// Check for duplicate slugs across languages.
				$all_slugs = array();
				foreach ( $languages as $lang ) {
					$page_ids = $this->collector->get_page_ids( $lang['code'], 'publish' );
					foreach ( $page_ids as $pid ) {
						$post = get_post( $pid );
						if ( $post ) {
							$slug = $post->post_name;
							if ( ! isset( $all_slugs[ $slug ] ) ) {
								$all_slugs[ $slug ] = array();
							}
							$all_slugs[ $slug ][] = array(
								'id'    => $pid,
								'lang'  => $lang['code'],
								'title' => $post->post_title,
							);
						}
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
		$recent_exports = array();
		$upload_dir     = wp_upload_dir();
		$export_dir     = trailingslashit( $upload_dir['basedir'] ) . 'sscribe-exports/';

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
					if ( $wpml_active && ! empty( $languages ) ) {
						foreach ( $languages as $l ) {
							if ( $l['code'] === $lang_code ) {
								$flag_url  = isset( $l['flag_url'] ) ? $l['flag_url'] : '';
								$lang_name = isset( $l['name'] ) ? $l['name'] : strtoupper( $lang_code );
								break;
							}
						}
					}

					$recent_exports[] = array(
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
