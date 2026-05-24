<?php
/**
 * SScribe Content Parser
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

class SScribe_Content_Parser {

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

		$logger = SScribe_Logger::instance( SSCRIBE_DEBUG );

		$logger->debug(
			'Content parser: parse() called',
			array(
				'html_len'     => strlen( $html ),
				'html_preview' => substr( $html, 0, 200 ),
			)
		);

		$button_elements = $this->extract_buttons_from_html( $html );
		$logger->debug( 'Content parser: buttons extracted', array( 'count' => count( $button_elements ) ) );

		$html = $this->normalize_html( $html );
		$logger->debug( 'Content parser: HTML normalized', array( 'normalized_len' => strlen( $html ) ) );

		$elements = $this->parse_dom( $html );
		$logger->debug(
			'Content parser: DOM parsed',
			array(
				'element_count' => count( $elements ),
				'button_count'  => count( $button_elements ),
			)
		);

		if ( ! empty( $button_elements ) ) {
			$elements = $this->merge_buttons_into_elements( $elements, $button_elements );
		}

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

		$html = wp_kses_post( $html );

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
		return SScribe_Helpers::strip_page_builder_attributes( $html );
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

			$html = mb_encode_numericentity(
				$html,
				array( 0x80, 0x10FFFF, 0, 0x1FFFFF ),
				'UTF-8'
			);

			$wrapped = '<!DOCTYPE html><html><head>'
				. '<meta http-equiv="Content-Type" content="text/html; charset=UTF-8">'
				. '</head><body>' . $html . '</body></html>';
			$dom->loadHTML( $wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );

			libxml_clear_errors();

			$body = $dom->getElementsByTagName( 'body' )->item( 0 );
			if ( ! $body ) {

				unset( $body );
				$dom = null;
				unset( $dom );
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
			if ( isset( $dom ) ) {
				$dom = null;
				unset( $dom );
			}
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
				$caption_node = null;

				foreach ( $node->childNodes as $child ) {
					if ( $child instanceof DOMElement ) {
						if ( 'img' === $child->tagName ) {
							$img_node = $child;
						} elseif ( 'figcaption' === $child->tagName ) {
							$caption_node = $child;
						}
					}
				}

				$output = '';
				if ( null !== $img_node ) {
					$src = $img_node->getAttribute( 'src' );
					$alt = $img_node->getAttribute( 'alt' );
					$alt = str_replace( array( '[', ']' ), array( '\[', '\]' ), $alt );
					$output .= '![' . $alt . '](' . $src . ')';
				}
				if ( null !== $caption_node ) {
					$caption_text = trim( $caption_node->textContent );
					if ( '' !== $caption_text ) {
						$output .= '\n\n*' . $caption_text . '*';
					}
				}

				return array(
					'type'    => 'figure',
					'content' => $output,
				);

			case 'figcaption':
				return array(
					'type'    => 'figcaption',
					'content' => trim( $node->textContent ),
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
				'depth'    => min( $depth, 2 ),
			);

			foreach ( $child->childNodes as $li_child ) {
				$li_tag = strtolower( $li_child->nodeName );

				if ( 'ul' === $li_tag || 'ol' === $li_tag ) {

					$nested_style = ( 'ul' === $li_tag ) ? 'bullet' : 'numbered';
					$nested       = $this->parse_list( $li_child, $nested_style, $depth + 1 );
					if ( isset( $nested['items'] ) ) {
						$item['children'] = $nested['items'];
					}
				} elseif ( XML_TEXT_NODE === $li_child->nodeType ) {

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
					$cells[] = array(
						'content'   => trim( $td->textContent ),
						'runs'      => $this->get_inline_runs( $td ),
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

		$local_path = $this->url_to_local_path( $src );

		return array(
			'type'       => 'image',
			'src'        => $src,
			'alt'        => $alt,
			'local_path' => $local_path,
		);
	}

	/**
	 * Extract button elements from HTML.
	 *
	 * @param string $html HTML content.
	 * @return array Button elements.
	 */
	private function extract_buttons_from_html( string $html ): array {
		$buttons = array();

		// Limit input size to prevent regex backtracking on large content.
		// Use mb_strcut to avoid splitting multi-byte UTF-8 characters.
		if ( mb_strlen( $html, '8bit' ) > 500000 ) {
			$html = mb_strcut( $html, 0, 500000, 'UTF-8' );
		}

		// Guard clause: skip if no button-related class keywords exist in input.
		$button_keywords = array(
			'wp-block-button__link',
			'wp-element-button',
			'elementor-button',
			'et_pb_button',
			'fl-button',
			'vc_btn',
		);
		$has_button_keyword = false;
		foreach ( $button_keywords as $keyword ) {
			if ( false !== strpos( $html, $keyword ) ) {
				$has_button_keyword = true;
				break;
			}
		}
		if ( ! $has_button_keyword && false === strpos( $html, 'class="button' ) && false === strpos( $html, "class='button" ) && false === strpos( $html, 'class="btn' ) && false === strpos( $html, "class='btn" ) ) {
			return $buttons;
		}

		/*
		 * Improved regex pattern that avoids catastrophic backtracking.
		 *
		 * Key improvements:
		 * 1. Matches <a followed by whitespace (not just any char)
		 * 2. Uses negated character classes that cannot contain > or quotes
		 * 3. Avoids pattern [^>]*class= which backtracks heavily on non-matching input
		 * 4. Separates attribute parsing from button class detection
		 *
		 * Pattern breakdown:
		 * - <a\s+          : <a tag with at least one space
		 * - (?:[^>]*?)     : optional attributes before class (non-greedy, prevents backtracking)
		 * - class=["\']    : class attribute opening
		 * - [^"\']*        : class value before button class (no quotes)
		 * - (?:wp-block-button__link|wp-element-button|...) : button class alternatives
		 * - [^"\']*        : class value after button class
		 * - ["\']          : closing quote
		 * - [^>]*          : remaining attributes
		 * - >               : tag close
		 * - (.*?)          : content (non-greedy)
		 * - <\/a>          : closing anchor
		 */
		$pattern = '/<a\s+(?:[^>]*?\s)?class=["\']([^"\']*(?:wp-block-button__link|wp-element-button|button|btn|elementor-button|et_pb_button|fl-button|vc_btn)[^"\']*)["\'](?:[^>]*)?>(.*?)<\/a>/is';

		$match_count = preg_match_all( $pattern, $html, $matches, PREG_SET_ORDER );
		if ( false !== $match_count && $match_count > 0 ) {
			foreach ( $matches as $match ) {
				$classes = $match[1];
				$content = wp_strip_all_tags( $match[2] );
				$content = html_entity_decode( $content, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				$content = trim( $content );

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
	 * Merge button elements into existing elements array.
	 *
	 * @param array $elements Existing elements.
	 * @param array $buttons  Button elements.
	 * @return array Merged elements.
	 */
	private function merge_buttons_into_elements( array $elements, array $buttons ): array {
		return array_merge( $elements, $buttons );
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
						$runs = array_merge( $runs, $sub_runs );
						break;

					case 'em':
					case 'i':
						$sub_runs = $this->get_inline_runs( $child );
						foreach ( $sub_runs as $key => $run ) {
							$sub_runs[ $key ]['italic'] = true;
						}
						$runs = array_merge( $runs, $sub_runs );
						break;

					case 'u':
						$sub_runs = $this->get_inline_runs( $child );
						foreach ( $sub_runs as $key => $run ) {
							$sub_runs[ $key ]['underline'] = true;
						}
						$runs = array_merge( $runs, $sub_runs );
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
						$runs     = array_merge( $runs, $sub_runs );
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

					default:
						$sub_runs = $this->get_inline_runs( $child );
						$runs     = array_merge( $runs, $sub_runs );
						break;
				}
			}
		}

		return $runs;
	}

	/**
	 * Get text content from a DOM node.
	 *
	 * @param \DOMNode $node DOM node.
	 * @return string
	 */
	private function get_text_content( \DOMNode $node ): string {
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
		if ( ! in_array( $extension, array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg', 'avif' ), true ) ) {
			return '';
		}

		return $real_local;
	}
}
