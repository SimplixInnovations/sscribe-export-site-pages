<?php
/**
 * SScribe HTML Exporter
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
 * Exports pages as standalone HTML documents.
 */
class SScribe_HTML_Exporter implements SScribe_Exporter_Interface {

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
	 * Keys are format-prefixed option names (e.g. `sscribe_html_include_css`).
	 * Populated via apply_format_options() before export() is called.
	 *
	 * @var array<string, mixed>
	 */
	private array $format_options = array();

	/**
	 * Initialize the HTML exporter.
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
	 * `sscribe_export_options_html` filter and before export().
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
	 * Normalize filtered metadata to a bounded scalar string.
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
	 * Generate HTML string for a page without writing to disk.
	 *
	 * Used by the PDF exporter to obtain the HTML content for TCPDF rendering
	 * without creating a .html side-effect file.
	 *
	 * @param array $page_data Page data.
	 * @return string HTML document string.
	 */
	public function generate_html_string( array $page_data ): string {
		return $this->generate_html( $page_data );
	}

	/**
	 * Export a page as an HTML file.
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
			$html = $this->generate_html( $page_data );

			$filename    = \SScribe_Exporter_Factory::build_filename( $page_data, $index, $total, 'html' );
			$output_path = trailingslashit( $output_dir ) . $filename;

			$result = $this->filesystem->put_contents( $output_path, $html );

			if ( ! $result ) {
				$this->logger->error(
					'HTML export failed: filesystem write error',
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
						__( 'Failed to write HTML file for "%s".', 'sscribe-export-site-pages' ),
						$title
					),
					array( 'page_id' => $page_id )
				);
			}

			return SScribe_Result::success(
				array(
					'path' => $output_path,
					'html' => $html,
					'size' => strlen( $html ),
				)
			);

		} catch ( \Throwable $e ) {
			$this->logger->error(
				'HTML export crashed',
				array(
					'page_id' => $page_id,
					'error'   => $e->getMessage(),
					'file'    => $e->getFile(),
					'line'    => $e->getLine(),
				)
			);

			return SScribe_Result::failure(
				__( 'Unable to generate the HTML file. Please try again.', 'sscribe-export-site-pages' ),
				array( 'page_id' => $page_id )
			);
		}
	}

	/**
	 * Get the strict archival allowlist for exported HTML.
	 *
	 * This disallows active content (iframes, embeds, scripts, etc.) to ensure
	 * the exported HTML is a non-executable archival snapshot. Ordinary links
	 * and images may still reference their original HTTP(S) resources.
	 *
	 * @return array Archival-safe HTML allowlist for wp_kses().
	 */
	private function get_archival_allowlist(): array {

		$allowlist['p']          = array(
			'lang' => true,
			'dir'  => true,
		);
		$allowlist['br']         = array();
		$allowlist['hr']         = array();
		$allowlist['blockquote'] = array( 'cite' => true );
		$allowlist['pre']        = array();
		$allowlist['code']       = array();

		$allowlist['h1'] = array();
		$allowlist['h2'] = array();
		$allowlist['h3'] = array();
		$allowlist['h4'] = array();
		$allowlist['h5'] = array();
		$allowlist['h6'] = array();

		$allowlist['strong'] = array();
		$allowlist['b']      = array();
		$allowlist['em']     = array();
		$allowlist['i']      = array();
		$allowlist['s']      = array();
		$allowlist['del']    = array();
		$allowlist['mark']   = array();
		$allowlist['small']  = array();
		$allowlist['sub']    = array();
		$allowlist['sup']    = array();
		$allowlist['u']      = array();

		$allowlist['a'] = array(
			'href'  => array(
				'protocols' => array( 'http', 'https', 'mailto', 'tel' ),
			),
			'title' => true,
			'rel'   => array(
				'nofollow'   => true,
				'noopener'   => true,
				'noreferrer' => true,
				'sponsored'  => true,
				'ugc'        => true,
				'tag'        => true,
			),
		);

		$allowlist['img'] = array(
			'src'     => true,
			'alt'     => true,
			'width'   => true,
			'height'  => true,
			'loading' => true,
		);

		$allowlist['ul'] = array();
		$allowlist['ol'] = array(
			'start' => true,
			'type'  => true,
		);
		$allowlist['li'] = array();
		$allowlist['dl'] = array();
		$allowlist['dt'] = array();
		$allowlist['dd'] = array();

		$allowlist['table']    = array();
		$allowlist['thead']    = array();
		$allowlist['tbody']    = array();
		$allowlist['tfoot']    = array();
		$allowlist['tr']       = array();
		$allowlist['th']       = array(
			'scope'   => true,
			'colspan' => true,
			'rowspan' => true,
		);
		$allowlist['td']       = array(
			'colspan' => true,
			'rowspan' => true,
		);
		$allowlist['caption']  = array();
		$allowlist['colgroup'] = array();
		$allowlist['col']      = array(
			'span'  => true,
			'width' => true,
		);

		$allowlist['figure']     = array();
		$allowlist['figcaption'] = array();
		$allowlist['details']    = array();
		$allowlist['summary']    = array();
		$allowlist['abbr']       = array( 'title' => true );
		$allowlist['cite']       = array();
		$allowlist['time']       = array( 'datetime' => true );
		$allowlist['address']    = array();
		$allowlist['article']    = array();
		$allowlist['aside']      = array();
		$allowlist['section']    = array();
		$allowlist['header']     = array();
		$allowlist['footer']     = array();
		$allowlist['nav']        = array();
		$allowlist['main']       = array();
		$allowlist['div']        = array(
			'lang' => true,
			'dir'  => true,
		);
		$allowlist['span']       = array();

		return $allowlist;
	}

