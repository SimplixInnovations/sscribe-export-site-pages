<?php
/**
 * SScribe Markdown Exporter
 *
 * @package SScribe_Export_Site_Pages
 * @license GPL v2 or later
 * @link    https://www.gnu.org/licenses/gpl-2.0.html
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once SSCRIBE_PLUGIN_DIR . 'includes/exporters/interface-sscribe-exporter.php';
require_once SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-rtl-helper.php';

/**
 * Exports pages as Markdown files with YAML front matter.
 */
class SScribe_Markdown_Exporter implements SScribe_Exporter_Interface {

	/**
	 * Logger instance.
	 *
	 * @var SScribe_Logger_Interface|null
	 */
	private ?SScribe_Logger_Interface $logger = null;

	/**
	 * Filesystem handler.
	 *
	 * @var SScribe_Filesystem
	 */
	private SScribe_Filesystem $filesystem;

	/**
	 * Initialize the Markdown exporter.
	 *
	 * @param SScribe_Logger_Interface|null $logger     Logger.
	 * @param SScribe_Filesystem|null       $filesystem Filesystem handler.
	 */
	public function __construct( ?SScribe_Logger_Interface $logger = null, ?SScribe_Filesystem $filesystem = null ) {
		$this->logger     = $logger ?? SScribe_Logger::instance( SScribe_Logger::is_logging_enabled() );
		$this->filesystem = $filesystem ?? new SScribe_Filesystem();
	}

	/**
	 * Export a page as a Markdown file.
	 *
	 * @param array  $page_data Page data to export.
	 * @param string $output_dir Output directory path.
	 * @param int    $index     Current page index.
	 * @param int    $total     Total number of pages.
	 * @return SScribe_Result Result of the export operation.
	 */
	public function export( array $page_data, string $output_dir, int $index = 0, int $total = 0 ): SScribe_Result {
		$page_id = $page_data['id'] ?? 0;
		$title   = $page_data['title'] ?? 'Untitled';

		try {
			$markdown = $this->generate_markdown( $page_data );

			$filename    = \SScribe_Exporter_Factory::build_filename( $page_data, $index, $total, 'md' );
			$output_path = trailingslashit( $output_dir ) . $filename;

			$result = $this->filesystem->put_contents( $output_path, $markdown );

			if ( ! $result ) {
				$this->logger->error(
					'Markdown export failed: filesystem write error',
					array(
						'page_id'   => $page_id,
						'path'      => $output_path,
						'fs_error'  => $this->filesystem->get_last_error(),
						'fs_method' => $this->filesystem->get_method(),
					)
				);

				return SScribe_Result::failure(
					sprintf(
						/* translators: %s: Page title. */

						__( 'Failed to write Markdown file for "%s".', 'sscribe-export-site-pages' ),
						$title
					),
					array(
						'page_id' => $page_id,
						'path'    => $output_path,
					)
				);
			}

			return SScribe_Result::success(
				array(
					'path' => $output_path,
					'size' => strlen( $markdown ),
				)
			);

		} catch ( \Throwable $e ) {
			$this->logger->error(
				'Markdown export crashed',
				array(
					'page_id' => $page_id,
					'title'   => $title,
					'error'   => $e->getMessage(),
					'file'    => $e->getFile(),
					'line'    => $e->getLine(),
				)
			);

			return SScribe_Result::failure(
				sprintf(
					/* translators: 1: Page title, 2: Error message. */

					__( 'Markdown export failed for "%1$s": %2$s', 'sscribe-export-site-pages' ),
					$title,
					$e->getMessage()
				),
				array( 'page_id' => $page_id )
			);
		}
	}

	/**
	 * Generate Markdown content from page data.
	 *
	 * @param array $page_data Page data.
	 * @return string Markdown content.
	 */
	private function generate_markdown( array $page_data ): string {
		$md  = $this->generate_frontmatter( $page_data );
		$md .= $this->html_to_markdown( $page_data['content'] ?? '' );

		// NOTE: BOM is intentionally NOT prepended here. The YAML frontmatter
		// already contains a `direction` field (set by generate_frontmatter()) which
		// static site generators (Hugo, Jekyll, Obsidian) read to determine text
		// direction. Prepending a UTF-8 BOM (\xEF\xBB\xBF) before the YAML ---
		// delimiter would place the BOM bytes BEFORE the document start marker,
		// causing YAML parsers to reject the front matter entirely.

		return $md;
	}

