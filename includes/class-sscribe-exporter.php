<?php
/**
 * SScribe Exporter.
 *
 * @package SScribe_Export_Site_Pages
 * @license GPL v2 or later
 * @link    https://www.gnu.org/licenses/gpl-2.0.html
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SScribeVendor\PhpOffice\PhpWord\Element\Section;
use SScribeVendor\PhpOffice\PhpWord\Element\TextRun;
use SScribeVendor\PhpOffice\PhpWord\IOFactory;
use SScribeVendor\PhpOffice\PhpWord\PhpWord;
use SScribeVendor\PhpOffice\PhpWord\Settings;
use SScribeVendor\PhpOffice\PhpWord\Shared\Converter;
use SScribeVendor\PhpOffice\PhpWord\SimpleType\Jc;

/**
 * Legacy DOCX export engine.
 *
 * ## Why this class still exists
 *
 * `SScribe_Exporter` predates the format-specific exporter classes in
 * `includes/exporters/`. The four public format exporters each manage
 * their own format-native generation:
 *
 *  - `SScribe_DOCX_Exporter`     : PhpWord (DOCX)
 *  - `SScribe_PDF_Exporter`      : mPDF (PDF, via intermediate HTML)
 *  - `SScribe_HTML_Exporter`     : self-contained HTML page
 *  - `SScribe_Markdown_Exporter` : Markdown
 *
 * Each implements {@see SScribe_Exporter_Interface} and is the public
 * entry point for plugin consumers. This class is retained as the
 * legacy DOCX engine: `SScribe_DOCX_Exporter` delegates
 * `generate_docx()` and the format-option / cover-page / TOC / SEO /
 * breadcrumbs / child-pages helpers to it. A future refactor may
 * move those methods into `SScribe_DOCX_Exporter` directly, leaving
 * this class as a shared utilities bundle.
 *
 * ## Stability
 *
 * **Internal : not part of the public API.** No third-party code
 * should `new SScribe_Exporter()` or call its methods directly. The
 * stable contract is {@see SScribe_Exporter_Interface}; use the
 * `SScribe_Exporter_Factory` or `SScribe_Export_All_Formats_Wrapper`
 * to obtain a format exporter.
 *
 * Marked `final` to prevent extension : the dependency surface and
 * internal state are tightly coupled and not designed for subclassing.
 *
 * @package SScribe_Export_Site_Pages
 * @subpackage Exporters
 * @internal
 * @since   1.0.0
 */
final class SScribe_Exporter {

	/**
	 * Last error message from export operation.
	 *
	 * @var string
	 */
	private string $last_error = '';

	/**
	 * Logger instance.
	 *
	 * @var SScribe_Logger_Interface|null
	 */
	private ?SScribe_Logger_Interface $logger = null;

	/**
	 * Filesystem policy checker for the final DOCX path.
	 *
	 * @var SScribe_Filesystem
	 */
	private SScribe_Filesystem $filesystem;

	/**
	 * Content parser instance.
	 *
	 * @var SScribe_Content_Parser|null
	 */
	private ?SScribe_Content_Parser $parser = null;

	/**
	 * Content renderer for DOCX elements.
	 *
	 * @var SScribe_DOCX_Content_Renderer
	 */
	private SScribe_DOCX_Content_Renderer $content_renderer;

	/**
	 * Whether the document is RTL.
	 *
	 * @var bool
	 */
	private bool $is_rtl = false;

	/**
	 * Primary font name.
	 *
	 * @var string
	 */
	private string $font_name = 'Arial';

	/**
	 * RTL font name.
	 *
	 * @var string
	 */
	private string $rtl_font_name = 'Arial Unicode MS';

	/**
	 * Base font size.
	 *
	 * @var int
	 */
	private int $font_size = 11;

	/**
	 * Cached WordPress date format to avoid repeated get_option() calls.
	 *
	 * @var string
	 */
	private string $cached_date_format = '';

	/**
	 * Cached WordPress time format to avoid repeated get_option() calls.
	 *
	 * @var string
	 */
	private string $cached_time_format = '';

	/**
	 * Cached site name from get_bloginfo('name') to avoid repeated
	 * option reads + filter chains on every DOCX page.
	 *
	 * Populated by {@see self::get_site_name()} on first access.
	 *
	 * @var string
	 */
	private string $cached_site_name = '';

	/**
	 * Cached WP locale to avoid repeated get_option('WPLANG') + filter
	 * chains on every page (cover page + every content page both call
	 * get_section_settings() which reads the locale).
	 *
	 * Populated by {@see self::get_cached_locale()} on first access.
	 *
	 * @var string
	 */
	private string $cached_locale = '';

	/**
	 * Color palette for document styling.
	 *
	 * @var array<string, string>
	 */
	private array $colors = array(
		'primary'  => '4A8263',
		'heading'  => '122119',
		'body'     => '495057',
		'light_bg' => 'E8EFEB',
		'link'     => '2C6E8A',
		'code_bg'  => 'F5F6F8',
		'white'    => 'FFFFFF',
		'border'   => 'CCCCCC',
	);

	/**
	 * Shutdown handler registration flag.
	 *
	 * @var bool
	 */
	private static bool $shutdown_registered = false;

	/**
	 * Request-isolated PHPWord scratch directory.
	 *
	 * @var string
	 */
	private static string $phpword_temp_dir = '';

	/**
	 * Per-export format options forwarded by SScribe_DOCX_Exporter.
	 *
	 * The DOCX exporter calls set_format_options() with the resolved
	 * options map; methods like add_cover_page() and add_featured_image()
	 * read keys like `sscribe_docx_template` from this map.
	 *
	 * @var array<string, mixed>
	 */
	private array $format_options = array();

	/**
	 * Initialize the exporter.
	 *
	 * @param SScribe_Content_Parser|null        $parser           Content parser.
	 * @param SScribe_DOCX_Content_Renderer|null $content_renderer Content renderer.
	 * @param SScribe_Logger_Interface|null      $logger           Logger instance.
	 * @param SScribe_Filesystem|null            $filesystem       Filesystem policy checker.
	 */
	public function __construct(
		?SScribe_Content_Parser $parser = null,
		?SScribe_DOCX_Content_Renderer $content_renderer = null,
		?SScribe_Logger_Interface $logger = null,
		?SScribe_Filesystem $filesystem = null
	) {
		$this->parser     = $parser ?? new SScribe_Content_Parser();
		$this->logger     = $logger;
		$this->filesystem = $filesystem ?? new SScribe_Filesystem();

		/**
		 * Filter the DOCX color palette.
		 *
		 * @since 1.1.2
		 * @param array<string, string> $colors Color palette array.
		 */
		$filtered_colors = apply_filters( 'sscribe_docx_colors', $this->colors );
		$filtered_colors = is_array( $filtered_colors ) ? $filtered_colors : array();

		$defaults = array(
			'primary'  => '4A8263',
			'heading'  => '122119',
			'body'     => '495057',
			'light_bg' => 'E8EFEB',
			'link'     => '2C6E8A',
			'code_bg'  => 'F5F6F8',
			'white'    => 'FFFFFF',
			'border'   => 'CCCCCC',
		);
		foreach ( $defaults as $key => $default_value ) {
			$candidate = $filtered_colors[ $key ] ?? null;
			if ( ! is_string( $candidate ) || 1 !== preg_match( '/^[0-9A-Fa-f]{6}$/', $candidate ) ) {
				$this->colors[ $key ] = $default_value;
			} else {
				$this->colors[ $key ] = strtoupper( $candidate );
			}
		}

		$this->content_renderer = $content_renderer ?? new SScribe_DOCX_Content_Renderer(
			$this->parser,
			null,
			$this->colors,
			$this->is_rtl,
			$this->font_name,
			$this->font_size
		);

		if ( null !== $content_renderer ) {
			$this->content_renderer->sync_config(
				$this->colors,
				$this->is_rtl,
				$this->font_name,
				$this->font_size
			);
		}
	}

	/**
	 * Set per-format options forwarded by SScribe_DOCX_Exporter.
	 *
	 * Called once per export with the resolved options map (already filtered
	 * through `sscribe_export_options_docx`). Stored in the instance so
	 * conditional methods (cover page, featured image, TOC) can read it.
	 *
	 * @param array<string, mixed> $options Sanitized options map.
	 * @return void
	 */
	public function set_format_options( array $options ): void {
		$this->format_options = $options;
	}

