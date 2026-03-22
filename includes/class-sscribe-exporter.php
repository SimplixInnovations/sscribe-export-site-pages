<?php
/**
 * Generates DOCX documents from page data using PHPWord.
 *
 * @package SScribe
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\Style\Font;
use PhpOffice\PhpWord\SimpleType\Jc;
use PhpOffice\PhpWord\Shared\Converter;

/**
 * Class SScribe_Exporter
 *
 * Creates professional DOCX documents from structured page data.
 */
class SScribe_Exporter {


	/**
	 * Content parser instance.
	 *
	 * @var SScribe_Content_Parser
	 */
	private $parser;

	/**
	 * SEO reader instance.
	 *
	 * @var SScribe_SEO_Reader
	 */
	private $seo_reader;

	/**
	 * Font name for normal text.
	 *
	 * @var string
	 */
	private $font_name = 'Arial';

	/**
	 * Font size for normal text (in points).
	 *
	 * @var int
	 */
	private $font_size = 11;

	/**
	 * Document colors.
	 *
	 * @var array
	 */
	private $colors = array(
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
	 */
	public function __construct() {
		$this->parser     = new SScribe_Content_Parser();
		$this->seo_reader = new SScribe_SEO_Reader();
	}

	/**
	 * Make a string safe for PHPWord / XML output.
	 *
	 * Strips emoji (astral Unicode planes), control characters, and
	 * escapes XML entities so PHPWord never produces corrupt XML.
	 *
	 * @param mixed $text Input value (will be cast to string).
	 * @return string Cleaned, XML-safe string.
	 */
	private function safe_text( $text ) {
		$text = (string) $text;

		// 1. Remove astral-plane Unicode (emoji, symbols above U+FFFF).
		$text = preg_replace( '/[\x{10000}-\x{10FFFF}]/u', '', $text );

		// 2. Remove XML-illegal control characters (keep tab, newline, carriage return).
		$text = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text );

		// Do NOT call htmlspecialchars() here — PHPWord handles XML escaping internally.
		// Calling it here causes double-encoding (&amp; → &amp;amp; in output).

		return $text;
	}

	/**
	 * Determine if the document should use RTL text direction.
	 *
	 * @param array $page_data Page data.
	 * @return bool True if RTL.
	 */
	private function is_rtl_document( $page_data ) {
		$rtl_languages = array( 'ar', 'he', 'fa', 'ur', 'ps', 'ku', 'sd' );
		$lang = ! empty( $page_data['language'] ) ? substr( $page_data['language'], 0, 2 ) : 'en';
		return in_array( $lang, $rtl_languages, true );
	}

