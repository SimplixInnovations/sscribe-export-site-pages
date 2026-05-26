<?php
/**
 * SScribe SEO Reader
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
 * Reads SEO metadata from popular WordPress SEO plugins.
 */
class SScribe_SEO_Reader {

	/**
	 * Get SEO data for a page from the first active SEO plugin.
	 *
	 * @param int $page_id Post ID.
	 * @return array
	 */
	public function get_seo_data( int $page_id ): array {
		$seo_data = array(
			'meta_title'       => '',
			'meta_description' => '',
			'focus_keyword'    => '',
			'canonical_url'    => '',
			'og_title'         => '',
			'og_description'   => '',
			'og_image'         => '',
			'noindex'          => false,
			'nofollow'         => false,
			'source'           => '',
		);

		$readers = array(
			'Yoast SEO'         => 'read_yoast',
			'Rank Math'         => 'read_rankmath',
			'All in One SEO v4' => 'read_aioseo_v4',
			'All in One SEO v3' => 'read_aioseo_v3',
			'SEOPress'          => 'read_seopress',
			'The SEO Framework' => 'read_tsf',
		);

		foreach ( $readers as $plugin_name => $method ) {
			$result = $this->$method( $page_id );
			if ( $this->has_seo_data( $result ) ) {
				$result['source'] = $plugin_name;
				return $result;
			}
		}

		return $seo_data;
	}

	/**
	 * Check if any SEO plugin is active.
	 *
	 * @return bool
	 */
	public function has_seo_plugin(): bool {
		return $this->is_yoast_active()
			|| $this->is_rankmath_active()
			|| $this->is_aioseo_v4_active()
			|| $this->is_aioseo_v3_active()
			|| $this->is_seopress_active()
			|| $this->is_tsf_active();
	}

	/**
	 * Get list of active SEO plugins.
	 *
	 * @return array
	 */
	public function get_active_seo_plugins(): array {
		$active = array();
		if ( $this->is_yoast_active() ) {
			$active[] = 'Yoast SEO';
		}
		if ( $this->is_rankmath_active() ) {
			$active[] = 'Rank Math';
		}
		if ( $this->is_aioseo_v4_active() ) {
			$active[] = 'All in One SEO v4';
		}
		if ( $this->is_aioseo_v3_active() ) {
			$active[] = 'All in One SEO v3';
		}
		if ( $this->is_seopress_active() ) {
			$active[] = 'SEOPress';
		}
		if ( $this->is_tsf_active() ) {
			$active[] = 'The SEO Framework';
		}
		return $active;
	}

	/**
	 * Read Yoast SEO metadata.
	 *
	 * @param int $page_id Post ID.
	 * @return array
	 */
	private function read_yoast( int $page_id ): array {
		if ( ! $this->is_yoast_active() ) {
			return $this->empty_seo_data();
		}
		$robots_noindex  = get_post_meta( $page_id, '_yoast_wpseo_meta-robots-noindex', true );
		$robots_nofollow = get_post_meta( $page_id, '_yoast_wpseo_meta-robots-nofollow', true );

		$focus_kw = get_post_meta( $page_id, '_yoast_wpseo_focuskw', true );
		if ( is_array( $focus_kw ) ) {
			$focus_kw = implode( ', ', $focus_kw );
		}

		return array(
			'meta_title'       => wp_strip_all_tags( (string) get_post_meta( $page_id, '_yoast_wpseo_title', true ) ),
			'meta_description' => wp_strip_all_tags( (string) get_post_meta( $page_id, '_yoast_wpseo_metadesc', true ) ),
			'focus_keyword'    => wp_strip_all_tags( (string) $focus_kw ),
			'canonical_url'    => (string) get_post_meta( $page_id, '_yoast_wpseo_canonical', true ),
			'og_title'         => (string) get_post_meta( $page_id, '_yoast_wpseo_opengraph-title', true ),
			'og_description'   => (string) get_post_meta( $page_id, '_yoast_wpseo_opengraph-description', true ),
			'og_image'         => (string) get_post_meta( $page_id, '_yoast_wpseo_opengraph-image', true ),
			'noindex'          => '1' === $robots_noindex,
			'nofollow'         => '1' === $robots_nofollow,
			'source'           => '',
		);
	}

