<?php
/**
 * SScribe Exporter.
 *
 * @package SScribe_Export_Site_Pages
 * @license GPL v2 or later
 * @link    https://www.gnu.org/licenses/gpl-2.0.html
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\Element\TextRun;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\SimpleType\Jc;
use PhpOffice\PhpWord\Shared\Converter;
use PhpOffice\PhpWord\IOFactory;

/**
 * Export orchestration and format routing.
 *
 * @package SScribe_Export_Site_Pages
 * @subpackage Exporters
 */
class SScribe_Exporter {

	/**
	 * Last error message from export operation.
	 *
	 * @var string
	 */
	private string $last_error = '';

	/**
	 * Logger instance.
	 *
	 * @var SScribe_Logger_Interface|null
	 */
	private ?SScribe_Logger_Interface $logger = null;

	/**
	 * Content parser instance.
	 *
	 * @var SScribe_Content_Parser|null
	 */
	private ?SScribe_Content_Parser $parser = null;

	/**
	 * Content renderer for DOCX elements.
	 *
	 * @var SScribe_DOCX_Content_Renderer
	 */
	private SScribe_DOCX_Content_Renderer $content_renderer;

	/**
	 * Whether the document is RTL.
	 *
	 * @var bool
	 */
	private bool $is_rtl = false;

	/**
	 * Primary font name.
	 *
	 * @var string
	 */
	private string $font_name = 'Arial';

	/**
	 * RTL font name.
	 *
	 * @var string
	 */
	private string $rtl_font_name = 'Arial Unicode MS';

	/**
	 * Base font size.
	 *
	 * @var int
	 */
	private int $font_size = 11;

	/**
	 * Color palette for document styling.
	 *
	 * @var array<string, string>
	 */
	private array $colors = array(
		'primary'  => '4A8263',
		'heading'  => '122119',
		'body'     => '495057',
		'light_bg' => 'E8EFEB',
		'link'     => '2C6E8A',
		'code_bg'  => 'F5F6F8',
		'white'    => 'FFFFFF',
		'border'   => 'CCCCCC',
	);

	/**
	 * Initialize the exporter.
	 *
	 * @param SScribe_Content_Parser|null        $parser           Content parser.
	 * @param SScribe_DOCX_Content_Renderer|null $content_renderer Content renderer.
	 */
	public function __construct(
		?SScribe_Content_Parser $parser = null,
		?SScribe_DOCX_Content_Renderer $content_renderer = null
	) {
		$this->parser = $parser ?? new SScribe_Content_Parser();

		/**
		 * Filter the DOCX color palette.
		 *
		 * @since 1.1.1
		 * @param array<string, string> $colors Color palette array.
		 */
		$this->colors = apply_filters( 'sscribe_docx_colors', $this->colors );

		// Validate required color keys exist after filter application.
		// To prevent undefined index errors from third-party mutations.
		$defaults = array(
			'primary'  => '4A8263',
			'heading'  => '122119',
			'body'     => '495057',
			'light_bg' => 'E8EFEB',
			'link'     => '2C6E8A',
			'code_bg'  => 'F5F6F8',
			'white'    => 'FFFFFF',
			'border'   => 'CCCCCC',
		);
		foreach ( $defaults as $key => $default_value ) {
			if ( ! isset( $this->colors[ $key ] ) || ! is_string( $this->colors[ $key ] ) ) {
				$this->colors[ $key ] = $default_value;
			}
		}

		$this->content_renderer = $content_renderer ?? new SScribe_DOCX_Content_Renderer(
			$this->parser,
			null,
			$this->colors,
			$this->is_rtl,
			$this->font_name,
			$this->font_size
		);

		// Sync config for injected content_renderer to ensure RTL and colors are up-to-date.
		if ( null !== $this->content_renderer ) {
			$this->content_renderer->sync_config(
				$this->colors,
				$this->is_rtl,
				$this->font_name,
				$this->font_size
			);
		}
	}

	/**
	 * Safe preg_replace wrapper.
	 *
	 * @param array|string $pattern     Regex pattern.
	 * @param array|string $replacement Replacement.
	 * @param string       $subject     Input string.
	 * @return string
	 */
	private function safe_preg_replace( array|string $pattern, array|string $replacement, string $subject ): string {
		$result = preg_replace( $pattern, $replacement, $subject );
		return is_string( $result ) ? $result : $subject;
	}

	/**
	 * Sanitize text for safe XML embedding.
	 *
	 * @param string $text Input text.
	 * @return string Sanitized text.
	 */
	private function safe_text( string $text ): string {
		$text = (string) $text;

		// Use iconv for UTF-8 sanitization - compatible with PHP 8.2+ (mb_convert_encoding deprecation).
		$cleaned = @iconv( 'UTF-8', 'UTF-8//IGNORE', $text );
		if ( false !== $cleaned ) {
			$text = $cleaned;
		}

		$text = $this->safe_preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text );

		$text = $this->safe_preg_replace( '/[\x{FFFE}\x{FFFF}]/u', '', $text );

		$text = $this->safe_preg_replace( '/[\x{FDD0}-\x{FDEF}]/u', '', $text );

		$text = $this->safe_preg_replace( '/\xED[\xA0-\xBF][\x80-\xBF]/', '', $text );

		$text = $this->safe_preg_replace( '/[\x{200B}\x{FEFF}\x{00AD}]/u', '', $text );

		$text = str_replace( array( "\r\n", "\r" ), "\n", $text );

		$text = str_replace( "\x0C", '', $text );

		// Truncate extremely long strings without spaces (e.g., long hashes, encoded data)
		// to prevent oversized XML elements in DOCX. Threshold is 2048 Unicode chars
		// to accommodate long URLs, CDNs, and affiliate links while still protecting DOCX integrity.
		if ( mb_strlen( $text, 'UTF-8' ) > 2048 && false === mb_strpos( $text, ' ', 0, 'UTF-8' ) ) {
			$text = mb_substr( $text, 0, 2048, 'UTF-8' );
		}

		// NOTE: Do NOT apply htmlspecialchars() here. PHPWord performs its own
		// XML encoding internally (PhpWord >= 1.5), so htmlspecialchars would cause
		// double-encoding. The character-stripping logic above is sufficient.

