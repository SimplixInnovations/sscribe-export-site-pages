<?php
/**
 * SScribe Page Collector.
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
 * Page collection and filtering.
 *
 * @package SScribe_Export_Site_Pages
 * @subpackage Collector
 */
class SScribe_Page_Collector {

	/**
	 * Maximum cache entries before LRU eviction.
	 */
	private const CACHE_MAX_SIZE = 500;

	/**
	 * Cached featured images by page ID.
	 *
	 * @var array<int, array>
	 */
	private array $featured_images_cache = array();

	/**
	 * Cached child pages by parent ID.
	 *
	 * @var array<int, array>
	 */
	private array $child_pages_cache = array();

	/**
	 * Cached breadcrumbs by page ID.
	 *
	 * @var array<int, array>
	 */
	private array $breadcrumb_cache = array();

	/**
	 * Clear all page caches.
	 *
	 * @return void
	 */
	public function clear_page_caches(): void {
		$this->featured_images_cache = array();
		$this->child_pages_cache     = array();
		$this->breadcrumb_cache      = array();
	}

	/**
	 * Add an entry to a cache with LRU eviction when max size is exceeded.
	 *
	 * @param array &$cache Cache reference.
	 * @param int   $key    Cache key.
	 * @param mixed $value  Cache value.
	 * @return void
	 */
	private function cache_add( array &$cache, int $key, mixed $value ): void {
		if ( count( $cache ) >= self::CACHE_MAX_SIZE ) {
			// Evict oldest entry (first key) to maintain bounded size.
			array_shift( $cache );
		}
		$cache[ $key ] = $value;
	}

	/**
	 * SEO reader instance.
	 *
	 * @var SScribe_SEO_Reader
	 */
	private readonly SScribe_SEO_Reader $seo_reader;

	/**
	 * Logger instance.
	 *
	 * @var SScribe_Logger_Interface
	 */
	private readonly SScribe_Logger_Interface $logger;

	/**
	 * Initialize the page collector.
	 */
	public function __construct() {
		$this->seo_reader = new SScribe_SEO_Reader();
		$this->logger     = SScribe_Logger::instance( SScribe_Logger::is_logging_enabled() );
	}

	/**
	 * Get page IDs matching filter criteria.
	 *
	 * @param string $language    Language code.
	 * @param string $post_status Post status.
	 * @param string $post_type   Post type.
	 * @param int    $limit       Maximum number of IDs to return (-1 for all).
	 * @return array<int>
	 */
	public function get_page_ids( string $language = '', string $post_status = 'publish', string $post_type = 'page', int $limit = -1 ): array {

		$filter_value = apply_filters( 'sscribe_use_chunked_page_ids', null );
		if ( null !== $filter_value && false === $filter_value ) {

			return $this->get_page_ids_direct( $language, $post_status, $post_type, $limit );
		}

		$estimated_count = $this->estimate_page_count( $language, $post_status, $post_type );
		$use_chunked     = $estimated_count > 500 || $limit > 0;

		if ( $filter_value || $use_chunked ) {
			$all_ids = array();
			foreach ( $this->get_page_ids_chunked( $language, $post_status, $post_type, 500 ) as $chunk ) {
				$all_ids = array_merge( $all_ids, $chunk );
				if ( $limit > 0 && count( $all_ids ) >= $limit ) {
					break;
				}
			}
			if ( $limit > 0 ) {
				$all_ids = array_slice( $all_ids, 0, $limit );
			}
			return $all_ids;
		}

		return $this->get_page_ids_direct( $language, $post_status, $post_type, $limit );
	}

