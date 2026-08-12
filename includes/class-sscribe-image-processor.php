<?php
/**
 * SScribe Image Processor
 *
 * @package SScribe_Export_Site_Pages
 * @license GPL v2 or later
 * @link    https://www.gnu.org/licenses/gpl-2.0.html
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Downloads, validates, and optimizes images for export.
 */
class SScribe_Image_Processor {

	private const ALLOWED_CONTENT_TYPES = array(
		'image/jpeg',
		'image/png',
		'image/gif',
		'image/webp',
	);

	private const ALLOWED_EXTENSIONS = array( 'jpg', 'jpeg', 'png', 'gif', 'webp' );

	private const MAX_DOWNLOAD_BYTES = 10485760;

	private const MAX_IMAGE_PIXELS = 25000000;

	private const MAX_WIDTH = 1200;

	private const JPEG_QUALITY = 85;

	/**
	 * Resolve a path to a regular file inside the WordPress uploads tree.
	 *
	 * @param string $path Candidate local path.
	 * @return string Canonical path, or an empty string when unsafe.
	 */
	public static function validate_local_path( string $path ): string {
		if ( '' === $path || is_link( $path ) || ! is_file( $path ) || ! is_readable( $path ) ) {
			return '';
		}

		$upload_dir = wp_upload_dir();
		if ( ! empty( $upload_dir['error'] ) || empty( $upload_dir['basedir'] ) ) {
			return '';
		}

		$base_real = realpath( (string) $upload_dir['basedir'] );
		$file_real = realpath( $path );
		if ( false === $base_real || false === $file_real ) {
			return '';
		}

		$safe_prefix = rtrim( $base_real, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR;
		return str_starts_with( $file_real, $safe_prefix ) ? $file_real : '';
	}

	/**
	 * Get allowed hostnames for image downloads (cached per request).
	 *
	 * @return array<string>
	 */
	private static function get_allowed_hosts(): array {
		static $hosts = null;
		if ( null === $hosts ) {
			$upload_dir = wp_upload_dir();
			$hosts      = array_filter(
				array_unique(
					array(
						strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ),
						strtolower( (string) wp_parse_url( site_url(), PHP_URL_HOST ) ),
						strtolower( (string) wp_parse_url( $upload_dir['baseurl'] ?? '', PHP_URL_HOST ) ),
					)
				)
			);
		}
		return $hosts;
	}

	/**
	 * Download and optimize an image from URL.
	 *
	 * @param string $url Image URL.
	 * @return string|false
	 */
	public static function download_and_optimize( string $url ): string|false {
		$url = self::normalize_url( $url );

		if ( '' === $url || ! self::is_allowed_remote_url( $url ) ) {
			if ( '' !== $url ) {
				$host = strtolower( (string) ( wp_parse_url( $url, PHP_URL_HOST ) ?? '' ) );
				if ( defined( 'SSCRIBE_DEBUG' ) && SSCRIBE_DEBUG ) {
					error_log( sprintf( 'SScribe: Image blocked - host "%s" not in allowed hosts list. Use sscribe_allowed_image_hosts filter to add external hosts.', $host ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				}
			}
			return false;
		}

		$local_path = self::resolve_local_upload_url( $url );
		if ( '' !== $local_path ) {
			return self::optimize_local( $local_path );
		}

		$temp_path = self::download_to_temp( $url );
		if ( false === $temp_path ) {
			return false;
		}

		$optimized_path = self::optimize_local( $temp_path );

		$temp_real      = realpath( $temp_path );
		$optimized_real = is_string( $optimized_path ) ? realpath( $optimized_path ) : false;
		if ( false !== $optimized_path && $temp_real !== $optimized_real && file_exists( $temp_path ) ) {
			wp_delete_file( $temp_path );
		}

		return $optimized_path;
	}

	/**
	 * Download image to temporary file.
	 *
	 * @param string $url Image URL.
	 * @return string|false
	 */
	private static function download_to_temp( string $url ): string|false {
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 10,
				'user-agent'          => 'SScribe Export Plugin',
				'reject_unsafe_urls'  => true,
				'redirection'         => 0,
				'limit_response_size' => self::MAX_DOWNLOAD_BYTES,
			)
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return false;
		}

		$body = wp_remote_retrieve_body( $response );
		if ( empty( $body ) || strlen( $body ) > self::MAX_DOWNLOAD_BYTES ) {
			return false;
		}

		$content_type = self::normalize_content_type( (string) wp_remote_retrieve_header( $response, 'content-type' ) );
		if ( '' === $content_type || ! in_array( $content_type, self::ALLOWED_CONTENT_TYPES, true ) ) {
			return false;
		}

		$path = wp_parse_url( $url, PHP_URL_PATH ) ?? '';
		$ext  = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );

