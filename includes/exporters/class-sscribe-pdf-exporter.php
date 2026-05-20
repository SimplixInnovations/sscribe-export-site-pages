<?php
/**
 * SScribe PDF Exporter
 *
 * @package SScribe_Export_Site_Pages
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
	 * Flag indicating if temp directory is protected.
	 *
	 * @var bool
	 */
	private static bool $mpdf_temp_protected = false;

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
		$this->logger        = $logger ?? SScribe_Logger::instance( defined( 'SSCRIBE_DEBUG' ) && SSCRIBE_DEBUG );
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

		$html_result = $this->html_exporter->export( $processed_page_data, $output_dir, $index, $total );

		if ( $html_result->is_failure() ) {
			$this->cleanup_temp_images( $temp_image_paths );
			return $html_result;
		}

		$html_content = $html_result->get_data()['html'] ?? '';
		$html_size    = strlen( $html_content );

		$libxml_errors = array();
		$prev_errors   = libxml_use_internal_errors( true );
		$output_path   = '';

		try {
			if ( ! class_exists( '\\SScribeVendor\\Mpdf\\Mpdf' ) ) {
				$this->logger->error(
					'PDF export failed: mPDF class not found',
					array(
						'page_id'           => $page_id,
						'class_check'       => '\\SScribeVendor\\Mpdf\\Mpdf',
						'available_classes' => get_declared_classes(),
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
						'memory_usage' => memory_get_usage( true ),
						'memory_peak'  => memory_get_peak_usage( true ),
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

			$font_dir    = trailingslashit( SSCRIBE_PLUGIN_DIR ) . 'assets/fonts/';
			$manrope_dir = $font_dir . 'manrope/';
			$upload_dir  = wp_upload_dir();
			$mpdf_temp   = trailingslashit( $upload_dir['basedir'] ) . 'sscribe/mpdf-tmp/';

			if ( ! is_dir( $mpdf_temp ) ) {
				wp_mkdir_p( $mpdf_temp );
			}

			$manrope_regular = null;
			if ( is_dir( $manrope_dir ) ) {
				$font_files = scandir( $manrope_dir );
				foreach ( $font_files as $font_file ) {
					if ( preg_match( '/^manrope[-_]?regular\.ttf$/i', $font_file ) ) {
						$manrope_regular = $manrope_dir . $font_file;
						break;
					}
				}
			}

			if ( ! is_dir( $manrope_dir ) || empty( $manrope_regular ) ) {
				$this->logger->error(
					'PDF export failed: Manrope font files are missing',
					array(
						'manrope_dir'     => $manrope_dir,
						'dir_exists'      => is_dir( $manrope_dir ),
						'manrope_regular' => $manrope_regular,
						'dir_contents'    => is_dir( $manrope_dir ) ? scandir( $manrope_dir ) : array(),
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

			if ( ! self::$mpdf_temp_protected ) {
				SScribe_Security::protect_directory( $mpdf_temp );
				self::$mpdf_temp_protected = true;
			}

			$default_config = ( new \SScribeVendor\Mpdf\Config\ConfigVariables() )->getDefaults();
			$font_dirs      = $default_config['fontDir'];

			$default_font_config = ( new \SScribeVendor\Mpdf\Config\FontVariables() )->getDefaults();
			$font_data           = $default_font_config['fontdata'];

			$manrope_regular = $this->find_font_file( $manrope_dir, 'manrope[-_]?regular' ) ?? 'Manrope-Regular.ttf';

			$config = array(
				'fontDir'          => array_merge(
					$font_dirs,
					array(
						$manrope_dir,
					)
				),
				'fontdata'         => $font_data + array(
					'manrope' => array(
						'R' => $manrope_regular,
						'B' => $this->find_font_file( $manrope_dir, 'manrope[-_]?bold' ) ?? 'Manrope-Bold.ttf',
						'M' => $this->find_font_file( $manrope_dir, 'manrope[-_]?medium' ) ?? 'Manrope-Medium.ttf',
						'L' => $this->find_font_file( $manrope_dir, 'manrope[-_]?light' ) ?? 'Manrope-Light.ttf',
					),
				),

				'fonttrans'        => array(
					'dejavu sans'     => 'xbriyaz',
					'dejavusans'      => 'xbriyaz',
					'arial'           => 'xbriyaz',
					'xbriyaz'         => 'xbriyaz',
					'lateef'          => 'xbriyaz',
					'times new roman' => 'xbriyaz',
					'serif'           => 'xbriyaz',
					'sans-serif'      => 'xbriyaz',
				),
				'mode'             => 'utf-8',
				'default_font'     => $is_rtl ? 'xbriyaz' : 'manrope',
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
				'debug'            => defined( 'SSCRIBE_DEBUG' ) && SSCRIBE_DEBUG,
			);

			$mpdf = new \SScribeVendor\Mpdf\Mpdf( $config );
			$mpdf->SetDirectionality( $is_rtl ? 'rtl' : 'ltr' );

			$mpdf->SetTitle( $title );
			$mpdf->SetAuthor( $page_data['author'] ?? '' );
			$mpdf->SetCreator( 'SScribe Export Plugin v' . SSCRIBE_VERSION );
			$mpdf->SetSubject( $page_data['seo']['meta_description'] ?? '' );
			$mpdf->SetKeywords( $page_data['seo']['focus_keyword'] ?? '' );

			$html_content = preg_replace( '/<style[^>]*>.*?<\/style>/is', '', $html_content ) ?? $html_content;
			$html_content = preg_replace( '/\s*style="[^"]*"/i', '', $html_content ) ?? $html_content;
			$html_content = preg_replace( "/\s*style='[^']*'/i", '', $html_content ) ?? $html_content;

			$html_content = preg_replace( '/@font-face\s*\{[^}]+\}/isU', '', $html_content ) ?? $html_content;

			if ( function_exists( 'set_time_limit' ) ) {

				// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
				set_time_limit( 60 );
			}

			$filename    = \SScribe_Exporter_Factory::build_filename( $page_data, $index, $total, 'pdf' );
			$output_path = trailingslashit( $output_dir ) . $filename;

			$font_stack = $is_rtl ? 'xbriyaz, freeserif, sans-serif' : 'manrope, xbriyaz, sans-serif';
			$base_css   = 'html, body, div, p, span, h1, h2, h3, h4, h5, h6, table, tr, td, th, ul, ol, li, blockquote, q, cite, a { font-family: ' . $font_stack . '; }';

			if ( $is_rtl ) {
				$base_css .= ' html, body { direction: rtl; text-align: right; }';
				$base_css .= ' table { direction: rtl; border-collapse: collapse; }';
			}
			$base_css .= ' img { max-width: 100%; height: auto; }';
			$base_css .= ' a { color: #2C6E8A; text-decoration: none; }';
			$base_css .= ' h1, h2, h3, h4, h5, h6 { color: #122119; }';
			$mpdf->WriteHTML( $base_css, \SScribeVendor\Mpdf\HTMLParserMode::HEADER_CSS );

			$mpdf->WriteHTML( $html_content );
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
					'memory_usage'  => memory_get_usage( true ),
					'memory_peak'   => memory_get_peak_usage( true ),
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
					'memory_usage'    => memory_get_usage( true ),
					'memory_peak'     => memory_get_peak_usage( true ),
					'memory_limit'    => ini_get( 'memory_limit' ),
					'mpdf_available'  => true,
					'libxml_errors'   => $libxml_errors,
					'exception_class' => get_class( $e ),
					'exception_file'  => basename( $e->getFile() ) . ':' . $e->getLine(),
				)
			);
		} finally {
			$this->cleanup_temp_images( $temp_image_paths );
			libxml_clear_errors();
			libxml_use_internal_errors( $prev_errors );
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
		if ( ! empty( $processed_page_data['featured_image_url'] ) ) {
			$path = $processed_page_data['featured_image_url'];

			if ( file_exists( $path ) && strpos( $path, sys_get_temp_dir() ) === 0 ) {
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
	 * Process images in page data and download local copies.
	 *
	 * @param array $page_data Page data to process.
	 * @return array Processed page data.
	 */
	private function process_images_in_page_data( array $page_data ): array {
		if ( ! empty( $page_data['featured_image_url'] ) ) {
			$local_path = SScribe_Image_Processor::download_and_optimize( $page_data['featured_image_url'] );
			if ( $local_path && file_exists( $local_path ) ) {
				$page_data['featured_image_url'] = $local_path;
			}
		}

		return $page_data;
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
