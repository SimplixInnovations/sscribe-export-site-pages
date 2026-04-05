<?php
/**
 * Collects page data for export.
 *
 * @package SScribe
 */

declare(strict_types=1);

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SScribe_Page_Collector
 *
 * Queries and collects all published pages with metadata,
 * supporting WPML language filtering.
 */
class SScribe_Page_Collector {

	/**
	 * Pre-fetched featured images cache.
	 *
	 * @var array
	 */
	private array $featured_images_cache = array();

	/**
	 * Pre-fetched child pages cache.
	 *
	 * @var array
	 */
	private array $child_pages_cache = array();

	/**
	 * Pre-fetched breadcrumbs cache.
	 *
	 * @var array
	 */
	private array $breadcrumb_cache = array();

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
	 * Constructor.
	 */
	public function __construct() {
		$this->seo_reader = new SScribe_SEO_Reader();
		$this->logger     = SScribe_Logger::instance( defined( 'SSCRIBE_DEBUG' ) && SSCRIBE_DEBUG );
	}

	/**
	 * Get all published page IDs, optionally filtered by language and status.
	 *
	 * @param string $language    Optional WPML language code (e.g., 'en', 'ar'). Empty = all languages.
	 * @param string $post_status Optional post status (publish, draft, private, future, pending, all).
	 * @return array Array of page IDs.
	 */
	public function get_page_ids( string $language = '', string $post_status = 'publish' ): array {
		$post_status = $this->validate_post_status( $post_status );

		$args = array(
			'post_type'      => 'page',
			'post_status'    => $post_status,
			'posts_per_page' => -1,
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

				$this->clear_status_cache( $language );

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

		return $page_ids;
	}

	/**
	 * Get page IDs in chunks for memory-efficient processing.
	 *
	 * Returns a generator that yields batches of page IDs,
	 * reducing memory usage for sites with thousands of pages.
	 *
	 * @param string $language    Optional WPML language code.
	 * @param string $post_status Optional post status.
	 * @param int    $chunk_size  Number of IDs per chunk.
	 * @return \Generator Yields arrays of page IDs.
	 */
	public function get_page_ids_chunked( string $language = '', string $post_status = 'publish', int $chunk_size = 100 ): \Generator {
		$post_status = $this->validate_post_status( $post_status );
		$chunk_size  = (int) apply_filters( 'sscribe_page_ids_chunk_size', $chunk_size );

		$page = 1;

		do {
			$args = array(
				'post_type'      => 'page',
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
	 * Clear status count cache for a specific language.
	 *
	 * @param string $language Language code.
	 */
	private function clear_status_cache( string $language = '' ): void {
		$cache_key = 'sscribe_status_counts_' . md5( $language );
		delete_transient( $cache_key );

		foreach ( array( 'publish', 'draft', 'private', 'future', 'pending', 'all' ) as $status ) {
			$key = 'sscribe_page_count_' . md5( $language . '_' . $status );
			delete_transient( $key );
		}
	}

	/**
	 * Write debug log entry.
	 *
	 * @param string $message Log message.
	 * @param array  $data    Optional data to include.
	 */
	private function debug_log( string $message, array $data = array() ): void {
		$this->logger->debug( $message, $data );
	}

	/**
	 * Batch fetch featured images for multiple page IDs.
	 *
	 * Reduces N+1 queries by fetching all featured images in 2 queries
	 * instead of 3 queries per page.
	 *
	 * @param array $page_ids Array of page IDs.
	 * @return array Associative array: page_id => ['id' => int, 'url' => string, 'path' => string]
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

		$placeholders = implode( ',', array_fill( 0, count( $page_ids ), '%d' ) );
		$sql          = "SELECT post_id, meta_value AS thumbnail_id FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND post_id IN ({$placeholders})";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Placeholders are safely generated; batch operation for featured images; caching not needed for one-time batch export.
		$results = $wpdb->get_results( $wpdb->prepare( $sql, $page_ids ) );

		if ( $wpdb->last_error ) {
			$this->debug_log(
				'Database query failed for featured images',
				array(
					'error' => $wpdb->last_error,
					'query' => $wpdb->last_query,
				)
			);
			return array();
		}

		$thumbnail_ids = array();
		$page_to_thumb = array();

		foreach ( $results as $row ) {
			$thumb_id                             = (int) $row->thumbnail_id;
			$thumbnail_ids[]                      = $thumb_id;
			$page_to_thumb[ (int) $row->post_id ] = $thumb_id;
		}

		$attachment_data = array();
		if ( ! empty( $thumbnail_ids ) ) {
			$thumb_placeholders = implode( ',', array_fill( 0, count( $thumbnail_ids ), '%d' ) );
			$sql                = "SELECT p.ID, p.guid, pm_path.meta_value AS filepath FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} pm_path ON p.ID = pm_path.post_id AND pm_path.meta_key = '_wp_attached_file' WHERE p.ID IN ({$thumb_placeholders})";
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Placeholders are safely generated; batch operation for attachment paths; caching not needed for one-time batch export.
			$attachments = $wpdb->get_results( $wpdb->prepare( $sql, $thumbnail_ids ) );

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

		$this->featured_images_cache = array_merge( $this->featured_images_cache, $featured_images );

		return $featured_images;
	}

	/**
	 * Get upload base directory.
	 *
	 * @return string
	 */
	private function get_upload_base_dir(): string {
		$upload_dir = wp_upload_dir();
		return $upload_dir['basedir'];
	}

	/**
	 * Get page count efficiently without loading all IDs.
	 * Uses WP_Query's found_posts with posts_per_page=1 to avoid
	 * loading the full ID set just for counting.
	 *
	 * @param string $language    Optional WPML language code. Empty = all languages.
	 * @param string $post_status Optional post status (publish, draft, private, future, pending, all).
	 * @return int
	 */
	public function get_page_count_only( string $language = '', string $post_status = 'publish' ): int {
		$post_status = $this->validate_post_status( $post_status );

		$args = array(
			'post_type'      => 'page',
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
	 * Get total number of pages for a given language.
	 *
	 * @param string $language Optional WPML language code.
	 * @return int Total number of pages.
	 */
	public function get_total_pages( string $language = '' ): int {
		return $this->get_page_count_only( $language );
	}

	/**
	 * Collect full data for a single page.
	 *
	 * @param int $page_id The page ID.
	 * @return array|false Page data array or false on failure.
	 */
	public function get_page_data( int $page_id ): array|false {
		$page_id = absint( $page_id );
		if ( $page_id <= 0 ) {
			return false;
		}

		$post_object = get_post( $page_id );
		if ( ! $post_object || 'page' !== $post_object->post_type ) {
			return false;
		}

		// Guard against recursive calls from plugins that hook the_content.
		static $sscribe_in_content_filter = false;

		if ( $sscribe_in_content_filter ) {
			$content = $post_object->post_content;
		} else {
			$sscribe_in_content_filter = true;
			$content                   = $post_object->post_content;

			// Record ob level before our buffer to avoid closing WP's buffers.
			$ob_level_before = ob_get_level();

			// Defensive initialization for the finally block.
			$original_post = null;

			try {
				global $post;
				$original_post = $post;
				// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
				$post = $post_object;
				setup_postdata( $post );

				// Buffer stray output from page builders (Elementor, Divi, etc.)
				// that may echo HTML during apply_filters('the_content') in AJAX context.
				ob_start();

				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core filter.
				$content = apply_filters( 'the_content', $post->post_content );

				// Discard any stray HTML output. We only want the return value.
				// Restore to exactly the level before our ob_start().
				while ( ob_get_level() > $ob_level_before ) {
					ob_end_clean();
				}
			} catch ( \Throwable $e ) {
				// Restore buffers to pre-call state before doing anything else.
				while ( ob_get_level() > $ob_level_before ) {
					ob_end_clean();
				}
				$content = $post_object->post_content;
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					error_log( 'SScribe: apply_filters the_content threw for page ' . $page_id . ': ' . $e->getMessage() );
				}
			} finally {
				// ALWAYS restore: reset post data and re-entry guard.
				// Buffer restoration already handled above (try success or catch).
				// This finally only handles state that must ALWAYS reset.
				wp_reset_postdata();
				// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
				if ( null !== $original_post ) {
					// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
					$post = $original_post;
				}
				$sscribe_in_content_filter = false;
			}
		}

		// Optional: strip inline styles and page builder classes for a cleaner export,
		// ensuring exports (like HTML, MD, PDF) are perfectly legible and well-structured.
		$content = preg_replace( '/\s*style="[^"]*"/i', '', $content );
		$content = preg_replace( "/\s*style='[^']*'/i", '', $content );
		$content = preg_replace( '/<style[^>]*>.*?<\/style>/is', '', $content );
		$content = preg_replace( '/\s*class="[^"]*"/i', '', $content );
		$content = preg_replace( "/\s*class='[^']*'/i", '', $content );
		$content = preg_replace( '/\s*data-elementor(-[a-z]+)?="[^"]*"/i', '', $content );
		// SECURITY FIX: Limit attribute name to valid chars and max 30 length to prevent ReDoS.
		// Previously used [^=]* which caused catastrophic backtracking on malformed input.
		$content = preg_replace( '/\s*data-(widget|column|section)-[a-z0-9_-]{0,30}="[^"]*"/i', '', $content );
		$content = preg_replace( '/\s*id="elementor-[^"]*"/i', '', $content );

		// Calculate word count and reading time with Unicode fallback.
		$stripped   = wp_strip_all_tags( $content );
		$word_count = str_word_count( $stripped );

		// str_word_count() is not Unicode-aware — it returns 0 for Arabic, CJK, etc.
		// Use character count fallback for non-Latin scripts.
		if ( 0 === $word_count && mb_strlen( $stripped ) > 0 ) {
			// Estimate: average word length is ~5 chars for CJK, ~4.5 for Arabic.
			$word_count = (int) ceil( mb_strlen( $stripped, 'UTF-8' ) / 5 );
		}

		// Reading speed: 200 wpm Latin, 300 chars/min CJK (approx ~250 wpm Arabic).
		$reading_time = max( 1, (int) ceil( $word_count / 200 ) );

		// Get author.
		$author = get_the_author_meta( 'display_name', $post_object->post_author );

		// Get featured image - use cached batch data if available.
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

		// Get breadcrumbs.
		$breadcrumbs = $this->get_breadcrumbs( $page_id );

		// Get child pages.
		$children = $this->get_child_pages( $page_id );

		// Get language.
		$language = $this->get_page_language( $page_id );

		// Get permalink.
		$permalink = get_permalink( $page_id );

		/**
		 * Filter the page data array before DOCX generation.
		 *
		 * Allows third-party plugins to add custom fields,
		 * modify content, or enrich the data passed to the exporter.
		 *
		 * @param array $data    The page data.
		 * @param int   $page_id The page ID.
		 */
		return apply_filters(
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
	}

	/**
	 * Get breadcrumb trail for a page.
	 *
	 * @param int $page_id The page ID.
	 * @return array Array of breadcrumb items with title and url.
	 */
	private function get_breadcrumbs( int $page_id ): array {
		if ( isset( $this->breadcrumb_cache[ $page_id ] ) ) {
			return $this->breadcrumb_cache[ $page_id ];
		}

		$breadcrumbs = array();
		$ancestors   = get_post_ancestors( $page_id );
		$ancestors   = array_reverse( $ancestors );

		// Add home.
		$breadcrumbs[] = array(
			'title' => __( 'Home', 'sscribe-export-site-pages' ),
			'url'   => home_url( '/' ),
		);

		// Add ancestors.
		foreach ( $ancestors as $ancestor_id ) {
			$breadcrumbs[] = array(
				'title' => html_entity_decode( get_the_title( $ancestor_id ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
				'url'   => get_permalink( $ancestor_id ),
			);
		}

		// Add current page.
		$breadcrumbs[] = array(
			'title' => html_entity_decode( get_the_title( $page_id ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
			'url'   => get_permalink( $page_id ),
		);

		$this->breadcrumb_cache[ $page_id ] = $breadcrumbs;
		return $breadcrumbs;
	}

	/**
	 * Batch fetch child pages for multiple parent IDs.
	 *
	 * Reduces N+1 queries by fetching all children in a single query.
	 *
	 * @param array $page_ids Array of parent page IDs.
	 * @return array Associative array: parent_id => array of child data.
	 */
	public function get_child_pages_batch( array $page_ids ): array {
		if ( empty( $page_ids ) ) {
			return array();
		}

		$page_ids = array_map( 'absint', $page_ids );
		$page_ids = array_filter( $page_ids );

		if ( empty( $page_ids ) ) {
			return array();
		}

		$args = array(
			'post_type'       => 'page',
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

		$this->child_pages_cache = array_merge( $this->child_pages_cache, $children_by_parent );

		return $children_by_parent;
	}

	/**
	 * Get child pages of a given page.
	 *
	 * @param int $page_id The parent page ID.
	 * @return array Array of child page data (id, title, url).
	 */
	private function get_child_pages( int $page_id ): array {
		if ( isset( $this->child_pages_cache[ $page_id ] ) ) {
			return $this->child_pages_cache[ $page_id ];
		}

		$children    = array();
		$child_pages = get_children(
			array(
				'post_parent' => $page_id,
				'post_type'   => 'page',
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
	 * Get the language of a page (WPML).
	 *
	 * @param int $page_id The page ID.
	 * @return string Language code or 'en' default.
	 */
	private function get_page_language( int $page_id ): string {
		if ( $this->is_wpml_active() ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML hook.
			$language_details = apply_filters( 'wpml_post_language_details', null, $page_id );
			if ( $language_details && ! is_wp_error( $language_details ) ) {
				return isset( $language_details['language_code'] ) ? $language_details['language_code'] : 'en';
			}
		}
		return get_bloginfo( 'language' );
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
	 * Get list of valid post statuses for export.
	 *
	 * @return array Associative array of status => label pairs.
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
	 * Get page counts by status for the admin UI.
	 *
	 * Uses wp_count_posts() for non-WPML case (most efficient).
	 * Falls back to WP_Query for WPML since language filtering is needed.
	 *
	 * @param string $language Optional WPML language code. Empty = all languages.
	 * @return array Associative array of status => count pairs.
	 */
	public function get_post_status_counts( string $language = '' ): array {
		// Tier 2: Cache status counts for 60 seconds to avoid repeating the full
		// WP_Query + post status iteration on every admin page load.
		$cache_key = 'sscribe_status_counts_' . md5( $language );
		$cached    = get_transient( $cache_key );

		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		$statuses = $this->get_valid_post_statuses();
		$counts   = array_fill_keys( array_keys( $statuses ), 0 );

		// For non-WPML, use wp_count_posts() which is highly optimized (single cached query).
		if ( ! $this->is_wpml_active() || empty( $language ) ) {
			$count = wp_count_posts( 'page' );

			if ( $count ) {
				foreach ( $statuses as $status => $label ) {
					if ( isset( $count->$status ) ) {
						$counts[ $status ] = (int) $count->$status;
					}
				}
			}

			$counts['all'] = array_sum( $counts );
			set_transient( $cache_key, $counts, 60 );

			return $counts;
		}

		// WPML case: need to filter by language, so use WP_Query.
		// Note: We already know WPML is active and language is non-empty due to early return above.
		$args = array(
			'post_type'      => 'page',
			'post_status'    => array_keys( $statuses ),
			'posts_per_page' => -1,
			'no_found_rows'  => true,
		);

		$switched = false;

		try {
			// Switch to the requested language.
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML hook.
			do_action( 'wpml_switch_language', $language );
			$args['suppress_filters'] = false;
			$switched                 = true;

			// Query outside try-catch - always executes regardless of exception.
			$query = new WP_Query( $args );

			// Count by status directly from query posts (no N+1 - use post objects already loaded).
			foreach ( $query->posts as $post ) {
				if ( $post && isset( $counts[ $post->post_status ] ) ) {
					++$counts[ $post->post_status ];
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
	 * Get total page count for all statuses combined.
	 *
	 * @param string $language Optional WPML language code.
	 * @return int Total number of pages across all statuses.
	 */
	public function get_total_all_statuses( string $language = '' ): int {
		return $this->get_page_count_only( $language, 'all' );
	}

	/**
	 * Validate and sanitize a post status value.
	 *
	 * @param string $status The status to validate.
	 * @return string Valid status value (defaults to 'publish' if invalid).
	 */
	public function validate_post_status( string $status ): string {
		$valid = array_keys( $this->get_valid_post_statuses() );

		if ( 'all' === $status ) {
			return 'any';
		}

		if ( in_array( $status, $valid, true ) ) {
			return $status;
		}

		return 'publish';
	}

	/**
	 * Get all active WPML languages.
	 *
	 * @return array Array of language data or empty array if WPML not active.
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

		// Use wpml_get_active_languages function if available (WPML 3.2+).
		// This avoids calling apply_filters() with a non-prefixed hook name
		// directly, which triggers WordPress Plugin Check warnings.
		if ( function_exists( 'wpml_get_active_languages' ) ) {
			$languages_raw = wpml_get_active_languages( '' );
		} else {
			// Fallback for older WPML: use the documented filter API.
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML-documented hook.
			$languages_raw = apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) );
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
