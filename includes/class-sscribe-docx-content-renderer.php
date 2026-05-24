<?php
/**
 * SScribe DOCX Content Renderer
 *
 * @package SScribe_Export_Site_Pages
 * @license GPL v2 or later
 * @link    https://www.gnu.org/licenses/gpl-2.0.html
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SScribeVendor\PhpOffice\PhpWord\Element\Section;
use SScribeVendor\PhpOffice\PhpWord\Element\TextRun;
use SScribeVendor\PhpOffice\PhpWord\Shared\Converter;
use SScribeVendor\PhpOffice\PhpWord\SimpleType\Jc;

/**
 * Renders HTML-like content elements into DOCX document sections.
 *
 * Extracted from SScribe_Exporter to reduce file complexity.
 */
class SScribe_DOCX_Content_Renderer {

	/**
	 * Content parser instance.
	 *
	 * @var SScribe_Content_Parser
	 */
	private readonly SScribe_Content_Parser $parser;

	/**
	 * Logger instance.
	 *
	 * @var SScribe_Logger_Interface|null
	 */
	private ?SScribe_Logger_Interface $logger = null;

	/**
	 * Color palette for document styling.
	 *
	 * @var array<string, string>
	 */
	private array $colors;

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
	 * Font size in points.
	 *
	 * @var int
	 */
	private int $font_size = 11;

	/**
	 * Last rendering error message.
	 *
	 * @var string
	 */
	public string $last_error = '';

	/**
	 * Initialize the content renderer.
	 *
	 * @param SScribe_Content_Parser|null   $parser   Content parser.
	 * @param SScribe_Logger_Interface|null $logger   Logger.
	 * @param array<string, string>         $colors   Color palette.
	 * @param bool                          $is_rtl   RTL flag.
	 * @param string                        $font_name Font name.
	 * @param int                           $font_size Font size.
	 */
	public function __construct(
		?SScribe_Content_Parser $parser = null,
		?SScribe_Logger_Interface $logger = null,
		array $colors = array(),
		bool $is_rtl = false,
		string $font_name = 'Arial',
		int $font_size = 11
	) {
		$this->parser    = $parser ?? new SScribe_Content_Parser();
		$this->logger    = $logger;
		$this->colors    = ! empty( $colors ) ? $colors : $this->default_colors();
		$this->is_rtl    = $is_rtl;
		$this->font_name = $font_name;
		$this->font_size = $font_size;
	}

	/**
	 * Default color palette.
	 *
	 * @return array<string, string>
	 */
	private function default_colors(): array {
		return array(
			'primary'  => '4A8263',
			'heading'  => '122119',
			'body'     => '495057',
			'light_bg' => 'E8EFEB',
			'link'     => '2C6E8A',
			'code_bg'  => 'F5F6F8',
			'white'    => 'FFFFFF',
			'border'   => 'CCCCCC',
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

		if ( mb_strlen( $text, 'UTF-8' ) > 200 && false === mb_strpos( $text, ' ', 0, 'UTF-8' ) ) {
			$text = mb_substr( $text, 0, 200, 'UTF-8' );
		}

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

	/**
	 * Update renderer configuration from exporter settings.
	 *
	 * @param array<string, string> $colors   Color palette.
	 * @param bool                  $is_rtl   RTL flag.
	 * @param string                $font_name Font name.
	 * @param int                   $font_size Font size.
	 */
	public function sync_config( array $colors, bool $is_rtl, string $font_name, int $font_size ): void {
		$this->colors    = $colors;
		$this->is_rtl    = $is_rtl;
		$this->font_name = $font_name;
		$this->font_size = $font_size;
	}

	/**
	 * Add main content to document section.
	 *
	 * @param Section $section   Document section.
	 * @param array   $page_data Page data.
	 */
	public function add_main_content( Section $section, array $page_data ): void {
		$content     = $page_data['content'] ?? '';
		$content_len = strlen( $content );

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

		if ( 0 === $element_count && $content_len > 0 ) {
			$this->get_logger()->error(
				'CRITICAL: Content parser returned zero elements',
				array(
					'page_id'         => $page_data['id'] ?? 0,
					'page_title'      => $page_data['title'] ?? 'unknown',
					'content_len'     => $content_len,
					'content_preview' => mb_strcut( $content, 0, 500 ),
				)
			);
		}

		foreach ( $elements as $element_index => $element ) {
			try {
				$this->render_element( $section, $element );
			} catch ( \Throwable $e ) {
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
			}
		}
	}

	/**
	 * Render a single element to the document section.
	 *
	 * @param Section $section Document section.
	 * @param array   $element Parsed element data.
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
	 * Render a paragraph element.
	 *
	 * @param Section $section Document section.
	 * @param array   $element Paragraph element data.
	 */
	private function render_paragraph( Section $section, array $element ): void {
		if ( empty( $element['runs'] ) ) {
			return;
		}

		$text_run = $section->addTextRun( $this->get_para_style() );
		$this->render_runs( $text_run, $element['runs'] );
	}

	/**
	 * Render inline text runs with formatting.
	 *
	 * @param TextRun $text_run TextRun element.
	 * @param array   $runs     Inline runs.
	 * @param bool    $italic   Force italic.
	 * @param bool    $bold     Force bold.
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
	 * @param Section $section Document section.
	 * @param array   $element List element data.
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
	 * Render a table element.
	 *
	 * @param Section $section Document section.
	 * @param array   $element Table element data.
	 */
	private function render_table( Section $section, array $element ): void {
		if ( empty( $element['rows'] ) ) {
			return;
		}

		$col_count = ! empty( $element['rows'][0]['cells'] )
			? count( $element['rows'][0]['cells'] )
			: 0;

		if ( 0 === $col_count ) {
			return;
		}

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
	 * @param Section $section Document section.
	 * @param array   $element Button element data.
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
	 * Render an inline image element.
	 *
	 * @param Section $section Document section.
	 * @param array   $element Image element data.
	 */
	private function render_inline_image( Section $section, array $element ): void {
		$path = ! empty( $element['local_path'] ) ? $element['local_path'] : '';
		$src  = ! empty( $element['src'] ) ? $element['src'] : __( 'Unknown URL', 'sscribe-export-site-pages' );

		if ( empty( $path ) || ! file_exists( $path ) ) {
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

				if ( 0 === $width_emu || 0 === $height_emu ) {
					$this->get_logger()->debug(
						'Content image has zero dimensions, using original size',
						array(
							'path'   => $path,
							'width'  => $image_info[0],
							'height' => $image_info[1],
						)
					);
				} else {
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
}
