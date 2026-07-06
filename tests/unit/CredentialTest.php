<?php
/**
 * Tests for the inert service-token credential slot.
 *
 * @package Terraviz
 */

declare( strict_types = 1 );

use Terraviz\Support\Credential;
use Terraviz\Support\Crypto;

/**
 * @covers \Terraviz\Support\Credential
 */
class CredentialTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		Credential::clear();
	}

	public function test_empty_by_default(): void {
		$this->assertSame( '', Credential::client_id() );
		$this->assertFalse( Credential::has_secret() );
		$this->assertFalse( Credential::configured() );
		$this->assertSame( array(), Credential::headers() );
	}

	public function test_save_and_read_back(): void {
		$result = Credential::save( 'abc123.access', 'super-secret' );
		$this->assertTrue( $result['ok'] );

		$this->assertSame( 'abc123.access', Credential::client_id() );
		$this->assertTrue( Credential::has_secret() );
		$this->assertTrue( Credential::configured() );
		$this->assertSame( 'super-secret', Credential::secret() );
	}

	public function test_secret_is_encrypted_at_rest(): void {
		Credential::save( 'abc123.access', 'super-secret' );

		$raw = get_option( Credential::OPTION );
		$this->assertIsArray( $raw );
		// The stored blob is not the plaintext.
		$this->assertStringNotContainsString( 'super-secret', (string) $raw['secret_enc'] );
		// But it decrypts back to the plaintext.
		$this->assertSame( 'super-secret', Crypto::decrypt( (string) $raw['secret_enc'] ) );
	}

	public function test_headers_form_the_service_token_pair(): void {
		Credential::save( 'abc123.access', 'super-secret' );

		$headers = Credential::headers();
		$this->assertSame( 'abc123.access', $headers['Cf-Access-Client-Id'] );
		$this->assertSame( 'super-secret', $headers['Cf-Access-Client-Secret'] );
	}

	public function test_prepare_with_null_secret_keeps_existing(): void {
		Credential::save( 'abc123.access', 'first-secret' );
		$stored_enc = get_option( Credential::OPTION )['secret_enc'];

		$prepared = Credential::prepare( 'renamed.access', null );
		$this->assertSame( '', $prepared['error'] );
		$this->assertSame( 'renamed.access', $prepared['stored']['client_id'] );
		$this->assertSame( $stored_enc, $prepared['stored']['secret_enc'] );
	}

	public function test_clear_removes_everything(): void {
		Credential::save( 'abc123.access', 'super-secret' );
		Credential::clear();

		$this->assertFalse( Credential::configured() );
		$this->assertSame( array(), Credential::headers() );
	}
}
