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
	 * Generate HTML string for a page without writing to disk.
	 *
	 * Used by the PDF exporter to obtain the HTML content for mPDF rendering
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
		$page_id = $page_data['id'] ?? 0;
		$title   = $page_data['title'] ?? 'Untitled';

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
					array(
						'page_id' => $page_id,
						'path'    => $output_path,
					)
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
					'title'   => $title,
					'error'   => $e->getMessage(),
					'file'    => $e->getFile(),
					'line'    => $e->getLine(),
				)
			);

			return SScribe_Result::failure(
				sprintf(
					/* translators: 1: Page title, 2: Error message. */

					__( 'HTML export failed for "%1$s": %2$s', 'sscribe-export-site-pages' ),
					$title,
					$e->getMessage()
				),
				array( 'page_id' => $page_id )
			);
		}
	}

	/**
	 * Get the strict archival allowlist for exported HTML.
	 *
	 * This disallows all active/remote content (iframes, embeds, scripts, etc.)
	 * to ensure the exported HTML is a safe archival snapshot with no live
	 * network dependencies or executable content.
	 *
	 * @return array Archival-safe HTML allowlist for wp_kses().
	 */
	private function get_archival_allowlist(): array {
		// Text structure.
		$allowlist['p']          = array(
			'lang' => true,
			'dir'  => true,
		);
		$allowlist['br']         = array();
		$allowlist['hr']         = array();
		$allowlist['blockquote'] = array( 'cite' => true );
		$allowlist['pre']        = array();
		$allowlist['code']       = array();
		// Headings.
		$allowlist['h1'] = array();
		$allowlist['h2'] = array();
		$allowlist['h3'] = array();
		$allowlist['h4'] = array();
		$allowlist['h5'] = array();
		$allowlist['h6'] = array();
		// Inline.
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
		// Links - href restricted to safe protocols only; javascript:/data: blocked.
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
		// Images - src/alt/dimensions only, no script hooks.
		$allowlist['img'] = array(
			'src'     => true,
			'alt'     => true,
			'width'   => true,
			'height'  => true,
			'loading' => true,
		);
		// Lists.
		$allowlist['ul'] = array();
		$allowlist['ol'] = array(
			'start' => true,
			'type'  => true,
		);
		$allowlist['li'] = array();
		$allowlist['dl'] = array();
		$allowlist['dt'] = array();
		$allowlist['dd'] = array();
		// Tables.
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
		// Semantic.
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
		// NO: iframe, embed, object, param, canvas, svg, script, style,
		// video, audio, source, track, img with on* attributes.
		return $allowlist;
	}

	/**
	 * Generate full HTML document from page data.
	 *
	 * @param array $page_data Page data.
	 * @return string Complete HTML document.
	 */
	private function generate_html( array $page_data ): string {
		$site_name = get_bloginfo( 'name' );
		$title     = esc_html( $page_data['title'] );
		$language  = $page_data['language'] ?? 'en';
		$direction = SScribe_RTL_Helper::get_direction( $language );
		$is_rtl    = SScribe_RTL_Helper::is_rtl( $language );

		$filtered_content = wp_kses( $page_data['content'], $this->get_archival_allowlist() );

		$direction_css = $is_rtl ? 'html, body { direction: rtl; }' : '';

		// SEO block is opt-in via filter — not shown by default in reader-facing exports.
		$show_seo = (bool) apply_filters( 'sscribe_html_export_show_seo', false, $page_data );

		$html = '<!DOCTYPE html>
<html lang="' . esc_attr( $language ) . '" dir="' . esc_attr( $direction ) . '">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>' . $title . ' | ' . esc_html( $site_name ) . '</title>
	<style>
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
		' . $direction_css . '
	</style>
</head>
<body lang="' . esc_attr( $language ) . '" dir="' . esc_attr( $direction ) . '">
	<header>
		<h1>' . $title . '</h1>
		<p><a href="' . esc_url( $page_data['permalink'] ) . '">' . esc_html( rawurldecode( $page_data['permalink'] ) ) . '</a></p>
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
			/* translators: 1: site name, 2: date and time */

				__( 'Exported from %1$s on %2$s', 'sscribe-export-site-pages' ),
				$site_name,
				gmdate( 'Y-m-d H:i' )
			)
		) . '</small></p>
	</footer>