	/**
	 * Generate YAML frontmatter from page data.
	 *
	 * @param array $page_data Page data.
	 * @return string YAML frontmatter block.
	 */
	private function generate_frontmatter( array $page_data ): string {
		$title     = $page_data['title'] ?? 'Untitled';
		$language  = $page_data['language'] ?? 'en';
		$direction = SScribe_RTL_Helper::get_direction( $language );

		// YAML front matter block FIRST — byte zero for Hugo/Jekyll/Obsidian compatibility.
		$md  = "---\n";
		$md .= 'title: "' . $this->escape_yaml_string( $title ) . "\"\n";
		$md .= 'url: "' . $this->escape_yaml_string( $page_data['permalink'] ?? '' ) . "\"\n";
		$md .= 'slug: "' . $this->escape_yaml_string( $page_data['slug'] ?? '' ) . "\"\n";
		$md .= 'author: "' . $this->escape_yaml_string( $page_data['author'] ?? 'Unknown' ) . "\"\n";
		$md .= 'published: "' . $this->escape_yaml_string( $page_data['date_published'] ?? '' ) . "\"\n";
		$md .= 'modified: "' . $this->escape_yaml_string( $page_data['date_modified'] ?? '' ) . "\"\n";
		$md .= 'word_count: ' . (int) ( $page_data['word_count'] ?? 0 ) . "\n";
		$md .= 'reading_time: ' . (int) ( $page_data['reading_time'] ?? 1 ) . "\n";
		$md .= 'language: "' . $this->escape_yaml_string( $language ) . "\"\n";
		$md .= 'direction: "' . $this->escape_yaml_string( $direction ) . "\"\n";

		if ( ! empty( $page_data['featured_image_url'] ) ) {
			$md .= 'featured_image: "' . $this->escape_yaml_string( $page_data['featured_image_url'] ) . "\"\n";
		}

		if ( ! empty( $page_data['seo'] ) ) {
			$seo = $page_data['seo'];
			if ( ! empty( $seo['meta_title'] ) ) {
				$md .= 'seo_title: "' . $this->escape_yaml_string( $seo['meta_title'] ) . "\"\n";
			}
			if ( ! empty( $seo['meta_description'] ) ) {
				$md .= 'seo_description: "' . $this->escape_yaml_string( $seo['meta_description'] ) . "\"\n";
			}
			if ( ! empty( $seo['focus_keyword'] ) ) {
				$md .= 'seo_focus_keyword: "' . $this->escape_yaml_string( $seo['focus_keyword'] ) . "\"\n";
			}
			if ( ! empty( $seo['canonical_url'] ) ) {
				$md .= 'canonical_url: "' . $this->escape_yaml_string( $seo['canonical_url'] ) . "\"\n";
			}
			if ( ! empty( $seo['og_title'] ) ) {
				$md .= 'og_title: "' . $this->escape_yaml_string( $seo['og_title'] ) . "\"\n";
			}
			if ( ! empty( $seo['og_description'] ) ) {
				$md .= 'og_description: "' . $this->escape_yaml_string( $seo['og_description'] ) . "\"\n";
			}
			if ( ! empty( $seo['source'] ) ) {
				$md .= 'seo_source: "' . $this->escape_yaml_string( $seo['source'] ) . "\"\n";
			}
		}

		if ( ! empty( $page_data['breadcrumbs'] ) && count( $page_data['breadcrumbs'] ) > 1 ) {
			$md .= "breadcrumbs:\n";
			foreach ( $page_data['breadcrumbs'] as $crumb ) {
				$md .= '  - title: "' . $this->escape_yaml_string( $crumb['title'] ?? '' ) . "\"\n";
				if ( ! empty( $crumb['url'] ) ) {
					$md .= '    url: "' . $this->escape_yaml_string( $crumb['url'] ) . "\"\n";
				}
			}
		}

		$md .= "---\n\n";

		// Human-readable header AFTER front matter — not part of YAML document.
		$md .= '# ' . $this->escape_markdown( $title ) . "\n\n";
		$md .= '> ' . ( $page_data['permalink'] ?? '' ) . "\n\n";

		return $md;
	}

