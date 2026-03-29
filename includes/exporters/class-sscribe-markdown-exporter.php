<?php
/**
 * Markdown exporter for SScribe.
 *
 * @package SScribe
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once SSCRIBE_PLUGIN_DIR . 'includes/exporters/interface-sscribe-exporter.php';

/**
 * Class SScribe_Markdown_Exporter
 *
 * Exports pages to Markdown format with improved HTML conversion.
 */
class SScribe_Markdown_Exporter implements SScribe_Exporter_Interface {

	/**
	 * List depth counter for nested lists.
	 *
	 * @var int
	 */
	private int $list_depth = 0;

	/**
	 * List type stack (ul/ol).
	 *
	 * @var array
	 */
	private array $list_stack = array();

	/**
	 * Export a single page to Markdown.
	 *
	 * @param array  $page_data  Page data from collector.
	 * @param string $output_dir Output directory.
	 * @param int    $index      Page index.
	 * @param int    $total      Total pages.
	 * @return SScribe_Result
	 */
	public function export( array $page_data, string $output_dir, int $index = 0, int $total = 0 ): SScribe_Result {
		$markdown = $this->generate_markdown( $page_data );

		$filename    = \SScribe_Exporter_Factory::build_filename( $page_data, $index, $total, 'md' );
		$output_path = trailingslashit( $output_dir ) . $filename;

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Output generation in temp dir for export; WP_Filesystem adds unnecessary complexity for simple file writes.
		$result = file_put_contents( $output_path, $markdown );

		if ( false === $result ) {
			return SScribe_Result::failure(
				__( 'Failed to write Markdown file.', 'sscribe-export-site-pages' ),
				array( 'path' => $output_path )
			);
		}

		return SScribe_Result::success(
			array(
				'path' => $output_path,
				'size' => strlen( $markdown ),
			)
		);
	}

	/**
	 * Generate Markdown content with YAML frontmatter.
	 *
	 * @param array $page_data Page data.
	 * @return string
	 */
	private function generate_markdown( array $page_data ): string {
		$md  = $this->generate_frontmatter( $page_data );
		$md .= $this->html_to_markdown( $page_data['content'] ?? '' );

		return $md;
	}

	/**
	 * Generate YAML frontmatter.
	 *
	 * @param array $page_data Page data.
	 * @return string
	 */
	private function generate_frontmatter( array $page_data ): string {
		$title = $page_data['title'] ?? 'Untitled';
		$md    = '# ' . $this->escape_markdown( $title ) . "\n\n";
		$md   .= '> ' . ( $page_data['permalink'] ?? '' ) . "\n\n";

		$md .= "---\n";
		$md .= 'title: "' . $this->escape_yaml_string( $title ) . "\"\n";
		$md .= 'url: ' . ( $page_data['permalink'] ?? '' ) . "\n";
		$md .= 'slug: ' . ( $page_data['slug'] ?? '' ) . "\n";
		$md .= 'author: "' . $this->escape_yaml_string( $page_data['author'] ?? 'Unknown' ) . "\"\n";
		$md .= 'published: ' . ( $page_data['date_published'] ?? '' ) . "\n";
		$md .= 'modified: ' . ( $page_data['date_modified'] ?? '' ) . "\n";
		$md .= 'word_count: ' . ( $page_data['word_count'] ?? 0 ) . "\n";
		$md .= 'reading_time: ' . ( $page_data['reading_time'] ?? 1 ) . " minutes\n";
		$md .= 'language: ' . ( $page_data['language'] ?? 'en' ) . "\n";

		if ( ! empty( $page_data['featured_image_url'] ) ) {
			$md .= 'featured_image: ' . $page_data['featured_image_url'] . "\n";
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
				$md .= 'canonical_url: ' . $seo['canonical_url'] . "\n";
			}
			if ( ! empty( $seo['og_title'] ) ) {
				$md .= 'og_title: "' . $this->escape_yaml_string( $seo['og_title'] ) . "\"\n";
			}
			if ( ! empty( $seo['og_description'] ) ) {
				$md .= 'og_description: "' . $this->escape_yaml_string( $seo['og_description'] ) . "\"\n";
			}
			if ( ! empty( $seo['source'] ) ) {
				$md .= 'seo_source: ' . $seo['source'] . "\n";
			}
		}

		if ( ! empty( $page_data['breadcrumbs'] ) && count( $page_data['breadcrumbs'] ) > 1 ) {
			$breadcrumbs = array_map(
				function ( $crumb ) {
					return $crumb['title'];
				},
				$page_data['breadcrumbs']
			);
			$md         .= "breadcrumbs:\n";
			foreach ( $breadcrumbs as $crumb ) {
				$md .= '  - "' . $this->escape_yaml_string( $crumb ) . "\"\n";
			}
		}

