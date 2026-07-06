<?php
/**
 * Symmetric encryption-at-rest for the plugin's single stored secret.
 *
 * @package Terraviz
 */

declare( strict_types = 1 );

namespace Terraviz\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Encrypts a short secret (the service-token client secret) for storage in
 * `wp_options`, keyed off this site's auth salt so the ciphertext is bound to
 * the site and never travels with a database dump to a different install.
 *
 * Prefers libsodium's authenticated `secretbox` (in PHP core since 7.2);
 * falls back to OpenSSL AES-256-GCM. Both are authenticated (AEAD), so a
 * tampered ciphertext fails to decrypt rather than yielding garbage. If
 * neither extension is present, encryption is unavailable and the caller must
 * refuse to store the secret rather than persist it in the clear.
 *
 * Key rotation caveat: the key derives from `wp_salt('auth')`. If an operator
 * rotates their WordPress salts, previously stored ciphertext becomes
 * undecryptable and the credential must be re-entered — an acceptable,
 * fail-safe outcome (a stale secret stops working rather than leaking).
 */
final class Crypto {

	/**
	 * Envelope version + algorithm markers, so a future format change can be
	 * distinguished on read.
	 */
	private const V_SODIUM  = 'tvz1.s.';
	private const V_OPENSSL = 'tvz1.o.';

	/**
	 * The OpenSSL cipher used for the fallback path.
	 */
	private const OPENSSL_CIPHER = 'aes-256-gcm';

	/**
	 * Whether any supported encryption backend is available.
	 */
	public static function available(): bool {
		return function_exists( 'sodium_crypto_secretbox' )
			|| ( function_exists( 'openssl_encrypt' ) && function_exists( 'openssl_random_pseudo_bytes' ) );
	}

	/**
	 * Encrypt a plaintext secret, returning an opaque, self-describing token
	 * safe to store in an option, or null if no backend is available.
	 *
	 * @param string $plaintext Secret to protect.
	 */
	public static function encrypt( string $plaintext ): ?string {
		if ( function_exists( 'sodium_crypto_secretbox' ) ) {
			$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$key    = self::key( SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
			$cipher = sodium_crypto_secretbox( $plaintext, $nonce, $key );

			// Base64 here is transport framing for binary ciphertext, not obfuscation.
			$token = self::V_SODIUM . base64_encode( $nonce . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode

			if ( function_exists( 'sodium_memzero' ) ) {
				sodium_memzero( $key );
			}

			return $token;
		}

		if ( function_exists( 'openssl_encrypt' ) && function_exists( 'openssl_random_pseudo_bytes' ) ) {
			$iv = openssl_random_pseudo_bytes( 12 );
			// A false return means the CSPRNG could not produce bytes; refuse to
			// encrypt with a bad IV rather than emit a weak/invalid ciphertext.
			if ( false === $iv ) {
				return null;
			}
			$key    = self::key( 32 );
			$tag    = '';
			$cipher = openssl_encrypt( $plaintext, self::OPENSSL_CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag );
			if ( false === $cipher ) {
				return null;
			}

			return self::V_OPENSSL . base64_encode( $iv . $tag . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		}

		return null;
	}

	/**
	 * Decrypt a token produced by encrypt(). Returns null if the token is
	 * malformed, was written with an unavailable backend, or fails
	 * authentication (wrong key / tampered).
	 *
	 * @param string $token Opaque token from encrypt().
	 */
	public static function decrypt( string $token ): ?string {
		if ( 0 === strpos( $token, self::V_SODIUM ) ) {
			if ( ! function_exists( 'sodium_crypto_secretbox_open' ) ) {
				return null;
			}
			$raw = base64_decode( substr( $token, strlen( self::V_SODIUM ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
				return null;
			}
			$nonce  = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$cipher = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$key    = self::key( SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
			$plain  = sodium_crypto_secretbox_open( $cipher, $nonce, $key );
			if ( function_exists( 'sodium_memzero' ) ) {
				sodium_memzero( $key );
			}

			return false === $plain ? null : $plain;
		}

		if ( 0 === strpos( $token, self::V_OPENSSL ) ) {
			if ( ! function_exists( 'openssl_decrypt' ) ) {
				return null;
			}
			$raw = base64_decode( substr( $token, strlen( self::V_OPENSSL ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			// 12-byte IV + 16-byte GCM tag = 28 bytes of framing minimum.
			if ( false === $raw || strlen( $raw ) <= 28 ) {
				return null;
			}
			$iv     = substr( $raw, 0, 12 );
			$tag    = substr( $raw, 12, 16 );
			$cipher = substr( $raw, 28 );
			$key    = self::key( 32 );
			$plain  = openssl_decrypt( $cipher, self::OPENSSL_CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag );

			return false === $plain ? null : $plain;
		}

		return null;
	}

	/**
	 * Derive a fixed-length key bound to this site's auth salt, domain-
	 * separated so it is never reused for another purpose.
	 *
	 * @param int $length Desired key length in bytes.
	 */
	private static function key( int $length ): string {
		$material = 'terraviz-credential-v1|' . wp_salt( 'auth' );

		if ( function_exists( 'sodium_crypto_generichash' ) ) {
			return sodium_crypto_generichash( $material, '', $length );
		}

		// hash() with raw output gives 32 bytes from sha256 — the only length
		// this fallback is ever asked for (AES-256 / secretbox keys).
		return substr( hash( 'sha256', $material, true ), 0, $length );
	}
}