	/**
	 * Convert HTML to Markdown.
	 *
	 * @param string $html HTML content.
	 * @return string Markdown content.
	 */
	private function html_to_markdown( string $html ): string {
		if ( empty( $html ) ) {
			return '';
		}

		$html = $this->strip_all_styles( $html );

		$md = $html;

		$md = $this->convert_tables( $md );
		$md = $this->convert_headings( $md );
		$md = $this->convert_images( $md );
		$md = $this->convert_links( $md );
		$md = $this->convert_formatting( $md );
		$md = $this->convert_lists( $md );
		$md = $this->convert_code_blocks( $md );
		$md = $this->convert_paragraphs( $md );
		$md = $this->convert_blockquotes( $md );
		$md = $this->convert_horizontal_rules( $md );
		$md = $this->convert_details( $md );

		// Decode HTML entities AFTER stripping all tags so that any HTML-like
		// characters that resulted from decoding (e.g. a literal <div> string)
		// cannot be misinterpreted as HTML tags by wp_strip_all_tags().
		// Code block content is already protected by triple-backtick fences at
		// this point; html_entity_decode() only affects the remaining content.
		$md = wp_strip_all_tags( $md );
		$md = html_entity_decode( $md, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		// Escape Markdown syntax characters at line-start positions in body content
		// to prevent literal characters from being interpreted as Markdown.
		$md = $this->escape_markdown_body( $md );

		$md = preg_replace( '/\n{3,}/', "\n\n", $md );
		$md = preg_replace( '/[ \t]+$/m', '', $md );

		return trim( $md );
	}

	/**
	 * Strip all style/script elements and attributes, replacing SVGs with placeholders.
	 *
	 * @param string $html HTML content.
	 * @return string Cleaned HTML.
	 */
	private function strip_all_styles( string $html ): string {
		$html = preg_replace( '/<style[^>]*>.*?<\/style>/is', '', $html ) ?? $html;
		$html = preg_replace( '/<script[^>]*>.*?<\/script>/is', '', $html ) ?? $html;
		$html = preg_replace( '/<noscript[^>]*>.*?<\/noscript>/is', '', $html ) ?? $html;

		// Replace SVGs with a text placeholder to preserve intent.
		$html = preg_replace_callback(
			'/<svg[^>]*>.*?<\/svg>/is',
			function ( $matches ) {
				// Try to extract a title child element as alt text.
				if ( preg_match( '/<title[^>]*>(.*?)<\/title>/is', $matches[0], $t ) ) {
					$label = trim( wp_strip_all_tags( $t[1] ) );
					return $label ? '[SVG: ' . $label . ']' : '[SVG image]';
				}
				return '[SVG image]';
			},
			$html
		) ?? $html;

		$html = preg_replace( '/\s*style="[^"]*"/i', '', $html ) ?? $html;
		$html = preg_replace( "/\s*style='[^']*'/i", '', $html ) ?? $html;
		$html = preg_replace( '/\s*class="[^"]*"/i', '', $html ) ?? $html;
		$html = preg_replace( "/\s*class='[^']*'/i", '', $html ) ?? $html;
		$html = preg_replace( '/\s*data-[a-z-]+="[^"]*"/i', '', $html ) ?? $html;
		$html = preg_replace( "/\s*data-[a-z-]+='[^']*'/i", '', $html ) ?? $html;
		$html = preg_replace( '/<!--.*?-->/s', '', $html ) ?? $html;

		return $html;
	}

	/**
	 * Convert HTML tables to Markdown tables.
	 *
	 * @param string $html HTML content.
	 * @return string Content with Markdown tables.
	 */
	private function convert_tables( string $html ): string {
		return preg_replace_callback(
			'/<table[^>]*>(.*?)<\/table>/is',
			function ( $matches ) {
				$table_html = $matches[1];

				if ( ! preg_match_all( '/<tr[^>]*>(.*?)<\/tr>/is', $table_html, $row_matches ) ) {
					return '';
				}

				$rows         = array();
				$is_first_row = true;

				foreach ( $row_matches[1] as $row_html ) {
					$cells        = array();
					$cell_pattern = '/<t[dh][^>]*>(.*?)<\/t[dh]>/is';

					if ( preg_match_all( $cell_pattern, $row_html, $cell_matches ) ) {
						foreach ( $cell_matches[1] as $cell_content ) {
							$cell_content = wp_strip_all_tags( $cell_content );
							$cell_content = trim( preg_replace( '/\s+/', ' ', $cell_content ) );
							// Escape pipe characters in cell content to prevent table misalignment.
							$cell_content = str_replace( '|', '\\|', $cell_content );
							$cells[]      = $cell_content;
						}
					}

					if ( ! empty( $cells ) ) {
						$rows[] = $cells;

						// Always add GFM separator row after first row — even when no <th> cells
						// are present — to produce a valid Markdown table (GFM requires the
						// | --- | --- | row). Use left-align (:---) as the default alignment.
						if ( $is_first_row ) {
							$rows[]       = array_fill( 0, count( $cells ), ':---' );
							$is_first_row = false;
						}
					}
				}

				if ( empty( $rows ) ) {
					return '';
				}

				$md_table = "\n";
				foreach ( $rows as $row ) {
					$md_table .= '| ' . implode( ' | ', $row ) . ' |' . "\n";
				}
				$md_table .= "\n";

				return $md_table;
			},
			$html
		) ?? $html;
	}

	/**
	 * Convert HTML headings to Markdown headings.
	 *
	 * @param string $html HTML content.
	 * @return string Content with Markdown headings.
	 */
	private function convert_headings( string $html ): string {
		for ( $i = 6; $i >= 1; $i-- ) {
			$html = preg_replace_callback(
				'/<h' . $i . '[^>]*>(.*?)<\/h' . $i . '>/is',
				function ( $m ) use ( $i ): string {
					// Strip inner HTML tags at capture time so child elements like <span>,
					// <a>, <strong> don't appear raw in the Markdown heading.
					$inner = wp_strip_all_tags( $m[1] );
					return "\n" . str_repeat( '#', $i ) . ' ' . $inner . "\n";
				},
				$html
			) ?? $html;
		}
		return $html;
	}

	/**
	 * Convert HTML images to Markdown image syntax.
	 *
	 * Uses a two-pass attribute extraction to handle any attribute order
	 * (alt before src, multiple classes, etc.) instead of positional regex.
	 *
	 * @param string $html HTML content.
	 * @return string Content with Markdown images.
	 */
	private function convert_images( string $html ): string {
		return preg_replace_callback(
			'/<img\s[^>]*>/is',
			function ( $matches ) {
				$tag = $matches[0];
				$src = $this->extract_attribute( $tag, 'src' );
				if ( empty( $src ) ) {
					return '';
				}
				$alt = $this->extract_attribute( $tag, 'alt' );
				return '![' . ( '' !== $alt ? $alt : 'image' ) . '](' . $this->sanitize_url( $src ) . ')';
			},
			$html
		);
	}

	/**
	 * Extract an attribute value from an HTML tag string.
	 *
	 * Uses two alternating patterns to handle both double and single quoted values
	 * correctly, including mixed-quote edge cases.
	 *
	 * @param string $tag  Full HTML tag string.
	 * @param string $attr Attribute name to extract.
	 * @return string Attribute value or empty string.
	 */
	private function extract_attribute( string $tag, string $attr ): string {
		// Try double-quoted value first.
		if ( preg_match( '/' . preg_quote( $attr, '/' ) . '="([^"]*)"/', $tag, $m ) ) {
			return $m[1];
		}
		// Then single-quoted value.
		if ( preg_match( '/' . preg_quote( $attr, '/' ) . '=\'([^\']*)\'/', $tag, $m ) ) {
			return $m[1];
		}
		return '';
	}

	/**
	 * Convert HTML links to Markdown link syntax.
	 *
	 * @param string $html HTML content.
	 * @return string Content with Markdown links.
	 */
	private function convert_links( string $html ): string {
		return preg_replace_callback(
			'/<a[^>]*href=["\']([^"\']*)["\'][^>]*>(.*?)<\/a>/is',
			function ( $matches ) {
				$url        = $this->sanitize_url( $matches[1] );
				$inner_html = $matches[2];

				// If the link contains only an <img> tag, convert to an inline image
				// inside a link: [![alt](src)](url) format.
				if ( preg_match( '/<img\s[^>]*>/is', $inner_html ) ) {
					// Extract src and alt from the first <img> using our extract_attribute helper.
					$img_tag = preg_match( '/<img\s[^>]*>/is', $inner_html, $img_match ) ? $img_match[0] : '';
					$img_src = $this->extract_attribute( $img_tag, 'src' );
					$img_alt = $this->extract_attribute( $img_tag, 'alt' );
					if ( empty( $img_src ) ) {
						return '[' . $url . '](' . $url . ')';
					}
					$img_markdown = '![' . ( '' !== $img_alt ? $img_alt : 'image' ) . '](' . $this->sanitize_url( $img_src ) . ')';
					$url_encoded = str_replace( array( '(', ')' ), array( '%28', '%29' ), $url );
					return '[' . $img_markdown . '](' . $url_encoded . ')';
				}

				$text = wp_strip_all_tags( $inner_html );
				$text = trim( preg_replace( '/\s+/', ' ', $text ) );
				if ( empty( $text ) ) {
					$text = $url;
				}
				// Encode literal parentheses in URLs to prevent Markdown link breakage
				// (e.g., Wikipedia URLs like C_(programming_language)).
				$url = str_replace( array( '(', ')' ), array( '%28', '%29' ), $url );
				return '[' . $text . '](' . $url . ')';
			},
			$html
		);
	}

	/**
	 * Convert HTML formatting (bold, italic, strikethrough) to Markdown.
	 *
	 * @param string $html HTML content.
	 * @return string Content with Markdown formatting.
	 */
	private function convert_formatting( string $html ): string {
		// Process innermost tags FIRST (strong>em, b>i, etc.) so that nested
		// inline elements like <strong><em>text</em></strong> are fully collapsed
		// before the outer tag is processed, preventing partial matches.
		// Use (.*?) with /s flag for non-greedy matching across nested tags.
		// Handle strong>em and b>i combinations first.
		$html = preg_replace( '/<(strong|b)><(em|i)>(.*?)<\/\2><\/\1>/is', '***$3***', $html ) ?? $html;
		$html = preg_replace( '/<(em|i)><(strong|b)>(.*?)<\/\2><\/\1>/is', '***$3***', $html ) ?? $html;
		// Now handle simple (non-nested) formatting.
		$html = preg_replace( '/<(strong|b)>(.*?)<\/\1>/is', '**$2**', $html ) ?? $html;
		$html = preg_replace( '/<(em|i)>(.*?)<\/\1>/is', '*$2*', $html ) ?? $html;
		$html = preg_replace( '/<(s|strike|del)>(.*?)<\/\1>/is', '~~$2~~', $html ) ?? $html;
		return $html;
	}

	/**
	 * Convert HTML lists to Markdown list syntax.
	 *
	 * @param string $html HTML content.
	 * @return string Content with Markdown lists.
	 */
	private function convert_lists( string $html ): string {
		// Use DOMDocument for safe list parsing instead of regex to avoid
		// catastrophic backtracking (ReDoS) on deeply nested or crafted HTML.
		$dom             = new DOMDocument( '1.0', 'UTF-8' );
		$prev_use_errors = libxml_use_internal_errors( true );
		try {
			// NOTE: The @ operator was intentionally removed. Errors are captured via
			// libxml_use_internal_errors(true) above, and libxml_get_errors() can be
			// inspected after loadHTML() to detect and log parse failures without
			// silently swallowing fatal libxml errors that could indicate corruption.
			$dom->loadHTML( '<!DOCTYPE html><html><body>' . $html . '</body></html>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
			$libxml_errors = libxml_get_errors();
			if ( ! empty( $libxml_errors ) ) {
				$this->logger->debug(
					'DOMDocument loadHTML had non-fatal libxml errors, continuing',
					array(
						'error_count' => count( $libxml_errors ),
						'first_error'  => $libxml_errors[0]->message ?? 'unknown',
					)
				);
			}
			libxml_clear_errors();

			$converted = $this->convert_dom_lists( $dom->getElementsByTagName( 'body' )->item( 0 ), $html );
			return $converted;
		} catch ( \Throwable $e ) {
			$this->logger->warning(
				'DOMDocument list conversion failed, falling back to regex',
				array( 'error' => $e->getMessage() )
			);
		} finally {
			libxml_use_internal_errors( $prev_use_errors );
		}

		// Fallback: bounded regex for environments where DOMDocument is unavailable.
		$max_iterations = 5000;
		$iteration      = 0;

		while ( preg_match( '/<(ul|ol)[^>]*>(.*?)<\/\1>/is', $html, $matches, PREG_OFFSET_CAPTURE ) && $iteration < $max_iterations ) {
			$list_type    = $matches[1][0];
			$list_content = $matches[2][0];

			$converted = $this->convert_list_items( $list_content, $list_type );

			$html = substr_replace( $html, $converted, $matches[0][1], strlen( $matches[0][0] ) );
			++$iteration;
		}

		if ( $iteration >= $max_iterations ) {
			$this->logger->error(
				'Markdown list conversion hit iteration cap — output is incomplete',
				array(
					'iteration_cap' => $max_iterations,
					'html_excerpt'  => substr( $html, 0, 200 ),
				)
			);
		}

		return $html;
	}

	/**
	 * Convert DOM lists using DOMDocument tree traversal (safe, no ReDoS).
	 *
	 * @param \DOMNode $node     DOM node to process.
	 * @param string   $original Original HTML for fallback.
	 * @return string Markdown content.
	 */
	private function convert_dom_lists( \DOMNode $node, string $original ): string {
		$out = '';
		foreach ( $node->childNodes as $child ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			if ( XML_ELEMENT_NODE !== $child->nodeType ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				continue;
			}
			$tag = strtolower( $child->nodeName ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			if ( 'ul' === $tag || 'ol' === $tag ) {
				$out .= $this->convert_single_list( $child, $tag, 1 );
			} else {
				// Use saveHTML($child) to preserve the outer tag so that subsequent
				// converters (convert_paragraphs, etc.) can still find and process it.
				// get_inner_html() previously stripped the tag, leaving bare text that
				// subsequent regex-based converters would skip.
				$out .= $child->ownerDocument->saveHTML( $child ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			}
		}
		return $out;
	}

	/**
	 * Convert a single DOM list element to Markdown.
	 *
	 * @param \DOMNode $list_node List element node.
	 * @param string   $list_tag  'ul' or 'ol'.
	 * @param int      $depth     Nesting depth (default 1 for top-level, increments for nested).
	 * @return string Markdown list.
	 */
	private function convert_single_list( \DOMNode $list_node, string $list_tag, int $depth = 1 ): string {
		$result  = "\n";
		$counter = 1;
		// 2 spaces per nesting level (matches the convert_list_items regex fallback).
		$indent = str_repeat( '  ', $depth );
		foreach ( $list_node->childNodes as $li ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			if ( XML_ELEMENT_NODE !== $li->nodeType || 'li' !== strtolower( $li->nodeName ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				continue;
			}

			$item_text_parts = array();
			foreach ( $li->childNodes as $child ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				$child_tag = strtolower( $child->nodeName ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				if ( 'ul' === $child_tag || 'ol' === $child_tag ) {
					// Recursively convert nested lists with increased depth.
					$item_text_parts[] = $this->convert_single_list( $child, $child_tag, $depth + 1 );
				} elseif ( XML_ELEMENT_NODE === $child->nodeType ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					// Call convert_dom_lists() instead of wp_strip_all_tags() so that
					// inline formatting (<strong>, <em>, <code>) inside <li> items goes
					// through the full conversion pipeline (convert_formatting,
					// convert_links, convert_code_blocks, etc.) instead of being
					// prematurely stripped. Normalize resulting whitespace afterward.
					$item_text_parts[] = preg_replace(
						'/\s+/',
						' ',
						$this->convert_dom_lists( $child, '' )
					);
				} else {
					$item_text_parts[] = $child->textContent; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				}
			}

			$item_text = trim( preg_replace( '/\s+/', ' ', implode( ' ', $item_text_parts ) ) );
			if ( 'ol' === $list_tag ) {
				$result .= $indent . $counter . '. ' . $item_text . "\n";
				++$counter;
			} else {
				$result .= $indent . '- ' . $item_text . "\n";
			}
		}
		return $result . "\n";
	}
	/* phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase */

	/**
	 * Convert HTML list items to Markdown list items.
	 *
	 * Handles nested lists by recursively processing them with increased depth.
	 *
	 * @param string $content   List item HTML.
	 * @param string $list_type List type ('ul' or 'ol').
	 * @param int    $depth     Nesting depth.
	 * @return string Markdown list items.
	 */
	private function convert_list_items( string $content, string $list_type, int $depth = 0 ): string {
		$indent  = str_repeat( '    ', $depth );
		$counter = 1;

		$result = "\n";

		/*
		 * Process list items. This regex matches each <li>...</li> including any nested lists.
		 * The key improvement is that we DON'T strip tags before processing - we handle
		 * nested lists recursively before stripping.
		 */
		if ( preg_match_all( '/<li>(.*?)<\/li>/is', $content, $matches ) ) {
			foreach ( $matches[1] as $item_content ) {
				/*
				 * Before processing this list item, check if it contains nested lists.
				 * If so, recursively convert them with increased depth.
				 */
				$item_content = $this->convert_nested_lists_in_content( $item_content, $depth + 1 );

				// Now strip remaining HTML tags and normalize whitespace.
				$item_content = wp_strip_all_tags( $item_content );
				$item_content = trim( preg_replace( '/\s+/', ' ', $item_content ) );

				if ( 'ol' === $list_type ) {
					$result .= $indent . $counter . '. ' . $item_content . "\n";
					++$counter;
				} else {
					$result .= $indent . '- ' . $item_content . "\n";
				}
			}
		}

		return $result;
	}

	/**
	 * Recursively convert nested lists within list item content.
	 *
	 * This handles cases like:
	 *   <li>Item 1
	 *     <ul><li>Nested Item</li></ul>
	 *   </li>
	 *
	 * @param string $content HTML content that may contain nested lists.
	 * @param int    $depth   Current nesting depth.
	 * @return string Content with nested lists converted to Markdown.
	 */
	private function convert_nested_lists_in_content( string $content, int $depth ): string {
		$max_nested_iterations = 100;
		$iteration             = 0;

		// Keep processing while there are nested list patterns.
		while ( preg_match( '/<(ul|ol)>(.*?)<\/\1>/is', $content, $matches, PREG_OFFSET_CAPTURE ) && $iteration < $max_nested_iterations ) {
			$nested_list_type    = $matches[1][0];
			$nested_list_content = $matches[2][0];
			$nested_converted    = $this->convert_list_items( $nested_list_content, $nested_list_type, $depth );

			$content = substr_replace( $content, $nested_converted, $matches[0][1], strlen( $matches[0][0] ) );
			++$iteration;
		}

		if ( $iteration >= $max_nested_iterations ) {
			$this->logger->warning(
				'Markdown nested list conversion hit depth cap — nested output may be incomplete',
				array(
					'nested_cap' => $max_nested_iterations,
					'depth'      => $depth,
				)
			);
		}

		return $content;
	}

	/**
	 * Convert HTML code blocks to Markdown code blocks.
	 *
	 * Uses a single combined pass to avoid double-fencing when<pre> has attributes
	 * that prevent the <pre><code> pattern from matching (e.g., Prism.js blocks
	 * with <pre class="..."><code class="language-...">).
	 *
	 * @param string $html HTML content.
	 * @return string Content with Markdown code blocks.
	 */
	private function convert_code_blocks( string $html ): string {
		// Combined single-pass regex: matches<pre> (with optional attributes) containing
		// optional <code> (with optional attributes), captures inner content, and extracts
		// language from class="language-XXX" on either element.
		$html = preg_replace_callback(
			'/<pre([^>]*)>(?:<code[^>]*>)?(.*?)(?:<\/code>)?<\/pre>/is',
			function ( $m ): string {
				$lang = '';
				// Extract language from class="language-XXX" on <pre> or <code>.
				if ( preg_match( '/class=["\'](?:[^"\']*\s)?language-([a-zA-Z0-9_-]+)(?:\s[^"\']*)?["\']/', $m[1] . $m[2], $lang_match ) ) {
					$lang = $lang_match[1];
				}
				$fence = $lang ? '```' . $lang : '```';
				return "\n" . $fence . "\n" . $m[2] . "\n```\n";
			},
			$html
		) ?? $html;

		// Inline code: <code>...</code> (not inside pre).
		$html = preg_replace( '/<code>(.*?)<\/code>/is', '`$1`', $html ) ?? $html;
		return $html;
	}

	/**
	 * Convert HTML blockquotes to Markdown blockquote syntax.
	 *
	 * @param string $html HTML content.
	 * @return string Content with Markdown blockquotes.
	 */
	private function convert_blockquotes( string $html ): string {
		return preg_replace_callback(
			'/<blockquote[^>]*>(.*?)<\/blockquote>/is',
			function ( $matches ) {
				$content = wp_strip_all_tags( $matches[1] );
				$lines   = preg_split( '/\r?\n/', trim( $content ) );
				$result  = "\n";
				foreach ( $lines as $line ) {
					$line = trim( $line );
					if ( ! empty( $line ) ) {
						$result .= '> ' . $line . "\n";
					}
				}
				return $result . "\n";
			},
			$html
		) ?? $html;
	}

	/**
	 * Convert HTML paragraphs to Markdown paragraphs.
	 *
	 * @param string $html HTML content.
	 * @return string Content with Markdown paragraphs.
	 */
	private function convert_paragraphs( string $html ): string {
		// No /s flag: paragraph content should not span multiple lines in the HTML
		// at this point (styles/scripts already stripped, other block elements
		// already converted). Using /s here risks matching across </p> boundaries
		// in malformed HTML.
		$html = preg_replace( '/<p[^>]*>(.*?)<\/p>/i', "\n$1\n", $html ) ?? $html;
		$html = preg_replace( '/<br\s*\/?>/i', "\n", $html ) ?? $html;
		return $html;
	}

	/**
	 * Convert HTML horizontal rules to Markdown HR syntax.
	 *
	 * @param string $html HTML content.
	 * @return string Content with Markdown HRs.
	 */
	private function convert_horizontal_rules( string $html ): string {
		return preg_replace( '/<hr\s*\/?>/i', "\n---\n", $html ) ?? $html;
	}

	/**
	 * Preserve <details>/<summary> collapsible sections as raw HTML in Markdown.
	 *
	 * Markdown parsers (including GitHub Flavored Markdown) support raw HTML
	 * within Markdown documents, so we preserve the HTML structure as-is.
	 *
	 * @param string $html HTML content.
	 * @return string HTML with details/summary elements preserved.
	 */
	private function convert_details( string $html ): string {
		// Strip only the 'open' attribute from <details> since Markdown doesn't
		// support the boolean open attribute — the element will render closed by
		// default in most Markdown renderers, which is the safe fallback.
		$html = preg_replace( '/<details([^>]*)open([^>]*)>/i', '<details$1$2>', $html ) ?? $html;
		$html = preg_replace( '/<details(\s[^>]*)?>/i', '<details>', $html ) ?? $html;

		return $html;
	}

	/**
	 * Sanitize a URL for use in Markdown.
	 *
	 * @param string $url URL to sanitize.
	 * @return string Sanitized URL or '#' if invalid.
	 */
	private function sanitize_url( string $url ): string {
		$url = trim( $url );

		if ( empty( $url ) ) {
			return '#';
		}

		// Exclude data URIs — they are too large for Markdown files and should
		// not appear as inline image references.
		if ( str_starts_with( $url, 'data:' ) ) {
			return '';
		}

		if ( str_starts_with( $url, '/' ) ) {
			$absolute_url = esc_url_raw( home_url( $url ) );
			if ( ! empty( $absolute_url ) && str_starts_with( $absolute_url, home_url() ) ) {
				return $absolute_url;
			}
			$this->logger->warning(
				'Sanitized out-of-site relative URL in Markdown export',
				array( 'url' => substr( $url, 0, 100 ) )
			);
			return '#';
		}

		$parsed = wp_parse_url( $url );
		$scheme = isset( $parsed['scheme'] ) ? strtolower( $parsed['scheme'] ) : '';

		$allowed_schemes = array( 'http', 'https', 'mailto', 'tel' );

		if ( ! empty( $scheme ) && ! in_array( $scheme, $allowed_schemes, true ) ) {
			$this->logger->warning(
				'Sanitized disallowed URL scheme in Markdown export',
				array(
					'url'    => substr( $url, 0, 100 ),
					'scheme' => $scheme,
				)
			);
			return '#';
		}

		return $url;
	}

	/**
	 * Escape special Markdown characters in text.
	 *
	 * Only escapes characters that have special meaning in Markdown contexts.
	 * Note: '.' and '-' are NOT escaped globally — '.' only needs escaping before
	 * digits (e.g., "2." for ordered lists) and '-' only at line-start as list
	 * markers; escaping them everywhere produces ugly output like "anti\-pattern".
	 *
	 * @param string $text Text to escape.
	 * @return string Escaped text.
	 */
	private function escape_markdown( string $text ): string {
		$chars = array( '\\', '`', '*', '_', '{', '}', '[', ']', '(', ')', '#', '+', '!', '|' );
		foreach ( $chars as $char ) {
			$text = str_replace( $char, '\\' . $char, $text );
		}
		return $text;
	}

	/**
	 * Escape Markdown special characters at line-start positions in body content.
	 *
	 * After HTML-to-Markdown conversion, body text may contain lines starting with
	 * characters that have Markdown meaning (# heading, - list, > blockquote, |
	 * table). These must be escaped to prevent unintended formatting.
	 *
	 * @param string $text Markdown content.
	 * @return string Content with line-start Markdown chars escaped.
	 */
	private function escape_markdown_body( string $text ): string {
		// NOTE: Heading markers (#) are intentionally NOT escaped at line start,
		// because convert_headings() has already produced valid Markdown headings
		// (e.g. "## Section") by this point. Escaping # would corrupt them.
		$lines = explode( "\n", $text );
		foreach ( $lines as $index => $line ) {
			$trimmed = ltrim( $line );
			if ( '' === $trimmed ) {
				continue;
			}
			$first_char = $trimmed[0];
			// Escape Markdown special chars at line start — but NOT # (headings)
			// since headings have already been converted before this runs.
			if ( in_array( $first_char, array( '-', '*', '>', '|' ), true ) ) {
				$lines[ $index ] = ltrim( substr( $line, 0, -strlen( $trimmed ) ) ) . '\\' . $trimmed;
			}
		}
		return implode( "\n", $lines );
	}

	/**
	 * Escape a string for safe YAML output.
	 *
	 * @param string $text Text to escape.
	 * @return string Escaped text.
	 */
	private function escape_yaml_string( string $text ): string {
		// Strip non-printable control characters (except \t=\x09, \n=\x0A, and \r=\x0D which are handled below).
		$text = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text );
		$text = str_replace( '\\', '\\\\', $text );
		$text = str_replace( '"', '\\"', $text );
		$text = str_replace( "\n", '\\n', $text );
		$text = str_replace( "\t", '\\t', $text );
		$text = str_replace( "\r", '\\r', $text );
		return $text;
	}

	/**
	 * Get the file extension for Markdown files.
	 *
	 * @return string
	 */
	public function get_extension(): string {
		return 'md';
	}

	/**
	 * Get the MIME type for Markdown files.
	 *
	 * @return string
	 */
	public function get_mime_type(): string {
		return 'text/markdown';
	}
}
