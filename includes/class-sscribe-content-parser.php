<?php
/**
 * Parses HTML content into structured elements for DOCX generation.
 *
 * @package SScribe
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SScribe_Content_Parser
 *
 * Converts rendered HTML page content into a structured array
 * of elements that the Exporter can turn into PHPWord objects.
 */
class SScribe_Content_Parser {


	/**
	 * Cached upload directory data.
	 *
	 * @var array|null
	 */
	private $upload_dir_cache = null;

	/**
	 * Get cached upload directory info.
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
	 * @param string $html The rendered HTML content.
	 * @return array Array of element arrays.
	 */
	public function parse( string $html ): array {
		if ( empty( $html ) ) {
			return array();
		}

		// Strip shortcode markers.
		$html = $this->strip_shortcodes( $html );

		// Normalize HTML.
		$html = $this->normalize_html( $html );

		// Parse DOM.
		$elements = $this->parse_dom( $html );

		return $elements;
	}

	/**
	 * Strip remaining shortcodes and add placeholder marker.
	 *
	 * @param string $html The HTML content.
	 * @return string Cleaned HTML.
	 */
	private function strip_shortcodes( string $html ): string {
		// Use WordPress's own shortcode stripper first (safe — only strips registered shortcodes).
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WP core function.
		$html = strip_shortcodes( $html );

		// Strip any remaining unregistered shortcode-like patterns only if they look like
		// actual shortcodes (must have a valid tag name at the start, with attributes).
		// Be conservative — do NOT strip simple [word] patterns that could be content.
		$html = preg_replace( '/\[(\/?[a-zA-Z0-9_-]+)(\s+[^\]]+)?\]/', '', $html );

		return $html;
	}

	/**
	 * Normalize HTML for consistent parsing.
	 *
	 * @param string $html The HTML content.
	 * @return string Normalized HTML.
	 */
	private function normalize_html( string $html ): string {
		$html = $this->strip_all_styles( $html );

		$html = preg_replace( '/<(style|script|noscript|svg)\b[^>]*>.*?<\/\1>/is', '', $html );

		$html = preg_replace( '/<!--.*?-->/s', '', $html );

		$html = preg_replace( '/:root\s*\{[^}]*\}/s', '', $html );
		$html = preg_replace( '/\.elementor-[a-zA-Z0-9_-]+\s*\{[^}]*\}/s', '', $html );

		$html = preg_replace( '/<style[^>]*>.*?<\/style>/is', '', $html );
		$html = preg_replace( '/<script[^>]*>.*?<\/script>/is', '', $html );
		$html = preg_replace( '/<svg[^>]*>.*?<\/svg>/is', '', $html );
		$html = preg_replace( '/<noscript[^>]*>.*?<\/noscript>/is', '', $html );

		$html = wp_kses_post( $html );

		$html = preg_replace( '/>\s+</', '><', $html );

		$html = preg_replace( '/<\/(p|div|h[1-6]|ul|ol|li|table|tr|blockquote|pre)>/', "</$1>\n", $html );

		return trim( $html );
	}

	/**
	 * Strip all inline styles and Elementor-specific attributes.
	 *
	 * @param string $html The HTML content.
	 * @return string Cleaned HTML.
	 */
	private function strip_all_styles( string $html ): string {
		$html = preg_replace( '/\s*style="[^"]*"/i', '', $html );
		$html = preg_replace( "/\s*style='[^']*'/i", '', $html );

		$html = preg_replace( '/<style[^>]*>.*?<\/style>/is', '', $html );

		$html = preg_replace( '/\s*class="[^"]*"/i', '', $html );
		$html = preg_replace( "/\s*class='[^']*'/i", '', $html );

		$html = preg_replace( '/\s*data-elementor(-[a-z]+)?="[^"]*"/i', '', $html );
		$html = preg_replace( '/\s*data-(widget|column|section)-[^=]*="[^"]*"/i', '', $html );
		$html = preg_replace( '/\s*data-settings="[^"]*"/i', '', $html );
		$html = preg_replace( '/\s*data-id="[^"]*"/i', '', $html );

		$html = preg_replace( '/\s*id="elementor-[^"]*"/i', '', $html );

		$html = preg_replace( '/<div\s+id="[^"]*"[^>]*><\/div>/i', '', $html );
		$html = preg_replace( '/<span\s*><\/span>/i', '', $html );
		$html = preg_replace( '/<div\s*><\/div>/i', '', $html );

		return $html;
	}

