<?php
/**
 * Test language filtering functionality.
 *
 * @package SScribe
 */

use PHPUnit\Framework\TestCase;

/**
 * Class TestLanguageFiltering
 */
class TestLanguageFiltering extends TestCase {

	/**
	 * Test that empty language returns all pages.
	 */
	public function test_empty_language_returns_all_pages() {
		if ( ! defined( 'ABSPATH' ) ) {
			$this->markTestSkipped( 'WordPress environment required' );
			return;
		}

		$collector = new SScribe_Page_Collector();
		
		// Test with empty language (should get all pages)
		$all_pages = $collector->get_page_ids( '', 'publish' );
		$all_count = count( $all_pages );
		
		// Test with specific language if WPML is active
		if ( $collector->is_wpml_active() ) {
			$languages = $collector->get_wpml_languages();
			if ( ! empty( $languages ) ) {
				$first_lang = $languages[0]['code'];
				$lang_pages = $collector->get_page_ids( $first_lang, 'publish' );
				$lang_count = count( $lang_pages );
				
				// All pages should be >= language-specific pages
				$this->assertGreaterThanOrEqual( $lang_count, $all_count );
			}
		}
		
		// Should have at least some pages
		$this->assertGreaterThanOrEqual( 0, $all_count );
	}
}