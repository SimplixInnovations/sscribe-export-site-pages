<?php
/**
 * Parses HTML content into structured elements for DOCX generation.
 *
 * @package SScribe
 */

declare(strict_types=1);

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

		// Note: Shortcodes are already expanded by apply_filters('the_content') before
		// this method is called, so we don't need to strip shortcode markers here.
		// We only clean up any remaining shortcode-like patterns that could be content.

		// CRITICAL: Detect button elements BEFORE normalizing HTML.
		// normalize_html() strips class attributes which detect_button() needs.
		// We must extract buttons from raw HTML first, then normalize for further parsing.
		$button_elements = $this->extract_buttons_from_html( $html );

		// Normalize HTML (strips classes, but we already captured buttons).
		$html = $this->normalize_html( $html );

		// Parse DOM.
		$elements = $this->parse_dom( $html );

		// Inject detected buttons into the element stream.
		if ( ! empty( $button_elements ) ) {
			$elements = $this->merge_buttons_into_elements( $elements, $button_elements );
		}

		return $elements;
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
			// CRITICAL: Clear DOMDocument before returning to free memory.
			// DOMDocument can retain large amounts of memory for complex HTML.
			unset( $body );
			$dom = null;
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

		// CRITICAL: Explicitly release DOMDocument to prevent memory accumulation.
		// DOMDocument retains the entire parsed tree in memory even after processing.
		// For large HTML documents (5MB+), this can consume significant memory.
		unset( $body );
		$dom = null;
		unset( $dom );

		return $elements;
	}

	/**
	 * Parse a DOM node into a structured element.
	 *
	 * @param \DOMNode $node  The DOM node.
	 * @param int      $depth Nesting depth for lists.
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

				// NOTE: Button detection is now handled in parse() before normalize_html() strips classes.
				// This commented code is kept for reference but is effectively dead code.
				// $button = $this->detect_button( $node );
				// if ( $button ) { return $button; }
				// End of commented code.

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
	 * @param \DOMNode $node   The list node.
	 * @param string   $style  'bullet' or 'numbered'.
	 * @param int      $depth  Nesting depth.
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
				} elseif ( XML_TEXT_NODE === $li_child->nodeType ) {
					// Text content of this list item.
					$text = trim( $li_child->textContent );
					if ( ! empty( $text ) ) {
						$item['runs'][] = array( 'text' => $text );
					}
				} elseif ( XML_ELEMENT_NODE === $li_child->nodeType ) {
					$inline_runs  = $this->get_inline_runs( $li_child );
					$item['runs'] = array_merge( $item['runs'], $inline_runs );
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
	 * @param \DOMNode $node The table node.
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
	 * @param \DOMNode $node The img node.
	 *
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
	 * Extract button elements from raw HTML before normalization.
	 *
	 * This must be called BEFORE normalize_html() because that function
	 * strips class attributes which are needed to detect button-like links.
	 *
	 * @param string $html The raw HTML content.
	 * @return array Array of button element arrays.
	 */
	private function extract_buttons_from_html( string $html ): array {
		$buttons = array();

		// Use regex to find anchor tags with button-like classes before DOM parsing.
		// This captures buttons that would be lost after normalize_html() strips classes.
		$pattern = '/<a\s+[^>]*class=["\']([^"\']*(?:wp-block-button__link|wp-element-button|button|btn|elementor-button|et_pb_button|fl-button|vc_btn)[^"\']*)["\'][^>]*>(.*?)<\/a>/is';

		if ( preg_match_all( $pattern, $html, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$classes = $match[1];
				$content = wp_strip_all_tags( $match[2] );
				$content = html_entity_decode( $content, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				$content = trim( $content );

				// Extract href if present.
				$url = '';
				if ( preg_match( '/href=["\']([^"\']+)/', $match[0], $url_match ) ) {
					$url = $url_match[1];
				}

				if ( ! empty( $content ) ) {
					$buttons[] = array(
						'type'    => 'button',
						'content' => $content,
						'url'     => $url,
					);
				}
			}
		}

		return $buttons;
	}

	/**
	 * Merge extracted buttons into the element stream.
	 *
	 * Buttons are inserted as first-level elements in the stream.
	 *
	 * @param array $elements Parsed elements from DOM.
	 * @param array $buttons   Extracted button elements.
	 * @return array Merged elements with buttons included.
	 */
	private function merge_buttons_into_elements( array $elements, array $buttons ): array {
		// Prepend all detected buttons to the element stream.
		return array_merge( $buttons, $elements );
	}

	/**
	 * Get inline runs (text with formatting) from a node.
	 *
	 * @param \DOMNode $node The container node.
	 *
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
	 * @param \DOMNode $node The node.
	 *
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
