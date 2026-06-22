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

		// Reset static active-session cache between tests.
		$cache_prop = new \ReflectionProperty( \SScribe_Session::class, 'active_session_cache' );
		$cache_prop->setValue( null, array() );

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

	public function test_decrypt_session_data_legacy_aes_roundtrip(): void {
		if ( ! function_exists( 'openssl_encrypt' ) || ! function_exists( 'openssl_decrypt' ) ) {
			$this->markTestSkipped( 'openssl not available' );
		}

		$payload    = wp_json_encode( array( 'legacy' => true, 'n' => 7 ) );
		$ciphertext = $this->call_private( 'legacy_aes_encrypt_session_data', array( $payload ) );

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

		$ciphertext = $this->call_private( 'legacy_aes_encrypt_session_data', array( 'tamper-me' ) );
		// AES-CBC has no MAC, so corruption is detected by padding errors.
		$raw        = base64_decode( $ciphertext, true );
		$raw[0]     = chr( ord( $raw[0] ) ^ 0xFF );
		$corrupted  = base64_encode( $raw );

		$this->assertNull( $this->call_private( 'decrypt_session_data', array( $corrupted ) ) );
	}

	public function test_decrypt_session_data_garbage_returns_null(): void {
		$this->assertNull( $this->call_private( 'decrypt_session_data', array( 'not-base64' ) ) );
		$this->assertNull( $this->call_private( 'decrypt_session_data', array( '' ) ) );
	}
}