	/**
	 * Parse HTML DOM into structured element array.
	 *
	 * @param string $html The HTML content.
	 * @return array Array of elements.
	 */
	private function parse_dom( string $html ): array {
		$elements = array();

		// Use DOMDocument for reliable parsing.
		$dom = new DOMDocument( '1.0', 'UTF-8' );

		// Suppress warnings from malformed HTML.
		$prev_use_errors = libxml_use_internal_errors( true );

		// Wrap content to ensure proper encoding.
		$wrapped = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>' . $html . '</body></html>';
		$dom->loadHTML( $wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );

		libxml_clear_errors();
		libxml_use_internal_errors( $prev_use_errors );

		$body = $dom->getElementsByTagName( 'body' )->item( 0 );
		if ( ! $body ) {
			unset( $dom );
			return $elements;
		}

		// Process child nodes.
		foreach ( $body->childNodes as $node ) {
			$parsed = $this->parse_node( $node );
			if ( $parsed ) {
				if ( isset( $parsed['type'] ) ) {
					$elements[] = $parsed;
				} else {
					// Array of elements returned.
					$elements = array_merge( $elements, $parsed );
				}
			}
		}

		unset( $dom );

		return $elements;
	}

	/**
	 * Parse a DOM node into a structured element.
	 *
	 * @param DOMNode $node  The DOM node.
	 * @param int     $depth Nesting depth for lists.
	 * @return array|null Element data or null if node should be skipped.
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

				// Check if this is a button paragraph.
				$button = $this->detect_button( $node );
				if ( $button ) {
					return $button;
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
				// Standalone link (not inside a paragraph).
				$href = $node->getAttribute( 'href' );
				$text = $this->get_text_content( $node );
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

			case 'div':
			case 'section':
			case 'article':
			case 'main':
			case 'aside':
			case 'figure':
			case 'figcaption':
				// Recurse into container elements.
				$children = array();
				foreach ( $node->childNodes as $child ) {
					$parsed = $this->parse_node( $child, $depth );
					if ( $parsed ) {
						if ( isset( $parsed['type'] ) ) {
							$children[] = $parsed;
						} else {
							$children = array_merge( $children, $parsed );
						}
					}
				}
				return ! empty( $children ) ? $children : null;

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

			default:
				// Try to extract text from unknown elements.
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
	 * Parse a list element (ul/ol) into structured items.
	 *
	 * @param DOMNode $node   The list node.
	 * @param string  $style  'bullet' or 'numbered'.
	 * @param int     $depth  Nesting depth.
	 * @return array List element data.
	 */
	private function parse_list( \DOMNode $node, string $style, int $depth = 0 ): array {
		$items = array();

		foreach ( $node->childNodes as $child ) {
			if ( XML_ELEMENT_NODE !== $child->nodeType || 'li' !== strtolower( $child->nodeName ) ) {
				continue;
			}

			$item = array(
				'content'  => '',
				'runs'     => array(),
				'children' => array(),
				'depth'    => min( $depth, 2 ), // Max 3 levels (0, 1, 2).
			);

			foreach ( $child->childNodes as $li_child ) {
				$li_tag = strtolower( $li_child->nodeName );

				if ( 'ul' === $li_tag || 'ol' === $li_tag ) {
					// Nested list.
					$nested_style = ( 'ul' === $li_tag ) ? 'bullet' : 'numbered';
					$nested       = $this->parse_list( $li_child, $nested_style, $depth + 1 );
					if ( isset( $nested['items'] ) ) {
						$item['children'] = $nested['items'];
					}
				} else {
					// Text content of this list item.
					if ( XML_TEXT_NODE === $li_child->nodeType ) {
						$text = trim( $li_child->textContent );
						if ( ! empty( $text ) ) {
							$item['runs'][] = array( 'text' => $text );
						}
					} elseif ( XML_ELEMENT_NODE === $li_child->nodeType ) {
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
	 * Parse an HTML table into structured data.
	 *
	 * @param DOMNode $node The table node.
	 * @return array Table element data.
	 */
	private function parse_table( \DOMNode $node ): array {
		$rows = array();

		// Get thead/tbody/direct tr children.
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
					$cells[] = array(
						'content'   => trim( $td->textContent ),
						'is_header' => ( 'th' === $cell_tag || $section['is_header'] ),
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
	 * Parse an image element.
	 *
	 * @param DOMNode $node The img node.
	 * @return array|null Image element data or null if src is empty.
	 */
	private function parse_image( \DOMNode $node ): ?array {
		$src = $node->getAttribute( 'src' );
		$alt = $node->getAttribute( 'alt' );

		if ( empty( $src ) ) {
			return null;
		}

		// Try to get local file path.
		$local_path = $this->url_to_local_path( $src );

		return array(
			'type'       => 'image',
			'src'        => $src,
			'alt'        => $alt,
			'local_path' => $local_path,
		);
	}

	/**
	 * Detect button-like elements inside a paragraph.
	 *
	 * @param DOMNode $node The paragraph node.
	 * @return array|false Button element data or false.
	 */
	private function detect_button( \DOMNode $node ): array|false {
		// Look for links with button-like classes.
		$links = $node->getElementsByTagName( 'a' );

		foreach ( $links as $link ) {
			$classes = $link->getAttribute( 'class' );
			if ( $this->is_button_class( $classes ) ) {
				return array(
					'type'    => 'button',
					'content' => trim( $link->textContent ),
					'url'     => $link->getAttribute( 'href' ),
				);
			}
		}

		// Check for actual button elements.
		$buttons = $node->getElementsByTagName( 'button' );
		foreach ( $buttons as $button ) {
			return array(
				'type'    => 'button',
				'content' => trim( $button->textContent ),
				'url'     => '',
			);
		}

		return false;
	}

	/**
	 * Check if CSS classes indicate a button.
	 *
	 * @param string $classes The class attribute value.
	 * @return bool
	 */
	private function is_button_class( string $classes ): bool {
		$button_patterns = array(
			'wp-block-button__link',
			'wp-element-button',
			'button',
			'btn',
			'elementor-button',
			'et_pb_button',
			'fl-button',
			'vc_btn',
		);

		foreach ( $button_patterns as $pattern ) {
			if ( str_contains( $classes, $pattern ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get inline runs (text with formatting) from a node.
	 *
	 * @param DOMNode $node The container node.
	 * @return array Array of run data (text, bold, italic, link, etc.).
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
						foreach ( $sub_runs as &$run ) {
							$run['bold'] = true;
						}
						$runs = array_merge( $runs, $sub_runs );
						break;

					case 'em':
					case 'i':
						$sub_runs = $this->get_inline_runs( $child );
						foreach ( $sub_runs as &$run ) {
							$run['italic'] = true;
						}
						$runs = array_merge( $runs, $sub_runs );
						break;

					case 'u':
						$sub_runs = $this->get_inline_runs( $child );
						foreach ( $sub_runs as &$run ) {
							$run['underline'] = true;
						}
						$runs = array_merge( $runs, $sub_runs );
						break;

					case 's':
					case 'del':
					case 'strike':
						$sub_runs = $this->get_inline_runs( $child );
						foreach ( $sub_runs as &$run ) {
							$run['strikethrough'] = true;
						}
						$runs = array_merge( $runs, $sub_runs );
						break;

					case 'a':
						$href     = $child->getAttribute( 'href' );
						$sub_runs = $this->get_inline_runs( $child );
						foreach ( $sub_runs as &$run ) {
							$run['link'] = $href;
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
						// Recurse into spans.
						$sub_runs = $this->get_inline_runs( $child );
						$runs     = array_merge( $runs, $sub_runs );
						break;

					case 'img':
						// Inline image reference.
						$src = $child->getAttribute( 'src' );
						if ( $src ) {
							$runs[] = array(
								'text'  => '[Image: ' . $child->getAttribute( 'alt' ) . ']',
								'image' => $src,
							);
						}
						break;

					default:
						// Extract text from unknown inline elements.
						$sub_runs = $this->get_inline_runs( $child );
						$runs     = array_merge( $runs, $sub_runs );
						break;
				}
			}
		}

		return $runs;
	}

	/**
	 * Get plain text content from a node.
	 *
	 * @param DOMNode $node The node.
	 * @return string Plain text content.
	 */
	private function get_text_content( \DOMNode $node ): string {
		return trim( $node->textContent );
	}

	/**
	 * Convert runs array to plain text.
	 *
	 * @param array $runs Array of run data.
	 * @return string Plain text.
	 */
	private function runs_to_text( array $runs ): string {
		$text = '';
		foreach ( $runs as $run ) {
			$text .= isset( $run['text'] ) ? $run['text'] : '';
		}
		return trim( $text );
	}

	/**
	 * Try to convert a URL to a local file path.
	 *
	 * @param string $url The image URL.
	 * @return string Local file path or empty string if not found/invalid.
	 */
	private function url_to_local_path( string $url ): string {
		$upload_dir  = $this->get_upload_dir();
		$upload_url  = $upload_dir['baseurl'];
		$upload_path = realpath( $upload_dir['basedir'] );

		// Only process URLs that start with our upload base URL.
		if ( empty( $upload_path ) || strpos( $url, $upload_url ) !== 0 ) {
			return '';
		}

		$relative = substr( $url, strlen( $upload_url ) );

		// Strip query strings (e.g. ?v=123 on CDN URLs).
		$relative = strtok( $relative, '?' );

		$local      = $upload_path . $relative;
		$real_local = realpath( $local );

		// CRITICAL: Ensure the resolved path is still within the uploads directory.
		if ( false === $real_local || strpos( $real_local, $upload_path ) !== 0 ) {
			return '';
		}

		// Only allow common image file types.
		$extension = strtolower( pathinfo( $real_local, PATHINFO_EXTENSION ) );
		if ( ! in_array( $extension, array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp' ), true ) ) {
			return '';
		}

		return $real_local;
	}
}
