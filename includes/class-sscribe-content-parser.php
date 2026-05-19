<?php

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SScribe_Content_Parser {

	private ?array $upload_dir_cache = null;

	private function get_upload_dir(): array {
		if ( null === $this->upload_dir_cache ) {
			$this->upload_dir_cache = wp_upload_dir();
		}
		return $this->upload_dir_cache;
	}

	public function parse( string $html ): array {
		if ( empty( $html ) ) {
			return array();
		}

		$logger = SScribe_Logger::instance( defined( 'SSCRIBE_DEBUG' ) && SSCRIBE_DEBUG );

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

	private function safe_replace( array|string $pattern, array|string $replacement, string $subject ): string {
		$result = preg_replace( $pattern, $replacement, $subject );
		return is_string( $result ) ? $result : $subject;
	}

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

	private function strip_all_styles( string $html ): string {
		return SScribe_Helpers::strip_page_builder_attributes( $html );
	}

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

		} catch ( \Throwable $e ) {
			throw $e;

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

			case 'div':
			case 'section':
			case 'article':
			case 'main':
			case 'aside':
			case 'figure':
			case 'figcaption':

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

	private function extract_buttons_from_html( string $html ): array {
		$buttons = array();

		$pattern = '/<a\s+[^>]*class=["\']([^"\']*(?:wp-block-button__link|wp-element-button|button|btn|elementor-button|et_pb_button|fl-button|vc_btn)[^"\']*)["\'][^>]*>(.*?)<\/a>/is';

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

	private function merge_buttons_into_elements( array $elements, array $buttons ): array {
		return array_merge( $elements, $buttons );
	}

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

	private function get_text_content( \DOMNode $node ): string {
		return trim( $node->textContent );
	}

	private function runs_to_text( array $runs ): string {
		$text = '';
		foreach ( $runs as $run ) {
			$text .= isset( $run['text'] ) ? $run['text'] : '';
		}
		return trim( $text );
	}

	private function url_to_local_path( string $url ): string {
		$upload_dir  = $this->get_upload_dir();
		$upload_url  = $upload_dir['baseurl'];
		$upload_path = realpath( $upload_dir['basedir'] );

		if ( empty( $upload_path ) || stripos( $url, $upload_url ) !== 0 ) {
			return '';
		}

		$relative = substr( $url, strlen( $upload_url ) );

		$stripped = strtok( $relative, '?' );
		$relative = false !== $stripped ? $stripped : $relative;

		$local      = $upload_path . $relative;
		$real_local = realpath( $local );

		if ( false === $real_local || strpos( $real_local, $upload_path ) !== 0 ) {
			return '';
		}

		$extension = strtolower( pathinfo( $real_local, PATHINFO_EXTENSION ) );
		if ( ! in_array( $extension, array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg', 'avif' ), true ) ) {
			return '';
		}

		return $real_local;
	}
}
