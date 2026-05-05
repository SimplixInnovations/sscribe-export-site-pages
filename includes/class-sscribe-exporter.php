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
	 * Font name for RTL text (Arabic-capable).
	 *
	 * @var string
	 */
	private string $rtl_font_name = 'Noto Sans Arabic';

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

		// 0. Decode percent-encoded URLs so they display as readable text (e.g. %D9%84%D8%B9%D8%B1%D8%A7%D9%86 →发展有限公司).
		// This must happen before any XML character filtering so we don't double-decode.
		if ( str_starts_with( $text, 'http' ) || str_starts_with( $text, '//' ) ) {
			$text = urldecode( $text );
		}

		// 1. Remove XML 1.0 illegal control characters (keep \t, \n, \r).
		$text = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text );

		// 2. Remove XML non-characters: U+FFFE and U+FFFF.
		$text = preg_replace( '/[\x{FFFE}\x{FFFF}]/u', '', $text );

		// 3. Remove Unicode surrogate code points (U+D800-U+DFFF).
		// These are encoded as 4-byte UTF-8 sequences starting with 0xED.
		$text = preg_replace( '/\xED[\xA0-\xBF][\x80-\xBF]/', '', $text );

		// 4. Replace zero-width and invisible formatting chars that cause display issues.
		$text = preg_replace( '/[\x{200B}\x{FEFF}\x{00AD}]/u', '', $text );

		// IMPORTANT: We do NOT strip astral plane characters (emoji, symbols) or
		// Private Use Area characters, as PHPWord handles them correctly with proper
		// UTF-8 encoding and they may be legitimate content in user pages.

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
			return esc_url_raw( site_url( $url ) );
		}

		$parsed_scheme = wp_parse_url( $url, PHP_URL_SCHEME );
		$scheme       = strtolower( ( false === $parsed_scheme || null === $parsed_scheme ) ? '' : $parsed_scheme );

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
		if ( $this->is_rtl && ! isset( $font_def['complexScript'] ) ) {
			$font_def['complexScript'] = array(
				'name' => $font_def['name'] ?? $this->font_name,
			);
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
			$base_style['bidi']      = true;
			$base_style['alignment'] = Jc::END;
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
			$this->is_rtl = $this->is_rtl_document( $page_data );

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
				$heading_font['complexScript'] = array( 'name' => $this->font_name );
			}
			$php_word->addTitleStyle(
				$i,
				$heading_font,
				array(
					'spaceBefore' => Converter::pointToTwip( $i <= 2 ? 18 : 12 ),
					'spaceAfter'  => Converter::pointToTwip( 6 ),
					'keepNext'    => true,
					'bidi'        => $this->is_rtl,
				)
			);
		}

		// Blockquote paragraph style.
		$php_word->addParagraphStyle(
			'Blockquote',
			array(
				'indentation'     => array( 'left' => Converter::cmToTwip( 1 ) ),
				'spaceBefore'     => Converter::pointToTwip( 6 ),
				'spaceAfter'      => Converter::pointToTwip( 6 ),
				'borderLeftSize'  => 12,
				'borderLeftColor' => $this->colors['primary'],
			)
		);

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
					get_bloginfo( 'name' )
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
			sprintf( __( 'Extracted Date: %s', 'sscribe-export-site-pages' ), wp_date( ( get_option( 'date_format' ) ?: 'Y-m-d' ) . ' ' . ( get_option( 'time_format' ) ?: 'H:i' ) ) ),
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

		$tab_leader = defined( '\\SScribeVendor\\PhpOffice\\PhpWord\\Style\\TOC::TAB_LEADER_DOT' )
			? \SScribeVendor\PhpOffice\PhpWord\Style\TOC::TAB_LEADER_DOT
			: '.';

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
			$this->logger->warning(
				'TOC generation failed, omitting table of contents',
				array(
					'error' => $e->getMessage(),
					'font'  => $this->font_name,
				)
			);
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
			$this->safe_text( get_bloginfo( 'name' ) ),
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
			array( 'alignment' => Jc::END )
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
			array( 'alignment' => Jc::END )
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
				$this->logger->debug( 'Skipping SVG featured image', array( 'path' => $path ) );
				return;
			}

			// Calculate dimensions to fit within 6.5 inches width.
			$max_width  = Converter::inchToEmu( 6.5 );
			$max_height = Converter::inchToEmu( 4 );

			$width_emu  = Converter::pixelToEmu( $image_info[0] );
			$height_emu = Converter::pixelToEmu( $image_info[1] );

			// Scale down if necessary.
			if ( $width_emu > $max_width ) {
				$ratio      = $max_width / $width_emu;
				$width_emu  = $max_width;
				$height_emu = (int) ( $height_emu * $ratio );
			}
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
	 */
	private function render_runs( TextRun $text_run, array $runs, bool $italic = false ): void {
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
				$font_style['rtl']          = true;
				$font_style['complexScript'] = array( 'name' => $this->font_name );
			}

			if ( ! empty( $run['bold'] ) ) {
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
					$display_url = urldecode( $link_url );
					if ( $text_content !== $display_url && $text_content !== $link_url ) {
						$text_run->addText(
							' (' . $this->safe_text( $display_url ) . ')',
							array(
								'name'  => $this->font_name,
								'size'  => 8,
								'color' => $this->colors['body'],
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

		$list_type = ( 'numbered' === $style ) ? \SScribeVendor\PhpOffice\PhpWord\Style\ListItem::TYPE_NUMBER : \SScribeVendor\PhpOffice\PhpWord\Style\ListItem::TYPE_BULLET_FILLED;

		foreach ( $element['items'] as $item ) {
			$depth = isset( $item['depth'] ) ? $item['depth'] : 0;

			$section->addListItem(
				$this->safe_text( $item['content'] ),
				$depth,
				array(
					'name'  => $this->font_name,
					'size'  => $this->font_size,
					'color' => $this->colors['body'],
				),
				array_merge( array( 'listType' => $list_type ), $this->get_para_style() )
			);

			// Render nested children.
			if ( ! empty( $item['children'] ) ) {
				foreach ( $item['children'] as $child ) {
					$child_depth = isset( $child['depth'] ) ? $child['depth'] : $depth + 1;
					$section->addListItem(
						$this->safe_text( $child['content'] ),
						$child_depth,
						array(
							'name'  => $this->font_name,
							'size'  => $this->font_size,
							'color' => $this->colors['body'],
						),
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
			: 1;

		// Total content width: 6.5 inches (US Letter - 1" margins each side).
		$total_width_twip = Converter::inchToTwip( 6.5 );
		$cell_width       = (int) ( $total_width_twip / $col_count );

		$table_style = array(
			'borderSize'  => 1,
			'borderColor' => $this->colors['border'],
			'cellMargin'  => Converter::cmToTwip( 0.1 ),
			'unit'        => \SScribeVendor\PhpOffice\PhpWord\SimpleType\TblWidth::TWIP,
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

				if ( ! empty( $cell['is_header'] ) ) {
					$cell_style['bgColor'] = $this->colors['light_bg'];
					$font_style['bold']    = true;
					$font_style['color']   = $this->colors['heading'];
				}

				$table->addCell( $cell_width, $cell_style )->addText(
					$this->safe_text( $cell['content'] ),
					$font_style,
					$this->get_para_style()
				);
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

				if ( $width_emu > $max_width ) {
					$ratio      = $max_width / $width_emu;
					$width_emu  = $max_width;
					$height_emu = (int) ( $height_emu * $ratio );
				}

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
		$cell = $table->addCell( Converter::inchToTwip( 5.5 ), array( 'bgColor' => '#F8FAFC' ) );
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
				$child['url'],
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
