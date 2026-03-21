<?php
/**
 * Reads SEO metadata from popular SEO plugins.
 *
 * @package SScribe
 */

// Prevent direct access.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class SScribe_SEO_Reader
 *
 * Reads SEO metadata (meta title, description, focus keyword) from
 * popular SEO plugins in priority order.
 */
class SScribe_SEO_Reader
{

    /**
     * Get SEO data for a page from the best available SEO plugin.
     *
     * Priority order: Yoast → Rank Math → AIOSEO v4 → AIOSEO v3 → SEOPress → The SEO Framework.
     *
     * @param int $page_id The page ID.
     * @return array SEO data with keys: meta_title, meta_description, focus_keyword, source.
     */
    public function get_seo_data($page_id)
    {
        $seo_data = array(
            'meta_title' => '',
            'meta_description' => '',
            'focus_keyword' => '',
            'source' => '',
        );

        // Try each SEO plugin in priority order.
        $readers = array(
            'Yoast SEO' => 'read_yoast',
            'Rank Math' => 'read_rankmath',
            'All in One SEO v4' => 'read_aioseo_v4',
            'All in One SEO v3' => 'read_aioseo_v3',
            'SEOPress' => 'read_seopress',
            'The SEO Framework' => 'read_tsf',
        );

        foreach ($readers as $plugin_name => $method) {
            $result = $this->$method($page_id);
            if ($this->has_seo_data($result)) {
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
    public function has_seo_plugin()
    {
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
     * @return array Array of active SEO plugin names.
     */
    public function get_active_seo_plugins()
    {
        $active = array();
        if ($this->is_yoast_active()) {
            $active[] = 'Yoast SEO';
        }
        if ($this->is_rankmath_active()) {
            $active[] = 'Rank Math';
        }
        if ($this->is_aioseo_v4_active()) {
            $active[] = 'All in One SEO v4';
        }
        if ($this->is_aioseo_v3_active()) {
            $active[] = 'All in One SEO v3';
        }
        if ($this->is_seopress_active()) {
            $active[] = 'SEOPress';
        }
        if ($this->is_tsf_active()) {
            $active[] = 'The SEO Framework';
        }
        return $active;
    }

    /**
     * Read Yoast SEO metadata.
     *
     * @param int $page_id The page ID.
     * @return array SEO data.
     */
    private function read_yoast($page_id)
    {
        if (!$this->is_yoast_active()) {
            return $this->empty_seo_data();
        }
        return array(
            'meta_title' => (string) get_post_meta($page_id, '_yoast_wpseo_title', true),
            'meta_description' => (string) get_post_meta($page_id, '_yoast_wpseo_metadesc', true),
            'focus_keyword' => (string) get_post_meta($page_id, '_yoast_wpseo_focuskw', true),
            'source' => '',
        );
    }

    /**
     * Read Rank Math metadata.
     *
     * @param int $page_id The page ID.
     * @return array SEO data.
     */
    private function read_rankmath($page_id)
    {
        if (!$this->is_rankmath_active()) {
            return $this->empty_seo_data();
        }
        return array(
            'meta_title' => (string) get_post_meta($page_id, 'rank_math_title', true),
            'meta_description' => (string) get_post_meta($page_id, 'rank_math_description', true),
            'focus_keyword' => (string) get_post_meta($page_id, 'rank_math_focus_keyword', true),
            'source' => '',
        );
    }

    /**
     * Read All in One SEO v4 metadata.
     *
     * @param int $page_id The page ID.
     * @return array SEO data.
     */
    private function read_aioseo_v4($page_id)
    {
        if (!$this->is_aioseo_v4_active()) {
            return $this->empty_seo_data();
        }

        $title = '';
        $description = '';
        $keyword = '';

        if (function_exists('aioseo')) {
            $aioseo_post = aioseo()->models->Post::getPost($page_id);
            if ($aioseo_post) {
                $title = isset($aioseo_post->title) ? (string) $aioseo_post->title : '';
                $description = isset($aioseo_post->description) ? (string) $aioseo_post->description : '';
                $keyphrases = isset($aioseo_post->keyphrases) ? json_decode($aioseo_post->keyphrases, true) : array();
                if (!empty($keyphrases['focus']['keyphrase'])) {
                    $keyword = $keyphrases['focus']['keyphrase'];
                }
            }
        }

        return array(
            'meta_title' => $title,
            'meta_description' => $description,
            'focus_keyword' => $keyword,
            'source' => '',
        );
    }

    /**
     * Read All in One SEO v3 (legacy) metadata.
     *
     * @param int $page_id The page ID.
     * @return array SEO data.
     */
    private function read_aioseo_v3($page_id)
    {
        if (!$this->is_aioseo_v3_active()) {
            return $this->empty_seo_data();
        }
        return array(
            'meta_title' => (string) get_post_meta($page_id, '_aioseop_title', true),
            'meta_description' => (string) get_post_meta($page_id, '_aioseop_description', true),
            'focus_keyword' => (string) get_post_meta($page_id, '_aioseop_keywords', true),
            'source' => '',
        );
    }

    /**
     * Read SEOPress metadata.
     *
     * @param int $page_id The page ID.
     * @return array SEO data.
     */
    private function read_seopress($page_id)
    {
        if (!$this->is_seopress_active()) {
            return $this->empty_seo_data();
        }
        return array(
            'meta_title' => (string) get_post_meta($page_id, '_seopress_titles_title', true),
            'meta_description' => (string) get_post_meta($page_id, '_seopress_titles_desc', true),
            'focus_keyword' => (string) get_post_meta($page_id, '_seopress_analysis_target_kw', true),
            'source' => '',
        );
    }

    /**
     * Read The SEO Framework metadata.
     *
     * @param int $page_id The page ID.
     * @return array SEO data.
     */
    private function read_tsf($page_id)
    {
        if (!$this->is_tsf_active()) {
            return $this->empty_seo_data();
        }
        return array(
            'meta_title' => (string) get_post_meta($page_id, '_genesis_title', true),
            'meta_description' => (string) get_post_meta($page_id, '_genesis_description', true),
            'focus_keyword' => (string) get_post_meta($page_id, '_tsf_title', true),
            'source' => '',
        );
    }

    /**
     * Check if results have any actual SEO data.
     *
     * @param array $data SEO data array.
     * @return bool
     */
    private function has_seo_data($data)
    {
        return !empty($data['meta_title'])
            || !empty($data['meta_description'])
            || !empty($data['focus_keyword']);
    }

    /**
     * Return empty SEO data structure.
     *
     * @return array
     */
    private function empty_seo_data()
    {
        return array(
            'meta_title' => '',
            'meta_description' => '',
            'focus_keyword' => '',
            'source' => '',
        );
    }

    // Plugin detection helpers.

    private function is_yoast_active()
    {
        return defined('WPSEO_VERSION');
    }

    private function is_rankmath_active()
    {
        return class_exists('RankMath');
    }

    private function is_aioseo_v4_active()
    {
        return function_exists('aioseo') && defined('AIOSEO_VERSION');
    }

    private function is_aioseo_v3_active()
    {
        return class_exists('All_in_One_SEO_Pack') && !function_exists('aioseo');
    }

    private function is_seopress_active()
    {
        return defined('SEOPRESS_VERSION');
    }

    private function is_tsf_active()
    {
        return defined('THE_SEO_FRAMEWORK_VERSION');
    }
}
