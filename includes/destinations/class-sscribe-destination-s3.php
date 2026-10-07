<?php
/**
 * SScribe S3 Destination
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
 * Uploads finished archives to Amazon S3 or an S3-compatible store.
 *
 * Works with AWS and with services that speak the S3 API, such as MinIO,
 * Cloudflare R2 and Wasabi; those usually need path-style addressing. The
 * request is signed with Signature Version 4 over the real SHA-256 of the
 * archive, so the store can reject a body that was changed in transit.
 */
final class SScribe_Destination_S3 implements SScribe_Destination_Interface {

	public const DEFAULT_ENDPOINT = 'https://s3.amazonaws.com';
	public const DEFAULT_MAX_MB   = 256;

	private const TIMEOUT        = 300;
	private const ZIP_NAME       = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,199}\.zip$/D';
	private const BUCKET_PATTERN = '/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/D';
	private const REGION_PATTERN = '/^[a-z0-9][a-z0-9-]{0,30}[a-z0-9]$/D';
	private const KEY_PATTERN    = '/^[A-Za-z0-9_.+=-]{3,128}$/D';
	private const PREFIX_PATTERN = '#^[A-Za-z0-9!_.*\'()/-]{0,512}$#D';

	/**
	 * Stable id.
	 *
	 * @return string
	 */
	public static function id(): string {
		return 's3';
	}

	/**
	 * Name shown to people.
	 *
	 * @return string
	 */
	public static function label(): string {
		return __( 'Amazon S3 or S3-compatible storage', 'sscribe-export-site-pages' );
	}

	/**
	 * Settings the destination takes.
	 *
	 * @return array<string, array{type: string, label: string, required: bool}>
	 */
	public static function settings_schema(): array {
		return array(
			'endpoint'   => array(
				'type'     => 'text',
				'label'    => __( 'Endpoint URL (https), default https://s3.amazonaws.com', 'sscribe-export-site-pages' ),
				'required' => false,
			),
			'region'     => array(
				'type'     => 'text',
				'label'    => __( 'Region, such as us-east-1', 'sscribe-export-site-pages' ),
				'required' => true,
			),
			'bucket'     => array(
				'type'     => 'text',
				'label'    => __( 'Bucket', 'sscribe-export-site-pages' ),
				'required' => true,
			),
			'prefix'     => array(
				'type'     => 'text',
				'label'    => __( 'Key prefix, such as exports/', 'sscribe-export-site-pages' ),
				'required' => false,
			),
			'access_key' => array(
				'type'     => 'text',
				'label'    => __( 'Access key id', 'sscribe-export-site-pages' ),
				'required' => true,
			),
			'secret_key' => array(
				'type'     => 'password',
				'label'    => __( 'Secret access key', 'sscribe-export-site-pages' ),
				'required' => true,
			),
			'path_style' => array(
				'type'     => 'checkbox',
				'label'    => __( 'Path-style addressing (MinIO, R2, Wasabi)', 'sscribe-export-site-pages' ),
				'required' => false,
			),
		);
	}

	/**
	 * Check the connection settings.
	 *
	 * @param array<string, mixed> $settings Settings with the secret in plain text.
	 * @return SScribe_Result Normalized settings, or the first problem found.
	 */
	public function validate( array $settings ): SScribe_Result {
		$text = static fn( string $name ): string => is_scalar( $settings[ $name ] ?? null ) ? trim( (string) $settings[ $name ] ) : '';

		$endpoint = self::clean_endpoint( '' !== $text( 'endpoint' ) ? $text( 'endpoint' ) : self::DEFAULT_ENDPOINT );
		if ( '' === $endpoint ) {
			return SScribe_Result::failure( __( 'The endpoint must be an https URL without a path, such as https://s3.amazonaws.com.', 'sscribe-export-site-pages' ) );
		}

		$region = strtolower( $text( 'region' ) );
		if ( 1 !== preg_match( self::REGION_PATTERN, $region ) ) {
			return SScribe_Result::failure( __( 'Give a region such as us-east-1.', 'sscribe-export-site-pages' ) );
		}

		$bucket = $text( 'bucket' );
		if ( 1 !== preg_match( self::BUCKET_PATTERN, $bucket ) || str_contains( $bucket, '..' ) || false !== filter_var( $bucket, FILTER_VALIDATE_IP ) ) {
			return SScribe_Result::failure( __( 'The bucket name is not valid. Use 3 to 63 lower-case letters, digits, dots or dashes.', 'sscribe-export-site-pages' ) );
		}

		$prefix = ltrim( $text( 'prefix' ), '/' );
		if ( 1 !== preg_match( self::PREFIX_PATTERN, $prefix ) || in_array( '..', explode( '/', $prefix ), true ) || str_contains( $prefix, '//' ) ) {
			return SScribe_Result::failure( __( 'The key prefix may only use letters, digits and !_.*\'()/- and must not contain "..".', 'sscribe-export-site-pages' ) );
		}

		$access_key = $text( 'access_key' );
		if ( 1 !== preg_match( self::KEY_PATTERN, $access_key ) ) {
			return SScribe_Result::failure( __( 'The access key id is not valid.', 'sscribe-export-site-pages' ) );
		}

		$secret_key = is_string( $settings['secret_key'] ?? null ) ? $settings['secret_key'] : '';
		if ( '' === $secret_key || strlen( $secret_key ) > 256 || 1 !== preg_match( '/^[\x21-\x7E]+$/D', $secret_key ) ) {
			return SScribe_Result::failure( __( 'The secret access key is missing or not valid.', 'sscribe-export-site-pages' ) );
		}

		$path_style = $settings['path_style'] ?? false;

		return SScribe_Result::success(
			array(
				'endpoint'   => $endpoint,
				'region'     => $region,
				'bucket'     => $bucket,
				'prefix'     => $prefix,
				'access_key' => $access_key,
				'secret_key' => $secret_key,
				'path_style' => is_bool( $path_style ) ? $path_style : true === filter_var( $path_style, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE ),
			)
		);
	}

	/**
	 * Upload the archive.
	 *
	 * @param string               $zip_path Absolute path of the archive.
	 * @param array<string, mixed> $context  Export context.
	 * @param array<string, mixed> $settings Settings with the secret in plain text.
	 * @return SScribe_Result The s3:// location, or why the upload failed.
	 */
	public function deliver( string $zip_path, array $context, array $settings ): SScribe_Result {
		$checked = $this->validate( $settings );
		if ( $checked->is_failure() ) {
			return $checked;
		}
		$valid = (array) $checked->get_data();

		$size = is_link( $zip_path ) || ! is_file( $zip_path ) ? false : filesize( $zip_path );
		if ( false === $size ) {
			return SScribe_Result::failure( __( 'The archive to upload is missing.', 'sscribe-export-site-pages' ) );
		}

		$max_bytes = (int) apply_filters( 'sscribe_s3_max_bytes', self::DEFAULT_MAX_MB * MB_IN_BYTES );
		if ( $size > $max_bytes ) {
			return SScribe_Result::failure(
				sprintf(
					/* translators: 1: Archive size, 2: Largest size allowed. */
					__( 'The archive is %1$s, larger than the %2$s S3 upload limit.', 'sscribe-export-site-pages' ),
					size_format( $size ),
					size_format( max( 0, $max_bytes ) )
				)
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- wp_remote_request() needs the body as a string; the size is capped above.
		$body = file_get_contents( $zip_path );
		if ( false === $body ) {
			return SScribe_Result::failure( __( 'The archive could not be read.', 'sscribe-export-site-pages' ) );
		}

		return $this->upload( $body, self::object_key( (string) $valid['prefix'], $zip_path, $context ), $context, $valid );
	}

	/**
	 * Address of an object for the given settings.
	 *
	 * @param array<string, mixed> $settings Normalized settings.
	 * @param string               $key      Object key.
	 * @return string
	 */
	public static function object_url( array $settings, string $key ): string {
		$parts  = (array) wp_parse_url( (string) $settings['endpoint'] );
		$host   = strtolower( (string) ( $parts['host'] ?? '' ) );
		$port   = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
		$bucket = (string) $settings['bucket'];
		$path   = SScribe_AWS_Signature_V4::encode_path( $key );

		if ( true === $settings['path_style'] ) {
			return 'https://' . $host . $port . '/' . $bucket . '/' . $path;
		}

		return 'https://' . $bucket . '.' . $host . $port . '/' . $path;
	}

	/**
	 * Send the signed PUT request.
	 *
	 * @param string               $body     Archive bytes.
	 * @param string               $key      Object key.
	 * @param array<string, mixed> $context  Export context.
	 * @param array<string, mixed> $settings Normalized settings.
	 * @return SScribe_Result
	 */
	private function upload( string $body, string $key, array $context, array $settings ): SScribe_Result {
		$hash    = hash( 'sha256', $body );
		$url     = self::object_url( $settings, $key );
		$signer  = new SScribe_AWS_Signature_V4( (string) $settings['access_key'], (string) $settings['secret_key'], (string) $settings['region'] );
		$headers = $signer->sign(
			'PUT',
			$url,
			array(
				'content-type'               => 'application/zip',
				'x-amz-meta-sscribe-session' => sanitize_key( (string) ( $context['session_id'] ?? '' ) ),
				'x-amz-meta-sscribe-sha256'  => $hash,
			),
			$hash,
			time()
		);

		$response = wp_remote_request(
			$url,
			array(
				'method'      => 'PUT',
				'headers'     => $headers,
				'body'        => $body,
				'timeout'     => self::TIMEOUT,
				'redirection' => 0,
				'sslverify'   => true,
			)
		);

		if ( $response instanceof WP_Error ) {
			return SScribe_Result::failure(
				sprintf(
					/* translators: %s: Error code from the HTTP client. */
					__( 'The S3 upload could not connect (%s).', 'sscribe-export-site-pages' ),
					sanitize_key( (string) $response->get_error_code() )
				)
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 === $status || 201 === $status ) {
			return SScribe_Result::success( 's3://' . $settings['bucket'] . '/' . $key );
		}

		$body = wp_remote_retrieve_body( $response );

		return SScribe_Result::failure( self::failure_message( $status, is_string( $body ) ? $body : '' ) );
	}

	/**
	 * Explain a rejected upload without echoing anything from the request.
	 *
	 * @param int    $status HTTP status.
	 * @param string $body   Response body.
	 * @return string
	 */
	private static function failure_message( int $status, string $body ): string {
		$code = 1 === preg_match( '#<Code>([A-Za-z]{1,64})</Code>#', $body, $matches ) ? $matches[1] : '';
		if ( '' !== $code ) {
			return sprintf(
				/* translators: 1: HTTP status code, 2: S3 error code such as AccessDenied. */
				__( 'S3 rejected the upload with HTTP %1$d (%2$s).', 'sscribe-export-site-pages' ),
				$status,
				$code
			);
		}

		return sprintf(
			/* translators: %d: HTTP status code. */
			__( 'S3 rejected the upload with HTTP %d.', 'sscribe-export-site-pages' ),
			$status
		);
	}

	/**
	 * Object key for the archive.
	 *
	 * @param string               $prefix   Key prefix.
	 * @param string               $zip_path Archive path.
	 * @param array<string, mixed> $context  Export context.
	 * @return string
	 */
	private static function object_key( string $prefix, string $zip_path, array $context ): string {
		$name = is_string( $context['filename'] ?? null ) ? $context['filename'] : basename( $zip_path );
		if ( 1 !== preg_match( self::ZIP_NAME, $name ) ) {
			$name = 'sscribe-export-' . gmdate( 'Ymd-His' ) . '.zip';
		}

		return $prefix . $name;
	}

	/**
	 * Keep an https endpoint with a host and nothing else.
	 *
	 * @param string $endpoint Endpoint URL.
	 * @return string Endpoint without a trailing slash, or empty when not allowed.
	 */
	private static function clean_endpoint( string $endpoint ): string {
		$parts = wp_parse_url( $endpoint );
		if ( ! is_array( $parts ) || 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) ) ) {
			return '';
		}
		$host = strtolower( (string) ( $parts['host'] ?? '' ) );
		if ( '' === $host || 1 !== preg_match( '/^[a-z0-9.-]{1,253}$/D', $host ) ) {
			return '';
		}
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) ) {
			return '';
		}
		if ( '' !== trim( (string) ( $parts['path'] ?? '' ), '/' ) ) {
			return '';
		}

		return 'https://' . $host . ( isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '' );
	}
}