	/**
	 * Read a format option with a default. Treats checkbox values as
	 * strings ("1" / ""), so callers should compare to "1".
	 *
	 * @param string $key     Option key.
	 * @param mixed  $default Default when key is absent.
	 * @return mixed
	 */
	private function get_format_option( string $key, $default = null ) {
		return array_key_exists( $key, $this->format_options ) ? $this->format_options[ $key ] : $default;
	}

	/**
	 * Normalize a value supplied through collection or filter hooks.
	 *
	 * @param mixed  $value   Candidate value.
	 * @param string $default Fallback value.
	 * @return string
	 */
	private function normalize_scalar_value( $value, string $default = '' ): string {
		if ( ! is_scalar( $value ) ) {
			return $default;
		}

		$value = trim( (string) $value );
		return '' !== $value ? $value : $default;
	}

	/**
	 * Normalize the collected page contract before it reaches strict PhpWord APIs.
	 *
	 * @param array<string, mixed> $page_data Raw page data.
	 * @return array<string, mixed>
	 */
	private function normalize_page_data( array $page_data ): array {
		$string_fields = array(
			'title',
			'content',
			'permalink',
			'slug',
			'author',
			'date_published',
			'date_modified',
			'featured_image_url',
			'featured_image_path',
			'excerpt',
			'language',
		);
		foreach ( $string_fields as $field ) {
			$page_data[ $field ] = $this->normalize_scalar_value( $page_data[ $field ] ?? '' );
		}

		$page_data['title']        = '' !== $page_data['title'] ? $page_data['title'] : __( 'Untitled', 'sscribe-export-site-pages' );
		$page_data['language']     = 1 === preg_match( '/^[A-Za-z]{2,8}(?:[-_][A-Za-z0-9]{1,8})*$/', $page_data['language'] ) ? $page_data['language'] : 'en';
		$page_data['id']           = isset( $page_data['id'] ) && is_numeric( $page_data['id'] ) ? absint( $page_data['id'] ) : 0;
		$page_data['word_count']   = isset( $page_data['word_count'] ) && is_numeric( $page_data['word_count'] ) ? max( 0, (int) $page_data['word_count'] ) : 0;
		$page_data['reading_time'] = isset( $page_data['reading_time'] ) && is_numeric( $page_data['reading_time'] ) ? max( 0.0, (float) $page_data['reading_time'] ) : 0.0;

		$seo = isset( $page_data['seo'] ) && is_array( $page_data['seo'] ) ? $page_data['seo'] : array();
		foreach ( array( 'source', 'meta_title', 'meta_description', 'focus_keyword', 'canonical_url' ) as $field ) {
			$seo[ $field ] = $this->normalize_scalar_value( $seo[ $field ] ?? '' );
		}
		$page_data['seo'] = $seo;

		$breadcrumbs = isset( $page_data['breadcrumbs'] ) && is_array( $page_data['breadcrumbs'] ) ? $page_data['breadcrumbs'] : array();
		$page_data['breadcrumbs'] = array();
		foreach ( array_slice( $breadcrumbs, 0, 100 ) as $crumb ) {
			if ( ! is_array( $crumb ) ) {
				continue;
			}
			$page_data['breadcrumbs'][] = array(
				'title' => $this->normalize_scalar_value( $crumb['title'] ?? '' ),
				'url'   => $this->normalize_scalar_value( $crumb['url'] ?? '' ),
			);
		}

		$children = isset( $page_data['children'] ) && is_array( $page_data['children'] ) ? $page_data['children'] : array();
		$page_data['children'] = $this->normalize_child_pages( $children );

		return $page_data;
	}

	/**
	 * Normalize the bounded child-page tree used by the DOCX appendix.
	 *
	 * @param array<int|string, mixed> $children Raw child rows.
	 * @param int                      $depth    Current depth.
	 * @return array<int, array<string, mixed>>
	 */
	private function normalize_child_pages( array $children, int $depth = 0 ): array {
		if ( $depth >= 3 ) {
			return array();
		}

		$normalized = array();
		foreach ( array_slice( $children, 0, 50 ) as $child ) {
			if ( ! is_array( $child ) ) {
				continue;
			}

			$nested       = isset( $child['children'] ) && is_array( $child['children'] ) ? $child['children'] : array();
			$normalized[] = array(
				'title'    => $this->normalize_scalar_value( $child['title'] ?? '' ),
				'url'      => $this->normalize_scalar_value( $child['url'] ?? '' ),
				'children' => $this->normalize_child_pages( $nested, $depth + 1 ),
			);
		}

		return $normalized;
	}

	/**
	 * Safe preg_replace wrapper.
	 *
	 * @param array|string $pattern     Regex pattern.
	 * @param array|string $replacement Replacement.
	 * @param string       $subject     Input string.
	 * @return string
	 */
	private function safe_preg_replace( array|string $pattern, array|string $replacement, string $subject ): string {
		$result = preg_replace( $pattern, $replacement, $subject );
		return is_string( $result ) ? $result : $subject;
	}

	/**
	 * Sanitize text for safe XML embedding.
	 *
	 * @param string $text Input text.
	 * @return string Sanitized text.
	 */
	private function safe_text( string $text ): string {
		$text = (string) $text;

		$cleaned = @iconv( 'UTF-8', 'UTF-8//IGNORE', $text );
		if ( false !== $cleaned ) {
			$text = $cleaned;
		}

		$text = $this->safe_preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text );

		$text = $this->safe_preg_replace( '/[\x{FFFE}\x{FFFF}]/u', '', $text );

		$text = $this->safe_preg_replace( '/[\x{FDD0}-\x{FDEF}]/u', '', $text );

		$text = $this->safe_preg_replace( '/\xED[\xA0-\xBF][\x80-\xBF]/', '', $text );

		$text = $this->safe_preg_replace( '/[\x{200B}\x{FEFF}\x{00AD}]/u', '', $text );

		$text = str_replace( array( "\r\n", "\r" ), "\n", $text );

		$text = str_replace( "\x0C", '', $text );

		if ( SScribe_Helpers::mb_strlen( $text ) > 2048 && $this->is_machine_style_string( $text ) ) {
			$original_length = SScribe_Helpers::mb_strlen( $text );
			$text = SScribe_Helpers::mb_substr( $text, 0, 2048 );
			$this->get_logger()->warning(
				'Text truncated in safe_text : long machine-style string detected',
				array(
					'original_length' => $original_length,
					'truncated_to'    => 2048,
				)
			);
		}

