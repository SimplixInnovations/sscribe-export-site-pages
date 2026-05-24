<?php
/**
 * SScribe Exporter
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

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

class SScribe_Exporter {

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
	private string $rtl_font_name = 'Arial';

	/**
	 * Base font size.
	 *
	 * @var int
	 */
	private int $font_size = 11;

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
	 * Initialize the exporter.
	 *
	 * @param SScribe_Content_Parser|null     $parser           Content parser.
	 * @param SScribe_DOCX_Content_Renderer|null $content_renderer Content renderer.
	 */
	public function __construct(
		?SScribe_Content_Parser $parser = null,
		?SScribe_DOCX_Content_Renderer $content_renderer = null
	) {
		$this->parser = $parser ?? new SScribe_Content_Parser();
		$this->content_renderer = $content_renderer ?? new SScribe_DOCX_Content_Renderer(
			$this->parser,
			null,
			$this->colors,
			$this->is_rtl,
			$this->font_name,
			$this->font_size
		);
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

		$cleaned = mb_convert_encoding( $text, 'UTF-8', 'UTF-8' );
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

		// Truncate extremely long strings without spaces (e.g., URLs, hashes, encoded data)
		// to prevent oversized XML elements in DOCX. Threshold is 200 Unicode chars.
		if ( mb_strlen( $text, 'UTF-8' ) > 200 && false === mb_strpos( $text, ' ', 0, 'UTF-8' ) ) {
			$text = mb_substr( $text, 0, 200, 'UTF-8' );
		}

		// NOTE: Do NOT apply htmlspecialchars() here. PHPWord performs its own
		// XML encoding internally (PhpWord >= 1.5), so htmlspecialchars would cause
		// double-encoding. The character-stripping logic above is sufficient.

		return $text;
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
	 * Check if page data indicates an RTL document.
	 *
	 * @param array $page_data Page data.
	 * @return bool
	 */
	private function is_rtl_document( array $page_data ): bool {
		require_once SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-rtl-helper.php';
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
	 * @param array  $page_data Page data to export.
	 * @param string $output_dir Output directory path.
	 * @param int    $index     Current page index.
	 * @param int    $total     Total number of pages.
	 * @return string|false Output file path or false on failure.
	 * @throws \RuntimeException If DOCX generation fails integrity checks.
	 */
	public function generate_docx( array $page_data, string $output_dir, int $index = 0, int $total = 0 ): string|false {
		if ( empty( $page_data ) || ! is_dir( $output_dir ) ) {
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
			}

			$php_word = new PhpWord();

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

			$writer = IOFactory::createWriter( $php_word, 'Word2007' );
			$writer->save( $output_path );

			// Lightweight integrity check: verify file size > minimum threshold.
			$file_size = filesize( $output_path );
			$min_size   = 4096; // Minimal DOCX should be at least 4KB to avoid empty/corrupted files.
			if ( $file_size < $min_size ) {
				wp_delete_file( $output_path );
				unset( $writer, $php_word );
				throw new \RuntimeException( 'DOCX file size below minimum threshold' );
			}

			// Structural integrity check: always run in production to catch corrupted files.
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

			if ( ! defined( 'SSCRIBE_DEBUG' ) || ! SSCRIBE_DEBUG ) {
				unset( $writer, $php_word );
				return $output_path;
			}

			// Deep XML validation — only in debug mode.
			$xml_valid = true;
			if ( $has_document ) {
				$zip_xml = new \ZipArchive();
				$zip_xml->open( $output_path );
				$doc_xml = $zip_xml->getFromName( 'word/document.xml' );
				if ( false !== $doc_xml && ! empty( $doc_xml ) ) {
					$prev_xml_errors = libxml_use_internal_errors( true );
					$test_doc        = new \DOMDocument();
					$parse_result    = $test_doc->loadXML( $doc_xml );
					$xml_errors      = libxml_get_errors();
					libxml_clear_errors();
					libxml_use_internal_errors( $prev_xml_errors );

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
					unset( $test_doc, $doc_xml, $zip_xml );
				}
			}

			// $has_document and $has_types guaranteed true here (early throw above).
			// Only $xml_valid may be false when debug mode is enabled.
			if ( ! $xml_valid ) {
				wp_delete_file( $output_path );
				unset( $writer, $php_word );
				throw new \RuntimeException( 'DOCX integrity check failed: XML validation error' );
			}

			unset( $writer, $php_word );

			return $output_path;

		} catch ( \Throwable $e ) {

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
	 * Set document metadata properties.
	 *
	 * @param PhpWord $php_word  PhpWord instance.
	 * @param array   $page_data Page data.
	 * @return void
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
	 * Get section settings for document layout.
	 *
	 * @param bool $is_rtl Whether RTL mode.
	 * @return array Section settings.
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

		return apply_filters( 'sscribe_docx_section_settings', $settings, $is_rtl );
	}

	/**
	 * Add cover page to the document.
	 *
	 * @param \SScribeVendor\PhpOffice\PhpWord\Element\Section $section   Document section.
	 * @param array                                            $page_data Page data.
	 * @return void
	 */
	private function add_cover_page( \SScribeVendor\PhpOffice\PhpWord\Element\Section $section, array $page_data ): void {

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

			unset( $e );
		}

		$section->addPageBreak();
	}

	/**
	 * Add header and footer to document section.
	 *
	 * @param \SScribeVendor\PhpOffice\PhpWord\Element\Section $section   Document section.
	 * @param array                                            $page_data Page data.
	 * @return void
	 */
	private function add_header_footer( \SScribeVendor\PhpOffice\PhpWord\Element\Section $section, array $page_data ): void {

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
	 * Add featured image to document section.
	 *
	 * @param \SScribeVendor\PhpOffice\PhpWord\Element\Section $section   Document section.
	 * @param array                                            $page_data Page data.
	 * @return void
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

			$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
			if ( 'svg' === $ext ) {
				$this->get_logger()->debug( 'Skipping SVG featured image', array( 'path' => $path ) );
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
				$ratio      = $max_width / $width_emu;
				$width_emu  = $max_width;
				$height_emu = (int) ( $height_emu * $ratio );

				if ( $height_emu > $max_height ) {
					$ratio      = $max_height / $height_emu;
					$height_emu = $max_height;
					$width_emu  = (int) ( $width_emu * $ratio );
				}
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

			return;
		}
	}

	/**
	 * Add page information table to document.
	 *
	 * @param \SScribeVendor\PhpOffice\PhpWord\Element\Section $section   Document section.
	 * @param array                                            $page_data Page data.
	 * @return void
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
	 * Add SEO information section to document.
	 *
	 * @param \SScribeVendor\PhpOffice\PhpWord\Element\Section $section   Document section.
	 * @param array                                            $page_data Page data.
	 * @return void
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
	 * Add breadcrumbs to document.
	 *
	 * @param \SScribeVendor\PhpOffice\PhpWord\Element\Section $section   Document section.
	 * @param array                                            $page_data Page data.
	 * @return void
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
	 * Add child pages section to document.
	 *
	 * @param \SScribeVendor\PhpOffice\PhpWord\Element\Section $section   Document section.
	 * @param array                                            $page_data Page data.
	 * @return void
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
	 * Get or create the logger instance.
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
