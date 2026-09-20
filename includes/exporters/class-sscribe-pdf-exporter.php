<?php
/**
 * SScribe PDF Exporter
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

/**
 * Exports pages as PDF documents using the bundled TCPDF renderer.
 */
class SScribe_PDF_Exporter implements SScribe_Exporter_Interface {

	/**
	 * HTML exporter for generating page content.
	 *
	 * @var SScribe_HTML_Exporter
	 */
	private SScribe_HTML_Exporter $html_exporter;

	/**
	 * Logger for tracking export operations.
	 *
	 * @var SScribe_Logger_Interface
	 */
	private SScribe_Logger_Interface $logger;

	/**
	 * Filesystem handler for file operations.
	 *
	 * @var SScribe_Filesystem
	 */
	private SScribe_Filesystem $filesystem;

	/**
	 * Per-export format options set by the batch processor.
	 *
	 * Keys are format-prefixed option names (e.g. `sscribe_pdf_page_size`).
	 * Populated via apply_format_options() before export() is called.
	 *
	 * @var array<string, mixed>
	 */
	private array $format_options = array();

	/**
	 * Initialize the PDF exporter.
	 *
	 * @param SScribe_HTML_Exporter|null    $html_exporter HTML exporter.
	 * @param SScribe_Logger_Interface|null $logger        Logger.
	 * @param SScribe_Filesystem|null       $filesystem    Filesystem handler.
	 */
	public function __construct(
		?SScribe_HTML_Exporter $html_exporter = null,
		?SScribe_Logger_Interface $logger = null,
		?SScribe_Filesystem $filesystem = null
	) {
		$this->html_exporter = $html_exporter ?? new SScribe_HTML_Exporter();
		$this->logger        = $logger ?? SScribe_Logger::instance( SScribe_Logger::is_logging_enabled() );
		$this->filesystem    = $filesystem ?? new SScribe_Filesystem();
	}

