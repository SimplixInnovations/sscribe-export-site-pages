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
	 * Cached permalinks by page ID and active WPML language.
	 *
	 * `get_permalink()` is non-trivial: it loads the post, runs
	 * apply_filters( 'post_link', ... ) (Yoast/RankMath/Polylang all
	 * hook here), and resolves the rewrite rule. Within one export
	 * the same set of pages is referenced multiple times (page data,
	 * breadcrumbs, child lists) : memoizing the result per request
	 * trims several filter chains per page.
	 *
	 * @var array<string,string>
	 */
	private array $permalink_cache = array();

	/**
	 * Memoized get_the_title() calls per request.
	 *
	 * The underlying get_the_title() runs through the `the_title` filter
	 * chain on every call, which is expensive in batch exports where the
	 * same page is referenced many times (page data, breadcrumbs, child
	 * lists, etc.).
	 *
	 * @var array<int,string>
	 */
	private array $title_cache = array();

	/**
	 * Clear all page caches.
	 *
	 * @return void
	 */
	public function clear_page_caches(): void {
		$this->featured_images_cache = array();
		$this->child_pages_cache     = array();
		$this->breadcrumb_cache      = array();
		$this->permalink_cache       = array();
		$this->title_cache           = array();
	}

	/**
	 * Read the current content cache generation so transient keys can be
	 * namespaced by it. Bumping the generation on post mutation
	 * (save_post, trashed_post, deleted_post, untrashed_post) automatically
	 * invalidates every key derived from content without enumerating them.
	 *
	 * @return int Current generation.
	 */
	public function get_content_cache_generation(): int {
		if ( function_exists( 'get_option' ) ) {
			return max( 1, (int) get_option( 'sscribe_content_cache_generation', 1 ) );
		}
		return 1;
	}

	/**
	 * Add an entry to a cache with LRU eviction when max size is exceeded.
	 *
	 * @param array      &$cache Cache reference.
	 * @param int|string $key    Cache key.
	 * @param mixed      $value  Cache value.
	 * @return void
	 */
	private function cache_add( array &$cache, int|string $key, mixed $value ): void {
		if ( count( $cache ) >= self::CACHE_MAX_SIZE ) {

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

		$generation = $this->get_content_cache_generation();
		$cache_key  = 'sscribe_page_ids_v3_' . $post_status . '_' . md5( "{$language}_{$post_type}_{$limit}" );
		$cached     = get_transient( $cache_key );
		if (
			is_array( $cached )
			&& isset( $cached['generation'], $cached['ids'] )
			&& (int) $cached['generation'] === $generation
			&& is_array( $cached['ids'] )
		) {
			return $this->filter_readable_page_ids( $cached['ids'] );
		}

		$effective_limit = $limit > 0 ? min( $limit, 10000 ) : 10000;

		$args = array(
			'post_type'      => $this->resolve_post_type_for_query( $post_type ),
			'post_status'    => $post_status,
			'posts_per_page' => $effective_limit,
			'fields'         => 'ids',
			'orderby'        => array(
				'menu_order' => 'ASC',
				'title'      => 'ASC',
				'ID'         => 'ASC',
			),
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

		set_transient( $cache_key, array( 'generation' => $generation, 'ids' => $page_ids ), 5 * MINUTE_IN_SECONDS );

		return $this->filter_readable_page_ids( $page_ids );
	}

	/**
	 * Keep only posts the current user may read through WordPress's canonical
	 * per-post capability mapping.
	 *
	 * The export capability controls access to SScribe itself; it must never
	 * bypass a post type's own read/private/read_others permissions. Raw query
	 * results may be shared through the short-lived ID cache, so filtering is
	 * deliberately applied after cache retrieval on every request.
	 *
	 * @param array<int|string> $page_ids Candidate post IDs.
	 * @return array<int> Readable post IDs.
	 */
	private function filter_readable_page_ids( array $page_ids ): array {
		$page_ids = array_values(
			array_unique(
				array_filter(
					array_map( 'absint', $page_ids )
				)
			)
		);
		if ( empty( $page_ids ) ) {
			return array();
		}

		$this->prime_readability_post_cache( $page_ids );
		$readable = array();
		foreach ( $page_ids as $page_id ) {
			if ( $this->is_post_readable_for_export( $page_id ) ) {
				$readable[] = $page_id;
			}
		}

		return $readable;
	}

	/**
	 * Prime post objects before per-ID capability checks so an ID-only query or
	 * transient hit cannot degrade into one SELECT per post.
	 *
	 * @param int[] $page_ids Post IDs.
	 */
	private function prime_readability_post_cache( array $page_ids ): void {
		foreach ( array_chunk( $page_ids, 500 ) as $chunk ) {
			if ( function_exists( '_prime_post_caches' ) ) {
				_prime_post_caches( $chunk, false, false );
				continue;
			}
			get_posts(
				array(
					'post__in'               => $chunk,
					'post_type'              => 'any',
					'post_status'            => 'any',
					'numberposts'            => count( $chunk ),
					'orderby'                => 'post__in',
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
					'suppress_filters'       => true,
				)
			);
		}
	}

	/**
	 * Decide whether one post may be exposed through an export.
	 *
	 * Published content on selectable public post types is public by
	 * definition and does not require a logged-in WordPress capability check.
	 * Every non-public status is delegated to WordPress's canonical read_post
	 * meta-capability so private, draft, pending, and scheduled content keeps
	 * the post type's native ownership/read-private policy.
	 *
	 * @param int $page_id Post ID.
	 * @return bool Whether the current request may read the post.
	 */
	private function is_post_readable_for_export( int $page_id ): bool {
		$post = get_post( $page_id );
		if ( ! $post instanceof WP_Post ) {
			return false;
		}

		if (
			'publish' === (string) $post->post_status
			&& in_array( (string) $post->post_type, $this->get_selectable_post_types(), true )
			&& $this->is_post_type_public( (string) $post->post_type )
		) {
			return true;
		}

		return current_user_can( 'read_post', $page_id );
	}

	/**
	 * Determine whether a registered post type is publicly readable.
	 *
	 * The selectable-post-type filter may intentionally add non-public types.
	 * Such types stay selectable for authorized workflows, but they must never
	 * inherit the published-content public shortcut.
	 *
	 * @param string $post_type Post type name.
	 * @return bool Whether the registered type is public.
	 */
	private function is_post_type_public( string $post_type ): bool {
		$post_type_object = get_post_type_object( $post_type );
		return is_object( $post_type_object ) && ! empty( $post_type_object->public );
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

		$generation  = $this->get_content_cache_generation();
		$cache_key   = 'sscribe_estimate_count_v2_' . md5( $language . '|' . $post_status . '|' . ( is_array( $post_types ) ? implode( ',', $post_types ) : (string) $post_types ) );
		$cached      = function_exists( 'get_transient' ) ? get_transient( $cache_key ) : false;
		if (
			is_array( $cached )
			&& isset( $cached['generation'], $cached['count'] )
			&& (int) $cached['generation'] === $generation
			&& is_numeric( $cached['count'] )
		) {
			return max( 0, (int) $cached['count'] );
		}

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

		if ( function_exists( 'set_transient' ) ) {
			set_transient( $cache_key, array( 'generation' => $generation, 'count' => $count ), MINUTE_IN_SECONDS );
		}

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
		$chunk_size  = max( 1, min( 1000, (int) apply_filters( 'sscribe_page_ids_chunk_size', $chunk_size ) ) );

		$page = 1;

		do {
			$args = array(
				'post_type'      => $this->resolve_post_type_for_query( $post_type ),
				'post_status'    => $post_status,
				'posts_per_page' => $chunk_size,
				'paged'          => $page,
				'fields'         => 'ids',
				// Skip the SQL_CALC_FOUND_ROWS pass: chunked iteration
				// never needs a total count, and the calc on paginated
				// post queries adds an unindexed scan for zero benefit.
				// With no_found_rows enabled, $query->max_num_pages is 0,
				// so the outer loop drives its exit on the actual post
				// count returned: if the chunk is shorter than the
				// requested page size, we reached the end of the result set.
				'no_found_rows'  => true,
				'orderby'        => array(
					'menu_order' => 'ASC',
					'title'      => 'ASC',
					'ID'         => 'ASC',
				),
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

			$fetched = count( $query->posts );

			if ( $fetched > 0 ) {
				$readable_ids = $this->filter_readable_page_ids( $query->posts );
				if ( ! empty( $readable_ids ) ) {
					yield $readable_ids;
				}
			}

			++$page;
		} while ( $fetched >= $chunk_size );
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
	 * Prime the SEO postmeta cache for a batch of page IDs.
	 *
	 * Pass-through to SScribe_SEO_Reader::prime_meta_cache() so the
	 * batch processor can warm the cache through the same collector
	 * collaborator that already owns get_featured_images_batch() and
	 * get_child_pages_batch().
	 *
	 * @param int[] $page_ids Page IDs in the current batch.
	 * @return void
	 */
	public function prime_seo_meta_cache( array $page_ids ): void {
		$this->seo_reader->prime_meta_cache( $page_ids );
	}

	/**
	 * Get featured images for a batch of page IDs.
	 *
	 * Invalid entries are ignored so extension code cannot accidentally turn a
	 * malformed ID into post ID 1 through PHP's array-to-integer conversion.
	 *
	 * @param array<mixed> $page_ids Page IDs.
	 * @return array Featured image data.
	 */
	public function get_featured_images_batch( array $page_ids ): array {
		if ( empty( $page_ids ) ) {
			return array();
		}

		$page_ids = array_map(
			static fn( $id ): int => is_scalar( $id ) && ! is_bool( $id ) ? absint( $id ) : 0,
			$page_ids
		);
		$page_ids = array_filter( $page_ids );

		if ( empty( $page_ids ) ) {
			return array();
		}

		global $wpdb;

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

		if ( 'all' === $post_status ) {
			$counts = $this->get_post_status_counts( $language, $post_type );
			return (int) ( $counts['all'] ?? 0 );
		}

		// The found_posts fast path is safe only when every resolved post type
		// is actually registered public=true. The filter may add non-public CPTs;
		// those counts remain permission-sensitive and must use readable IDs.
		if ( 'publish' === $post_status ) {
			$resolved_post_types = $this->resolve_post_type_for_query( $post_type );
			$post_types_to_check = is_array( $resolved_post_types ) ? $resolved_post_types : array( $resolved_post_types );
			$all_public          = ! empty( $post_types_to_check );
			foreach ( $post_types_to_check as $resolved_post_type ) {
				if ( ! $this->is_post_type_public( (string) $resolved_post_type ) ) {
					$all_public = false;
					break;
				}
			}
			if ( ! $all_public ) {
				return count( $this->get_page_ids( $language, $post_status, $post_type, 10001 ) );
			}

			$args = array(
				'post_type'      => $resolved_post_types,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => false,
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
				$count = (int) $query->found_posts;
				wp_reset_postdata();
			} finally {
				if ( $switched ) {
					// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML hook.
					do_action( 'wpml_switch_language', null );
				}
			}

			return $count;
		}

		// Non-public counts are permission-sensitive. Reuse the same bounded ID
		// collection path as export admission so delegated users cannot infer
		// unreadable private/draft content from aggregate counts. 10,001 is a
		// deliberate sentinel: it is enough to prove that the 10,000-item export
		// cap has been exceeded without turning a UI count into an unbounded scan.
		return count( $this->get_page_ids( $language, $post_status, $post_type, 10001 ) );
	}

	/**
	 * Get a memoized permalink for a page.
	 *
	 * `get_permalink()` is non-trivial : it loads the post, runs
	 * apply_filters( 'post_link', ... ), and resolves the rewrite
	 * rule. Within a single export the same page is referenced
	 * several times (page data, breadcrumb, child list), so caching
	 * the result per request trims several filter chains per page.
	 *
	 * @param int $page_id Page ID.
	 * @return string Memoized permalink (empty string if the page
	 *                has no permalink, e.g. not yet published).
	 */
	private function get_permalink_cached( int $page_id ): string {
		$language = '';
		if ( $this->is_wpml_active() ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML hook.
			$language = sanitize_key( (string) apply_filters( 'wpml_current_language', null ) );
		}
		$cache_key = $page_id . '|' . $language;

		if ( ! isset( $this->permalink_cache[ $cache_key ] ) ) {
			$this->cache_add( $this->permalink_cache, $cache_key, (string) get_permalink( $page_id ) );
		}
		return $this->permalink_cache[ $cache_key ];
	}

	/**
	 * Get the post title for a page, memoized for the request.
	 *
	 * The underlying get_the_title() runs through the `the_title` filter
	 * chain on every call, which is expensive during large batch exports.
	 * We memoize per request in a small static-ish array on this
	 * collector instance.
	 *
	 * @param int $page_id Page ID.
	 * @return string Post title (empty string if the post has none).
	 */
	private function get_title_cached( int $page_id ): string {
		if ( ! isset( $this->title_cache[ $page_id ] ) ) {
			$title = get_the_title( $page_id );
			$this->title_cache[ $page_id ] = ( false === $title || null === $title ) ? '' : (string) $title;
		}
		return $this->title_cache[ $page_id ];
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
		if (
			! $post_object instanceof WP_Post
			|| ! in_array( $post_object->post_type, $this->get_selectable_post_types(), true )
		) {
			return false;
		}

		if ( ! $this->is_post_readable_for_export( $page_id ) ) {
			return false;
		}

		if ( ! empty( $post_object->post_password ) ) {
			$password_title = $this->get_title_cached( $page_id );
			$password_title = $password_title ? $password_title : sprintf(
				/* translators: %d: post ID. */
				__( 'Untitled Page %d', 'sscribe-export-site-pages' ),
				$page_id
			);

			$password_author = get_the_author_meta( 'display_name', $post_object->post_author );
			if ( empty( $password_author ) ) {
				$password_author = __( 'Unknown', 'sscribe-export-site-pages' );
			}

			return array(
				'id'                  => $page_id,
				'title'               => html_entity_decode(
					$password_title,
					ENT_QUOTES | ENT_HTML5,
					'UTF-8'
				),
				'content'             => '<p>' . __( '[Password Protected Content]', 'sscribe-export-site-pages' ) . '</p>',
				'raw_content'         => '',
				'excerpt'             => '',
				'permalink'           => $this->get_permalink_cached( $page_id ),
				'slug'                => $post_object->post_name,
				'author'              => $password_author,
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

		$page_title_raw = $this->get_title_cached( $page_id );
		$page_title     = $page_title_raw ? $page_title_raw : sprintf(
			/* translators: %d: post ID. */
			__( 'Untitled Page %d', 'sscribe-export-site-pages' ),
			$page_id
		);

		$language = $this->get_page_language( $page_id );

		if ( $this->is_wpml_active() && ! empty( $language ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML hook.
			do_action( 'wpml_switch_language', $language );
			try {
				$permalink = $this->get_permalink_cached( $page_id );
			} finally {
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML hook.
				do_action( 'wpml_switch_language', null );
			}
		} else {
			$permalink = $this->get_permalink_cached( $page_id );
		}

		if ( ! in_array( $post_object->post_status, array( 'publish', 'private' ), true ) ) {
			$permalink = __( '[Draft - Not Published]', 'sscribe-export-site-pages' );
		}

		$filtered = apply_filters(
			'sscribe_page_data',
			array(
				'id'                  => $page_id,
				'title'               => html_entity_decode(
					$page_title,
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
	 * Invalid entries are ignored so extension code cannot accidentally turn a
	 * malformed ID into post ID 1 through PHP's array-to-integer conversion.
	 *
	 * @param array<mixed> $page_ids  Parent page IDs.
	 * @param string       $post_type Post type.
	 * @return array Child pages grouped by parent.
	 */
	public function get_child_pages_batch( array $page_ids, string $post_type = 'page' ): array {
		if ( empty( $page_ids ) ) {
			return array();
		}

		$page_ids = array_map(
			static fn( $id ): int => is_scalar( $id ) && ! is_bool( $id ) ? absint( $id ) : 0,
			$page_ids
		);
		$page_ids = array_filter( $page_ids );

		if ( empty( $page_ids ) ) {
			return array();
		}

		$batch_size         = 500;
		$paged              = 1;
		$children_by_parent = array();

		do {
			$args = array(
				'post_type'              => $this->resolve_post_type_for_query( $post_type ),
				'post_status'            => 'publish',
				'posts_per_page'         => $batch_size,
				'paged'                  => $paged,
				'post_parent__in'        => $page_ids,
				'orderby'                => array(
					'menu_order' => 'ASC',
					'title'      => 'ASC',
					'ID'         => 'ASC',
				),
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			);

			$query = new WP_Query( $args );
			foreach ( $query->posts as $child ) {
				if ( ! $this->is_post_readable_for_export( (int) $child->ID ) ) {
					continue;
				}
				$parent_id = $child->post_parent;
				if ( ! isset( $children_by_parent[ $parent_id ] ) ) {
					$children_by_parent[ $parent_id ] = array();
				}
				$children_by_parent[ $parent_id ][] = array(
					'id'    => $child->ID,
					'title' => html_entity_decode( $child->post_title, ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
					'url'   => $this->get_permalink_cached( (int) $child->ID ),
				);
			}
			$loaded = count( $query->posts );
			++$paged;
		} while ( $loaded === $batch_size );

		foreach ( $children_by_parent as $k => $v ) {
			$this->cache_add( $this->child_pages_cache, $k, $v );
		}

		return $children_by_parent;
	}

	/**
	 * Get child pages for a single parent.
	 *
	 * Uses wp_cache_get/set (object cache) so re-visiting a parent page
	 * during a large export hits no DB. Per-request instance cache is
	 * kept for the duration of the export so even the same-process
	 * repeats skip the wp_cache layer.
	 *
	 * @param int    $page_id   Parent page ID.
	 * @param string $post_type Post type.
	 * @return array Child pages.
	 */
	private function get_child_pages( int $page_id, string $post_type = 'page' ): array {
		if ( isset( $this->child_pages_cache[ $page_id ] ) ) {
			return $this->filter_readable_child_rows( $this->child_pages_cache[ $page_id ] );
		}

		$cache_key   = 'sscribe_child_pages_' . get_current_blog_id() . '_' . $page_id;
		$cache_group = 'sscribe_page_collector';
		$cached      = wp_cache_get( $cache_key, $cache_group );
		if ( is_array( $cached ) ) {
			$this->child_pages_cache[ $page_id ] = $cached;
			return $this->filter_readable_child_rows( $cached );
		}

		$children   = array();
		$offset     = 0;
		$batch_size = 200;
		do {
			$child_pages = get_children(
				array(
					'post_parent'            => $page_id,
					'post_type'              => $post_type,
					'post_status'            => 'publish',
					'orderby'                => 'menu_order title',
					'order'                  => 'ASC',
					'numberposts'             => $batch_size,
					'offset'                  => $offset,
					'no_found_rows'           => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				)
			);

			foreach ( (array) $child_pages as $child ) {
				$children[] = array(
					'id'    => $child->ID,
					'title' => html_entity_decode( $child->post_title, ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
					'url'   => $this->get_permalink_cached( (int) $child->ID ),
				);
			}
			$loaded = count( (array) $child_pages );
			$offset += $loaded;
		} while ( $loaded === $batch_size );

		wp_cache_set( $cache_key, $children, $cache_group, MINUTE_IN_SECONDS * 5 );
		$this->child_pages_cache[ $page_id ] = $children;
		return $this->filter_readable_child_rows( $children );
	}

	/**
	 * Filter cached child metadata through the current user's post permissions.
	 *
	 * @param array<int, mixed> $children Child metadata rows loaded from query/object cache.
	 * @return array<int, array<string, mixed>> Readable child rows.
	 */
	private function filter_readable_child_rows( array $children ): array {
		$ids = array();
		foreach ( $children as $child ) {
			if ( is_array( $child ) && isset( $child['id'] ) ) {
				$id = absint( $child['id'] );
				if ( $id > 0 ) {
					$ids[] = $id;
				}
			}
		}
		if ( ! empty( $ids ) ) {
			$this->prime_readability_post_cache( $ids );
		}

		return array_values(
			array_filter(
				$children,
				fn( $child ): bool => is_array( $child )
					&& isset( $child['id'] )
					&& $this->is_post_readable_for_export( absint( $child['id'] ) )
			)
		);
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
		return strtolower( substr( is_string( $lang ) ? $lang : '', 0, 2 ) );
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

		if ( $ancestors ) {
			$seen     = array( $page_id => true );
			$filtered = array();
			foreach ( $ancestors as $ancestor_id ) {
				if ( isset( $seen[ $ancestor_id ] ) ) {

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
					'post__in'               => $ancestors,
					'post_type'              => get_post_type( $page_id ),
					'post_status'            => 'publish',
					'fields'                 => 'all',
					'orderby'                => 'post__in',
					'order'                  => 'ASC',
					'numberposts'            => count( $ancestors ),
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				)
			);

			$ancestor_map = array();
			foreach ( is_array( $ancestor_posts ) ? $ancestor_posts : array() as $ancestor_post ) {
				$ancestor_map[ $ancestor_post->ID ] = $ancestor_post;
			}

			$ancestors_by_lang = array();
			foreach ( $ancestors as $ancestor_id ) {
				if (
					! isset( $ancestor_map[ $ancestor_id ] )
					|| ! $this->is_post_readable_for_export( (int) $ancestor_id )
				) {
					continue;
				}
				$ancestor_lang = $this->get_page_language( $ancestor_id );
				if ( ! isset( $ancestors_by_lang[ $ancestor_lang ] ) ) {
					$ancestors_by_lang[ $ancestor_lang ] = array();
				}
				$ancestors_by_lang[ $ancestor_lang ][] = $ancestor_id;
			}

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
							'url'   => $this->get_permalink_cached( $ancestor_id ),
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
				'title' => html_entity_decode( $this->get_title_cached( $page_id ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
				'url'   => $this->get_permalink_cached( $page_id ),
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
	 * Get the list of public post types the user can pick from the admin UI.
	 *
	 * Filters out `attachment` (handled by the media library, not exports) and
	 * exposes the allow-list through the `sscribe_allowed_post_types` filter so
	 * third-party integrations can add or remove types without forking.
	 *
	 * @return array<int, string>
	 */
	public function get_selectable_post_types(): array {
		$registered = function_exists( 'get_post_types' )
			? get_post_types( array( 'public' => true ) )
			: array( 'page', 'post' );

		if ( ! is_array( $registered ) ) {
			$registered = array( 'page', 'post' );
		}

		unset( $registered['attachment'] );

		return array_values(
			(array) apply_filters( 'sscribe_allowed_post_types', array_values( $registered ) )
		);
	}

	/**
	 * Resolve post type for WP_Query.
	 *
	 * Public so the batch processor and tests can share the same allow-list
	 * logic. The optional second parameter is a test seam.
	 *
	 * @param string     $post_type Post type input.
	 * @param array|null $allowed   Optional explicit allow-list (testing).
	 * @return string|array
	 */
	public function resolve_post_type_for_query( string $post_type, ?array $allowed = null ): string|array {
		$allowed_types = null !== $allowed ? $allowed : $this->get_selectable_post_types();

		if ( 'any' === $post_type ) {
			return $allowed_types;
		}

		if ( ! in_array( $post_type, $allowed_types, true ) ) {
			return $allowed_types[0] ?? 'page';
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
		$statuses = $this->get_valid_post_statuses();
		$counts   = array_fill_keys( array_keys( $statuses ), 0 );

		// Do not cache permission-sensitive aggregates. A user's role or mapped
		// post-type capabilities can change independently of the content cache
		// generation, and serving a pre-change count would disclose non-public
		// post metadata after access had been revoked.
		foreach ( array_keys( $statuses ) as $status ) {
			$counts[ $status ] = $this->get_page_count_only( $language, $status, $post_type );
		}

		$counts['all'] = array_sum( $counts );

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
			return $this->normalize_wpml_languages( $cached );
		}

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML-documented hook.
		$languages_raw = apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) );

		if ( empty( $languages_raw ) && function_exists( 'wpml_get_active_languages' ) ) {
			$languages_raw = wpml_get_active_languages( '' );
		}

		if ( ! is_array( $languages_raw ) ) {
			return array();
		}

		$result = $this->normalize_wpml_languages( $languages_raw );

		set_transient( $cache_key, $result, 5 * MINUTE_IN_SECONDS );

		return $result;
	}

	/**
	 * Normalize language rows returned by WPML or the transient cache.
	 *
	 * @param array $languages Raw language rows.
	 * @return array<int, array<string, string>> Validated rows.
	 */
	private function normalize_wpml_languages( array $languages ): array {
		$result = array();
		foreach ( array_slice( $languages, 0, 100 ) as $lang ) {
			if ( ! is_array( $lang ) ) {
				continue;
			}
			$code = sanitize_key( (string) ( $lang['language_code'] ?? $lang['code'] ?? '' ) );
			if ( 1 !== preg_match( '/^[a-z0-9_-]{1,20}$/D', $code ) ) {
				continue;
			}
			$result[] = array(
				'code'        => $code,
				'name'        => SScribe_Helpers::mb_substr( sanitize_text_field( (string) ( $lang['translated_name'] ?? $lang['name'] ?? $code ) ), 0, 100 ),
				'native_name' => SScribe_Helpers::mb_substr( sanitize_text_field( (string) ( $lang['native_name'] ?? $code ) ), 0, 100 ),
				'flag_url'    => esc_url_raw( (string) ( $lang['country_flag_url'] ?? $lang['flag_url'] ?? '' ) ),
			);
		}

		return $result;
	}

	/**
	 * Validate a requested language against WPML's active language list.
	 *
	 * @param string $language Requested language code; empty means all languages.
	 * @return string Active language code or an empty string.
	 */
	public function normalize_language_code( string $language ): string {
		if ( '' === $language || ! $this->is_wpml_active() ) {
			return '';
		}

		$language = sanitize_key( $language );
		if ( 1 !== preg_match( '/^[a-z0-9_-]{1,20}$/D', $language ) ) {
			return '';
		}

		foreach ( $this->get_wpml_languages() as $active_language ) {
			if ( isset( $active_language['code'] ) && hash_equals( sanitize_key( (string) $active_language['code'] ), $language ) ) {
				return $language;
			}
		}

		return '';
	}
}
