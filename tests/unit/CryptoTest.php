<?php
/**
 * Tests for encryption-at-rest of the stored secret.
 *
 * @package Terraviz
 */

declare( strict_types = 1 );

use Terraviz\Support\Crypto;

/**
 * @covers \Terraviz\Support\Crypto
 */
class CryptoTest extends WP_UnitTestCase {

	public function test_backend_is_available_in_ci(): void {
		// CI runs on stock PHP with sodium (7.4+) or openssl; one must exist.
		$this->assertTrue( Crypto::available() );
	}

	public function test_round_trip_ascii(): void {
		$secret = 'sk_live_abcDEF123456.access-secret';
		$token  = Crypto::encrypt( $secret );

		$this->assertIsString( $token );
		$this->assertNotSame( '', $token );
		$this->assertSame( $secret, Crypto::decrypt( $token ) );
	}

	public function test_round_trip_multibyte(): void {
		$secret = 'sécrèt-clé-Ωμέγα';
		$this->assertSame( $secret, Crypto::decrypt( Crypto::encrypt( $secret ) ) );
	}

	public function test_ciphertext_is_not_plaintext(): void {
		$secret = 'plain-secret-value';
		$token  = Crypto::encrypt( $secret );
		$this->assertStringNotContainsString( $secret, $token );
	}

	public function test_nonce_is_randomised(): void {
		$secret = 'same-input';
		$this->assertNotSame( Crypto::encrypt( $secret ), Crypto::encrypt( $secret ) );
	}

	public function test_tampered_token_fails_authentication(): void {
		$token   = Crypto::encrypt( 'authentic' );
		$tampered = substr( $token, 0, -4 ) . 'AAAA';
		$this->assertNull( Crypto::decrypt( $tampered ) );
	}

	public function test_garbage_token_returns_null(): void {
		$this->assertNull( Crypto::decrypt( 'not-a-real-token' ) );
		$this->assertNull( Crypto::decrypt( '' ) );
	}
}
