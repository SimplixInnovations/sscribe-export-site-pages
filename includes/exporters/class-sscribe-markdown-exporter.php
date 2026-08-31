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
	 * Per-export format options set by the batch processor.
	 *
	 * Keys are format-prefixed option names (e.g. `sscribe_md_include_frontmatter`).
	 * Populated via apply_format_options() before export() is called.
	 *
	 * @var array<string, mixed>
	 */
	private array $format_options = array();

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
	 * Apply per-format options to this exporter instance.
	 *
	 * The batch processor calls this after running the
	 * `sscribe_export_options_markdown` filter and before export().
	 *
	 * @param array<string, mixed> $options Sanitized options map.
	 * @return void
	 */
	public function apply_format_options( array $options ): void {
		$this->format_options = $options;
	}

	/**
	 * Read a format option with a default. Treats checkbox values as
	 * strings ("1" / ""), so callers should compare to "1".
	 *
	 * @param string $key     Option key.
	 * @param mixed  $default Default when key is absent.
	 * @return mixed
	 */
	private function get_format_option( string $key, $default = null ) {
		return array_key_exists( $key, $this->format_options ) ? $this->format_options[ $key ] : $default;
	}

	/**
	 * Normalize filtered metadata without passing arrays or objects into string APIs.
	 *
	 * @param mixed  $value   Candidate value.
	 * @param string $default Fallback value.
	 * @return string
	 */
	private function normalize_scalar( $value, string $default = '' ): string {
		if ( ! is_scalar( $value ) ) {
			return $default;
		}

		$value = trim( (string) $value );
		return '' !== $value ? $value : $default;
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
		$page_id = isset( $page_data['id'] ) && is_numeric( $page_data['id'] ) ? absint( $page_data['id'] ) : 0;
		$title   = $this->normalize_scalar( $page_data['title'] ?? '', __( 'Untitled', 'sscribe-export-site-pages' ) );

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
					array( 'page_id' => $page_id )
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
					'error'   => $e->getMessage(),
					'file'    => $e->getFile(),
					'line'    => $e->getLine(),
				)
			);

			return SScribe_Result::failure(
				__( 'Unable to generate the Markdown file. Please try again.', 'sscribe-export-site-pages' ),
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
		$include_frontmatter = '1' === (string) $this->get_format_option( 'sscribe_md_include_frontmatter', '1' );

		$md  = $include_frontmatter ? $this->generate_frontmatter( $page_data ) : '';
		$md .= $this->html_to_markdown( $this->normalize_scalar( $page_data['content'] ?? '' ) );

		return $md;
	}

	/**
	 * Generate YAML frontmatter from page data.
	 *
	 * @param array $page_data Page data.
	 * @return string YAML frontmatter block.
	 */
	private function generate_frontmatter( array $page_data ): string {
		$title     = $this->normalize_scalar( $page_data['title'] ?? '', __( 'Untitled', 'sscribe-export-site-pages' ) );
		$language  = $this->normalize_scalar( $page_data['language'] ?? '', 'en' );
		$language  = 1 === preg_match( '/^[A-Za-z]{2,8}(?:[-_][A-Za-z0-9]{1,8})*$/', $language ) ? $language : 'en';
		$direction = SScribe_RTL_Helper::get_direction( $language );

		$include_featured_image = '1' === (string) $this->get_format_option( 'sscribe_md_include_featured_image', '1' );

		$md  = "---\n";
		$md .= 'title: "' . $this->escape_yaml_string( $title ) . "\"\n";
		$permalink = $this->sanitize_url( $this->normalize_scalar( $page_data['permalink'] ?? '' ) );
		$md       .= 'url: "' . $this->escape_yaml_string( '#' !== $permalink ? $permalink : '' ) . "\"\n";
		$md       .= 'slug: "' . $this->escape_yaml_string( $this->normalize_scalar( $page_data['slug'] ?? '' ) ) . "\"\n";
		$md       .= 'author: "' . $this->escape_yaml_string( $this->normalize_scalar( $page_data['author'] ?? '', __( 'Unknown', 'sscribe-export-site-pages' ) ) ) . "\"\n";
		$md       .= 'published: "' . $this->escape_yaml_string( $this->normalize_scalar( $page_data['date_published'] ?? '' ) ) . "\"\n";
		$md       .= 'modified: "' . $this->escape_yaml_string( $this->normalize_scalar( $page_data['date_modified'] ?? '' ) ) . "\"\n";
		$md       .= 'word_count: ' . ( isset( $page_data['word_count'] ) && is_numeric( $page_data['word_count'] ) ? max( 0, (int) $page_data['word_count'] ) : 0 ) . "\n";
		$md       .= 'reading_time: ' . ( isset( $page_data['reading_time'] ) && is_numeric( $page_data['reading_time'] ) ? max( 1, (int) $page_data['reading_time'] ) : 1 ) . "\n";
		$md .= 'language: "' . $this->escape_yaml_string( $language ) . "\"\n";
		$md .= 'direction: "' . $this->escape_yaml_string( $direction ) . "\"\n";

		$featured_image = $this->sanitize_url( $this->normalize_scalar( $page_data['featured_image_url'] ?? '' ) );
		if ( $include_featured_image && '#' !== $featured_image && '' !== $featured_image ) {
			$md .= 'featured_image: "' . $this->escape_yaml_string( $featured_image ) . "\"\n";
		}

		$excerpt = $this->normalize_scalar( $page_data['excerpt'] ?? '' );
		if ( '' !== $excerpt ) {
			$md .= 'excerpt: "' . $this->escape_yaml_string( $excerpt ) . "\"\n";
		}

		if ( ! empty( $page_data['seo'] ) && is_array( $page_data['seo'] ) ) {
			$seo = $page_data['seo'];
			$seo_fields = array(
				'meta_title'       => 'seo_title',
				'meta_description' => 'seo_description',
				'focus_keyword'    => 'seo_focus_keyword',
				'og_title'         => 'og_title',
				'og_description'   => 'og_description',
				'source'           => 'seo_source',
			);
			foreach ( $seo_fields as $source_key => $output_key ) {
				$value = $this->normalize_scalar( $seo[ $source_key ] ?? '' );
				if ( '' !== $value ) {
					$md .= $output_key . ': "' . $this->escape_yaml_string( $value ) . "\"\n";
				}
			}
			$canonical_url = $this->sanitize_url( $this->normalize_scalar( $seo['canonical_url'] ?? '' ) );
			if ( '#' !== $canonical_url && '' !== $canonical_url ) {
				$md .= 'canonical_url: "' . $this->escape_yaml_string( $canonical_url ) . "\"\n";
			}
		}

		if ( ! empty( $page_data['breadcrumbs'] ) && is_array( $page_data['breadcrumbs'] ) && count( $page_data['breadcrumbs'] ) > 1 ) {
			$md .= "breadcrumbs:\n";
			foreach ( $page_data['breadcrumbs'] as $crumb ) {
				if ( ! is_array( $crumb ) ) {
					continue;
				}
				$md       .= '  - title: "' . $this->escape_yaml_string( $this->normalize_scalar( $crumb['title'] ?? '' ) ) . "\"\n";
				$crumb_url = $this->sanitize_url( $this->normalize_scalar( $crumb['url'] ?? '' ) );
				if ( '#' !== $crumb_url && '' !== $crumb_url ) {
					$md .= '    url: "' . $this->escape_yaml_string( $crumb_url ) . "\"\n";
				}
			}
		}

		$md .= "---\n\n";

		$md .= '# ' . $this->escape_markdown( $title ) . "\n\n";
		if ( '#' !== $permalink && '' !== $permalink ) {
			$md .= '> ' . $permalink . "\n\n";
		}

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

		$html = $this->strip_shortcodes( $html );

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

		$md = html_entity_decode( $md, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$md = wp_strip_all_tags( $md );

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

		$html = preg_replace_callback(
			'/<svg[^>]*>.*?<\/svg>/is',
			function ( $matches ) {

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
	 * Strip unexpanded shortcodes from content.
	 *
	 * WordPress shortcodes that survive in the post_content when
	 * `the_content` filter is bypassed (page builder content, custom
	 * plugin shortcodes, [caption]/[gallery]/[embed] from the media
	 * library) would otherwise leak into the exported Markdown as raw
	 * `[shortcode]...[/shortcode]` syntax. This pass removes both
	 * self-closing and paired shortcodes.
	 *
	 * Uses WordPress's strip_shortcodes() if available (it relies on
	 * the registered shortcode tag list), and additionally applies a
	 * regex fallback to catch unregistered tags.
	 *
	 * @param string $html HTML content.
	 * @return string Content with shortcodes removed.
	 */
	private function strip_shortcodes( string $html ): string {
		if ( function_exists( 'strip_shortcodes' ) ) {
			$html = strip_shortcodes( $html );
		}

		// Unregistered tags only: require either a matching closing tag or a
		// self-closing marker. A bare "[word]" in prose must survive.
		$html = preg_replace( '/\[([a-zA-Z_][a-zA-Z0-9_-]*)(?:\s+[^\]]*)?\/+\]\s*/', '', $html ) ?? $html;
		$html = preg_replace( '/\[([a-zA-Z_][a-zA-Z0-9_-]*)(?:\s+[^\]]*)?\][\s\S]*?\[\/\1\]/s', '', $html ) ?? $html;

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

				if ( preg_match( '/<(td|th)\b[^>]*\b(colspan|rowspan)\s*=\s*["\']?[2-9]/is', $table_html ) ) {
					$plain = trim( wp_strip_all_tags( $table_html ) );
					$plain = preg_replace( '/\s+/', ' ', $plain );
					return "\n<!-- SScribe: HTML table with merged cells preserved as text (GFM has no colspan/rowspan support) -->\n"
						. $plain . "\n\n";
				}

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

							$cell_content = str_replace( '|', '\\|', $cell_content );
							$cells[]      = $cell_content;
						}
					}

					if ( ! empty( $cells ) ) {
						$rows[] = $cells;

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

		return preg_replace_callback(
			'/<h([1-6])[^>]*>(.*?)<\/h\1>/is',
			static function ( $m ): string {

				$level = (int) $m[1];
				$inner = wp_strip_all_tags( $m[2] );
				return "\n" . str_repeat( '#', $level ) . ' ' . $inner . "\n";
			},
			$html
		) ?? $html;
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
		$converted = preg_replace_callback(
			'/<img\s[^>]*>/is',
			function ( $matches ) {
				$tag = $matches[0];
				$src = $this->extract_attribute( $tag, 'src' );
				if ( empty( $src ) ) {
					return '';
				}
				$url = $this->sanitize_url( $src );
				if ( '' === $url || '#' === $url ) {
					return '';
				}
				$alt = $this->extract_attribute( $tag, 'alt' );
				$alt = str_replace( array( '[', ']', "\r", "\n" ), array( '\\[', '\\]', ' ', ' ' ), $alt );
				return '![' . ( '' !== $alt ? $alt : 'image' ) . '](' . $url . ')';
			},
			$html
		);

		return is_string( $converted ) ? $converted : $html;
	}

	/**
	 * Extract an attribute value from an HTML tag string.
	 *
	 * Uses three alternating patterns to handle double-quoted, single-quoted,
	 * and unquoted values (legal in HTML5 for attributes without spaces, quotes,
	 * or = signs in the value). Without unquoted-attribute support, optimized
	 * HTML produced by some page builders loses its images during Markdown export.
	 *
	 * @param string $tag  Full HTML tag string.
	 * @param string $attr Attribute name to extract.
	 * @return string Attribute value or empty string.
	 */
	private function extract_attribute( string $tag, string $attr ): string {

		if ( preg_match( '/' . preg_quote( $attr, '/' ) . '="([^"]*)"/', $tag, $m ) ) {
			return $m[1];
		}

		if ( preg_match( '/' . preg_quote( $attr, '/' ) . '=\'([^\']*)\'/', $tag, $m ) ) {
			return $m[1];
		}

		if ( preg_match( '/' . preg_quote( $attr, '/' ) . '=([^\s"\'=`<>]+)/', $tag, $m ) ) {
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
		$result = preg_replace_callback(
			'/<a[^>]*href=["\']([^"\']*)["\'][^>]*>(.*?)<\/a>/is',
			function ( $matches ) {
				$url        = $this->sanitize_url( $matches[1] );
				$inner_html = $matches[2];

				if ( preg_match( '/<img\s[^>]*>/is', $inner_html ) ) {

					$img_tag = preg_match( '/<img\s[^>]*>/is', $inner_html, $img_match ) ? $img_match[0] : '';
					$img_src = $this->extract_attribute( $img_tag, 'src' );
					$img_alt = $this->extract_attribute( $img_tag, 'alt' );
					if ( empty( $img_src ) ) {
						return '[' . $url . '](' . $url . ')';
					}
					$img_markdown = '![' . ( '' !== $img_alt ? $img_alt : 'image' ) . '](' . $this->sanitize_url( $img_src ) . ')';
					$url_encoded  = str_replace( array( '(', ')' ), array( '%28', '%29' ), $url );
					return '[' . $img_markdown . '](' . $url_encoded . ')';
				}

				$text = wp_strip_all_tags( $inner_html );
				$text = trim( (string) preg_replace( '/\s+/', ' ', $text ) );
				if ( empty( $text ) ) {
					$text = $url;
				}

				$url = str_replace( array( '(', ')' ), array( '%28', '%29' ), $url );
				return '[' . $text . '](' . $url . ')';
			},
			$html
		);

		return is_string( $result ) ? $result : $html;
	}

	/**
	 * Convert HTML formatting (bold, italic, strikethrough) to Markdown.
	 *
	 * @param string $html HTML content.
	 * @return string Content with Markdown formatting.
	 */
	private function convert_formatting( string $html ): string {

		$html = preg_replace( '/<(strong|b)><(em|i)>(.*?)<\/\2><\/\1>/is', '***$3***', $html ) ?? $html;
		$html = preg_replace( '/<(em|i)><(strong|b)>(.*?)<\/\2><\/\1>/is', '***$3***', $html ) ?? $html;

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

		$dom             = new DOMDocument( '1.0', 'UTF-8' );
		$prev_use_errors = libxml_use_internal_errors( true );
		try {

			$wrapped = '<!DOCTYPE html><html><head>'
				. '<meta http-equiv="Content-Type" content="text/html; charset=UTF-8">'
				. '</head><body>' . $html . '</body></html>';
			$dom->loadHTML(
				$wrapped,
				LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET
			);
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

			$body = $dom->getElementsByTagName( 'body' )->item( 0 );
			if ( ! $body instanceof \DOMNode ) {
				return $html;
			}
			$converted = $this->convert_dom_lists( $body );
			return $converted;
		} catch ( \Throwable $e ) {
			$this->logger->warning(
				'DOMDocument list conversion failed, falling back to regex',
				array( 'error' => $e->getMessage() )
			);
		} finally {
			libxml_use_internal_errors( $prev_use_errors );
		}

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
				'Markdown list conversion hit iteration cap : output is incomplete',
				array(
					'iteration_cap' => $max_iterations,
				)
			);
		}

		return $html;
	}

	/**
	 * Convert DOM lists using DOMDocument tree traversal (safe, no ReDoS).
	 *
	 * @param \DOMNode $node DOM node whose contents should be converted.
	 * @return string Markdown content.
	 */
	private function convert_dom_lists( \DOMNode $node ): string {
		$this->replace_dom_list_nodes( $node );

		$out = '';
		foreach ( $node->childNodes as $child ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$document = $child->ownerDocument; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$out     .= $document instanceof \DOMDocument ? (string) $document->saveHTML( $child ) : $child->textContent; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		}
		return $out;
	}

	/**
	 * Replace list elements anywhere in a DOM subtree with Markdown text nodes.
	 *
	 * @param \DOMNode $node DOM subtree root.
	 */
	private function replace_dom_list_nodes( \DOMNode $node ): void {
		$children = iterator_to_array( $node->childNodes ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		foreach ( $children as $child ) {
			if ( XML_ELEMENT_NODE !== $child->nodeType ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				continue;
			}

			$tag = strtolower( $child->nodeName ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			if ( 'ul' === $tag || 'ol' === $tag ) {
				$document = $child->ownerDocument; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				if ( $document instanceof \DOMDocument ) {
					$replacement = $document->createTextNode( $this->convert_single_list( $child, $tag ) );
					$node->replaceChild( $replacement, $child );
				}
				continue;
			}

			$this->replace_dom_list_nodes( $child );
		}
	}

	/**
	 * Convert a single DOM list element to Markdown.
	 *
	 * @param \DOMNode $list_node List element node.
	 * @param string   $list_tag  'ul' or 'ol'.
	 * @param int      $depth     Nesting depth (default 1 for top-level, increments for nested).
	 * @return string Markdown list.
	 */
	private function convert_single_list( \DOMNode $list_node, string $list_tag, int $depth = 0 ): string {
		$result  = "\n";
		$counter = 1;

		$indent = str_repeat( '  ', $depth );
		foreach ( $list_node->childNodes as $li ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			if ( XML_ELEMENT_NODE !== $li->nodeType || 'li' !== strtolower( $li->nodeName ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				continue;
			}

			$item_text_parts = array();
			$nested_lists    = '';
			foreach ( $li->childNodes as $child ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				$child_tag = strtolower( $child->nodeName ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				if ( 'ul' === $child_tag || 'ol' === $child_tag ) {
					$nested_lists .= $this->convert_single_list( $child, $child_tag, $depth + 1 );
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
			$result .= $nested_lists;
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

		if ( preg_match_all( '/<li[^>]*>(.*?)<\/li>/is', $content, $matches ) ) {
			foreach ( $matches[1] as $item_content ) {

				$item_content = $this->convert_nested_lists_in_content( $item_content, $depth + 1 );

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

		while ( preg_match( '/<(ul|ol)[^>]*>(.*?)<\/\1>/is', $content, $matches, PREG_OFFSET_CAPTURE ) && $iteration < $max_nested_iterations ) {
			$nested_list_type    = $matches[1][0];
			$nested_list_content = $matches[2][0];
			$nested_converted    = $this->convert_list_items( $nested_list_content, $nested_list_type, $depth );

			$content = substr_replace( $content, $nested_converted, $matches[0][1], strlen( $matches[0][0] ) );
			++$iteration;
		}

		if ( $iteration >= $max_nested_iterations ) {
			$this->logger->warning(
				'Markdown nested list conversion hit depth cap : nested output may be incomplete',
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

		$html = preg_replace_callback(
			'/<pre([^>]*)>(?:<code[^>]*>)?(.*?)(?:<\/code>)?<\/pre>/is',
			function ( $m ): string {
				$lang = '';

				if ( preg_match( '/class=["\'](?:[^"\']*\s)?language-([a-zA-Z0-9_-]+)(?:\s[^"\']*)?["\']/', $m[1] . $m[2], $lang_match ) ) {
					$lang = $lang_match[1];
				}
				$max_tick_run = 0;
				if ( preg_match_all( '/`+/', $m[2], $tick_runs ) ) {
					foreach ( $tick_runs[0] as $tick_run ) {
						$max_tick_run = max( $max_tick_run, strlen( $tick_run ) );
					}
				}
				$fence = str_repeat( '`', max( 3, $max_tick_run + 1 ) );
				return "\n" . $fence . $lang . "\n" . $m[2] . "\n" . $fence . "\n";
			},
			$html
		) ?? $html;

		$html = preg_replace_callback(
			'/<code[^>]*>(.*?)<\/code>/is',
			static function ( array $matches ): string {
				$content      = $matches[1];
				$max_tick_run = 0;
				if ( preg_match_all( '/`+/', $content, $tick_runs ) ) {
					foreach ( $tick_runs[0] as $tick_run ) {
						$max_tick_run = max( $max_tick_run, strlen( $tick_run ) );
					}
				}
				$delimiter = str_repeat( '`', max( 1, $max_tick_run + 1 ) );
				$padding   = $max_tick_run > 0 || str_starts_with( $content, ' ' ) || str_ends_with( $content, ' ' ) ? ' ' : '';
				return $delimiter . $padding . $content . $padding . $delimiter;
			},
			$html
		) ?? $html;
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
				$content   = wp_strip_all_tags( $matches[1] );
				$lines_raw = preg_split( '/\r?\n/', trim( $content ) );
				$lines     = is_array( $lines_raw ) ? $lines_raw : array();
				$result    = "\n";
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
	 * Convert details/summary sections to ordinary Markdown text.
	 *
	 * @param string $html HTML content.
	 * @return string Markdown-compatible content.
	 */
	private function convert_details( string $html ): string {

		$html = preg_replace(
			'/<details[^>]*>\s*<summary[^>]*>(.*?)<\/summary>(.*?)<\/details>/is',
			"\n**$1**\n\n$2\n",
			$html
		) ?? $html;
		$html = preg_replace( '/<\/?(?:details|summary)[^>]*>/i', "\n", $html ) ?? $html;

		return $html;
	}

	/**
	 * Sanitize a URL for use in Markdown.
	 *
	 * @param string $url URL to sanitize.
	 * @return string Sanitized URL or '#' if invalid.
	 */
	private function sanitize_url( string $url ): string {
		$url = html_entity_decode( trim( $url ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$url = preg_replace( '/[\x00-\x20\x7F]+/u', '', $url ) ?? '';

		if ( empty( $url ) ) {
			return '#';
		}

		if ( str_starts_with( strtolower( $url ), 'data:' ) ) {
			return '';
		}

		if ( str_starts_with( $url, '#' ) ) {
			return 1 === preg_match( '/^#[A-Za-z0-9_.:-]*$/', $url ) ? $url : '#';
		}

		if ( str_starts_with( $url, '/' ) ) {
			if ( str_starts_with( $url, '//' ) ) {
				return '#';
			}

			$use_absolute = '1' === (string) $this->get_format_option( 'sscribe_md_absolute_urls', '1' );
			if ( ! $use_absolute ) {
				return $this->escape_markdown_url( $url );
			}

			$absolute_url = esc_url_raw( home_url( $url ) );
			if ( ! empty( $absolute_url ) && str_starts_with( $absolute_url, home_url() ) ) {
				return $this->escape_markdown_url( $absolute_url );
			}
			$this->logger->warning(
				'Sanitized out-of-site relative URL in Markdown export',
				array( 'url' => substr( $url, 0, 100 ) )
			);
			return '#';
		}

		$parsed = wp_parse_url( $url );
		if ( false === $parsed ) {
			return '#';
		}
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

		$sanitized = esc_url_raw( $url, $allowed_schemes );
		if ( '' === $sanitized ) {
			return '#';
		}

		return $this->escape_markdown_url( $sanitized );
	}

	/**
	 * Escape characters that can terminate a Markdown link destination.
	 *
	 * @param string $url Sanitized URL.
	 * @return string
	 */
	private function escape_markdown_url( string $url ): string {
		return str_replace(
			array( '(', ')', '<', '>', '\\' ),
			array( '%28', '%29', '%3C', '%3E', '%5C' ),
			$url
		);
	}

	/**
	 * Escape special Markdown characters in text.
	 *
	 * Only escapes characters that have special meaning in Markdown contexts.
	 * Note: '.' and '-' are NOT escaped globally : '.' only needs escaping before
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
	 * Escape a string for safe YAML output.
	 *
	 * @param string $text Text to escape.
	 * @return string Escaped text.
	 */
	private function escape_yaml_string( string $text ): string {

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