	/**
	 * Generate a DOCX file for a single page.
	 *
	 * @param array  $page_data Page data from SScribe_Page_Collector.
	 * @param string $output_dir Directory to save the DOCX file.
	 * @return string|false Path to generated DOCX or false on failure.
	 */
	public function generate_docx( $page_data, $output_dir ) {
		if ( empty( $page_data ) || ! is_dir( $output_dir ) ) {
			return false;
		}

		try {
			// Force ZipArchive — prevents PHPWord from ever loading bundled PCLZip.
			if ( class_exists( 'ZipArchive' ) ) {
				\PhpOffice\PhpWord\Settings::setZipClass( \PhpOffice\PhpWord\Settings::ZIPARCHIVE );
			}

			$php_word = new PhpWord();

			// Determine RTL setting for the document.
			$is_rtl = $this->is_rtl_document( $page_data );

			// Set document properties.
			$this->set_document_properties( $php_word, $page_data );

			// Set default styles.
			$this->set_default_styles( $php_word );

			// Define custom styles.
			$this->define_styles( $php_word );

			// --- Section 1: Cover Page ---
			$cover = $php_word->addSection( $this->get_section_settings( $is_rtl ) );
			$this->add_cover_page( $cover, $page_data );

			// --- Section 2: Content ---
			$content_section = $php_word->addSection( $this->get_section_settings( $is_rtl ) );

			// Add header and footer.
			$this->add_header_footer( $content_section, $page_data );

			// Add featured image.
			$this->add_featured_image( $content_section, $page_data );

			// Add page info table.
			$this->add_page_info_table( $content_section, $page_data );

			// Add SEO section.
			$this->add_seo_section( $content_section, $page_data );

			// Add breadcrumb trail.
			$this->add_breadcrumbs( $content_section, $page_data );

			// Add main content.
			$this->add_main_content( $content_section, $page_data );

			// Add child pages.
			$this->add_child_pages( $content_section, $page_data );

			// Save document.
			$safe_slug = sanitize_file_name( $page_data['slug'] );

			// Prefix with page ID to guarantee uniqueness.
			// Format: {ID}-{slug}.docx so it's still human-readable.
			$filename    = $page_data['id'] . '-' . $safe_slug . '.docx';
			$output_path = trailingslashit( $output_dir ) . $filename;

			$writer = IOFactory::createWriter( $php_word, 'Word2007' );
			$writer->save( $output_path );

			return $output_path;

		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'SScribe Export Error [Page ' . ( $page_data['id'] ?? 'unknown' ) . ']: ' . $e->getMessage() );
			}
			return false;
		}
	}

	/**
	 * Set document metadata properties.
	 *
	 * @param PhpWord $php_word  The PhpWord instance.
	 * @param array   $page_data Page data.
	 */
	private function set_document_properties( $php_word, $page_data ) {
		$properties = $php_word->getDocInfo();
		$properties->setCreator( 'SScribe by Simplix Innovations' );
		$properties->setCompany( get_bloginfo( 'name' ) );
		$properties->setTitle( $page_data['title'] );
		$properties->setDescription( 'Exported from ' . $page_data['permalink'] );
		$properties->setLastModifiedBy( $page_data['author'] );
	}

	/**
	 * Set default font and paragraph styles.
	 *
	 * @param PhpWord $php_word The PhpWord instance.
	 */
	private function set_default_styles( $php_word ) {
		$php_word->setDefaultFontName( $this->font_name );
		$php_word->setDefaultFontSize( $this->font_size );
		$php_word->setDefaultParagraphStyle(
			array(
				'spaceAfter'  => Converter::pointToTwip( 6 ),
				'spaceBefore' => Converter::pointToTwip( 2 ),
				'lineHeight'  => 1.15,
			)
		);
	}

	/**
	 * Define named styles for headings, etc.
	 *
	 * @param PhpWord $php_word The PhpWord instance.
	 */
	private function define_styles( $php_word ) {
		// Heading styles.
		$heading_sizes = array( 24, 20, 16, 14, 12, 11 );
		for ( $i = 1; $i <= 6; $i++ ) {
			$php_word->addTitleStyle(
				$i,
				array(
					'name'  => $this->font_name,
					'size'  => $heading_sizes[ $i - 1 ],
					'bold'  => true,
					'color' => $this->colors['heading'],
				),
				array(
					'spaceBefore' => Converter::pointToTwip( $i <= 2 ? 18 : 12 ),
					'spaceAfter'  => Converter::pointToTwip( 6 ),
					'keepNext'    => true,
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
	private function get_section_settings( $is_rtl = false ) {
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
	 * @param \PhpOffice\PhpWord\Element\Section $section The section.
	 * @param array                              $page_data Page data.
	 */
	private function add_cover_page( $section, $page_data ) {
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
			array(
				'alignment'   => Jc::CENTER,
				'spaceBefore' => Converter::pointToTwip( 12 ),
				'spaceAfter'  => Converter::pointToTwip( 12 ),
			)
		);

		$section->addTextBreak( 4 );

		// Huge page title.
		$section->addText(
			$this->safe_text( mb_strtoupper( (string) $page_data['title'], 'UTF-8' ) ),
			array(
				'name'  => $this->font_name,
				'size'  => 28,
				'bold'  => true,
				'color' => $this->colors['heading'],
			),
			array( 'alignment' => Jc::CENTER )
		);

		$section->addTextBreak( 1 );

		// The URL link prominently displayed.
		$section->addLink(
			$page_data['permalink'],
			$this->safe_text( $page_data['permalink'] ),
			array(
				'name'      => $this->font_name,
				'size'      => 12,
				'color'     => $this->colors['link'],
				'underline' => 'single',
			),
			array( 'alignment' => Jc::CENTER )
		);

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
			esc_html__( 'DOCUMENT BLUEPRINT OVERVIEW', 'sscribe-export-site-pages' ),
			array(
				'name'  => $this->font_name,
				'size'  => 11,
				'bold'  => true,
				'color' => $this->colors['heading'],
			),
			array( 'alignment' => Jc::CENTER )
		);
		$meta_cell->addText(
			/* translators: %s: language code */
			sprintf( esc_html__( 'Target Language: %s', 'sscribe-export-site-pages' ), $page_data['language'] ),
			array(
				'name'  => $this->font_name,
				'size'  => 10,
				'color' => $this->colors['body'],
			),
			array( 'alignment' => Jc::CENTER )
		);
		$meta_cell->addText(
			/* translators: %s: export date */
			sprintf( esc_html__( 'Extracted Date: %s', 'sscribe-export-site-pages' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ),
			array(
				'name'  => $this->font_name,
				'size'  => 10,
				'color' => $this->colors['body'],
			),
			array( 'alignment' => Jc::CENTER )
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
				array(
					'name'   => $this->font_name,
					'size'   => 9,
					'color'  => $this->colors['body'],
					'italic' => true,
				),
				array( 'alignment' => Jc::CENTER )
			);
		}

		$section->addPageBreak();

		// Table of contents.
		$section->addText(
			esc_html__( 'TABLE OF CONTENTS', 'sscribe-export-site-pages' ),
			array(
				'name'  => $this->font_name,
				'size'  => 16,
				'bold'  => true,
				'color' => $this->colors['heading'],
			),
			array( 'spaceAfter' => Converter::pointToTwip( 12 ) )
		);

		$section->addTOC(
			array(
				'name' => $this->font_name,
				'size' => 11,
			),
			array( 'tabLeader' => \PhpOffice\PhpWord\Style\TOC::TAB_LEADER_DOT ),
			1,
			3
		);

		$section->addPageBreak();
	}

	/**
	 * Add header and footer to a section.
	 *
	 * @param \PhpOffice\PhpWord\Element\Section $section   The section.
	 * @param array                              $page_data Page data.
	 */
	private function add_header_footer( $section, $page_data ) {
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
			$this->safe_text( $page_data['permalink'] ),
			array(
				'name'  => $this->font_name,
				'size'  => 7,
				'color' => $this->colors['body'],
			)
		);
		$cell = $footer_table->addCell( Converter::inchToTwip( 2.5 ) );
		$cell->addPreserveText(
			esc_html__( 'Page', 'sscribe-export-site-pages' ) . ' {PAGE} / {NUMPAGES}',
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
	 * @param \PhpOffice\PhpWord\Element\Section $section   The section.
	 * @param array                              $page_data Page data.
	 */
	private function add_featured_image( $section, $page_data ) {
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

		} catch ( \Exception $e ) {
			// Skip image on error - image file may be corrupted or inaccessible.
			return;
		}
	}

	/**
	 * Add page info table.
	 *
	 * @param \PhpOffice\PhpWord\Element\Section $section   The section.
	 * @param array                              $page_data Page data.
	 */
	private function add_page_info_table( $section, $page_data ) {
		$section->addTitle( esc_html__( 'Page Information', 'sscribe-export-site-pages' ), 2 );

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
				sprintf( _n( '%d minute', '%d minutes', $page_data['reading_time'], 'sscribe-export-site-pages' ), $page_data['reading_time'] ),
			),
		);

		foreach ( $info_rows as $row ) {
			$table->addRow();
			$table->addCell( Converter::inchToTwip( 2 ), $header_cell_style )->addText(
				$this->safe_text( $row[0] ),
				$label_style
			);
			$table->addCell( Converter::inchToTwip( 4.5 ) )->addText(
				$this->safe_text( $row[1] ),
				$value_style
			);
		}

		$section->addTextBreak( 1 );
	}

	/**
	 * Add SEO metadata section.
	 *
	 * @param \PhpOffice\PhpWord\Element\Section $section   The section.
	 * @param array                              $page_data Page data.
	 */
	private function add_seo_section( $section, $page_data ) {
		$seo_data = $this->seo_reader->get_seo_data( $page_data['id'] );

		if ( empty( $seo_data['meta_title'] ) && empty( $seo_data['meta_description'] ) && empty( $seo_data['focus_keyword'] ) ) {
			return;
		}

		$section->addTitle( esc_html__( 'SEO Information', 'sscribe-export-site-pages' ), 2 );

		if ( ! empty( $seo_data['source'] ) ) {
			$section->addText(
				/* translators: %s: SEO plugin name */
				sprintf( esc_html__( 'Source: %s', 'sscribe-export-site-pages' ), $seo_data['source'] ),
				array(
					'name'   => $this->font_name,
					'size'   => 9,
					'italic' => true,
					'color'  => $this->colors['body'],
				)
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
					$label_style
				);
				$table->addCell( Converter::inchToTwip( 4.5 ) )->addText(
					$this->safe_text( $row[1] ),
					$value_style
				);
			}
		}

		$section->addTextBreak( 1 );
	}

	/**
	 * Add breadcrumb trail.
	 *
	 * @param \PhpOffice\PhpWord\Element\Section $section   The section.
	 * @param array                              $page_data Page data.
	 */
	private function add_breadcrumbs( $section, $page_data ) {
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
			array(
				'name'   => $this->font_name,
				'size'   => 9,
				'italic' => true,
				'color'  => $this->colors['body'],
			)
		);

		$section->addTextBreak( 1 );
	}

	/**
	 * Add main content (parsed HTML → DOCX elements).
	 *
	 * @param \PhpOffice\PhpWord\Element\Section $section   The section.
	 * @param array                              $page_data Page data.
	 */
	private function add_main_content( $section, $page_data ) {
		$section->addTitle( esc_html__( 'Content', 'sscribe-export-site-pages' ), 1 );

		$elements = $this->parser->parse( $page_data['content'] );

		foreach ( $elements as $element ) {
			$this->render_element( $section, $element );
		}
	}

	/**
	 * Render a parsed element into the DOCX section.
	 *
	 * @param \PhpOffice\PhpWord\Element\Section $section The section.
	 * @param array                              $element The parsed element.
	 */
	private function render_element( $section, $element ) {
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
				$text_run = $section->addTextRun( 'Blockquote' );
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
					'CodeBlock'
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
					array( 'alignment' => Jc::CENTER )
				);
				break;
		}
	}

	/**
	 * Render a paragraph with inline runs.
	 *
	 * @param \PhpOffice\PhpWord\Element\Section $section The section.
	 * @param array                              $element The paragraph element.
	 */
	private function render_paragraph( $section, $element ) {
		if ( empty( $element['runs'] ) ) {
			return;
		}

		$text_run = $section->addTextRun();
		$this->render_runs( $text_run, $element['runs'] );
	}

	/**
	 * Render inline runs into a text run.
	 *
	 * @param \PhpOffice\PhpWord\Element\TextRun $text_run The text run container.
	 * @param array                              $runs    Array of run data.
	 * @param bool                               $italic  Force italic (for blockquotes).
	 */
	private function render_runs( $text_run, $runs, $italic = false ) {
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
				$font_style['color'] = $this->colors['link'];
				$text_run->addLink(
					$run['link'],
					$text_content,
					$font_style
				);
				// Add URL in parentheses for print-friendliness.
				if ( $run['text'] !== $run['link'] ) {
					$text_run->addText(
						' (' . $this->safe_text( $run['link'] ) . ')',
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
		}
	}

	/**
	 * Render a list element.
	 *
	 * @param \PhpOffice\PhpWord\Element\Section $section The section.
	 * @param array                              $element The list element.
	 */
	private function render_list( $section, $element ) {
		$style = isset( $element['style'] ) ? $element['style'] : 'bullet';

		if ( ! isset( $element['items'] ) ) {
			return;
		}

		$list_type = ( 'numbered' === $style ) ? \PhpOffice\PhpWord\Style\ListItem::TYPE_NUMBER : \PhpOffice\PhpWord\Style\ListItem::TYPE_BULLET_FILLED;

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
				array( 'listType' => $list_type )
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
						array( 'listType' => $list_type )
					);
				}
			}
		}
	}

	/**
	 * Render an HTML table into DOCX.
	 *
	 * @param \PhpOffice\PhpWord\Element\Section $section The section.
	 * @param array                              $element The table element.
	 */
	private function render_table( $section, $element ) {
		if ( empty( $element['rows'] ) ) {
			return;
		}

		$table_style = array(
			'borderSize'  => 1,
			'borderColor' => $this->colors['border'],
			'cellMargin'  => Converter::cmToTwip( 0.1 ),
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

				$table->addCell( null, $cell_style )->addText(
					$this->safe_text( $cell['content'] ),
					$font_style
				);
			}
		}

		$section->addTextBreak( 1 );
	}

	/**
	 * Render a button element.
	 *
	 * @param \PhpOffice\PhpWord\Element\Section $section The section.
	 * @param array                              $element The button element.
	 */
	private function render_button( $section, $element ) {
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
			array( 'alignment' => Jc::CENTER )
		);

		if ( ! empty( $element['url'] ) ) {
			$cell->addText(
				esc_html__( 'DESTINATION URL:', 'sscribe-export-site-pages' ),
				array(
					'name'  => $this->font_name,
					'size'  => 8,
					'bold'  => true,
					'color' => $this->colors['heading'],
				),
				array(
					'alignment'   => Jc::CENTER,
					'spaceBefore' => Converter::pointToTwip( 6 ),
				)
			);
			$cell->addLink(
				$element['url'],
				$this->safe_text( $element['url'] ),
				array(
					'name'      => $this->font_name,
					'size'      => 9,
					'color'     => $this->colors['link'],
					'underline' => 'single',
				),
				array( 'alignment' => Jc::CENTER )
			);
		}

		$section->addTextBreak( 1 );
	}

	/**
	 * Render an inline image.
	 *
	 * @param \PhpOffice\PhpWord\Element\Section $section The section.
	 * @param array                              $element The image element.
	 */
	private function render_inline_image( $section, $element ) {
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
				array( 'alignment' => Jc::CENTER )
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
			esc_html__( 'IMAGE ASSET SOURCE URL:', 'sscribe-export-site-pages' ),
			array(
				'name'  => $this->font_name,
				'size'  => 7,
				'bold'  => true,
				'color' => $this->colors['heading'],
			)
		);
		$cell->addText(
			$this->safe_text( $src ),
			array(
				'name'  => 'Courier New',
				'size'  => 8,
				'color' => $this->colors['link'],
			)
		);

		$section->addTextBreak( 1 );
	}

	/**
	 * Add child pages list.
	 *
	 * @param \PhpOffice\PhpWord\Element\Section $section   The section.
	 * @param array                              $page_data Page data.
	 */
	private function add_child_pages( $section, $page_data ) {
		if ( empty( $page_data['children'] ) ) {
			return;
		}

		$section->addTextBreak( 1 );
		$section->addTitle( esc_html__( 'Child Pages', 'sscribe-export-site-pages' ), 2 );

		foreach ( $page_data['children'] as $child ) {
			$text_run = $section->addTextRun();
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
}