		if ( ! in_array( $ext, self::ALLOWED_EXTENSIONS, true ) ) {
			$ext = self::extension_from_content_type( $content_type );
		}

		$temp_dir = self::temp_dir();
		if ( '' === $temp_dir ) {
			return false;
		}

		try {
			$temp_path = $temp_dir . '/sscribe-img-' . bin2hex( random_bytes( 12 ) ) . '.' . $ext;
		} catch ( \Throwable $e ) {
			return false;
		}

		$filesystem = new SScribe_Filesystem();
		if ( ! $filesystem->put_contents( $temp_path, $body ) ) {
			return false;
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Invalid image data is rejected and the staging file is deleted below.
		$image_info = @getimagesize( $temp_path );
		if ( false === $image_info || empty( $image_info['mime'] ) || self::normalize_content_type( (string) $image_info['mime'] ) !== $content_type ) {
			wp_delete_file( $temp_path );
			return false;
		}
		if ( ! self::are_dimensions_safe( (int) $image_info[0], (int) $image_info[1] ) ) {
			wp_delete_file( $temp_path );
			return false;
		}

		return $temp_path;
	}

	/**
	 * Resolve a media URL to a regular file inside the uploads directory.
	 *
	 * @param string $url Validated HTTP(S) URL.
	 * @return string Canonical local path, or an empty string.
	 */
	private static function resolve_local_upload_url( string $url ): string {
		$upload_dir = wp_upload_dir();
		if ( ! empty( $upload_dir['error'] ) || empty( $upload_dir['baseurl'] ) || empty( $upload_dir['basedir'] ) ) {
			return '';
		}

		$url_parts  = wp_parse_url( $url );
		$base_parts = wp_parse_url( (string) $upload_dir['baseurl'] );
		if ( ! is_array( $url_parts ) || ! is_array( $base_parts ) ) {
			return '';
		}

		foreach ( array( 'scheme', 'host', 'port' ) as $part ) {
			if ( strtolower( (string) ( $url_parts[ $part ] ?? '' ) ) !== strtolower( (string) ( $base_parts[ $part ] ?? '' ) ) ) {
				return '';
			}
		}

		$base_path = trailingslashit( (string) ( $base_parts['path'] ?? '' ) );
		$url_path  = rawurldecode( (string) ( $url_parts['path'] ?? '' ) );
		if ( ! str_starts_with( $url_path, $base_path ) ) {
			return '';
		}

		$relative  = ltrim( substr( $url_path, strlen( $base_path ) ), '/' );
		$candidate = trailingslashit( (string) $upload_dir['basedir'] ) . $relative;
		$base_real = realpath( (string) $upload_dir['basedir'] );
		$file_real = realpath( $candidate );
		if ( false === $base_real || false === $file_real || is_link( $candidate ) || ! is_file( $file_real ) ) {
			return '';
		}

		$safe_prefix = rtrim( $base_real, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR;
		return str_starts_with( $file_real, $safe_prefix ) ? $file_real : '';
	}

	/**
	 * Normalize and validate URL.
	 *
	 * @param string $url Raw URL.
	 * @return string
	 */
	private static function normalize_url( string $url ): string {
		$url = trim( $url );
		if ( '' === $url ) {
			return '';
		}

		return esc_url_raw( $url );
	}

	/**
	 * Check if URL is allowed for remote image download.
	 *
	 * @param string $url URL to check.
	 * @return bool
	 */
	private static function is_allowed_remote_url( string $url ): bool {
		if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return false;
		}

		if ( function_exists( 'wp_http_validate_url' ) && ! wp_http_validate_url( $url ) ) {
			return false;
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) ) {
			return false;
		}

		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return false;
		}

		$host = strtolower( (string) ( $parts['host'] ?? '' ) );
		if ( '' === $host ) {
			return false;
		}

		// Defense-in-depth against SSRF: even if an operator widens the
		// `sscribe_allowed_image_hosts` filter to `*` or `0.0.0.0/0`, raw
		// IP literals in URL hosts must never reach wp_remote_get. Reject
		// loopback, link-local (AWS metadata at 169.254.169.254), private
		// (RFC1918), and reserved ranges. IPv6 zone-ids and brackets are
		// stripped by the host-validation regex above so this runs only
		// on bare literals.
		if ( filter_var( trim( $host, '[]' ), FILTER_VALIDATE_IP ) ) {
			if ( ! filter_var(
				trim( $host, '[]' ),
				FILTER_VALIDATE_IP,
				FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
			) ) {
				return false;
			}
		}

		$site_hosts    = self::get_allowed_hosts();
		$allowed_hosts = apply_filters( 'sscribe_allowed_image_hosts', $site_hosts );

		if ( ! is_array( $allowed_hosts ) ) {
			$allowed_hosts = $site_hosts;
		}
		$allowed_hosts = array_values(
			array_filter(
				array_unique(
					array_map(
						static function ( $allowed_host ): string {
							$allowed_host = strtolower( trim( (string) $allowed_host ) );
							return 1 === preg_match( '/^(?:[a-z0-9](?:[a-z0-9.-]{0,251}[a-z0-9])?|\[[0-9a-f:]+\])$/', $allowed_host )
								? $allowed_host
								: '';
						},
						array_slice( $allowed_hosts, 0, 100 )
					)
				)
			)
		);

		return in_array( $host, $allowed_hosts, true );
	}

	/**
	 * Normalize content type string.
	 *
	 * @param string $content_type Raw content type.
	 * @return string
	 */
	private static function normalize_content_type( string $content_type ): string {
		if ( '' === $content_type ) {
			return '';
		}

		return strtolower( trim( explode( ';', $content_type )[0] ) );
	}

	/**
	 * Get file extension from content type.
	 *
	 * @param string $content_type MIME type.
	 * @return string
	 */
	private static function extension_from_content_type( string $content_type ): string {
		return match ( $content_type ) {
			'image/png'  => 'png',
			'image/gif'  => 'gif',
			'image/webp' => 'webp',
			default      => 'jpg',
		};
	}

	/**
	 * Optimize a local image file.
	 *
	 * @param string $path File path.
	 * @return string|false
	 */
	public static function optimize_local( string $path ): string|false {
		$path = self::validate_local_path( $path );
		if ( '' === $path ) {
			return false;
		}

		$info = false;
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Production error handling for image processing.
		set_error_handler(
			static function ( int $errno, string $errstr ) use ( $path ): bool {
				SScribe_Logger::instance( SScribe_Logger::is_logging_enabled() )
					->warning(
						'getimagesize failed for local image',
						array(
							'path'  => $path,
							'error' => $errstr,
						)
					);
				return true;
			}
		);
		try {
			$info = getimagesize( $path );
		} finally {
			restore_error_handler();
		}

		if ( false === $info ) {
			return false;
		}

		list( $width, $height, $type ) = $info;
		if ( ! self::are_dimensions_safe( (int) $width, (int) $height ) ) {
			return false;
		}
		$allowed_types = array( IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF );
		if ( defined( 'IMAGETYPE_WEBP' ) ) {
			$allowed_types[] = IMAGETYPE_WEBP;
		}
		if ( ! in_array( $type, $allowed_types, true ) ) {
			return false;
		}

		if ( ! extension_loaded( 'gd' ) ) {
			return $path;
		}

		if ( $width <= self::MAX_WIDTH ) {
			return $path;
		}

		$new_width  = self::MAX_WIDTH;
		$new_height = (int) ( $height * ( self::MAX_WIDTH / $width ) );

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Production error handling for GD image loading.
		set_error_handler(
			static function ( int $errno, string $errstr ) use ( $path ): bool {
				SScribe_Logger::instance( SScribe_Logger::is_logging_enabled() )
					->warning(
						'Image loading failed',
						array(
							'path'  => $path,
							'error' => $errstr,
						)
					);
				return true;
			}
		);
		$image = false;
		try {
			$image = match ( $type ) {
				IMAGETYPE_JPEG => imagecreatefromjpeg( $path ),
				IMAGETYPE_PNG  => imagecreatefrompng( $path ),
				IMAGETYPE_GIF  => imagecreatefromgif( $path ),
				IMAGETYPE_WEBP => imagecreatefromwebp( $path ),
			};
		} finally {
			restore_error_handler();
		}

		if ( false === $image ) {
			return $path;
		}

		$resized = imagecreatetruecolor( $new_width, $new_height );
		if ( false === $resized ) {
			unset( $image );
			return $path;
		}

		if ( IMAGETYPE_PNG === $type || IMAGETYPE_GIF === $type ) {
			imagealphablending( $resized, false );
			imagesavealpha( $resized, true );
			$transparent = imagecolorallocatealpha( $resized, 0, 0, 0, 127 );
			imagefill( $resized, 0, 0, $transparent );
		}

		imagecopyresampled( $resized, $image, 0, 0, 0, 0, $new_width, $new_height, $width, $height );
		unset( $image );

		$temp_dir = self::temp_dir();
		if ( '' === $temp_dir ) {
			unset( $resized );
			return $path;
		}
		try {
			$optimized_path = $temp_dir . '/sscribe-opt-' . bin2hex( random_bytes( 12 ) ) . '.jpg';
		} catch ( \Throwable $e ) {
			unset( $resized );
			return $path;
		}
		$filesystem = new SScribe_Filesystem();
		if ( SScribe_Filesystem::SSCRIBE_PATH_ALLOWED !== $filesystem->is_path_safe_for_write( $optimized_path ) || is_link( $optimized_path ) ) {
			unset( $resized );
			return $path;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.image_jpeg

		$result = imagejpeg( $resized, $optimized_path, self::JPEG_QUALITY );

		unset( $resized );

		if ( false === $result ) {
			return $path;
		}

		return $optimized_path;
	}

	/**
	 * Reject invalid dimensions and compressed image bombs before GD decoding.
	 *
	 * @param int $width  Source width.
	 * @param int $height Source height.
	 * @return bool Whether the source is safe to decode.
	 */
	public static function are_dimensions_safe( int $width, int $height ): bool {
		return $width > 0
			&& $height > 0
			&& $width <= self::MAX_IMAGE_PIXELS
			&& $height <= self::MAX_IMAGE_PIXELS
			&& $width <= intdiv( self::MAX_IMAGE_PIXELS, $height );
	}

	/**
	 * Clean up temporary image file.
	 *
	 * @param string $path File path.
	 */
	public static function cleanup( string $path ): void {
		$temp_dir = self::temp_dir();
		if ( '' === $temp_dir || is_link( $path ) || ! is_file( $path ) ) {
			return;
		}

		$temp_real = realpath( $temp_dir );
		$file_real = realpath( $path );
		if ( false === $temp_real || false === $file_real ) {
			return;
		}

		$safe_prefix = rtrim( $temp_real, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR;
		if ( str_starts_with( $file_real, $safe_prefix ) ) {
			wp_delete_file( $file_real );
		}
	}

	/**
	 * Delete abandoned image-staging files after their retention window.
	 *
	 * @param int $max_age Maximum age in seconds.
	 * @return int Number of files deleted.
	 */
	public static function cleanup_stale( int $max_age = DAY_IN_SECONDS ): int {
		$temp_dir = self::temp_dir();
		if ( '' === $temp_dir ) {
			return 0;
		}

		$cleaned = 0;
		$now     = time();
		$scanned = 0;
		try {
			$dir = new \DirectoryIterator( $temp_dir );
		} catch ( \UnexpectedValueException ) {
			return 0;
		}
		foreach ( $dir as $entry ) {
			if ( $entry->isDot() || ++$scanned > 500 ) {
				continue;
			}
			$file = $entry->getPathname();
			if ( ! str_starts_with( $entry->getFilename(), 'sscribe-' ) || $entry->isLink() || ! $entry->isFile() ) {
				continue;
			}
			$modified = filemtime( $file );
			if ( false !== $modified && ( $now - $modified ) > max( 1, $max_age ) ) {
				self::cleanup( $file );
				if ( ! file_exists( $file ) ) {
					++$cleaned;
				}
			}
		}

		return $cleaned;
	}

	/**
	 * Plugin-owned temp directory for intermediate image files.
	 *
	 * Lives inside sscribe-exports/ so the default-deny
	 * is_path_safe_for_write() rule accepts the write. Using
	 * sys_get_temp_dir() here would put scratch files outside the
	 * allowlist (and on a multi-site install potentially in a different
	 * filesystem from the export dir, breaking cross-temp linking).
	 *
	 * @return string Absolute path to the image staging dir (with trailing slash).
	 */
	private static function temp_dir(): string {
		if ( ! function_exists( 'wp_upload_dir' ) ) {
			return '';
		}
		$upload_dir = wp_upload_dir();
		if ( ! empty( $upload_dir['error'] ) || empty( $upload_dir['basedir'] ) ) {
			return '';
		}
		$base       = (string) $upload_dir['basedir'];
		$dir        = trailingslashit( $base ) . 'sscribe-exports/image-staging/';
		if ( ! is_dir( $dir ) ) {
			if ( ! function_exists( 'wp_mkdir_p' ) || ! wp_mkdir_p( $dir ) ) {
				return '';
			}
		}
		if ( is_link( $dir ) || ! wp_is_writable( $dir ) ) {
			return '';
		}
		$base_real = realpath( $base );
		$dir_real  = realpath( $dir );
		if ( false === $base_real || false === $dir_real ) {
			return '';
		}
		$safe_prefix = rtrim( $base_real, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . 'sscribe-exports' . DIRECTORY_SEPARATOR;
		if ( ! str_starts_with( rtrim( $dir_real, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR, $safe_prefix ) ) {
			return '';
		}
		if ( ! file_exists( trailingslashit( $base ) . 'sscribe-exports/.htaccess' ) ) {
			SScribe_Security::protect_directory( trailingslashit( $base ) . 'sscribe-exports' );
		}
		return untrailingslashit( $dir );
	}
}
