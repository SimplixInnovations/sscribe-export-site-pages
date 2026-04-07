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
		if ( empty( $url ) || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
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
		$response = wp_remote_get(
			$url,
			array(
				'timeout'    => 30,
				'sslverify'  => false,
				'user-agent' => 'SScribe Export Plugin',
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

		$path       = wp_parse_url( $url, PHP_URL_PATH ) ?? '';
		$ext        = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		$valid_ext  = array( 'jpg', 'jpeg', 'png', 'gif', 'webp' );

		if ( ! in_array( $ext, $valid_ext, true ) ) {
			$ext = 'jpg';
		}

		$temp_path = sys_get_temp_dir() . '/sscribe-img-' . uniqid() . '.' . $ext;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false === file_put_contents( $temp_path, $body ) ) {
			return false;
		}

		return $temp_path;
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

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$info = @getimagesize( $path );
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
		imagedestroy( $image );

		$optimized_path = sys_get_temp_dir() . '/sscribe-opt-' . uniqid() . '.jpg';

		// phpcs:ignore WordPress.WP.AlternativeFunctions.image_jpeg
		$result = imagejpeg( $resized, $optimized_path, self::JPEG_QUALITY );
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
