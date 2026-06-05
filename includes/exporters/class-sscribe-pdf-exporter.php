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

		$processed_page_data = $this->process_images_in_page_data( $page_data );
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

			// libxml state must be captured AFTER the early-return checks so that
			// the finally block always restores the correct prior state.
			$prev_errors = libxml_use_internal_errors( true );

			$mpdf_config = $this->build_mpdf_config( $is_rtl, $page_id );
			if ( $mpdf_config instanceof SScribe_Result ) {
				return $mpdf_config;
			}

			list( 'config' => $config, 'mpdf_temp' => $mpdf_temp, 'xbriyaz_available' => $xbriyaz_available ) = $mpdf_config;

			$mpdf = new \SScribeVendor\Mpdf\Mpdf( $config );
			$mpdf->SetDirectionality( $is_rtl ? 'rtl' : 'ltr' );

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

			$font_stack = $is_rtl
				? ( $xbriyaz_available ? 'xbriyaz, freeserif, sans-serif' : 'freeserif, sans-serif' )
				: 'manrope, freeserif, sans-serif';
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

			// Discard any accidental output from WordPress hooks or other plugins
			// before writing the PDF binary to prevent file corruption.
			while ( ob_get_level() ) {
				ob_end_clean();
			}

			$mpdf->Output( $output_path, \SScribeVendor\Mpdf\Output\Destination::FILE );

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
						$url = '' !== $matches[2] ? $matches[2] : $matches[3];
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
	 * Find a font file matching the pattern in a directory.
	 *
	 * @param string $dir      Directory to search.
	 * @param string $pattern  Font file pattern without extension.
	 * @return string|null Font filename or null if not found.
	 */
	/**
	 * Build mPDF configuration array and validate prerequisites.
	 *
	 * Extracted from export() to keep the method focused on the rendering pipeline.
	 *
	 * @param bool $is_rtl Whether the page is RTL.
	 * @param int  $page_id Page ID for error context.
	 * @return array{config: array, mpdf_temp: string, xbriyaz_available: bool}|SScribe_Result Config array on success, failure Result on error.
	 */
	private function build_mpdf_config( bool $is_rtl, int $page_id ): array|SScribe_Result {
		$font_dir    = trailingslashit( SSCRIBE_PLUGIN_DIR ) . 'assets/fonts/';
		$manrope_dir = $font_dir . 'manrope/';
		$upload_dir  = wp_upload_dir();
		$mpdf_temp   = trailingslashit( $upload_dir['basedir'] ) . 'sscribe/mpdf-tmp/';

		if ( ! is_dir( $mpdf_temp ) ) {
			wp_mkdir_p( $mpdf_temp );
		}

		if ( ! is_dir( $manrope_dir ) ) {
			$this->logger->error(
				'PDF export failed: Manrope font files are missing',
				array(
					'manrope_dir' => $manrope_dir,
					'dir_exists'  => false,
				)
			);

			return SScribe_Result::failure(
				__( 'PDF export failed: Manrope font files are missing. Reinstall the plugin.', 'sscribe-export-site-pages' ),
				array(
					'error_category' => 'pdf_missing_library',
					'page_id'        => $page_id,
					'missing_dir'    => $manrope_dir,
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

		$manrope_regular = $this->find_font_file( $manrope_dir, 'manrope[-_]?regular' ) ?? 'Manrope-Regular.ttf';
		$manrope_bold    = $this->find_font_file( $manrope_dir, 'manrope[-_]?bold' ) ?? 'Manrope-Bold.ttf';
		$manrope_medium  = $this->find_font_file( $manrope_dir, 'manrope[-_]?medium' ) ?? 'Manrope-Medium.ttf';
		$manrope_light   = $this->find_font_file( $manrope_dir, 'manrope[-_]?light' ) ?? 'Manrope-Light.ttf';

		$xbriyaz_available = false;
		foreach ( $font_dirs as $font_dir_path ) {
			if ( file_exists( trailingslashit( $font_dir_path ) . 'xbriyaz.ttf' ) ) {
				$xbriyaz_available = true;
				break;
			}
		}

		$config = array(
			'fontDir'          => array_merge( $font_dirs, array( $manrope_dir ) ),
			'fontdata'         => array_replace(
				$font_data,
				array(
					'manrope' => array(
						'R' => $manrope_regular,
						'B' => $manrope_bold,
						'M' => $manrope_medium,
						'L' => $manrope_light,
					),
				)
			),
			'isRemoteEnabled'  => true,
			'fonttrans'        => $is_rtl ? array(
				'dejavu sans'     => $xbriyaz_available ? 'xbriyaz' : 'freeserif',
				'dejavusans'      => $xbriyaz_available ? 'xbriyaz' : 'freeserif',
				'arial'           => $xbriyaz_available ? 'xbriyaz' : 'freeserif',
				'xbriyaz'         => $xbriyaz_available ? 'xbriyaz' : 'freeserif',
				'lateef'          => $xbriyaz_available ? 'xbriyaz' : 'freeserif',
				'times new roman' => $xbriyaz_available ? 'xbriyaz' : 'freeserif',
				'serif'           => $xbriyaz_available ? 'xbriyaz' : 'freeserif',
				'sans-serif'      => $xbriyaz_available ? 'xbriyaz' : 'freeserif',
			) : array(),
			'mode'             => 'utf-8',
			'default_font'     => $is_rtl ? ( $xbriyaz_available ? 'xbriyaz' : 'freeserif' ) : 'manrope',
			'useOTL'           => 0xFF,
			'useKashida'       => 75,
			'OTLhelper'        => true,
			'autoArabic'       => true,
			'autoScriptToLang' => true,
			'autoLangToFont'   => true,
			'orientation'      => 'P',
			'format'           => 'A4',
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
		);
	}

	/**
	 * Prepare HTML content for mPDF rendering.
	 *
	 * Strips embedded <style> blocks, removes @font-face declarations that could
	 * interfere with the PDF font pipeline, and (for both RTL and LTR pages)
	 * filters inline style attributes through a whitelist of properties that
	 * affect document semantics: text-align, page-break-*, break-*, color,
	 * background-color, border, width, height, and (for RTL only) direction.
	 *
	 * Handles BOTH single- and double-quoted style attributes via the unified
	 * filter_style_attribute() helper, so single-quoted styles (e.g.
	 * style='direction:rtl' or style="text-align:center") are preserved
	 * according to the same rules.
	 *
	 * @param string $html_content Raw HTML content.
	 * @param bool   $is_rtl      Whether the page is RTL.
	 * @return string Cleaned HTML content.
	 */
	private function prepare_html_for_mpdf( string $html_content, bool $is_rtl ): string {
		$html_content = preg_replace( '/<style[^>]*>.*?<\/style>/is', '', $html_content ) ?? $html_content;

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
	 * @param string $dir      Directory to search.
	 * @param string $pattern  Font file pattern without extension.
	 * @return string|null Font filename or null if not found.
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