	/**
	 * Read Rank Math metadata.
	 *
	 * @param int $page_id Post ID.
	 * @return array
	 */
	private function read_rankmath( int $page_id ): array {
		if ( ! $this->is_rankmath_active() ) {
			return $this->empty_seo_data();
		}
		$robots   = get_post_meta( $page_id, 'rank_math_robots', true );
		$noindex  = false;
		$nofollow = false;
		if ( is_array( $robots ) ) {
			$noindex  = in_array( 'noindex', $robots, true );
			$nofollow = in_array( 'nofollow', $robots, true );
		}

		$focus_kw = get_post_meta( $page_id, 'rank_math_focus_keyword', true );
		if ( is_array( $focus_kw ) ) {
			$focus_kw = implode( ', ', $focus_kw );
		}

		return array(
			'meta_title'       => wp_strip_all_tags( (string) get_post_meta( $page_id, 'rank_math_title', true ) ),
			'meta_description' => wp_strip_all_tags( (string) get_post_meta( $page_id, 'rank_math_description', true ) ),
			'focus_keyword'    => wp_strip_all_tags( (string) $focus_kw ),
			'canonical_url'    => (string) get_post_meta( $page_id, 'rank_math_canonical_url', true ),
			'og_title'         => (string) get_post_meta( $page_id, 'rank_math_facebook_title', true ),
			'og_description'   => (string) get_post_meta( $page_id, 'rank_math_facebook_description', true ),
			'og_image'         => (string) get_post_meta( $page_id, 'rank_math_facebook_image', true ),
			'noindex'          => $noindex,
			'nofollow'         => $nofollow,
			'source'           => '',
		);
	}

	/**
	 * Read All in One SEO v4 metadata.
	 *
	 * @param int $page_id Post ID.
	 * @return array
	 */
	private function read_aioseo_v4( int $page_id ): array {
		if ( ! $this->is_aioseo_v4_active() ) {
			return $this->empty_seo_data();
		}

		$title          = '';
		$description    = '';
		$keyword        = '';
		$canonical_url  = '';
		$og_title       = '';
		$og_description = '';
		$og_image       = '';
		$noindex        = false;
		$nofollow       = false;

		if ( function_exists( 'aioseo' ) ) {
			$aioseo_post = aioseo()->models->Post::getPost( $page_id );
			if ( $aioseo_post ) {
				$title          = isset( $aioseo_post->title ) ? (string) $aioseo_post->title : '';
				$description    = isset( $aioseo_post->description ) ? (string) $aioseo_post->description : '';
				$canonical_url  = isset( $aioseo_post->canonical_url ) ? (string) $aioseo_post->canonical_url : '';
				$og_title       = isset( $aioseo_post->og_title ) ? (string) $aioseo_post->og_title : '';
				$og_description = isset( $aioseo_post->og_description ) ? (string) $aioseo_post->og_description : '';
				$og_image       = isset( $aioseo_post->og_image_url ) ? (string) $aioseo_post->og_image_url : '';
				$noindex        = ! empty( $aioseo_post->robots_noindex );
				$nofollow       = ! empty( $aioseo_post->robots_nofollow );
				$keyphrases     = isset( $aioseo_post->keyphrases ) ? json_decode( $aioseo_post->keyphrases, true ) : array();
				if ( ! empty( $keyphrases['focus']['keyphrase'] ) ) {
					$keyword = $keyphrases['focus']['keyphrase'];
				}
			}
		}

		return array(
			'meta_title'       => wp_strip_all_tags( $title ),
			'meta_description' => wp_strip_all_tags( $description ),
			'focus_keyword'    => wp_strip_all_tags( $keyword ),
			'canonical_url'    => $canonical_url,
			'og_title'         => $og_title,
			'og_description'   => $og_description,
			'og_image'         => $og_image,
			'noindex'          => $noindex,
			'nofollow'         => $nofollow,
			'source'           => '',
		);
	}

