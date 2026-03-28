<?php
declare(strict_types=1);

/**
 * HTML exporter for SScribe.
 *
 * @package SScribe
 */

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
	 * Export a single page to HTML.
	 *
	 * @param array  $page_data  Page data from collector.
	 * @param string $output_dir Output directory.
	 * @param int    $index      Page index.
	 * @param int    $total      Total pages.
	 * @return SScribe_Result
	 */
	public function export( array $page_data, string $output_dir, int $index = 0, int $total = 0 ): SScribe_Result {
		$html = $this->generate_html( $page_data );

		$filename    = \SScribe_Exporter_Factory::build_filename( $page_data, $index, $total, 'html' );
		$output_path = trailingslashit( $output_dir ) . $filename;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents -- Output generation in temp dir.
		$result = file_put_contents( $output_path, $html );

		if ( false === $result ) {
			return SScribe_Result::failure(
				'Failed to write HTML file',
				array( 'path' => $output_path )
			);
		}

		return SScribe_Result::success(
			array(
				'path' => $output_path,
				'html' => $html,
				'size' => strlen( $html ),
			)
		);
	}

	/**
	 * Generate HTML content for a page.
	 *
	 * @param array $page_data Page data.
	 * @return string
	 */
	private function generate_html( array $page_data ): string {
		$site_name = get_bloginfo( 'name' );
		$title     = esc_html( $page_data['title'] );

		$html = '<!DOCTYPE html>
<html lang="' . esc_attr( $page_data['language'] ?? 'en' ) . '">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>' . $title . ' | ' . esc_html( $site_name ) . '</title>
	<style>
		body { font-family: Arial, sans-serif; max-width: 800px; margin: 0 auto; padding: 20px; line-height: 1.6; }
		h1, h2, h3 { color: #122119; }
		.meta { background: #E8EFEB; padding: 15px; border-radius: 4px; margin-bottom: 20px; }
		.meta dt { font-weight: bold; margin-top: 10px; }
		.meta dd { margin: 0; color: #495057; }
		.content { margin-top: 20px; }
		.seo { background: #f5f5f5; padding: 15px; border-radius: 4px; margin-top: 20px; }
		.featured-image { max-width: 100%; height: auto; margin-bottom: 20px; }
		a { color: #2C6E8A; }
	</style>
</head>
<body>
	<header>
		<h1>' . $title . '</h1>
		<p><a href="' . esc_url( $page_data['permalink'] ) . '">' . esc_html( $page_data['permalink'] ) . '</a></p>
	</header>

	<main class="content">
		' . $this->get_featured_image_html( $page_data ) . '
		' . $this->get_meta_html( $page_data ) . '
		' . $this->get_seo_html( $page_data ) . '
		' . wp_kses_post( $page_data['content'] ) . '
	</main>

	<footer>
		<hr>
		<p><small>Exported from ' . esc_html( $site_name ) . ' on ' . esc_html( gmdate( 'Y-m-d H:i' ) ) . '</small></p>
	</footer>
</body>
</html>';

		return $html;
	}

	/**
	 * Get featured image HTML.
	 *
	 * @param array $page_data Page data.
	 * @return string
	 */
	private function get_featured_image_html( array $page_data ): string {
		if ( empty( $page_data['featured_image_url'] ) ) {
			return '';
		}

		return '<img src="' . esc_url( $page_data['featured_image_url'] ) . '" 
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
			<dd>' . number_format( $page_data['word_count'] ?? 0 ) . '</dd>
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
			$html .= '<p><strong>' . __( 'Canonical URL:', 'sscribe-export-site-pages' ) . '</strong> <a href="' . esc_url( $seo['canonical_url'] ) . '">' . esc_html( $seo['canonical_url'] ) . '</a></p>';
		}
		if ( ! empty( $seo['og_title'] ) ) {
			$html .= '<p><strong>' . __( 'Open Graph Title:', 'sscribe-export-site-pages' ) . '</strong> ' . esc_html( $seo['og_title'] ) . '</p>';
		}
		if ( ! empty( $seo['og_description'] ) ) {
			$html .= '<p><strong>' . __( 'Open Graph Description:', 'sscribe-export-site-pages' ) . '</strong> ' . esc_html( $seo['og_description'] ) . '</p>';
		}
		if ( ! empty( $seo['og_image'] ) ) {
			$html .= '<p><strong>' . __( 'Open Graph Image:', 'sscribe-export-site-pages' ) . '</strong> <a href="' . esc_url( $seo['og_image'] ) . '">' . esc_html( $seo['og_image'] ) . '</a></p>';
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
