<?php
/**
 * Image download and optimization utility.
 *
 * @package SScribe
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SScribe_Image_Processor
 *
 * Downloads and optimizes images for PDF export.
 */
class SScribe_Image_Processor {

	/**
	 * Allowed remote content types.
	 *
	 * @var array<int, string>
	 */
	private const ALLOWED_CONTENT_TYPES = array(
		'image/jpeg',
		'image/png',
		'image/gif',
		'image/webp',
	);

	/**
	 * Allowed image extensions.
	 *
	 * @var array<int, string>
	 */
	private const ALLOWED_EXTENSIONS = array( 'jpg', 'jpeg', 'png', 'gif', 'webp' );

	/**
	 * Maximum remote image size in bytes.
	 */
	private const MAX_DOWNLOAD_BYTES = 10485760;

	/**
	 * Maximum width for images.
	 */
	private const MAX_WIDTH = 1200;

	/**
	 * JPEG quality (0-100).
	 */
	private const JPEG_QUALITY = 85;

	/**
	 * Download and optimize an image from URL.
	 *
	 * @param string $url Image URL.
	 * @return string|false Local path or false on failure.
	 */
	public static function download_and_optimize( string $url ): string|false {
		$url = self::normalize_url( $url );

		if ( '' === $url || ! self::is_allowed_remote_url( $url ) ) {
			return false;
		}

		$upload_dir = wp_upload_dir();
		if ( strpos( $url, $upload_dir['url'] ) === 0 ) {
			$local_path = str_replace( $upload_dir['url'], $upload_dir['basedir'], $url );
			if ( file_exists( $local_path ) ) {
				return self::optimize_local( $local_path );
			}
		}

		$temp_path = self::download_to_temp( $url );
		if ( false === $temp_path ) {
			return false;
		}

		$optimized_path = self::optimize_local( $temp_path );

		if ( $temp_path !== $optimized_path && file_exists( $temp_path ) ) {
			wp_delete_file( $temp_path );
		}

		return $optimized_path;
	}

	/**
	 * Download image to temporary file.
	 *
	 * @param string $url Image URL.
	 * @return string|false Temp path or false.
	 */
	private static function download_to_temp( string $url ): string|false {
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 15,
				'user-agent'          => 'SScribe Export Plugin',
				'reject_unsafe_urls'  => true,
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
		if ( empty( $body ) ) {
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

		$temp_path = sys_get_temp_dir() . '/sscribe-img-' . uniqid() . '.' . $ext;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false === file_put_contents( $temp_path, $body ) ) {
			return false;
		}

		return $temp_path;
	}

	/**
	 * Normalize a candidate URL.
	 *
	 * @param string $url Candidate image URL.
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
	 * Validate that the URL is safe and stays on the local site host.
	 *
	 * @param string $url Candidate image URL.
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

		$site_hosts = array_filter(
			array_unique(
				array(
					strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ),
					strtolower( (string) wp_parse_url( site_url(), PHP_URL_HOST ) ),
					strtolower( (string) wp_parse_url( wp_upload_dir()['baseurl'] ?? '', PHP_URL_HOST ) ),
				)
			)
		);

		return in_array( $host, $site_hosts, true );
	}

	/**
	 * Normalize a response content type.
	 *
	 * @param string $content_type Raw content type header.
	 * @return string
	 */
	private static function normalize_content_type( string $content_type ): string {
		if ( '' === $content_type ) {
			return '';
		}

		return strtolower( trim( explode( ';', $content_type )[0] ) );
	}

	/**
	 * Derive a file extension from a supported content type.
	 *
	 * @param string $content_type Normalized content type.
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
	 * @param string $path Local file path.
	 * @return string|false Optimized path or false.
	 */
	public static function optimize_local( string $path ): string|false {
		if ( ! file_exists( $path ) ) {
			return false;
		}

		// Safely attempt to read image info, logging failures instead of suppressing.
		$info = false;
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Production error handling for image processing.
		$prev_handler = set_error_handler(
			static function ( int $errno, string $errstr ) use ( $path ): bool {
				SScribe_Logger::instance( defined( 'SSCRIBE_DEBUG' ) && SSCRIBE_DEBUG )
					->warning(
						'getimagesize failed for local image',
						array(
							'path'  => $path,
							'error' => $errstr,
						)
					);
				return true; // Suppress the warning.
			}
		);
		try {
			$info = getimagesize( $path );
		} finally {
			restore_error_handler();
		}

		if ( false === $info ) {
			return $path;
		}

		list( $width, $height, $type ) = $info;

		if ( $width <= self::MAX_WIDTH ) {
			return $path;
		}

		// phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged
		$image = match ( $type ) {
			IMAGETYPE_JPEG, IMAGETYPE_JPEG2000 => @imagecreatefromjpeg( $path ),
			IMAGETYPE_PNG                      => @imagecreatefrompng( $path ),
			IMAGETYPE_GIF                      => @imagecreatefromgif( $path ),
			IMAGETYPE_WEBP                     => @imagecreatefromwebp( $path ),
			default                            => false,
		};
		// phpcs:enable

		if ( false === $image ) {
			return $path;
		}

		$new_width  = self::MAX_WIDTH;
		$new_height = (int) ( $height * ( self::MAX_WIDTH / $width ) );

		$resized = imagecreatetruecolor( $new_width, $new_height );
		if ( false === $resized ) {
			// phpcs:ignore Generic.PHP.DeprecatedFunctions.Deprecated
			imagedestroy( $image );
			return $path;
		}

		if ( IMAGETYPE_PNG === $type || IMAGETYPE_GIF === $type ) {
			imagealphablending( $resized, false );
			imagesavealpha( $resized, true );
			$transparent = imagecolorallocatealpha( $resized, 0, 0, 0, 127 );
			imagefill( $resized, 0, 0, $transparent );
		}

		imagecopyresampled( $resized, $image, 0, 0, 0, 0, $new_width, $new_height, $width, $height );
		// phpcs:ignore Generic.PHP.DeprecatedFunctions.Deprecated
		imagedestroy( $image );

		$optimized_path = sys_get_temp_dir() . '/sscribe-opt-' . uniqid() . '.jpg';

		// phpcs:ignore WordPress.WP.AlternativeFunctions.image_jpeg
		$result = imagejpeg( $resized, $optimized_path, self::JPEG_QUALITY );
		// phpcs:ignore Generic.PHP.DeprecatedFunctions.Deprecated
		imagedestroy( $resized );

		if ( false === $result ) {
			return $path;
		}

		return $optimized_path;
	}

	/**
	 * Clean up temporary image files.
	 *
	 * @param string $path File path to clean up.
	 */
	public static function cleanup( string $path ): void {
		if ( file_exists( $path ) && strpos( $path, sys_get_temp_dir() ) === 0 ) {
			wp_delete_file( $path );
		}
	}
}
