<?php
/**
 * SScribe Export Query Controller.
 *
 * @package SScribe_Export_Site_Pages
 * @license GPL v2 or later
 * @link    https://www.gnu.org/licenses/gpl-2.0.html
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AJAX query controller.
 *
 * @package SScribe_Export_Site_Pages
 * @subpackage API
 */
class SScribe_Export_Query_Controller {

	/**
	 * Rate limiter instance.
	 *
	 * @var SScribe_Export_Rate_Limiter
	 */
	private readonly SScribe_Export_Rate_Limiter $rate_limiter;

	/**
	 * Diagnostics instance.
	 *
	 * @var SScribe_Diagnostics
	 */
	private readonly SScribe_Diagnostics $diagnostics;

	/**
	 * Page collector instance.
	 *
	 * @var SScribe_Page_Collector
	 */
	private readonly SScribe_Page_Collector $collector;

	/**
	 * Logger instance.
	 *
	 * @var SScribe_Logger_Interface
	 */
	private readonly SScribe_Logger_Interface $logger;

	/**
	 * ZIP handler instance.
	 *
	 * @var SScribe_Zip_Handler
	 */
	private readonly SScribe_Zip_Handler $zip_handler;

	/**
	 * Adaptive metrics instance.
	 *
	 * @var SScribe_Adaptive_Metrics
	 */
	private readonly SScribe_Adaptive_Metrics $adaptive_metrics;

	/**
	 * Error handler instance.
	 *
	 * @var SScribe_Export_Error_Handler
	 */
	private readonly SScribe_Export_Error_Handler $error_handler;

	/**
	 * Initialize the controller.
	 *
	 * @param SScribe_Export_Rate_Limiter|null  $rate_limiter     Rate limiter.
	 * @param SScribe_Diagnostics|null          $diagnostics      Diagnostics.
	 * @param SScribe_Page_Collector|null       $collector        Page collector.
	 * @param SScribe_Logger_Interface|null     $logger           Logger.
	 * @param SScribe_Zip_Handler|null          $zip_handler      ZIP handler.
	 * @param SScribe_Adaptive_Metrics|null     $adaptive_metrics Adaptive metrics.
	 * @param SScribe_Export_Error_Handler|null $error_handler    Error handler.
	 */
	public function __construct(
		?SScribe_Export_Rate_Limiter $rate_limiter = null,
		?SScribe_Diagnostics $diagnostics = null,
		?SScribe_Page_Collector $collector = null,
		?SScribe_Logger_Interface $logger = null,
		?SScribe_Zip_Handler $zip_handler = null,
		?SScribe_Adaptive_Metrics $adaptive_metrics = null,
		?SScribe_Export_Error_Handler $error_handler = null
	) {
		$this->rate_limiter     = $rate_limiter ?? new SScribe_Export_Rate_Limiter();
		$this->diagnostics      = $diagnostics ?? new SScribe_Diagnostics();
		$this->collector        = $collector ?? new SScribe_Page_Collector();
		$this->logger           = $logger ?? SScribe_Logger::instance();
		$this->zip_handler      = $zip_handler ?? new SScribe_Zip_Handler();
		$this->adaptive_metrics = $adaptive_metrics ?? new SScribe_Adaptive_Metrics();
		$this->error_handler    = $error_handler ?? new SScribe_Export_Error_Handler();
	}