	/**
	 * Generate full HTML document from page data.
	 *
	 * @param array $page_data Page data.
	 * @return string Complete HTML document.
	 */
	private function generate_html( array $page_data ): string {
		$site_name = $this->normalize_scalar( get_bloginfo( 'name' ), 'WordPress' );
		$title_raw = $this->normalize_scalar( $page_data['title'] ?? '', __( 'Untitled', 'sscribe-export-site-pages' ) );
		$title     = esc_html( $title_raw );
		$language  = $this->normalize_scalar( $page_data['language'] ?? '', 'en' );
		$language  = 1 === preg_match( '/^[A-Za-z]{2,8}(?:[-_][A-Za-z0-9]{1,8})*$/', $language ) ? $language : 'en';
		$direction = SScribe_RTL_Helper::get_direction( $language );
		$is_rtl    = SScribe_RTL_Helper::is_rtl( $language );

		$content          = $this->normalize_scalar( $page_data['content'] ?? '' );
		$filtered_content = wp_kses( $content, $this->get_archival_allowlist() );
		$permalink        = esc_url( $this->normalize_scalar( $page_data['permalink'] ?? '' ) );

		$direction_css = $is_rtl ? 'html, body { direction: rtl; }' : '';

		$show_seo = (bool) apply_filters( 'sscribe_html_export_show_seo', false, $page_data );

		$include_css = '1' === (string) $this->get_format_option( 'sscribe_html_include_css', '1' );
		$style_block = $include_css ? $this->get_document_style_element( $direction_css ) : '';

		$html = '<!DOCTYPE html>
<html lang="' . esc_attr( $language ) . '" dir="' . esc_attr( $direction ) . '">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>' . $title . ' | ' . esc_html( $site_name ) . '</title>
	' . $style_block . '
</head>
<body lang="' . esc_attr( $language ) . '" dir="' . esc_attr( $direction ) . '">
	<header>
		<h1>' . $title . '</h1>
		' . ( '' !== $permalink ? '<p><a href="' . $permalink . '">' . esc_html( rawurldecode( $permalink ) ) . '</a></p>' : '' ) . '
	</header>

	<main class="content">
		' . $this->get_featured_image_html( $page_data ) . '
		' . $this->get_meta_html( $page_data ) . '
		' . ( $show_seo ? $this->get_seo_html( $page_data ) : '' ) . '
		' . $filtered_content . '
	</main>

	<footer>
		<p><small>' . esc_html(
			sprintf(
				/* translators: %1$s: site name, %2$s: date and time */
				__( 'Exported from %1$s on %2$s', 'sscribe-export-site-pages' ),
				$site_name,
				wp_date(
					sanitize_text_field( (string) get_option( 'date_format', 'Y-m-d' ) ) . ' ' .
					sanitize_text_field( (string) get_option( 'time_format', 'H:i' ) )
				)
			)
		) . '</small></p>
	</footer>
</body>
</html>';

