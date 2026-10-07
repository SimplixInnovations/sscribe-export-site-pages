<?php
/**
 * SScribe AWS Signature Version 4
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
 * Signs S3 requests with AWS Signature Version 4.
 *
 * Only the pieces an S3 PUT needs are here: canonical request, string to
 * sign, signing key and the Authorization header. S3 paths are encoded
 * once, as S3 expects, not twice like other AWS services.
 */
final class SScribe_AWS_Signature_V4 {

	public const ALGORITHM = 'AWS4-HMAC-SHA256';

	/**
	 * Build a signer for one set of credentials.
	 *
	 * @param string $access_key Access key id.
	 * @param string $secret_key Secret access key.
	 * @param string $region     Region, such as us-east-1.
	 * @param string $service    Service name.
	 */
	public function __construct(
		private readonly string $access_key,
		#[\SensitiveParameter]
		private readonly string $secret_key,
		private readonly string $region,
		private readonly string $service = 's3'
	) {
	}

	/**
	 * Keep the secret out of dumps and debug output.
	 *
	 * @return array<string, string>
	 */
	public function __debugInfo(): array {
		return array(
			'access_key' => $this->access_key,
			'region'     => $this->region,
			'service'    => $this->service,
		);
	}

	/**
	 * Headers for a signed request, including Authorization.
	 *
	 * @param string                $method       HTTP method.
	 * @param string                $url          Full request URL.
	 * @param array<string, string> $headers      Headers to sign besides host, x-amz-date and x-amz-content-sha256.
	 * @param string                $payload_hash Hex SHA-256 of the body.
	 * @param int                   $timestamp    Unix time of the request.
	 * @return array<string, string> Every header to send, keyed by lower-case name.
	 */
	public function sign( string $method, string $url, array $headers, string $payload_hash, int $timestamp ): array {
		$amz_date = gmdate( 'Ymd\THis\Z', $timestamp );
		$parts    = wp_parse_url( $url );
		$parts    = is_array( $parts ) ? $parts : array();
		$host     = strtolower( (string) ( $parts['host'] ?? '' ) );
		if ( isset( $parts['port'] ) ) {
			$host .= ':' . (int) $parts['port'];
		}

		$signed = array();
		foreach ( $headers as $name => $value ) {
			$signed[ strtolower( trim( (string) $name ) ) ] = (string) $value;
		}
		$signed['host']                 = $host;
		$signed['x-amz-date']           = $amz_date;
		$signed['x-amz-content-sha256'] = $payload_hash;
		ksort( $signed );

		$canonical = $this->canonical_request(
			$method,
			(string) ( $parts['path'] ?? '/' ),
			(string) ( $parts['query'] ?? '' ),
			$signed,
			$payload_hash
		);
		$scope     = $this->scope( substr( $amz_date, 0, 8 ) );
		$signature = hash_hmac( 'sha256', $this->string_to_sign( $canonical, $amz_date ), $this->signing_key( substr( $amz_date, 0, 8 ) ) );

		$signed['authorization'] = sprintf(
			'%1$s Credential=%2$s/%3$s, SignedHeaders=%4$s, Signature=%5$s',
			self::ALGORITHM,
			$this->access_key,
			$scope,
			implode( ';', array_keys( $signed ) ),
			$signature
		);

		return $signed;
	}

	/**
	 * The canonical request.
	 *
	 * @param string                $method       HTTP method.
	 * @param string                $path         Already URL-encoded path.
	 * @param string                $query        Raw query string without "?".
	 * @param array<string, string> $headers      Headers to sign keyed by name.
	 * @param string                $payload_hash Hex SHA-256 of the body.
	 * @return string
	 */
	public function canonical_request( string $method, string $path, string $query, array $headers, string $payload_hash ): string {
		$canonical_headers = array();
		foreach ( $headers as $name => $value ) {
			$name                       = strtolower( trim( (string) $name ) );
			$canonical_headers[ $name ] = trim( (string) preg_replace( '/\s+/', ' ', (string) $value ) );
		}
		ksort( $canonical_headers );

		$header_lines = '';
		foreach ( $canonical_headers as $name => $value ) {
			$header_lines .= $name . ':' . $value . "\n";
		}

		return implode(
			"\n",
			array(
				strtoupper( $method ),
				'' !== $path ? $path : '/',
				self::canonical_query( $query ),
				$header_lines,
				implode( ';', array_keys( $canonical_headers ) ),
				$payload_hash,
			)
		);
	}

	/**
	 * The string to sign for a canonical request.
	 *
	 * @param string $canonical_request Canonical request.
	 * @param string $amz_date          Request time as YYYYMMDDTHHMMSSZ.
	 * @return string
	 */
	public function string_to_sign( string $canonical_request, string $amz_date ): string {
		return implode(
			"\n",
			array(
				self::ALGORITHM,
				$amz_date,
				$this->scope( substr( $amz_date, 0, 8 ) ),
				hash( 'sha256', $canonical_request ),
			)
		);
	}

	/**
	 * The derived signing key for a day.
	 *
	 * @param string $date Day as YYYYMMDD.
	 * @return string Raw 32-byte key.
	 */
	public function signing_key( string $date ): string {
		$key = hash_hmac( 'sha256', $date, 'AWS4' . $this->secret_key, true );
		$key = hash_hmac( 'sha256', $this->region, $key, true );
		$key = hash_hmac( 'sha256', $this->service, $key, true );

		return hash_hmac( 'sha256', 'aws4_request', $key, true );
	}

	/**
	 * Encode an object key for a URL path, keeping the slashes.
	 *
	 * @param string $key Object key.
	 * @return string
	 */
	public static function encode_path( string $key ): string {
		return implode( '/', array_map( 'rawurlencode', explode( '/', $key ) ) );
	}

	/**
	 * Credential scope for a day.
	 *
	 * @param string $date Day as YYYYMMDD.
	 * @return string
	 */
	private function scope( string $date ): string {
		return $date . '/' . $this->region . '/' . $this->service . '/aws4_request';
	}

	/**
	 * Sorted, encoded query string.
	 *
	 * @param string $query Raw query string.
	 * @return string
	 */
	private static function canonical_query( string $query ): string {
		if ( '' === $query ) {
			return '';
		}

		$pairs = array();
		foreach ( explode( '&', $query ) as $pair ) {
			if ( '' === $pair ) {
				continue;
			}
			$split   = explode( '=', $pair, 2 );
			$pairs[] = array( rawurlencode( rawurldecode( $split[0] ) ), rawurlencode( rawurldecode( $split[1] ?? '' ) ) );
		}
		usort(
			$pairs,
			static fn( array $a, array $b ): int => array( $a[0], $a[1] ) <=> array( $b[0], $b[1] )
		);

		return implode( '&', array_map( static fn( array $pair ): string => $pair[0] . '=' . $pair[1], $pairs ) );
	}
}