	/**
	 * AJAX handler for health check.
	 *
	 * Gated by the dedicated `sscribe_health` capability (not the
	 * `sscribe_export` capability). Splitting the two lets a site admin
	 * grant read-only diagnostic access without granting export
	 * authority : useful for support staff who should be able to run
	 * the "copy support info" action but not start an export.
	 *
	 * @return void
	 */
	public function ajax_health_check(): void {
		if ( ! check_ajax_referer( 'sscribe_health_nonce', 'nonce', false ) ) {
			SScribe_AJAX_Guard::error(
				array(
					'code'    => 'invalid_nonce',
					'message' => __( 'Security check failed.', 'sscribe-export-site-pages' ),
				),
				403
			);
		}

		/**
		 * Filter the capability required to call the health diagnostics
		 * endpoint. Defaults to the dedicated `sscribe_health` capability
		 * (granted to administrators by {@see SScribe_Activator}).
		 *
		 * @since 1.1.3
		 *
		 * @param string $capability Capability name.
		 */
		$health_capability = SScribe_Capabilities::get_health_required();

		if ( ! current_user_can( $health_capability ) ) {
			SScribe_AJAX_Guard::error(
				array(
					'code'    => 'permission_denied',
					'message' => __( 'Permission denied.', 'sscribe-export-site-pages' ),
				),
				403
			);
		}

		$rate_check = $this->rate_limiter->check_rate_limit( $health_capability );
		if ( false === $rate_check ) {
			SScribe_AJAX_Guard::error(
				array(
					'code'    => 'rate_limited',
					'message' => __( 'Too many requests. Please wait a moment.', 'sscribe-export-site-pages' ),
				),
				429
			);
		}

		$force = '1' === SScribe_AJAX_Guard::get_text( 'force', '0', 1 );
		$cache_key = 'sscribe_health_snapshot_' . $health_capability . '_' . get_current_user_id();
		if ( ! $force ) {
			$cached = get_transient( $cache_key );
			if ( false !== $cached && is_array( $cached ) ) {
				$cached['cache_hit'] = true;
				SScribe_AJAX_Guard::success( $cached );
			}
		}

		$diagnostics = $this->diagnostics->check_ajax_health();
		$boot        = $this->diagnostics->get_boot_diagnostics();

		$payload = array(
			'ajax_health' => $diagnostics,
			'boot_state'  => $boot,
			'server_time' => current_time( 'mysql' ),
			'server_utc'  => gmdate( 'Y-m-d H:i:s' ),
		);

		set_transient( $cache_key, $payload, 30 );

		SScribe_AJAX_Guard::success( $payload );
	}

	/**
	 * AJAX handler for getting post status counts.
	 *
	 * @param string $export_capability Required capability.
	 * @return void
	 */
	public function ajax_get_status_counts( string $export_capability = 'sscribe_export' ): void {
		$rate_check = $this->rate_limiter->check_rate_limit( $export_capability );
		if ( false === $rate_check ) {
			SScribe_AJAX_Guard::error(
				array(
					'code'    => 'rate_limited',
					'message' => __( 'Too many requests. Please wait.', 'sscribe-export-site-pages' ),
				),
				429
			);
		}

		$requested_language = SScribe_AJAX_Guard::post_text( 'language', '', 100 );
		$language           = $this->collector->normalize_language_code( $requested_language );
		if ( '' !== $requested_language && '' === $language ) {
			SScribe_AJAX_Guard::error( array( 'message' => __( 'Invalid or inactive language.', 'sscribe-export-site-pages' ) ), 400 );
		}
		$post_type = SScribe_AJAX_Guard::post_text( 'post_type', 'page', 30 );
		if ( ! in_array( $post_type, array( 'page', 'post', 'any' ), true ) ) {
			$post_type = 'page';
		}

		$page_counts = $this->collector->get_post_status_counts( $language, 'page' );
		$post_counts = $this->collector->get_post_status_counts( $language, 'post' );

		$any_counts = array();
		$all_keys   = array_unique( array_merge( array_keys( $page_counts ), array_keys( $post_counts ) ) );
		foreach ( $all_keys as $key ) {
			$any_counts[ $key ] = ( $page_counts[ $key ] ?? 0 ) + ( $post_counts[ $key ] ?? 0 );
		}

		$counts = 'any' === $post_type ? $any_counts : ( 'page' === $post_type ? $page_counts : $post_counts );

		SScribe_AJAX_Guard::success(
			array(
				'counts'      => $counts,
				'counts_page' => $page_counts,
				'counts_post' => $post_counts,
				'counts_any'  => $any_counts,
			)
		);
	}

