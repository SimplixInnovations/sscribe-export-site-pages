<?php
/**
 * HTML exporter for SScribe.
 *
 * @package SScribe
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once SSCRIBE_PLUGIN_DIR . 'includes/exporters/interface-sscribe-exporter.php';

/**
 * Class SScribe_HTML_Exporter
 *
 * Exports pages to standalone HTML format.
 */
class SScribe_HTML_Exporter implements SScribe_Exporter_Interface {

	/**
	 * Logger instance.
	 *
	 * @var SScribe_Logger_Interface|null
	 */
	private ?SScribe_Logger_Interface $logger = null;

	/**
	 * Filesystem instance.
	 *
	 * @var SScribe_Filesystem
	 */
	private SScribe_Filesystem $filesystem;

	/**
	 * Constructor.
	 *
	 * @param SScribe_Logger_Interface|null $logger     Logger instance.
	 * @param SScribe_Filesystem|null       $filesystem Filesystem instance.
	 */
	public function __construct( ?SScribe_Logger_Interface $logger = null, ?SScribe_Filesystem $filesystem = null ) {
		$this->logger     = $logger ?? SScribe_Logger::instance( defined( 'SSCRIBE_DEBUG' ) && SSCRIBE_DEBUG );
		$this->filesystem = $filesystem ?? new SScribe_Filesystem();
	}

	/**
	 * Export a single page to HTML.
	 *
	 * @param array  $page_data  Page data from collector.
	 * @param string $output_dir Output directory.
	 * @param int    $index      Page index.
	 * @param int    $total      Total pages.
	 * @return SScribe_Result
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
	 * Generate HTML content for a page.
	 *
	 * For standalone HTML exports, we apply a more permissive content filter
	 * since the content has already been sanitized at input time via WordPress.
	 * We extend the allowlist to include media elements appropriate for
	 * standalone HTML files (video, audio, iframe, canvas, svg).
	 *
	 * @param array $page_data Page data.
	 * @return string
	 */
	private function generate_html( array $page_data ): string {
		require_once SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-rtl-helper.php';

		$site_name  = get_bloginfo( 'name' );
		$title      = esc_html( $page_data['title'] );
		$language  = $page_data['language'] ?? 'en';
		$direction = SScribe_RTL_Helper::get_direction( $language );
		$is_rtl    = SScribe_RTL_Helper::is_rtl( $language );

		$rtl_extra = $is_rtl ?
			'
		html, body { direction: rtl; font-family: system-ui, -apple-system, sans-serif; }
		h1 { text-align: center; }
		.featured-image { width: 100%; max-width: 600px; margin: 0 auto; display: block; }
		' :
			'
		h1 { text-align: center; }
		.featured-image { width: 100%; max-width: 600px; margin: 0 auto; display: block; }
		';

		// Build a permissive kses allowlist for standalone HTML export.
		// wp_kses_post() strips video, audio, iframe, canvas — elements valid in HTML.
		// The content was already sanitized at input time via WordPress, so this is safe.
		$allowed_html = array(
			'video'  => array(
				'src'      => true,
				'controls' => true,
				'width'    => true,
				'height'   => true,
				'loop'     => true,
				'muted'    => true,
				'poster'   => true,
				'preload'  => true,
			),
			'audio'  => array(
				'src'      => true,
				'controls' => true,
				'loop'     => true,
				'muted'    => true,
				'preload'  => true,
			),
			'iframe' => array(
				'src'             => true,
				'width'           => true,
				'height'          => true,
				'frameborder'     => true,
				'allow'           => true,
				'allowfullscreen' => true,
				'loading'         => true,
			),
			'canvas' => array(
				'width'  => true,
				'height' => true,
				'id'     => true,
			),
			'svg'    => array(
				'xmlns'       => true,
				'viewbox'     => true,
				'width'       => true,
				'height'      => true,
				'fill'        => true,
				'stroke'      => true,
				'stroke-width' => true,
			),
			'source' => array(
				'src'  => true,
				'type' => true,
				'media' => true,
			),
			'track' => array(
				'kind'    => true,
				'src'     => true,
				'srclang' => true,
				'label'   => true,
				'default' => true,
			),
			'embed' => array(
				'src'    => true,
				'type'   => true,
				'width'  => true,
				'height' => true,
			),
			'object' => array(
				'data'   => true,
				'type'   => true,
				'width'  => true,
				'height' => true,
			),
			'param' => array(
				'name'  => true,
				'value' => true,
			),
		);

		$filtered_content = wp_kses( $page_data['content'], $allowed_html );

		$html = '<!DOCTYPE html>
<html lang="' . esc_attr( $language ) . '" dir="' . esc_attr( $direction ) . '">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>' . $title . ' | ' . esc_html( $site_name ) . '</title>
	<style>
		* { margin: 0; padding: 0; box-sizing: border-box; }
		body { font-family: \'Manrope\', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif; max-width: 800px; margin: 0 auto; padding: 30px 20px; line-height: 1.6; color: #333; }
		header { border-bottom: 2px solid #4A8263; padding-bottom: 20px; margin-bottom: 30px; }
		h1 { font-size: 2em; color: #122119; margin-bottom: 10px; }
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
		' . $rtl_extra . '
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
		' . $this->get_seo_html( $page_data ) . '
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
	 * Get featured image HTML.
	 *
	 * For standalone HTML exports, always use the HTTP URL so the image
	 * is accessible when the HTML file is opened on any device/server.
	 * The local path (featured_image_path) is only needed for PDF generation.
	 *
	 * @param array $page_data Page data.
	 * @return string
	 */
	private function get_featured_image_html( array $page_data ): string {
		// Use HTTP URL for standalone HTML portability.
		// Local paths are only useful for PDF (mPDF) generation, not HTML exports.
		$src = ! empty( $page_data['featured_image_url'] )
			? $page_data['featured_image_url']
			: '';

		if ( empty( $src ) ) {
			return '';
		}

		return '<img src="' . esc_url( $src ) . '"
			alt="' . esc_attr( $page_data['title'] ) . '"
			class="featured-image">';
	}

	/**
	 * Get meta information HTML.
	 *
	 * @param array $page_data Page data.
	 * @return string
	 */
	private function get_meta_html( array $page_data ): string {
		return '<dl class="meta">
			<dt>' . __( 'Author', 'sscribe-export-site-pages' ) . '</dt>
			<dd>' . esc_html( $page_data['author'] ?? '' ) . '</dd>
			<dt>' . __( 'Published', 'sscribe-export-site-pages' ) . '</dt>
			<dd>' . esc_html( $page_data['date_published'] ?? '' ) . '</dd>
			<dt>' . __( 'Last Modified', 'sscribe-export-site-pages' ) . '</dt>
			<dd>' . esc_html( $page_data['date_modified'] ?? '' ) . '</dd>
			<dt>' . __( 'Word Count', 'sscribe-export-site-pages' ) . '</dt>
			<dd>' . number_format_i18n( $page_data['word_count'] ?? 0 ) . '</dd>
		</dl>';
	}

	/**
	 * Get SEO information HTML.
	 *
	 * @param array $page_data Page data.
	 * @return string
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
	 * Get the file extension.
	 *
	 * @return string
	 */
	public function get_extension(): string {
		return 'html';
	}

	/**
	 * Get the mime type.
	 *
	 * @return string
	 */
	public function get_mime_type(): string {
		return 'text/html';
	}
}
