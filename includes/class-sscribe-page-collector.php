<?php
/**
 * SScribe Page Collector
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SScribe_Page_Collector {

	private array $featured_images_cache = array();

	private array $child_pages_cache = array();

	private array $breadcrumb_cache = array();

	public function clear_page_caches(): void {
		$this->featured_images_cache = array();
		$this->child_pages_cache     = array();
		$this->breadcrumb_cache      = array();
	}

	private readonly SScribe_SEO_Reader $seo_reader;

	private readonly SScribe_Logger_Interface $logger;

	public function __construct() {
		$this->seo_reader = new SScribe_SEO_Reader();
		$this->logger     = SScribe_Logger::instance( defined( 'SSCRIBE_DEBUG' ) && SSCRIBE_DEBUG );
	}

	public function get_page_ids( string $language = '', string $post_status = 'publish', string $post_type = 'page' ): array {

		$filter_value = apply_filters( 'sscribe_use_chunked_page_ids', null );
		if ( null !== $filter_value && false === $filter_value ) {

			return $this->get_page_ids_direct( $language, $post_status, $post_type );
		}

		$estimated_count = $this->estimate_page_count( $language, $post_status, $post_type );
		$use_chunked     = $estimated_count > 500;

		if ( $filter_value || $use_chunked ) {
			$all_ids = array();
			foreach ( $this->get_page_ids_chunked( $language, $post_status, $post_type, 500 ) as $chunk ) {
				$all_ids = array_merge( $all_ids, $chunk );
			}
			return $all_ids;
		}

		return $this->get_page_ids_direct( $language, $post_status, $post_type );
	}

	private function get_page_ids_direct( string $language, string $post_status, string $post_type ): array {
		$post_status = $this->validate_post_status( $post_status );

		$args = array(
			'post_type'      => $this->resolve_post_type_for_query( $post_type ),
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

		return $page_ids;
	}

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

	private function clear_status_cache( string $language = '' ): void {
		$cache_key = 'sscribe_status_counts_' . md5( $language );
		delete_transient( $cache_key );

		foreach ( array( 'publish', 'draft', 'private', 'future', 'pending', 'all' ) as $status ) {
			$key = 'sscribe_page_count_' . md5( $language . '_' . $status );
			delete_transient( $key );
		}
	}

	private function debug_log( string $message, array $data = array() ): void {
		$this->logger->debug( $message, $data );
	}

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
		$results = $wpdb->get_results( $wpdb->prepare( $sql, ...$page_ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Placeholders safely generated

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
			$attachments = $wpdb->get_results( $wpdb->prepare( $sql, ...$thumbnail_ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Placeholders safely generated

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

	private function get_upload_base_dir(): string {
		$upload_dir = wp_upload_dir();
		return $upload_dir['basedir'];
	}

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

	public function get_total_pages( string $language = '' ): int {
		return $this->get_page_count_only( $language );
	}

	public function get_page_data( int $page_id ): array|false {
		$page_id = absint( $page_id );
		if ( $page_id <= 0 ) {
			return false;
		}

		$post_object = get_post( $page_id );
		if ( ! $post_object || ! in_array( $post_object->post_type, array( 'page', 'post' ), true ) ) {
			return false;
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
			$permalink = get_permalink( $page_id );
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML hook.

			do_action( 'wpml_switch_language', null );
		} else {
			$permalink = get_permalink( $page_id );
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

		$this->child_pages_cache = array_merge( $this->child_pages_cache, $children_by_parent );

		return $children_by_parent;
	}

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

	private function get_breadcrumbs( int $page_id ): array {
		if ( isset( $this->breadcrumb_cache[ $page_id ] ) ) {
			return $this->breadcrumb_cache[ $page_id ];
		}

		$breadcrumbs = array();
		$ancestors   = get_post_ancestors( $page_id );

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

			foreach ( $ancestors as $ancestor_id ) {
				if ( ! isset( $ancestor_map[ $ancestor_id ] ) ) {
					continue;
				}

				$ancestor_lang = $this->get_page_language( $ancestor_id );
				if ( $this->is_wpml_active() && ! empty( $ancestor_lang ) ) {
					// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML hook.

					do_action( 'wpml_switch_language', $ancestor_lang );
				}

				$ancestor_post = $ancestor_map[ $ancestor_id ];
				$breadcrumbs[] = array(
					'title' => html_entity_decode( $ancestor_post->post_title, ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
					'url'   => get_permalink( $ancestor_id ),
				);

				if ( $this->is_wpml_active() ) {
					// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML hook.

					do_action( 'wpml_switch_language', null );
				}
			}
		}

		$page_language = $this->get_page_language( $page_id );
		if ( $this->is_wpml_active() && ! empty( $page_language ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML hook.

			do_action( 'wpml_switch_language', $page_language );
		}
		$breadcrumbs[] = array(
			'title' => html_entity_decode( get_the_title( $page_id ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
			'url'   => get_permalink( $page_id ),
		);
		if ( $this->is_wpml_active() ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML hook.

			do_action( 'wpml_switch_language', null );
		}

		$this->breadcrumb_cache[ $page_id ] = $breadcrumbs;
		return $breadcrumbs;
	}

	public function is_wpml_active(): bool {
		return defined( 'ICL_SITEPRESS_VERSION' ) && class_exists( 'SitePress' );
	}

	private function resolve_post_type_for_query( string $post_type ): string|array {
		if ( 'any' === $post_type ) {
			return array( 'page', 'post' );
		}
		return $post_type;
	}

	public function get_valid_post_statuses(): array {
		return array(
			'publish' => __( 'Published', 'sscribe-export-site-pages' ),
			'draft'   => __( 'Draft', 'sscribe-export-site-pages' ),
			'private' => __( 'Private', 'sscribe-export-site-pages' ),
			'future'  => __( 'Scheduled', 'sscribe-export-site-pages' ),
			'pending' => __( 'Pending Review', 'sscribe-export-site-pages' ),
		);
	}

	public function get_post_status_counts( string $language = '', string $post_type = 'page' ): array {

		$cache_key = 'sscribe_status_counts_' . md5( $language . '_' . $post_type );
		$cached    = get_transient( $cache_key );

		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		$statuses = $this->get_valid_post_statuses();
		$counts   = array_fill_keys( array_keys( $statuses ), 0 );

		if ( ! $this->is_wpml_active() || empty( $language ) ) {
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

			foreach ( $statuses as $status => $label ) {
				$args['post_status'] = $status;
				$query               = new WP_Query( $args );
				$counts[ $status ]   = (int) $query->found_posts;
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

	public function get_total_all_statuses( string $language = '', string $post_type = 'page' ): int {
		return $this->get_page_count_only( $language, 'all', $post_type );
	}

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