	/**
	 * Batch AJAX handler: returns status counts for many languages in one call.
	 *
	 * Replaces the per-language firehose where the JS previously fired one
	 * round-trip per language option. With 10+ WPML languages, that was 10+
	 * concurrent POSTs per card-toggle; collapsing them into a single batched
	 * query cuts round-trips by Nx and the SQL by the same factor because
	 * the per-language counts share a single cache key per (lang, post_type).
	 *
	 * Accepts either a JSON-encoded array under `languages[]` or a CSV under
	 * `languages` (the JS sends JSON; CSV is a defensive fallback for older
	 * clients).
	 *
	 * @param string $export_capability Required capability.
	 * @return void
	 */
	public function ajax_get_all_status_counts( string $export_capability = 'sscribe_export' ): void {
		$rate_check = $this->rate_limiter->check_rate_limit( $export_capability );
		if ( false === $rate_check ) {
			SScribe_AJAX_Guard::error(
				array(
					'code'    => 'rate_limited',
					'message' => __( 'Too many requests. Please wait.', 'sscribe-export-site-pages' ),
				),
				429
			);
		}

		$post_type = SScribe_AJAX_Guard::post_text( 'post_type', 'page', 30 );
		if ( ! in_array( $post_type, array( 'page', 'post', 'any' ), true ) ) {
			$post_type = 'page';
		}

		$languages = SScribe_AJAX_Guard::post_array( 'languages', 50 );
		if ( empty( $languages ) ) {
			$raw     = SScribe_AJAX_Guard::post_text( 'languages', '', 5000 );
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				$languages = array_slice( $decoded, 0, 50 );
			} elseif ( '' !== $raw ) {
				$languages = array_slice( array_filter( array_map( 'trim', explode( ',', $raw ) ) ), 0, 50 );
			}
		}

		$languages = array_values(
			array_filter(
				array_unique(
					array_map(
						function ( $lang ) {
							return is_string( $lang ) ? $this->collector->normalize_language_code( sanitize_text_field( $lang ) ) : '';
						},
						$languages
					)
				),
				static function ( string $language ): bool {
					return '' !== $language;
				}
			)
		);
		$languages = array_slice( $languages, 0, 50 );

		$per_language = array();
		foreach ( $languages as $language ) {
			$page_counts = $this->collector->get_post_status_counts( $language, 'page' );
			$post_counts = $this->collector->get_post_status_counts( $language, 'post' );
			$any_counts  = array();
			$all_keys    = array_unique( array_merge( array_keys( $page_counts ), array_keys( $post_counts ) ) );
			foreach ( $all_keys as $key ) {
				$any_counts[ $key ] = ( $page_counts[ $key ] ?? 0 ) + ( $post_counts[ $key ] ?? 0 );
			}
			$per_language[ $language ] = array(
				'counts'      => 'any' === $post_type
					? $any_counts
					: ( 'page' === $post_type ? $page_counts : $post_counts ),
				'counts_page' => $page_counts,
				'counts_post' => $post_counts,
				'counts_any'  => $any_counts,
			);
		}

