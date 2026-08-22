<?php
/**
 * SScribe Session Crypto Unit Test
 *
 * Verifies the sodium (preferred) and legacy AES-256-CBC paths used by
 * SScribe_Session to encrypt session rows in wp_options, plus the
 * failure modes that an attacker might try to exploit (truncated or
 * tampered ciphertexts).
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

class SScribe_Session_Crypto_Test extends TestCase {

	/** @var \SScribe_Session */
	private $session;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['sscribe_test_options']    = array();
		$GLOBALS['sscribe_test_transients'] = array();

		// Force a fresh sodium key per test so wrong-key/right-key paths are isolated.
		delete_option( 'sscribe_session_sodium_key' );
		delete_option( 'sscribe_session_legacy_key' );

		$this->session = new \SScribe_Session();
	}

	protected function tearDown(): void {
		$GLOBALS['sscribe_test_options']    = array();
		$GLOBALS['sscribe_test_transients'] = array();
		parent::tearDown();
	}

	/**
	 * Invoke a private method on SScribe_Session via Closure::bind
	 * (PHP 8.1+ deprecates setAccessible()).
	 *
	 * @param string $method Method name.
	 * @param array  $args   Arguments to pass.
	 * @return mixed Method return value.
	 */
	private function call_private( string $method, array $args ) {
		$method_name = $method;
		$fn          = \Closure::bind(
			function ( $obj, ...$a ) use ( $method_name ) {
				return $obj->$method_name( ...$a );
			},
			null,
			\SScribe_Session::class
		);
		return $fn( $this->session, ...$args );
	}

	/**
	 * Create a row in the unversioned format written by older releases.
	 *
	 * @param string $payload Plaintext test payload.
	 * @return string Base64-encoded legacy ciphertext.
	 */
	private function make_legacy_ciphertext( string $payload ): string {
		$key        = $this->call_private( 'get_legacy_aes_key', array() );
		$iv         = random_bytes( 16 );
		$ciphertext = openssl_encrypt( $payload, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
		$this->assertIsString( $ciphertext );

		return base64_encode( $iv . $ciphertext );
	}

	public function test_sodium_encrypt_produces_s1_prefix(): void {
		if ( ! function_exists( 'sodium_crypto_secretbox' ) ) {
			$this->markTestSkipped( 'libsodium not available' );
		}

		$ciphertext = $this->call_private( 'sodium_encrypt_session_data', array( 'hello' ) );

		$this->assertIsString( $ciphertext );
		$this->assertStringStartsWith( 's1:', $ciphertext );
		// s1: (3) + base64url of (24-byte nonce + 16-byte MAC + plaintext).
		// For a 5-byte plaintext, the encoded length is ceil( (24+16+5) * 4/3 ) = 60.
		$this->assertGreaterThanOrEqual( 60, strlen( $ciphertext ) );
	}

	public function test_sodium_roundtrip(): void {
		if ( ! function_exists( 'sodium_crypto_secretbox' ) ) {
			$this->markTestSkipped( 'libsodium not available' );
		}

		$payload    = wp_json_encode( array( 'page_ids' => array( 1, 2, 3 ), 'total' => 3 ) );
		$ciphertext = $this->call_private( 'sodium_encrypt_session_data', array( $payload ) );
		$plaintext  = $this->call_private( 'sodium_decrypt_session_data', array( $ciphertext ) );

		$this->assertSame( $payload, $plaintext );
	}

	public function test_sodium_decrypt_truncated_payload_returns_null(): void {
		if ( ! function_exists( 'sodium_crypto_secretbox' ) ) {
			$this->markTestSkipped( 'libsodium not available' );
		}

		$ciphertext = $this->call_private( 'sodium_encrypt_session_data', array( 'something' ) );
		// Truncate below nonce+1-byte minimum.
		$truncated = substr( $ciphertext, 0, 10 );

		$this->assertNull( $this->call_private( 'sodium_decrypt_session_data', array( $truncated ) ) );
	}

	public function test_sodium_decrypt_tampered_ciphertext_returns_null(): void {
		if ( ! function_exists( 'sodium_crypto_secretbox' ) ) {
			$this->markTestSkipped( 'libsodium not available' );
		}

		$ciphertext = $this->call_private( 'sodium_encrypt_session_data', array( 'secret' ) );
		// Flip a bit in the middle of the encoded payload (after 's1:').
		$flipped = 's1:' . ( substr( $ciphertext, 3, 1 ) === 'A' ? 'B' . substr( $ciphertext, 4 ) : 'A' . substr( $ciphertext, 4 ) );

		$this->assertNull( $this->call_private( 'sodium_decrypt_session_data', array( $flipped ) ) );
	}

	public function test_sodium_decrypt_wrong_key_returns_null(): void {
		if ( ! function_exists( 'sodium_crypto_secretbox' ) ) {
			$this->markTestSkipped( 'libsodium not available' );
		}

		// Encrypt with the current key.
		$ciphertext = $this->call_private( 'sodium_encrypt_session_data', array( 'secret' ) );

		// Rotate the stored key so a subsequent decrypt uses a different secret.
		// The next call to get_sodium_key() will fall through to keygen().
		delete_option( 'sscribe_session_sodium_key' );
		update_option( 'sscribe_session_sodium_key', base64_encode( sodium_crypto_secretbox_keygen() ) );

		$this->assertNull( $this->call_private( 'sodium_decrypt_session_data', array( $ciphertext ) ) );
	}

	public function test_decrypt_session_data_routes_s1_to_sodium(): void {
		if ( ! function_exists( 'sodium_crypto_secretbox' ) ) {
			$this->markTestSkipped( 'libsodium not available' );
		}

		$payload    = 'routed via sodium';
		$ciphertext = $this->call_private( 'sodium_encrypt_session_data', array( $payload ) );

		$decrypted = $this->call_private( 'decrypt_session_data', array( $ciphertext ) );
		$this->assertSame( $payload, $decrypted );
	}

	public function test_openssl_gcm_roundtrip_and_dispatch(): void {
		if ( ! function_exists( 'openssl_encrypt' ) || ! in_array( 'aes-256-gcm', openssl_get_cipher_methods(), true ) ) {
			$this->markTestSkipped( 'OpenSSL AES-256-GCM not available' );
		}

		$payload    = wp_json_encode( array( 'authenticated' => true, 'pages' => 4 ) );
		$ciphertext = $this->call_private( 'openssl_gcm_encrypt_session_data', array( $payload ) );

		$this->assertStringStartsWith( 'o1:', $ciphertext );
		$this->assertSame( $payload, $this->call_private( 'openssl_gcm_decrypt_session_data', array( $ciphertext ) ) );
		$this->assertSame( $payload, $this->call_private( 'decrypt_session_data', array( $ciphertext ) ) );
	}

	public function test_openssl_gcm_rejects_tampering(): void {
		if ( ! function_exists( 'openssl_encrypt' ) || ! in_array( 'aes-256-gcm', openssl_get_cipher_methods(), true ) ) {
			$this->markTestSkipped( 'OpenSSL AES-256-GCM not available' );
		}

		$ciphertext  = $this->call_private( 'openssl_gcm_encrypt_session_data', array( 'tamper-resistant' ) );
		$position    = strlen( $ciphertext ) - 2;
		$replacement = 'A' === $ciphertext[ $position ] ? 'B' : 'A';
		$tampered    = substr_replace( $ciphertext, $replacement, $position, 1 );

		$this->assertNull( $this->call_private( 'decrypt_session_data', array( $tampered ) ) );
	}

	public function test_decrypt_session_data_legacy_aes_roundtrip(): void {
		if ( ! function_exists( 'openssl_encrypt' ) || ! function_exists( 'openssl_decrypt' ) ) {
			$this->markTestSkipped( 'openssl not available' );
		}

		$payload    = wp_json_encode( array( 'legacy' => true, 'n' => 7 ) );
		$ciphertext = $this->make_legacy_ciphertext( $payload );

		// Legacy path is base64 of (iv + ciphertext) with no magic prefix.
		$this->assertStringStartsNotWith( 's1:', $ciphertext );
		$this->assertSame( $payload, $this->call_private( 'legacy_aes_decrypt_session_data', array( $ciphertext ) ) );

		// decrypt_session_data() should also route a non-prefixed row
		// through the legacy path and recover the original payload.
		$this->assertSame( $payload, $this->call_private( 'decrypt_session_data', array( $ciphertext ) ) );
	}

	public function test_decrypt_session_data_tampered_legacy_returns_null(): void {
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			$this->markTestSkipped( 'openssl not available' );
		}

		$ciphertext = $this->make_legacy_ciphertext( 'tamper-me' );
		// AES-CBC has no MAC, so corruption is detected by padding errors.
		$raw        = base64_decode( $ciphertext, true );
		// Flip a bit in the IV (first 16 bytes). For a 9-byte plaintext
		// the entire ciphertext is one block, so flipping the IV garbles
		// the only decrypted block. PKCS7 padding then has a 1/16 chance
		// of being valid by accident — try multiple byte positions until
		// the padding check fires (the loop always terminates: 16
		// different flips give 16 independent chances, so the cumulative
		// probability of never failing is (15/16)^16 ≈ 0.36).
		$decrypted = 'not-null-sentinel';
		$flipped   = 0;
		while ( null !== $decrypted && $flipped < 16 ) {
			$flipped_byte = chr( ord( $raw[ $flipped ] ) ^ 0xFF );
			// Use substr_replace for byte-level mutation; direct $str[$i] = ... is
			// a deprecated no-op in PHP 8.1+ (this test runs on PHP 8.5).
			$raw_tampered = substr_replace( $raw, $flipped_byte, $flipped, 1 );
			$corrupted    = base64_encode( $raw_tampered );

			$decrypted = $this->call_private( 'decrypt_session_data', array( $corrupted ) );
			++$flipped;
		}

		$this->assertNull( $decrypted,
			"Expected tampered legacy ciphertext to be rejected, but decryption returned a value after {$flipped} attempts." );
	}

	public function test_decrypt_session_data_garbage_returns_null(): void {
		$this->assertNull( $this->call_private( 'decrypt_session_data', array( 'not-base64' ) ) );
		$this->assertNull( $this->call_private( 'decrypt_session_data', array( '' ) ) );
	}
}