	/**
	 * Apply per-format options to this exporter instance.
	 *
	 * The batch processor calls this after running the
	 * `sscribe_export_options_pdf` filter and before export().
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
	 * Normalize filtered page metadata without allowing arrays or objects to
	 * reach strict third-party library APIs.
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
	 * Export a page as a PDF file.
	 *
	 * @param array  $page_data Page data to export.
	 * @param string $output_dir Output directory path.
	 * @param int    $index     Current page index.
	 * @param int    $total     Total number of pages.
	 * @return SScribe_Result Result of the export operation.
	 */
	public function export( array $page_data, string $output_dir, int $index = 0, int $total = 0 ): SScribe_Result {
		$page_id  = isset( $page_data['id'] ) && is_numeric( $page_data['id'] ) ? absint( $page_data['id'] ) : 0;
		$title    = $this->normalize_scalar( $page_data['title'] ?? '', __( 'Untitled', 'sscribe-export-site-pages' ) );
		$language = $this->normalize_scalar( $page_data['language'] ?? '', 'en' );
		$author   = $this->normalize_scalar( $page_data['author'] ?? '' );
		$seo      = isset( $page_data['seo'] ) && is_array( $page_data['seo'] ) ? $page_data['seo'] : array();

		$is_rtl                 = false;
		$html_size              = 0;
		$output_path            = '';
		$pdf                     = null;
		$html_content            = '';
		$temp_image_paths       = array();
		$ob_level_before_render = ob_get_level();

		try {
			require_once SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-rtl-helper.php';
			require_once SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-image-processor.php';

			$is_rtl              = SScribe_RTL_Helper::is_rtl( $language );
			$include_images      = '1' === (string) $this->get_format_option( 'sscribe_pdf_include_images', '1' );
			$processed_page_data = $include_images ? $this->process_images_in_page_data( $page_data ) : $page_data;
			$temp_image_paths    = $this->collect_temp_image_paths( $processed_page_data );
			$html_content        = $this->html_exporter->generate_html_string( $processed_page_data );
			$html_content        = $this->sanitize_pdf_image_sources( $html_content, $include_images );
			$html_content        = $this->prepare_html_for_pdf_engine( $html_content, $is_rtl );
			$html_size           = strlen( $html_content );

			if ( ! class_exists( '\\SScribeVendor_TCPDF' ) ) {
				return SScribe_Result::failure(
					__( 'PDF export is not available because the bundled PDF renderer is missing. Please reinstall the plugin.', 'sscribe-export-site-pages' ),
					array(
						'error_category' => 'pdf_missing_library',
						'page_id'        => $page_id,
						'page_title'     => $title,
						'language'       => $language,
					)
				);
			}

			$filtered_max_html_size = (int) apply_filters( 'sscribe_pdf_max_html_size', 5 * 1024 * 1024 );
			$max_html_size          = $filtered_max_html_size > 0 ? max( 64 * 1024, min( 50 * 1024 * 1024, $filtered_max_html_size ) ) : 0;
			if ( $max_html_size > 0 && $html_size > $max_html_size ) {
				return SScribe_Result::failure(
					sprintf(
						/* translators: %s: HTML size. */
						__( 'PDF render skipped because the HTML content is too large (%s). Try exporting to DOCX or reduce page complexity.', 'sscribe-export-site-pages' ),
						size_format( $html_size )
					),
					array(
						'error_category' => 'pdf_memory_guard',
						'page_id'        => $page_id,
						'html_size'      => $html_size,
					)
				);
			}

			$memory_pressure = $this->check_memory_pressure();
			if ( $memory_pressure instanceof SScribe_Result ) {
				return $memory_pressure;
			}

			if ( function_exists( 'set_time_limit' ) && (int) ini_get( 'max_execution_time' ) > 0 ) {
				$max_exec = (int) ini_get( 'max_execution_time' );
				set_time_limit( max( 60, $max_exec ) ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
			}

			$max_exec    = (int) ini_get( 'max_execution_time' );
			$batch_start = isset( $page_data['_batch_start_time'] ) && is_numeric( $page_data['_batch_start_time'] ) ? (float) $page_data['_batch_start_time'] : 0.0;
			if ( $batch_start > 0.0 && $max_exec > 0 && ( $max_exec - ( microtime( true ) - $batch_start ) ) < 20 ) {
				return SScribe_Result::failure(
					__( 'Insufficient time remaining to render PDF.', 'sscribe-export-site-pages' ),
					array(
						'error_category' => 'timeout',
						'page_id'        => $page_id,
						'page_title'     => $title,
						'language'       => $language,
					)
				);
			}

			$filename    = \SScribe_Exporter_Factory::build_filename( $page_data, $index, $total, 'pdf' );
			$output_path = trailingslashit( $output_dir ) . $filename;
			if ( SScribe_Filesystem::SSCRIBE_PATH_ALLOWED !== $this->filesystem->is_path_safe_for_write( $output_path ) ) {
				return SScribe_Result::failure(
					__( 'Failed to write PDF file.', 'sscribe-export-site-pages' ),
					array(
						'error_category' => 'pdf_filesystem',
						'page_id'        => $page_id,
					)
				);
			}

			$pdf = $this->create_tcpdf_document( $is_rtl );
			if ( $pdf instanceof SScribe_Result ) {
				return $pdf;
			}

			$pdf->setTitle( wp_strip_all_tags( $title ) );
			$pdf->setAuthor( wp_strip_all_tags( $author ) );
			$pdf->setCreator( 'SScribe Export Plugin v' . SSCRIBE_VERSION );
			$pdf->setSubject( wp_strip_all_tags( $this->normalize_scalar( $seo['meta_description'] ?? '' ) ) );
			$pdf->setKeywords( wp_strip_all_tags( $this->normalize_scalar( $seo['focus_keyword'] ?? '' ) ) );
			$pdf->AddPage();

			$base_css  = 'html, body, div, p, span, h1, h2, h3, h4, h5, h6, table, tr, td, th, ul, ol, li, blockquote, q, cite, a { font-family: dejavusans; }';
			$base_css .= ' img { max-width: 100%; height: auto; }';
			$base_css .= ' table { width: 100%; border-collapse: collapse; }';
			$base_css .= ' td, th { word-wrap: break-word; }';
			$base_css .= ' a { color: #2C6E8A; text-decoration: none; }';
			$base_css .= ' h1, h2, h3, h4, h5, h6 { color: #122119; }';
			if ( $is_rtl ) {
				$base_css .= ' html, body, table { direction: rtl; } body { text-align: right; }';
			}

			$pdf->writeHTML( '<style>' . $base_css . '</style>' . $html_content, true, false, true, false, $is_rtl ? 'R' : 'L' );
			$pdf->Output( $output_path, 'F' );

			if ( is_link( $output_path ) || ! is_file( $output_path ) ) {
				return SScribe_Result::failure(
					__( 'Failed to write PDF file.', 'sscribe-export-site-pages' ),
					array(
						'error_category' => 'pdf_filesystem',
						'page_id'        => $page_id,
					)
				);
			}

			$bytes_written = filesize( $output_path );
			if ( false === $bytes_written || $bytes_written <= 0 ) {
				wp_delete_file( $output_path );
				return SScribe_Result::failure(
					__( 'Failed to write PDF file.', 'sscribe-export-site-pages' ),
					array(
						'error_category' => 'pdf_filesystem',
						'page_id'        => $page_id,
					)
				);
			}

			return SScribe_Result::success(
				array(
					'path' => $output_path,
					'size' => $bytes_written,
				)
			);
		} catch ( \Throwable $e ) {
			if ( ! empty( $output_path ) && file_exists( $output_path ) ) {
				wp_delete_file( $output_path );
			}
			$this->logger->error(
				'PDF generation failed',
				array(
					'error'        => $e->getMessage(),
					'class'        => get_class( $e ),
					'page_id'      => $page_id,
					'html_size'    => $html_size,
					'memory_usage' => size_format( memory_get_usage( true ) ),
					'memory_peak'  => size_format( memory_get_peak_usage( true ) ),
				)
			);
			return SScribe_Result::failure(
				__( 'Unable to generate the PDF. Please try again or use another export format.', 'sscribe-export-site-pages' ),
				array(
					'error_category'  => 'pdf_generation',
					'page_id'         => $page_id,
					'page_title'      => $title,
					'language'        => $language,
					'is_rtl'          => $is_rtl,
					'html_size'       => $html_size,
					'tcpdf_available' => class_exists( '\\SScribeVendor_TCPDF', false ),
				)
			);
		} finally {
			while ( ob_get_level() > $ob_level_before_render ) {
				ob_end_clean();
			}
			$this->cleanup_temp_images( $temp_image_paths );
			$pdf          = null;
			$html_content = null;
			if ( function_exists( 'gc_collect_cycles' ) ) {
				gc_collect_cycles();
			}
		}
	}

	/**
	 * Check current memory usage against PHP memory_limit and either
	 * trigger garbage collection or return a failure result if the
	 * process is too close to the limit to safely render another PDF.
	 *
	 * Two thresholds, both configurable via the
	 * `sscribe_pdf_memory_soft_margin_bytes` (default 32MB) and
	 * `sscribe_pdf_memory_hard_margin_bytes` (default 8MB) filters:
	 *
	 *  - Soft margin: trigger gc_collect_cycles() and log a warning.
	 *  - Hard margin: abort the export with a graceful failure result.
	 *
	 * The hard margin prevents OOM mid-render, which would produce
	 * truncated or corrupt PDF output. The soft margin gives the runtime
	 * a chance to reclaim memory between pages in a long batch.
	 *
	 * @return null|SScribe_Result Null if memory is OK; SScribe_Result
	 *                            failure if the export must abort.
	 */
	private function check_memory_pressure() {
		$memory_limit_str = (string) ini_get( 'memory_limit' );
		if ( '' === $memory_limit_str || '-1' === $memory_limit_str ) {

			return null;
		}

		$memory_limit = wp_convert_hr_to_bytes( $memory_limit_str );
		if ( $memory_limit <= 0 ) {
			return null;
		}

		$memory_used    = memory_get_usage( true );
		$memory_peak    = memory_get_peak_usage( true );
		$memory_free    = $memory_limit - $memory_used;

		$soft_margin = (int) apply_filters( 'sscribe_pdf_memory_soft_margin_bytes', 32 * 1024 * 1024 );
		$hard_margin = (int) apply_filters( 'sscribe_pdf_memory_hard_margin_bytes', 8 * 1024 * 1024 );

		if ( $memory_free <= $hard_margin ) {
			$this->logger->error(
				'PDF export aborted: memory pressure too high to render safely',
				array(
					'memory_used'   => size_format( $memory_used ),
					'memory_peak'   => size_format( $memory_peak ),
					'memory_limit'  => size_format( $memory_limit ),
					'memory_free'   => size_format( max( 0, $memory_free ) ),
					'hard_margin'   => size_format( $hard_margin ),
				)
			);

			return SScribe_Result::failure(
				sprintf(
					/* translators: 1: Free memory, 2: Memory limit. */
					__( 'PDF export aborted : only %1$s free of %2$s PHP memory limit. Try exporting to DOCX instead, or increase the memory_limit.', 'sscribe-export-site-pages' ),
					size_format( max( 0, $memory_free ) ),
					size_format( $memory_limit )
				),
				array(
					'error_category' => 'pdf_memory_pressure',
					'memory_free'    => max( 0, $memory_free ),
					'memory_limit'   => $memory_limit,
				)
			);
		}

		if ( $memory_free <= $soft_margin ) {
			$this->logger->warning(
				'PDF export memory pressure: soft margin reached, forcing GC',
				array(
					'memory_used'  => size_format( $memory_used ),
					'memory_peak'  => size_format( $memory_peak ),
					'memory_limit' => size_format( $memory_limit ),
					'memory_free'  => size_format( max( 0, $memory_free ) ),
					'soft_margin'  => size_format( $soft_margin ),
				)
			);

			if ( function_exists( 'gc_collect_cycles' ) ) {
				gc_collect_cycles();
			}
		}

		return null;
	}

	/**
	 * Collect paths of temporary images for cleanup.
	 *
	 * @param array $processed_page_data Processed page data.
	 * @return array List of image paths.
	 */
	private function collect_temp_image_paths( array $processed_page_data ): array {
		$paths = array();

		if ( ! empty( $processed_page_data['_temp_image_paths'] ) && is_array( $processed_page_data['_temp_image_paths'] ) ) {
			foreach ( $processed_page_data['_temp_image_paths'] as $path ) {
				if ( is_string( $path ) && '' !== $path ) {
					$paths[] = $path;
				}
			}
		}

		if ( ! empty( $processed_page_data['featured_image_url'] ) ) {
			$path = $processed_page_data['featured_image_url'];
			if ( is_string( $path ) && '' !== $path && ! in_array( $path, $paths, true ) ) {
				$paths[] = $path;
			}
		}

		return $paths;
	}

	/**
	 * Remove temporary image files.
	 *
	 * @param array $paths List of file paths to remove.
	 */
	private function cleanup_temp_images( array $paths ): void {
		foreach ( $paths as $path ) {
			SScribe_Image_Processor::cleanup( $path );
		}
	}

	/**
	 * Remove the private per-export TCPDF scratch directory.
	 *
	 * @param string $tcpdf_temp Path to TCPDF temp directory.
	 */

	/**
	 * Process images in page data and download local copies.
	 *
	 * @param array $page_data Page data to process.
	 * @return array Processed page data.
	 */
	private function process_images_in_page_data( array $page_data ): array {
		$temp_paths = array();

		if ( ! empty( $page_data['featured_image_url'] ) ) {
			$local_path = SScribe_Image_Processor::download_and_optimize( $page_data['featured_image_url'] );
			if ( $local_path && file_exists( $local_path ) ) {
				$page_data['featured_image_url'] = $local_path;
				$temp_paths[]                    = $local_path;
			}
		}

		$content = isset( $page_data['content'] ) ? (string) $page_data['content'] : '';
		if ( '' !== $content ) {
			$max_content_images = max( 0, min( 100, (int) apply_filters( 'sscribe_pdf_max_content_images', 20 ) ) );

			if ( $max_content_images > 0 ) {
				$attempts              = 0;
				$page_data['content'] = (string) preg_replace_callback(
					'/<img\b[^>]*\bsrc=("([^"]*)"|\'([^\']*)\')[^>]*>/i',
					function ( array $matches ) use ( &$attempts, $max_content_images, &$temp_paths ): string {

						$url = $matches[2] ?? ( $matches[3] ?? '' );
						if ( '' === $url || $attempts >= $max_content_images ) {
							return $matches[0];
						}

						if ( str_starts_with( $url, 'data:' ) || str_starts_with( $url, '#' ) || str_starts_with( $url, 'file://' ) ) {
							return $matches[0];
						}

						++$attempts;
						$local_path = SScribe_Image_Processor::download_and_optimize( $url );
						if ( $local_path && file_exists( $local_path ) ) {
							$temp_paths[] = $local_path;

							$replacement = str_replace(
								array( '"' . $url . '"', "'" . $url . "'" ),
								array( '"' . $local_path . '"', "'" . $local_path . "'" ),
								$matches[0]
							);
							return $replacement;
						}

						return $matches[0];
					},
					$content
				);
			}
		}

		$page_data['_temp_image_paths'] = $temp_paths;

		return $page_data;
	}

	/**
	 * Remove image sources that TCPDF must never resolve itself.
	 *
	 * Remote loading is performed only by SScribe_Image_Processor, which applies
	 * URL, host, size, redirect, and MIME checks. By this stage every retained
	 * image must therefore be a canonical regular file inside WordPress uploads.
	 * This also makes the "include images" option authoritative.
	 *
	 * @param string $html_content  Generated document HTML.
	 * @param bool   $include_images Whether images are enabled for this export.
	 * @return string HTML containing only validated local image sources.
	 */
	private function sanitize_pdf_image_sources( string $html_content, bool $include_images ): string {
		$cleaned = preg_replace_callback(
			'/<img\\b[^>]*>/i',
			static function ( array $matches ) use ( $include_images ): string {
				if ( ! $include_images ) {
					return '';
				}
				$tag = $matches[0];
				if ( 1 !== preg_match( '/\\bsrc\\s*=\\s*(?:"([^"]*)"|\'([^\']*)\'|([^\\s>]+))/i', $tag, $source_match ) ) {
					return '';
				}
				$source = '';
				foreach ( array_slice( $source_match, 1 ) as $candidate ) {
					if ( '' !== $candidate ) {
						$source = $candidate;
						break;
					}
				}
				$source    = rawurldecode( html_entity_decode( $source, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
				$canonical = SScribe_Image_Processor::validate_local_path( $source );
				if ( '' === $canonical ) {
					return '';
				}
				$tag = str_replace( $source_match[0], 'src="' . esc_attr( $canonical ) . '"', $tag );
				$tag = preg_replace( '/\\s+srcset\\s*=\\s*(?:"[^"]*"|\'[^\']*\'|[^\\s>]+)/i', '', $tag ) ?? $tag;
				return $tag;
			},
			$html_content
		);
		return is_string( $cleaned ) ? $cleaned : '';
	}

	/**
	 * Resolve the configured PDF page size to a TCPDF format string.
	 *
	 * Falls back to A4 when the user-selected value is empty or unknown,
	 * so a corrupt/legacy value can never crash the export pipeline.
	 *
	 * @return string One of A4, A3, Letter, Legal.
	 */
	private function resolve_pdf_page_size(): string {
		$allowed = array( 'A4', 'A3', 'Letter', 'Legal' );
		$value   = (string) $this->get_format_option( 'sscribe_pdf_page_size', 'A4' );
		return in_array( $value, $allowed, true ) ? $value : 'A4';
	}

	/**
	 * Create a configured TCPDF document.
	 *
	 * @param bool $is_rtl Whether the page is RTL.
	 * @return \SScribeVendor_TCPDF|SScribe_Result Configured renderer or failure result.
	 */
	private function create_tcpdf_document( bool $is_rtl ) {
		if ( ! class_exists( '\\SScribeVendor_TCPDF' ) ) {
			return SScribe_Result::failure(
				__( 'PDF export is not available because the bundled PDF renderer is missing. Please reinstall the plugin.', 'sscribe-export-site-pages' ),
				array( 'error_category' => 'pdf_missing_library' )
			);
		}
		$page_size = $this->resolve_pdf_page_size();
		if ( in_array( $page_size, array( 'Letter', 'Legal' ), true ) ) {
			$page_size = strtoupper( $page_size );
		}
		$pdf = new \SScribeVendor_TCPDF( 'P', 'mm', $page_size, true, 'UTF-8', false );
		$pdf->setPrintHeader( false );
		$pdf->setPrintFooter( '1' === (string) $this->get_format_option( 'sscribe_pdf_include_page_numbers', '1' ) );
		$pdf->SetMargins( 15, 15, 15 );
		$pdf->SetHeaderMargin( 0 );
		$pdf->SetFooterMargin( 10 );
		$pdf->SetAutoPageBreak( true, 15 );
		$pdf->setRTL( $is_rtl );
		$pdf->SetFont( 'dejavusans', '', 10, '', true );
		return $pdf;
	}

	/**
	 * Prepare HTML content for TCPDF rendering.
	 *
	 * Preserves page-level <style> blocks (Gutenberg block CSS, layout rules,
	 * media queries) so structured layouts render in PDF. Strips only the
	 * declarations that interfere with the PDF font pipeline: @font-face
	 * rules (which would override TCPDF's font selection) and @import
	 * directives (which have no meaning in PDF context).
	 *
	 * Also filters inline style attributes through a whitelist of properties
	 * that affect document semantics: text-align, page-break-*, break-*,
	 * color, background-color, border, width, height, and (for RTL only)
	 * direction. Handles BOTH single- and double-quoted style attributes.
	 *
	 * @param string $html_content Raw HTML content.
	 * @param bool   $is_rtl       Whether the page is RTL.
	 * @return string Cleaned HTML content.
	 */
	private function prepare_html_for_pdf_engine( string $html_content, bool $is_rtl ): string {
		$html_content = (string) preg_replace_callback(
			'/\\s*style=("([^"]*)"|\'([^\']*)\')/i',
			function ( array $matches ) use ( $is_rtl ): string {
				$declarations = '' !== ( $matches[2] ?? '' ) ? (string) $matches[2] : (string) ( $matches[3] ?? '' );
				$filtered     = $this->filter_style_attribute( $declarations, $is_rtl );
				return '' !== $filtered ? ' style="' . $filtered . '"' : '';
			},
			$html_content
		);
		$html_content = preg_replace( '/@font-face\\s*\\{[^}]+\\}/isU', '', $html_content ) ?? $html_content;
		$html_content = preg_replace( '/@import\\s+[^;]+;/isU', '', $html_content ) ?? $html_content;
		$html_content = preg_replace( '/url\\s*\\([^)]*\\)/i', 'none', $html_content ) ?? $html_content;
		$html_content = preg_replace( '/(?<![-a-z])font-family\\s*:[^;}]+;?/i', '', $html_content ) ?? $html_content;
		$html_content = preg_replace( '/(?<![-a-z])font\\s*:[^;}]+;?/i', '', $html_content ) ?? $html_content;
		return $html_content;
	}

	/**
	 * Filter a style attribute's declarations through the whitelist.
	 *
	 * Properties kept:
	 *  - direction (preserved always; if RTL, the page-level CSS already sets
	 *    direction:rtl, so this is a no-op for RTL pages but still useful for
	 *    LTR pages that contain a single RTL element).
	 *  - text-align, vertical-align : content alignment.
	 *  - page-break-before, page-break-after, break-before, break-after : page
	 *    break hints (CSS Paged Media).
	 *  - color, background-color, background : text and cell coloring.
	 *  - border, border-*, border-width, border-color, border-style : table
	 *    cell borders (used in pricing tables and similar layouts).
	 *  - width, height, min-width, max-width, min-height, max-height : cell
	 *    sizing (used for image dimensions and column widths).
	 *  - float, clear : table cell alignment.
	 *
	 * @param string $declarations Raw CSS declarations (e.g. "color:red; width:100%").
	 * @param bool   $is_rtl       Whether the page is RTL.
	 * @return string Filtered declarations (semicolon-terminated), or '' if none survive.
	 */
	private function filter_style_attribute( string $declarations, bool $is_rtl ): string {
		unset( $is_rtl );
		$declarations = trim( $declarations );
		if ( '' === $declarations ) {
			return '';
		}
		$allowed = array(
			'direction',
			'text-align',
			'vertical-align',
			'page-break-before',
			'page-break-after',
			'break-before',
			'break-after',
			'break-inside',
			'color',
			'background',
			'background-color',
			'border',
			'border-top',
			'border-right',
			'border-bottom',
			'border-left',
			'border-width',
			'border-color',
			'border-style',
			'border-collapse',
			'border-spacing',
			'width',
			'height',
			'min-width',
			'max-width',
			'min-height',
			'max-height',
			'float',
			'clear',
		);
		$kept = array();
		foreach ( explode( ';', $declarations ) as $part ) {
			$part = trim( $part );
			$colon = strpos( $part, ':' );
			if ( '' === $part || false === $colon ) {
				continue;
			}
			$property = strtolower( trim( substr( $part, 0, $colon ) ) );
			$value    = trim( substr( $part, $colon + 1 ) );
			if (
				in_array( $property, $allowed, true )
				&& '' !== $value
				&& 1 !== preg_match( '/(?:url\\s*\\(|expression\\s*\\(|javascript:|data:|@import)/i', $value )
			) {
				$kept[] = $property . ':' . $value;
			}
		}
		return empty( $kept ) ? '' : implode( ';', $kept ) . ';';
	}

	/**
	 * Get the file extension for PDF files.
	 *
	 * @return string File extension.
	 */
	public function get_extension(): string {
		return 'pdf';
	}

	/**
	 * Get the MIME type for PDF files.
	 *
	 * @return string MIME type.
	 */
	public function get_mime_type(): string {
		return 'application/pdf';
	}
}
