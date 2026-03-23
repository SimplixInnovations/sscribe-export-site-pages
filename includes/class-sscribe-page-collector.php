<?php
/**
 * Collects page data for export.
 *
 * @package SScribe
 */

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
	 * Get all published page IDs, optionally filtered by language.
	 *
	 * @param string $language Optional WPML language code (e.g., 'en', 'ar').
	 * @return array Array of page IDs.
	 */
	public function get_page_ids( $language = '' ) {
		$args = array(
			'post_type'      => 'page',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'orderby'        => 'menu_order title',
			'order'          => 'ASC',
			'no_found_rows'  => true,
		);

		$switched = false;
		// WPML language filtering.
		if ( ! empty( $language ) && $this->is_wpml_active() ) {
			// Switch WPML language context.
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML hook.
			do_action( 'wpml_switch_language', $language );
			$args['suppress_filters'] = false;
			$switched = true;
		}

		try {
			$query    = new WP_Query( $args );
			$page_ids = $query->posts;
		} finally {
			// Reset WPML language context.
			if ( $switched ) {
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML hook.
				do_action( 'wpml_switch_language', null );
			}
		}

		return $page_ids;
	}

	/**
	 * Get page count efficiently without loading all IDs.
	 * Uses WP_Query's found_posts with posts_per_page=1 to avoid
	 * loading the full ID set just for counting.
	 *
	 * @param string $language Optional WPML language code.
	 * @return int
	 */
	public function get_page_count_only( $language = '' ) {
		$args = array(
			'post_type'      => 'page',
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => false,
		);

		$switched = false;
		if ( ! empty( $language ) && $this->is_wpml_active() ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML hook.
			do_action( 'wpml_switch_language', $language );
			$args['suppress_filters'] = false;
			$switched = true;
		}

		try {
			$query = new WP_Query( $args );
			$count = (int) $query->found_posts;
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
	public function get_total_pages( $language = '' ) {
		return $this->get_page_count_only( $language );
	}

	/**
	 * Collect full data for a single page.
	 *
	 * @param int $page_id The page ID.
	 * @return array|false Page data array or false on failure.
	 */
	public function get_page_data( $page_id ) {
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
				$post                      = $original_post;
				$sscribe_in_content_filter = false;
			}
		}

		// Calculate word count and reading time with Unicode fallback.
		$stripped    = wp_strip_all_tags( $content );
		$word_count  = str_word_count( $stripped );

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

		// Get featured image.
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
				'title'               => get_the_title( $page_id ),
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
	private function get_breadcrumbs( $page_id ) {
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
				'title' => get_the_title( $ancestor_id ),
				'url'   => get_permalink( $ancestor_id ),
			);
		}

		// Add current page.
		$breadcrumbs[] = array(
			'title' => get_the_title( $page_id ),
			'url'   => get_permalink( $page_id ),
		);

		return $breadcrumbs;
	}

	/**
	 * Get child pages of a given page.
	 *
	 * @param int $page_id The parent page ID.
	 * @return array Array of child page data (id, title, url).
	 */
	private function get_child_pages( $page_id ) {
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
					'title' => $child->post_title,
					'url'   => get_permalink( $child->ID ),
				);
			}
		}

		return $children;
	}

	/**
	 * Get the language of a page (WPML).
	 *
	 * @param int $page_id The page ID.
	 * @return string Language code or 'en' default.
	 */
	private function get_page_language( $page_id ) {
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
	public function is_wpml_active() {
		return defined( 'ICL_SITEPRESS_VERSION' ) && class_exists( 'SitePress' );
	}

	/**
	 * Get all active WPML languages.
	 *
	 * @return array Array of language data or empty array if WPML not active.
	 */
	public function get_wpml_languages() {
		if ( ! $this->is_wpml_active() ) {
			return array();
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

		return $result;
	}
}
