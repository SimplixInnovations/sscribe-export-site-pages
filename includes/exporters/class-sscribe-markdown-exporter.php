<?php
/**
 * Markdown exporter for SScribe.
 *
 * @package SScribe
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once SSCRIBE_PLUGIN_DIR . 'includes/exporters/interface-sscribe-exporter.php';

/**
 * Class SScribe_Markdown_Exporter
 *
 * Exports pages to Markdown format.
 */
class SScribe_Markdown_Exporter implements SScribe_Exporter_Interface {

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

		$result = file_put_contents( $output_path, $markdown );

		if ( false === $result ) {
			return SScribe_Result::failure(
				'Failed to write Markdown file',
				array( 'path' => $output_path )
			);
		}

		return SScribe_Result::success( array(
			'path' => $output_path,
			'size' => strlen( $markdown ),
		) );
	}

	/**
	 * Generate Markdown content.
	 *
	 * @param array $page_data Page data.
	 * @return string
	 */
	private function generate_markdown( array $page_data ): string {
		$md = '# ' . ( $page_data['title'] ?? 'Untitled' ) . "\n\n";
		$md .= '> ' . ( $page_data['permalink'] ?? '' ) . "\n\n";

		$md .= "---\n";
		$md .= "author: " . ( $page_data['author'] ?? 'Unknown' ) . "\n";
		$md .= "published: " . ( $page_data['date_published'] ?? '' ) . "\n";
		$md .= "modified: " . ( $page_data['date_modified'] ?? '' ) . "\n";
		$md .= "word_count: " . ( $page_data['word_count'] ?? 0 ) . "\n";
		$md .= "language: " . ( $page_data['language'] ?? 'en' ) . "\n";

		if ( ! empty( $page_data['seo'] ) ) {
			$seo = $page_data['seo'];
			if ( ! empty( $seo['meta_title'] ) ) {
				$md .= "seo_title: \"" . str_replace( '"', '\\"', $seo['meta_title'] ) . "\"\n";
			}
			if ( ! empty( $seo['meta_description'] ) ) {
				$md .= "seo_description: \"" . str_replace( '"', '\\"', $seo['meta_description'] ) . "\"\n";
			}
			if ( ! empty( $seo['focus_keyword'] ) ) {
				$md .= "seo_focus_keyword: \"" . str_replace( '"', '\\"', $seo['focus_keyword'] ) . "\"\n";
			}
			if ( ! empty( $seo['canonical_url'] ) ) {
				$md .= "canonical_url: " . $seo['canonical_url'] . "\n";
			}
			if ( ! empty( $seo['source'] ) ) {
				$md .= "seo_source: " . $seo['source'] . "\n";
			}
		}

		$md .= "---\n\n";

		$md .= $this->html_to_markdown( $page_data['content'] ?? '' );

		return $md;
	}

	/**
	 * Convert HTML to Markdown.
	 *
	 * @param string $html HTML content.
	 * @return string
	 */
	private function html_to_markdown( string $html ): string {
		$md = $html;

		$md = preg_replace( '/<h1[^>]*>(.*?)<\/h1>/is', '# $1', $md );
		$md = preg_replace( '/<h2[^>]*>(.*?)<\/h2>/is', '## $1', $md );
		$md = preg_replace( '/<h3[^>]*>(.*?)<\/h3>/is', '### $1', $md );
		$md = preg_replace( '/<h4[^>]*>(.*?)<\/h4>/is', '#### $1', $md );
		$md = preg_replace( '/<h5[^>]*>(.*?)<\/h5>/is', '##### $1', $md );
		$md = preg_replace( '/<h6[^>]*>(.*?)<\/h6>/is', '###### $1', $md );

		$md = preg_replace( '/<p[^>]*>(.*?)<\/p>/is', "$1\n\n", $md );

		$md = preg_replace( '/<(strong|b)>(.*?)<\/\1>/is', '**$2**', $md );
		$md = preg_replace( '/<(em|i)>(.*?)<\/\1>/is', '*$2*', $md );

		$md = preg_replace_callback(
			'/<a[^>]*href="([^"]*)"[^>]*>(.*?)<\/a>/is',
			function( $matches ) {
				$url = $matches[1];
				$text = $matches[2];
				$sanitized_url = $this->sanitize_markdown_url( $url );
				return '[' . $text . '](' . $sanitized_url . ')';
			},
			$md
		);

		$md = preg_replace( '/<li>(.*?)<\/li>/is', '- $1', $md );
		$md = preg_replace( '/<\/?ul>/is', '', $md );
		$md = preg_replace( '/<\/?ol>/is', '', $md );

		$md = preg_replace( '/<code>(.*?)<\/code>/is', '`$1`', $md );
		$md = preg_replace( '/<pre>(.*?)<\/pre>/is', "```\n$1\n```", $md );

		$md = preg_replace( '/<blockquote[^>]*>(.*?)<\/blockquote>/is', "> $1", $md );

		$md = strip_tags( $md );

		$md = preg_replace( '/\n{3,}/', "\n\n", $md );

		return trim( $md );
	}

	/**
	 * Sanitize URL for markdown output.
	 *
	 * @param string $url URL to sanitize.
	 * @return string Sanitized URL.
	 */
	private function sanitize_markdown_url( string $url ): string {
		$url = trim( $url );
		
		if ( empty( $url ) ) {
			return '#';
		}

		$parsed = parse_url( $url );
		$scheme = isset( $parsed['scheme'] ) ? strtolower( $parsed['scheme'] ) : '';

		$allowed_schemes = array( 'http', 'https', 'mailto', 'tel', 'ftp' );
		
		if ( ! empty( $scheme ) && ! in_array( $scheme, $allowed_schemes, true ) ) {
			return '#';
		}

		return $url;
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
