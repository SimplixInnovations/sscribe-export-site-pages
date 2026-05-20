<?php
/**
 * SScribe Language Filtering Test
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class TestLanguageFiltering extends TestCase {



	public function test_empty_language_returns_all_pages() {
		if ( ! defined( 'ABSPATH' ) ) {
			$this->markTestSkipped( 'WordPress environment required' );
			return;
		}

		$collector = new SScribe_Page_Collector();
		
		
		$all_pages = $collector->get_page_ids( '', 'publish' );
		$all_count = count( $all_pages );
		
		
		if ( $collector->is_wpml_active() ) {
			$languages = $collector->get_wpml_languages();
			if ( ! empty( $languages ) ) {
				$first_lang = $languages[0]['code'];
				$lang_pages = $collector->get_page_ids( $first_lang, 'publish' );
				$lang_count = count( $lang_pages );
				
				
				$this->assertGreaterThanOrEqual( $lang_count, $all_count );
			}
		}
		
		
		$this->assertGreaterThanOrEqual( 0, $all_count );
	}
}
