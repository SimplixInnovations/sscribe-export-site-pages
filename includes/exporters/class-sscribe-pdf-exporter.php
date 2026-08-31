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
 * Exports pages as PDF documents using mPDF library.
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
		$libxml_errors          = array();
		$prev_errors            = null;
		$output_path            = '';
		$mpdf_temp              = '';
		$config                 = array();
		$mpdf                   = null;
		$html_content           = '';
		$font_stack             = '';
		$base_css               = '';
		$temp_image_paths       = array();
		$ob_level_before_render = ob_get_level();

		try {
			require_once SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-rtl-helper.php';
			require_once SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-image-processor.php';

			$is_rtl                 = SScribe_RTL_Helper::is_rtl( $language );
			$include_images         = '1' === (string) $this->get_format_option( 'sscribe_pdf_include_images', '1' );
			$processed_page_data    = $include_images ? $this->process_images_in_page_data( $page_data ) : $page_data;
			$temp_image_paths       = $this->collect_temp_image_paths( $processed_page_data );
			$html_content           = $this->html_exporter->generate_html_string( $processed_page_data );
			$html_content           = $this->sanitize_pdf_image_sources( $html_content, $include_images );
			$html_size              = strlen( $html_content );

			if ( ! class_exists( '\\SScribeVendor\\Mpdf\\Mpdf' ) ) {
				$this->logger->error(
					'PDF export failed: mPDF class not found',
					array(
						'page_id'                => $page_id,
						'class_check'            => '\\SScribeVendor\\Mpdf\\Mpdf',
						'vendor_autoload_exists' => file_exists( SSCRIBE_PLUGIN_DIR . 'vendor-prefixed/autoload.php' ),
					)
				);

				return SScribe_Result::failure(
					__( 'PDF export is not available : mPDF library is missing. Please reinstall the plugin.', 'sscribe-export-site-pages' ),
					array(
						'error_category' => 'pdf_missing_library',
						'page_id'        => $page_id,
						'page_title'     => $title,
						'language'       => $language,
						'fix_steps'      => array(
							__( 'Reinstall the SScribe plugin to restore the bundled mPDF library.', 'sscribe-export-site-pages' ),
							__( 'Verify the plugin upload completed successfully and no vendor files were removed.', 'sscribe-export-site-pages' ),
							__( 'If the issue persists, contact support or your hosting provider to inspect the plugin files.', 'sscribe-export-site-pages' ),
						),
					)
				);
			}

			$filtered_max_html_size = (int) apply_filters( 'sscribe_pdf_max_html_size', 5 * 1024 * 1024 );
			$max_html_size          = $filtered_max_html_size > 0
				? max( 64 * 1024, min( 50 * 1024 * 1024, $filtered_max_html_size ) )
				: 0;
			if ( $max_html_size > 0 && $html_size > $max_html_size ) {
				$this->logger->error(
					'PDF export aborted: HTML content too large for render',
					array(
						'page_id'      => $page_id,
						'html_size'    => $html_size,
						'memory_usage' => size_format( memory_get_usage( true ) ),
						'memory_peak'  => size_format( memory_get_peak_usage( true ) ),
						'memory_limit' => ini_get( 'memory_limit' ),
					)
				);

				return SScribe_Result::failure(
					sprintf(
						/* translators: %s: HTML size. */
						__( 'PDF render skipped : HTML content is too large (%1$s). To raise the limit, use the "sscribe_pdf_max_html_size" filter. Try exporting to DOCX instead, or reduce page content complexity.', 'sscribe-export-site-pages' ),
						size_format( $html_size )
					),
					array(
						'error_category' => 'pdf_memory_guard',
						'page_id'        => $page_id,
						'page_title'     => $title,
						'html_size'      => $html_size,
					)
				);
			}

			$memory_pressure = $this->check_memory_pressure();
			if ( $memory_pressure instanceof SScribe_Result ) {
				return $memory_pressure;
			}

			$prev_errors = libxml_use_internal_errors( true );

			$mpdf_config = $this->build_mpdf_config( $is_rtl, $page_id );
			if ( $mpdf_config instanceof SScribe_Result ) {
				return $mpdf_config;
			}

			list( 'config' => $config, 'mpdf_temp' => $mpdf_temp, 'xbriyaz_available' => $xbriyaz_available, 'amiri_available' => $amiri_available ) = $mpdf_config;

			$mpdf = new \SScribeVendor\Mpdf\Mpdf( $config );
			$mpdf->SetDirectionality( $is_rtl ? 'rtl' : 'ltr' );

			if ( '1' === (string) $this->get_format_option( 'sscribe_pdf_include_page_numbers', '1' ) ) {
				$mpdf->SetFooter( '{PAGENO}/{nb}' );
			}

			$mpdf->SetTitle( $title );
			$mpdf->SetAuthor( $author );
			$mpdf->SetCreator( 'SScribe Export Plugin v' . SSCRIBE_VERSION );
			$mpdf->SetSubject( esc_html( $this->normalize_scalar( $seo['meta_description'] ?? '' ) ) );
			$mpdf->SetKeywords( esc_html( $this->normalize_scalar( $seo['focus_keyword'] ?? '' ) ) );

			$html_content = $this->prepare_html_for_mpdf( $html_content, $is_rtl );

			if ( function_exists( 'set_time_limit' ) && (int) ini_get( 'max_execution_time' ) > 0 ) {
				$max_exec = (int) ini_get( 'max_execution_time' );
				set_time_limit( max( 60, $max_exec ) ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
			}

			if ( function_exists( 'microtime' ) ) {
				$max_exec    = (int) ini_get( 'max_execution_time' );
				$batch_start = isset( $page_data['_batch_start_time'] ) && is_numeric( $page_data['_batch_start_time'] )
					? (float) $page_data['_batch_start_time']
					: 0.0;
				if ( $batch_start > 0.0 && $max_exec > 0 ) {
					$elapsed   = microtime( true ) - $batch_start;
					$remaining = $max_exec - $elapsed;
					if ( $remaining < 20 ) {
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
				} elseif ( 0.0 === $batch_start ) {
					$this->logger->warning(
						'PDF time guard skipped: _batch_start_time not set in page_data',
						array(
							'page_id' => $page_id,
						)
					);
				}
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

			$font_stack = $is_rtl
				? ( $amiri_available
					? 'amiri, freeserif, sans-serif'
					: ( $xbriyaz_available ? 'xbriyaz, freeserif, sans-serif' : 'freeserif, sans-serif' )
				)
				: 'freeserif, sans-serif';
			$base_css   = 'html, body, div, p, span, h1, h2, h3, h4, h5, h6, table, tr, td, th, ul, ol, li, blockquote, q, cite, a { font-family: ' . $font_stack . '; }';

			if ( $is_rtl ) {
				$base_css .= ' html, body { direction: rtl; text-align: right; }';
				$base_css .= ' table { direction: rtl; border-collapse: collapse; }';
			}
			$base_css .= ' img { max-width: 100%; height: auto; }';
			$base_css .= ' table { width: 100% !important; table-layout: fixed; word-wrap: break-word; }';
			$base_css .= ' td, th { word-wrap: break-word; overflow-wrap: break-word; }';
			$base_css .= ' a { color: #2C6E8A; text-decoration: none; }';
			$base_css .= ' h1, h2, h3, h4, h5, h6 { color: #122119; }';

			$mpdf->WriteHTML( $base_css, \SScribeVendor\Mpdf\HTMLParserMode::HEADER_CSS );

			$mpdf->WriteHTML( $html_content );

			$mpdf->Output( $output_path, \SScribeVendor\Mpdf\Output\Destination::FILE );

			if ( is_link( $output_path ) || ! is_file( $output_path ) ) {
				$fs_error  = $this->filesystem->get_last_error();
				$fs_method = $this->filesystem->get_method();

				$this->logger->error(
					'PDF export failed: mPDF did not create output file',
					array(
						'page_id'   => $page_id,
						'path'      => $output_path,
						'fs_error'  => $fs_error,
						'fs_method' => $fs_method,
					)
				);

				return SScribe_Result::failure(
					__( 'Failed to write PDF file.', 'sscribe-export-site-pages' ),
					array(
						'error_category' => 'pdf_filesystem',
						'page_id'        => $page_id,
						'page_title'     => $title,
						'language'       => $language,
						'fs_method'      => $fs_method,
						'fs_error'       => $fs_error,
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

			$libxml_errors = $this->get_libxml_error_details();

			$this->logger->error(
				'PDF generation failed',
				array(
					'error'         => $e->getMessage(),
					'class'         => get_class( $e ),
					'page_id'       => $page_id,
					'file'          => $e->getFile(),
					'line'          => $e->getLine(),
					'html_size'     => $html_size,
					'memory_usage'  => size_format( memory_get_usage( true ) ),
					'memory_peak'   => size_format( memory_get_peak_usage( true ) ),
					'memory_limit'  => ini_get( 'memory_limit' ),
					'libxml_errors' => $libxml_errors,
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
					'memory_usage'    => size_format( memory_get_usage( true ) ),
					'memory_peak'     => size_format( memory_get_peak_usage( true ) ),
					'memory_limit'    => ini_get( 'memory_limit' ),
					'mpdf_available'  => true,
				)
			);
		} finally {
			while ( ob_get_level() > $ob_level_before_render ) {
				ob_end_clean();
			}
			$this->cleanup_temp_images( $temp_image_paths );
			$this->cleanup_mpdf_temp( $mpdf_temp );
			libxml_clear_errors();

			if ( null !== $prev_errors ) {
				libxml_use_internal_errors( $prev_errors );
			}

			$mpdf         = null;
			$html_content = null;
			unset( $config, $mpdf_temp, $font_stack, $base_css, $temp_image_paths, $libxml_errors );

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
	 * Remove the private per-export mPDF scratch directory.
	 *
	 * @param string $mpdf_temp Path to mPDF temp directory.
	 */
	private function cleanup_mpdf_temp( string $mpdf_temp ): void {
		if ( '' === $mpdf_temp || is_link( $mpdf_temp ) || ! is_dir( $mpdf_temp ) ) {
			return;
		}

		$expected_parent = untrailingslashit( wp_normalize_path( SScribe_Private_Storage::get_subdirectory( 'mpdf-tmp', false ) ) );
		$actual_parent   = untrailingslashit( wp_normalize_path( dirname( $mpdf_temp ) ) );
		if ( $expected_parent !== $actual_parent || 1 !== preg_match( '/^run-[a-f0-9]{32}$/', basename( $mpdf_temp ) ) ) {
			return;
		}

		SScribe_Security::delete_directory( $mpdf_temp );
	}

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
	 * Remove image sources that mPDF must never resolve itself.
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
			'/<img\b[^>]*>/i',
			static function ( array $matches ) use ( $include_images ): string {
				if ( ! $include_images ) {
					return '';
				}

				$tag = $matches[0];
				if ( 1 !== preg_match( '/\bsrc\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $tag, $source_match ) ) {
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

				return str_replace( $source_match[0], 'src="' . esc_attr( $canonical ) . '"', $tag );
			},
			$html_content
		);

		return is_string( $cleaned ) ? $cleaned : '';
	}

	/**
	 * Resolve the configured PDF page size to an mPDF format string.
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
	 * Build mPDF configuration array and validate prerequisites.
	 *
	 * Extracted from export() to keep the method focused on the rendering pipeline.
	 *
	 * @param bool $is_rtl Whether the page is RTL.
	 * @param int  $page_id Page ID for error context.
	 * @return array{config: array, mpdf_temp: string, xbriyaz_available: bool, amiri_available: bool}|SScribe_Result Config array on success, failure Result on error.
	 */
	private function build_mpdf_config( bool $is_rtl, int $page_id ): array|SScribe_Result {
		$font_dir   = trailingslashit( SSCRIBE_PLUGIN_DIR ) . 'assets/fonts/';
		$amiri_dir  = $font_dir . 'amiri/';
		$mpdf_temp_root = SScribe_Private_Storage::get_subdirectory( 'mpdf-tmp' );
		if ( '' === $mpdf_temp_root ) {
			return SScribe_Result::failure(
				__( 'PDF export failed: private temporary storage is unavailable.', 'sscribe-export-site-pages' ),
				array(
					'error_category' => 'pdf_filesystem',
					'page_id'        => $page_id,
				)
			);
		}
		$mpdf_temp_root = trailingslashit( $mpdf_temp_root );

		$amiri_available = is_dir( $amiri_dir ) && file_exists( $amiri_dir . 'Amiri-Regular.ttf' );
		if ( ! $amiri_available ) {
			$this->logger->warning(
				'PDF export: Amiri font files not found, falling back to mPDF default Arabic font',
				array(
					'amiri_dir'  => $amiri_dir,
					'dir_exists' => is_dir( $amiri_dir ),
				)
			);
		}

		if ( ! wp_is_writable( $mpdf_temp_root ) ) {
			return SScribe_Result::failure(
				__( 'PDF export failed: the temporary directory is not writable.', 'sscribe-export-site-pages' ),
				array(
					'error_category' => 'pdf_filesystem',
					'page_id'        => $page_id,
				)
			);
		}

		if ( ! file_exists( $mpdf_temp_root . '.htaccess' ) ) {
			SScribe_Security::protect_directory( $mpdf_temp_root );
		}

		try {
			$run_token = bin2hex( random_bytes( 16 ) );
		} catch ( \Throwable $e ) {
			$this->logger->error( 'PDF export failed: unable to generate a secure temporary-directory name' );
			return SScribe_Result::failure(
				__( 'PDF export failed: a secure temporary directory could not be created.', 'sscribe-export-site-pages' ),
				array(
					'error_category' => 'pdf_filesystem',
					'page_id'        => $page_id,
				)
			);
		}

		$mpdf_temp = $mpdf_temp_root . 'run-' . $run_token;
		if ( ! wp_mkdir_p( $mpdf_temp ) || ! wp_is_writable( $mpdf_temp ) ) {
			$this->cleanup_mpdf_temp( $mpdf_temp );
			return SScribe_Result::failure(
				__( 'PDF export failed: a writable temporary directory could not be created.', 'sscribe-export-site-pages' ),
				array(
					'error_category' => 'pdf_filesystem',
					'page_id'        => $page_id,
				)
			);
		}
		SScribe_Private_Storage::harden_directory( $mpdf_temp );

		$server_software = isset( $_SERVER['SERVER_SOFTWARE'] ) && is_string( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '';
		if ( '' !== $server_software && false !== stripos( $server_software, 'nginx' ) ) {
			$this->logger->debug(
				'PDF export temp directory: Nginx host detected : verify Nginx config denies direct access to mpdf-tmp/ (the .htaccess is Apache-only; index.php is the cross-platform fallback).',
				array(
					'server_software'   => $server_software,
					'temp_dir'          => $mpdf_temp_root,
					'index_html_exists' => file_exists( $mpdf_temp_root . 'index.php' ),
				)
			);
		}

		$default_config = ( new \SScribeVendor\Mpdf\Config\ConfigVariables() )->getDefaults();
		$font_dirs      = $default_config['fontDir'];

		$default_font_config = ( new \SScribeVendor\Mpdf\Config\FontVariables() )->getDefaults();
		$font_data           = $default_font_config['fontdata'];

		$amiri_regular = $this->find_font_file( $amiri_dir, 'amiri[-_]?regular' ) ?? 'Amiri-Regular.ttf';
		$amiri_bold    = $this->find_font_file( $amiri_dir, 'amiri[-_]?bold' ) ?? 'Amiri-Bold.ttf';

		$deja_vu_sans_face = array(
			'R'  => 'DejaVuSans.ttf',
			'B'  => 'DejaVuSans-Bold.ttf',
			'I'  => 'DejaVuSans-Oblique.ttf',
			'BI' => 'DejaVuSans-BoldOblique.ttf',
		);

		$xbriyaz_available = false;
		$xbriyaz_search_dirs = array_merge( array( $font_dir, $amiri_dir ), $font_dirs );
		foreach ( $xbriyaz_search_dirs as $xbriyaz_dir_path ) {
			if ( file_exists( trailingslashit( $xbriyaz_dir_path ) . 'xbriyaz.ttf' ) ) {
				$xbriyaz_available = true;
				break;
			}
		}

		$rtl_arabic_font = $amiri_available ? 'amiri' : ( $xbriyaz_available ? 'xbriyaz' : 'freeserif' );

		$config = array(
			'fontDir'          => array_merge( $font_dirs, array( $amiri_dir ) ),
			'fontdata'         => array_replace(
				$font_data,
				array(
					'amiri' => array(
						'R' => $amiri_regular,
						'B' => $amiri_bold,
					),

					'freesans' => array(
						'R'  => 'DejaVuSans.ttf',
						'B'  => 'DejaVuSans-Bold.ttf',
						'I'  => 'DejaVuSans-Oblique.ttf',
						'BI' => 'DejaVuSans-BoldOblique.ttf',
					),
					'freemono' => array(
						'R'  => 'DejaVuSansMono.ttf',
						'B'  => 'DejaVuSansMono-Bold.ttf',
						'I'  => 'DejaVuSansMono-Oblique.ttf',
						'BI' => 'DejaVuSansMono-BoldOblique.ttf',
					),
					'dejavuserifcondensed' => array(
						'R'  => 'DejaVuSerif.ttf',
						'B'  => 'DejaVuSerif-Bold.ttf',
						'I'  => 'DejaVuSerif-Italic.ttf',
						'BI' => 'DejaVuSerif-BoldItalic.ttf',
					),
					'dejavusanscondensed' => array(
						'R'  => 'DejaVuSans.ttf',
						'B'  => 'DejaVuSans-Bold.ttf',
						'I'  => 'DejaVuSans-Oblique.ttf',
						'BI' => 'DejaVuSans-BoldOblique.ttf',
					),

					'sun-exta' => array(
						'R'  => 'DejaVuSans.ttf',
						'B'  => 'DejaVuSans-Bold.ttf',
						'I'  => 'DejaVuSans-Oblique.ttf',
						'BI' => 'DejaVuSans-BoldOblique.ttf',
					),
					'sun-extb' => array(
						'R'  => 'DejaVuSans.ttf',
						'B'  => 'DejaVuSans-Bold.ttf',
						'I'  => 'DejaVuSans-Oblique.ttf',
						'BI' => 'DejaVuSans-BoldOblique.ttf',
					),

					'ocrb'              => $deja_vu_sans_face,
					'estrangeloedessa'  => $deja_vu_sans_face,
					'kaputaunicode'     => $deja_vu_sans_face,
					'abyssinicasil'     => $deja_vu_sans_face,
					'aboriginalsans'    => $deja_vu_sans_face,
					'jomolhari'         => $deja_vu_sans_face,
					'sundaneseunicode'  => $deja_vu_sans_face,
					'taiheritagepro'    => $deja_vu_sans_face,
					'aegean'            => $deja_vu_sans_face,
					'aegyptus'          => $deja_vu_sans_face,
					'akkadian'          => $deja_vu_sans_face,
					'quivira'           => $deja_vu_sans_face,
					'eeyekunicode'      => $deja_vu_sans_face,
					'lannaalif'         => $deja_vu_sans_face,
					'daibannasilbook'   => $deja_vu_sans_face,
					'garuda'            => $deja_vu_sans_face,
					'khmeros'           => $deja_vu_sans_face,
					'dhyana'            => $deja_vu_sans_face,
					'tharlon'           => $deja_vu_sans_face,
					'padaukbook'        => $deja_vu_sans_face,
					'zawgyi-one'        => $deja_vu_sans_face,
					'ayar'              => $deja_vu_sans_face,
					'taameydavidclm'    => $deja_vu_sans_face,
					'mph2bdamase'       => $deja_vu_sans_face,
					'lohitkannada'      => $deja_vu_sans_face,
					'pothana2000'       => $deja_vu_sans_face,

					'xbriyaz'           => $deja_vu_sans_face,
					'lateef'            => $deja_vu_sans_face,
				)
			),

			'backupSubsFont'   => array( 'freeserif' ),
			'backupSIPFont'    => null,
			// Images are resolved through SScribe_Image_Processor before mPDF
			// receives the document. Keeping mPDF networking disabled prevents
			// failed or blocked image URLs from bypassing that SSRF boundary.
			'isRemoteEnabled'  => false,

			'fonttrans'        => array_merge(
				array(

					'serif'           => $is_rtl ? $rtl_arabic_font : 'freeserif',
					'sans-serif'      => $is_rtl ? $rtl_arabic_font : 'freesans',
					'monospace'       => 'freemono',

					'times'           => $is_rtl ? $rtl_arabic_font : 'freeserif',
					'times new roman' => $is_rtl ? $rtl_arabic_font : 'freeserif',
					'georgia'         => $is_rtl ? $rtl_arabic_font : 'freeserif',
					'palatino'        => $is_rtl ? $rtl_arabic_font : 'freeserif',
					'cambria'         => $is_rtl ? $rtl_arabic_font : 'freeserif',
					'garamond'        => $is_rtl ? $rtl_arabic_font : 'freeserif',
					'bookman'         => $is_rtl ? $rtl_arabic_font : 'freeserif',
					'arial'           => $is_rtl ? $rtl_arabic_font : 'freesans',
					'helvetica'       => $is_rtl ? $rtl_arabic_font : 'freesans',
					'verdana'         => $is_rtl ? $rtl_arabic_font : 'freesans',
					'tahoma'          => $is_rtl ? $rtl_arabic_font : 'freesans',
					'trebuchet'       => $is_rtl ? $rtl_arabic_font : 'freesans',
					'trebuchet ms'    => $is_rtl ? $rtl_arabic_font : 'freesans',
					'lucida'          => $is_rtl ? $rtl_arabic_font : 'freesans',
					'lucida sans'     => $is_rtl ? $rtl_arabic_font : 'freesans',
					'courier'         => 'freemono',
					'courier new'     => 'freemono',
					'monaco'          => 'freemono',
					'consolas'        => 'freemono',
				),
				$is_rtl ? array(
					'dejavu sans'     => $rtl_arabic_font,
					'dejavusans'      => $rtl_arabic_font,
					'amiri'           => $rtl_arabic_font,
					'xbriyaz'         => $xbriyaz_available ? 'xbriyaz' : $rtl_arabic_font,
					'lateef'          => $rtl_arabic_font,
				) : array()
			),
			'mode'             => 'utf-8',
			'default_font'     => $is_rtl ? $rtl_arabic_font : 'freeserif',
			'useOTL'           => 0xFF,
			'useKashida'       => 75,
			'OTLhelper'        => true,
			'autoArabic'       => true,
			'autoScriptToLang' => true,
			'autoLangToFont'   => true,
			'orientation'      => 'P',
			'format'           => $this->resolve_pdf_page_size(),
			'margin_left'      => 15,
			'margin_right'     => 15,
			'margin_top'       => 15,
			'margin_bottom'    => 15,
			'tempDir'          => $mpdf_temp,
			'debug'            => ( defined( 'SSCRIBE_DEBUG' ) && SSCRIBE_DEBUG ),
		);

		return array(
			'config'            => $config,
			'mpdf_temp'         => $mpdf_temp,
			'xbriyaz_available' => $xbriyaz_available,
			'amiri_available'   => $amiri_available,
		);
	}

	/**
	 * Prepare HTML content for mPDF rendering.
	 *
	 * Preserves page-level <style> blocks (Gutenberg block CSS, layout rules,
	 * media queries) so structured layouts render in PDF. Strips only the
	 * declarations that interfere with the PDF font pipeline: @font-face
	 * rules (which would override mPDF's font selection) and @import
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
	private function prepare_html_for_mpdf( string $html_content, bool $is_rtl ): string {
		$html_content = (string) preg_replace_callback(
			'/\s*style=("([^"]*)"|\'([^\']*)\')/i',
			function ( array $matches ) use ( $is_rtl ): string {
				$double = isset( $matches[2] ) ? $matches[2] : '';
				$single = isset( $matches[3] ) ? $matches[3] : '';
				$declarations = '' !== $double ? $double : $single;
				$filtered     = $this->filter_style_attribute( $declarations, $is_rtl );
				return '' !== $filtered ? ' style="' . $filtered . '"' : '';
			},
			$html_content
		);

		$html_content = preg_replace( '/@font-face\s*\{[^}]+\}/isU', '', $html_content ) ?? $html_content;
		$html_content = preg_replace( '/@import\s+[^;]+;/isU', '', $html_content ) ?? $html_content;

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

		$kept  = array();
		$parts = explode( ';', $declarations );
		foreach ( $parts as $part ) {
			$part = trim( $part );
			if ( '' === $part ) {
				continue;
			}
			$colon = strpos( $part, ':' );
			if ( false === $colon ) {
				continue;
			}
			$property = strtolower( trim( substr( $part, 0, $colon ) ) );
			$value    = trim( substr( $part, $colon + 1 ) );
			if ( in_array( $property, $allowed, true ) && '' !== $value ) {
				$kept[] = $property . ':' . $value;
			}
		}

		if ( empty( $kept ) ) {
			return '';
		}

		return implode( ';', $kept ) . ';';
	}

	/**
	 * Find a font file matching the pattern in a directory.
	 *
	 * The $pattern is a PCRE fragment matched against the basename
	 * (case-insensitive) followed by ".ttf". Patterns are hard-coded
	 * by the caller in this class, so they are trusted : do not pass
	 * untrusted user input here.
	 *
	 * Example: pattern "amiri[-_]?regular" matches
	 * "Amiri-Regular.ttf", "Amiri_Regular.ttf", and
	 * "amiri-regular.ttf", but not "Amiri-Bold.ttf".
	 *
	 * @param string $dir     Directory to search.
	 * @param string $pattern PCRE fragment (no anchors, no extension).
	 * @return string|null Matching filename, or null if none found.
	 */
	private function find_font_file( string $dir, string $pattern ): ?string {
		if ( ! is_dir( $dir ) ) {
			return null;
		}

		$files = scandir( $dir );
		if ( false === $files ) {
			return null;
		}

		foreach ( $files as $file ) {
			if ( preg_match( '/^' . $pattern . '\.ttf$/i', $file ) ) {
				return $file;
			}
		}

		return null;
	}

	/**
	 * Get detailed information about libxml errors.
	 *
	 * @return array Error details.
	 */
	private function get_libxml_error_details(): array {
		$errors  = libxml_get_errors();
		$details = array();

		foreach ( $errors as $error ) {

			$details[] = array(
				'level'   => (int) $error->level,
				'code'    => (int) $error->code,
				'line'    => (int) $error->line,
				'column'  => (int) $error->column,
				'message' => trim( $error->message ),
				'file'    => (string) $error->file,
			);
		}

		return $details;
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