		return $text;
	}

	/**
	 * Validate and sanitize a URL.
	 *
	 * @param string $url URL to validate.
	 * @return string Sanitized URL.
	 */
	private function validate_url( string $url ): string {
		if ( empty( $url ) ) {
			return '';
		}

		if ( str_starts_with( $url, '#' ) ) {
			return $url;
		}

		if ( str_starts_with( $url, '/' ) ) {

			$parsed = wp_parse_url( site_url( $url ) );
			if ( ! $parsed ) {
				return '';
			}
			$scheme   = $parsed['scheme'] ?? 'https';
			$host     = $parsed['host'] ?? '';
			$port     = ! empty( $parsed['port'] ) ? ':' . $parsed['port'] : '';
			$path     = $parsed['path'] ?? '';
			$query    = $parsed['query'] ?? '';
			$fragment = $parsed['fragment'] ?? '';

			$safe_path  = implode(
				'/',
				array_map(
					function ( $segment ) {
						return rawurlencode( rawurldecode( $segment ) );
					},
					explode( '/', $path )
				)
			);
			$safe_query = '';
			if ( ! empty( $query ) ) {
				$params = array();
				parse_str( $query, $params );
				$safe_query = '?' . http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );
			}
			$safe_fragment = ! empty( $fragment ) ? '#' . rawurlencode( rawurldecode( $fragment ) ) : '';

			return $scheme . '://' . $host . $port . $safe_path . $safe_query . $safe_fragment;
		}

		$parsed_scheme = wp_parse_url( $url, PHP_URL_SCHEME );
		$scheme        = strtolower( ( false === $parsed_scheme || null === $parsed_scheme ) ? '' : $parsed_scheme );

		if ( in_array( $scheme, array( 'http', 'https', 'mailto', 'tel' ), true ) ) {
			// SSRF protection: validate URL doesn't point to internal/private IP ranges.
			$host = wp_parse_url( $url, PHP_URL_HOST );
			if ( $host && $this->is_ip_blocked( $host ) ) {
				$this->logger->warning(
					'Blocked SSRF attempt: internal IP range',
					array(
						'url'  => $url,
						'host' => $host,
					)
				);
				return '';
			}
			return esc_url_raw( $url );
		}

		return '';
	}

	/**
	 * Check if host is a blocked internal/private IP address.
	 *
	 * Uses a timeout-aware DNS resolution to prevent hanging on slow/-blocking DNS servers.
	 *
	 * @param string $host Hostname or IP to check.
	 * @return bool True if blocked.
	 */
	private function is_ip_blocked( string $host ): bool {
		// Check if it's already a valid public IP.
		if ( filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) !== false ) {
			return false;
		}

		// It's either a hostname or a private IP - hostname that resolves to private should be blocked.
		// Use DNS lookup with timeout to prevent hanging.
		$ip = $this->resolve_host_with_timeout( $host );
		if ( null === $ip ) {
			// Could not resolve within timeout or DNS failure.
			// Do NOT block - unresolvable hostnames are not internal.
			// Blocking valid CDN hostnames during DNS outages would cause
			// silent content stripping. Only block if we positively detect
			// a private IP after resolution.
			return false;
		}

		// Check if resolved IP is private/reserved.
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) === false ) {
			return true;
		}

		return false;
	}

	/**
	 * Resolve hostname with timeout to prevent DNS hanging.
	 *
	 * @param string $hostname Hostname to resolve.
	 * @return string|null Resolved IP or null on timeout/failure.
	 */
	private function resolve_host_with_timeout( string $hostname ): ?string {
		// Try DNS-over-HTTP first with a short timeout (3s).
		// This is the primary resolution path because gethostbyname()
		// has no timeout control and can block for 30+ seconds on
		// slow/blocked DNS servers.
		$response = wp_safe_remote_get(
			'https://dns.google/resolve?name=' . rawurlencode( $hostname ) . '&type=A',
			array(
				'timeout'    => 3,
				'user-agent' => 'SScribe-Blocklist/1.0',
			)
		);

		if ( ! is_wp_error( $response ) ) {
			$body = wp_remote_retrieve_body( $response );
			if ( ! empty( $body ) ) {
				$data = json_decode( $body, true );
				if ( is_array( $data ) && ! empty( $data['Answer'] ) ) {
					foreach ( $data['Answer'] as $answer ) {
						if ( isset( $answer['data'] ) && filter_var( $answer['data'], FILTER_VALIDATE_IP ) ) {
							return $answer['data'];
						}
					}
				}
			}
		}

		// Fallback: gethostbyname() has no timeout but is fast when it works.
		// Only use as fallback since it can hang on broken DNS.
		$ip = gethostbyname( $hostname );
		if ( $ip !== $hostname ) {
			return $ip;
		}

		return null;
	}

	/**
	 * Check if page data indicates an RTL document.
	 *
	 * @param array $page_data Page data.
	 * @return bool
	 */
	private function is_rtl_document( array $page_data ): bool {
		return SScribe_RTL_Helper::is_rtl( $page_data['language'] ?? 'en' );
	}

	/**
	 * Add complex script settings to font definition for RTL.
	 *
	 * @param array $font_def Font definition.
	 * @return array Modified font definition.
	 */
	private function with_complex_script( array $font_def ): array {
		if ( $this->is_rtl ) {
			if ( ! isset( $font_def['complexScript'] ) ) {
				$font_def['complexScript'] = true;
			}
			if ( ! isset( $font_def['rtl'] ) ) {
				$font_def['rtl'] = true;
			}
		}
		return $font_def;
	}

	/**
	 * Get paragraph style with RTL adjustments.
	 *
	 * @param array $base_style Base style array.
	 * @return array Modified style.
	 */
	private function get_para_style( array $base_style = array() ): array {
		if ( $this->is_rtl ) {
			$base_style['bidi'] = true;
			if ( ! isset( $base_style['alignment'] ) ) {
				$base_style['alignment'] = Jc::START;
			}
		}
		return $base_style;
	}

	/**
	 * Generate a DOCX file from page data.
	 *
	 * @param array  $page_data Page data array. See SScribePageData type.
	 * @type int       $id                  Page ID.
	 *     @type string    $title               Page title (HTML-decoded).
	 *     @type string    $content             Processed HTML content.
	 *     @type string    $raw_content         Raw post content.
	 *     @type string    $excerpt             Page excerpt.
	 *     @type string    $permalink           Full permalink URL.
	 *     @type string    $slug                URL-friendly slug.
	 *     @type string    $author              Author display name.
	 *     @type string    $date_published      Formatted publish date.
	 *     @type string    $date_modified       Formatted last modified date.
	 *     @type string    $featured_image_url  Featured image URL.
	 *     @type string    $featured_image_path Local path to featured image.
	 *     @type int       $word_count          Word count estimate.
	 *     @type float     $reading_time       Reading time in minutes.
	 *     @type array     $breadcrumbs         Breadcrumb trail array.
	 *     @type array     $children            Child page data array.
	 *     @type string    $language            Language code (e.g. 'en').
	 *     @type int       $parent_id          Parent page ID.
	 *     @type array     $seo                SEO data array from SEO_Reader.
	 * }
	 * @param string $output_dir Output directory path.
	 * @param int    $index     Current page index.
	 * @param int    $total     Total number of pages.
	 * @return string|false Output file path or false on failure.
	 * @throws \RuntimeException If DOCX generation fails integrity checks.
	 */
	public function generate_docx( array $page_data, string $output_dir, int $index = 0, int $total = 0 ): string|false {
		// Register shutdown handler FIRST to clean up temp files on fatal error/OOM
		// or any early return path where PHPWord may have initialized temp files.
		register_shutdown_function(
			static function (): void {
				$error = error_get_last();
				if ( $error && E_ERROR === $error['type'] ) {
					$temp_patterns = array(
						sys_get_temp_dir() . '/phpword_*.tmp',
						sys_get_temp_dir() . '/PhpWord*',
					);
					foreach ( $temp_patterns as $temp_pattern ) {
						$temp_files = glob( $temp_pattern );
						if ( is_array( $temp_files ) ) {
							foreach ( $temp_files as $temp_file ) {
								if ( is_file( $temp_file ) && is_writable( $temp_file ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
									wp_delete_file( $temp_file );
								} elseif ( is_dir( $temp_file ) ) {
									// Clean up PhpWord temp directories.
									$dir_files = glob( $temp_file . '/*' );
									if ( is_array( $dir_files ) ) {
										foreach ( $dir_files as $dir_file ) {
											if ( is_file( $dir_file ) ) {
												wp_delete_file( $dir_file );
											}
										}
									}
									// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
									@rmdir( $temp_file );
								}
							}
						}
					}
				}
			}
		);

		if ( empty( $page_data ) || ! is_dir( $output_dir ) ) {
			$this->cleanup_phpword_temp_files();
			return false;
		}

		// Verify output directory is writable before attempting file creation.
		if ( ! is_writable( $output_dir ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
			$this->last_error = 'Output directory is not writable: ' . $output_dir;
			$this->get_logger()->error(
				'DOCX generation failed: output directory not writable',
				array(
					'page_id'    => $page_data['id'] ?? 0,
					'output_dir' => $output_dir,
				)
			);
			$this->cleanup_phpword_temp_files();
			return false;
		}

		$this->last_error = '';

		$output_path = '';

		try {
			if ( ! class_exists( 'ZipArchive' ) ) {
				throw new \RuntimeException( 'The ZipArchive PHP extension is required to generate DOCX files.' );
			}

			if ( class_exists( '\SScribeVendor\PhpOffice\PhpWord\Settings' ) ) {
				\SScribeVendor\PhpOffice\PhpWord\Settings::setZipClass(
					\SScribeVendor\PhpOffice\PhpWord\Settings::ZIPARCHIVE
				);
			}

			$php_word = new PhpWord();

			$this->get_logger()->debug(
				'DOCX generation started',
				array(
					'page_id'       => $page_data['id'] ?? 0,
					'page_title'    => $page_data['title'] ?? 'unknown',
					'memory_before' => size_format( memory_get_usage( true ) ),
				)
			);

			$this->font_name = 'Arial';
			$this->is_rtl    = $this->is_rtl_document( $page_data );

			if ( $this->is_rtl ) {
				$this->font_name = $this->rtl_font_name;
			}

			$this->content_renderer->sync_config(
				$this->colors,
				$this->is_rtl,
				$this->font_name,
				$this->font_size
			);

			$this->set_document_properties( $php_word, $page_data );

			$this->set_default_styles( $php_word );

			$this->define_styles( $php_word );

			$cover = $php_word->addSection( $this->get_section_settings( $this->is_rtl ) );
			try {
				$this->add_cover_page( $cover, $page_data );
			} catch ( \Throwable $e ) {
				$this->get_logger()->warning(
					'Cover page skipped',
					array(
						'page_id'   => $page_data['id'] ?? 0,
						'exception' => get_class( $e ),
					)
				);
			}

			$content_section = $php_word->addSection( $this->get_section_settings( $this->is_rtl ) );

			try {
				$this->add_header_footer( $content_section, $page_data );
			} catch ( \Throwable $e ) {
				$this->get_logger()->warning(
					'Header/footer skipped',
					array(
						'page_id'   => $page_data['id'] ?? 0,
						'exception' => get_class( $e ),
					)
				);
			}

			$this->add_featured_image( $content_section, $page_data );

			try {
				$this->add_page_info_table( $content_section, $page_data );
			} catch ( \Throwable $e ) {
				$this->get_logger()->warning(
					'Page info table skipped',
					array(
						'page_id'   => $page_data['id'] ?? 0,
						'exception' => get_class( $e ),
					)
				);
			}

			try {
				$this->add_seo_section( $content_section, $page_data );
			} catch ( \Throwable $e ) {
				$this->get_logger()->warning(
					'SEO section skipped',
					array(
						'page_id'   => $page_data['id'] ?? 0,
						'exception' => get_class( $e ),
					)
				);
			}

			try {
				$this->add_breadcrumbs( $content_section, $page_data );
			} catch ( \Throwable $e ) {
				$this->get_logger()->warning(
					'Breadcrumbs skipped',
					array(
						'page_id'   => $page_data['id'] ?? 0,
						'exception' => get_class( $e ),
					)
				);
			}

			$this->content_renderer->add_main_content( $content_section, $page_data );

			try {
				$this->add_child_pages( $content_section, $page_data );
			} catch ( \Throwable $e ) {
				$this->get_logger()->warning(
					'Child pages skipped',
					array(
						'page_id'   => $page_data['id'] ?? 0,
						'exception' => get_class( $e ),
					)
				);
			}

			$filename    = \SScribe_Exporter_Factory::build_filename( $page_data, $index, $total, 'docx' );
			$output_path = trailingslashit( $output_dir ) . $filename;

			$writer = IOFactory::createWriter( $php_word, 'Word2007' );
			$writer->save( $output_path );

			$this->cleanup_phpword_temp_files();

			// Lightweight integrity check: verify file size > minimum threshold.
			$file_size = @filesize( $output_path );
			$min_size  = 8192; // Minimal DOCX should be at least 8KB to avoid empty/corrupted files.

			$this->get_logger()->debug(
				'DOCX saved to disk',
				array(
					'page_id'     => $page_data['id'] ?? 0,
					'output_path' => $output_path,
					'file_size'   => size_format( $file_size ),
					'memory_now'  => size_format( memory_get_usage( true ) ),
				)
			);

			if ( $file_size < $min_size ) {
				wp_delete_file( $output_path );
				unset( $writer, $php_word );
				throw new \RuntimeException( 'DOCX file size below minimum threshold' );
			}

			// Structural integrity check: always run in production to catch corrupted files.
			$zip_check = new \ZipArchive();
			if ( true !== $zip_check->open( $output_path ) ) {
				wp_delete_file( $output_path );
				unset( $writer, $php_word );
				throw new \RuntimeException( 'DOCX failed ZipArchive integrity check after write' );
			}
			$has_document = false !== $zip_check->locateName( 'word/document.xml' );
			$has_types    = false !== $zip_check->locateName( '[Content_Types].xml' );
			$zip_check->close();
			unset( $zip_check );

			if ( ! $has_document || ! $has_types ) {
				wp_delete_file( $output_path );
				unset( $writer, $php_word );
				throw new \RuntimeException( 'DOCX missing required archive members' );
			}

			if ( ! ( defined( 'SSCRIBE_DEBUG' ) && SSCRIBE_DEBUG ) ) {
				unset( $writer, $php_word );
				return $output_path;
			}

			// Deep XML validation — only in debug mode.
			$xml_valid = true;
			$zip_xml   = null;
			if ( $has_document ) {
				$zip_xml = new \ZipArchive();
				if ( true !== $zip_xml->open( $output_path ) ) {
					$zip_xml   = null;
					$xml_valid = false;
				} else {
					$doc_xml = $zip_xml->getFromName( 'word/document.xml' );
					if ( false !== $doc_xml && ! empty( $doc_xml ) ) {
						$prev_xml_errors = libxml_use_internal_errors( true );
						try {
							$test_doc     = new \DOMDocument();
							$parse_result = $test_doc->loadXML( $doc_xml );
							$xml_errors   = libxml_get_errors();
							foreach ( $xml_errors as $xml_error ) {
								if ( LIBXML_ERR_FATAL === $xml_error->level ) {
									$xml_valid = false;
									$this->get_logger()->error(
										'DOCX XML validation failed',
										array(
											'page_id'   => $page_data['id'] ?? 0,
											'xml_error' => trim( $xml_error->message ),
											'xml_line'  => $xml_error->line,
										)
									);
									break;
								}
							}
							if ( false === $parse_result ) {
								$xml_valid = false;
							}
						} finally {
							libxml_clear_errors();
							libxml_use_internal_errors( $prev_xml_errors );
						}
						unset( $test_doc, $doc_xml );
					}
				}
				if ( null !== $zip_xml ) {
					$zip_xml->close();
					$zip_xml = null;
				}
			}

			// $has_document and $has_types guaranteed true here (early throw above).
			// Only $xml_valid may be false when debug mode is enabled.
			if ( ! $xml_valid ) {
				wp_delete_file( $output_path );
				unset( $writer, $php_word );
				throw new \RuntimeException( 'DOCX integrity check failed: XML validation error' );
			}

			unset( $writer, $php_word );

			return $output_path;

		} catch ( \Throwable $e ) {
			$this->cleanup_phpword_temp_files();

			if ( isset( $writer ) ) {
				unset( $writer );
			}
			if ( isset( $php_word ) ) {
				unset( $php_word );
			}

			if ( ! empty( $output_path ) && file_exists( $output_path ) ) {
				wp_delete_file( $output_path );
			}

			$memory_context = sprintf(
				'Memory: %s used / %s limit (peak: %s)',
				size_format( memory_get_usage( true ) ),
				ini_get( 'memory_limit' ),
				size_format( memory_get_peak_usage( true ) )
			);

			$exception_class = (string) get_class( $e );
			$raw_message     = $e->getMessage();

			$error_lower = strtolower( $raw_message );
			if ( str_contains( $error_lower, 'memory' ) || str_contains( $error_lower, 'allocated' ) ) {
				$error_message = 'Memory exhausted - ' . $memory_context;
			} elseif ( ! empty( $raw_message ) ) {
				$error_message = sprintf( '%s: %s | %s', $exception_class, $raw_message, $memory_context );
			} else {

				$error_message = sprintf( '%s (no message) | %s', $exception_class, $memory_context );
			}

			$this->get_logger()->error(
				'DOCX generation failed',
				array(
					'page_id' => $page_data['id'] ?? 0,
					'error'   => $error_message,
				)
			);

			$this->last_error = $error_message;

			return false;
		}
	}

	/**
	 * Clean up PHPWord temporary files.
	 *
	 * PHPWord creates temp files during document saving that can persist
	 * if a fatal error occurs mid-write.
	 *
	 * @return void
	 */
	private function cleanup_phpword_temp_files(): void {
		$temp_pattern = sys_get_temp_dir() . '/phpword_*.tmp';
		$temp_files   = glob( $temp_pattern );

		if ( is_array( $temp_files ) ) {
			foreach ( $temp_files as $temp_file ) {
				if ( is_file( $temp_file ) && is_writable( $temp_file ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
					wp_delete_file( $temp_file );
				}
			}
		}
	}

	/**
	 * Set document metadata properties.
	 *
	 * @param PhpWord $php_word  PhpWord instance.
	 * @param array   $page_data Page data.
	 * @return void
	 */
	private function set_document_properties( PhpWord $php_word, array $page_data ): void {
		$properties = $php_word->getDocInfo();
		$properties->setCreator( 'SScribe by Simplix Innovations' );

		$blog_name         = get_bloginfo( 'name' );
		$blog_name_decoded = html_entity_decode( ( is_string( $blog_name ) ? $blog_name : '' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$properties->setCompany( $blog_name_decoded );

		$title         = $page_data['title'] ?? '';
		$title_decoded = html_entity_decode( ( is_string( $title ) ? $title : '' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$properties->setTitle( $title_decoded );

		$permalink      = $page_data['permalink'] ?? '';
		$permalink_safe = is_string( $permalink ) ? esc_url_raw( $permalink ) : '';
		$properties->setDescription( 'Exported from ' . $permalink_safe );

		$author          = $page_data['author'] ?? '';
		$author_stripped = is_string( $author ) ? wp_strip_all_tags( $author ) : '';
		$properties->setLastModifiedBy( $author_stripped );
	}

	/**
	 * Get the last error message.
	 *
	 * @return string
	 */
	public function get_last_error(): string {
		return $this->last_error;
	}

	/**
	 * Set default font and paragraph styles.
	 *
	 * @param PhpWord $php_word PhpWord instance.
	 * @return void
	 */
	private function set_default_styles( PhpWord $php_word ): void {
		$php_word->setDefaultFontName( $this->font_name );
		$php_word->setDefaultFontSize( $this->font_size );

		if ( $this->is_rtl ) {
			$php_word->setDefaultParagraphStyle(
				array(
					'spaceAfter'  => Converter::pointToTwip( 6 ),
					'spaceBefore' => Converter::pointToTwip( 2 ),
					'lineHeight'  => 1.15,
					'bidi'        => true,
				)
			);
		} else {
			$php_word->setDefaultParagraphStyle(
				array(
					'spaceAfter'  => Converter::pointToTwip( 6 ),
					'spaceBefore' => Converter::pointToTwip( 2 ),
					'lineHeight'  => 1.15,
				)
			);
		}
	}

	/**
	 * Define custom paragraph and heading styles.
	 *
	 * @param PhpWord $php_word PhpWord instance.
	 * @return void
	 */
	private function define_styles( PhpWord $php_word ): void {

		$heading_sizes = array( 24, 20, 16, 14, 12, 11 );
		for ( $i = 1; $i <= 6; $i++ ) {
			$heading_font = array(
				'name'  => $this->font_name,
				'size'  => $heading_sizes[ $i - 1 ],
				'bold'  => true,
				'color' => $this->colors['heading'],
			);
			if ( $this->is_rtl ) {
				$heading_font['bidi']          = true;
				$heading_font['rtl']           = true;
				$heading_font['complexScript'] = true;
			}
			$php_word->addTitleStyle(
				$i,
				$heading_font,
				$this->get_para_style(
					array(
						'spaceBefore' => Converter::pointToTwip( $i <= 2 ? 18 : 12 ),
						'spaceAfter'  => Converter::pointToTwip( 6 ),
						'keepNext'    => true,
					)
				)
			);
		}

		$blockquote_style = array(
			'spaceBefore' => Converter::pointToTwip( 6 ),
			'spaceAfter'  => Converter::pointToTwip( 6 ),
		);

		if ( $this->is_rtl ) {
			$blockquote_style['bidi']             = true;
			$blockquote_style['indentation']      = array( 'right' => Converter::cmToTwip( 1 ) );
			$blockquote_style['borderRightSize']  = 12;
			$blockquote_style['borderRightColor'] = $this->colors['primary'];
		} else {
			$blockquote_style['indentation']     = array( 'left' => Converter::cmToTwip( 1 ) );
			$blockquote_style['borderLeftSize']  = 12;
			$blockquote_style['borderLeftColor'] = $this->colors['primary'];
		}

		$php_word->addParagraphStyle( 'Blockquote', $this->get_para_style( $blockquote_style ) );

		$codeblock_style = array(
			'spaceBefore' => Converter::pointToTwip( 6 ),
			'spaceAfter'  => Converter::pointToTwip( 6 ),
		);

		if ( $this->is_rtl ) {
			$codeblock_style['bidi']        = true;
			$codeblock_style['indentation'] = array( 'right' => Converter::cmToTwip( 0.5 ) );
		} else {
			$codeblock_style['indentation'] = array( 'left' => Converter::cmToTwip( 0.5 ) );
		}

		// CodeBlock needs explicit complexScript and rtl for proper RTL code display.
		if ( $this->is_rtl ) {
			$codeblock_style['complexScript'] = true;
			$codeblock_style['rtl']           = true;
		}

		$php_word->addParagraphStyle( 'CodeBlock', $this->get_para_style( $codeblock_style ) );

		// Define ListBullet and ListNumber styles to ensure consistent sizing with the rest of the document.
		$list_style = array(
			'spaceBefore' => Converter::pointToTwip( 2 ),
			'spaceAfter'  => Converter::pointToTwip( 2 ),
			'lineHeight'  => 1.15,
		);
		if ( $this->is_rtl ) {
			$list_style['bidi'] = true;
		}
		$php_word->addParagraphStyle( 'ListBullet', $this->get_para_style( $list_style ) );
		$php_word->addParagraphStyle( 'ListNumber', $this->get_para_style( $list_style ) );
	}

	/**
	 * Get section settings for document layout.
	 *
	 * @param bool $is_rtl Whether RTL mode.
	 * @return array Section settings.
	 */
	private function get_section_settings( bool $is_rtl = false ): array {
		$settings = array(
			'pageSizeW'    => Converter::inchToTwip( 8.5 ),
			'pageSizeH'    => Converter::inchToTwip( 11 ),
			'marginTop'    => Converter::inchToTwip( 1 ),
			'marginBottom' => Converter::inchToTwip( 1 ),
			'marginLeft'   => Converter::inchToTwip( 1 ),
			'marginRight'  => Converter::inchToTwip( 1 ),
			'headerHeight' => Converter::inchToTwip( 0.5 ),
			'footerHeight' => Converter::inchToTwip( 0.5 ),
		);

		if ( $is_rtl ) {
			$settings['bidi'] = true;
		}

		return apply_filters( 'sscribe_docx_section_settings', $settings, $is_rtl );
	}

	/**
	 * Add cover page to the document.
	 *
	 * @param Section $section   Document section.
	 * @param array   $page_data Page data.
	 * @return void
	 */
	private function add_cover_page( Section $section, array $page_data ): void {

		$section->addTextBreak( 2 );

		$table = $section->addTable( array( 'borderSize' => 0 ) );
		$table->addRow();
		$cell = $table->addCell(
			Converter::inchToTwip( 6.5 ),
			array(
				'bgColor' => $this->colors['primary'],
				'valign'  => 'center',
			)
		);
		$cell->addText(
			$this->safe_text(
				sprintf(
					/* translators: %s: site name */

					__( '%s | EXTERNAL AUDIT AND DOCUMENTATION', 'sscribe-export-site-pages' ),
					html_entity_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' )
				)
			),
			array(
				'name'  => $this->font_name,
				'size'  => 10,
				'color' => $this->colors['white'],
				'bold'  => true,
			),
			$this->get_para_style(
				array(
					'alignment'   => Jc::CENTER,
					'spaceBefore' => Converter::pointToTwip( 12 ),
					'spaceAfter'  => Converter::pointToTwip( 12 ),
				)
			)
		);

		$section->addTextBreak( 4 );

		$cover_title = $this->safe_text( (string) ( $page_data['title'] ?? '' ) );
		if ( ! $this->is_rtl ) {
			$cover_title = function_exists( 'mb_strtoupper' )
				? mb_strtoupper( $cover_title, 'UTF-8' )
				: strtoupper( $cover_title );
		}
		$section->addText(
			$cover_title,
			$this->with_complex_script(
				array(
					'name'  => $this->font_name,
					'size'  => 28,
					'bold'  => true,
					'color' => $this->colors['heading'],
				)
			),
			$this->get_para_style( array( 'alignment' => Jc::CENTER ) )
		);

		$section->addTextBreak( 1 );

		$permalink = $this->validate_url( $page_data['permalink'] );
		if ( ! empty( $permalink ) ) {
			$section->addLink(
				$permalink,
				$this->safe_text( rawurldecode( $permalink ) ),
				array(
					'name'      => $this->font_name,
					'size'      => 12,
					'color'     => $this->colors['link'],
					'underline' => 'single',
				),
				$this->get_para_style( array( 'alignment' => Jc::CENTER ) )
			);
		}

		$section->addTextBreak( 2 );

		$info_table = $section->addTable(
			array(
				'borderSize'  => 12,
				'borderColor' => $this->colors['border'],
				'cellMargin'  => Converter::cmToTwip( 0.2 ),
			)
		);
		$info_table->addRow();

		$meta_cell = $info_table->addCell( Converter::inchToTwip( 6.5 ), array( 'bgColor' => $this->colors['light_bg'] ) );
		$meta_cell->addText(
			__( 'DOCUMENT BLUEPRINT OVERVIEW', 'sscribe-export-site-pages' ),
			array(
				'name'  => $this->font_name,
				'size'  => 11,
				'bold'  => true,
				'color' => $this->colors['heading'],
			),
			$this->get_para_style( array( 'alignment' => Jc::CENTER ) )
		);
		$lang_display = ! empty( $page_data['language'] ) ? strtoupper( $page_data['language'] ) : __( 'All Languages', 'sscribe-export-site-pages' );
		$meta_cell->addText(
			/* translators: %s: language code */

			sprintf( __( 'Target Language: %s', 'sscribe-export-site-pages' ), $lang_display ),
			array(
				'name'  => $this->font_name,
				'size'  => 10,
				'color' => $this->colors['body'],
			),
			$this->get_para_style( array( 'alignment' => Jc::CENTER ) )
		);
		$meta_cell->addText(
			/* translators: %s: export date */

			sprintf( __( 'Extracted Date: %s', 'sscribe-export-site-pages' ), wp_date( ( get_option( 'date_format' ) ? get_option( 'date_format' ) : 'Y-m-d' ) . ' ' . ( get_option( 'time_format' ) ? get_option( 'time_format' ) : 'H:i' ) ) ),
			array(
				'name'  => $this->font_name,
				'size'  => 10,
				'color' => $this->colors['body'],
			),
			$this->get_para_style( array( 'alignment' => Jc::CENTER ) )
		);

		if ( ! empty( $page_data['breadcrumbs'] ) ) {
			$breadcrumb_text = implode(
				' > ',
				array_map(
					function ( $c ) {
						return $this->safe_text( $c['title'] ?? '' );
					},
					$page_data['breadcrumbs']
				)
			);
			$meta_cell->addTextBreak( 1 );
			$meta_cell->addText(
				$this->safe_text(
					/* translators: %s: breadcrumb path */

					sprintf( __( 'Site Path: %s', 'sscribe-export-site-pages' ), $breadcrumb_text )
				),
				$this->with_complex_script(
					array(
						'name'   => $this->font_name,
						'size'   => 9,
						'color'  => $this->colors['body'],
						'italic' => true,
					)
				),
				$this->get_para_style( array( 'alignment' => Jc::CENTER ) )
			);
		}

		// Skip TOC if page content is minimal (single section or very few headings).
		// A table of contents with a single entry is pointless and wastes a page.
		$content       = $page_data['content'] ?? '';
		$word_count    = $page_data['word_count'] ?? 0;
		$heading_count = 0;
		if ( function_exists( 'preg_match_all' ) ) {
			preg_match_all( '/<h[1-6][^>]*>/i', $content, $heading_matches );
			$heading_count = count( $heading_matches[0] );
		}
		if ( $heading_count <= 1 || $word_count < 150 ) {
			return;
		}

		$section->addPageBreak();

		$section->addText(
			__( 'TABLE OF CONTENTS', 'sscribe-export-site-pages' ),
			array(
				'name'  => $this->font_name,
				'size'  => 16,
				'bold'  => true,
				'color' => $this->colors['heading'],
			),
			$this->get_para_style( array( 'spaceAfter' => Converter::pointToTwip( 12 ) ) )
		);

		$tab_leader = defined( '\\SScribeVendor\\PhpOffice\\PhpWord\\Style\\TOC::TAB_LEADER_DOT' )
			? \SScribeVendor\PhpOffice\PhpWord\Style\TOC::TAB_LEADER_DOT
			: 'dot';

		try {
			$section->addTOC(
				array(
					'name' => $this->font_name,
					'size' => 11,
				),
				array( 'tabLeader' => $tab_leader ),
				1,
				3
			);
		} catch ( \Throwable $e ) {
			$this->get_logger()->warning(
				'TOC generation failed, omitting table of contents',
				array(
					'error' => $e->getMessage(),
					'font'  => $this->font_name,
				)
			);

			$section->addText(
				'[Table of Contents could not be generated]',
				array(
					'name'   => $this->font_name,
					'size'   => 10,
					'italic' => true,
					'color'  => '888888',
				),
				$this->get_para_style( array( 'spaceBefore' => Converter::pointToTwip( 6 ) ) )
			);
		}

		try {
			$section->addText(
				/* translators: This appears below the TOC placeholder in DOCX files. */

				__( 'Right-click above and select "Update Field" to generate the Table of Contents.', 'sscribe-export-site-pages' ),
				array(
					'name'   => $this->font_name,
					'size'   => 9,
					'italic' => true,
					'color'  => '888888',
				),
				$this->get_para_style( array( 'spaceBefore' => Converter::pointToTwip( 4 ) ) )
			);
		} catch ( \Throwable $e ) {

			unset( $e );
		}

		$section->addPageBreak();
	}

	/**
	 * Add header and footer to document section.
	 *
	 * @param Section $section   Document section.
	 * @param array   $page_data Page data.
	 * @return void
	 */
	private function add_header_footer( Section $section, array $page_data ): void {

		$header       = $section->addHeader();
		$header_table = $header->addTable();
		$header_table->addRow();
		$header_table->addCell( Converter::inchToTwip( 3.25 ) )->addText(
			$this->safe_text( html_entity_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ),
			array(
				'name'  => $this->font_name,
				'size'  => 8,
				'color' => $this->colors['primary'],
				'bold'  => true,
			)
		);
		$header_table->addCell( Converter::inchToTwip( 3.25 ) )->addText(
			$this->safe_text( $page_data['title'] ),
			array(
				'name'   => $this->font_name,
				'size'   => 8,
				'color'  => $this->colors['body'],
				'italic' => true,
			),
			array( 'alignment' => $this->is_rtl ? Jc::START : Jc::END )
		);

		$footer       = $section->addFooter();
		$footer_table = $footer->addTable();
		$footer_table->addRow();
		$footer_table->addCell( Converter::inchToTwip( 4 ) )->addText(
			$this->safe_text( rawurldecode( $page_data['permalink'] ) ),
			array(
				'name'  => $this->font_name,
				'size'  => 7,
				'color' => $this->colors['body'],
			)
		);
		$cell = $footer_table->addCell( Converter::inchToTwip( 2.5 ) );
		$cell->addPreserveText(
			/* translators: %s: page number field (e.g. "Page 1 / 10") */
			sprintf( __( 'Page %s', 'sscribe-export-site-pages' ), '{PAGE} / {NUMPAGES}' ),
			array(
				'name'  => $this->font_name,
				'size'  => 7,
				'color' => $this->colors['body'],
			),
			array( 'alignment' => $this->is_rtl ? Jc::START : Jc::END )
		);
	}

	/**
	 * Add featured image to document section.
	 *
	 * @param Section $section   Document section.
	 * @param array   $page_data Page data.
	 * @return void
	 */
	private function add_featured_image( Section $section, array $page_data ): void {
		if ( empty( $page_data['featured_image_path'] ) ) {
			$this->get_logger()->warning(
				'Featured image skipped: path not provided',
				array(
					'page_id' => $page_data['id'] ?? 0,
				)
			);
			return;
		}

		$path = $page_data['featured_image_path'];

		try {
			if ( ! is_readable( $path ) ) {
				$this->get_logger()->warning(
					'Featured image skipped: not readable',
					array(
						'page_id' => $page_data['id'] ?? 0,
						'path'    => $path,
					)
				);
				return;
			}
			$image_info = getimagesize( $path );
			if ( ! $image_info ) {
				$this->get_logger()->warning(
					'Featured image skipped: getimagesize failed',
					array(
						'page_id' => $page_data['id'] ?? 0,
						'path'    => $path,
					)
				);
				return;
			}

			$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
			if ( in_array( $ext, array( 'svg', 'webp', 'avif', 'heic', 'heif' ), true ) ) {
				$this->get_logger()->debug( 'Skipping unsupported image format', array( 'ext' => $ext ) );
				return;
			}

			$max_width  = Converter::inchToEmu( 6.5 );
			$max_height = Converter::inchToEmu( 4 );

			$width_emu  = Converter::pixelToEmu( $image_info[0] );
			$height_emu = Converter::pixelToEmu( $image_info[1] );

			if ( 0 === $width_emu || 0 === $height_emu ) {
				$this->get_logger()->debug(
					'Featured image has zero dimensions, using original size',
					array(
						'path'   => $page_data['featured_image_path'],
						'width'  => $image_info[0],
						'height' => $image_info[1],
					)
				);
			} else {
				$ratio      = $max_width / $width_emu;
				$width_emu  = $max_width;
				$height_emu = (int) ( $height_emu * $ratio );

				if ( $height_emu > $max_height ) {
					$ratio      = $max_height / $height_emu;
					$height_emu = $max_height;
					$width_emu  = (int) ( $width_emu * $ratio );
				}
			}

			$section->addImage(
				$page_data['featured_image_path'],
				array(
					'width'     => Converter::emuToPixel( $width_emu ),
					'height'    => Converter::emuToPixel( $height_emu ),
					'alignment' => Jc::CENTER,
				)
			);

			$section->addTextBreak( 1 );

		} catch ( \Throwable $e ) {
			$this->get_logger()->warning(
				'Featured image skipped due to error',
				array(
					'page_id'   => $page_data['id'] ?? 0,
					'path'      => $path,
					'exception' => get_class( $e ),
					'message'   => $e->getMessage(),
				)
			);

			return;
		}
	}

	/**
	 * Add page information table to document.
	 *
	 * @param Section $section   Document section.
	 * @param array   $page_data Page data.
	 * @return void
	 */
	private function add_page_info_table( Section $section, array $page_data ): void {
		$section->addTitle( __( 'Page Information', 'sscribe-export-site-pages' ), 2 );

		$table_style = array(
			'borderSize'  => 1,
			'borderColor' => $this->colors['border'],
			'cellMargin'  => Converter::cmToTwip( 0.15 ),
		);

		$label_style = $this->with_complex_script(
			array(
				'name'  => $this->font_name,
				'size'  => 10,
				'bold'  => true,
				'color' => $this->colors['heading'],
			)
		);

		$value_style = $this->with_complex_script(
			array(
				'name'  => $this->font_name,
				'size'  => 10,
				'color' => $this->colors['body'],
			)
		);

		$header_cell_style = array(
			'bgColor' => $this->colors['light_bg'],
		);

		$table = $section->addTable( $table_style );

		$info_rows = array(
			array( __( 'URL', 'sscribe-export-site-pages' ), $page_data['permalink'] ?? '' ),
			array( __( 'Author', 'sscribe-export-site-pages' ), $page_data['author'] ?? '' ),
			array( __( 'Published', 'sscribe-export-site-pages' ), $page_data['date_published'] ?? '' ),
			array( __( 'Last Modified', 'sscribe-export-site-pages' ), $page_data['date_modified'] ?? '' ),
			array( __( 'Word Count', 'sscribe-export-site-pages' ), number_format( (int) ( $page_data['word_count'] ?? 0 ) ) ),
			array(
				__( 'Reading Time', 'sscribe-export-site-pages' ),
				/* translators: %d: number of minutes */

				sprintf( _n( '%d minute', '%d minutes', (int) ceil( (float) ( $page_data['reading_time'] ?? 0 ) ), 'sscribe-export-site-pages' ), (int) ceil( (float) ( $page_data['reading_time'] ?? 0 ) ) ),
			),
		);

		foreach ( $info_rows as $row ) {
			$table->addRow();
			$table->addCell( Converter::inchToTwip( 2 ), $header_cell_style )->addText(
				$this->safe_text( $row[0] ),
				$label_style,
				$this->get_para_style()
			);
			$table->addCell( Converter::inchToTwip( 4.5 ) )->addText(
				$this->safe_text( $row[1] ),
				$value_style,
				$this->get_para_style()
			);
		}

		$section->addTextBreak( 1 );
	}

	/**
	 * Add SEO information section to document.
	 *
	 * @param Section $section   Document section.
	 * @param array   $page_data Page data.
	 * @return void
	 */
	private function add_seo_section( Section $section, array $page_data ): void {
		$seo_data = ! empty( $page_data['seo'] ) ? $page_data['seo'] : array();

		if ( empty( $seo_data['meta_title'] ) && empty( $seo_data['meta_description'] ) && empty( $seo_data['focus_keyword'] ) ) {
			return;
		}

		$section->addTitle( __( 'SEO Information', 'sscribe-export-site-pages' ), 2 );

		if ( ! empty( $seo_data['source'] ) ) {
			$section->addText(
				/* translators: %s: SEO plugin name */

				sprintf( __( 'Source: %s', 'sscribe-export-site-pages' ), $seo_data['source'] ),
				array(
					'name'   => $this->font_name,
					'size'   => 9,
					'italic' => true,
					'color'  => $this->colors['body'],
				),
				$this->get_para_style()
			);
		}

		$table_style = array(
			'borderSize'  => 1,
			'borderColor' => $this->colors['border'],
			'cellMargin'  => Converter::cmToTwip( 0.15 ),
		);

		$label_style = array(
			'name'  => $this->font_name,
			'size'  => 10,
			'bold'  => true,
			'color' => $this->colors['heading'],
		);

		$value_style = array(
			'name'  => $this->font_name,
			'size'  => 10,
			'color' => $this->colors['body'],
		);

		$table = $section->addTable( $table_style );

		$seo_rows = array(
			array( __( 'Meta Title', 'sscribe-export-site-pages' ), $seo_data['meta_title'] ),
			array( __( 'Meta Description', 'sscribe-export-site-pages' ), $seo_data['meta_description'] ),
			array( __( 'Focus Keyword', 'sscribe-export-site-pages' ), $seo_data['focus_keyword'] ),
		);

		foreach ( $seo_rows as $row ) {
			if ( ! empty( $row[1] ) ) {
				$table->addRow();
				$table->addCell( Converter::inchToTwip( 2 ), array( 'bgColor' => $this->colors['light_bg'] ) )->addText(
					$this->safe_text( $row[0] ),
					$label_style,
					$this->get_para_style()
				);
				$table->addCell( Converter::inchToTwip( 4.5 ) )->addText(
					$this->safe_text( $row[1] ),
					$value_style,
					$this->get_para_style()
				);
			}
		}

		$section->addTextBreak( 1 );
	}

	/**
	 * Add breadcrumbs to document.
	 *
	 * @param Section $section   Document section.
	 * @param array   $page_data Page data.
	 * @return void
	 */
	private function add_breadcrumbs( Section $section, array $page_data ): void {
		if ( empty( $page_data['breadcrumbs'] ) || count( $page_data['breadcrumbs'] ) <= 1 ) {
			return;
		}

		$breadcrumb_text = implode(
			' > ',
			array_map(
				function ( $crumb ) {
					return $this->safe_text( $crumb['title'] ?? '' );
				},
				$page_data['breadcrumbs']
			)
		);

		$section->addText(
			$this->safe_text(
				sprintf(
					/* translators: %s: breadcrumb path */

					__( 'Path: %s', 'sscribe-export-site-pages' ),
					$breadcrumb_text
				)
			),
			$this->with_complex_script(
				array(
					'name'   => $this->font_name,
					'size'   => 9,
					'italic' => true,
					'color'  => $this->colors['body'],
				)
			),
			$this->get_para_style()
		);

		$section->addTextBreak( 1 );
	}

	/**
	 * Add child pages section to document.
	 *
	 * @param Section $section   Document section.
	 * @param array   $page_data Page data.
	 * @return void
	 */
	private function add_child_pages( Section $section, array $page_data ): void {
		if ( empty( $page_data['children'] ) ) {
			return;
		}

		$section->addTextBreak( 1 );
		$section->addTitle( __( 'Child Pages', 'sscribe-export-site-pages' ), 2 );

		// Cap at 3 levels deep and limit total children to prevent abnormally long lists.
		$max_depth    = 3;
		$max_children = 50;
		$this->render_child_pages( $section, $page_data['children'], 0, $max_depth, $max_children, 0 );
	}

	/**
	 * Render child pages recursively with depth limit.
	 *
	 * @param Section $section      Document section.
	 * @param array   $children     Children array.
	 * @param int     $depth        Current depth.
	 * @param int     $max_depth    Maximum depth allowed.
	 * @param int     $max_children Maximum total children to render.
	 * @param int     $rendered     Count of rendered children.
	 * @return int Total rendered count.
	 */
	private function render_child_pages(
		Section $section,
		array $children,
		int $depth,
		int $max_depth,
		int $max_children,
		int $rendered
	): int {
		if ( $depth >= $max_depth || $rendered >= $max_children ) {
			return $rendered;
		}

		foreach ( $children as $child ) {
			if ( $rendered >= $max_children ) {
				break;
			}

			$child_url = $this->validate_url( $child['url'] ?? '' );
			if ( empty( $child_url ) ) {
				continue;
			}

			$indent   = str_repeat( '  ', $depth );
			$text_run = $section->addTextRun( $this->get_para_style() );
			$text_run->addText(
				$indent . '> ',
				array(
					'size'  => 10,
					'bold'  => true,
					'color' => $this->colors['primary'],
				)
			);
			$text_run->addLink(
				$child_url,
				$this->safe_text( $child['title'] ?? '' ),
				array(
					'name'  => $this->font_name,
					'size'  => $this->font_size,
					'color' => $this->colors['link'],
				)
			);
			$text_run->addText(
				' — ' . $this->safe_text( $child['url'] ),
				array(
					'name'  => $this->font_name,
					'size'  => 8,
					'color' => $this->colors['body'],
				)
			);
			++$rendered;

			// Recursively render grandchildren.
			if ( ! empty( $child['children'] ) && is_array( $child['children'] ) ) {
				$rendered = $this->render_child_pages( $section, $child['children'], $depth + 1, $max_depth, $max_children, $rendered );
			}
		}

		return $rendered;
	}

	/**
	 * Get or create the logger instance.
	 *
	 * @return SScribe_Logger_Interface
	 */
	private function get_logger(): SScribe_Logger_Interface {
		if ( null === $this->logger ) {
			$this->logger = SScribe_Logger::instance( SScribe_Logger::is_logging_enabled() );
		}
		return $this->logger;
	}
}