		return $html;
	}

	/**
	 * Build the style element for the exported document.
	 *
	 * This markup is written into a user-downloaded, standalone HTML artifact;
	 * it is never printed into a WordPress admin or front-end response. WordPress
	 * enqueue APIs therefore do not apply here. Keeping the CSS embedded is what
	 * makes the exported file portable after it leaves the WordPress site.
	 *
	 * @param string $direction_css Direction-specific CSS to append.
	 * @return string Complete style element, including tags.
	 */
	private function get_document_style_element( string $direction_css ): string {
		$css = '
		* { margin: 0; padding: 0; box-sizing: border-box; }
		body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif; max-width: 800px; margin: 0 auto; padding: 30px 20px; line-height: 1.6; color: #333; }
		header { border-bottom: 2px solid #4A8263; padding-bottom: 20px; margin-bottom: 30px; }
		h1 { font-size: 2em; color: #122119; margin-bottom: 10px; text-align: center; }
		h2, h3, h4 { color: #122119; margin: 20px 0 10px; }
		.meta { background: #E8EFEB; padding: 15px 20px; border-radius: 4px; margin-bottom: 20px; }
		.meta dt { font-weight: bold; margin-top: 10px; }
		.meta dd { margin: 0; color: #495057; }
		.content { margin-top: 20px; }
		.seo { background: #f5f5f5; padding: 15px 20px; border-radius: 4px; margin-top: 20px; }
		.featured-image { width: 100%; max-width: 600px; height: auto; margin-bottom: 20px; border-radius: 4px; display: block; margin-left: auto; margin-right: auto; }
		img { max-width: 100%; height: auto; display: block; margin: 8px auto; }
		a { color: #2C6E8A; text-decoration: none; }
		a:hover { text-decoration: underline; }
		footer { margin-top: 40px; padding-top: 20px; border-top: 1px solid #ddd; color: #666; font-size: 0.9em; }
		' . $direction_css;

		$element_name = 'style';
		return sprintf( '<%1$s>%2$s</%1$s>', $element_name, $css );
	}

	/**
	 * Get featured image HTML for the page.
	 *
	 * @param array $page_data Page data.
	 * @return string Image HTML or empty string.
	 */
	private function get_featured_image_html( array $page_data ): string {
		$src = esc_url( $this->normalize_scalar( $page_data['featured_image_url'] ?? '' ) );

		if ( '' === $src ) {
			return '';
		}

		$width  = isset( $page_data['featured_image_width'] ) && is_numeric( $page_data['featured_image_width'] ) ? max( 0, (int) $page_data['featured_image_width'] ) : 0;
		$height = isset( $page_data['featured_image_height'] ) && is_numeric( $page_data['featured_image_height'] ) ? max( 0, (int) $page_data['featured_image_height'] ) : 0;
		$alt    = esc_attr( $this->normalize_scalar( $page_data['title'] ?? '' ) );

		$responsive = '1' === (string) $this->get_format_option( 'sscribe_html_responsive_images', '1' );
		$loading    = $responsive ? 'lazy' : 'eager';
		$dimensions = ( $width && $height && ! $responsive )
			? sprintf( ' width="%d" height="%d"', $width, $height )
			: '';

		return sprintf(
			'<img src="%s" alt="%s" class="featured-image" loading="%s"%s>',
			$src,
			$alt,
			$loading,
			$dimensions
		);
	}

	/**
	 * Get meta information HTML for the page.
	 *
	 * @param array $page_data Page data.
	 * @return string Meta HTML or empty string.
	 */
	private function get_meta_html( array $page_data ): string {
		$rows           = array();
		$author         = $this->normalize_scalar( $page_data['author'] ?? '' );
		$date_published = $this->normalize_scalar( $page_data['date_published'] ?? '' );
		$date_modified  = $this->normalize_scalar( $page_data['date_modified'] ?? '' );

		if ( '' !== $author ) {
			$rows[] = '<dt>' . esc_html__( 'Author', 'sscribe-export-site-pages' ) . '</dt>'
				. '<dd>' . esc_html( $author ) . '</dd>';
		}
		if ( '' !== $date_published ) {
			$rows[] = '<dt>' . esc_html__( 'Published', 'sscribe-export-site-pages' ) . '</dt>'
				. '<dd>' . esc_html( $date_published ) . '</dd>';
		}
		if ( '' !== $date_modified ) {
			$rows[] = '<dt>' . esc_html__( 'Last Modified', 'sscribe-export-site-pages' ) . '</dt>'
				. '<dd>' . esc_html( $date_modified ) . '</dd>';
		}
		if ( isset( $page_data['word_count'] ) && is_numeric( $page_data['word_count'] ) ) {
			$rows[] = '<dt>' . esc_html__( 'Word Count', 'sscribe-export-site-pages' ) . '</dt>'
				. '<dd>' . esc_html( number_format_i18n( max( 0, (int) $page_data['word_count'] ) ) ) . '</dd>';
		}

		if ( empty( $rows ) ) {
			return '';
		}

		return '<dl class="meta">' . implode( '', $rows ) . '</dl>';
	}

	/**
	 * Get SEO metadata HTML for the page.
	 *
	 * @param array $page_data Page data.
	 * @return string SEO HTML or empty string.
	 */
	private function get_seo_html( array $page_data ): string {
		if ( empty( $page_data['seo'] ) || ! is_array( $page_data['seo'] ) ) {
			return '';
		}

		$seo  = $page_data['seo'];
		$html = '<div class="seo"><h3>' . esc_html__( 'SEO Metadata', 'sscribe-export-site-pages' ) . '</h3>';

		$text_fields = array(
			'source'             => __( 'Source:', 'sscribe-export-site-pages' ),
			'meta_title'         => __( 'Meta Title:', 'sscribe-export-site-pages' ),
			'meta_description'   => __( 'Meta Description:', 'sscribe-export-site-pages' ),
			'focus_keyword'      => __( 'Focus Keyword:', 'sscribe-export-site-pages' ),
			'og_title'           => __( 'Open Graph Title:', 'sscribe-export-site-pages' ),
			'og_description'     => __( 'Open Graph Description:', 'sscribe-export-site-pages' ),
		);
		foreach ( $text_fields as $key => $label ) {
			$value = $this->normalize_scalar( $seo[ $key ] ?? '' );
			if ( '' !== $value ) {
				$html .= '<p><strong>' . esc_html( $label ) . '</strong> ' . esc_html( $value ) . '</p>';
			}
		}

		$url_fields = array(
			'canonical_url' => __( 'Canonical URL:', 'sscribe-export-site-pages' ),
			'og_image'      => __( 'Open Graph Image:', 'sscribe-export-site-pages' ),
		);
		foreach ( $url_fields as $key => $label ) {
			$url = esc_url( $this->normalize_scalar( $seo[ $key ] ?? '' ) );
			if ( '' !== $url ) {
				$html .= '<p><strong>' . esc_html( $label ) . '</strong> <a href="' . $url . '">' . esc_html( rawurldecode( $url ) ) . '</a></p>';
			}
		}
		if ( ! empty( $seo['noindex'] ) || ! empty( $seo['nofollow'] ) ) {
			$robots = array();
			if ( ! empty( $seo['noindex'] ) ) {
				$robots[] = 'noindex';
			}
			if ( ! empty( $seo['nofollow'] ) ) {
				$robots[] = 'nofollow';
			}
			$html .= '<p><strong>' . esc_html__( 'Robots:', 'sscribe-export-site-pages' ) . '</strong> ' . esc_html( implode( ', ', $robots ) ) . '</p>';
		}

		$html .= '</div>';
		return $html;
	}

	/**
	 * Get the file extension for HTML files.
	 *
	 * @return string
	 */
	public function get_extension(): string {
		return 'html';
	}

	/**
	 * Get the MIME type for HTML files.
	 *
	 * @return string
	 */
	public function get_mime_type(): string {
		return 'text/html';
	}
}
