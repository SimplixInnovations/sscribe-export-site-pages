<?php
/**
 * Unit tests for SScribe_Destination_S3 using the recording wp_remote_request() stub.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SScribe_Destination_S3_Test extends TestCase {

	private const ACCESS = 'AKIAIOSFODNN7EXAMPLE';
	private const SECRET = 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY';

	private string $zip = '';

	/** @var array<int, mixed> */
	private array $saved_filters = array();

	protected function setUp(): void {
		parent::setUp();
		$this->saved_filters                           = (array) ( $GLOBALS['sscribe_test_filters'] ?? array() );
		$GLOBALS['sscribe_test_http_requests']         = array();
		$GLOBALS['sscribe_test_http_request_response'] = self::reply( 200 );
		$this->zip                                     = (string) tempnam( sys_get_temp_dir(), 'sscribe-s3-' );
		file_put_contents( $this->zip, 'PK archive bytes' );
	}

	protected function tearDown(): void {
		$GLOBALS['sscribe_test_filters'] = $this->saved_filters;
		unset( $GLOBALS['sscribe_test_http_requests'], $GLOBALS['sscribe_test_http_request_response'] );
		@unlink( $this->zip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		parent::tearDown();
	}

	public function test_virtual_host_upload_is_a_signed_put_with_metadata(): void {
		$result = $this->deliver( array( 'prefix' => 'exports/' ) );

		$this::assertTrue( $result->is_success(), (string) $result->get_error() );
		$this::assertSame( 's3://my-bucket/exports/site-export.zip', $result->get_data() );
		$this::assertCount( 1, $GLOBALS['sscribe_test_http_requests'] );

		$request = $GLOBALS['sscribe_test_http_requests'][0];
		$headers = $request['args']['headers'];
		$hash    = hash( 'sha256', 'PK archive bytes' );

		$this::assertSame( 'https://my-bucket.s3.amazonaws.com/exports/site-export.zip', $request['url'] );
		$this::assertSame( 'PUT', $request['args']['method'] );
		$this::assertSame( 'PK archive bytes', $request['args']['body'] );
		$this::assertSame( 'my-bucket.s3.amazonaws.com', $headers['host'] );
		$this::assertSame( 'application/zip', $headers['content-type'] );
		$this::assertSame( $hash, $headers['x-amz-content-sha256'] );
		$this::assertNotSame( 'UNSIGNED-PAYLOAD', $headers['x-amz-content-sha256'] );
		$this::assertSame( $hash, $headers['x-amz-meta-sscribe-sha256'] );
		$this::assertSame( 'abc123', $headers['x-amz-meta-sscribe-session'] );
		$this::assertMatchesRegularExpression( '/^\d{8}T\d{6}Z$/', $headers['x-amz-date'] );
		$this::assertMatchesRegularExpression(
			'#^AWS4-HMAC-SHA256 Credential=AKIAIOSFODNN7EXAMPLE/\d{8}/eu-west-1/s3/aws4_request, SignedHeaders=content-type;host;x-amz-content-sha256;x-amz-date;x-amz-meta-sscribe-session;x-amz-meta-sscribe-sha256, Signature=[0-9a-f]{64}$#',
			$headers['authorization']
		);
		$this::assertStringNotContainsString( self::SECRET, (string) wp_json_encode( $request ) );
	}

	public function test_path_style_upload_puts_the_bucket_in_the_path(): void {
		$result = $this->deliver(
			array(
				'endpoint'   => 'https://minio.example.test:9000/',
				'path_style' => true,
			)
		);

		$request = $GLOBALS['sscribe_test_http_requests'][0];
		$this::assertTrue( $result->is_success() );
		$this::assertSame( 'https://minio.example.test:9000/my-bucket/site-export.zip', $request['url'] );
		$this::assertSame( 'minio.example.test:9000', $request['args']['headers']['host'] );
	}

	public function test_created_status_counts_as_success(): void {
		$GLOBALS['sscribe_test_http_request_response'] = self::reply( 201 );

		$this::assertTrue( $this->deliver()->is_success() );
	}

	public function test_forbidden_upload_fails_without_leaking_credentials(): void {
		$GLOBALS['sscribe_test_http_request_response'] = self::reply(
			403,
			'<Error><Code>SignatureDoesNotMatch</Code><AWSAccessKeyId>' . self::ACCESS . '</AWSAccessKeyId><StringToSign>' . self::SECRET . '</StringToSign></Error>'
		);

		$result = $this->deliver();

		$this::assertTrue( $result->is_failure() );
		$this::assertStringContainsString( '403', (string) $result->get_error() );
		$this::assertStringContainsString( 'SignatureDoesNotMatch', (string) $result->get_error() );
		$this::assertStringNotContainsString( self::ACCESS, (string) $result->get_error() );
		$this::assertStringNotContainsString( self::SECRET, (string) $result->get_error() );
	}

	public function test_transport_error_is_reported(): void {
		$GLOBALS['sscribe_test_http_request_response'] = new \WP_Error( 'http_request_failed', 'cURL error 6' );

		$this::assertStringContainsString( 'http_request_failed', (string) $this->deliver()->get_error() );
	}

	public function test_archive_over_the_size_cap_is_not_uploaded(): void {
		add_filter( 'sscribe_s3_max_bytes', static fn(): int => 4 );

		$result = $this->deliver();

		$this::assertTrue( $result->is_failure() );
		$this::assertStringContainsString( 'limit', (string) $result->get_error() );
		$this::assertSame( array(), $GLOBALS['sscribe_test_http_requests'] );
	}

	public function test_validation_rejects_unsafe_settings(): void {
		$destination = new \SScribe_Destination_S3();
		$cases       = array(
			array( 'endpoint' => 'http://s3.amazonaws.com' ),
			array( 'endpoint' => 'https://user:pass@s3.amazonaws.com' ),
			array( 'endpoint' => 'https://s3.amazonaws.com/path' ),
			array( 'bucket' => 'Upper_Case' ),
			array( 'bucket' => 'a..b' ),
			array( 'bucket' => '192.168.1.1' ),
			array( 'region' => 'us east' ),
			array( 'prefix' => 'a/../b/' ),
			array( 'prefix' => 'spaces not allowed/' ),
			array( 'access_key' => 'has/slash' ),
			array( 'secret_key' => '' ),
		);

		foreach ( $cases as $override ) {
			$this::assertTrue( $destination->validate( array_merge( self::settings(), $override ) )->is_failure(), (string) wp_json_encode( $override ) );
		}
		$this::assertSame( array(), $GLOBALS['sscribe_test_http_requests'] );
	}

	public function test_validation_normalizes_defaults(): void {
		$result = ( new \SScribe_Destination_S3() )->validate(
			array_merge(
				self::settings(),
				array(
					'endpoint' => '',
					'prefix'   => '/backups/',
					'region'   => 'EU-WEST-1',
				)
			)
		);

		$this::assertTrue( $result->is_success() );
		$this::assertSame( 'https://s3.amazonaws.com', $result->get_data()['endpoint'] );
		$this::assertSame( 'backups/', $result->get_data()['prefix'] );
		$this::assertSame( 'eu-west-1', $result->get_data()['region'] );
		$this::assertFalse( $result->get_data()['path_style'] );
	}

	/**
	 * @param array<string, mixed> $override Settings to change.
	 */
	private function deliver( array $override = array() ): \SScribe_Result {
		return ( new \SScribe_Destination_S3() )->deliver(
			$this->zip,
			array(
				'filename'   => 'site-export.zip',
				'session_id' => 'abc123',
			),
			array_merge( self::settings(), $override )
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function settings(): array {
		return array(
			'region'     => 'eu-west-1',
			'bucket'     => 'my-bucket',
			'access_key' => self::ACCESS,
			'secret_key' => self::SECRET,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function reply( int $status, string $body = '' ): array {
		return array(
			'response' => array( 'code' => $status ),
			'headers'  => array(),
			'body'     => $body,
		);
	}
}