	/**
	 * Read All in One SEO v3 metadata.
	 *
	 * @param int $page_id Post ID.
	 * @return array
	 */
	private function read_aioseo_v3( int $page_id ): array {
		if ( ! $this->is_aioseo_v3_active() ) {
			return $this->empty_seo_data();
		}

		$og_image = (string) get_post_meta( $page_id, '_aioseop_opengraph_image', true );
		if ( empty( $og_image ) ) {
			$og_image = (string) get_post_meta( $page_id, '_aioseop_social_image_url', true );
		}

		$noindex  = false;
		$nofollow = false;
		$robots   = get_post_meta( $page_id, '_aioseop_robots', true );
		if ( ! empty( $robots ) && is_string( $robots ) ) {
			$robots_lower = strtolower( $robots );
			$noindex      = str_contains( $robots_lower, 'noindex' );
			$nofollow     = str_contains( $robots_lower, 'nofollow' );
		}

		$meta_robots_noindex = get_post_meta( $page_id, '_aioseop_noindex', true );
		if ( 'on' === $meta_robots_noindex ) {
			$noindex = true;
		}

		$meta_robots_nofollow = get_post_meta( $page_id, '_aioseop_nofollow', true );
		if ( 'on' === $meta_robots_nofollow ) {
			$nofollow = true;
		}

return array(
			'meta_title'       => wp_strip_all_tags( (string) get_post_meta( $page_id, '_aioseop_title', true ) ),
			'meta_description' => wp_strip_all_tags( (string) get_post_meta( $page_id, '_aioseop_description', true ) ),
			'focus_keyword'    => wp_strip_all_tags( $focus_keyword ),
			'canonical_url'    => (string) get_post_meta( $page_id, '_aioseop_custom_link', true ),
			'og_title'         => (string) get_post_meta( $page_id, '_aioseop_opengraph_title', true ),
			'og_description'   => (string) get_post_meta( $page_id, '_aioseop_opengraph_description', true ),
			'og_image'         => $og_image,
			'noindex'          => $noindex,
			'nofollow'         => $nofollow,
			'source'           => '',
		);
	}

	/**
	 * Read SEOPress metadata.
	 *
	 * @param int $page_id Post ID.
	 * @return array
	 */
	private function read_seopress( int $page_id ): array {
		if ( ! $this->is_seopress_active() ) {
			return $this->empty_seo_data();
		}
		$focus_kw = get_post_meta( $page_id, '_seopress_analysis_target_kw', true );
		if ( is_array( $focus_kw ) ) {
			$focus_kw = implode( ', ', $focus_kw );
		}
		return array(
			'meta_title'       => wp_strip_all_tags( (string) get_post_meta( $page_id, '_seopress_titles_title', true ) ),
			'meta_description' => wp_strip_all_tags( (string) get_post_meta( $page_id, '_seopress_titles_desc', true ) ),
			'focus_keyword'    => wp_strip_all_tags( (string) $focus_kw ),
			'canonical_url'    => (string) get_post_meta( $page_id, '_seopress_robots_canonical', true ),
			'og_title'         => (string) get_post_meta( $page_id, '_seopress_social_fb_title', true ),
			'og_description'   => (string) get_post_meta( $page_id, '_seopress_social_fb_desc', true ),
			'og_image'         => (string) get_post_meta( $page_id, '_seopress_social_fb_img', true ),
			'noindex'          => 'yes' === get_post_meta( $page_id, '_seopress_robots_index', true ),
			'nofollow'         => 'yes' === get_post_meta( $page_id, '_seopress_robots_follow', true ),
			'source'           => '',
		);
	}

