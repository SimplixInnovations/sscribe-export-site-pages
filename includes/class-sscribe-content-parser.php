<?php
/**
 * SScribe Content Parser.
 *
 * @package SScribe_Export_Site_Pages
 * @license GPL v2 or later
 * @link    https://www.gnu.org/licenses/gpl-2.0.html
 */

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * HTML content parsing and sanitization.
 *
 * @package SScribe_Export_Site_Pages
 * @subpackage Content
 */
class SScribe_Content_Parser {

	/**
	 * KSES allowed HTML elements for content export.
	 * Extends wp_kses_post with additional elements needed for rich content.
	 *
	 * @var array
	 */
	private const KSES_ALLOWED_HTML = array(
		'a'          => array(
			'href'   => true,
			'title'  => true,
			'target' => true,
			'rel'    => true,
			'class'  => true,
			'id'     => true,
		),
		'abbr'       => array(
			'title' => true,
			'class' => true,
		),
		'acronym'    => array( 'title' => true ),
		'b'          => array( 'class' => true ),
		'blockquote' => array(
			'cite'  => true,
			'class' => true,
		),
		'br'         => array(),
		'code'       => array( 'class' => true ),
		'del'        => array( 'datetime' => true ),
		'dd'         => array(),
		'dl'         => array(),
		'dt'         => array(),
		'em'         => array( 'class' => true ),
		'i'          => array( 'class' => true ),
		'img'        => array(
			'src'     => true,
			'alt'     => true,
			'width'   => true,
			'height'  => true,
			'class'   => true,
			'id'      => true,
			'loading' => true,
		),
		'li'         => array(
			'class' => true,
			'value' => true,
		),
		'ol'         => array(
			'class' => true,
			'start' => true,
			'type'  => true,
		),
		'p'          => array( 'class' => true ),
		'pre'        => array( 'class' => true ),
		'q'          => array( 'cite' => true ),
		's'          => array(),
		'strike'     => array(),
		'strong'     => array( 'class' => true ),
		'sub'        => array(),
		'sup'        => array(),
		'table'      => array(
			'class'       => true,
			'id'          => true,
			'border'      => true,
			'cellpadding' => true,
			'cellspacing' => true,
		),
		'tbody'      => array(),
		'td'         => array(
			'class'   => true,
			'colspan' => true,
			'rowspan' => true,
		),
		'tfoot'      => array(),
		'th'         => array(
			'class'   => true,
			'colspan' => true,
			'rowspan' => true,
			'scope'   => true,
		),
		'thead'      => array(),
		'tr'         => array( 'class' => true ),
		'ul'         => array(
			'class' => true,
			'type'  => true,
		),
		'div'        => array(
			'class' => true,
			'id'    => true,
			'align' => true,
		),
		'span'       => array(
			'class' => true,
			'id'    => true,
		),
		'h1'         => array(
			'class' => true,
			'id'    => true,
		),
		'h2'         => array(
			'class' => true,
			'id'    => true,
		),
		'h3'         => array(
			'class' => true,
			'id'    => true,
		),
		'h4'         => array(
			'class' => true,
			'id'    => true,
		),
		'h5'         => array(
			'class' => true,
			'id'    => true,
		),
		'h6'         => array(
			'class' => true,
			'id'    => true,
		),
		'figure'     => array( 'class' => true ),
		'figcaption' => array(),
		'small'      => array(),
		'mark'       => array(),
		'ins'        => array( 'datetime' => true ),
		'hr'         => array( 'class' => true ),
		'details'    => array(
			'class' => true,
			'open'  => true,
		),
		'summary'    => array( 'class' => true ),
	);

	/**
	 * Cached upload directory data.
	 *
	 * @var array|null
	 */
	private ?array $upload_dir_cache = null;

	/**
	 * Get the WordPress upload directory (cached).
	 *
	 * @return array
	 */
	private function get_upload_dir(): array {
		if ( null === $this->upload_dir_cache ) {
			$this->upload_dir_cache = wp_upload_dir();
		}
		return $this->upload_dir_cache;
	}

