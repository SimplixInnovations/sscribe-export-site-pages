<?php
/**
 * Generates DOCX documents from page data using PHPWord.
 *
 * @package SScribe
 */

declare(strict_types=1);

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SScribeVendor\PhpOffice\PhpWord\PhpWord;
use SScribeVendor\PhpOffice\PhpWord\IOFactory;
use SScribeVendor\PhpOffice\PhpWord\Style\Font;
use SScribeVendor\PhpOffice\PhpWord\SimpleType\Jc;
use SScribeVendor\PhpOffice\PhpWord\Shared\Converter;
use SScribeVendor\PhpOffice\PhpWord\Element\Section;
use SScribeVendor\PhpOffice\PhpWord\Element\TextRun;

/**
 * Class SScribe_Exporter
 *
 * Creates professional DOCX documents from structured page data.
 */
class SScribe_Exporter {


	/**
	 * Last exception message from generate_docx(), for debug surfacing.
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
	 * Content parser instance.
	 *
	 * @var SScribe_Content_Parser|null
	 */
	private ?SScribe_Content_Parser $parser = null;

	/**
	 * Whether the current document is RTL.
	 *
	 * @var bool
	 */
	private bool $is_rtl = false;

	/**
	 * Font name for normal text.
	 *
	 * @var string
	 */
	private string $font_name = 'Arial';

	/**
	 * Font name for RTL text (Arabic-capable DOCX).
	 *
	 * Arial is used intentionally — NotoSansArabic was removed from the plugin
	 * package in v3.7.6 (see class-sscribe-font-helper.php). Arial is universally
	 * available on Windows (the primary DOCX viewing environment), includes full
	 * Arabic Unicode block, and provides consistent rendering across all systems
	 * without requiring bundled font files.
	 *
	 * @var string
	 */
	private string $rtl_font_name = 'Arial';

	/**
	 * Font size for normal text (in points).
	 *
	 * @var int
	 */
	private int $font_size = 11;

	/**
	 * Document colors.
	 *
	 * @var array
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
	 * Constructor.
	 *
	 * @param SScribe_Content_Parser|null $parser Content parser instance.
	 */
	public function __construct(
		?SScribe_Content_Parser $parser = null
	) {
		$this->parser = $parser ?? new SScribe_Content_Parser();
	}

	/**
	 * Safe-preg_replace wrapper that never returns null.
	 *
	 * Preg_replace() returns null when the pattern causes backtrack/recursion
	 * limit exhaustion (PCRE_ERROR). In PHP 8.2+, passing null to str_replace
	 * or another preg_replace causes a TypeError, crashing the export.
	 * This wrapper ensures string type is always preserved.
	 *
	 * @param string|string[] $pattern  Regex pattern(s).
	 * @param string|string[] $replacement Replacement string(s).
	 * @param string          $subject The input string.
	 * @return string Cleaned string (original subject on failure).
	 */
	private function safe_preg_replace( array|string $pattern, array|string $replacement, string $subject ): string {
		$result = preg_replace( $pattern, $replacement, $subject );
		return is_string( $result ) ? $result : $subject;
	}

	/**
	 * Clean text for safe XML 1.0 output.
	 *
	 * Note: We preserve Unicode characters (including Arabic, CJK, etc.) as PHPWord
	 * handles them correctly with proper encoding.
	 *
	 * @param string $text Input value.
	 * @return string Cleaned, XML 1.0-safe string.
	 */
	private function safe_text( string $text ): string {
		$text = (string) $text;

		// NOTE: Do NOT urldecode here — callers already decode URLs before passing.
		// Applying urldecode would corrupt encoded path segments (%2F → /) and query
		// strings, breaking hyperlink targets in addLink() calls.
		// Per-word URL decoding happens at display-time in $display_url assignments,
		// not in this general-purpose XML-safe text routine.

		// 1. FIRST: Strip invalid UTF-8 sequences before any regex processing.
		// All subsequent preg_replace() calls with /u modifier will fail on invalid
		// UTF-8, potentially returning null and losing the entire text content.
		$cleaned = mb_convert_encoding( $text, 'UTF-8', 'UTF-8' );
		if ( false !== $cleaned ) {
			$text = $cleaned;
		}

		// 2. Remove XML 1.0 illegal control characters (keep \t, \n, \r).
		// Use safe_preg_replace() to guard against PCRE backtrack limit exhaustion
		// on very long strings — null return would cause TypeError downstream.
		$text = $this->safe_preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text );

		// 3. Remove XML non-characters: U+FFFE and U+FFFF.
		$text = $this->safe_preg_replace( '/[\x{FFFE}\x{FFFF}]/u', '', $text );

		// 4. Remove Unicode non-characters (U+FDD0–U+FDEF).
		$text = $this->safe_preg_replace( '/[\x{FDD0}-\x{FDEF}]/u', '', $text );

		// 5. Remove Unicode surrogate code points (U+D800-U+DFFF).
		// These are encoded as 3-byte UTF-8 sequences starting with 0xED.
		// Valid UTF-8 never contains surrogates, but corrupted data might.
		$text = $this->safe_preg_replace( '/\xED[\xA0-\xBF][\x80-\xBF]/', '', $text );

		// 6. Replace zero-width and invisible formatting chars that cause display issues.
		// NOTE: Preserves U+200C (ZWNJ) and U+200D (ZWJ) which are CRITICAL for
		// Arabic/Persian text shaping — removing them breaks letter joining.
		// Also preserves U+200E (LRM) and U+200F (RLM) needed for bidi text.
		$text = $this->safe_preg_replace( '/[\x{200B}\x{FEFF}\x{00AD}]/u', '', $text );

		// 7. Normalize mixed line endings to Unix style.
		$text = str_replace( array( "\r\n", "\r" ), "\n", $text );

		// 8. Remove form feed characters (cause some XML parsers to fail).
		$text = str_replace( "\x0C", '', $text );

		// 9. Truncate very long unbreakable strings (URLs, base64) to prevent
		// table cell overflow in DOCX rendering. No soft-hyphen insertion —
		// raw UTF-8 bytes in XML character data cause parsing errors in PHPWord.
		// CRITICAL: Use mb_strlen (character count) not strlen (byte count).
		// Arabic text uses 2-byte UTF-8 per character, so strlen > 150 triggers
		// for strings of only ~75 Arabic chars, prematurely truncating content.
		if ( mb_strlen( $text, 'UTF-8' ) > 200 && false === mb_strpos( $text, ' ', 0, 'UTF-8' ) ) {
			$text = mb_substr( $text, 0, 200, 'UTF-8' );
		}