		return $text;
	}

	/**
	 * Heuristic: does this string look like a machine-generated payload
	 * (URL, base64 blob, hash) that should be truncated, rather than natural
	 * human text in a script that does not use spaces (Arabic, Hebrew, CJK)?
	 *
	 * Signals that increase the score: contains a space, contains a high
	 * proportion of ASCII letters/digits/symbols, or contains a URL scheme
	 * (http://, https://, data:). Returns true only if the string is
	 * predominantly Latin/machine content.
	 *
	 * @param string $text Text to test.
	 * @return bool True if it looks like a machine-style string suitable for truncation.
	 */
	private function is_machine_style_string( string $text ): bool {

		if ( false !== SScribe_Helpers::mb_strpos( $text, ' ', 0 ) ) {
			return true;
		}

		if ( preg_match( '#^[a-z][a-z0-9+.\-]*://#i', $text ) ) {
			return true;
		}

		$ascii_machine_count = preg_match_all( '/[A-Za-z0-9=\/\+_\-:.;?&%@#]/', $text );
		$total_length        = SScribe_Helpers::mb_strlen( $text );

		if ( $total_length > 0 && ( $ascii_machine_count / $total_length ) > 0.6 ) {
			return true;
		}

		return false;
	}

	/**
	 * Validate and sanitize a URL.
	 *
	 * @param string $url URL to validate.
	 * @return string Sanitized URL.
	 */
	private function validate_url( string $url ): string {
		if ( empty( $url ) ) {
			return '';
		}

		if ( str_starts_with( $url, '#' ) ) {
			return $url;
		}

		if ( str_starts_with( $url, '/' ) ) {

			$parsed = wp_parse_url( site_url( $url ) );
			if ( ! $parsed ) {
				return '';
			}
			$scheme   = $parsed['scheme'] ?? 'https';
			$host     = $parsed['host'] ?? '';
			$port     = ! empty( $parsed['port'] ) ? ':' . $parsed['port'] : '';
			$path     = $parsed['path'] ?? '';
			$query    = $parsed['query'] ?? '';
			$fragment = $parsed['fragment'] ?? '';

			$safe_path  = implode(
				'/',
				array_map(
					function ( $segment ) {
						return rawurlencode( rawurldecode( $segment ) );
					},
					explode( '/', $path )
				)
			);
			$safe_query = '';
			if ( ! empty( $query ) ) {
				$params = array();
				parse_str( $query, $params );
				$safe_query = '?' . http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );
			}
			$safe_fragment = ! empty( $fragment ) ? '#' . rawurlencode( rawurldecode( $fragment ) ) : '';

			return $scheme . '://' . $host . $port . $safe_path . $safe_query . $safe_fragment;
		}

		$parsed_scheme = wp_parse_url( $url, PHP_URL_SCHEME );
		$scheme        = strtolower( ( false === $parsed_scheme || null === $parsed_scheme ) ? '' : $parsed_scheme );

		if ( in_array( $scheme, array( 'http', 'https', 'mailto', 'tel' ), true ) ) {

			$host = wp_parse_url( $url, PHP_URL_HOST );
			if ( $host && $this->is_ip_blocked( $host ) ) {
				$this->get_logger()->warning(
					'Blocked SSRF attempt: internal IP range',
					array(
						'url'  => $url,
						'host' => $host,
					)
				);
				return '';
			}
			return esc_url_raw( $url );
		}

		return '';
	}

	/**
	 * Check if host is a blocked internal/private IP address.
	 *
	 * For document generation, URLs are only added as text links and
	 * never fetched. Therefore, we only block explicit private/reserved
	 * IPs without DNS resolution. External DNS lookups (e.g., Google
	 * DNS) would be disproportionate for this use case.
	 *
	 * Note: handles dotted-quad (`127.0.0.1`), IPv6 (`::1`), and
	 * alternative single-integer forms (`2130706433` == `127.0.0.1`,
	 * `0x7f000001` == `127.0.0.1`). Other forms (octal, IPv4-mapped
	 * IPv6) are caught by `FILTER_VALIDATE_IP` returning false and
	 * then falling through to the `ip2long` long-form check below.
	 *
	 * @param string $host Hostname or IP to check.
	 * @return bool True if blocked.
	 */
	private function is_ip_blocked( string $host ): bool {

		if ( filter_var( $host, FILTER_VALIDATE_IP ) !== false ) {
			return filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) === false;
		}

		$normalized = $this->normalize_ip_literal( $host );
		if ( null !== $normalized ) {
			return filter_var( $normalized, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) === false;
		}

		return false;
	}

	/**
	 * Convert a numeric or hex IP literal to its canonical dotted-quad form.
	 *
	 * Handles the well-known alternative forms browsers and HTTP
	 * libraries sometimes accept:
	 *  - Pure decimal integer: `2130706433` → `127.0.0.1`
	 *  - Pure hex integer: `0x7f000001` → `127.0.0.1`
	 *
	 * Returns null for non-numeric input or for values that don't fit
	 * in 32 bits (which can't be a valid IPv4 address).
	 *
	 * @param string $host Hostname or IP literal to inspect.
	 * @return string|null Canonical IP, or null if not a numeric literal.
	 */
	private function normalize_ip_literal( string $host ): ?string {
		$candidate = trim( $host );
		if ( '' === $candidate ) {
			return null;
		}

		if ( preg_match( '/^0x[0-9a-fA-F]+$/', $candidate ) ) {
			$value = intval( substr( $candidate, 2 ), 16 );
		} elseif ( ctype_digit( $candidate ) ) {

			$value = (int) $candidate;
		} else {
			return null;
		}

		if ( $value < 0 || $value > 0xFFFFFFFF ) {
			return null;
		}

		return long2ip( $value );
	}

	/**
	 * Check if page data indicates an RTL document.
	 *
	 * @param array $page_data Page data.
	 * @return bool
	 */
	private function is_rtl_document( array $page_data ): bool {
		if ( ! isset( $page_data['language'] ) ) {
			$this->get_logger()->warning(
				'Missing language key in page_data, defaulting to LTR',
				array( 'page_id' => $page_data['id'] ?? 0 )
			);
		}
		return SScribe_RTL_Helper::is_rtl( $page_data['language'] ?? 'en' );
	}

	/**
	 * Add complex script settings to font definition for RTL.
	 *
	 * @param array $font_def Font definition.
	 * @return array Modified font definition.
	 */
	private function with_complex_script( array $font_def ): array {
		if ( $this->is_rtl ) {
			if ( ! isset( $font_def['complexScript'] ) ) {
				$font_def['complexScript'] = true;
			}
			if ( ! isset( $font_def['rtl'] ) ) {
				$font_def['rtl'] = true;
			}
		}
		return $font_def;
	}

	/**
	 * Get paragraph style with RTL adjustments.
	 *
	 * @param array $base_style Base style array.
	 * @return array Modified style.
	 */
	private function get_para_style( array $base_style = array() ): array {
		if ( $this->is_rtl ) {
			$base_style['bidi'] = true;
			if ( ! isset( $base_style['alignment'] ) ) {
				$base_style['alignment'] = Jc::START;
			}
		}
		return $base_style;
	}

	/**
	 * Generate a DOCX file from page data.
	 *
	 * @param array  $page_data Page data array. See SScribePageData type.
	 * @type int       $id                  Page ID.
	 *     @type string    $title               Page title (HTML-decoded).
	 *     @type string    $content             Processed HTML content.
	 *     @type string    $raw_content         Raw post content.
	 *     @type string    $excerpt             Page excerpt.
	 *     @type string    $permalink           Full permalink URL.
	 *     @type string    $slug                URL-friendly slug.
	 *     @type string    $author              Author display name.
	 *     @type string    $date_published      Formatted publish date.
	 *     @type string    $date_modified       Formatted last modified date.
	 *     @type string    $featured_image_url  Featured image URL.
	 *     @type string    $featured_image_path Local path to featured image.
	 *     @type int       $word_count          Word count estimate.
	 *     @type float     $reading_time       Reading time in minutes.
	 *     @type array     $breadcrumbs         Breadcrumb trail array.
	 *     @type array     $children            Child page data array.
	 *     @type string    $language            Language code (e.g. 'en').
	 *     @type int       $parent_id          Parent page ID.
	 *     @type array     $seo                SEO data array from SEO_Reader.
	 * }
	 * @param string $output_dir Output directory path.
	 * @param int    $index     Current page index.
	 * @param int    $total     Total number of pages.
	 * @return string|false Output file path or false on failure.
	 * @throws \RuntimeException If DOCX generation fails integrity checks.
	 */
	public function generate_docx( array $page_data, string $output_dir, int $index = 0, int $total = 0 ): string|false {
		if ( ! self::$shutdown_registered ) {
			self::$shutdown_registered = true;
			register_shutdown_function(
				static function (): void {
					$error = error_get_last();
					$fatal_types = array( E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR );
					if ( $error && in_array( $error['type'], $fatal_types, true ) && '' !== self::$phpword_temp_dir ) {
						SScribe_Security::delete_directory( self::$phpword_temp_dir );
					}
				}
			);
		}

		if ( empty( $page_data ) || ! is_dir( $output_dir ) ) {
			$this->cleanup_phpword_temp_files();
			return false;
		}

		$page_data = $this->normalize_page_data( $page_data );

		if ( ! is_writable( $output_dir ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
			$this->last_error = 'Output directory is not writable.';
			$this->get_logger()->error(
				'DOCX generation failed: output directory not writable',
				array(
					'page_id'    => $page_data['id'] ?? 0,
					'output_dir' => $output_dir,
				)
			);
			$this->cleanup_phpword_temp_files();
			return false;
		}

		$this->last_error = '';

		$output_path = '';

		try {
			if ( ! class_exists( 'ZipArchive' ) ) {
				throw new \RuntimeException( 'The ZipArchive PHP extension is required to generate DOCX files.' );
			}

			if ( class_exists( '\SScribeVendor\PhpOffice\PhpWord\Settings' ) ) {
				\SScribeVendor\PhpOffice\PhpWord\Settings::setZipClass(
					\SScribeVendor\PhpOffice\PhpWord\Settings::ZIPARCHIVE
				);

				$phpword_tempdir = self::get_phpword_temp_dir();
				if ( '' === $phpword_tempdir || ! function_exists( 'wp_mkdir_p' ) || ( ! is_dir( $phpword_tempdir ) && ! wp_mkdir_p( $phpword_tempdir ) ) || ! wp_is_writable( $phpword_tempdir ) ) {
					throw new \RuntimeException( 'Private storage is unavailable for DOCX temporary files.' );
				}
				SScribe_Private_Storage::harden_directory( untrailingslashit( $phpword_tempdir ) );
				\SScribeVendor\PhpOffice\PhpWord\Settings::setTempDir( $phpword_tempdir );
			}

			if ( ! class_exists( '\SScribeVendor\PhpOffice\PhpWord\PhpWord' ) ) {
				throw new \RuntimeException( 'The PhpWord library is required to generate DOCX files.' );
			}

			\SScribeVendor\PhpOffice\PhpWord\Settings::setOutputEscapingEnabled( true );

			$php_word = new \SScribeVendor\PhpOffice\PhpWord\PhpWord();

			$this->get_logger()->debug(
				'DOCX generation started',
				array(
					'page_id'       => $page_data['id'] ?? 0,
					'memory_before' => size_format( memory_get_usage( true ) ),
				)
			);

			$this->font_name = 'Arial';
			$this->is_rtl    = $this->is_rtl_document( $page_data );

			if ( $this->is_rtl ) {
				$this->font_name = $this->rtl_font_name;
			}

			$this->content_renderer->sync_config(
				$this->colors,
				$this->is_rtl,
				$this->font_name,
				$this->font_size
			);

			$this->set_document_properties( $php_word, $page_data );

			$this->set_default_styles( $php_word );

			$this->define_styles( $php_word );

			$template = (string) $this->get_format_option( 'sscribe_docx_template', 'default' );
			if ( 'minimal' !== $template ) {

				$cover_settings              = $this->get_section_settings( $this->is_rtl );
				$cover_settings['vAlign']    = 'center';
				$cover = $php_word->addSection( $cover_settings );
				try {
					$this->add_cover_page( $cover, $page_data );
				} catch ( \Throwable $e ) {
					$this->get_logger()->warning(
						'Cover page skipped',
						array(
							'page_id'   => $page_data['id'] ?? 0,
							'exception' => get_class( $e ),
						)
					);
				}
			}

			$content_section = $php_word->addSection( $this->get_section_settings( $this->is_rtl ) );

			try {
				$this->add_header_footer( $content_section, $page_data );
			} catch ( \Throwable $e ) {
				$this->get_logger()->warning(
					'Header/footer skipped',
					array(
						'page_id'   => $page_data['id'] ?? 0,
						'exception' => get_class( $e ),
					)
				);
			}

			$this->add_featured_image( $content_section, $page_data );

			try {
				$this->add_page_info_table( $content_section, $page_data );
			} catch ( \Throwable $e ) {
				$this->get_logger()->warning(
					'Page info table skipped',
					array(
						'page_id'   => $page_data['id'] ?? 0,
						'exception' => get_class( $e ),
					)
				);
			}

			try {
				$this->add_seo_section( $content_section, $page_data );
			} catch ( \Throwable $e ) {
				$this->get_logger()->warning(
					'SEO section skipped',
					array(
						'page_id'   => $page_data['id'] ?? 0,
						'exception' => get_class( $e ),
					)
				);
			}

			try {
				$this->add_breadcrumbs( $content_section, $page_data );
			} catch ( \Throwable $e ) {
				$this->get_logger()->warning(
					'Breadcrumbs skipped',
					array(
						'page_id'   => $page_data['id'] ?? 0,
						'exception' => get_class( $e ),
					)
				);
			}

			$this->content_renderer->add_main_content( $content_section, $page_data );

			try {
				$this->add_child_pages( $content_section, $page_data );
			} catch ( \Throwable $e ) {
				$this->get_logger()->warning(
					'Child pages skipped',
					array(
						'page_id'   => $page_data['id'] ?? 0,
						'exception' => get_class( $e ),
					)
				);
			}

			$filename    = \SScribe_Exporter_Factory::build_filename( $page_data, $index, $total, 'docx' );
			$output_path = trailingslashit( $output_dir ) . $filename;
			if ( SScribe_Filesystem::SSCRIBE_PATH_ALLOWED !== $this->filesystem->is_path_safe_for_write( $output_path ) || is_link( $output_path ) ) {
				throw new \RuntimeException( 'DOCX output path is outside the plugin-owned export directory.' );
			}

			$writer = IOFactory::createWriter( $php_word, 'Word2007' );
			$writer->save( $output_path );

			$this->cleanup_phpword_temp_files();

			$file_size = @filesize( $output_path );
			$min_size  = 8192;

			$this->get_logger()->debug(
				'DOCX saved to disk',
				array(
					'page_id'     => $page_data['id'] ?? 0,
					'output_path' => $output_path,
					'file_size'   => false !== $file_size ? size_format( $file_size ) : 'unknown',
					'memory_now'  => size_format( memory_get_usage( true ) ),
				)
			);

			if ( false === $file_size || $file_size < $min_size ) {
				wp_delete_file( $output_path );
				unset( $writer, $php_word );
				throw new \RuntimeException( 'DOCX file size below minimum threshold' );
			}

			$zip_check = new \ZipArchive();
			if ( true !== $zip_check->open( $output_path ) ) {
				wp_delete_file( $output_path );
				unset( $writer, $php_word );
				throw new \RuntimeException( 'DOCX failed ZipArchive integrity check after write' );
			}
			$has_document = false !== $zip_check->locateName( 'word/document.xml' );
			$has_types    = false !== $zip_check->locateName( '[Content_Types].xml' );
			$zip_check->close();
			unset( $zip_check );

			if ( ! $has_document || ! $has_types ) {
				wp_delete_file( $output_path );
				unset( $writer, $php_word );
				throw new \RuntimeException( 'DOCX missing required archive members' );
			}

			if ( ! ( defined( 'SSCRIBE_DEBUG' ) && SSCRIBE_DEBUG ) ) {
				unset( $writer, $php_word );
				return $output_path;
			}

			$xml_valid = true;
			$zip_xml   = null;
			if ( $has_document ) {
				$zip_xml = new \ZipArchive();
				if ( true !== $zip_xml->open( $output_path ) ) {
					$zip_xml   = null;
					$xml_valid = false;
				} else {
					$doc_xml = $zip_xml->getFromName( 'word/document.xml' );
					if ( false !== $doc_xml && ! empty( $doc_xml ) ) {
						$prev_xml_errors = libxml_use_internal_errors( true );
						try {
							$test_doc     = new \DOMDocument();
							$parse_result = $test_doc->loadXML( $doc_xml );
							$xml_errors   = libxml_get_errors();
							foreach ( $xml_errors as $xml_error ) {
								if ( LIBXML_ERR_FATAL === $xml_error->level ) {
									$xml_valid = false;
									$this->get_logger()->error(
										'DOCX XML validation failed',
										array(
											'page_id'   => $page_data['id'] ?? 0,
											'xml_error' => trim( $xml_error->message ),
											'xml_line'  => $xml_error->line,
										)
									);
									break;
								}
							}
							if ( false === $parse_result ) {
								$xml_valid = false;
							}
						} finally {
							libxml_clear_errors();
							libxml_use_internal_errors( $prev_xml_errors );
						}
						unset( $test_doc, $doc_xml );
					}
				}
				if ( null !== $zip_xml ) {
					$zip_xml->close();
					$zip_xml = null;
				}
			}

			if ( ! $xml_valid ) {

				unset( $writer, $php_word );
				throw new \RuntimeException( 'DOCX integrity check failed: XML validation error' );
			}

			unset( $writer, $php_word );

			return $output_path;

		} catch ( \Throwable $e ) {
			$this->cleanup_phpword_temp_files();

			if ( isset( $writer ) ) {
				unset( $writer );
			}
			if ( isset( $php_word ) ) {
				unset( $php_word );
			}

			if ( ! empty( $output_path ) && file_exists( $output_path ) ) {
				wp_delete_file( $output_path );
			}

			$memory_context = sprintf(
				'Memory: %s used / %s limit (peak: %s)',
				size_format( memory_get_usage( true ) ),
				ini_get( 'memory_limit' ),
				size_format( memory_get_peak_usage( true ) )
			);

			$exception_class = (string) get_class( $e );
			$raw_message     = $e->getMessage();

			$error_lower = strtolower( $raw_message );
			if ( str_contains( $error_lower, 'memory' ) || str_contains( $error_lower, 'allocated' ) ) {
				$error_message = 'Memory exhausted - ' . $memory_context;
			} elseif ( ! empty( $raw_message ) ) {
				$error_message = sprintf( '%s: %s | %s', $exception_class, $raw_message, $memory_context );
			} else {

				$error_message = sprintf( '%s (no message) | %s', $exception_class, $memory_context );
			}

			$this->get_logger()->error(
				'DOCX generation failed',
				array(
					'page_id' => $page_data['id'] ?? 0,
					'error'   => $error_message,
				)
			);

			$this->last_error = $error_message;

			return false;
		}
	}

	/**
	 * Clean up PHPWord temporary files.
	 *
	 * PHPWord creates temp files during document saving that can persist
	 * if a fatal error occurs mid-write.
	 *
	 * @return void
	 */
	private function cleanup_phpword_temp_files(): void {
		$temp_dir = self::get_phpword_temp_dir();
		if ( '' === $temp_dir || ! is_dir( $temp_dir ) ) {
			return;
		}

		if ( SScribe_Security::delete_directory( $temp_dir ) ) {
			self::$phpword_temp_dir = '';
		}
	}

	/**
	 * Get the plugin-owned PHPWord scratch directory.
	 *
	 * @return string Absolute directory path, or an empty string on failure.
	 */
	private static function get_phpword_temp_dir(): string {
		if ( '' !== self::$phpword_temp_dir ) {
			return self::$phpword_temp_dir;
		}

		$root = SScribe_Private_Storage::get_subdirectory( 'phpword-scratch' );
		if ( '' === $root ) {
			return '';
		}

		try {
			$token = bin2hex( random_bytes( 12 ) );
		} catch ( \Throwable $e ) {
			return '';
		}

		self::$phpword_temp_dir = trailingslashit( $root ) . 'run-' . $token . '/';
		return self::$phpword_temp_dir;
	}

	/**
	 * Set document metadata properties.
	 *
	 * @param PhpWord $php_word  PhpWord instance.
	 * @param array   $page_data Page data.
	 * @return void
	 */
	private function set_document_properties( PhpWord $php_word, array $page_data ): void {
		$properties = $php_word->getDocInfo();
		$properties->setCreator( 'SScribe by Simplix Innovations' );

		$blog_name_decoded = $this->get_site_name();
		$properties->setCompany( $blog_name_decoded );

		$title         = $page_data['title'] ?? '';
		$title_decoded = html_entity_decode( ( is_string( $title ) ? $title : '' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$properties->setTitle( $title_decoded );

		$permalink      = $page_data['permalink'] ?? '';
		$permalink_safe = is_string( $permalink ) ? esc_url_raw( $permalink ) : '';
		$properties->setDescription(
			sprintf(
				/* translators: %s: site permalink of the exported page. */
				__( 'Exported from %s', 'sscribe-export-site-pages' ),
				$permalink_safe
			)
		);

		$author          = $page_data['author'] ?? '';
		$author_stripped = is_string( $author ) ? wp_strip_all_tags( $author ) : '';
		$properties->setLastModifiedBy( $author_stripped );
	}

	/**
	 * Get the last error message.
	 *
	 * @return string
	 */
	public function get_last_error(): string {
		return $this->last_error;
	}

	/**
	 * Set default font and paragraph styles.
	 *
	 * @param PhpWord $php_word PhpWord instance.
	 * @return void
	 */
	private function set_default_styles( PhpWord $php_word ): void {
		$php_word->setDefaultFontName( $this->font_name );
		$php_word->setDefaultFontSize( $this->font_size );

		if ( $this->is_rtl ) {
			$php_word->setDefaultParagraphStyle(
				array(
					'spaceAfter'  => Converter::pointToTwip( 6 ),
					'spaceBefore' => Converter::pointToTwip( 2 ),
					'lineHeight'  => 1.15,
					'bidi'        => true,
				)
			);
		} else {
			$php_word->setDefaultParagraphStyle(
				array(
					'spaceAfter'  => Converter::pointToTwip( 6 ),
					'spaceBefore' => Converter::pointToTwip( 2 ),
					'lineHeight'  => 1.15,
				)
			);
		}
	}

	/**
	 * Define custom paragraph and heading styles.
	 *
	 * @param PhpWord $php_word PhpWord instance.
	 * @return void
	 */
	private function define_styles( PhpWord $php_word ): void {

		$heading_sizes = array(
			1 => 24,
			2 => 20,
			3 => 16,
			4 => 14,
			5 => 12,
			6 => 11,
		);
		for ( $i = 1; $i <= 6; $i++ ) {
			$heading_font = array(
				'name'  => $this->font_name,
				'size'  => $heading_sizes[ $i ],
				'bold'  => true,
				'color' => $this->colors['heading'],
			);
			if ( $this->is_rtl ) {
				$heading_font['bidi']          = true;
				$heading_font['rtl']           = true;
				$heading_font['complexScript'] = true;
			}
			$php_word->addTitleStyle(
				$i,
				$heading_font,
				$this->get_para_style(
					array(
						'spaceBefore' => Converter::pointToTwip( $i <= 2 ? 18 : 12 ),
						'spaceAfter'  => Converter::pointToTwip( 6 ),
						'keepNext'    => true,
					)
				)
			);
		}

		$blockquote_style = array(
			'spaceBefore' => Converter::pointToTwip( 6 ),
			'spaceAfter'  => Converter::pointToTwip( 6 ),
		);

		if ( $this->is_rtl ) {
			$blockquote_style['bidi']             = true;
			$blockquote_style['indentation']      = array( 'right' => Converter::cmToTwip( 1 ) );
			$blockquote_style['borderRightSize']  = 12;
			$blockquote_style['borderRightColor'] = $this->colors['primary'];
		} else {
			$blockquote_style['indentation']     = array( 'left' => Converter::cmToTwip( 1 ) );
			$blockquote_style['borderLeftSize']  = 12;
			$blockquote_style['borderLeftColor'] = $this->colors['primary'];
		}

		$php_word->addParagraphStyle( 'Blockquote', $this->get_para_style( $blockquote_style ) );

		$codeblock_style = array(
			'spaceBefore' => Converter::pointToTwip( 6 ),
			'spaceAfter'  => Converter::pointToTwip( 6 ),
		);

		if ( $this->is_rtl ) {
			$codeblock_style['bidi']        = true;
			$codeblock_style['indentation'] = array( 'right' => Converter::cmToTwip( 0.5 ) );
		} else {
			$codeblock_style['indentation'] = array( 'left' => Converter::cmToTwip( 0.5 ) );
		}

		if ( $this->is_rtl ) {
			$codeblock_style['complexScript'] = true;
			$codeblock_style['rtl']           = true;
		}

		$codeblock_style['shading'] = array(
			'fill' => 'F2F2F2',
		);

		$php_word->addParagraphStyle( 'CodeBlock', $this->get_para_style( $codeblock_style ) );

		$list_style = array(
			'spaceBefore' => Converter::pointToTwip( 2 ),
			'spaceAfter'  => Converter::pointToTwip( 2 ),
			'lineHeight'  => 1.15,
		);
		if ( $this->is_rtl ) {
			$list_style['bidi'] = true;
		}
		$php_word->addParagraphStyle( 'ListBullet', $this->get_para_style( $list_style ) );
		$php_word->addParagraphStyle( 'ListNumber', $this->get_para_style( $list_style ) );
	}

	/**
	 * Get section settings for document layout.
	 *
	 * @param bool $is_rtl Whether RTL mode.
	 * @return array Section settings.
	 */
	private function get_section_settings( bool $is_rtl = false ): array {

		$locale       = $this->get_cached_locale();
		$us_like_locales = array( 'en_US', 'en_CA', 'en_MX', 'fil_PH' );
		$is_us_letter    = in_array( $locale, $us_like_locales, true )
			|| str_starts_with( $locale, 'en_US' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_dir
		$page_w = $is_us_letter ? Converter::inchToTwip( 8.5 ) : Converter::inchToTwip( 8.27 );
		$page_h = $is_us_letter ? Converter::inchToTwip( 11 ) : Converter::inchToTwip( 11.69 );

		$settings = array(
			'pageSizeW'    => $page_w,
			'pageSizeH'    => $page_h,
			'marginTop'    => Converter::inchToTwip( 1 ),
			'marginBottom' => Converter::inchToTwip( 1 ),
			'marginLeft'   => Converter::inchToTwip( 1 ),
			'marginRight'  => Converter::inchToTwip( 1 ),
			'headerHeight' => Converter::inchToTwip( 0.5 ),
			'footerHeight' => Converter::inchToTwip( 0.5 ),
		);

		if ( $is_rtl ) {
			$settings['bidi'] = true;
		}

		return apply_filters( 'sscribe_docx_section_settings', $settings, $is_rtl );
	}

	/**
	 * Add cover page to the document.
	 *
	 * @param Section $section   Document section.
	 * @param array   $page_data Page data.
	 * @return void
	 */
	private function add_cover_page( Section $section, array $page_data ): void {

		$section->addTextBreak( 2 );

		$table = $section->addTable(
			array(
				'borderSize' => 0,
				'width'      => Converter::inchToTwip( 6.5 ),
			)
		);
		$table->addRow();
		$cell = $table->addCell(
			Converter::inchToTwip( 6.5 ),
			array(
				'bgColor' => $this->colors['primary'],
				'valign'  => 'center',
			)
		);
		$cell->addText(
			$this->safe_text(
				sprintf(
					/* translators: %s: site name */
					__( '%s | EXTERNAL AUDIT AND DOCUMENTATION', 'sscribe-export-site-pages' ),
					$this->get_site_name()
				)
			),
			array(
				'name'  => $this->font_name,
				'size'  => 10,
				'color' => $this->colors['white'],
				'bold'  => true,
			),
			$this->get_para_style(
				array(
					'alignment'   => Jc::CENTER,
					'spaceBefore' => Converter::pointToTwip( 12 ),
					'spaceAfter'  => Converter::pointToTwip( 12 ),
				)
			)
		);

		$section->addTextBreak( 4 );

		$cover_title = $this->safe_text( (string) ( $page_data['title'] ?? '' ) );

		$cover_title_font = array(
			'name'    => $this->font_name,
			'size'    => 28,
			'bold'    => true,
			'color'   => $this->colors['heading'],
			'allCaps' => ! $this->is_rtl,
		);
		$section->addText(
			$cover_title,
			$this->with_complex_script( $cover_title_font ),
			$this->get_para_style( array( 'alignment' => Jc::CENTER ) )
		);

		$section->addTextBreak( 1 );

		$permalink = $this->validate_url( $page_data['permalink'] );
		if ( ! empty( $permalink ) ) {
			$section->addLink(
				$permalink,
				$this->safe_text( rawurldecode( $permalink ) ),
				array(
					'name'      => $this->font_name,
					'size'      => 12,
					'color'     => $this->colors['link'],
					'underline' => 'single',
				),
				$this->get_para_style( array( 'alignment' => Jc::CENTER ) )
			);
		}

		$section->addTextBreak( 2 );

		$info_table = $section->addTable(
			array(
				'borderSize'  => 12,
				'borderColor' => $this->colors['border'],
				'cellMargin'  => Converter::cmToTwip( 0.2 ),
			)
		);
		$info_table->addRow();

		$meta_cell = $info_table->addCell( Converter::inchToTwip( 6.5 ), array( 'bgColor' => $this->colors['light_bg'] ) );
		$meta_cell->addText(
			__( 'DOCUMENT BLUEPRINT OVERVIEW', 'sscribe-export-site-pages' ),
			array(
				'name'  => $this->font_name,
				'size'  => 11,
				'bold'  => true,
				'color' => $this->colors['heading'],
			),
			$this->get_para_style( array( 'alignment' => Jc::CENTER ) )
		);
		$lang_display = ! empty( $page_data['language'] ) ? strtoupper( $page_data['language'] ) : __( 'All Languages', 'sscribe-export-site-pages' );
		$meta_cell->addText(
			$this->safe_text(
				/* translators: %s: language code */
				sprintf( __( 'Target Language: %s', 'sscribe-export-site-pages' ), $lang_display )
			),
			array(
				'name'  => $this->font_name,
				'size'  => 10,
				'color' => $this->colors['body'],
			),
			$this->get_para_style( array( 'alignment' => Jc::CENTER ) )
		);
		$meta_cell->addText(
			sprintf(
				/* translators: %s: export date */
				__( 'Extracted Date: %s', 'sscribe-export-site-pages' ),
				wp_date( $this->get_wp_datetime_formats()['date_format'] . ' ' . $this->get_wp_datetime_formats()['time_format'] )
			),
			array(
				'name'  => $this->font_name,
				'size'  => 10,
				'color' => $this->colors['body'],
			),
			$this->get_para_style( array( 'alignment' => Jc::CENTER ) )
		);

		if ( ! empty( $page_data['breadcrumbs'] ) ) {
			$breadcrumb_text = implode(
				' > ',
				array_map(
					function ( $c ) {
						return $this->safe_text( $c['title'] ?? '' );
					},
					$page_data['breadcrumbs']
				)
			);
			$meta_cell->addTextBreak( 1 );
			$meta_cell->addText(
				$this->safe_text(
					/* translators: %s: breadcrumb path */
					sprintf( __( 'Site Path: %s', 'sscribe-export-site-pages' ), $breadcrumb_text )
				),
				$this->with_complex_script(
					array(
						'name'   => $this->font_name,
						'size'   => 9,
						'color'  => $this->colors['body'],
						'italic' => true,
					)
				),
				$this->get_para_style( array( 'alignment' => Jc::CENTER ) )
			);
		}

		if ( '1' !== (string) $this->get_format_option( 'sscribe_docx_include_toc', '1' ) ) {
			return;
		}

		$content       = $page_data['content'] ?? '';
		$word_count    = $page_data['word_count'] ?? 0;
		$heading_count = 0;
		if ( function_exists( 'preg_match_all' ) ) {
			preg_match_all( '/<h[1-6][^>]*>/i', $content, $heading_matches );
			$heading_count = count( $heading_matches[0] );
		}
		if ( $heading_count <= 1 || $word_count < 150 ) {
			return;
		}

		$section->addPageBreak();

		$section->addText(
			__( 'TABLE OF CONTENTS', 'sscribe-export-site-pages' ),
			array(
				'name'  => $this->font_name,
				'size'  => 16,
				'bold'  => true,
				'color' => $this->colors['heading'],
			),
			$this->get_para_style( array( 'spaceAfter' => Converter::pointToTwip( 12 ) ) )
		);

		$tab_leader = defined( '\\SScribeVendor\\PhpOffice\\PhpWord\\Style\\TOC::TAB_LEADER_DOT' )
			? \SScribeVendor\PhpOffice\PhpWord\Style\TOC::TAB_LEADER_DOT
			: 'dot';

		try {
			$section->addTOC(
				array(
					'name' => $this->font_name,
					'size' => 11,
				),
				array( 'tabLeader' => $tab_leader ),
				1,
				3
			);
		} catch ( \Throwable $e ) {
			$this->get_logger()->warning(
				'TOC generation failed, omitting table of contents',
				array(
					'error' => $e->getMessage(),
					'font'  => $this->font_name,
				)
			);

			$section->addText(
				__( '[Table of Contents could not be generated]', 'sscribe-export-site-pages' ),
				array(
					'name'   => $this->font_name,
					'size'   => 10,
					'italic' => true,
					'color'  => '888888',
				),
				$this->get_para_style( array( 'spaceBefore' => Converter::pointToTwip( 6 ) ) )
			);
		}

		try {
			$section->addText(
				/* translators: Instructions for updating the Table of Contents field in Microsoft Word and LibreOffice. */
				__( 'To update the Table of Contents: Microsoft Word: right-click, then select Update Field. LibreOffice: press F9 or select Tools, Update, All Fields.', 'sscribe-export-site-pages' ),
				array(
					'name'   => $this->font_name,
					'size'   => 9,
					'italic' => true,
					'color'  => '888888',
				),
				$this->get_para_style( array( 'spaceBefore' => Converter::pointToTwip( 4 ) ) )
			);
		} catch ( \Throwable $e ) {
			// TOC generation is best-effort; do not abort the document if mPDF rejects it.
			unset( $e );
		}

		$section->addPageBreak();
	}

	/**
	 * Add header and footer to document section.
	 *
	 * @param Section $section   Document section.
	 * @param array   $page_data Page data.
	 * @return void
	 */
	private function add_header_footer( Section $section, array $page_data ): void {
		$table_width = Converter::inchToTwip( 6.5 );

		$header       = $section->addHeader();
		$header_table = $header->addTable( array( 'width' => $table_width ) );
		$header_table->addRow();
		$header_table->addCell( Converter::inchToTwip( 3.25 ) )->addText(
			$this->safe_text( $this->get_site_name() ),
			array(
				'name'  => $this->font_name,
				'size'  => 8,
				'color' => $this->colors['primary'],
				'bold'  => true,
			)
		);
		$header_table->addCell( Converter::inchToTwip( 3.25 ) )->addText(
			$this->safe_text( $page_data['title'] ),
			array(
				'name'   => $this->font_name,
				'size'   => 8,
				'color'  => $this->colors['body'],
				'italic' => true,
			),
			array( 'alignment' => $this->is_rtl ? Jc::START : Jc::END )
		);

		$footer       = $section->addFooter();
		$footer_table = $footer->addTable( array( 'width' => $table_width ) );
		$footer_table->addRow();
		$footer_table->addCell( Converter::inchToTwip( 4 ) )->addText(
			$this->safe_text( rawurldecode( $page_data['permalink'] ) ),
			array(
				'name'  => $this->font_name,
				'size'  => 7,
				'color' => $this->colors['body'],
			)
		);
		$cell = $footer_table->addCell( Converter::inchToTwip( 2.5 ) );
		$cell->addPreserveText(
			/* translators: %s: page number field (e.g. "Page 1 / 10") */
			sprintf( __( 'Page %s', 'sscribe-export-site-pages' ), '{PAGE} / {NUMPAGES}' ),
			array(
				'name'  => $this->font_name,
				'size'  => 7,
				'color' => $this->colors['body'],
			),
			array( 'alignment' => $this->is_rtl ? Jc::START : Jc::END )
		);
	}

	/**
	 * Add featured image to document section.
	 *
	 * @param Section $section   Document section.
	 * @param array   $page_data Page data.
	 * @return void
	 */
	private function add_featured_image( Section $section, array $page_data ): void {

		if ( '1' !== (string) $this->get_format_option( 'sscribe_docx_include_images', '1' ) ) {
			return;
		}

		if ( empty( $page_data['featured_image_path'] ) ) {
			$this->get_logger()->warning(
				'Featured image skipped: path not provided',
				array(
					'page_id' => $page_data['id'] ?? 0,
				)
			);
			return;
		}

		if ( ! class_exists( 'SScribe_Image_Processor' ) ) {
			require_once SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-image-processor.php';
		}

		$path = SScribe_Image_Processor::validate_local_path( (string) $page_data['featured_image_path'] );
		if ( '' === $path ) {
			$this->get_logger()->warning(
				'Featured image skipped: path is outside the uploads directory or unsafe',
				array( 'page_id' => $page_data['id'] ?? 0 )
			);
			return;
		}

		try {
			if ( ! is_readable( $path ) ) {
				$this->get_logger()->warning(
					'Featured image skipped: not readable',
					array(
						'page_id' => $page_data['id'] ?? 0,
						'path'    => $path,
					)
				);
				return;
			}
			$image_info = getimagesize( $path );
			if ( ! $image_info ) {
				$this->get_logger()->warning(
					'Featured image skipped: getimagesize failed',
					array(
						'page_id' => $page_data['id'] ?? 0,
						'path'    => $path,
					)
				);
				return;
			}
			if ( ! SScribe_Image_Processor::are_dimensions_safe( (int) $image_info[0], (int) $image_info[1] ) ) {
				$this->get_logger()->warning(
					'Featured image skipped: unsafe dimensions',
					array( 'page_id' => $page_data['id'] ?? 0 )
				);
				return;
			}

			$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
			if ( in_array( $ext, array( 'svg', 'webp', 'avif', 'heic', 'heif' ), true ) ) {
				$this->get_logger()->debug( 'Skipping unsupported image format', array( 'ext' => $ext ) );
				return;
			}

			$max_width  = Converter::inchToEmu( 6.5 );
			$max_height = Converter::inchToEmu( 4 );

			$width_emu  = Converter::pixelToEmu( $image_info[0] );
			$height_emu = Converter::pixelToEmu( $image_info[1] );

			if ( 0 === $width_emu || 0 === $height_emu ) {
				$this->get_logger()->debug(
					'Featured image has zero dimensions, using original size',
					array(
						'path'   => $page_data['featured_image_path'],
						'width'  => $image_info[0],
						'height' => $image_info[1],
					)
				);
			} else {
				$ratio      = min( 1, $max_width / $width_emu, $max_height / $height_emu );
				$width_emu  = (int) ( $width_emu * $ratio );
				$height_emu = (int) ( $height_emu * $ratio );
			}

			$max_image_bytes = max( 1, min( 50 * 1024 * 1024, (int) apply_filters( 'sscribe_max_featured_image_bytes', 5 * 1024 * 1024 ) ) );
			$image_bytes     = @filesize( $path );
			if ( false !== $image_bytes && $image_bytes > $max_image_bytes ) {
				$this->get_logger()->warning(
					'Featured image skipped: file too large',
					array(
						'page_id'      => $page_data['id'] ?? 0,
						'path'         => $path,
						'file_size'    => size_format( $image_bytes ),
						'max_allowed'  => size_format( $max_image_bytes ),
					)
				);
				return;
			}

			$section->addImage(
				$path,
				array(
					'width'     => Converter::emuToPixel( $width_emu ),
					'height'    => Converter::emuToPixel( $height_emu ),
					'alignment' => Jc::CENTER,
				)
			);

			$section->addTextBreak( 1 );

		} catch ( \Throwable $e ) {
			$this->get_logger()->warning(
				'Featured image skipped due to error',
				array(
					'page_id'   => $page_data['id'] ?? 0,
					'path'      => $path,
					'exception' => get_class( $e ),
					'message'   => $e->getMessage(),
				)
			);

			return;
		}
	}

	/**
	 * Add page information table to document.
	 *
	 * @param Section $section   Document section.
	 * @param array   $page_data Page data.
	 * @return void
	 */
	private function add_page_info_table( Section $section, array $page_data ): void {
		$section->addTitle( __( 'Page Information', 'sscribe-export-site-pages' ), 2 );

		$table_style = array(
			'borderSize'  => 1,
			'borderColor' => $this->colors['border'],
			'cellMargin'  => Converter::cmToTwip( 0.15 ),
		);

		$label_style = $this->with_complex_script(
			array(
				'name'  => $this->font_name,
				'size'  => 10,
				'bold'  => true,
				'color' => $this->colors['heading'],
			)
		);

		$value_style = $this->with_complex_script(
			array(
				'name'  => $this->font_name,
				'size'  => 10,
				'color' => $this->colors['body'],
			)
		);

		$header_cell_style = array(
			'bgColor' => $this->colors['light_bg'],
		);

		$table = $section->addTable( $table_style );

		$info_rows = array(
			array( __( 'URL', 'sscribe-export-site-pages' ), $page_data['permalink'] ?? '' ),
			array( __( 'Author', 'sscribe-export-site-pages' ), $page_data['author'] ?? '' ),
			array( __( 'Published', 'sscribe-export-site-pages' ), $page_data['date_published'] ?? '' ),
			array( __( 'Last Modified', 'sscribe-export-site-pages' ), $page_data['date_modified'] ?? '' ),
			array( __( 'Word Count', 'sscribe-export-site-pages' ), number_format_i18n( (int) ( $page_data['word_count'] ?? 0 ) ) ),
		);
		$reading_time_value = (float) ( $page_data['reading_time'] ?? 0 );
		$info_rows[] = array(
			__( 'Reading Time', 'sscribe-export-site-pages' ),
			$reading_time_value > 0
				? sprintf(
					/* translators: %d: Number of minutes. */
					_n( '%d minute', '%d minutes', (int) ceil( $reading_time_value ), 'sscribe-export-site-pages' ),
					(int) ceil( $reading_time_value )
				)
				: __( 'Under 1 minute', 'sscribe-export-site-pages' ),
		);

		foreach ( $info_rows as $row ) {
			$table->addRow();
			$table->addCell( Converter::inchToTwip( 2 ), $header_cell_style )->addText(
				$this->safe_text( $row[0] ),
				$label_style,
				$this->get_para_style()
			);
			$table->addCell( Converter::inchToTwip( 4.5 ) )->addText(
				$this->safe_text( $row[1] ),
				$value_style,
				$this->get_para_style()
			);
		}

		$section->addTextBreak( 1 );
	}

	/**
	 * Add SEO information section to document.
	 *
	 * @param Section $section   Document section.
	 * @param array   $page_data Page data.
	 * @return void
	 */
	private function add_seo_section( Section $section, array $page_data ): void {
		$seo_data = ! empty( $page_data['seo'] ) ? $page_data['seo'] : array();

		if ( empty( $seo_data['meta_title'] ) && empty( $seo_data['meta_description'] ) && empty( $seo_data['focus_keyword'] ) ) {
			return;
		}

		$section->addTitle( __( 'SEO Information', 'sscribe-export-site-pages' ), 2 );

		if ( ! empty( $seo_data['source'] ) ) {
			$section->addText(
				$this->safe_text(
					/* translators: %s: SEO plugin name */
					sprintf( __( 'Source: %s', 'sscribe-export-site-pages' ), $seo_data['source'] )
				),
				array(
					'name'   => $this->font_name,
					'size'   => 9,
					'italic' => true,
					'color' => $this->colors['body'],
				),
				$this->get_para_style()
			);
		}

		$table_style = array(
			'borderSize'  => 1,
			'borderColor' => $this->colors['border'],
			'cellMargin'  => Converter::cmToTwip( 0.15 ),
		);

		$label_style = array(
			'name'  => $this->font_name,
			'size'  => 10,
			'bold'  => true,
			'color' => $this->colors['heading'],
		);

		$value_style = array(
			'name'  => $this->font_name,
			'size'  => 10,
			'color' => $this->colors['body'],
		);

		$table = $section->addTable( $table_style );

		$seo_rows = array(
			array( __( 'Meta Title', 'sscribe-export-site-pages' ), $seo_data['meta_title'] ?? '' ),
			array( __( 'Meta Description', 'sscribe-export-site-pages' ), $seo_data['meta_description'] ?? '' ),
			array( __( 'Focus Keyword', 'sscribe-export-site-pages' ), $seo_data['focus_keyword'] ?? '' ),
		);

		foreach ( $seo_rows as $row ) {
			if ( ! empty( $row[1] ) ) {
				$table->addRow();
				$table->addCell( Converter::inchToTwip( 2 ), array( 'bgColor' => $this->colors['light_bg'] ) )->addText(
					$this->safe_text( $row[0] ),
					$label_style,
					$this->get_para_style()
				);
				$table->addCell( Converter::inchToTwip( 4.5 ) )->addText(
					$this->safe_text( $row[1] ),
					$value_style,
					$this->get_para_style()
				);
			}
		}

		$section->addTextBreak( 1 );
	}

	/**
	 * Add breadcrumbs to document.
	 *
	 * @param Section $section   Document section.
	 * @param array   $page_data Page data.
	 * @return void
	 */
	private function add_breadcrumbs( Section $section, array $page_data ): void {
		if ( empty( $page_data['breadcrumbs'] ) || count( $page_data['breadcrumbs'] ) <= 1 ) {
			return;
		}

		$breadcrumb_text = implode(
			' > ',
			array_map(
				function ( $crumb ) {
					return $this->safe_text( $crumb['title'] ?? '' );
				},
				$page_data['breadcrumbs']
			)
		);

		$section->addText(
			$this->safe_text(
				sprintf(
					/* translators: %s: breadcrumb path */
					__( 'Path: %s', 'sscribe-export-site-pages' ),
					$breadcrumb_text
				)
			),
			$this->with_complex_script(
				array(
					'name'   => $this->font_name,
					'size'   => 9,
					'italic' => true,
					'color'  => $this->colors['body'],
				)
			),
			$this->get_para_style()
		);

		$section->addTextBreak( 1 );
	}

	/**
	 * Add child pages section to document.
	 *
	 * @param Section $section   Document section.
	 * @param array   $page_data Page data.
	 * @return void
	 */
	private function add_child_pages( Section $section, array $page_data ): void {
		if ( empty( $page_data['children'] ) ) {
			return;
		}

		$section->addTextBreak( 1 );
		$section->addTitle( __( 'Child Pages', 'sscribe-export-site-pages' ), 2 );

		$max_depth    = 3;
		$max_children = 50;
		$this->render_child_pages( $section, $page_data['children'], 0, $max_depth, $max_children, 0 );
	}

	/**
	 * Render child pages recursively with depth limit.
	 *
	 * @param Section $section      Document section.
	 * @param array   $children     Children array.
	 * @param int     $depth        Current depth.
	 * @param int     $max_depth    Maximum depth allowed.
	 * @param int     $max_children Maximum total children to render.
	 * @param int     $rendered     Count of rendered children.
	 * @return int Total rendered count.
	 */
	private function render_child_pages(
		Section $section,
		array $children,
		int $depth,
		int $max_depth,
		int $max_children,
		int $rendered
	): int {
		if ( $depth >= $max_depth || $rendered >= $max_children ) {
			return $rendered;
		}

		foreach ( $children as $child ) {
			if ( $rendered >= $max_children ) {
				break;
			}

			$child_url = $this->validate_url( $child['url'] ?? '' );
			if ( empty( $child_url ) ) {
				continue;
			}

			$indent   = str_repeat( '  ', $depth );
			$text_run = $section->addTextRun( $this->get_para_style() );
			$text_run->addText(
				$indent . '> ',
				array(
					'size'  => 10,
					'bold'  => true,
					'color' => $this->colors['primary'],
				)
			);
			$text_run->addLink(
				$child_url,
				$this->safe_text( $child['title'] ?? '' ),
				array(
					'name'  => $this->font_name,
					'size'  => $this->font_size,
					'color' => $this->colors['link'],
				)
			);
			++$rendered;

			if ( ! empty( $child['children'] ) && is_array( $child['children'] ) ) {
				$rendered = $this->render_child_pages( $section, $child['children'], $depth + 1, $max_depth, $max_children, $rendered );
			}
		}

		return $rendered;
	}

	/**
	 * Get or create the logger instance.
	 *
	 * @return SScribe_Logger_Interface
	 */
	private function get_logger(): SScribe_Logger_Interface {
		if ( null === $this->logger ) {
			$this->logger = SScribe_Logger::instance( SScribe_Logger::is_logging_enabled() );
		}
		return $this->logger;
	}

	/**
	 * Get cached WordPress date and time formats, populating cache on first call.
	 *
	 * @return array{date_format: string, time_format: string}
	 */
	private function get_wp_datetime_formats(): array {
		if ( '' === $this->cached_date_format ) {
			$this->cached_date_format = (string) get_option( 'date_format', 'Y-m-d' );
			$this->cached_time_format = (string) get_option( 'time_format', 'H:i' );
		}
		return array(
			'date_format' => $this->cached_date_format,
			'time_format' => $this->cached_time_format,
		);
	}

	/**
	 * Get cached site name (via get_bloginfo('name')), populating
	 * the cache on first call.
	 *
	 * Returning a memoized string avoids running get_bloginfo() :
	 * which fetches options and applies a long filter chain : once
	 * per DOCX page. Without this, set_document_properties(),
	 * add_cover_page(), and add_header_footer() each issue an
	 * identical get_bloginfo() call, multiplying cost by 3 per page.
	 *
	 * @return string Site name (decoded as text).
	 */
	private function get_site_name(): string {
		if ( '' === $this->cached_site_name ) {
			$raw                  = get_bloginfo( 'name' );
			$this->cached_site_name = html_entity_decode(
				is_string( $raw ) ? $raw : '',
				ENT_QUOTES | ENT_HTML5,
				'UTF-8'
			);
		}
		return $this->cached_site_name;
	}

	/**
	 * Get cached WordPress locale, populating the cache on first call.
	 *
	 * `get_locale()` looks up the WPLANG option and runs through the
	 * locale and pre_option_locale filters on every call. Memoizing
	 * it once per export saves one filter chain invocation per page.
	 *
	 * @return string Locale identifier, e.g. 'en_US'.
	 */
	private function get_cached_locale(): string {
		if ( '' === $this->cached_locale ) {
			$this->cached_locale = (string) get_locale();
		}
		return $this->cached_locale;
	}
}
