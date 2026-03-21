<?php
/**
 * Define internationalization functionality.
 *
 * @package SScribe
 */

// Prevent direct access.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class SScribe_i18n
 *
 * Loads the plugin text domain for translation.
 */
class SScribe_i18n
{

    /**
     * Load the plugin text domain.
     *
     * @return void
     */
    public function load_plugin_textdomain()
    {
        load_plugin_textdomain(
            'sscribe-export-site-pages',
            false,
            dirname(SSCRIBE_PLUGIN_BASENAME) . '/languages/'
        );
    }
}