		// 10. Encode XML 1.0 special characters. PHPWord 1.4.0 does not escape
		// &, <, >, ", ' in addText/addTitle output, producing invalid XML that
		// makes DOCX unopenable. htmlspecialchars with ENT_XML1 ensures all five
		// XML entities are encoded. The double_encode=false flag prevents
		// re-escaping text that was already XML-encoded upstream.
		$text = htmlspecialchars( $text, ENT_XML1 | ENT_QUOTES, 'UTF-8', false );

		return $text;
	}

	/**
	 * Validate and sanitize a URL for use in documents.
	 *
	 * Uses esc_url_raw to prevent HTML entity encoding in document output.
	 *
	 * @param string $url URL to validate.
	 * @return string Valid URL or empty string if invalid.
	 */
	private function validate_url( string $url ): string {
		if ( empty( $url ) ) {
			return '';
		}

		if ( str_starts_with( $url, '#' ) ) {
			return $url;
		}

		if ( str_starts_with( $url, '/' ) ) {
			// Use esc_url_raw only for the scheme+host; re-encode the path
			// portion with rawurlencode to avoid double-encoding %XX sequences.
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

			$safe_path = implode(
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
				// Use query string as-is — it may already contain encoded characters
				// (%XX sequences). Re-encoding would create double-encoded sequences
				// that break URL parsing (e.g., %3D → %253D for the = sign).
				$safe_query = '?' . $query;
			}
			$safe_fragment = ! empty( $fragment ) ? '#' . rawurlencode( $fragment ) : '';

			return $scheme . '://' . $host . $port . $safe_path . $safe_query . $safe_fragment;
		}

		$parsed_scheme = wp_parse_url( $url, PHP_URL_SCHEME );
		$scheme        = strtolower( ( false === $parsed_scheme || null === $parsed_scheme ) ? '' : $parsed_scheme );

		if ( in_array( $scheme, array( 'http', 'https', 'mailto', 'tel' ), true ) ) {
			return esc_url_raw( $url );
		}

		return '';
	}

	/**
	 * Determine if the document should use RTL text direction.
	 *
	 * @param array $page_data Page data.
	 * @return bool True if RTL.
	 */
	private function is_rtl_document( array $page_data ): bool {
		require_once SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-rtl-helper.php';
		return SScribe_RTL_Helper::is_rtl( $page_data['language'] ?? 'en' );
	}

	/**
	 * Get font definition array with complex script support for RTL.
	 *
	 * When the document is RTL (Arabic, Hebrew, etc.), PHPWord needs the
	 * 'complexScript' font key to render complex script glyphs correctly.
	 * Without it, Arabic characters appear as squares in some DOCX viewers.
	 *
	 * @param array $font_def Base font definition (name, size, bold, etc.).
	 * @return array Font definition with complex script support if RTL.
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
	 * Get paragraph style with optional RTL bidirectional flag.
	 *
	 * @param array $base_style Base paragraph style array.
	 * @return array Paragraph style with bidi if needed.
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
	 * Generate a DOCX file for a single page.
	 *
	 * @param array  $page_data  Page data from SScribe_Page_Collector.
	 * @param string $output_dir Directory to save the DOCX file.
	 * @param int    $index      Sequential position in the export (1-based). Used for filename.
	 * @param int    $total      Total number of pages being exported. Used for zero-padding.
	 * @return string|false Path to generated DOCX or false on failure.
	 * @throws \RuntimeException If ZipArchive extension is not available or DOCX generation fails.
	 */
	public function generate_docx( array $page_data, string $output_dir, int $index = 0, int $total = 0 ): string|false {
		if ( empty( $page_data ) || ! is_dir( $output_dir ) ) {
			return false;
		}

		// CRITICAL: Reset last_error before each page — SScribe_Exporter instance
		// is reused across all pages in a batch. Without this, a failure on page 3
		// would leave $last_error set, causing stale error messages on subsequent pages.
		$this->last_error = '';

		$output_path = '';

		try {
			if ( ! class_exists( 'ZipArchive' ) ) {
				throw new \RuntimeException( __( 'The ZipArchive PHP extension is required to generate DOCX files.', 'sscribe-export-site-pages' ) );
			}

			// Force ZipArchive — prevents PHPWord from ever loading bundled PCLZip.
			// Guard with class_exists to handle prefixed/vendor-less installations.
			if ( class_exists( '\SScribeVendor\PhpOffice\PhpWord\Settings' ) ) {
				\SScribeVendor\PhpOffice\PhpWord\Settings::setZipClass(
					\SScribeVendor\PhpOffice\PhpWord\Settings::ZIPARCHIVE
				);
			}

			$php_word = new PhpWord();

			// Determine RTL setting for the document.
			// CRITICAL: Reset font_name before each page — SScribe_Exporter is reused
			// across pages in a batch. If an Arabic page runs first, font_name is set
			// to 'Noto Sans Arabic'. Without this reset, subsequent English pages would
			// use the wrong font, corrupting heading styles in the DOCX.
			$this->font_name = 'Arial';
			$this->is_rtl    = $this->is_rtl_document( $page_data );

			// Use Arabic-capable font for RTL documents to render Arabic glyphs properly.
			if ( $this->is_rtl ) {
				$this->font_name = $this->rtl_font_name;
			}

			// Set document properties.
			$this->set_document_properties( $php_word, $page_data );

			// Set default styles.
			$this->set_default_styles( $php_word );

			// Define custom styles.
			$this->define_styles( $php_word );

			// --- Section 1: Cover Page ---
			$cover = $php_word->addSection( $this->get_section_settings( $this->is_rtl ) );
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

			// --- Section 2: Content ---
			$content_section = $php_word->addSection( $this->get_section_settings( $this->is_rtl ) );

			// Add header and footer.
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

			// Add featured image.
			$this->add_featured_image( $content_section, $page_data );

			// Add page info table.
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

			// Add SEO section.
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

			// Add breadcrumb trail.
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

			// Add main content.
			$this->add_main_content( $content_section, $page_data );

			// Add child pages.
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

			// Save document.
			$filename    = \SScribe_Exporter_Factory::build_filename( $page_data, $index, $total, 'docx' );
			$output_path = trailingslashit( $output_dir ) . $filename;

			$writer = IOFactory::createWriter( $php_word, 'Word2007' );
			$writer->save( $output_path );

			// Verify DOCX ZIP integrity — PHPWord can produce malformed ZIPs from
			// broken HTML (nested tables, invalid lists, malformed headings).
			$zip_check = new \ZipArchive();
			if ( true !== $zip_check->open( $output_path ) ) {
				wp_delete_file( $output_path );
				unset( $writer, $php_word );
				throw new \RuntimeException( 'DOCX failed ZipArchive integrity check after write' );
			}
			$has_document = false !== $zip_check->locateName( 'word/document.xml' );
			$has_types    = false !== $zip_check->locateName( '[Content_Types].xml' );

			// CRITICAL: Validate XML content inside document.xml — not just ZIP structure.
			// Corrupted text encoding (e.g., mangled Arabic from bad UTF-8 handling)
			// produces invalid XML that passes the ZIP check but makes the DOCX
			// unopenable in Word/LibreOffice. Parse the XML to catch this.
			$xml_valid = true;
			if ( $has_document ) {
				$doc_xml = $zip_check->getFromName( 'word/document.xml' );
				if ( false !== $doc_xml && ! empty( $doc_xml ) ) {
					$prev_xml_errors = libxml_use_internal_errors( true );
					$test_doc        = new \DOMDocument();
					$parse_result    = $test_doc->loadXML( $doc_xml );
					$xml_errors      = libxml_get_errors();
					libxml_clear_errors();
					libxml_use_internal_errors( $prev_xml_errors );

					// Check for fatal XML errors (level 3 = LIBXML_ERR_FATAL).
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
					unset( $test_doc, $doc_xml );
				}
			}

			$zip_check->close();
			if ( ! $has_document || ! $has_types || ! $xml_valid ) {
				wp_delete_file( $output_path );
				unset( $writer, $php_word );
				$missing = array();
				if ( ! $has_document ) {
					$missing[] = 'word/document.xml';
				}
				if ( ! $has_types ) {
					$missing[] = '[Content_Types].xml';
				}
				if ( ! $xml_valid ) {
					$missing[] = 'valid XML content';
				}
				throw new \RuntimeException( 'DOCX integrity check failed: missing ' . implode( ', ', $missing ) );
			}

			// CRITICAL: Explicitly release PHPWord objects to prevent memory leaks in batch processing.
			// PHPWord retains circular references between elements and the parent document,
			// which prevents PHP's garbage collector from reclaiming memory automatically.
			// Without this, memory accumulates 2-5MB per page during batch exports.
			unset( $writer, $php_word );

			// Force garbage collection to break PHPWord's circular references immediately.
			// Without this, memory isn't freed until end of request.
			gc_collect_cycles();

			// NOTE: Parser is intentionally kept alive across page exports in a batch.
			// The parser is stateless and can be safely reused. Destroying it would cause
			// crashes on page 2+ because the exporter instance is reused.

			return $output_path;

		} catch ( \Throwable $e ) {
			// ALWAYS log — this is an unexpected failure that must be visible regardless of WP_DEBUG.
			// Note: File path removed for security - sensitive server info should not be in logs.

			// CRITICAL: Ensure cleanup even on failure to prevent memory/resource leaks.
			if ( isset( $writer ) ) {
				unset( $writer );
			}
			if ( isset( $php_word ) ) {
				unset( $php_word );
			}

			// CRITICAL: Remove any partial file left by a failed $writer->save().
			// Without this, the partial (corrupted) DOCX gets included in the ZIP,
			// corrupting the entire export package.
			if ( ! empty( $output_path ) && file_exists( $output_path ) ) {
				wp_delete_file( $output_path );
			}

			// NOTE: Parser is intentionally kept alive even on failure.
			// The batch processor may retry or continue with remaining pages,
			// and the parser can still be used for those pages.

			// Capture memory context for diagnostics - helps identify memory exhaustion vs other failures.
			$memory_context = sprintf(
				'Memory: %s used / %s limit (peak: %s)',
				size_format( memory_get_usage( true ) ),
				ini_get( 'memory_limit' ),
				size_format( memory_get_peak_usage( true ) )
			);

			// Build descriptive error message including exception class for diagnostics.
			// Many PHPWord/DOMDocument exceptions have empty messages, so the class name is critical.
			$exception_class = (string) get_class( $e );
			$raw_message     = $e->getMessage();

			$error_lower = strtolower( $raw_message );
			if ( str_contains( $error_lower, 'memory' ) || str_contains( $error_lower, 'allocated' ) ) {
				$error_message = 'Memory exhausted - ' . $memory_context;
			} elseif ( ! empty( $raw_message ) ) {
				$error_message = sprintf( '%s: %s | %s', $exception_class, $raw_message, $memory_context );
			} else {
				// Exception message is empty — class name is the only diagnostic.
				$error_message = sprintf( '%s (no message) | %s', $exception_class, $memory_context );
			}

			$this->get_logger()->error(
				'DOCX generation failed',
				array(
					'page_id' => $page_data['id'] ?? 0,
					'error'   => $error_message,
				)
			);

			// Store the exception message so the batch processor can surface it in the debug log.
			$this->last_error = $error_message;

			return false;
		}
	}

	/**
	 * Set document metadata properties.
	 *
	 * @param PhpWord $php_word  The PhpWord instance.
	 * @param array   $page_data Page data.
	 */
	private function set_document_properties( PhpWord $php_word, array $page_data ): void {
		$properties = $php_word->getDocInfo();
		$properties->setCreator( 'SScribe by Simplix Innovations' );
		$properties->setCompany( html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		$properties->setTitle( html_entity_decode( $page_data['title'], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		$properties->setDescription( 'Exported from ' . esc_url_raw( $page_data['permalink'] ) );
		$properties->setLastModifiedBy( wp_strip_all_tags( $page_data['author'] ) );
	}

	/**
	 * Get the last error message from document generation.
	 *
	 * @return string Last error message, or empty string if no error.
	 */
	public function get_last_error(): string {
		return $this->last_error;
	}

	/**
	 * Set default font and paragraph styles.
	 *
	 * @param PhpWord $php_word The PhpWord instance.
	 */
	private function set_default_styles( PhpWord $php_word ): void {
		$php_word->setDefaultFontName( $this->font_name );
		$php_word->setDefaultFontSize( $this->font_size );

		// Set complex script font for Arabic/Hebrew/RTL text rendering.
		// Without this, Word renders Arabic characters as squares because
		// it doesn't know which font to use for complex script glyphs.
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
	 * Define named styles for headings, etc.
	 *
	 * @param PhpWord $php_word The PhpWord instance.
	 */
	private function define_styles( PhpWord $php_word ): void {
		// Heading styles.
		$heading_sizes = array( 24, 20, 16, 14, 12, 11 );
		for ( $i = 1; $i <= 6; $i++ ) {
			$heading_font = array(
				'name'  => $this->font_name,
				'size'  => $heading_sizes[ $i - 1 ],
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

		// Blockquote paragraph style.
		$blockquote_style = array(
			'spaceBefore' => Converter::pointToTwip( 6 ),
			'spaceAfter'  => Converter::pointToTwip( 6 ),
		);

		if ( $this->is_rtl ) {
			$blockquote_style['indentation']      = array( 'right' => Converter::cmToTwip( 1 ) );
			$blockquote_style['borderRightSize']  = 12;
			$blockquote_style['borderRightColor'] = $this->colors['primary'];
		} else {
			$blockquote_style['indentation']     = array( 'left' => Converter::cmToTwip( 1 ) );
			$blockquote_style['borderLeftSize']  = 12;
			$blockquote_style['borderLeftColor'] = $this->colors['primary'];
		}

		$php_word->addParagraphStyle( 'Blockquote', $this->get_para_style( $blockquote_style ) );

		// Code paragraph style.
		$php_word->addParagraphStyle(
			'CodeBlock',
			array(
				'indentation' => array( 'left' => Converter::cmToTwip( 0.5 ) ),
				'spaceBefore' => Converter::pointToTwip( 6 ),
				'spaceAfter'  => Converter::pointToTwip( 6 ),
			)
		);
	}

	/**
	 * Get section settings (page size and margins).
	 *
	 * @param bool $is_rtl Whether document is RTL.
	 * @return array Section properties.
	 */
	private function get_section_settings( bool $is_rtl = false ): array {
		$settings = array(
			'pageSizeW'    => Converter::inchToTwip( 8.5 ),
			'pageSizeH'    => Converter::inchToTwip( 11 ),
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

		/**
		 * Filter the DOCX section settings.
		 *
		 * @param array $settings Section settings array.
		 * @param bool  $is_rtl   Whether document is RTL.
		 */
		return apply_filters( 'sscribe_docx_section_settings', $settings, $is_rtl );
	}

	/**
	 * Add the cover page.
	 *
	 * @param \SScribeVendor\PhpOffice\PhpWord\Element\Section $section The section.
	 * @param array                                            $page_data Page data.
	 */
	private function add_cover_page( \SScribeVendor\PhpOffice\PhpWord\Element\Section $section, array $page_data ): void {
		// Top solid bar simulation.
		$section->addTextBreak( 2 );

		$table = $section->addTable( array( 'borderSize' => 0 ) );
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
					html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' )
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

		$cover_title = (string) $page_data['title'];
		if ( ! $this->is_rtl ) {
			$cover_title = function_exists( 'mb_strtoupper' )
				? mb_strtoupper( $cover_title, 'UTF-8' )
				: strtoupper( $cover_title );
		}
		$section->addText(
			$this->safe_text( $cover_title ),
			$this->with_complex_script(
				array(
					'name'  => $this->font_name,
					'size'  => 28,
					'bold'  => true,
					'color' => $this->colors['heading'],
				)
			),
			$this->get_para_style( array( 'alignment' => Jc::CENTER ) )
		);

		$section->addTextBreak( 1 );

		// The URL link prominently displayed.
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

		// Executive summary block.
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
			/* translators: %s: language code */
			sprintf( __( 'Target Language: %s', 'sscribe-export-site-pages' ), $lang_display ),
			array(
				'name'  => $this->font_name,
				'size'  => 10,
				'color' => $this->colors['body'],
			),
			$this->get_para_style( array( 'alignment' => Jc::CENTER ) )
		);
		$meta_cell->addText(
			/* translators: %s: export date */
			sprintf( __( 'Extracted Date: %s', 'sscribe-export-site-pages' ), wp_date( ( get_option( 'date_format' ) ? get_option( 'date_format' ) : 'Y-m-d' ) . ' ' . ( get_option( 'time_format' ) ? get_option( 'time_format' ) : 'H:i' ) ) ),
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
						return $c['title'];
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

		$section->addPageBreak();

		// Table of contents.
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

		// Tab leader for TOC: use the PHPWord constant if available, otherwise
		// fall back to 'dot' (the correct string token). The previous fallback
		// '.' was not a valid PHPWord tab leader and would generate malformed
		// <w:tab> XML that could corrupt the document.
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
			// Add a clear placeholder so users know the TOC section is intentionally blank.
			$section->addText(
				'[Table of Contents could not be generated]',
				array(
					'name'   => $this->font_name,
					'size'   => 10,
					'italic' => true,
					'color'  => '888888',
				),
				$this->get_para_style( array( 'spaceBefore' => Converter::pointToTwip( 6 ) ) )
			);
		}

		// Word requires user to right-click TOC and select "Update Field" to populate it.
		// Add a subtle italic hint so users know they need to do this.
		try {
			$section->addText(
				/* translators: This appears below the TOC placeholder in DOCX files. */
				__( 'Right-click above and select "Update Field" to generate the Table of Contents.', 'sscribe-export-site-pages' ),
				array(
					'name'   => $this->font_name,
					'size'   => 9,
					'italic' => true,
					'color'  => '888888',
				),
				$this->get_para_style( array( 'spaceBefore' => Converter::pointToTwip( 4 ) ) )
			);
		} catch ( \Throwable $e ) {
			// Silently ignore — the TOC instruction is non-critical.
			unset( $e ); // Sentinel: empty catch is intentional here.
		}

		$section->addPageBreak();
	}

	/**
	 * Add header and footer to a section.
	 *
	 * @param \SScribeVendor\PhpOffice\PhpWord\Element\Section $section   The section.
	 * @param array                                            $page_data Page data.
	 */
	private function add_header_footer( \SScribeVendor\PhpOffice\PhpWord\Element\Section $section, array $page_data ): void {
		// Header.
		$header       = $section->addHeader();
		$header_table = $header->addTable();
		$header_table->addRow();
		$header_table->addCell( Converter::inchToTwip( 3.25 ) )->addText(
			$this->safe_text( html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ),
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
			array( 'alignment' => Jc::START )
		);

		// Footer.
		$footer       = $section->addFooter();
		$footer_table = $footer->addTable();
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
			__( 'Page', 'sscribe-export-site-pages' ) . ' {PAGE} / {NUMPAGES}',
			array(
				'name'  => $this->font_name,
				'size'  => 7,
				'color' => $this->colors['body'],
			),
			array( 'alignment' => Jc::START )
		);
	}

	/**
	 * Add featured image if available.
	 *
	 * @param \SScribeVendor\PhpOffice\PhpWord\Element\Section $section   The section.
	 * @param array                                            $page_data Page data.
	 */
	private function add_featured_image( \SScribeVendor\PhpOffice\PhpWord\Element\Section $section, array $page_data ): void {
		if ( empty( $page_data['featured_image_path'] ) || ! file_exists( $page_data['featured_image_path'] ) ) {
			return;
		}

		try {
			$path = $page_data['featured_image_path'];
			if ( ! file_exists( $path ) || ! is_readable( $path ) ) {
				return;
			}
			$image_info = getimagesize( $path );
			if ( ! $image_info ) {
				return;
			}

			// PHPWord cannot handle SVG — skip SVG featured images to prevent fatal errors.
			$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
			if ( 'svg' === $ext ) {
				$this->get_logger()->debug( 'Skipping SVG featured image', array( 'path' => $path ) );
				return;
			}

			// Calculate dimensions to fit within 6.5 inches width.
			$max_width  = Converter::inchToEmu( 6.5 );
			$max_height = Converter::inchToEmu( 4 );

			$width_emu  = Converter::pixelToEmu( $image_info[0] );
			$height_emu = Converter::pixelToEmu( $image_info[1] );

			// Always scale to target width (both up and down) for uniform appearance.
			$ratio      = $max_width / $width_emu;
			$width_emu  = $max_width;
			$height_emu = (int) ( $height_emu * $ratio );

			if ( $height_emu > $max_height ) {
				$ratio      = $max_height / $height_emu;
				$height_emu = $max_height;
				$width_emu  = (int) ( $width_emu * $ratio );
			}

			$section->addImage(
				$page_data['featured_image_path'],
				array(
					'width'     => Converter::emuToPixel( $width_emu ),
					'height'    => Converter::emuToPixel( $height_emu ),
					'alignment' => Jc::CENTER,
				)
			);

			$section->addTextBreak( 1 );

		} catch ( \Throwable $e ) {
			// Skip image on error - image file may be corrupted or inaccessible.
			// Catches both Exception and Error (e.g., TypeError from Converter).
			return;
		}
	}

	/**
	 * Add page info table.
	 *
	 * @param \SScribeVendor\PhpOffice\PhpWord\Element\Section $section   The section.
	 * @param array                                            $page_data Page data.
	 */
	private function add_page_info_table( \SScribeVendor\PhpOffice\PhpWord\Element\Section $section, array $page_data ): void {
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
			array( __( 'URL', 'sscribe-export-site-pages' ), $page_data['permalink'] ),
			array( __( 'Author', 'sscribe-export-site-pages' ), $page_data['author'] ),
			array( __( 'Published', 'sscribe-export-site-pages' ), $page_data['date_published'] ),
			array( __( 'Last Modified', 'sscribe-export-site-pages' ), $page_data['date_modified'] ),
			array( __( 'Word Count', 'sscribe-export-site-pages' ), number_format( $page_data['word_count'] ) ),
			array(
				__( 'Reading Time', 'sscribe-export-site-pages' ),
				/* translators: %d: number of minutes */
				sprintf( _n( '%d minute', '%d minutes', (int) ( $page_data['reading_time'] ?? 0 ), 'sscribe-export-site-pages' ), (int) ( $page_data['reading_time'] ?? 0 ) ),
			),
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
	 * Add SEO metadata section.
	 *
	 * @param \SScribeVendor\PhpOffice\PhpWord\Element\Section $section   The section.
	 * @param array                                            $page_data Page data.
	 */
	private function add_seo_section( \SScribeVendor\PhpOffice\PhpWord\Element\Section $section, array $page_data ): void {
		$seo_data = ! empty( $page_data['seo'] ) ? $page_data['seo'] : array();

		if ( empty( $seo_data['meta_title'] ) && empty( $seo_data['meta_description'] ) && empty( $seo_data['focus_keyword'] ) ) {
			return;
		}

		$section->addTitle( __( 'SEO Information', 'sscribe-export-site-pages' ), 2 );

		if ( ! empty( $seo_data['source'] ) ) {
			$section->addText(
				/* translators: %s: SEO plugin name */
				sprintf( __( 'Source: %s', 'sscribe-export-site-pages' ), $seo_data['source'] ),
				array(
					'name'   => $this->font_name,
					'size'   => 9,
					'italic' => true,
					'color'  => $this->colors['body'],
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
			array( __( 'Meta Title', 'sscribe-export-site-pages' ), $seo_data['meta_title'] ),
			array( __( 'Meta Description', 'sscribe-export-site-pages' ), $seo_data['meta_description'] ),
			array( __( 'Focus Keyword', 'sscribe-export-site-pages' ), $seo_data['focus_keyword'] ),
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
	 * Add breadcrumb trail.
	 *
	 * @param \SScribeVendor\PhpOffice\PhpWord\Element\Section $section   The section.
	 * @param array                                            $page_data Page data.
	 */
	private function add_breadcrumbs( \SScribeVendor\PhpOffice\PhpWord\Element\Section $section, array $page_data ): void {
		if ( empty( $page_data['breadcrumbs'] ) || count( $page_data['breadcrumbs'] ) <= 1 ) {
			return;
		}

		$breadcrumb_text = implode(
			' > ',
			array_map(
				function ( $crumb ) {
					return $crumb['title'];
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
	 * Add main content section with detailed error tracing.
	 *
	 * @param \SScribeVendor\PhpOffice\PhpWord\Element\Section $section The section.
	 * @param array                                            $page_data Page data.
	 */
	private function add_main_content( \SScribeVendor\PhpOffice\PhpWord\Element\Section $section, array $page_data ): void {
		$content     = $page_data['content'] ?? '';
		$content_len = strlen( $content );

		// CRITICAL: Don't skip content just because word_count is 0.
		// word_count can be 0 when content is only HTML tags (no visible text).
		// But DOM-parsed content may still have valid elements to render.
		// Only skip when content is truly empty.
		if ( empty( $content ) ) {
			$this->get_logger()->warning(
				'Main content skipped: content is empty',
				array(
					'page_id'    => $page_data['id'] ?? 0,
					'page_title' => $page_data['title'] ?? 'unknown',
					'word_count' => $page_data['word_count'] ?? 0,
				)
			);
			return;
		}

		$this->get_logger()->debug(
			'Parsing content for DOCX',
			array(
				'page_id'     => $page_data['id'] ?? 0,
				'content_len' => $content_len,
				'word_count'  => $page_data['word_count'] ?? 0,
			)
		);

		$section->addTitle( __( 'Content', 'sscribe-export-site-pages' ), 1 );

		$elements = $this->parser->parse( $content );

		$element_count = count( $elements );

		$this->get_logger()->debug(
			'Content parsed into elements',
			array(
				'page_id'       => $page_data['id'] ?? 0,
				'element_count' => $element_count,
				'content_len'   => $content_len,
			)
		);

		// CRITICAL: Log when no elements were extracted — indicates content parsing failure.
		if ( 0 === $element_count && $content_len > 0 ) {
			$this->get_logger()->error(
				'CRITICAL: Content parser returned zero elements',
				array(
					'page_id'         => $page_data['id'] ?? 0,
					'page_title'      => $page_data['title'] ?? 'unknown',
					'content_len'     => $content_len,
					'content_preview' => substr( $content, 0, 500 ),
				)
			);
		}

		foreach ( $elements as $element_index => $element ) {
			try {
				$this->render_element( $section, $element );
			} catch ( \Throwable $e ) {
				// Log but don't abort — a single bad element must not kill the entire page export.
				$ex_class         = get_class( $e );
				$ex_message       = $e->getMessage();
				$this->last_error = sprintf(
					'Element %d (%s) failed: %s%s',
					$element_index,
					$element['type'] ?? 'unknown',
					$ex_class,
					! empty( $ex_message ) ? ': ' . $ex_message : ' (no message)'
				);
				$this->get_logger()->warning(
					'Element render failed',
					array(
						'page_id'                 => $page_data['id'] ?? 0,
						'element_index'           => $element_index,
						'element_type'            => $element['type'] ?? 'unknown',
						'element_content_preview' => substr( $element['content'] ?? '', 0, 100 ),
						'error_class'             => $ex_class,
						'error_message'           => $ex_message,
						'error_file'              => basename( $e->getFile() ) . ':' . $e->getLine(),
					)
				);
				// Continue processing remaining elements.
			}
		}
	}

	/**
	 * Render a parsed element into the DOCX section.
	 *
	 * @param \SScribeVendor\PhpOffice\PhpWord\Element\Section $section The section.
	 * @param array                                            $element The parsed element.
	 */
	private function render_element( Section $section, array $element ): void {
		if ( empty( $element['type'] ) ) {
			return;
		}

		switch ( $element['type'] ) {
			case 'heading':
				$level = isset( $element['level'] ) ? min( $element['level'], 6 ) : 2;
				$section->addTitle( $this->safe_text( $element['content'] ), $level );
				break;

			case 'paragraph':
				$this->render_paragraph( $section, $element );
				break;

			case 'list':
				$this->render_list( $section, $element );
				break;

			case 'blockquote':
				$text_run = $section->addTextRun( $this->get_para_style( array( 'styleName' => 'Blockquote' ) ) );
				$this->render_runs( $text_run, $element['runs'], true );
				break;

			case 'code':
				$section->addText(
					$this->safe_text( $element['content'] ),
					array(
						'name'  => 'Courier New',
						'size'  => 9,
						'color' => $this->colors['heading'],
					),
					$this->get_para_style( array( 'styleName' => 'CodeBlock' ) )
				);
				break;

			case 'table':
				$this->render_table( $section, $element );
				break;

			case 'image':
				$this->render_inline_image( $section, $element );
				break;

			case 'button':
				$this->render_button( $section, $element );
				break;

			case 'break':
				$section->addTextBreak();
				break;

			case 'horizontal_rule':
				$section->addText(
					str_repeat( '—', 60 ),
					array(
						'size'  => 8,
						'color' => $this->colors['border'],
					),
					$this->get_para_style( array( 'alignment' => Jc::CENTER ) )
				);
				break;
		}
	}

	/**
	 * Render a paragraph with inline runs.
	 *
	 * @param \SScribeVendor\PhpOffice\PhpWord\Element\Section $section The section.
	 * @param array                                            $element The paragraph element.
	 */
	private function render_paragraph( Section $section, array $element ): void {
		if ( empty( $element['runs'] ) ) {
			return;
		}

		$text_run = $section->addTextRun( $this->get_para_style() );
		$this->render_runs( $text_run, $element['runs'] );
	}

	/**
	 * Render inline runs into a text run.
	 *
	 * @param \SScribeVendor\PhpOffice\PhpWord\Element\TextRun $text_run The text run container.
	 * @param array                                            $runs    Array of run data.
	 * @param bool                                             $italic  Force italic (for blockquotes).
	 * @param bool                                             $bold    Force bold (for table headers).
	 */
	private function render_runs( TextRun $text_run, array $runs, bool $italic = false, bool $bold = false ): void {
		foreach ( $runs as $run ) {
			if ( ! isset( $run['text'] ) || '' === $run['text'] ) {
				continue;
			}

			if ( isset( $run['break'] ) && $run['break'] ) {
				$text_run->addTextBreak();
				continue;
			}

			$font_style = array(
				'name'  => $this->font_name,
				'size'  => $this->font_size,
				'color' => $this->colors['body'],
			);

			if ( $this->is_rtl ) {
				$font_style['bidi']          = true;
				$font_style['rtl']           = true;
				$font_style['complexScript'] = true;
			}

			if ( ! empty( $run['bold'] ) || $bold ) {
				$font_style['bold'] = true;
			}
			if ( ! empty( $run['italic'] ) || $italic ) {
				$font_style['italic'] = true;
			}
			if ( ! empty( $run['underline'] ) ) {
				$font_style['underline'] = 'single';
			}
			if ( ! empty( $run['strikethrough'] ) ) {
				$font_style['strikethrough'] = true;
			}
			if ( ! empty( $run['code'] ) ) {
				$font_style['name'] = 'Courier New';
				$font_style['size'] = 9;
			}

			$text_content = $this->safe_text( $run['text'] );

			if ( ! empty( $run['link'] ) ) {
				$link_url = $this->validate_url( $run['link'] );
				if ( ! empty( $link_url ) ) {
					$font_style['color'] = $this->colors['link'];
					$text_run->addLink(
						$link_url,
						$text_content,
						$font_style
					);
					$display_url      = urldecode( $link_url );
					$url_path_decoded = trim( wp_parse_url( $display_url, PHP_URL_PATH ), '/' );
					if ( $text_content !== $url_path_decoded && $text_content !== $link_url ) {
						$text_run->addText(
							' (' . $this->safe_text( $display_url ) . ')',
							$this->with_complex_script(
								array(
									'name'  => $this->font_name,
									'size'  => 8,
									'color' => $this->colors['body'],
								)
							)
						);
					}
				} else {
					$text_run->addText( $text_content, $font_style );
				}
			} else {
				$text_run->addText( $text_content, $font_style );
			}
		}
	}

	/**
	 * Render a list element.
	 *
	 * @param \SScribeVendor\PhpOffice\PhpWord\Element\Section $section The section.
	 * @param array                                            $element The list element.
	 */
	private function render_list( Section $section, array $element ): void {
		$style = isset( $element['style'] ) ? $element['style'] : 'bullet';

		if ( ! isset( $element['items'] ) ) {
			return;
		}

		$list_type = ( 'numbered' === $style )
			? \SScribeVendor\PhpOffice\PhpWord\Style\ListItem::TYPE_NUMBER
			: \SScribeVendor\PhpOffice\PhpWord\Style\ListItem::TYPE_BULLET_FILLED;

		foreach ( $element['items'] as $item ) {
			$depth = isset( $item['depth'] ) ? $item['depth'] : 0;

			// CRITICAL: Apply bidi/rtl/complexScript to the font style for RTL documents.
			// Without these, Arabic/Hebrew text in list items renders as squares in Word
			// because the font lacks complex script shaping instructions.
			$list_font_style = array(
				'name'  => $this->font_name,
				'size'  => $this->font_size,
				'color' => $this->colors['body'],
			);
			if ( $this->is_rtl ) {
				$list_font_style['bidi']          = true;
				$list_font_style['rtl']           = true;
				$list_font_style['complexScript'] = true;
			}

			$section->addListItem(
				$this->safe_text( $item['content'] ),
				$depth,
				$list_font_style,
				array_merge( array( 'listType' => $list_type ), $this->get_para_style() )
			);

			// Render nested children.
			if ( ! empty( $item['children'] ) ) {
				foreach ( $item['children'] as $child ) {
					$child_depth = isset( $child['depth'] ) ? $child['depth'] : $depth + 1;
					$section->addListItem(
						$this->safe_text( $child['content'] ),
						$child_depth,
						$list_font_style,
						array_merge( array( 'listType' => $list_type ), $this->get_para_style() )
					);
				}
			}
		}
	}

	/**
	 * Render an HTML table into DOCX.
	 *
	 * @param \SScribeVendor\PhpOffice\PhpWord\Element\Section $section The section.
	 * @param array                                            $element The table element.
	 */
	private function render_table( Section $section, array $element ): void {
		if ( empty( $element['rows'] ) ) {
			return;
		}

		// Calculate column count from first row.
		$col_count = ! empty( $element['rows'][0]['cells'] )
			? count( $element['rows'][0]['cells'] )
			: 0;

		if ( 0 === $col_count ) {
			return;
		}

		// Total content width: 6.5 inches (US Letter - 1" margins each side).
		$total_width_twip = Converter::inchToTwip( 6.5 );
		$cell_width       = (int) ( $total_width_twip / $col_count );

		$table_unit = \SScribeVendor\PhpOffice\PhpWord\SimpleType\TblWidth::TWIP;

		$table_style = array(
			'borderSize'  => 1,
			'borderColor' => $this->colors['border'],
			'cellMargin'  => Converter::cmToTwip( 0.1 ),
			'unit'        => $table_unit,
			'width'       => $total_width_twip,
		);

		$table = $section->addTable( $table_style );

		foreach ( $element['rows'] as $row ) {
			$table->addRow();
			foreach ( $row['cells'] as $cell ) {
				$cell_style = array();
				$font_style = array(
					'name'  => $this->font_name,
					'size'  => 10,
					'color' => $this->colors['body'],
				);

				// Apply RTL font settings for Arabic/Hebrew table content.
				if ( $this->is_rtl ) {
					$font_style['bidi']          = true;
					$font_style['rtl']           = true;
					$font_style['complexScript'] = true;
				}

				if ( ! empty( $cell['is_header'] ) ) {
					$cell_style['bgColor'] = $this->colors['light_bg'];
					$font_style['bold']    = true;
					$font_style['color']   = $this->colors['heading'];
				}

				$cell_obj = $table->addCell( $cell_width, $cell_style );
				if ( ! empty( $cell['runs'] ) ) {
					$text_run = $cell_obj->addTextRun( $this->get_para_style() );
					$this->render_runs( $text_run, $cell['runs'], false, ! empty( $cell['is_header'] ) );
				} else {
					$cell_obj->addText(
						$this->safe_text( $cell['content'] ),
						$font_style,
						$this->get_para_style()
					);
				}
			}
		}

		$section->addTextBreak( 1 );
	}

	/**
	 * Render a button element.
	 *
	 * @param \SScribeVendor\PhpOffice\PhpWord\Element\Section $section The section.
	 * @param array                                            $element The button element.
	 */
	private function render_button( Section $section, array $element ): void {
		$table = $section->addTable(
			array(
				'borderSize'  => 6,
				'borderColor' => $this->colors['primary'],
				'cellMargin'  => Converter::cmToTwip( 0.2 ),
				'alignment'   => Jc::CENTER,
			)
		);

		$table->addRow();
		$cell = $table->addCell( Converter::inchToTwip( 5 ), array( 'bgColor' => $this->colors['light_bg'] ) );

		$cell->addText(
			'[ACTION BUTTON] ' . $this->safe_text( ! empty( $element['content'] ) ? $element['content'] : __( 'Click Here', 'sscribe-export-site-pages' ) ),
			array(
				'name'  => $this->font_name,
				'size'  => 10,
				'bold'  => true,
				'color' => $this->colors['primary'],
			),
			$this->get_para_style( array( 'alignment' => Jc::CENTER ) )
		);

		if ( ! empty( $element['url'] ) ) {
			// CRITICAL: Validate URL before rendering to prevent XSS attacks.
			// Button URLs come from parsed HTML and may contain malicious content.
			$validated_url = $this->validate_url( $element['url'] );

			if ( ! empty( $validated_url ) ) {
				$cell->addText(
					__( 'DESTINATION URL:', 'sscribe-export-site-pages' ),
					array(
						'name'  => $this->font_name,
						'size'  => 8,
						'bold'  => true,
						'color' => $this->colors['heading'],
					),
					$this->get_para_style(
						array(
							'alignment'   => Jc::CENTER,
							'spaceBefore' => Converter::pointToTwip( 6 ),
						)
					)
				);
				$cell->addLink(
					$validated_url,
					$this->safe_text( $element['url'] ),
					array(
						'name'      => $this->font_name,
						'size'      => 9,
						'color'     => $this->colors['link'],
						'underline' => 'single',
					),
					$this->get_para_style( array( 'alignment' => Jc::CENTER ) )
				);
			}
		}

		$section->addTextBreak( 1 );
	}

	/**
	 * Render an inline image.
	 *
	 * @param \SScribeVendor\PhpOffice\PhpWord\Element\Section $section The section.
	 * @param array                                            $element The image element.
	 */
	private function render_inline_image( Section $section, array $element ): void {
		$path = ! empty( $element['local_path'] ) ? $element['local_path'] : '';
		$src  = ! empty( $element['src'] ) ? $element['src'] : __( 'Unknown URL', 'sscribe-export-site-pages' );

		if ( empty( $path ) || ! file_exists( $path ) ) {
			// Show placeholder text if image processing failed.
			$alt = ! empty( $element['alt'] ) ? $element['alt'] : __( 'No Alt Text Provided', 'sscribe-export-site-pages' );
			$section->addText(
				__( '[MISSING IMAGE] ', 'sscribe-export-site-pages' ) . $this->safe_text( $alt ),
				array(
					'name'   => $this->font_name,
					'size'   => 9,
					'italic' => true,
					'color'  => 'EF4444',
				),
				$this->get_para_style( array( 'alignment' => Jc::CENTER ) )
			);
		} elseif ( is_readable( $path ) ) {
			$image_info = getimagesize( $path );
			if ( $image_info ) {
				$max_width  = Converter::inchToEmu( 5.5 );
				$width_emu  = Converter::pixelToEmu( $image_info[0] );
				$height_emu = Converter::pixelToEmu( $image_info[1] );

				// Always scale to target width (both up and down) for uniform appearance.
				$ratio      = $max_width / $width_emu;
				$width_emu  = $max_width;
				$height_emu = (int) ( $height_emu * $ratio );

				$section->addImage(
					$path,
					array(
						'width'     => Converter::emuToPixel( $width_emu ),
						'height'    => Converter::emuToPixel( $height_emu ),
						'alignment' => Jc::CENTER,
					)
				);
			}
		}

		// Render professional image asset box below.
		$table = $section->addTable(
			array(
				'borderSize'  => 4,
				'borderColor' => $this->colors['border'],
				'cellMargin'  => Converter::cmToTwip( 0.1 ),
				'alignment'   => Jc::CENTER,
			)
		);
		$table->addRow();
		$cell = $table->addCell( Converter::inchToTwip( 5.5 ), array( 'bgColor' => 'F8FAFC' ) );
		$cell->addText(
			__( 'IMAGE ASSET SOURCE URL:', 'sscribe-export-site-pages' ),
			array(
				'name'  => $this->font_name,
				'size'  => 7,
				'bold'  => true,
				'color' => $this->colors['heading'],
			),
			$this->get_para_style()
		);
		$cell->addText(
			$this->safe_text( $src ),
			array(
				'name'  => 'Courier New',
				'size'  => 8,
				'color' => $this->colors['link'],
			),
			$this->get_para_style()
		);

		$section->addTextBreak( 1 );
	}

	/**
	 * Add child pages list.
	 *
	 * @param \SScribeVendor\PhpOffice\PhpWord\Element\Section $section   The section.
	 * @param array                                            $page_data Page data.
	 */
	private function add_child_pages( \SScribeVendor\PhpOffice\PhpWord\Element\Section $section, array $page_data ): void {
		if ( empty( $page_data['children'] ) ) {
			return;
		}

		$section->addTextBreak( 1 );
		$section->addTitle( __( 'Child Pages', 'sscribe-export-site-pages' ), 2 );

		foreach ( $page_data['children'] as $child ) {
			$child_url = $this->validate_url( $child['url'] ?? '' );
			if ( empty( $child_url ) ) {
				continue;
			}
			$text_run = $section->addTextRun( $this->get_para_style() );
			$text_run->addText(
				'> ',
				array(
					'size'  => 10,
					'bold'  => true,
					'color' => $this->colors['primary'],
				)
			);
			$text_run->addLink(
				$child_url,
				$this->safe_text( $child['title'] ),
				array(
					'name'  => $this->font_name,
					'size'  => $this->font_size,
					'color' => $this->colors['link'],
				)
			);
			$text_run->addText(
				' — ' . $this->safe_text( $child['url'] ),
				array(
					'name'  => $this->font_name,
					'size'  => 8,
					'color' => $this->colors['body'],
				)
			);
		}
	}

	/**
	 * Get or create a logger instance.
	 *
	 * @return SScribe_Logger_Interface
	 */
	private function get_logger(): SScribe_Logger_Interface {
		if ( null === $this->logger ) {
			$this->logger = SScribe_Logger::instance( defined( 'SSCRIBE_DEBUG' ) && SSCRIBE_DEBUG );
		}
		return $this->logger;
	}
}
