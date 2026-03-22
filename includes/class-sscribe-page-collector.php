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

		// WPML language filtering.
		if ( ! empty( $language ) && $this->is_wpml_active() ) {
			// Switch WPML language context.
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML hook.
			do_action( 'wpml_switch_language', $language );
			$args['suppress_filters'] = false;
		}

		$query    = new WP_Query( $args );
		$page_ids = $query->posts;

		// Reset WPML language context.
		if ( ! empty( $language ) && $this->is_wpml_active() ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML hook.
			do_action( 'wpml_switch_language', null );
		}

		return $page_ids;
	}

	/**
	 * Get total number of pages for a given language.
	 *
	 * @param string $language Optional WPML language code.
	 * @return int Total number of pages.
	 */
	public function get_total_pages( $language = '' ) {
		 return count( $this->get_page_ids( $language ) );
	}

	/**
	 * Collect full data for a single page.
	 *
	 * @param int $page_id The page ID.
	 * @return array|false Page data array or false on failure.
	 */
	public function get_page_data( $page_id ) {
		 $post = get_post( $page_id );
		if ( ! $post || 'page' !== $post->post_type ) {
			return false;
		}

		// Get rendered content (applies Gutenberg / builder filters).
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core filter.
		$content = apply_filters( 'the_content', $post->post_content );

		// Calculate word count and reading time.
		$word_count   = str_word_count( wp_strip_all_tags( $content ) );
		$reading_time = max( 1, (int) ceil( $word_count / 200 ) );

		// Get author.
		$author = get_the_author_meta( 'display_name', $post->post_author );

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

		return array(
			'id'                  => $page_id,
			'title'               => get_the_title( $page_id ),
			'content'             => $content,
			'raw_content'         => $post->post_content,
			'excerpt'             => $post->post_excerpt,
			'permalink'           => $permalink,
			'slug'                => $post->post_name,
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
			'parent_id'           => $post->post_parent,
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

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML hook.
		$languages = apply_filters(
			'wpml_active_languages',
			null,
			array(
				'skip_missing' => 0,
			)
		);

		if ( ! is_array( $languages ) ) {
			return array();
		}

		$result = array();
		foreach ( $languages as $lang ) {
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