	/**
	 * Get page IDs directly via WP_Query.
	 *
	 * @param string $language    Language code.
	 * @param string $post_status  Post status.
	 * @param string $post_type    Post type.
	 * @param int    $limit        Maximum number of IDs to return (-1 for all).
	 * @return array<int>
	 */
	private function get_page_ids_direct( string $language, string $post_status, string $post_type, int $limit = -1 ): array {
		$post_status = $this->validate_post_status( $post_status );

		$cache_key = "sscribe_page_ids_v2_{$post_status}_" . md5( "{$language}_{$post_type}_{$limit}" );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		// Cap posts_per_page at 10000 to prevent memory exhaustion on sites with
		// thousands of pages, while still allowing explicit per-page limits.
		$effective_limit = $limit > 0 ? min( $limit, 10000 ) : 10000;

		$args = array(
			'post_type'      => $this->resolve_post_type_for_query( $post_type ),
			'post_status'    => $post_status,
			'posts_per_page' => $effective_limit,
			'fields'         => 'ids',
			'orderby'        => 'menu_order title',
			'order'          => 'ASC',
			'no_found_rows'  => true,
		);

		$switched = false;

		try {
			if ( $this->is_wpml_active() ) {
				$target_lang = ! empty( $language ) ? $language : 'all';

				$this->debug_log(
					'WPML: Switching language',
					array(
						'requested_language' => $language,
						'target_lang'        => $target_lang,
					)
				);

				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML hook.
				do_action( 'wpml_switch_language', $target_lang );
				$args['suppress_filters'] = false;
				$switched                 = true;
			}

			$query    = new WP_Query( $args );
			$page_ids = $query->posts;

			$this->debug_log(
				'WP_Query results',
				array(
					'language'        => $language,
					'post_status'     => $post_status,
					'post_type'       => $post_type,
					'page_count'      => count( $page_ids ),
					'page_ids_sample' => array_slice( $page_ids, 0, 20 ),
				)
			);
		} finally {
			if ( $switched ) {
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML hook.
				do_action( 'wpml_switch_language', null );
				$this->debug_log( 'WPML: Language reset' );
			}
		}

		set_transient( $cache_key, $page_ids, 5 * MINUTE_IN_SECONDS );

		return $page_ids;
	}

	/**
	 * Estimate total page count for chunking decision.
	 *
	 * @param string $language    Language code.
	 * @param string $post_status Post status.
	 * @param string $post_type   Post type.
	 * @return int
	 */
	private function estimate_page_count( string $language, string $post_status, string $post_type ): int {
		global $wpdb;

		$post_status = $this->validate_post_status( $post_status );
		$post_types  = $this->resolve_post_type_for_query( $post_type );

		if ( is_array( $post_types ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
			// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders are dynamically built above.

			$sql = $wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe.
				$post_types
			);
			// phpcs:enable

		} else {
			$sql = $wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe.
				$post_types
			);
		}

		if ( 'all' !== $post_status ) {
			$sql .= $wpdb->prepare( ' AND post_status = %s', $post_status );
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Count query for auto-detection; caching not needed. SQL is prepared via $wpdb->prepare() above.

		$count = (int) $wpdb->get_var( $sql );
		// phpcs:enable

		return $count;
	}

	/**
	 * Get page IDs in chunks via generator.
	 *
	 * @param string $language    Language code.
	 * @param string $post_status Post status.
	 * @param string $post_type   Post type.
	 * @param int    $chunk_size  Chunk size.
	 * @return \Generator<int[]>
	 */
	public function get_page_ids_chunked( string $language = '', string $post_status = 'publish', string $post_type = 'page', int $chunk_size = 100 ): \Generator {
		$post_status = $this->validate_post_status( $post_status );
		$chunk_size  = (int) apply_filters( 'sscribe_page_ids_chunk_size', $chunk_size );

		$page = 1;

		do {
			$args = array(
				'post_type'      => $this->resolve_post_type_for_query( $post_type ),
				'post_status'    => $post_status,
				'posts_per_page' => $chunk_size,
				'paged'          => $page,
				'fields'         => 'ids',
				'orderby'        => 'menu_order title',
				'order'          => 'ASC',
			);

			$switched = false;

			try {
				if ( $this->is_wpml_active() ) {
					$target_lang = ! empty( $language ) ? $language : 'all';
					// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML hook.
					do_action( 'wpml_switch_language', $target_lang );
					$args['suppress_filters'] = false;
					$switched                 = true;
				}

				$query = new WP_Query( $args );
			} finally {
				if ( $switched ) {
					// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML hook.
					do_action( 'wpml_switch_language', null );
				}
			}

			if ( ! empty( $query->posts ) ) {
				yield $query->posts;
			}

			++$page;
		} while ( $page <= $query->max_num_pages );
	}

	/**
	 * Log debug message.
	 *
	 * @param string $message Debug message.
	 * @param array  $data    Context data.
	 * @return void
	 */
	private function debug_log( string $message, array $data = array() ): void {
		$this->logger->debug( $message, $data );
	}