	/**
	 * Read The SEO Framework metadata.
	 *
	 * @param int $page_id Post ID.
	 * @return array
	 */
	private function read_tsf( int $page_id ): array {
		if ( ! $this->is_tsf_active() ) {
			return $this->empty_seo_data();
		}

		$focus_keyword   = '';
		$primary_term_id = get_post_meta( $page_id, '_primary_term_' . $this->get_primary_taxonomy(), true );
		if ( $primary_term_id ) {
			$term = get_term( $primary_term_id );
			if ( $term && ! is_wp_error( $term ) ) {
				$focus_keyword = $term->name;
			}
		}

		$og_image = (string) get_post_meta( $page_id, '_social_image_url', true );
		if ( empty( $og_image ) ) {
			$og_image = (string) get_post_meta( $page_id, '_open_graph_image', true );
		}

		$noindex  = '1' === get_post_meta( $page_id, '_genesis_noindex', true );
		$nofollow = '1' === get_post_meta( $page_id, '_genesis_nofollow', true );

		return array(
			'meta_title'       => wp_strip_all_tags( (string) get_post_meta( $page_id, '_genesis_title', true ) ),
			'meta_description' => wp_strip_all_tags( (string) get_post_meta( $page_id, '_genesis_description', true ) ),
			'focus_keyword'    => wp_strip_all_tags( $focus_keyword ),
			'canonical_url'    => (string) get_post_meta( $page_id, '_genesis_canonical_uri', true ),
			'og_title'         => (string) get_post_meta( $page_id, '_open_graph_title', true ),
			'og_description'   => (string) get_post_meta( $page_id, '_open_graph_description', true ),
			'og_image'         => $og_image,
			'noindex'          => $noindex,
			'nofollow'         => $nofollow,
			'source'           => '',
		);
	}

	/**
	 * Get the primary taxonomy name for pages.
	 *
	 * @return string
	 */
	private function get_primary_taxonomy(): string {
		$taxonomies = get_object_taxonomies( 'page', 'objects' );
		foreach ( $taxonomies as $taxonomy ) {
			if ( $taxonomy->hierarchical && $taxonomy->public ) {
				return $taxonomy->name;
			}
		}
		return 'category';
	}

	/**
	 * Check if SEO data array has any populated fields.
	 *
	 * @param array $data SEO data.
	 * @return bool
	 */
	private function has_seo_data( array $data ): bool {
		return ! empty( $data['meta_title'] )
			|| ! empty( $data['meta_description'] )
			|| ! empty( $data['focus_keyword'] )
			|| ! empty( $data['canonical_url'] )
			|| ! empty( $data['og_title'] )
			|| ! empty( $data['og_description'] )
			|| ! empty( $data['og_image'] );
	}

	/**
	 * Get empty SEO data structure.
	 *
	 * @return array
	 */
	private function empty_seo_data(): array {
		return array(
			'meta_title'       => '',
			'meta_description' => '',
			'focus_keyword'    => '',
			'canonical_url'    => '',
			'og_title'         => '',
			'og_description'   => '',
			'og_image'         => '',
			'noindex'          => false,
			'nofollow'         => false,
			'source'           => '',
		);
	}

	/**
	 * Check if Yoast SEO is active.
	 *
	 * @return bool
	 */
	private function is_yoast_active(): bool {
		return defined( 'WPSEO_VERSION' );
	}

	/**
	 * Check if Rank Math is active.
	 *
	 * @return bool
	 */
	private function is_rankmath_active(): bool {
		return class_exists( 'RankMath' );
	}

	/**
	 * Check if All in One SEO v4 is active.
	 *
	 * @return bool
	 */
	private function is_aioseo_v4_active(): bool {
		return function_exists( 'aioseo' ) && defined( 'AIOSEO_VERSION' );
	}

	/**
	 * Check if All in One SEO v3 is active.
	 *
	 * @return bool
	 */
	private function is_aioseo_v3_active(): bool {
		return class_exists( 'All_in_One_SEO_Pack' ) && ! function_exists( 'aioseo' );
	}

	/**
	 * Check if SEOPress is active.
	 *
	 * @return bool
	 */
	private function is_seopress_active(): bool {
		return defined( 'SEOPRESS_VERSION' );
	}

	/**
	 * Check if The SEO Framework is active.
	 *
	 * @return bool
	 */
	private function is_tsf_active(): bool {
		return defined( 'THE_SEO_FRAMEWORK_VERSION' );
	}
}
