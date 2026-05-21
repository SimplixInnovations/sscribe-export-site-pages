<?php
/**
 * SScribe Markdown Exporter
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once SSCRIBE_PLUGIN_DIR . 'includes/exporters/interface-sscribe-exporter.php';

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
		$this->logger     = $logger ?? SScribe_Logger::instance( defined( 'SSCRIBE_DEBUG' ) && SSCRIBE_DEBUG );
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

		$md = $this->add_bom_if_rtl( $md, $page_data );

		return $md;
	}

	/**
	 * Add BOM prefix for RTL content.
	 *
	 * @param string $content   Markdown content.
	 * @param array  $page_data Page data.
	 * @return string Content with optional BOM.
	 */
	private function add_bom_if_rtl( string $content, array $page_data ): string {
		require_once SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-rtl-helper.php';

		$language = $page_data['language'] ?? 'en';

		if ( SScribe_RTL_Helper::is_rtl( $language ) ) {
			return "\xEF\xBB\xBF" . $content;
		}

		return $content;
	}

	/**
	 * Generate YAML frontmatter from page data.
	 *
	 * @param array $page_data Page data.
	 * @return string YAML frontmatter block.
	 */
	private function generate_frontmatter( array $page_data ): string {
		require_once SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-rtl-helper.php';

		$title     = $page_data['title'] ?? 'Untitled';
		$language  = $page_data['language'] ?? 'en';
		$direction = SScribe_RTL_Helper::get_direction( $language );
		$md        = '# ' . $this->escape_markdown( $title ) . "\n\n";
		$md       .= '> ' . ( $page_data['permalink'] ?? '' ) . "\n\n";

		$md .= "---\n";
		$md .= 'title: "' . $this->escape_yaml_string( $title ) . "\"\n";
		$md .= 'url: ' . ( $page_data['permalink'] ?? '' ) . "\n";
		$md .= 'slug: ' . ( $page_data['slug'] ?? '' ) . "\n";
		$md .= 'author: "' . $this->escape_yaml_string( $page_data['author'] ?? 'Unknown' ) . "\"\n";
		$md .= 'published: ' . ( $page_data['date_published'] ?? '' ) . "\n";
		$md .= 'modified: ' . ( $page_data['date_modified'] ?? '' ) . "\n";
		$md .= 'word_count: ' . ( $page_data['word_count'] ?? 0 ) . "\n";
		$md .= 'reading_time: ' . ( $page_data['reading_time'] ?? 1 ) . " minutes\n";
		$md .= 'language: ' . $language . "\n";
		$md .= 'direction: ' . $direction . "\n";

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
		$md = $this->convert_images_fallback( $md );
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
	 * Strip all style/script/svg elements and attributes.
	 *
	 * @param string $html HTML content.
	 * @return string Cleaned HTML.
	 */
	private function strip_all_styles( string $html ): string {
		$html = preg_replace( '/<style[^>]*>.*?<\/style>/is', '', $html ) ?? $html;
		$html = preg_replace( '/<script[^>]*>.*?<\/script>/is', '', $html ) ?? $html;
		$html = preg_replace( '/<noscript[^>]*>.*?<\/noscript>/is', '', $html ) ?? $html;
		$html = preg_replace( '/<svg[^>]*>.*?<\/svg>/is', '', $html ) ?? $html;

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
	 * @return string Content with Markdown headings.
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
	 * Convert HTML images to Markdown image syntax.
	 *
	 * @param string $html HTML content.
	 * @return string Content with Markdown images.
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
	 * Fallback image conversion for images without alt text.
	 *
	 * @param string $html HTML content.
	 * @return string Content with Markdown images.
	 */
	private function convert_images_fallback( string $html ): string {
		return preg_replace_callback(
			'/<img[^>]*src=["\']([^"\']*)["\'][^>]*>/is',
			function ( $matches ) {
				$url = $this->sanitize_url( $matches[1] );
				return '![image](' . $url . ')';
			},
			$html
		);
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
	 * Convert HTML formatting (bold, italic, strikethrough) to Markdown.
	 *
	 * @param string $html HTML content.
	 * @return string Content with Markdown formatting.
	 */
	private function convert_formatting( string $html ): string {
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

		$max_iterations = 500;
		$iteration      = 0;

		while ( preg_match( '/<(ul|ol)>(.*?)<\/\1>/is', $html, $matches, PREG_OFFSET_CAPTURE ) && $iteration < $max_iterations ) {
			$list_type    = $matches[1][0];
			$list_content = $matches[2][0];

			$converted = $this->convert_list_items( $list_content, $list_type );

			$html = substr_replace( $html, $converted, $matches[0][1], strlen( $matches[0][0] ) );
			++$iteration;
		}

		$html = preg_replace( '/<li>(.*?)<\/li>/is', '- $1' . "\n", $html ) ?? $html;

		return $html;
	}

	/**
	 * Convert HTML list items to Markdown list items.
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
	 * @return string Content with Markdown code blocks.
	 */
	private function convert_code_blocks( string $html ): string {
		$html = preg_replace( '/<pre[^>]*><code[^>]*>(.*?)<\/code><\/pre>/is', "\n```\n$1\n```\n", $html ) ?? $html;
		$html = preg_replace( '/<pre[^>]*>(.*?)<\/pre>/is', "\n```\n$1\n```\n", $html ) ?? $html;
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
		);
	}

	/**
	 * Convert HTML paragraphs to Markdown paragraphs.
	 *
	 * @param string $html HTML content.
	 * @return string Content with Markdown paragraphs.
	 */
	private function convert_paragraphs( string $html ): string {
		$html = preg_replace( '/<p[^>]*>(.*?)<\/p>/is', "\n$1\n", $html ) ?? $html;
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

		$parsed = wp_parse_url( $url );
		$scheme = isset( $parsed['scheme'] ) ? strtolower( $parsed['scheme'] ) : '';

		$allowed_schemes = array( 'http', 'https', 'mailto', 'tel' );

		if ( ! empty( $scheme ) && ! in_array( $scheme, $allowed_schemes, true ) ) {
			return '#';
		}

		return $url;
	}

	/**
	 * Escape special Markdown characters in text.
	 *
	 * @param string $text Text to escape.
	 * @return string Escaped text.
	 */
	private function escape_markdown( string $text ): string {
		$chars = array( '\\', '`', '*', '_', '{', '}', '[', ']', '(', ')', '#', '+', '-', '.', '!', '|' );
		foreach ( $chars as $char ) {
			$text = str_replace( $char, '\\' . $char, $text );
		}
		return $text;
	}

	/**
	 * Escape a string for safe YAML output.
	 *
	 * @param string $text Text to escape.
	 * @return string Escaped text.
	 */
	private function escape_yaml_string( string $text ): string {
		$text = str_replace( '\\', '\\\\', $text );
		$text = str_replace( '"', '\\"', $text );
		$text = str_replace( "\n", '\\n', $text );
		$text = str_replace( "\t", '\\t', $text );
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