	/**
	 * Get featured images for a batch of page IDs.
	 *
	 * @param array<int> $page_ids Page IDs.
	 * @return array Featured image data.
	 */
	public function get_featured_images_batch( array $page_ids ): array {
		if ( empty( $page_ids ) ) {
			return array();
		}

		$page_ids = array_map(
			function ( $id ) {
				return absint( $id );
			},
			$page_ids
		);
		$page_ids = array_filter( $page_ids );

		if ( empty( $page_ids ) ) {
			return array();
		}

		global $wpdb;

		// Process in chunks of 100 to prevent unbounded IN clauses while handling large batches.
		$chunk_size = 100;
		$chunks     = array_chunk( $page_ids, $chunk_size );

		$thumbnail_ids = array();
		$page_to_thumb = array();

		foreach ( $chunks as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
			$sql          = "SELECT post_id, meta_value AS thumbnail_id FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND post_id IN ({$placeholders})";
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Placeholders are safely generated; chunked batch operation; caching not needed for one-time batch export.
			$results = $wpdb->get_results( $wpdb->prepare( $sql, ...$chunk ) );

			if ( $wpdb->last_error ) {
				$this->debug_log(
					'Database query failed for featured images',
					array(
						'error' => $wpdb->last_error,
						'query' => $wpdb->last_query,
					)
				);
				continue;
			}

			foreach ( $results as $row ) {
				$thumb_id                             = (int) $row->thumbnail_id;
				$thumbnail_ids[]                      = $thumb_id;
				$page_to_thumb[ (int) $row->post_id ] = $thumb_id;
			}
		}

		$attachment_data = array();
		if ( ! empty( $thumbnail_ids ) ) {
			$thumb_placeholders = implode( ',', array_fill( 0, count( $thumbnail_ids ), '%d' ) );
			$sql                = "SELECT p.ID, p.guid, pm_path.meta_value AS filepath FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} pm_path ON p.ID = pm_path.post_id AND pm_path.meta_key = '_wp_attached_file' WHERE p.ID IN ({$thumb_placeholders})";
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Placeholders are safely generated; batch operation for attachment paths; caching not needed for one-time batch export.
			$attachments = $wpdb->get_results( $wpdb->prepare( $sql, ...$thumbnail_ids ) );

			if ( $wpdb->last_error ) {
				$this->debug_log(
					'Database query failed for attachments',
					array(
						'error' => $wpdb->last_error,
					)
				);
			}

			$upload_base = $this->get_upload_base_dir();

			foreach ( $attachments as $att ) {
				$attachment_data[ (int) $att->ID ] = array(
					'url'  => $att->guid,
					'path' => $att->filepath ? trailingslashit( $upload_base ) . $att->filepath : '',
				);
			}
		}

		$featured_images = array();
		foreach ( $page_ids as $page_id ) {
			if ( isset( $page_to_thumb[ $page_id ] ) ) {
				$thumb_id                    = $page_to_thumb[ $page_id ];
				$featured_images[ $page_id ] = array(
					'id'   => $thumb_id,
					'url'  => $attachment_data[ $thumb_id ]['url'] ?? '',
					'path' => $attachment_data[ $thumb_id ]['path'] ?? '',
				);
			} else {
				$featured_images[ $page_id ] = array(
					'id'   => 0,
					'url'  => '',
					'path' => '',
				);
			}
		}

		foreach ( $featured_images as $k => $v ) {
			$this->cache_add( $this->featured_images_cache, $k, $v );
		}

		return $featured_images;
	}

	/**
	 * Get the WordPress upload base directory.
	 *
	 * @return string
	 */
	private function get_upload_base_dir(): string {
		$upload_dir = wp_upload_dir();
		return $upload_dir['basedir'];
	}

