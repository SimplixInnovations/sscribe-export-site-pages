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
	 * Export a page as a PDF file.
	 *
	 * @param array  $page_data Page data to export.
	 * @param string $output_dir Output directory path.
	 * @param int    $index     Current page index.
	 * @param int    $total     Total number of pages.
	 * @return SScribe_Result Result of the export operation.
	 */
	public function export( array $page_data, string $output_dir, int $index = 0, int $total = 0 ): SScribe_Result {
		require_once SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-rtl-helper.php';
		require_once SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-image-processor.php';

		$page_id  = $page_data['id'] ?? 0;
		$title    = $page_data['title'] ?? 'Untitled';
		$language = $page_data['language'] ?? 'en';
		$is_rtl   = SScribe_RTL_Helper::is_rtl( $language );

		$include_images = '1' === (string) $this->get_format_option( 'sscribe_pdf_include_images', '1' );
		$processed_page_data = $include_images
			? $this->process_images_in_page_data( $page_data )
			: $page_data;
		$temp_image_paths    = $this->collect_temp_image_paths( $processed_page_data );

		// Use generate_html_string() — avoids .html file side-effect from export().
		$html_content = $this->html_exporter->generate_html_string( $processed_page_data );
		$html_size    = strlen( $html_content );

		$libxml_errors = array();
		$prev_errors   = null;
		$output_path   = '';
		$mpdf_temp     = '';

		try {
			if ( ! class_exists( '\\SScribeVendor\\Mpdf\\Mpdf' ) ) {
				$this->logger->error(
					'PDF export failed: mPDF class not found',
					array(
						'page_id'                => $page_id,
						'class_check'            => '\\SScribeVendor\\Mpdf\\Mpdf',
						'vendor_autoload_exists' => file_exists( SSCRIBE_PLUGIN_DIR . 'vendor/autoload.php' ),
					)
				);

				$this->cleanup_temp_images( $temp_image_paths );

				return SScribe_Result::failure(
					__( 'PDF export is not available — mPDF library is missing. Please reinstall the plugin.', 'sscribe-export-site-pages' ),
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

			$max_html_size = (int) apply_filters( 'sscribe_pdf_max_html_size', 5 * 1024 * 1024 );
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

				$this->cleanup_temp_images( $temp_image_paths );

				return SScribe_Result::failure(
					sprintf(
						/* translators: 1: HTML size, 2: Page title. */
						__( 'PDF render skipped — HTML content is too large (%1$s). To raise the limit, use the "sscribe_pdf_max_html_size" filter. Try exporting to DOCX instead, or reduce page content complexity.', 'sscribe-export-site-pages' ),
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

			// Memory pressure guard: bail out before allocating mPDF if the
			// process is already too close to memory_limit. Without this,
			// large multi-page exports accumulate per-page allocations and
			// hit OOM mid-render, producing truncated/corrupt PDFs.
			$memory_pressure = $this->check_memory_pressure();
			if ( $memory_pressure instanceof SScribe_Result ) {
				$this->cleanup_temp_images( $temp_image_paths );
				return $memory_pressure;
			}

			// libxml state must be captured AFTER the early-return checks so that
			// the finally block always restores the correct prior state.
			$prev_errors = libxml_use_internal_errors( true );

			$mpdf_config = $this->build_mpdf_config( $is_rtl, $page_id );
			if ( $mpdf_config instanceof SScribe_Result ) {
				$this->cleanup_temp_images( $temp_image_paths );
				return $mpdf_config;
			}

			list( 'config' => $config, 'mpdf_temp' => $mpdf_temp, 'xbriyaz_available' => $xbriyaz_available, 'amiri_available' => $amiri_available ) = $mpdf_config;

			$mpdf = new \SScribeVendor\Mpdf\Mpdf( $config );
			$mpdf->SetDirectionality( $is_rtl ? 'rtl' : 'ltr' );

			// sscribe_pdf_include_page_numbers: render "{PAGENO}/{nb}" in the
			// bottom-center of every page. mPDF's footer placeholders expand at
			// render time, so this is a no-op when the option is off.
			if ( '1' === (string) $this->get_format_option( 'sscribe_pdf_include_page_numbers', '1' ) ) {
				$mpdf->SetFooter( '{PAGENO}/{nb}' );
			}

			$mpdf->SetTitle( $title );
			$mpdf->SetAuthor( $page_data['author'] ?? '' );
			$mpdf->SetCreator( 'SScribe Export Plugin v' . SSCRIBE_VERSION );
			$mpdf->SetSubject( esc_html( $page_data['seo']['meta_description'] ?? '' ) );
			$mpdf->SetKeywords( esc_html( $page_data['seo']['focus_keyword'] ?? '' ) );

			$html_content = $this->prepare_html_for_mpdf( $html_content, $is_rtl );

			if ( function_exists( 'set_time_limit' ) && (int) ini_get( 'max_execution_time' ) > 0 ) {
				$max_exec = (int) ini_get( 'max_execution_time' );
				set_time_limit( max( 60, $max_exec ) ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
			}

			// Pre-render time check.
			if ( function_exists( 'microtime' ) ) {
				$max_exec    = (int) ini_get( 'max_execution_time' );
				$batch_start = $page_data['_batch_start_time'] ?? 0.0;
				if ( $batch_start > 0.0 && $max_exec > 0 ) {
					$elapsed   = microtime( true ) - $batch_start;
					$remaining = $max_exec - $elapsed;
					if ( $remaining < 20 ) {
						$this->cleanup_temp_images( $temp_image_paths );
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

			// RTL: prefer Amiri (shipped), then xbriyaz (mPDF vendor default),
			// then freeserif. LTR: mPDF's freeserif gives us a clean Latin
			// baseline without a 380 KB bundled font.
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

			// Discard any accidental output from WordPress hooks or other plugins
			// that occurred *during this export's render* (mPDF may echo notices
			// to stdout if display_errors is on), so the PDF binary is not
			// contaminated. Scope the cleanup to the render window only — we
			// capture the level before WriteHTML and clean up after Output(),
			// instead of tearing down all PHP output buffers globally (which
			// would also discard anything queued by parent callers and the
			// wider request). The cleanup runs AFTER Output() specifically so
			// any output buffer mPDF opens internally during its render
			// pipeline is also discarded — leaving it dangling would corrupt
			// output for the next request handled by the same PHP-FPM worker.
			$ob_level_before_render = ob_get_level();
			$mpdf->WriteHTML( $base_css, \SScribeVendor\Mpdf\HTMLParserMode::HEADER_CSS );

			$mpdf->WriteHTML( $html_content );

			$mpdf->Output( $output_path, \SScribeVendor\Mpdf\Output\Destination::FILE );

			while ( ob_get_level() > $ob_level_before_render ) {
				ob_end_clean();
			}

			if ( ! file_exists( $output_path ) ) {
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
						'output_path'    => $output_path,
					)
				);
			}

			$bytes_written = filesize( $output_path );

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
				sprintf(
					/* translators: 1: Error class, 2: Error message. */
					__( 'Unable to generate PDF: %1$s — %2$s', 'sscribe-export-site-pages' ),
					get_class( $e ),
					$e->getMessage()
				),
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
					'libxml_errors'   => $libxml_errors,
					'exception_class' => get_class( $e ),
					'exception_file'  => basename( $e->getFile() ) . ':' . $e->getLine(),
				)
			);
		} finally {
			$this->cleanup_temp_images( $temp_image_paths );
			$this->cleanup_mpdf_temp( $mpdf_temp );
			libxml_clear_errors();
			// Only restore if libxml state was captured (inside the try block).
			// If an early return was taken before entering the try, $prev_errors
			// is still null and there is no prior state to restore.
			if ( null !== $prev_errors ) {
				libxml_use_internal_errors( $prev_errors );
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
			// No limit set — nothing to check.
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

		// Hard limit: too close to OOM, must abort.
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
					__( 'PDF export aborted — only %1$s free of %2$s PHP memory limit. Try exporting to DOCX instead, or increase the memory_limit.', 'sscribe-export-site-pages' ),
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

		// Soft limit: warn and try to free memory before continuing.
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

		// Primary source: _temp_image_paths set by process_images_in_page_data().
		if ( ! empty( $processed_page_data['_temp_image_paths'] ) && is_array( $processed_page_data['_temp_image_paths'] ) ) {
			foreach ( $processed_page_data['_temp_image_paths'] as $path ) {
				if ( file_exists( $path ) && strpos( $path, sys_get_temp_dir() ) === 0 ) {
					$paths[] = $path;
				}
			}
		}

		// Fallback: featured_image_url if set and within temp directory.
		if ( ! empty( $processed_page_data['featured_image_url'] ) ) {
			$path = $processed_page_data['featured_image_url'];
			if ( file_exists( $path ) && strpos( $path, sys_get_temp_dir() ) === 0 && ! in_array( $path, $paths, true ) ) {
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
	 * Clean up mPDF temp directory by removing old files.
	 *
	 * @param string $mpdf_temp Path to mPDF temp directory.
	 */
	private function cleanup_mpdf_temp( string $mpdf_temp ): void {
		if ( empty( $mpdf_temp ) || ! is_dir( $mpdf_temp ) ) {
			return;
		}

		$prefix = trailingslashit( $mpdf_temp );
		$files  = glob( $prefix . '*', GLOB_NOSORT );
		$files  = is_array( $files ) ? $files : array();
		$hidden = glob( $prefix . '.[!.]*', GLOB_NOSORT );
		$hidden = is_array( $hidden ) ? $hidden : array();
		$files  = array_merge( $files, $hidden );

		if ( empty( $files ) ) {
			return;
		}

		$max_age = 5 * 60;
		$now     = time();

		foreach ( $files as $file ) {
			if ( is_file( $file ) ) {
				$mtime = filemtime( $file );
				if ( false !== $mtime && ( $now - $mtime ) > $max_age ) {
					wp_delete_file( $file );
				}
			}
		}
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

		// Also download content <img src="..."> tags and rewrite the HTML so
		// mPDF can render the image from a local file rather than fetching
		// it at render time (which fails for auth-protected, CDN-signed, or
		// Cloudflare-Access-gated URLs and adds significant render time on
		// slow hosting). Capped at sscribe_pdf_max_content_images
		// (default 20) to prevent unbounded I/O on long pages.
		$content = isset( $page_data['content'] ) ? (string) $page_data['content'] : '';
		if ( '' !== $content ) {
			$max_content_images = (int) apply_filters( 'sscribe_pdf_max_content_images', 20 );
			if ( $max_content_images < 0 ) {
				$max_content_images = 0;
			}

			if ( $max_content_images > 0 ) {
				$downloads            = 0;
				$page_data['content'] = (string) preg_replace_callback(
					'/<img\b[^>]*\bsrc=("([^"]*)"|\'([^\']*)\')[^>]*>/i',
					function ( array $matches ) use ( &$downloads, $max_content_images, &$temp_paths ): string {
						// Use null coalescing — the alternative group is
						// simply not captured when the matched src used the
						// other quote style, so $matches[2] or $matches[3]
						// can be undefined on PHP 8+ with strict notices.
						$url = $matches[2] ?? ( $matches[3] ?? '' );
						if ( '' === $url || $downloads >= $max_content_images ) {
							return $matches[0];
						}

						// Skip data: / fragment / file:// sources — they are
						// already inline or not fetchable.
						if ( str_starts_with( $url, 'data:' ) || str_starts_with( $url, '#' ) || str_starts_with( $url, 'file://' ) ) {
							return $matches[0];
						}

						$local_path = SScribe_Image_Processor::download_and_optimize( $url );
						if ( $local_path && file_exists( $local_path ) ) {
							$temp_paths[] = $local_path;
							++$downloads;
							// Rewrite only the src value, preserving the
							// original attribute quoting style.
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

		// Track all temp paths so collect_temp_image_paths() can find them.
		$page_data['_temp_image_paths'] = $temp_paths;

		return $page_data;
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
		$upload_dir = wp_upload_dir();
		$sscribe_dir = trailingslashit( $upload_dir['basedir'] ) . 'sscribe/';
		$mpdf_temp  = $sscribe_dir . 'mpdf-tmp/';

		if ( ! is_dir( $mpdf_temp ) ) {
			wp_mkdir_p( $mpdf_temp );
		}

		// Protect the parent sscribe/ directory as well as the mpdf-tmp/
		// subdirectory. On multisite, the uploads dir is per-site, so the
		// parent sscribe/ folder may not be covered by the standard WordPress
		// uploads .htaccess — site 1's mpdf-tmp/.htaccess does not protect
		// site 2's sscribe/ parent. Apache picks up the .htaccess on each
		// request, so the protection is verified before the first export.
		if ( ! file_exists( $sscribe_dir . '.htaccess' ) ) {
			SScribe_Security::protect_directory( $sscribe_dir );
		}

		// Amiri is the only Arabic font the plugin ships. If the TTF is
		// missing, the fontdata entry below falls back to a literal filename
		// that mPDF can't resolve and the rest of the pipeline silently uses
		// the next font in the stack (freeserif, then sans-serif). We log
		// a warning so site operators can spot a broken install, but we do
		// NOT hard-fail the export — losing the custom Arabic face is a
		// graceful degradation, not a stop-the-world error.
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

		if ( ! wp_is_writable( $mpdf_temp ) ) {
			return SScribe_Result::failure(
				sprintf(
					/* translators: %s: Temp directory path. */
					__( 'PDF export failed: temp directory is not writable (%s).', 'sscribe-export-site-pages' ),
					$mpdf_temp
				),
				array(
					'error_category' => 'pdf_filesystem',
					'page_id'        => $page_id,
					'temp_dir'       => $mpdf_temp,
				)
			);
		}

		if ( ! file_exists( $mpdf_temp . '/.htaccess' ) ) {
			SScribe_Security::protect_directory( $mpdf_temp );
		}

		// Nginx hosting note: protect_directory() writes both .htaccess (Apache)
		// and an index.php file (the "Silence is golden" fallback that works on
		// any web server). However, .htaccess is silently ignored on Nginx, so
		// operators on Nginx hosts should add an equivalent deny rule to their
		// server config. Surface this as a one-time debug warning so the issue
		// is visible without being noisy on every export.
		$server_software = isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['SERVER_SOFTWARE'] ) ) : '';
		if ( '' !== $server_software && false !== stripos( $server_software, 'nginx' ) ) {
			$this->logger->debug(
				'PDF export temp directory: Nginx host detected — verify Nginx config denies direct access to mpdf-tmp/ (the .htaccess is Apache-only; index.php is the cross-platform fallback).',
				array(
					'server_software'   => $server_software,
					'temp_dir'          => $mpdf_temp,
					'index_html_exists' => file_exists( $mpdf_temp . '/index.php' ),
				)
			);
		}

		$default_config = ( new \SScribeVendor\Mpdf\Config\ConfigVariables() )->getDefaults();
		$font_dirs      = $default_config['fontDir'];

		$default_font_config = ( new \SScribeVendor\Mpdf\Config\FontVariables() )->getDefaults();
		$font_data           = $default_font_config['fontdata'];

		$amiri_regular = $this->find_font_file( $amiri_dir, 'amiri[-_]?regular' ) ?? 'Amiri-Regular.ttf';
		$amiri_bold    = $this->find_font_file( $amiri_dir, 'amiri[-_]?bold' ) ?? 'Amiri-Bold.ttf';

		// DejaVuSans face used for fontdata re-pinning of every TTF
		// pruned by scripts/build-release.php `font_excludes`. Cached
		// here so each fontdata override below doesn't repeat the
		// 4-key array literal. Glyphs outside DejaVu's coverage render
		// as '?' tofu, but the export does not crash — this is the
		// contract documented on the existing freesans / freemono /
		// dejavu*condensed / sun-ext* overrides above.
		$deja_vu_sans_face = array(
			'R'  => 'DejaVuSans.ttf',
			'B'  => 'DejaVuSans-Bold.ttf',
			'I'  => 'DejaVuSans-Oblique.ttf',
			'BI' => 'DejaVuSans-BoldOblique.ttf',
		);

		// xbriyaz detection must include the plugin's own font directory.
		// The merged fontDir list is built AFTER this check, so scanning only
		// mPDF's default fontDirs would miss an xbriyaz.ttf shipped with the
		// plugin or dropped into assets/fonts/ by a site operator.
		$xbriyaz_available = false;
		$xbriyaz_search_dirs = array_merge( array( $font_dir, $amiri_dir ), $font_dirs );
		foreach ( $xbriyaz_search_dirs as $xbriyaz_dir_path ) {
			if ( file_exists( trailingslashit( $xbriyaz_dir_path ) . 'xbriyaz.ttf' ) ) {
				$xbriyaz_available = true;
				break;
			}
		}

		// RTL Arabic font resolution: prefer Amiri (shipped), then xbriyaz
		// (mPDF vendor default — can be dropped in by site operators), then
		// freeserif (always present). The Amiri file lookup above is always
		// executed (cheap scandir) so its filename flows into fontdata even
		// when the file is missing; mPDF will skip the entry and use the
		// next family in the CSS stack.
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
					// Re-pin sans/mono/condensed fontdata entries to the
					// TTFs the release ZIP actually ships. The default
					// fontdata points 'freesans' -> FreeSans.ttf,
					// 'freemono' -> FreeMono.ttf, and the DejaVu*Condensed
					// entries -> the Condensed TTF family. The build
					// script's `font_excludes` prunes all of these
					// (see scripts/build-release.php). Without these
					// overrides, ANY CSS that resolves to 'freesans',
					// 'freemono', or any name in the serif_fonts chain
					// (which begins with 'dejavuserifcondensed') crashes
					// with `Cannot find TTF TrueType font file ...`.
					// The 2026-06-24 report hit the
					// 'dejavusanscondensed' / DejaVuSansCondensed.ttf
					// path; the matching CSS-keyword path through
					// 'dejavuserifcondensed' was latent in the same
					// release and triggered here on standard theme CSS
					// (`font-family: serif` is in Twenty* core).
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
					// Sun-ExtA / Sun-ExtB are mPDF's auto-selected fonts
					// for CJK and SIP characters (see
					// LanguageToFont::getLanguageOptions which maps
					// Chinese / Korean / Japanese to 'sun-exta'). The
					// build script's `font_excludes` prunes the TTF
					// files, so any HTML containing CJK text crashes
					// the export with `Cannot find TTF TrueType font
					// file Sun-ExtA.ttf`. Re-pin to DejaVuSans (the
					// widest-coverage shipped font). Characters DejaVu
					// doesn't cover render as mPDF's internal "?"
					// tofu, but the export no longer crashes.
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
					// The fontdata entries below all point at TTFs that
					// scripts/build-release.php `font_excludes` removes from
					// the release ZIP. mPDF's fontdata key is also a valid
					// family name — mPDF's SetFont chain walker picks the
					// first name in serif_fonts / sans_fonts / mono_fonts
					// that ALSO appears in available_unifonts, which is
					// built from the fontdata keys. So even if a fonttrans
					// remap rewrites the CSS name, the family-name chain
					// can still resolve the original key, AddFont will
					// then look up the TTF listed in the fontdata entry,
					// and the export crashes with `Cannot find TTF
					// TrueType font file ... in configured font
					// directories.` for ANY character class that the
					// active font can't render and the chain walks
					// through. (The 2026-06-24 DejaVuSansCondensed.ttf
					// report was the same class; this closes every
					// fontdata entry whose TTF is excluded.)
					//
					// Pin to DejaVuSans (the widest-coverage shipped
					// font: Latin, Cyrillic, Greek, Vietnamese, IPA).
					// Glyphs outside DejaVu's coverage render as mPDF's
					// internal '?' tofu, but the export no longer
					// crashes. The named family is preserved in the
					// fonttrans map so any HTML that explicitly asks
					// for, e.g. 'Estrangelo Edessa' is rewritten to
					// 'freeserif' before the family-name chain walker
					// runs.
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
					// xbriyaz / lateef — their fontdata entries point at
					// TTFs the build script prunes. The fonttrans map
					// already rewrites the CSS name to the active RTL
					// font, but the family-name chain walker can still
					// resolve these names through the available_unifonts
					// intersection (same class as the DejaVu*Condensed
					// crash). Pin the fontdata entries to DejaVuSans so
					// AddFont never looks for the pruned TTF.
					'xbriyaz'           => $deja_vu_sans_face,
					'lateef'            => $deja_vu_sans_face,
				)
			),
			// Pin the mPDF automatic substitution chain to fonts we actually
			// ship. mPDF's defaults (`dejavusanscondensed`, `freesans`,
			// `sun-exta`, and `sun-extb` for backupSIPFont) include several
			// TTFs that are not in the release ZIP — see
			// scripts/build-release.php `font_excludes`. If a page contains a
			// character the active font can't render, mPDF would try to load
			// the first backup font, fail to find the TTF, and throw an
			// `MpdfException: Cannot find TTF TrueType font file ... in
			// configured font directories.` (reported 2026-06-24 with
			// `DejaVuSansCondensed.ttf`).
			//
			// We pin `backupSubsFont` to `['freeserif']` — which IS shipped
			// (FreeSerif.ttf covers Latin, Cyrillic, Greek, Vietnamese, and a
			// wide swath of IPA, so the vast majority of "missing glyph" cases
			// now resolve to a working font). Characters that FreeSerif also
			// doesn't cover (CJK, complex Indic, etc.) silently render as mPDF's
			// internal "?" tofu, but the PDF export no longer crashes.
			//
			// `backupSIPFont` is the SIP/Plane-2 fallback used for characters
			// above U+20000. mPDF defaults to `sun-extb`, which is also not
			// shipped. Setting it to `null` disables the SIP fallback entirely
			// — the same "?" behavior applies for those rare characters.
			'backupSubsFont'   => array( 'freeserif' ),
			'backupSIPFont'    => null,
			'isRemoteEnabled'  => true,
			// CSS-keyword remap. mPDF's setCSS resolves `font-family: serif`
			// (or `times new roman`, `georgia`, `arial`, etc.) by walking its
			// built-in `serif_fonts` / `sans_fonts` chains whose first entries
			// are `dejavuserifcondensed` / `dejavusanscondensed` — TTFs the
			// release ZIP does not ship (see scripts/build-release.php
			// `font_excludes`). Without this remap, any WordPress page that
			// uses default theme CSS (`font-family: serif` is in Twenty* core)
			// crashes the export with `MpdfException: Cannot find TTF TrueType
			// font file "DejaVuSerifCondensed.ttf"`. Pin to fonts we DO ship.
			//
			// This remap is unconditional — the previous RTL-only mapping
			// left non-RTL pages exposed to the same crash, just on the
			// CSS-resolution path instead of the backup-substitution path.
			// The original 2026-06-24 report (`DejaVuSansCondensed.ttf`) hit
			// the backup path; this remap closes the CSS-keyword path that
			// was latent in the same release.
			'fonttrans'        => array_merge(
				array(
					// CSS generic families — primary trigger.
					'serif'           => $is_rtl ? $rtl_arabic_font : 'freeserif',
					'sans-serif'      => $is_rtl ? $rtl_arabic_font : 'freesans',
					'monospace'       => 'freemono',
					// Common CSS named families that walk the *_fonts chain
					// to the same pruned DejaVu*Condensed entries.
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
				$declarations = '' !== $matches[2] ? $matches[2] : $matches[3];
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
	 *  - text-align, vertical-align — content alignment.
	 *  - page-break-before, page-break-after, break-before, break-after — page
	 *    break hints (CSS Paged Media).
	 *  - color, background-color, background — text and cell coloring.
	 *  - border, border-*, border-width, border-color, border-style — table
	 *    cell borders (used in pricing tables and similar layouts).
	 *  - width, height, min-width, max-width, min-height, max-height — cell
	 *    sizing (used for image dimensions and column widths).
	 *  - float, clear — table cell alignment.
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
	 * by the caller in this class, so they are trusted — do not pass
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