	/**
	 * Parse HTML content into structured elements.
	 *
	 * @param string $html HTML content.
	 * @return array Parsed elements.
	 */
	public function parse( string $html ): array {
		if ( empty( $html ) ) {
			return array();
		}

		/**
		 * Filter the raw export HTML before it is parsed and sanitized.
		 *
		 * Use this hook to apply an additional, stricter sanitization
		 * pass (e.g. strip `javascript:` URIs from `href`/`src`, remove
		 * a tag your security review has flagged, or replace a custom
		 * shortcode) without forking the plugin. The HTML has not yet
		 * been through `wp_kses()`; the SScribe content parser will
		 * run its own KSES allowlist on the returned value.
		 *
		 * @since 1.1.3
		 *
		 * @param string $html The original, unfiltered post content.
		 * @return string The (possibly further sanitized) HTML.
		 */
		$html = (string) apply_filters( 'sscribe_sanitize_export_html', $html );

		$logger = SScribe_Logger::instance( SScribe_Logger::is_logging_enabled() );

		$logger->debug(
			'Content parser: parse() called',
			array(
				'html_len' => strlen( $html ),
			)
		);

		$html = $this->normalize_html( $html );
		$logger->debug( 'Content parser: HTML normalized', array( 'normalized_len' => strlen( $html ) ) );

		$elements = $this->parse_dom( $html );
		$logger->debug(
			'Content parser: DOM parsed',
			array(
				'element_count' => count( $elements ),
			)
		);

		return $elements;
	}

	/**
	 * Safe preg_replace wrapper that returns original on failure.
	 *
	 * @param array|string $pattern     Regex pattern.
	 * @param array|string $replacement Replacement string.
	 * @param string       $subject     Input string.
	 * @return string
	 */
	private function safe_replace( array|string $pattern, array|string $replacement, string $subject ): string {
		$result = preg_replace( $pattern, $replacement, $subject );
		return is_string( $result ) ? $result : $subject;
	}

	/**
	 * Normalize HTML for parsing.
	 *
	 * @param string $html Raw HTML.
	 * @return string Normalized HTML.
	 */
	private function normalize_html( string $html ): string {
		$html = $this->strip_all_styles( $html );

		$html = $this->safe_replace( '/<(script|noscript|svg)\b[^>]*>.*?<\/\1>/is', '', $html );

		$html = $this->safe_replace( '/<!--.*?-->/s', '', $html );

		$html = $this->safe_replace( '/:root\s*\{[^}]*\}/s', '', $html );
		$html = $this->safe_replace( '/\.elementor-[a-zA-Z0-9_-]+\s*\{[^}]*\}/s', '', $html );

		$html = wp_kses( $html, self::KSES_ALLOWED_HTML );

		$html = $this->safe_replace( '/>\s+</', '><', $html );

		$html = $this->safe_replace( '/<\/(p|div|h[1-6]|ul|ol|li|table|tr|blockquote|pre)>/', "</$1>\n", $html );

		return trim( $html );
	}

	/**
	 * Strip all style-related attributes from HTML.
	 *
	 * @param string $html HTML content.
	 * @return string Cleaned HTML.
	 */
	private function strip_all_styles( string $html ): string {
		$html = $this->safe_replace(
			array(
				'/\s*style="[^"]*"/i',
				"/\s*style='[^']*'/i",
				'/<style[^>]*>.*?<\/style>/is',
				'/\s*data-(?:elementor|widget|column|section)(?:-[a-z0-9_-]+)?="[^"]*"/i',
				"/\s*data-(?:elementor|widget|column|section)(?:-[a-z0-9_-]+)?='[^']*'/i",
			),
			'',
			$html
		);

		return $html;
	}

