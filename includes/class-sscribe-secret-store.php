<?php
/**
 * SScribe Secret Store
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
 * Encrypts small secrets, such as destination passwords, for storage in options.
 *
 * Sealed values carry a version prefix: "sv1:" for libsodium secretbox and
 * "sv2:" for AES-256-GCM when libsodium is missing. Both use one 32-byte key
 * kept in its own option, separate from the session keys, so rotating the
 * session keys never locks stored destinations out.
 */
final class SScribe_Secret_Store {

	public const KEY_OPTION = 'sscribe_secret_key';

	private const PREFIX_SODIUM  = 'sv1:';
	private const PREFIX_OPENSSL = 'sv2:';
	private const KEY_BYTES      = 32;
	private const GCM_NONCE      = 12;
	private const GCM_TAG        = 16;

	/**
	 * Encrypt a secret.
	 *
	 * @param string $plaintext Secret to protect.
	 * @return string Sealed value safe to store.
	 * @throws RuntimeException When no encryption provider is available.
	 */
	public static function seal( string $plaintext ): string {
		$key = self::key();

		if ( function_exists( 'sodium_crypto_secretbox' ) ) {
			$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

			return self::PREFIX_SODIUM . self::encode( $nonce . sodium_crypto_secretbox( $plaintext, $nonce, $key ) );
		}

		return self::PREFIX_OPENSSL . self::encode( self::gcm_encrypt( $plaintext, $key ) );
	}

	/**
	 * Decrypt a sealed secret.
	 *
	 * @param string $sealed Value produced by seal().
	 * @return string The secret.
	 * @throws RuntimeException When the value is not sealed, was changed, or the key is gone.
	 */
	public static function open( string $sealed ): string {
		if ( str_starts_with( $sealed, self::PREFIX_SODIUM ) ) {
			$plaintext = self::sodium_open( self::decode( substr( $sealed, strlen( self::PREFIX_SODIUM ) ) ) );
		} elseif ( str_starts_with( $sealed, self::PREFIX_OPENSSL ) ) {
			$plaintext = self::gcm_decrypt( self::decode( substr( $sealed, strlen( self::PREFIX_OPENSSL ) ) ) );
		} else {
			throw new RuntimeException( 'The value is not a sealed secret.' );
		}

		if ( null === $plaintext ) {
			throw new RuntimeException( 'The sealed secret could not be opened.' );
		}

		return $plaintext;
	}

	/**
	 * Whether a value looks like the output of seal().
	 *
	 * @param string $value Stored value.
	 * @return bool
	 */
	public static function is_sealed( string $value ): bool {
		return 1 === preg_match( '/^sv[12]:[A-Za-z0-9_-]{20,}$/D', $value );
	}

	/**
	 * Decrypt a secretbox payload.
	 *
	 * @param string|null $raw Nonce followed by ciphertext.
	 * @return string|null
	 */
	private static function sodium_open( ?string $raw ): ?string {
		if ( null === $raw || ! function_exists( 'sodium_crypto_secretbox_open' ) ) {
			return null;
		}
		if ( strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return null;
		}

		$plaintext = sodium_crypto_secretbox_open(
			substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
			substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
			self::key()
		);

		return false === $plaintext ? null : $plaintext;
	}

	/**
	 * Encrypt with AES-256-GCM.
	 *
	 * @param string $plaintext Secret.
	 * @param string $key       32-byte key.
	 * @return string Nonce, tag and ciphertext.
	 * @throws RuntimeException When OpenSSL cannot encrypt.
	 */
	private static function gcm_encrypt( string $plaintext, string $key ): string {
		if ( ! function_exists( 'openssl_encrypt' ) || ! in_array( 'aes-256-gcm', openssl_get_cipher_methods(), true ) ) {
			throw new RuntimeException( 'Neither libsodium nor OpenSSL AES-256-GCM is available.' );
		}

		$nonce      = random_bytes( self::GCM_NONCE );
		$tag        = '';
		$ciphertext = openssl_encrypt( $plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, '', self::GCM_TAG );
		if ( false === $ciphertext || self::GCM_TAG !== strlen( $tag ) ) {
			throw new RuntimeException( 'The secret could not be encrypted.' );
		}

		return $nonce . $tag . $ciphertext;
	}

	/**
	 * Decrypt an AES-256-GCM payload.
	 *
	 * @param string|null $raw Nonce, tag and ciphertext.
	 * @return string|null
	 */
	private static function gcm_decrypt( ?string $raw ): ?string {
		if ( null === $raw || ! function_exists( 'openssl_decrypt' ) || strlen( $raw ) < self::GCM_NONCE + self::GCM_TAG ) {
			return null;
		}

		$plaintext = openssl_decrypt(
			substr( $raw, self::GCM_NONCE + self::GCM_TAG ),
			'aes-256-gcm',
			self::key(),
			OPENSSL_RAW_DATA,
			substr( $raw, 0, self::GCM_NONCE ),
			substr( $raw, self::GCM_NONCE, self::GCM_TAG )
		);

		return false === $plaintext ? null : $plaintext;
	}

	/**
	 * The encryption key, created on first use.
	 *
	 * @return string 32 raw bytes.
	 * @throws RuntimeException When the key cannot be stored.
	 */
	private static function key(): string {
		$key = self::stored_key();
		if ( null !== $key ) {
			return $key;
		}

		add_option( self::KEY_OPTION, base64_encode( random_bytes( self::KEY_BYTES ) ), '', 'no' );
		$key = self::stored_key();
		if ( null === $key ) {
			throw new RuntimeException( 'The secret encryption key could not be stored.' );
		}

		return $key;
	}

	/**
	 * The stored key, or null when missing or malformed.
	 *
	 * @return string|null
	 */
	private static function stored_key(): ?string {
		$stored = get_option( self::KEY_OPTION, '' );
		if ( ! is_string( $stored ) || '' === $stored ) {
			return null;
		}
		$key = base64_decode( $stored, true );

		return false !== $key && self::KEY_BYTES === strlen( $key ) ? $key : null;
	}

	/**
	 * URL-safe base64 without padding.
	 *
	 * @param string $raw Bytes.
	 * @return string
	 */
	private static function encode( string $raw ): string {
		return rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' );
	}

	/**
	 * Reverse encode().
	 *
	 * @param string $encoded Encoded bytes.
	 * @return string|null
	 */
	private static function decode( string $encoded ): ?string {
		$padding = ( 4 - ( strlen( $encoded ) % 4 ) ) % 4;
		$raw     = base64_decode( strtr( $encoded, '-_', '+/' ) . str_repeat( '=', $padding ), true );

		return false === $raw ? null : $raw;
	}
}