		$md .= "---\n\n";

		return $md;
	}

	/**
	 * Convert HTML to Markdown with improved handling.
	 *
	 * @param string $html HTML content.
	 * @return string
	 */
	private function html_to_markdown( string $html ): string {
		if ( empty( $html ) ) {
			return '';
		}

		$this->list_depth = 0;
		$this->list_stack = array();

		$html = $this->strip_all_styles( $html );

		$md = $html;

		$md = $this->convert_tables( $md );
		$md = $this->convert_headings( $md );
		$md = $this->convert_images( $md );
		$md = $this->convert_links( $md );
		$md = $this->convert_formatting( $md );
		$md = $this->convert_lists( $md );
		$md = $this->convert_code_blocks( $md );
		$md = $this->convert_blockquotes( $md );
		$md = $this->convert_paragraphs( $md );
		$md = $this->convert_horizontal_rules( $md );

		$md = wp_strip_all_tags( $md );

		$md = html_entity_decode( $md, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		$md = preg_replace( '/\n{3,}/', "\n\n", $md );

		$md = preg_replace( '/[ \t]+$/m', '', $md );

		return trim( $md );
	}

	/**
	 * Strip all inline styles and Elementor-specific attributes.
	 *
	 * @param string $html The HTML content.
	 * @return string Cleaned HTML.
	 */
	private function strip_all_styles( string $html ): string {
		$html = preg_replace( '/<style[^>]*>.*?<\/style>/is', '', $html );
		$html = preg_replace( '/<script[^>]*>.*?<\/script>/is', '', $html );
		$html = preg_replace( '/<noscript[^>]*>.*?<\/noscript>/is', '', $html );
		$html = preg_replace( '/<svg[^>]*>.*?<\/svg>/is', '', $html );

		$html = preg_replace( '/\s*style="[^"]*"/i', '', $html );
		$html = preg_replace( "/\s*style='[^']*'/i", '', $html );

		$html = preg_replace( '/\s*class="[^"]*"/i', '', $html );
		$html = preg_replace( "/\s*class='[^']*'/i", '', $html );

		$html = preg_replace( '/\s*data-[a-z-]+="[^"]*"/i', '', $html );
		$html = preg_replace( "/\s*data-[a-z-]+='[^']*'/i", '', $html );

		$html = preg_replace( '/<!--.*?-->/s', '', $html );

		return $html;
	}

	/**
	 * Convert HTML tables to Markdown tables.
	 *
	 * @param string $html HTML content.
	 * @return string
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
							$cells[]      = $cell_content;
						}
					}

					if ( ! empty( $cells ) ) {
						$rows[] = $cells;

						if ( $is_first_row ) {
							$rows[]       = array_fill( 0, count( $cells ), '---' );
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
		);
	}

	/**
	 * Convert HTML headings to Markdown headings.
	 *
	 * @param string $html HTML content.
	 * @return string
	 */
	private function convert_headings( string $html ): string {
		for ( $i = 6; $i >= 1; $i-- ) {
			$html = preg_replace(
				'/<h' . $i . '[^>]*>(.*?)<\/h' . $i . '>/is',
				"\n" . str_repeat( '#', $i ) . ' $1' . "\n",
				$html
			);
		}
		return $html;
	}

	/**
	 * Convert HTML images to Markdown images.
	 *
	 * @param string $html HTML content.
	 * @return string
	 */
	private function convert_images( string $html ): string {
		return preg_replace_callback(
			'/<img[^>]*src=["\']([^"\']*)["\'][^>]*alt=["\']([^"\']*)["\'][^>]*\/?>/is',
			function ( $matches ) {
				$url = $this->sanitize_url( $matches[1] );
				$alt = $matches[2];
				return '![' . $alt . '](' . $url . ')';
			},
			$html
		);
	}

	/**
	 * Convert HTML links to Markdown links.
	 *
	 * @param string $html HTML content.
	 * @return string
	 */
	private function convert_links( string $html ): string {
		return preg_replace_callback(
			'/<a[^>]*href=["\']([^"\']*)["\'][^>]*>(.*?)<\/a>/is',
			function ( $matches ) {
				$url  = $this->sanitize_url( $matches[1] );
				$text = wp_strip_all_tags( $matches[2] );
				$text = trim( preg_replace( '/\s+/', ' ', $text ) );
				if ( empty( $text ) ) {
					$text = $url;
				}
				return '[' . $text . '](' . $url . ')';
			},
			$html
		);
	}

	/**
	 * Convert HTML formatting tags to Markdown.
	 *
	 * @param string $html HTML content.
	 * @return string
	 */
	private function convert_formatting( string $html ): string {
		$html = preg_replace( '/<(strong|b)>(.*?)<\/\1>/is', '**$2**', $html );
		$html = preg_replace( '/<(em|i)>(.*?)<\/\1>/is', '*$2*', $html );
		$html = preg_replace( '/<(s|strike|del)>(.*?)<\/\1>/is', '~~$2~~', $html );
		return $html;
	}

	/**
	 * Convert HTML lists to Markdown lists.
	 *
	 * @param string $html HTML content.
	 * @return string
	 */
	private function convert_lists( string $html ): string {
		$max_iterations = 10;
		$iteration      = 0;

		while ( preg_match( '/<(ul|ol)>(.*?)<\/\1>/is', $html, $matches, PREG_OFFSET_CAPTURE ) && $iteration < $max_iterations ) {
			$list_type    = $matches[1][0];
			$list_content = $matches[2][0];

			$converted = $this->convert_list_items( $list_content, $list_type );

			$html = substr_replace( $html, $converted, $matches[0][1], strlen( $matches[0][0] ) );
			++$iteration;
		}

		$html = preg_replace( '/<li>(.*?)<\/li>/is', '- $1' . "\n", $html );

		return $html;
	}

	/**
	 * Convert list items within a ul/ol.
	 *
	 * @param string $content  List content.
	 * @param string $list_type ul or ol.
	 * @param int    $depth    Nesting depth.
	 * @return string
	 */
	private function convert_list_items( string $content, string $list_type, int $depth = 0 ): string {
		$indent  = str_repeat( '    ', $depth );
		$counter = 1;

		$result = "\n";

		if ( preg_match_all( '/<li>(.*?)<\/li>/is', $content, $matches ) ) {
			foreach ( $matches[1] as $item_content ) {
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
	 * Convert HTML code blocks to Markdown code blocks.
	 *
	 * @param string $html HTML content.
	 * @return string
	 */
	private function convert_code_blocks( string $html ): string {
		$html = preg_replace( '/<pre[^>]*><code[^>]*>(.*?)<\/code><\/pre>/is', "\n```\n$1\n```\n", $html );
		$html = preg_replace( '/<pre[^>]*>(.*?)<\/pre>/is', "\n```\n$1\n```\n", $html );
		$html = preg_replace( '/<code>(.*?)<\/code>/is', '`$1`', $html );
		return $html;
	}

	/**
	 * Convert HTML blockquotes to Markdown blockquotes.
	 *
	 * @param string $html HTML content.
	 * @return string
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
		);
	}

	/**
	 * Convert HTML paragraphs to Markdown.
	 *
	 * @param string $html HTML content.
	 * @return string
	 */
	private function convert_paragraphs( string $html ): string {
		$html = preg_replace( '/<p[^>]*>(.*?)<\/p>/is', "\n$1\n", $html );
		$html = preg_replace( '/<br\s*\/?>/i', "\n", $html );
		return $html;
	}

	/**
	 * Convert HTML horizontal rules to Markdown.
	 *
	 * @param string $html HTML content.
	 * @return string
	 */
	private function convert_horizontal_rules( string $html ): string {
		return preg_replace( '/<hr\s*\/?>/i', "\n---\n", $html );
	}

	/**
	 * Sanitize URL for markdown output.
	 *
	 * @param string $url URL to sanitize.
	 * @return string Sanitized URL.
	 */
	private function sanitize_url( string $url ): string {
		$url = trim( $url );

		if ( empty( $url ) ) {
			return '#';
		}

		$parsed = wp_parse_url( $url );
		$scheme = isset( $parsed['scheme'] ) ? strtolower( $parsed['scheme'] ) : '';

		$allowed_schemes = array( 'http', 'https', 'mailto', 'tel', 'ftp' );

		if ( ! empty( $scheme ) && ! in_array( $scheme, $allowed_schemes, true ) ) {
			return '#';
		}

		return $url;
	}

	/**
	 * Escape special Markdown characters.
	 *
	 * @param string $text Text to escape.
	 * @return string
	 */
	private function escape_markdown( string $text ): string {
		$chars = array( '\\', '`', '*', '_', '{', '}', '[', ']', '(', ')', '#', '+', '-', '.', '!', '|' );
		foreach ( $chars as $char ) {
			$text = str_replace( $char, '\\' . $char, $text );
		}
		return $text;
	}

	/**
	 * Escape string for YAML output.
	 *
	 * @param string $text Text to escape.
	 * @return string
	 */
	private function escape_yaml_string( string $text ): string {
		return str_replace( '"', '\\"', $text );
	}

	/**
	 * Get the file extension.
	 *
	 * @return string
	 */
	public function get_extension(): string {
		return 'md';
	}

	/**
	 * Get the mime type.
	 *
	 * @return string
	 */
	public function get_mime_type(): string {
		return 'text/markdown';
	}
}