	/**
	 * Parse HTML DOM into structured elements.
	 *
	 * @param string $html HTML content.
	 * @return array Parsed elements.
	 * @throws \Throwable If DOM parsing fails.
	 */
	private function parse_dom( string $html ): array {
		$elements = array();

		$dom = new DOMDocument( '1.0', 'UTF-8' );

		$prev_use_errors = libxml_use_internal_errors( true );

		try {

			$html = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $html );

			$wrapped = '<!DOCTYPE html><html><head>'
				. '<meta http-equiv="Content-Type" content="text/html; charset=UTF-8">'
				. '</head><body>' . $html . '</body></html>';
			$dom->loadHTML(
				$wrapped,
				LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET
			);

			libxml_clear_errors();

			$body = $dom->getElementsByTagName( 'body' )->item( 0 );
			if ( ! $body ) {

				return $elements;
			}

			foreach ( $body->childNodes as $node ) {
				$parsed = $this->parse_node( $node );
				if ( $parsed ) {
					if ( isset( $parsed['type'] ) ) {
						$elements[] = $parsed;
					} else {

						$elements = array_merge( $elements, $parsed );
					}
				}
			}

			return $elements;

		} finally {

			if ( isset( $body ) ) {
				unset( $body );
			}

			unset( $dom );
			libxml_clear_errors();
			libxml_use_internal_errors( $prev_use_errors );
		}
	}

	/**
	 * Parse a single DOM node into structured element(s).
	 *
	 * @param \DOMNode $node  DOM node.
	 * @param int      $depth Nesting depth.
	 * @return array|null Parsed element or null.
	 */
	private function parse_node( \DOMNode $node, int $depth = 0 ): ?array {
		if ( XML_TEXT_NODE === $node->nodeType ) {
			$text = trim( $node->textContent );
			if ( ! empty( $text ) ) {
				return array(
					'type'    => 'paragraph',
					'content' => $text,
					'runs'    => array( array( 'text' => $text ) ),
				);
			}
			return null;
		}

		if ( XML_ELEMENT_NODE !== $node->nodeType ) {
			return null;
		}

		$tag_name = strtolower( $node->nodeName );

		switch ( $tag_name ) {
			case 'h1':
			case 'h2':
			case 'h3':
			case 'h4':
			case 'h5':
			case 'h6':
				return array(
					'type'    => 'heading',
					'level'   => (int) substr( $tag_name, 1 ),
					'content' => $this->get_text_content( $node ),
					'runs'    => $this->get_inline_runs( $node ),
				);

			case 'p':
				$runs = $this->get_inline_runs( $node );
				if ( empty( $runs ) ) {
					return null;
				}

				return array(
					'type'    => 'paragraph',
					'content' => $this->get_text_content( $node ),
					'runs'    => $runs,
				);

			case 'ul':
				return $this->parse_list( $node, 'bullet', $depth );

			case 'ol':
				return $this->parse_list( $node, 'numbered', $depth );

			case 'blockquote':
				return array(
					'type'    => 'blockquote',
					'content' => $this->get_text_content( $node ),
					'runs'    => $this->get_inline_runs( $node ),
				);

			case 'pre':
			case 'code':
				return array(
					'type'    => 'code',
					'content' => $node->textContent,
				);

			case 'table':
				return $this->parse_table( $node );

			case 'img':
				return $this->parse_image( $node );

			case 'a':
				$href = $node->getAttribute( 'href' );
				$text = $this->get_text_content( $node );
				if ( $this->is_button_anchor( $node ) ) {
					return array(
						'type'    => 'button',
						'content' => $text,
						'url'     => $href,
					);
				}
				return array(
					'type'    => 'paragraph',
					'content' => $text,
					'runs'    => array(
						array(
							'text' => $text,
							'link' => $href,
						),
					),
				);

			case 'figure':
				$img_node     = null;
				$caption_text = '';

				foreach ( $node->childNodes as $child ) {
					if ( $child instanceof DOMElement ) {
						if ( 'img' === $child->tagName ) {
							$img_node = $child;
						} elseif ( 'figcaption' === $child->tagName ) {
							$caption_text = trim( $child->textContent );
						}
					}
				}

				if ( null === $img_node ) {
					$descendant_imgs = $node->getElementsByTagName( 'img' );
					if ( $descendant_imgs->length > 0 ) {
						$img_node = $descendant_imgs->item( 0 );
					}
				}

				$src        = null !== $img_node ? $img_node->getAttribute( 'src' ) : null;
				$alt        = null !== $img_node ? $img_node->getAttribute( 'alt' ) : '';
				$local_path = null !== $src ? $this->resolve_image_to_local( $src ) : '';

				$figure_data = array(
					'type'       => 'figure',
					'content'    => trim( $alt . ( $caption_text ? ' - ' . $caption_text : '' ) ),
					'src'        => $src,
					'alt'        => $alt,
					'caption'    => $caption_text,
					'local_path' => $local_path,
				);

				return $figure_data;

			case 'figcaption':
				$parent = $node->parentNode;
				if ( $parent instanceof DOMElement && 'figure' === strtolower( $parent->nodeName ) ) {
					return null;
				}
				return array(
					'type'    => 'figcaption',
					'content' => trim( $node->textContent ),
				);

			case 'details':
				$summary_text     = '';
				$body_elements    = array();
				$is_summary_found = false;

				foreach ( $node->childNodes as $child ) {
					if ( ! $is_summary_found && $child instanceof DOMElement && 'summary' === strtolower( $child->tagName ) ) {
						$summary_text     = trim( $child->textContent );
						$is_summary_found = true;
						continue;
					}
					if ( ! $is_summary_found ) {
						continue;
					}
					$parsed = $this->parse_node( $child, $depth + 1 );
					if ( null !== $parsed ) {
						if ( isset( $parsed[0] ) ) {
							foreach ( $parsed as $p ) {
								if ( null !== $p ) {
									$body_elements[] = $p;
								}
							}
						} else {
							$body_elements[] = $parsed;
						}
					}
				}

				return array(
					'type'    => 'details',
					'summary' => $summary_text,
					'content' => $body_elements,
				);

			case 'summary':
				$parent = $node->parentNode;
				if ( $parent instanceof DOMElement && 'details' === strtolower( $parent->nodeName ) ) {
					return null;
				}
				return array(
					'type'    => 'paragraph',
					'content' => trim( $node->textContent ),
					'runs'    => array(
						array(
							'text' => trim( $node->textContent ),
							'bold' => true,
						),
					),
				);

			case 'br':
				return array(
					'type'    => 'break',
					'content' => '',
				);

			case 'hr':
				return array(
					'type'    => 'horizontal_rule',
					'content' => '',
				);

			case 'style':
			case 'script':
			case 'noscript':
			case 'svg':
			case 'meta':
			case 'link':
			case 'head':
				return null;

			case 'div':
			case 'section':
			case 'article':
			case 'aside':
			case 'main':
			case 'header':
			case 'footer':
				$elements = array();
				foreach ( $node->childNodes as $child ) {
					if ( XML_ELEMENT_NODE === $child->nodeType ) {
						$parsed = $this->parse_node( $child, $depth );
						if ( null !== $parsed ) {
							if ( isset( $parsed[0] ) && is_array( $parsed[0] ) ) {
								foreach ( $parsed as $p ) {
									$elements[] = $p;
								}
							} else {
								$elements[] = $parsed;
							}
						}
					} elseif ( XML_TEXT_NODE === $child->nodeType ) {
						$text = trim( $child->textContent );
						if ( '' !== $text ) {
							$elements[] = array(
								'type'    => 'paragraph',
								'content' => $text,
								'runs'    => array( array( 'text' => $text ) ),
							);
						}
					}
				}
				if ( empty( $elements ) ) {
					return null;
				}
				return $elements;

			default:
				$text = trim( $node->textContent );
				if ( ! empty( $text ) ) {
					return array(
						'type'    => 'paragraph',
						'content' => $text,
						'runs'    => array( array( 'text' => $text ) ),
					);
				}
				return null;
		}
	}

	/**
	 * Determine whether an anchor uses a known page-builder button class.
	 *
	 * @param \DOMNode $node Anchor node.
	 * @return bool True when the anchor represents a button block.
	 */
	private function is_button_anchor( \DOMNode $node ): bool {
		if ( ! $node instanceof \DOMElement ) {
			return false;
		}

		$classes = preg_split( '/\s+/', trim( $node->getAttribute( 'class' ) ) );
		if ( false === $classes ) {
			return false;
		}

		$known_classes = array(
			'wp-block-button__link',
			'wp-element-button',
			'elementor-button',
			'et_pb_button',
			'fl-button',
			'vc_btn',
			'button',
			'btn',
		);

		foreach ( $classes as $class_name ) {
			if ( in_array( strtolower( $class_name ), $known_classes, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Parse a list element (ul/ol) into structured format.
	 *
	 * @param \DOMNode $node  List DOM node.
	 * @param string   $style List style ('bullet' or 'numbered').
	 * @param int      $depth Nesting depth.
	 * @return array Parsed list data.
	 */
	private function parse_list( \DOMNode $node, string $style, int $depth = 0 ): array {
		$items = array();

		if ( $depth >= 10 ) {
			return array(
				'type'  => 'list',
				'style' => $style,
				'items' => $items,
			);
		}

		foreach ( $node->childNodes as $child ) {
			if ( XML_ELEMENT_NODE !== $child->nodeType || 'li' !== strtolower( $child->nodeName ) ) {
				continue;
			}

			$item = array(
				'content'  => '',
				'runs'     => array(),
				'children' => array(),
				'depth'    => min( $depth, 8 ),
			);

			foreach ( $child->childNodes as $li_child ) {
				$li_tag = strtolower( $li_child->nodeName );

				if ( 'ul' === $li_tag || 'ol' === $li_tag ) {

					$nested_style = ( 'ul' === $li_tag ) ? 'bullet' : 'numbered';
					$nested       = $this->parse_list( $li_child, $nested_style, $depth + 1 );
					if ( isset( $nested['items'] ) && ! empty( $nested['items'] ) ) {
						$item['children'] = array_merge( $item['children'], $nested['items'] );
					}
				} elseif ( XML_TEXT_NODE === $li_child->nodeType ) {

					$text = trim( $li_child->textContent );
					if ( ! empty( $text ) ) {
						$item['runs'][] = array( 'text' => $text );
					}
				} elseif ( XML_ELEMENT_NODE === $li_child->nodeType ) {
					if ( 'a' === $li_tag ) {
						$href     = $li_child->getAttribute( 'href' );
						$sub_runs = $this->get_inline_runs( $li_child );
						if ( '' !== $href ) {
							foreach ( $sub_runs as $key => $run ) {
								$sub_runs[ $key ]['link'] = $href;
							}
						}
						$item['runs'] = array_merge( $item['runs'], $sub_runs );
					} else {
						$inline_runs  = $this->get_inline_runs( $li_child );
						$item['runs'] = array_merge( $item['runs'], $inline_runs );
					}
				}
			}

			$item['content'] = $this->runs_to_text( $item['runs'] );
			$items[]         = $item;
		}

		return array(
			'type'  => 'list',
			'style' => $style,
			'items' => $items,
		);
	}

	/**
	 * Parse a table element into structured format.
	 *
	 * @param \DOMNode $node Table DOM node.
	 * @return array Parsed table data.
	 */
	private function parse_table( \DOMNode $node ): array {
		$rows = array();

		$sections = array();
		foreach ( $node->childNodes as $child ) {
			if ( XML_ELEMENT_NODE !== $child->nodeType ) {
				continue;
			}
			$tag = strtolower( $child->nodeName );
			if ( 'thead' === $tag || 'tbody' === $tag || 'tfoot' === $tag ) {
				foreach ( $child->childNodes as $tr ) {
					if ( XML_ELEMENT_NODE === $tr->nodeType && 'tr' === strtolower( $tr->nodeName ) ) {
						$sections[] = array(
							'tr'        => $tr,
							'is_header' => ( 'thead' === $tag ),
						);
					}
				}
			} elseif ( 'tr' === $tag ) {
				$sections[] = array(
					'tr'        => $child,
					'is_header' => false,
				);
			}
		}

		foreach ( $sections as $index => $section ) {
			$tr    = $section['tr'];
			$cells = array();

			foreach ( $tr->childNodes as $td ) {
				if ( XML_ELEMENT_NODE !== $td->nodeType ) {
					continue;
				}
				$cell_tag = strtolower( $td->nodeName );
				if ( 'td' === $cell_tag || 'th' === $cell_tag ) {

					$colspan_attr = $td->getAttribute( 'colspan' );
					$rowspan_attr = $td->getAttribute( 'rowspan' );
					$colspan      = is_numeric( $colspan_attr ) ? max( 1, (int) $colspan_attr ) : 1;
					$rowspan      = is_numeric( $rowspan_attr ) ? max( 1, (int) $rowspan_attr ) : 1;

					$width_attr = $td->getAttribute( 'width' );

					$cells[] = array(
						'content'   => trim( $td->textContent ),
						'runs'      => $this->get_inline_runs( $td ),
						'is_header' => ( 'th' === $cell_tag || $section['is_header'] ),
						'colspan'   => $colspan,
						'rowspan'   => $rowspan,
						'width'     => is_numeric( $width_attr ) ? (int) $width_attr : null,
					);
				}
			}

			if ( ! empty( $cells ) ) {
				$rows[] = array(
					'cells'     => $cells,
					'is_header' => $section['is_header'] || ( 0 === $index && $cells[0]['is_header'] ),
				);
			}
		}

		return array(
			'type' => 'table',
			'rows' => $rows,
		);
	}

	/**
	 * Parse an image element into structured format.
	 *
	 * @param \DOMNode $node Image DOM node.
	 * @return array|null Parsed image data or null.
	 */
	private function parse_image( \DOMNode $node ): ?array {
		$src = $node->getAttribute( 'src' );
		$alt = $node->getAttribute( 'alt' );

		if ( empty( $src ) ) {
			return null;
		}

		$local_path = $this->resolve_image_to_local( $src );

		return array(
			'type'       => 'image',
			'src'        => $src,
			'alt'        => $alt,
			'local_path' => $local_path,
		);
	}

	/**
	 * Extract inline formatting runs from a DOM node.
	 *
	 * @param \DOMNode $node DOM node.
	 * @return array Inline runs.
	 */
	private function get_inline_runs( \DOMNode $node ): array {
		$runs = array();

		foreach ( $node->childNodes as $child ) {
			if ( XML_TEXT_NODE === $child->nodeType ) {
				$text = $child->textContent;
				if ( '' !== $text ) {
					$runs[] = array( 'text' => $text );
				}
			} elseif ( XML_ELEMENT_NODE === $child->nodeType ) {
				$tag = strtolower( $child->nodeName );

				switch ( $tag ) {
					case 'strong':
					case 'b':
						$sub_runs = $this->get_inline_runs( $child );
						foreach ( $sub_runs as $key => $run ) {
							$sub_runs[ $key ]['bold'] = true;
						}
						array_push( $runs, ...$sub_runs );
						break;

					case 'em':
					case 'i':
						$sub_runs = $this->get_inline_runs( $child );
						foreach ( $sub_runs as $key => $run ) {
							$sub_runs[ $key ]['italic'] = true;
						}
						array_push( $runs, ...$sub_runs );
						break;

					case 'u':
						$sub_runs = $this->get_inline_runs( $child );
						foreach ( $sub_runs as $key => $run ) {
							$sub_runs[ $key ]['underline'] = true;
						}
						array_push( $runs, ...$sub_runs );
						break;

					case 's':
					case 'del':
					case 'strike':
						$sub_runs = $this->get_inline_runs( $child );
						foreach ( $sub_runs as $key => $run ) {
							$sub_runs[ $key ]['strikethrough'] = true;
						}
						$runs = array_merge( $runs, $sub_runs );
						break;

					case 'a':
						$href     = $child->getAttribute( 'href' );
						$sub_runs = $this->get_inline_runs( $child );
						foreach ( $sub_runs as $key => $run ) {
							$sub_runs[ $key ]['link'] = $href;
						}
						$runs = array_merge( $runs, $sub_runs );
						break;

					case 'code':
						$runs[] = array(
							'text' => $child->textContent,
							'code' => true,
						);
						break;

					case 'br':
						$runs[] = array(
							'text'  => "\n",
							'break' => true,
						);
						break;

					case 'span':
						$sub_runs = $this->get_inline_runs( $child );
						array_push( $runs, ...$sub_runs );
						break;

					case 'img':
						$src = $child->getAttribute( 'src' );
						if ( $src ) {
							$runs[] = array(
								'text'  => '[Image: ' . $child->getAttribute( 'alt' ) . ']',
								'image' => $src,
							);
						}
						break;

					case 'sup':
						$sub_runs = $this->get_inline_runs( $child );
						foreach ( $sub_runs as $key => $run ) {
							$sub_runs[ $key ]['superScript'] = true;
						}
						array_push( $runs, ...$sub_runs );
						break;

					case 'sub':
						$sub_runs = $this->get_inline_runs( $child );
						foreach ( $sub_runs as $key => $run ) {
							$sub_runs[ $key ]['subScript'] = true;
						}
						array_push( $runs, ...$sub_runs );
						break;

					case 'mark':
						$sub_runs = $this->get_inline_runs( $child );
						foreach ( $sub_runs as $key => $run ) {
							$sub_runs[ $key ]['highlight'] = 'yellow';
						}
						array_push( $runs, ...$sub_runs );
						break;

					case 'ins':
						$sub_runs = $this->get_inline_runs( $child );
						foreach ( $sub_runs as $key => $run ) {
							$sub_runs[ $key ]['underline'] = true;
						}
						array_push( $runs, ...$sub_runs );
						break;

					case 'table':
						$nested_text = $this->extract_nested_table_text( $child );
						if ( '' !== $nested_text ) {
							$runs[] = array(
								'text' => $nested_text,
							);
						}
						break;

					default:
						$sub_runs = $this->get_inline_runs( $child );
						array_push( $runs, ...$sub_runs );
						break;
				}
			}
		}

		return $runs;
	}

	/**
	 * Extract text content from a nested table.
	 *
	 * When a table is found inside a <td>, extract all cell text
	 * and format as newline-separated plain text.
	 *
	 * @param \DOMNode $table Table element.
	 * @return string Extracted table text.
	 */
	private function extract_nested_table_text( \DOMNode $table ): string {
		$cell_texts = array();

		foreach ( $table->getElementsByTagName( 'td' ) as $td ) {
			$cell_text = trim( $td->textContent );
			if ( '' !== $cell_text ) {
				$cell_texts[] = $cell_text;
			}
		}

		if ( empty( $cell_texts ) ) {
			foreach ( $table->getElementsByTagName( 'th' ) as $th ) {
				$cell_text = trim( $th->textContent );
				if ( '' !== $cell_text ) {
					$cell_texts[] = $cell_text;
				}
			}
		}

		return implode( "\n", $cell_texts );
	}

	/**
	 * Get text content from a DOM node.
	 *
	 * @param \DOMNode $node DOM node.
	 * @return string
	 */
	private function get_text_content( \DOMNode $node ): string {

		$has_text_children = false;
		foreach ( $node->childNodes as $child ) {
			if ( XML_TEXT_NODE === $child->nodeType && '' !== trim( $child->nodeValue ) ) {
				$has_text_children = true;
				break;
			}
		}

		if ( ! $has_text_children && $node->childNodes->length > 0 ) {
			$parts = array();
			foreach ( $node->childNodes as $child ) {
				if ( XML_ELEMENT_NODE === $child->nodeType ) {
					$child_text = trim( $child->textContent );
					if ( '' !== $child_text ) {
						$parts[] = $child_text;
					}
				}
			}
			return implode( ' ', $parts );
		}

		return trim( $node->textContent );
	}

	/**
	 * Convert inline runs to plain text.
	 *
	 * @param array $runs Inline runs.
	 * @return string
	 */
	private function runs_to_text( array $runs ): string {
		$text = '';
		foreach ( $runs as $run ) {
			$text .= isset( $run['text'] ) ? $run['text'] : '';
		}
		return trim( $text );
	}

	/**
	 * Convert a URL to a local file path.
	 *
	 * @param string $url URL to convert.
	 * @return string Local path or empty string.
	 */
	private function url_to_local_path( string $url ): string {
		$upload_dir  = $this->get_upload_dir();
		$upload_url  = $upload_dir['baseurl'];
		$upload_path = realpath( $upload_dir['basedir'] );

		if ( str_starts_with( $url, '//' ) ) {
			$scheme = (string) wp_parse_url( $upload_url, PHP_URL_SCHEME );
			$url    = ( '' !== $scheme ? $scheme : 'https' ) . ':' . $url;
		}

		if ( empty( $upload_path ) || stripos( $url, $upload_url ) !== 0 ) {
			return '';
		}

		$relative = substr( $url, strlen( $upload_url ) );

		$stripped = explode( '?', $relative, 2 )[0];
		$relative = '' !== $stripped ? $stripped : $relative;

		$local      = $upload_path . $relative;
		$real_local = realpath( $local );

		$safe_upload_path = rtrim( $upload_path, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR;
		if ( false === $real_local || ! str_starts_with( $real_local, $safe_upload_path ) ) {
			return '';
		}

		$extension = strtolower( pathinfo( $real_local, PATHINFO_EXTENSION ) );

		if ( ! in_array( $extension, array( 'jpg', 'jpeg', 'png', 'gif', 'bmp', 'svg', 'webp', 'avif' ), true ) ) {
			return '';
		}

		if ( function_exists( 'getimagesize' ) ) {

			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- getimagesize returns false for invalid images; we check this and return empty.
			$image_info = @getimagesize( $real_local );
			if ( false === $image_info || ! isset( $image_info['mime'] ) ) {
				return '';
			}
			$mime = $image_info['mime'];
			$expected_mimes = array(
				'jpg'  => 'image/jpeg',
				'jpeg' => 'image/jpeg',
				'png'  => 'image/png',
				'gif'  => 'image/gif',
				'bmp'  => 'image/bmp',
				'svg'  => 'image/svg+xml',
				'webp' => 'image/webp',
				'avif' => 'image/avif',
			);
			if ( isset( $expected_mimes[ $extension ] ) && $mime !== $expected_mimes[ $extension ] ) {
				return '';
			}
		}

		return $real_local;
	}

	/**
	 * Resolve a URL to a local image file path, downloading remote
	 * images if needed.
	 *
	 * The plain `url_to_local_path()` only handles URLs that already
	 * live inside the WP uploads directory. This wrapper adds a fallback:
	 * for image URLs that are external (CDN, third-party host), it
	 * delegates to SScribe_Image_Processor::download_and_optimize() to
	 * fetch the image into a temp file, returning its local path.
	 *
	 * The download is gated by the existing `sscribe_allowed_image_hosts`
	 * filter : only whitelisted hosts will be fetched. Anything else
	 * returns an empty string, which exporters treat as a missing image
	 * (DOCX renders `[MISSING IMAGE]`, PDF skips the element).
	 *
	 * Security: path-traversal attempts in the URL (e.g.
	 * `/uploads/../sibling/file.png`) share the same host as the upload
	 * directory but resolve to a path outside it. We must NOT treat
	 * these as legitimate remote images : `url_to_local_path()` already
	 * rejects them, and we also skip the download fallback for any URL
	 * whose host matches the upload host. This prevents accidentally
	 * fetching a same-host URL that was constructed to escape the
	 * uploads directory.
	 *
	 * Returns an empty string for non-image URLs, blocked hosts, or
	 * download failures : never throws.
	 *
	 * @param string $url Image URL (local or remote).
	 * @return string Local file path, or empty string on failure.
	 */
	public function resolve_image_to_local( string $url ): string {
		$url = trim( $url );
		if ( '' === $url ) {
			return '';
		}

		$local = $this->url_to_local_path( $url );
		if ( '' !== $local && file_exists( $local ) ) {
			return $local;
		}

		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		if ( 'http' !== $scheme && 'https' !== $scheme ) {
			return '';
		}

		$upload_dir = $this->get_upload_dir();
		$upload_host = strtolower( (string) wp_parse_url( $upload_dir['baseurl'] ?? '', PHP_URL_HOST ) );
		$url_host    = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( '' !== $upload_host && $upload_host === $url_host ) {
			return '';
		}

		if ( ! class_exists( 'SScribe_Image_Processor' ) ) {
			require_once SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-image-processor.php';
		}

		$downloaded = \SScribe_Image_Processor::download_and_optimize( $url );
		if ( false === $downloaded || ! file_exists( $downloaded ) ) {
			return '';
		}

		return $downloaded;
	}
}
