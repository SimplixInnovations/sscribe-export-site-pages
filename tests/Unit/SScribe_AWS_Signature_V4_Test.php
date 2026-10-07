<?php
/**
 * Unit tests for SScribe_AWS_Signature_V4 against the examples published in the
 * Amazon S3 Signature Version 4 documentation.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SScribe_AWS_Signature_V4_Test extends TestCase {

	private const ACCESS_KEY = 'AKIAIOSFODNN7EXAMPLE';
	private const SECRET_KEY = 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY';
	private const EMPTY_HASH = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';
	private const AMZ_DATE   = '20130524T000000Z';

	private function signer(): \SScribe_AWS_Signature_V4 {
		return new \SScribe_AWS_Signature_V4( self::ACCESS_KEY, self::SECRET_KEY, 'us-east-1' );
	}

	/**
	 * @return array<string, string>
	 */
	private static function get_object_headers(): array {
		return array(
			'host'                 => 'examplebucket.s3.amazonaws.com',
			'range'                => 'bytes=0-9',
			'x-amz-content-sha256' => self::EMPTY_HASH,
			'x-amz-date'           => self::AMZ_DATE,
		);
	}

	public function test_get_object_canonical_request_matches_the_documented_example(): void {
		$canonical = $this->signer()->canonical_request( 'GET', '/test.txt', '', self::get_object_headers(), self::EMPTY_HASH );

		$expected = "GET\n/test.txt\n\nhost:examplebucket.s3.amazonaws.com\nrange:bytes=0-9\nx-amz-content-sha256:" . self::EMPTY_HASH . "\nx-amz-date:20130524T000000Z\n\nhost;range;x-amz-content-sha256;x-amz-date\n" . self::EMPTY_HASH;

		$this::assertSame( $expected, $canonical );
		$this::assertSame( '7344ae5b7ee6c3e7e6b0fe0640412a37625d1fbfff95c48bbb2dc43964946972', hash( 'sha256', $canonical ) );
	}

	public function test_get_object_string_to_sign_and_signature_match_the_documented_example(): void {
		$signer    = $this->signer();
		$canonical = $signer->canonical_request( 'GET', '/test.txt', '', self::get_object_headers(), self::EMPTY_HASH );
		$to_sign   = $signer->string_to_sign( $canonical, self::AMZ_DATE );

		$this::assertSame(
			"AWS4-HMAC-SHA256\n20130524T000000Z\n20130524/us-east-1/s3/aws4_request\n7344ae5b7ee6c3e7e6b0fe0640412a37625d1fbfff95c48bbb2dc43964946972",
			$to_sign
		);
		$this::assertSame(
			'f0e8bdb87c964420e857bd35b5d6ed310bd44f0170aba48dd91039c6036bdb41',
			hash_hmac( 'sha256', $to_sign, $signer->signing_key( '20130524' ) )
		);
	}

	public function test_sign_builds_the_documented_authorization_header(): void {
		$headers = $this->signer()->sign(
			'GET',
			'https://examplebucket.s3.amazonaws.com/test.txt',
			array( 'Range' => 'bytes=0-9' ),
			self::EMPTY_HASH,
			(int) gmmktime( 0, 0, 0, 5, 24, 2013 )
		);

		$this::assertSame(
			'AWS4-HMAC-SHA256 Credential=AKIAIOSFODNN7EXAMPLE/20130524/us-east-1/s3/aws4_request, SignedHeaders=host;range;x-amz-content-sha256;x-amz-date, Signature=f0e8bdb87c964420e857bd35b5d6ed310bd44f0170aba48dd91039c6036bdb41',
			$headers['authorization']
		);
		$this::assertSame( 'examplebucket.s3.amazonaws.com', $headers['host'] );
		$this::assertSame( self::AMZ_DATE, $headers['x-amz-date'] );
		$this::assertSame( self::EMPTY_HASH, $headers['x-amz-content-sha256'] );
	}

	public function test_put_object_example_with_an_encoded_key_matches_the_documented_signature(): void {
		$payload = 'Welcome to Amazon S3.';
		$hash    = hash( 'sha256', $payload );

		$headers = $this->signer()->sign(
			'PUT',
			'https://examplebucket.s3.amazonaws.com/' . \SScribe_AWS_Signature_V4::encode_path( 'test$file.text' ),
			array(
				'Date'                => 'Fri, 24 May 2013 00:00:00 GMT',
				'x-amz-storage-class' => 'REDUCED_REDUNDANCY',
			),
			$hash,
			(int) gmmktime( 0, 0, 0, 5, 24, 2013 )
		);

		$this::assertSame( '44ce7dd67c959e0d3524ffac1771dfbba87d2b6b4b4e99e42034a8b803f8b072', $hash );
		$this::assertStringEndsWith(
			'SignedHeaders=date;host;x-amz-content-sha256;x-amz-date;x-amz-storage-class, Signature=98ad721746da40c64f1a55b78f14c238d841ea1380cd77a1b5971af0ece108bd',
			$headers['authorization']
		);
	}

	public function test_non_default_port_is_part_of_the_host_header(): void {
		$headers = $this->signer()->sign( 'PUT', 'https://minio.example.test:9000/bucket/a.zip', array(), self::EMPTY_HASH, 1700000000 );

		$this::assertSame( 'minio.example.test:9000', $headers['host'] );
	}

	public function test_query_parameters_are_sorted_and_encoded(): void {
		$canonical = $this->signer()->canonical_request( 'GET', '/', 'prefix=a b&max-keys=2&acl', array( 'host' => 'h' ), self::EMPTY_HASH );

		$this::assertSame( 'acl=&max-keys=2&prefix=a%20b', explode( "\n", $canonical )[2] );
	}

	public function test_encode_path_keeps_slashes_and_encodes_segments(): void {
		$this::assertSame( 'exports/site%20one/a%2Bb.zip', \SScribe_AWS_Signature_V4::encode_path( 'exports/site one/a+b.zip' ) );
	}

	public function test_debug_output_never_contains_the_secret(): void {
		$dump = print_r( $this->signer(), true );

		$this::assertStringNotContainsString( self::SECRET_KEY, $dump );
		$this::assertStringContainsString( self::ACCESS_KEY, $dump );
	}
}