		SScribe_AJAX_Guard::success(
			array(
				'post_type'     => $post_type,
				'languages'     => $per_language,
				'queried_count' => count( $per_language ),
			)
		);
	}

	/**
	 * AJAX handler for getting export log.
	 *
	 * @param string $export_capability Required capability.
	 * @return void
	 */
	public function ajax_get_export_log( string $export_capability = 'sscribe_export' ): void {
		if ( ! check_ajax_referer( 'sscribe_download', 'nonce', false ) ) {
			SScribe_AJAX_Guard::error(
				array(
					'code'    => 'invalid_nonce',
					'message' => __( 'Security check failed.', 'sscribe-export-site-pages' ),
				),
				403
			);
		}

		if ( ! current_user_can( $export_capability ) ) {
			SScribe_AJAX_Guard::error(
				array(
					'code'    => 'permission_denied',
					'message' => __( 'Permission denied.', 'sscribe-export-site-pages' ),
				),
				403
			);
		}

		$raw_filename = SScribe_AJAX_Guard::post_text( 'file', '', 200 );
		$filename     = sanitize_file_name( $raw_filename );

		if ( '' === $filename || ! hash_equals( $raw_filename, $filename ) || 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]{0,199}\.zip$/D', $filename ) ) {
			SScribe_AJAX_Guard::error(
				array( 'message' => __( 'Invalid filename.', 'sscribe-export-site-pages' ) ),
				400
			);
		}

		$exports = $this->zip_handler->get_export_entry( $filename );

		if ( null === $exports ) {
			SScribe_AJAX_Guard::error(
				array( 'message' => __( 'Export not found.', 'sscribe-export-site-pages' ) ),
				404
			);
		}

		$export_info   = $exports;
		$stored_user_id = isset( $export_info['user_id'] ) ? (int) $export_info['user_id'] : 0;
		if ( $stored_user_id <= 0 || get_current_user_id() !== $stored_user_id ) {
			SScribe_AJAX_Guard::error(
				array(
					'code'    => 'permission_denied',
					'message' => __( 'Permission denied.', 'sscribe-export-site-pages' ),
				),
				403
			);
		}

		$rate_check = $this->rate_limiter->check_rate_limit( $export_capability );
		if ( false === $rate_check ) {
			SScribe_AJAX_Guard::error(
				array(
					'message'  => __( 'Too many requests. Please wait a moment.', 'sscribe-export-site-pages' ),
					'code'     => 'rate_limited',
					'retry'    => true,
					'retry_in' => 60000,
				),
				429
			);
		}

		$log_data = SScribe_Export_Log::get_log_by_filename( $filename );

		if ( ! $log_data ) {
			SScribe_AJAX_Guard::error(
				array( 'message' => __( 'Log not found for this export.', 'sscribe-export-site-pages' ) ),
				404
			);
		}

		$offset = SScribe_AJAX_Guard::post_integer( 'offset', 0, 0, 1000000 );
		$limit  = SScribe_AJAX_Guard::post_integer( 'limit', 50, 1, 200 );
		if ( isset( $log_data['pages'] ) && is_array( $log_data['pages'] ) ) {
			$pages     = $log_data['pages'];
			$page_keys = array_keys( $pages );
			$total     = count( $page_keys );
			$slice     = array_slice( $page_keys, $offset, $limit, true );
			$log_data['pages']       = array_intersect_key( $pages, array_flip( $slice ) );
			$log_data['_pagination'] = array(
				'offset'   => $offset,
				'limit'    => $limit,
				'total'    => $total,
				'has_more' => ( $offset + $limit ) < $total,
			);
		}

		$structured_errors = $this->error_handler->build_structured_errors_from_log( $log_data );
		$string_errors     = is_array( $log_data['errors'] ?? null )
			? wp_list_pluck( $log_data['errors'], 'message' )
			: array();
		$diagnostics       = $this->error_handler->build_diagnostics_payload( $structured_errors, $string_errors );

		SScribe_AJAX_Guard::success(
			array(
				'log'         => $log_data,
				'diagnostics' => $diagnostics,
			)
		);
	}

	/**
	 * AJAX handler for preflight check before export.
	 *
	 * @param string $export_capability Required capability.
	 * @return void
	 */
	public function ajax_preflight_check( string $export_capability = 'sscribe_export' ): void {
		if ( false === $this->rate_limiter->check_rate_limit( $export_capability ) ) {
			SScribe_AJAX_Guard::error(
				array(
					'message'  => __( 'Too many requests. Please wait a moment.', 'sscribe-export-site-pages' ),
					'code'     => 'rate_limited',
					'retry'    => true,
					'retry_in' => 60000,
				),
				429
			);
		}

		$formats_raw   = SScribe_AJAX_Guard::post_array( 'formats', 10 );
		$formats_input = array();
		foreach ( array_slice( $formats_raw, 0, 10 ) as $format_input ) {
			if ( is_scalar( $format_input ) ) {
				$formats_input[] = sanitize_key( (string) $format_input );
			}
		}
		$formats       = array_values( array_filter( $formats_input, array( 'SScribe_Exporter_Factory', 'is_supported' ) ) );
		$formats       = ! empty( $formats ) ? array_unique( $formats ) : array( 'docx' );

		$page_count = SScribe_AJAX_Guard::post_integer( 'page_count', 0, 0, 100000 );

		$diagnostics = $this->diagnostics->run_preflight( $page_count, $formats );

		$sscribe_is_debug = SSCRIBE_DEBUG;
		if ( $sscribe_is_debug ) {
			$diagnostics['debug_info'] = array(
				'php_version'   => PHP_VERSION,
				'memory_limit'  => ini_get( 'memory_limit' ),
				'max_execution' => ini_get( 'max_execution_time' ),
				'upload_dir'    => basename( $this->zip_handler->get_export_dir() ),
			);
		}

		SScribe_AJAX_Guard::success( $diagnostics );
	}

	/**
	 * AJAX handler for export preview.
	 *
	 * @param string $export_capability Required capability.
	 * @return void
	 */
	public function ajax_get_export_preview( string $export_capability = 'sscribe_export' ): void {
		$rate_check = $this->rate_limiter->check_rate_limit( $export_capability );
		if ( false === $rate_check ) {
			SScribe_AJAX_Guard::error(
				array(
					'message'  => __( 'Too many requests. Please wait a moment.', 'sscribe-export-site-pages' ),
					'code'     => 'rate_limited',
					'retry'    => true,
					'retry_in' => 60000,
				),
				429
			);
		}

		$requested_language = SScribe_AJAX_Guard::post_text( 'language', '', 100 );
		$language           = $this->collector->normalize_language_code( $requested_language );
		if ( '' !== $requested_language && '' === $language ) {
			SScribe_AJAX_Guard::error( array( 'message' => __( 'Invalid or inactive language.', 'sscribe-export-site-pages' ) ), 400 );
		}
		$post_status = SScribe_AJAX_Guard::post_text( 'post_status', 'publish', 30 );
		if ( ! in_array( $post_status, array( 'publish', 'private', 'draft', 'pending', 'future' ), true ) ) {
			$post_status = 'publish';
		}

		$format = SScribe_AJAX_Guard::post_text( 'format', 'docx', 30 );

		if ( 'all' === $format ) {
			$formats = \SScribe_Exporter_Factory::get_supported_formats();
		} elseif ( \SScribe_Exporter_Factory::is_supported( $format ) ) {
			$formats = array( $format );
		} else {
			$formats = \SScribe_Exporter_Factory::get_supported_formats();
		}

		$post_type = SScribe_AJAX_Guard::post_text( 'post_type', 'page', 30 );
		if ( ! in_array( $post_type, array( 'page', 'post' ), true ) ) {
			$post_type = 'page';
		}

		$page_count = $this->collector->get_page_count_only( $language, $post_status, $post_type );

		$pages = $this->collector->get_page_ids( $language, $post_status, $post_type, 1 );

		$seconds_per_page = 0.0;
		foreach ( $formats as $selected_format ) {
			$seconds_per_page += $this->adaptive_metrics->get_seconds_per_page( $selected_format, $post_type );
		}
		$total_seconds = (int) ceil( $page_count * $seconds_per_page );

		if ( $total_seconds < 60 ) {
			$estimated_time = sprintf(
				/* translators: %d: Number of seconds. */
				_n( '%d second', '%d seconds', $total_seconds, 'sscribe-export-site-pages' ),
				max( 1, ceil( $total_seconds ) )
			);
		} elseif ( $total_seconds < 3600 ) {
			$minutes        = (int) ceil( $total_seconds / 60 );
			$estimated_time = sprintf(
				/* translators: %d: Number of minutes. */
				_n( '%d minute', '%d minutes', $minutes, 'sscribe-export-site-pages' ),
				$minutes
			);
		} else {
			$hours          = (int) floor( $total_seconds / 3600 );
			$minutes        = (int) ceil( ( $total_seconds % 3600 ) / 60 );
			$estimated_time = sprintf(
				/* translators: 1: Hours. 2: Minutes. */
				__( '%1$d hr %2$d min', 'sscribe-export-site-pages' ),
				max( 1, $hours ),
				max( 1, $minutes )
			);
		}

		$megabytes_per_page = 0.0;
		foreach ( $formats as $selected_format ) {
			$megabytes_per_page += $this->adaptive_metrics->get_mb_per_page( $selected_format, $post_type );
		}
		$size_mb = $page_count * $megabytes_per_page;

		if ( $size_mb < 1 ) {
			$file_size_estimate = round( $size_mb * 1024 ) . ' KB';
		} else {
			$file_size_estimate = round( $size_mb, 1 ) . ' MB';
		}

		$sample_page = null;
		if ( ! empty( $pages ) ) {
			$sample_id   = $pages[0];
			$sample_post = get_post( $sample_id );
			if ( $sample_post ) {
				$sample_page = array(
					'title'   => $sample_post->post_title,
					'url'     => get_permalink( $sample_id ),
					'content' => wp_kses_post( wp_trim_words( strip_shortcodes( $sample_post->post_content ), 50 ) ),
				);
			}
		}

		$language_display = '' !== $language ? $language : __( 'All Languages', 'sscribe-export-site-pages' );
		if ( '' !== $language && $this->collector->is_wpml_active() ) {
			$wpml_languages = $this->collector->get_wpml_languages();
			foreach ( $wpml_languages as $wl ) {
				if ( isset( $wl['code'] ) && $wl['code'] === $language ) {
					$language_display = $wl['name'] ?? strtoupper( $language );
					break;
				}
			}
		}

		$preview_data = array(
			'total_pages'        => $page_count,
			'format'             => $format,
			'formats'            => $formats,
			'estimated_time'     => $estimated_time,
			'file_size_estimate' => $file_size_estimate,
			'language'           => $language_display,
			'post_status'        => $post_status,
		);

		if ( null !== $sample_page ) {
			$preview_data = array_merge( $preview_data, $sample_page );
		}

		SScribe_AJAX_Guard::success( $preview_data );
	}

	/**
	 * AJAX handler for getting recent exports.
	 *
	 * @param string $export_capability Required capability.
	 * @return void
	 */
	public function ajax_get_recent_exports( string $export_capability = 'sscribe_export' ): void {
		$rate_check = $this->rate_limiter->check_rate_limit( $export_capability );
		if ( false === $rate_check ) {
			SScribe_AJAX_Guard::error(
				array(
					'message'  => __( 'Too many requests. Please wait a moment.', 'sscribe-export-site-pages' ),
					'code'     => 'rate_limited',
					'retry'    => true,
					'retry_in' => 60000,
				),
				429
			);
		}

		$exports = $this->zip_handler->list_export_entries();
		$user_id = get_current_user_id();

		$result = array();
		$date_option = get_option( 'date_format', 'Y-m-d' );
		$time_option = get_option( 'time_format', 'H:i' );
		$date_format = is_string( $date_option ) && '' !== $date_option ? substr( $date_option, 0, 100 ) : 'Y-m-d';
		$time_format = is_string( $time_option ) && '' !== $time_option ? substr( $time_option, 0, 100 ) : 'H:i';

		try {
			$export_dir = $this->zip_handler->get_export_dir();
		} catch ( \InvalidArgumentException $e ) {
			SScribe_AJAX_Guard::success(
				array(
					'exports'     => array(),
					'total_count' => 0,
				)
			);
			return;
		}

		foreach ( $exports as $filename => $data ) {
			if ( ! isset( $data['user_id'] ) || (int) $data['user_id'] !== $user_id ) {
				continue;
			}

			$clean_filename = sanitize_file_name( $filename );
			if ( ! hash_equals( $filename, $clean_filename ) || 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]{0,199}\.zip$/D', $filename ) ) {
				continue;
			}

			$file_path = $export_dir . '/' . $filename;
			if ( is_link( $file_path ) || ! is_file( $file_path ) ) {
				continue;
			}

			clearstatcache( true, $file_path );
			$file_size  = @filesize( $file_path );
			$file_mtime = @filemtime( $file_path );
			$created_at = (int) ( $data['created_at'] ?? $file_mtime );
			if ( $created_at <= 0 ) {
				$created_at = time();
			}
			$result[] = array(
				'filename'       => $filename,
				'session_id'     => sanitize_key( (string) ( $data['session_id'] ?? '' ) ),
				'url'            => esc_url_raw( $this->zip_handler->get_ajax_download_url( $filename ) ),
				'size'           => false !== $file_size ? $file_size : 0,
				'size_formatted' => false !== $file_size ? size_format( $file_size ) : '0 B',
				'time'           => $created_at,
				'date'           => wp_date(
					$date_format . ' ' . $time_format,
					$created_at,
				),
				'lang_code'      => isset( $data['lang_code'] ) && is_scalar( $data['lang_code'] ) ? sanitize_key( (string) $data['lang_code'] ) : '',
				'lang_name'      => isset( $data['lang_name'] ) && is_scalar( $data['lang_name'] ) ? sanitize_text_field( (string) $data['lang_name'] ) : '',
				'flag_url'       => isset( $data['flag_url'] ) && is_scalar( $data['flag_url'] ) ? esc_url_raw( (string) $data['flag_url'] ) : '',
			);
		}

		$sorted = $result;
		uasort(
			$sorted,
			function ( array $a, array $b ): int {
				return $b['time'] <=> $a['time'];
			}
		);

		SScribe_AJAX_Guard::success(
			array(
				'exports'      => array_slice( array_values( $sorted ), 0, 10 ),
				'total_count'  => count( $result ),
			)
		);
	}

	/**
	 * AJAX handler for getting support information.
	 *
	 * @param string $health_capability Required diagnostics capability.
	 * @return void
	 */
	public function ajax_get_support_info( string $health_capability = 'sscribe_health' ): void {
		if ( ! check_ajax_referer( 'sscribe_health_nonce', 'nonce', false ) ) {
			SScribe_AJAX_Guard::error(
				array(
					'code'    => 'invalid_nonce',
					'message' => __( 'Security check failed.', 'sscribe-export-site-pages' ),
				),
				403
			);
		}

		if ( ! current_user_can( $health_capability ) ) {
			SScribe_AJAX_Guard::error(
				array(
					'code'    => 'permission_denied',
					'message' => __( 'Permission denied.', 'sscribe-export-site-pages' ),
				),
				403
			);
		}

		$rate_check = $this->rate_limiter->check_rate_limit( $health_capability, 'health_support' );
		if ( false === $rate_check ) {
			SScribe_AJAX_Guard::error(
				array(
					'code'     => 'rate_limited',
					'message'  => __( 'Too many requests. Please wait a moment.', 'sscribe-export-site-pages' ),
					'retry'    => true,
					'retry_in' => 60000,
				),
				429
			);
		}

		try {
			$support_info = $this->diagnostics->get_support_info();
			SScribe_AJAX_Guard::success( $support_info );
		} catch ( \Throwable $e ) {
			$this->logger->error(
				'Support info AJAX failed',
				array(
					'error' => $e->getMessage(),
					'file'  => basename( $e->getFile() ) . ':' . $e->getLine(),
				)
			);

			SScribe_AJAX_Guard::error(
				array(
					'code'    => 'support_info_unavailable',
					'message' => __( 'Unable to load support information right now. Please try again later.', 'sscribe-export-site-pages' ),
				),
				500
			);
		}
	}
}
