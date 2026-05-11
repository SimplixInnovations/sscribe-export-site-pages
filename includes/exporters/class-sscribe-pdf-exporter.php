<?php
/**
 * PDF exporter for SScribe.
 *
 * @package SScribe
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once SSCRIBE_PLUGIN_DIR . 'includes/exporters/interface-sscribe-exporter.php';

/**
 * Class SScribe_PDF_Exporter
 *
 * Exports pages to PDF format using mPDF.
 */
class SScribe_PDF_Exporter implements SScribe_Exporter_Interface {

	/**
	 * HTML exporter instance.
	 *
	 * @var SScribe_HTML_Exporter
	 */
	private SScribe_HTML_Exporter $html_exporter;

	/**
	 * Logger instance.
	 *
	 * @var SScribe_Logger_Interface
	 */
	private SScribe_Logger_Interface $logger;

	/**
	 * Filesystem instance.
	 *
	 * @var SScribe_Filesystem
	 */
	private SScribe_Filesystem $filesystem;

	/**
	 * Whether the mpdf temp directory has been protected.
	 * Set once per request to avoid redundant file_exists() checks.
	 *
	 * @var bool
	 */
	private static bool $mpdf_temp_protected = false;

	/**
	 * Constructor.
	 *
	 * @param SScribe_HTML_Exporter|null    $html_exporter HTML exporter instance.
	 * @param SScribe_Logger_Interface|null $logger        Logger instance.
	 * @param SScribe_Filesystem|null       $filesystem    Filesystem instance.
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
	 * Export a single page to PDF.
	 *
	 * @param array  $page_data  Page data from collector.
	 * @param string $output_dir Output directory.
	 * @param int    $index      Page index.
	 * @param int    $total      Total pages.
	 * @return SScribe_Result
	 */
	public function export( array $page_data, string $output_dir, int $index = 0, int $total = 0 ): SScribe_Result {
		require_once SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-rtl-helper.php';
		require_once SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-image-processor.php';

		$page_id  = $page_data['id'] ?? 0;
		$title    = $page_data['title'] ?? 'Untitled';
		$language = $page_data['language'] ?? 'en';
		$is_rtl   = SScribe_RTL_Helper::is_rtl( $language );

		$processed_page_data = $this->process_images_in_page_data( $page_data );
		$temp_image_paths     = $this->collect_temp_image_paths( $processed_page_data );

		$html_result = $this->html_exporter->export( $processed_page_data, $output_dir, $index, $total );

		if ( $html_result->is_failure() ) {
			$this->cleanup_temp_images( $temp_image_paths );
			return $html_result;
		}

		$html_content = $html_result->get_data()['html'] ?? '';
		$html_size    = strlen( $html_content );

		$libxml_errors = array();
		$prev_errors   = libxml_use_internal_errors( true );

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

			if ( $html_size > 5 * 1024 * 1024 ) {
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
						__( 'PDF render skipped — HTML content is too large (%1$s). Try exporting to DOCX instead, or reduce page content complexity.', 'sscribe-export-site-pages' ),
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

			// Find Manrope-Regular.ttf with case-insensitive search since Linux servers
			// may have case sensitivity issues and font files may have different casing.
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

			// Validate font directories before mPDF init — if fontDir entries don't
			// exist, mPDF throws a generic exception that gets swallowed by the outer
			// catch, producing a silent failure with no file written and no useful error.
			if ( ! is_dir( $manrope_dir ) || empty( $manrope_regular ) ) {
				$this->logger->error(
					'PDF export failed: Manrope font files are missing',
					array(
						'manrope_dir'      => $manrope_dir,
						'dir_exists'       => is_dir( $manrope_dir ),
						'manrope_regular' => $manrope_regular,
						'dir_contents'     => is_dir( $manrope_dir ) ? scandir( $manrope_dir ) : array(),
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

			// Validate temp dir is writable — mPDF writes temporary files during
			// rendering. If the dir is not writable, mPDF fails silently.
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

			// Protect temp directory with .htaccess + index.php — guard prevents
			// repeated file_exists() calls on every PDF page (200 checks per 100 pages).
			if ( ! self::$mpdf_temp_protected ) {
				SScribe_Security::protect_directory( $mpdf_temp );
				self::$mpdf_temp_protected = true;
			}

			$config = array(
				// fontDir: tells mPDF where to search for TTF font files.
				// Without this, FontFileFinder cannot locate fonts even when fontdata paths are set.
				'fontDir'          => array(
					$manrope_dir,
					$font_dir . 'notosansarabic/',
				),
				'mode'             => $is_rtl ? 'ar' : 'utf-8',
				'default_font'     => $is_rtl ? 'notosansarabic' : 'manrope',
				'useOTL'           => 0xFF,
				'useKashida'       => 75,
				'fontdata'         => array(
					'manrope'        => array(
						'R'  => 'Manrope-Regular.ttf',
						'B'  => $this->find_font_file( $manrope_dir, 'manrope[-_]?bold' )
							?: 'Manrope-Bold.ttf',
						'M'  => $this->find_font_file( $manrope_dir, 'manrope[-_]?medium' )
							?: 'Manrope-Medium.ttf',
						'L'  => $this->find_font_file( $manrope_dir, 'manrope[-_]?light' )
							?: 'Manrope-Light.ttf',
					),
					'notosansarabic' => array(
						'R' => $this->find_font_file( $font_dir . 'notosansarabic/', 'notosansarabic[-_]?regular' )
							?: 'NotoSansArabic-Regular.ttf',
						'B' => $this->find_font_file( $font_dir . 'notosansarabic/', 'notosansarabic[-_]?bold' )
							?: 'NotoSansArabic-Bold.ttf',
					),
				),
				'orientation'      => 'P',
				'format'           => 'A4',
				'margin_left'      => 15,
				'margin_right'     => 15,
				'margin_top'       => 15,
				'margin_bottom'    => 15,
				'autoScriptToLang' => true,
				'autoLangToFont'   => true,
				'tempDir'          => $mpdf_temp,
				'debug'            => defined( 'SSCRIBE_DEBUG' ) && SSCRIBE_DEBUG,
				// tabSpaces omitted — mPDF defaults to 4 spaces; null causes PHP 8 strict warning.
			);

			$mpdf = new \SScribeVendor\Mpdf\Mpdf( $config );
			$mpdf->SetDirectionality( $is_rtl ? 'rtl' : 'ltr' );

			// Set PDF metadata for accessibility and DMS compatibility.
			$mpdf->SetTitle( $title );
			$mpdf->SetAuthor( $page_data['author'] ?? '' );
			$mpdf->SetCreator( 'SScribe Export Plugin v' . SSCRIBE_VERSION );
			$mpdf->SetSubject( $page_data['seo']['meta_description'] ?? '' );
			$mpdf->SetKeywords( $page_data['seo']['focus_keyword'] ?? '' );

			// Strip @font-face declarations from HTML — they contain HTTP URLs that
			// cause mPDF to attempt server-side HTTP requests to itself, which fails.
			// The fontdata config above handles font resolution via local files.
			$html_content = preg_replace( '/@font-face\s*\{[^}]+\}/is', '', $html_content );

			if ( function_exists( 'set_time_limit' ) ) {
				// phpcs:ignore WordPress.PHP.DiscouragedFunctions.Discouraged, WordPress.PHP.IniSet.max_execution_time_Blacklisted -- mPDF rendering is CPU-intensive and requires extended time per page.
				@set_time_limit( 60 );
			}

			$filename    = \SScribe_Exporter_Factory::build_filename( $page_data, $index, $total, 'pdf' );
			$output_path = trailingslashit( $output_dir ) . $filename;

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
			// CRITICAL: Remove any partial file left by a failed mPDF->Output().
			// Without this, the partial (corrupted) PDF gets included in the ZIP,
			// corrupting the entire export package.
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
	 * Collect temp image paths from processed page data for cleanup later.
	 *
	 * @param array $processed_page_data Page data with resolved image paths.
	 * @return array<string> List of temp file paths to clean up.
	 */
	private function collect_temp_image_paths( array $processed_page_data ): array {
		$paths = array();
		if ( ! empty( $processed_page_data['featured_image_url'] ) ) {
			$path = $processed_page_data['featured_image_url'];
			// Only track temp files (not uploads dir files which are permanent).
			if ( file_exists( $path ) && strpos( $path, sys_get_temp_dir() ) === 0 ) {
				$paths[] = $path;
			}
		}
		return $paths;
	}

	/**
	 * Clean up temp image files to prevent disk space accumulation.
	 *
	 * @param array<string> $paths List of temp file paths.
	 * @return void
	 */
	private function cleanup_temp_images( array $paths ): void {
		foreach ( $paths as $path ) {
			SScribe_Image_Processor::cleanup( $path );
		}
	}

	/**
	 * Process images in page data for PDF embedding.
	 *
	 * @param array $page_data The page data array.
	 * @return array Modified page data with local image paths.
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
	 * Find a font file in a directory using a case-insensitive regex pattern.
	 *
	 * @param string $dir    Directory to search.
	 * @param string $pattern Regex pattern to match font filename (without .ttf extension).
	 *
	 * @return string|null Full path to found font file, or null if not found.
	 */
	private function find_font_file( string $dir, string $pattern ): ?string {
		if ( ! is_dir( $dir ) ) {
			return null;
		}

		$files = scandir( $dir );
		foreach ( $files as $file ) {
			if ( preg_match( '/^' . $pattern . '\.ttf$/i', $file ) ) {
				return $file;
			}
		}

		return null;
	}

	/**
	 * Collect libxml errors in a serializable structure.
	 *
	 * @return array<int, array<string, int|string>>
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
	 * Get the file extension.
	 *
	 * @return string
	 */
	public function get_extension(): string {
		return 'pdf';
	}

	/**
	 * Get the mime type.
	 *
	 * @return string
	 */
	public function get_mime_type(): string {
		return 'application/pdf';
	}
}