</body>
</html>';

		return $html;
	}

	/**
	 * Get featured image HTML for the page.
	 *
	 * @param array $page_data Page data.
	 * @return string Image HTML or empty string.
	 */
	private function get_featured_image_html( array $page_data ): string {
		$src = ! empty( $page_data['featured_image_url'] )
			? $page_data['featured_image_url']
			: '';

		if ( empty( $src ) ) {
			return '';
		}

		$width  = (int) ( $page_data['featured_image_width'] ?? 0 );
		$height = (int) ( $page_data['featured_image_height'] ?? 0 );
		$alt    = esc_attr( $page_data['title'] ?? '' );

		$dimensions = ( $width && $height )
			? sprintf( ' width="%d" height="%d"', $width, $height )
			: '';

		return sprintf(
			'<img src="%s" alt="%s" class="featured-image" loading="eager"%s>',
			esc_url( $src ),
			$alt,
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
		$rows = array();

		if ( ! empty( $page_data['author'] ) ) {
			$rows[] = '<dt>' . __( 'Author', 'sscribe-export-site-pages' ) . '</dt>'
				. '<dd>' . esc_html( $page_data['author'] ) . '</dd>';
		}
		if ( ! empty( $page_data['date_published'] ) ) {
			$rows[] = '<dt>' . __( 'Published', 'sscribe-export-site-pages' ) . '</dt>'
				. '<dd>' . esc_html( $page_data['date_published'] ) . '</dd>';
		}
		if ( ! empty( $page_data['date_modified'] ) ) {
			$rows[] = '<dt>' . __( 'Last Modified', 'sscribe-export-site-pages' ) . '</dt>'
				. '<dd>' . esc_html( $page_data['date_modified'] ) . '</dd>';
		}
		if ( isset( $page_data['word_count'] ) ) {
			$rows[] = '<dt>' . __( 'Word Count', 'sscribe-export-site-pages' ) . '</dt>'
				. '<dd>' . number_format_i18n( $page_data['word_count'] ) . '</dd>';
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
		if ( empty( $page_data['seo'] ) ) {
			return '';
		}

		$seo  = $page_data['seo'];
		$html = '<div class="seo"><h3>' . __( 'SEO Metadata', 'sscribe-export-site-pages' ) . '</h3>';

		if ( ! empty( $seo['source'] ) ) {
			$html .= '<p><strong>' . __( 'Source:', 'sscribe-export-site-pages' ) . '</strong> ' . esc_html( $seo['source'] ) . '</p>';
		}
		if ( ! empty( $seo['meta_title'] ) ) {
			$html .= '<p><strong>' . __( 'Meta Title:', 'sscribe-export-site-pages' ) . '</strong> ' . esc_html( $seo['meta_title'] ) . '</p>';
		}
		if ( ! empty( $seo['meta_description'] ) ) {
			$html .= '<p><strong>' . __( 'Meta Description:', 'sscribe-export-site-pages' ) . '</strong> ' . esc_html( $seo['meta_description'] ) . '</p>';
		}
		if ( ! empty( $seo['focus_keyword'] ) ) {
			$html .= '<p><strong>' . __( 'Focus Keyword:', 'sscribe-export-site-pages' ) . '</strong> ' . esc_html( $seo['focus_keyword'] ) . '</p>';
		}
		if ( ! empty( $seo['canonical_url'] ) ) {
			$html .= '<p><strong>' . __( 'Canonical URL:', 'sscribe-export-site-pages' ) . '</strong> <a href="' . esc_url( $seo['canonical_url'] ) . '">' . esc_html( rawurldecode( $seo['canonical_url'] ) ) . '</a></p>';
		}
		if ( ! empty( $seo['og_title'] ) ) {
			$html .= '<p><strong>' . __( 'Open Graph Title:', 'sscribe-export-site-pages' ) . '</strong> ' . esc_html( $seo['og_title'] ) . '</p>';
		}
		if ( ! empty( $seo['og_description'] ) ) {
			$html .= '<p><strong>' . __( 'Open Graph Description:', 'sscribe-export-site-pages' ) . '</strong> ' . esc_html( $seo['og_description'] ) . '</p>';
		}
		if ( ! empty( $seo['og_image'] ) ) {
			$html .= '<p><strong>' . __( 'Open Graph Image:', 'sscribe-export-site-pages' ) . '</strong> <a href="' . esc_url( $seo['og_image'] ) . '">' . esc_html( rawurldecode( $seo['og_image'] ) ) . '</a></p>';
		}
		if ( ! empty( $seo['noindex'] ) || ! empty( $seo['nofollow'] ) ) {
			$robots = array();
			if ( ! empty( $seo['noindex'] ) ) {
				$robots[] = 'noindex';
			}
			if ( ! empty( $seo['nofollow'] ) ) {
				$robots[] = 'nofollow';
			}
			$html .= '<p><strong>' . __( 'Robots:', 'sscribe-export-site-pages' ) . '</strong> ' . esc_html( implode( ', ', $robots ) ) . '</p>';
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
