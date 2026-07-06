<?php
/**
 * Tests for the settings screen's credential save path.
 *
 * @package Terraviz
 */

declare( strict_types = 1 );

use Terraviz\Settings;
use Terraviz\Support\Capabilities;
use Terraviz\Support\Credential;
use Terraviz\Support\Crypto;

/**
 * @covers \Terraviz\Settings::sanitize_credential
 */
class SettingsTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		// Storing/reading an encrypted secret needs a crypto backend; skip
		// cleanly without one, mirroring CredentialTest.
		if ( ! Crypto::available() ) {
			$this->markTestSkipped( 'No Sodium or OpenSSL backend available for encryption.' );
		}
		Credential::clear();

		// The credential only sanitises for a manage_terraviz holder.
		Capabilities::grant();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * WordPress runs a register_setting sanitize callback twice when the option
	 * row is first created (update_option -> add_option). The second pass must
	 * not drop the secret just encrypted by the first. Regression for the
	 * first-save credential-loss bug.
	 */
	public function test_first_save_persists_secret_through_double_sanitize(): void {
		( new Settings() )->register();

		// The option was cleared in set_up(), so this save takes the add_option
		// path and sanitize_credential runs twice.
		update_option(
			Credential::OPTION,
			array(
				'client_id'     => 'abc123.access',
				'client_secret' => 'super-secret',
			)
		);

		$this->assertTrue( Credential::configured(), 'Credential should be configured after a first save.' );
		$this->assertSame( 'super-secret', Credential::secret() );
	}

	/**
	 * Calling the sanitiser on its own output (as WordPress does on the second
	 * pass) is idempotent and preserves the encrypted secret.
	 */
	public function test_double_sanitize_is_idempotent(): void {
		$settings = new Settings();

		$first  = $settings->sanitize_credential(
			array(
				'client_id'     => 'abc123.access',
				'client_secret' => 'super-secret',
			)
		);
		$second = $settings->sanitize_credential( $first );

		$this->assertNotSame( '', $first['secret_enc'] );
		$this->assertSame( $first['secret_enc'], $second['secret_enc'] );
		$this->assertSame( 'abc123.access', $second['client_id'] );
	}

	/**
	 * A re-save that leaves the secret field blank keeps the stored secret and
	 * still updates the client id.
	 */
	public function test_blank_secret_resave_keeps_stored_secret(): void {
		Credential::save( 'abc123.access', 'super-secret' );
		( new Settings() )->register();

		update_option(
			Credential::OPTION,
			array(
				'client_id'     => 'renamed.access',
				'client_secret' => '',
			)
		);

		$this->assertSame( 'renamed.access', Credential::client_id() );
		$this->assertTrue( Credential::configured() );
		$this->assertSame( 'super-secret', Credential::secret() );
	}

	/**
	 * The "remove the stored credential" checkbox wipes both halves.
	 */
	public function test_clear_checkbox_removes_credential(): void {
		Credential::save( 'abc123.access', 'super-secret' );
		( new Settings() )->register();

		update_option(
			Credential::OPTION,
			array(
				'client_id' => 'abc123.access',
				'clear'     => '1',
			)
		);

		$this->assertFalse( Credential::configured() );
	}
}