	/**
	 * Get page count only (lightweight).
	 *
	 * @param string $language    Language code.
	 * @param string $post_status Post status.
	 * @param string $post_type   Post type.
	 * @return int
	 */
	public function get_page_count_only( string $language = '', string $post_status = 'publish', string $post_type = 'page' ): int {
		$post_status = $this->validate_post_status( $post_status );

		$args = array(
			'post_type'      => $this->resolve_post_type_for_query( $post_type ),
			'post_status'    => $post_status,
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => false,
		);

		$switched = false;

		try {
			if ( $this->is_wpml_active() ) {
				$target_lang = ! empty( $language ) ? $language : 'all';

				$this->debug_log(
					'WPML get_page_count_only: Switching language',
					array(
						'requested_language' => $language,
						'target_lang'        => $target_lang,
					)
				);

				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML hook.
				do_action( 'wpml_switch_language', $target_lang );
				$args['suppress_filters'] = false;
				$switched                 = true;
			}

			$query = new WP_Query( $args );
			$count = (int) $query->found_posts;
			wp_reset_postdata();

			$this->debug_log(
				'get_page_count_only result',
				array(
					'language'    => $language,
					'post_status' => $post_status,
					'count'       => $count,
				)
			);
		} finally {
			if ( $switched ) {
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML hook.
				do_action( 'wpml_switch_language', null );
			}
		}

		return $count;
	}

