<?php
/**
 * Polylang and TranslatePress support in SScribe_Page_Collector.
 *
 * Neither plugin can be installed in the fake-WP unit environment, so each
 * test loads a minimal stand-in for the public API SScribe relies on
 * (see tests/WPML_STRATEGY.md). Constants, functions and classes cannot be
 * unloaded, so every test that defines one runs in its own process.
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use SScribe_Page_Collector;
use SScribe_RTL_Helper;

if ( ! class_exists( '\\SScribe_Page_Collector', false ) ) {
	require_once SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-page-collector.php';
}

final class SScribe_Page_Collector_Multilingual_Test extends TestCase {

	/**
	 * Load a Polylang stand-in. Post languages come from
	 * $GLOBALS['sscribe_test_pll_post_languages'][ $post_id ][ $field ].
	 */
	private static function load_polylang(): void {
		if ( ! defined( 'POLYLANG_VERSION' ) ) {
			define( 'POLYLANG_VERSION', '3.6.0' );
		}
		if ( ! function_exists( 'pll_languages_list' ) ) {
			eval(
				'function pll_languages_list( $args = array() ) {
					$GLOBALS["sscribe_test_pll_list_args"] = $args;
					$make = static function ( $slug, $name, $locale, $rtl ) {
						$l = new \stdClass();
						$l->slug     = $slug;
						$l->name     = $name;
						$l->locale   = $locale;
						$l->is_rtl   = $rtl;
						$l->flag_url = "https://example.test/flags/" . $slug . ".png";
						return $l;
					};
					return array(
						$make( "en", "English", "en_US", 0 ),
						$make( "fr", "Français", "fr_FR", 0 ),
						$make( "arabic", "العربية", "ar", 1 ),
					);
				}
				function pll_get_post_language( $post_id, $field = "slug" ) {
					return $GLOBALS["sscribe_test_pll_post_languages"][ $post_id ][ $field ] ?? false;
				}'
			);
		}
	}

	/**
	 * Load a TranslatePress stand-in with en_US (default), ar and fr_FR.
	 */
	private static function load_translatepress(): void {
		$GLOBALS['sscribe_test_options']['trp_settings'] = array(
			'default-language'  => 'en_US',
			'publish-languages' => array( 'en_US', 'ar', 'fr_FR' ),
		);
		if ( ! class_exists( 'TRP_Translate_Press', false ) ) {
			eval(
				'class SScribe_Test_TRP_Languages {
					public function get_language_names( $codes, $which = null ) {
						$english = array( "en_US" => "English", "ar" => "Arabic", "fr_FR" => "French" );
						$native  = array( "en_US" => "English", "ar" => "العربية", "fr_FR" => "Français" );
						$names   = "native_name" === $which ? $native : $english;
						return array_intersect_key( $names, array_flip( $codes ) );
					}
				}
				class SScribe_Test_TRP_Url_Converter {
					public function get_url_for_language( $language, $url = null, $processed = "" ) {
						return $url . "?trp-lang=" . $language;
					}
				}
				class TRP_Translate_Press {
					public static function get_trp_instance() { return new self(); }
					public function get_component( $name ) {
						if ( "languages" === $name ) { return new SScribe_Test_TRP_Languages(); }
						if ( "url_converter" === $name ) { return new SScribe_Test_TRP_Url_Converter(); }
						return null;
					}
				}
				function trp_translate( $content, $language = null, $prevent_over_translation = true ) {
					return "[" . $language . "] " . $content;
				}'
			);
		}
	}

	/**
	 * Call a private collector method.
	 *
	 * @param SScribe_Page_Collector $collector Collector.
	 * @param string                 $method    Method name.
	 * @param array                  $args      Arguments (may hold references).
	 * @return mixed
	 */
	private static function call_private( SScribe_Page_Collector $collector, string $method, array $args ): mixed {
		$reflection = new \ReflectionMethod( SScribe_Page_Collector::class, $method );
		return $reflection->invokeArgs( $collector, $args );
	}

	public function test_no_multilingual_plugin_means_no_provider_and_no_languages(): void {
		$collector = new SScribe_Page_Collector();

		$this::assertSame( '', $collector->get_multilingual_provider() );
		$this::assertFalse( $collector->is_multilingual_active() );
		$this::assertSame( array(), $collector->get_languages() );
		$this::assertSame( '', $collector->normalize_language_code( 'fr' ) );

		$args     = array();
		$switched = self::call_private( $collector, 'apply_language_to_query', array( &$args, 'fr' ) );
		$this::assertFalse( $switched );
		$this::assertSame( array(), $args, 'Queries must be untouched when no multilingual plugin is active.' );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_polylang_is_detected_and_lists_languages(): void {
		self::load_polylang();
		$collector = new SScribe_Page_Collector();

		$this::assertTrue( $collector->is_polylang_active() );
		$this::assertSame( SScribe_Page_Collector::PROVIDER_POLYLANG, $collector->get_multilingual_provider() );
		$this::assertTrue( $collector->is_multilingual_active() );

		$languages = $collector->get_languages();
		$this::assertSame( array( 'en', 'fr', 'arabic' ), array_column( $languages, 'code' ) );
		$this::assertSame( 'Français', $languages[1]['name'] );
		$this::assertSame( 'https://example.test/flags/fr.png', $languages[1]['flag_url'] );
		$this::assertSame( '', $GLOBALS['sscribe_test_pll_list_args']['fields'] ?? null, 'Polylang must be asked for full language objects.' );

		$this::assertSame( 'fr', $collector->normalize_language_code( 'fr' ) );
		$this::assertSame( '', $collector->normalize_language_code( 'de' ) );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_polylang_queries_are_scoped_with_the_lang_query_var(): void {
		self::load_polylang();
		$collector = new SScribe_Page_Collector();

		$args     = array( 'post_type' => 'page' );
		$switched = self::call_private( $collector, 'apply_language_to_query', array( &$args, 'fr' ) );
		$this::assertFalse( $switched, 'Polylang needs no global language switch to undo.' );
		$this::assertSame( 'fr', $args['lang'] );
		$this::assertFalse( $args['suppress_filters'] );

		$all_args = array();
		self::call_private( $collector, 'apply_language_to_query', array( &$all_args, '' ) );
		$this::assertSame( '', $all_args['lang'], 'An empty lang query var makes Polylang return every language.' );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_polylang_page_language_uses_locale_so_rtl_survives_custom_slugs(): void {
		self::load_polylang();
		$GLOBALS['sscribe_test_pll_post_languages'] = array(
			10 => array(
				'slug'   => 'arabic',
				'locale' => 'ar',
			),
			11 => array(
				'slug'   => 'he',
				'locale' => 'he_IL',
			),
			12 => array(
				'slug'   => 'fr',
				'locale' => 'fr_FR',
			),
		);
		$collector = new SScribe_Page_Collector();

		$arabic = self::call_private( $collector, 'get_page_language', array( 10 ) );
		$hebrew = self::call_private( $collector, 'get_page_language', array( 11 ) );
		$french = self::call_private( $collector, 'get_page_language', array( 12 ) );

		$this::assertSame( 'ar', $arabic );
		$this::assertSame( 'he', $hebrew );
		$this::assertSame( 'fr', $french );
		$this::assertTrue( SScribe_RTL_Helper::is_rtl( $arabic ) );
		$this::assertTrue( SScribe_RTL_Helper::is_rtl( $hebrew ) );
		$this::assertFalse( SScribe_RTL_Helper::is_rtl( $french ) );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_wpml_takes_precedence_over_polylang(): void {
		self::load_polylang();
		if ( ! defined( 'ICL_SITEPRESS_VERSION' ) ) {
			define( 'ICL_SITEPRESS_VERSION', '4.6.0' );
		}
		if ( ! class_exists( 'SitePress', false ) ) {
			eval( 'class SitePress {}' );
		}
		$collector = new SScribe_Page_Collector();

		$this::assertSame( SScribe_Page_Collector::PROVIDER_WPML, $collector->get_multilingual_provider() );

		$args     = array();
		$switched = self::call_private( $collector, 'apply_language_to_query', array( &$args, 'fr' ) );
		$this::assertTrue( $switched, 'The WPML path must still switch languages globally.' );
		$this::assertArrayNotHasKey( 'lang', $args );
		self::call_private( $collector, 'restore_language_after_query', array( $switched ) );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_translatepress_is_detected_and_lists_published_languages(): void {
		self::load_translatepress();
		$collector = new SScribe_Page_Collector();

		$this::assertTrue( $collector->is_translatepress_active() );
		$this::assertSame( SScribe_Page_Collector::PROVIDER_TRANSLATEPRESS, $collector->get_multilingual_provider() );

		$languages = $collector->get_languages();
		$this::assertSame( array( 'en_us', 'ar', 'fr_fr' ), array_column( $languages, 'code' ) );
		$this::assertSame( 'French', $languages[2]['name'] );
		$this::assertSame( 'Français', $languages[2]['native_name'] );

		$this::assertSame( 'fr_fr', $collector->normalize_language_code( 'fr_fr' ) );
		$this::assertSame( '', $collector->normalize_language_code( 'de_de' ) );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_translatepress_queries_are_not_narrowed(): void {
		self::load_translatepress();
		$collector = new SScribe_Page_Collector();

		$args     = array( 'post_type' => 'page' );
		$switched = self::call_private( $collector, 'apply_language_to_query', array( &$args, 'ar' ) );

		$this::assertFalse( $switched );
		$this::assertSame( array( 'post_type' => 'page' ), $args, 'TranslatePress keeps every language on one post, so every page is exported.' );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_translatepress_page_language_follows_the_export_language(): void {
		self::load_translatepress();
		$collector = new SScribe_Page_Collector();

		$arabic  = self::call_private( $collector, 'get_page_language', array( 5, 'ar' ) );
		$french  = self::call_private( $collector, 'get_page_language', array( 5, 'fr_fr' ) );
		$default = self::call_private( $collector, 'get_page_language', array( 5, '' ) );

		$this::assertSame( 'ar', $arabic );
		$this::assertTrue( SScribe_RTL_Helper::is_rtl( $arabic ) );
		$this::assertSame( 'fr', $french );
		$this::assertSame( 'en', $default, 'All-languages exports use the TranslatePress default language.' );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_translatepress_translates_page_data_into_the_export_language(): void {
		self::load_translatepress();
		$collector = new SScribe_Page_Collector();

		$page_data = array(
			'title'        => 'About',
			'content'      => '<p>Hello world</p>',
			'excerpt'      => '',
			'permalink'    => 'https://example.test/about/',
			'language'     => 'ar',
			'word_count'   => 2,
			'reading_time' => 1,
			'breadcrumbs'  => array(
				array(
					'title' => 'About',
					'url'   => 'https://example.test/about/',
				),
			),
		);

		$translated = self::call_private( $collector, 'translate_page_data_with_translatepress', array( $page_data, 'ar' ) );

		$this::assertSame( '[ar] About', $translated['title'] );
		$this::assertSame( '[ar] <p>Hello world</p>', $translated['content'] );
		$this::assertSame( '', $translated['excerpt'] );
		$this::assertSame( 'https://example.test/about/?trp-lang=ar', $translated['permalink'] );
		$this::assertSame( '[ar] About', $translated['breadcrumbs'][0]['title'] );
		$this::assertSame( 'https://example.test/about/?trp-lang=ar', $translated['breadcrumbs'][0]['url'] );

		$untouched = self::call_private( $collector, 'translate_page_data_with_translatepress', array( $page_data, 'en_us' ) );
		$this::assertSame( $page_data, $untouched, 'The default language is exported as written.' );

		$all = self::call_private( $collector, 'translate_page_data_with_translatepress', array( $page_data, '' ) );
		$this::assertSame( $page_data, $all );
	}
}