	/**
	 * Get full page data by ID.
	 *
	 * Note: Uses a static guard to prevent nested the_content filter calls.
	 * If a fatal OOM occurs during content filtering, subsequent calls in the
	 * same PHP process will skip apply_filters('the_content') silently. The
	 * finally block resets the guard, but an OOM kill skips the finally block.
	 *
	 * @param int $page_id Page ID.
	 * @return array|false Page data or false.
	 */
	public function get_page_data( int $page_id ): array|false {
		$page_id = absint( $page_id );
		if ( $page_id <= 0 ) {
			return false;
		}

		$post_object = get_post( $page_id );
		if ( ! $post_object || ! in_array( $post_object->post_type, array( 'page', 'post' ), true ) ) {
			return false;
		}

		// Skip password-protected posts - export only title and note.
		if ( ! empty( $post_object->post_password ) ) {
			return array(
				'id'                  => $page_id,
				'title'               => html_entity_decode(
					get_the_title( $page_id ) ? get_the_title( $page_id ) : sprintf( 'Untitled Page %d', $page_id ),
					ENT_QUOTES | ENT_HTML5,
					'UTF-8'
				),
				'content'             => '<p>' . __( '[Password Protected Content]', 'sscribe-export-site-pages' ) . '</p>',
				'raw_content'         => '',
				'excerpt'             => '',
				'permalink'           => get_permalink( $page_id ),
				'slug'                => $post_object->post_name,
				'author'              => get_the_author_meta( 'display_name', $post_object->post_author ) ?? __( 'Unknown', 'sscribe-export-site-pages' ),
				'date_published'      => get_the_date( 'F j, Y', $page_id ),
				'date_modified'       => get_the_modified_date( 'F j, Y', $page_id ),
				'featured_image_url'  => '',
				'featured_image_path' => '',
				'word_count'          => 0,
				'reading_time'        => 0,
				'breadcrumbs'         => array(),
				'children'            => array(),
				'language'            => $this->get_page_language( $page_id ),
				'parent_id'           => $post_object->post_parent,
				'seo'                 => array(),
			);
		}

		static $is_applying_the_content_filter = false;

		if ( $is_applying_the_content_filter ) {
			$content = $post_object->post_content;
		} else {
			$is_applying_the_content_filter = true;
			$content                        = $post_object->post_content;

			$ob_level_before = ob_get_level();

			try {
				global $post;
				$original_post = $post;
				$post          = $post_object; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
				setup_postdata( $post );

				ob_start();

				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core filter.
				$content = apply_filters( 'the_content', $post->post_content );

				while ( ob_get_level() > $ob_level_before ) {
					ob_end_clean();
				}
			} catch ( \Throwable $e ) {

				while ( ob_get_level() > $ob_level_before ) {
					ob_end_clean();
				}
				$content = $post_object->post_content;
				$this->logger->warning(
					'apply_filters the_content threw',
					array(
						'page_id' => $page_id,
						'error'   => $e->getMessage(),
					)
				);
			} finally {

				wp_reset_postdata();
				// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
				$post                           = $original_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
				$is_applying_the_content_filter = false;
			}
		}

		$content = SScribe_Helpers::strip_page_builder_attributes( $content );

		$language = $this->get_page_language( $page_id );

		require_once SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-arabic-segmenter.php';

		$word_count   = SScribe_Arabic_Segmenter::count_words( $content, $language );
		$reading_time = SScribe_Arabic_Segmenter::get_reading_time( $content, $language );

		$author = get_the_author_meta( 'display_name', $post_object->post_author );
		if ( empty( $author ) ) {
			$author = __( 'Unknown', 'sscribe-export-site-pages' );
		}

		if ( isset( $this->featured_images_cache[ $page_id ] ) ) {
			$cached_image        = $this->featured_images_cache[ $page_id ];
			$featured_image_id   = $cached_image['id'];
			$featured_image_url  = $cached_image['url'];
			$featured_image_path = $cached_image['path'];
		} else {
			$featured_image_id   = get_post_thumbnail_id( $page_id );
			$featured_image_url  = '';
			$featured_image_path = '';
			if ( $featured_image_id ) {
				$image_src = wp_get_attachment_image_src( $featured_image_id, 'large' );
				if ( $image_src ) {
					$featured_image_url = $image_src[0];
				}
				$featured_image_path = get_attached_file( $featured_image_id );
			}
		}

		$breadcrumbs = $this->get_breadcrumbs( $page_id );

		$post_type_for_children = $post_object->post_type;
		$children               = $this->get_child_pages( $page_id, $post_type_for_children );

		$language = $this->get_page_language( $page_id );

		if ( $this->is_wpml_active() && ! empty( $language ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML hook.
			do_action( 'wpml_switch_language', $language );
			try {
				$permalink = get_permalink( $page_id );
			} finally {
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML hook.
				do_action( 'wpml_switch_language', null );
			}
		} else {
			$permalink = get_permalink( $page_id );
		}

		// For non-published posts, get_permalink() returns a preview URL with ?p=ID.
		// Use a placeholder instead of exposing internal admin URLs in exported documents.
		if ( ! in_array( $post_object->post_status, array( 'publish', 'private' ), true ) ) {
			$permalink = __( '[Draft - Not Published]', 'sscribe-export-site-pages' );
		}

		$filtered = apply_filters(
			'sscribe_page_data',
			array(
				'id'                  => $page_id,
				'title'               => html_entity_decode(
					get_the_title( $page_id ) ? get_the_title( $page_id ) : sprintf( 'Untitled Page %d', $page_id ),
					ENT_QUOTES | ENT_HTML5,
					'UTF-8'
				),
				'content'             => $content,
				'raw_content'         => $post_object->post_content,
				'excerpt'             => $post_object->post_excerpt,
				'permalink'           => $permalink,
				'slug'                => $post_object->post_name,
				'author'              => $author,
				'date_published'      => get_the_date( 'F j, Y', $page_id ),
				'date_modified'       => get_the_modified_date( 'F j, Y', $page_id ),
				'featured_image_url'  => $featured_image_url,
				'featured_image_path' => $featured_image_path,
				'word_count'          => $word_count,
				'reading_time'        => $reading_time,
				'breadcrumbs'         => $breadcrumbs,
				'children'            => $children,
				'language'            => $language,
				'parent_id'           => $post_object->post_parent,
				'seo'                 => $this->seo_reader->get_seo_data( $page_id ),
			),
			$page_id
		);

		return is_array( $filtered ) ? $filtered : false;
	}

	/**
	 * Get child pages for a batch of parent IDs.
	 *
	 * @param array<int> $page_ids  Parent page IDs.
	 * @param string     $post_type Post type.
	 * @return array Child pages grouped by parent.
	 */
	public function get_child_pages_batch( array $page_ids, string $post_type = 'page' ): array {
		if ( empty( $page_ids ) ) {
			return array();
		}

		$page_ids = array_map( 'absint', $page_ids );
		$page_ids = array_filter( $page_ids );

		if ( empty( $page_ids ) ) {
			return array();
		}

		$args = array(
			'post_type'       => $this->resolve_post_type_for_query( $post_type ),
			'post_status'     => 'publish',
			'posts_per_page'  => -1,
			'post_parent__in' => $page_ids,
			'orderby'         => 'menu_order title',
			'order'           => 'ASC',
		);

		$query              = new WP_Query( $args );
		$children_by_parent = array();

		foreach ( $query->posts as $child ) {
			$parent_id = $child->post_parent;
			if ( ! isset( $children_by_parent[ $parent_id ] ) ) {
				$children_by_parent[ $parent_id ] = array();
			}
			$children_by_parent[ $parent_id ][] = array(
				'id'    => $child->ID,
				'title' => html_entity_decode( $child->post_title, ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
				'url'   => get_permalink( $child->ID ),
			);
		}

		foreach ( $children_by_parent as $k => $v ) {
			$this->cache_add( $this->child_pages_cache, $k, $v );
		}

		return $children_by_parent;
	}

	/**
	 * Get child pages for a single parent.
	 *
	 * @param int    $page_id   Parent page ID.
	 * @param string $post_type Post type.
	 * @return array Child pages.
	 */
	private function get_child_pages( int $page_id, string $post_type = 'page' ): array {
		if ( isset( $this->child_pages_cache[ $page_id ] ) ) {
			return $this->child_pages_cache[ $page_id ];
		}

		$children    = array();
		$child_pages = get_children(
			array(
				'post_parent' => $page_id,
				'post_type'   => $post_type,
				'post_status' => 'publish',
				'orderby'     => 'menu_order title',
				'order'       => 'ASC',
			)
		);

		if ( $child_pages ) {
			foreach ( $child_pages as $child ) {
				$children[] = array(
					'id'    => $child->ID,
					'title' => html_entity_decode( $child->post_title, ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
					'url'   => get_permalink( $child->ID ),
				);
			}
		}

		$this->child_pages_cache[ $page_id ] = $children;
		return $children;
	}

	/**
	 * Get the language of a page.
	 *
	 * @param int $page_id Page ID.
	 * @return string Language code.
	 */
	private function get_page_language( int $page_id ): string {
		if ( $this->is_wpml_active() ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML hook.
			$language_details = apply_filters( 'wpml_post_language_details', null, $page_id );
			if ( $language_details && ! is_wp_error( $language_details ) ) {
				$code = isset( $language_details['language_code'] ) ? $language_details['language_code'] : 'en';
				return strtolower( substr( $code, 0, 2 ) );
			}
		}
		$lang = get_bloginfo( 'language' );
		return strtolower( substr( $lang, 0, 2 ) );
	}

	/**
	 * Get breadcrumbs for a page.
	 *
	 * @param int $page_id Page ID.
	 * @return array Breadcrumb items.
	 */
	private function get_breadcrumbs( int $page_id ): array {
		if ( isset( $this->breadcrumb_cache[ $page_id ] ) ) {
			return $this->breadcrumb_cache[ $page_id ];
		}

		$breadcrumbs = array();
		$ancestors   = get_post_ancestors( $page_id );

		// Guard against circular parent relationships by deduplicating ancestor IDs.
		if ( $ancestors ) {
			$seen     = array( $page_id => true );
			$filtered = array();
			foreach ( $ancestors as $ancestor_id ) {
				if ( isset( $seen[ $ancestor_id ] ) ) {
					// Circular reference detected - break the chain.
					break;
				}
				$seen[ $ancestor_id ] = true;
				$filtered[]           = $ancestor_id;
			}
			$ancestors = $filtered;
		}

		if ( $ancestors ) {
			$ancestors = array_reverse( $ancestors );

			$ancestor_posts = get_posts(
				array(
					'post__in'    => $ancestors,
					'post_type'   => get_post_type( $page_id ),
					'post_status' => 'publish',
					'fields'      => 'all',
					'orderby'     => 'post__in',
					'order'       => 'ASC',
				)
			);

			$ancestor_map = array();
			foreach ( $ancestor_posts as $ancestor_post ) {
				$ancestor_map[ $ancestor_post->ID ] = $ancestor_post;
			}

			// Group ancestors by language to minimize WPML language switches.
			$ancestors_by_lang = array();
			foreach ( $ancestors as $ancestor_id ) {
				if ( ! isset( $ancestor_map[ $ancestor_id ] ) ) {
					continue;
				}
				$ancestor_lang = $this->get_page_language( $ancestor_id );
				if ( ! isset( $ancestors_by_lang[ $ancestor_lang ] ) ) {
					$ancestors_by_lang[ $ancestor_lang ] = array();
				}
				$ancestors_by_lang[ $ancestor_lang ][] = $ancestor_id;
			}

			// For each unique language, switch once and fetch all titles/urls.
			foreach ( $ancestors_by_lang as $lang => $lang_ancestors ) {
				$switched = false;
				if ( $this->is_wpml_active() && ! empty( $lang ) ) {
					// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML hook.
					do_action( 'wpml_switch_language', $lang );
					$switched = true;
				}

				try {
					foreach ( $lang_ancestors as $ancestor_id ) {
						$ancestor_post = $ancestor_map[ $ancestor_id ];
						$breadcrumbs[] = array(
							'title' => html_entity_decode( $ancestor_post->post_title, ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
							'url'   => get_permalink( $ancestor_id ),
						);
					}
				} finally {
					if ( $switched ) {
						// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML hook.
						do_action( 'wpml_switch_language', null );
					}
				}
			}
		}

		$page_language = $this->get_page_language( $page_id );
		if ( $this->is_wpml_active() && ! empty( $page_language ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML hook.
			do_action( 'wpml_switch_language', $page_language );
		}
		try {
			$breadcrumbs[] = array(
				'title' => html_entity_decode( get_the_title( $page_id ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
				'url'   => get_permalink( $page_id ),
			);
		} finally {
			if ( $this->is_wpml_active() ) {
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML hook.
				do_action( 'wpml_switch_language', null );
			}
		}

		$this->cache_add( $this->breadcrumb_cache, $page_id, $breadcrumbs );
		return $breadcrumbs;
	}

	/**
	 * Check if WPML is active.
	 *
	 * @return bool
	 */
	public function is_wpml_active(): bool {
		return defined( 'ICL_SITEPRESS_VERSION' ) && class_exists( 'SitePress' );
	}

	/**
	 * Resolve post type for WP_Query.
	 *
	 * @param string $post_type Post type input.
	 * @return string|array
	 */
	private function resolve_post_type_for_query( string $post_type ): string|array {
		if ( 'any' === $post_type ) {
			return array( 'page', 'post' );
		}

		// Validate post_type against allowed list to prevent injection of arbitrary types.
		$allowed_types = array( 'page', 'post' );
		if ( ! in_array( $post_type, $allowed_types, true ) ) {
			return 'page';
		}

		return $post_type;
	}

	/**
	 * Get valid post statuses.
	 *
	 * @return array<string, string>
	 */
	public function get_valid_post_statuses(): array {
		return array(
			'publish' => __( 'Published', 'sscribe-export-site-pages' ),
			'draft'   => __( 'Draft', 'sscribe-export-site-pages' ),
			'private' => __( 'Private', 'sscribe-export-site-pages' ),
			'future'  => __( 'Scheduled', 'sscribe-export-site-pages' ),
			'pending' => __( 'Pending Review', 'sscribe-export-site-pages' ),
		);
	}

	/**
	 * Get post status counts.
	 *
	 * @param string $language  Language code.
	 * @param string $post_type Post type.
	 * @return array Status counts.
	 */
	public function get_post_status_counts( string $language = '', string $post_type = 'page' ): array {

		$cache_key = 'sscribe_status_counts_' . md5( $language . '_' . $post_type );
		$cached    = get_transient( $cache_key );

		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		$statuses = $this->get_valid_post_statuses();
		$counts   = array_fill_keys( array_keys( $statuses ), 0 );

		if ( ! $this->is_wpml_active() && empty( $language ) ) {
			if ( 'any' === $post_type ) {
				$count_page = wp_count_posts( 'page' );
				$count_post = wp_count_posts( 'post' );

				foreach ( $statuses as $status => $label ) {
					$page_count        = isset( $count_page->$status ) ? (int) $count_page->$status : 0;
					$post_count        = isset( $count_post->$status ) ? (int) $count_post->$status : 0;
					$counts[ $status ] = $page_count + $post_count;
				}
			} else {
				$count = wp_count_posts( $post_type );

				if ( $count ) {
					foreach ( $statuses as $status => $label ) {
						if ( isset( $count->$status ) ) {
							$counts[ $status ] = (int) $count->$status;
						}
					}
				}
			}

			$counts['all'] = array_sum( $counts );
			set_transient( $cache_key, $counts, 60 );

			return $counts;
		}

		$args = array(
			'post_type'      => $this->resolve_post_type_for_query( $post_type ),
			'post_status'    => array_keys( $statuses ),
			'posts_per_page' => 1,
			'no_found_rows'  => false,
			'fields'         => 'ids',
		);

		$switched = false;

		try {

			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML hook.
			do_action( 'wpml_switch_language', $language );
			$args['suppress_filters'] = false;
			$switched                 = true;

			// Optimize: Use a single query with GROUP BY instead of one query per status.
			global $wpdb;

			$post_type_placeholders = is_array( $args['post_type'] )
				? '(' . implode( ',', array_fill( 0, count( $args['post_type'] ), '%s' ) ) . ')'
				: '%s';

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Single optimized count query for performance; caching handled by transient below.
			if ( is_array( $args['post_type'] ) ) {
				$post_type_count        = count( $args['post_type'] );
				$status_count           = count( $statuses );
				$post_type_placeholders = implode( ',', array_fill( 0, $post_type_count, '%s' ) );
				$status_placeholders    = implode( ',', array_fill( 0, $status_count, '%s' ) );
				// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Dynamic IN clause placeholders built from safe array_fill() of %s only; query is fully prepared.
				$results = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT post_status, COUNT(*) as count FROM {$wpdb->posts} WHERE post_type IN ({$post_type_placeholders}) AND post_status IN ({$status_placeholders}) GROUP BY post_status",
						array_merge( $args['post_type'], array_keys( $statuses ) )
					),
					ARRAY_A
				);
				// phpcs:enable
			} else {
				$status_count        = count( $statuses );
				$status_placeholders = implode( ',', array_fill( 0, $status_count, '%s' ) );
				// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Dynamic IN clause placeholders built from safe array_fill() of %s only; query is fully prepared.
				$results = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT post_status, COUNT(*) as count FROM {$wpdb->posts} WHERE post_type = %s AND post_status IN ({$status_placeholders}) GROUP BY post_status",
						array_merge( array( $args['post_type'] ), array_keys( $statuses ) )
					),
					ARRAY_A
				);
				// phpcs:enable
			}
			// phpcs:enable

			// Initialize all counts to 0.
			foreach ( $statuses as $status => $label ) {
				$counts[ $status ] = 0;
			}

			// Map SQL results to counts array.
			if ( is_array( $results ) ) {
				foreach ( $results as $row ) {
					$status = $row['post_status'];
					if ( isset( $counts[ $status ] ) ) {
						$counts[ $status ] = (int) $row['count'];
					}
				}
			}
		} finally {
			if ( $switched ) {
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML hook.
				do_action( 'wpml_switch_language', null );
			}
		}

		$counts['all'] = array_sum( $counts );

		set_transient( $cache_key, $counts, 60 );

		return $counts;
	}

	/**
	 * Get total count across all statuses.
	 *
	 * @param string $language  Language code.
	 * @param string $post_type Post type.
	 * @return int
	 */
	public function get_total_all_statuses( string $language = '', string $post_type = 'page' ): int {
		return $this->get_page_count_only( $language, 'all', $post_type );
	}

	/**
	 * Validate a post status value.
	 *
	 * @param string $status Status to validate.
	 * @return string Validated status.
	 */
	public function validate_post_status( string $status ): string {
		// Sanitize the input to prevent injection and handle whitespace issues.
		$status = sanitize_text_field( $status );
		$valid  = array_keys( $this->get_valid_post_statuses() );

		if ( 'all' === $status ) {
			return 'any';
		}

		if ( in_array( $status, $valid, true ) ) {
			return $status;
		}

		return 'publish';
	}

	/**
	 * Get WPML active languages.
	 *
	 * @return array Language data.
	 */
	public function get_wpml_languages(): array {
		if ( ! $this->is_wpml_active() ) {
			return array();
		}

		$cache_key = 'sscribe_wpml_languages';
		$cached    = get_transient( $cache_key );

		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML-documented hook.
		$languages_raw = apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) );

		if ( empty( $languages_raw ) && function_exists( 'wpml_get_active_languages' ) ) {
			$languages_raw = wpml_get_active_languages( '' );
		}

		if ( ! is_array( $languages_raw ) ) {
			return array();
		}

		$result = array();
		foreach ( $languages_raw as $lang ) {
			$result[] = array(
				'code'        => $lang['language_code'],
				'name'        => $lang['translated_name'],
				'native_name' => $lang['native_name'],
				'flag_url'    => isset( $lang['country_flag_url'] ) ? $lang['country_flag_url'] : '',
			);
		}

		set_transient( $cache_key, $result, 5 * MINUTE_IN_SECONDS );

		return $result;
	}
}
