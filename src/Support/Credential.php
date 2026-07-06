<?php
/**
 * The Cloudflare Access service-token slot for the publisher path.
 *
 * @package Terraviz
 */

declare( strict_types = 1 );

namespace Terraviz\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores the Terraviz publish credential: a Cloudflare Access **service
 * token**, which is a `Cf-Access-Client-Id` + `Cf-Access-Client-Secret` pair
 * (upstream WORDPRESS_INTEGRATION_PLAN §5, Option 1; the same pair the
 * Terraviz CLI sends). Cloudflare's edge exchanges the pair for a JWT before
 * the request ever reaches the node, so the plugin only ever sends the two
 * headers.
 *
 * The stored token authenticates the server-side publish path: `PublishClient`
 * attaches it to every dataset write, and the read-only
 * `GET /api/v1/publish/me` probe validates it from the settings screen.
 *
 * Storage posture:
 *  - Kept in its **own** option, deliberately *not* in the main
 *    `terraviz_settings` array — that array is read in many places and would
 *    be easy to leak into a template or REST response.
 *  - The client id is only semi-secret (it surfaces server-side as the
 *    `<client-id>@service.local` audit identity), so it is stored in the
 *    clear. The client **secret** is encrypted at rest via {@see Crypto}.
 *  - The secret is **never** rendered back to the browser or returned by any
 *    REST route; the settings screen shows only a "saved" marker.
 */
final class Credential {

	/**
	 * The option name in wp_options.
	 */
	public const OPTION = 'terraviz_credential';

	/**
	 * The stored credential shape, normalised.
	 *
	 * @return array{client_id:string,secret_enc:string}
	 */
	private static function stored(): array {
		$raw = get_option( self::OPTION, array() );
		if ( ! is_array( $raw ) ) {
			$raw = array();
		}

		return array(
			'client_id'  => isset( $raw['client_id'] ) ? (string) $raw['client_id'] : '',
			'secret_enc' => isset( $raw['secret_enc'] ) ? (string) $raw['secret_enc'] : '',
		);
	}

	/**
	 * The stored client id (may be empty). Safe to display.
	 */
	public static function client_id(): string {
		return self::stored()['client_id'];
	}

	/**
	 * Whether an encrypted secret is on file.
	 */
	public static function has_secret(): bool {
		return '' !== self::stored()['secret_enc'];
	}

	/**
	 * Whether the credential is fully populated (both halves present).
	 */
	public static function configured(): bool {
		$s = self::stored();

		return '' !== $s['client_id'] && '' !== $s['secret_enc'];
	}

	/**
	 * Decrypt and return the client secret for a **server-side** call, or null
	 * if none is stored or decryption fails. Never expose the return value to
	 * the browser.
	 */
	public static function secret(): ?string {
		$enc = self::stored()['secret_enc'];
		if ( '' === $enc ) {
			return null;
		}

		return Crypto::decrypt( $enc );
	}

	/**
	 * The Cloudflare Access headers for an authenticated request, or an empty
	 * array when the credential is incomplete or undecryptable.
	 *
	 * @return array<string,string>
	 */
	public static function headers(): array {
		$id     = self::client_id();
		$secret = self::secret();
		if ( '' === $id || null === $secret ) {
			return array();
		}

		return array(
			'Cf-Access-Client-Id'     => $id,
			'Cf-Access-Client-Secret' => $secret,
		);
	}

	/**
	 * Compute the at-rest option array for a client id + optional secret,
	 * **without** persisting. The client id is kept verbatim; the secret is
	 * encrypted. Passing a null/empty secret keeps whatever secret is already
	 * on file (so a settings re-save can omit it).
	 *
	 * Returned so callers that persist via the Settings API can hand the array
	 * straight back to WordPress without a second write.
	 *
	 * On a crypto error the **new client id is still kept** (paired with the
	 * previously stored secret), matching the operator-facing message that the
	 * id was saved but the secret was not — the id is only semi-secret and
	 * re-typing it on every retry would be a poor experience.
	 *
	 * @param string      $client_id     Service-token client id.
	 * @param string|null $client_secret New secret, or null to keep the stored one.
	 * @return array{stored:array{client_id:string,secret_enc:string},error:string}
	 *         `error` is '' on success, else 'no_crypto' | 'encrypt_failed'
	 *         (with `stored` carrying the new id + the previously stored secret).
	 */
	public static function prepare( string $client_id, ?string $client_secret ): array {
		$current   = self::stored();
		$client_id = trim( $client_id );

		$secret_enc = $current['secret_enc'];
		$error      = '';
		if ( null !== $client_secret && '' !== trim( $client_secret ) ) {
			if ( ! Crypto::available() ) {
				$error = 'no_crypto';
			} else {
				$encrypted = Crypto::encrypt( trim( $client_secret ) );
				if ( null === $encrypted ) {
					$error = 'encrypt_failed';
				} else {
					$secret_enc = $encrypted;
				}
			}
		}

		return array(
			'stored' => array(
				'client_id'  => $client_id,
				'secret_enc' => $secret_enc,
			),
			'error'  => $error,
		);
	}

	/**
	 * Persist the credential (encrypting the secret). A convenience over
	 * prepare() for programmatic callers; the settings screen uses prepare()
	 * and lets the Settings API do the write.
	 *
	 * @param string      $client_id     Service-token client id.
	 * @param string|null $client_secret New secret, or null to keep the stored one.
	 * @return array{ok:bool,error:string} Result; `error` is '' on success.
	 */
	public static function save( string $client_id, ?string $client_secret ): array {
		$prepared = self::prepare( $client_id, $client_secret );
		if ( '' !== $prepared['error'] ) {
			return array(
				'ok'    => false,
				'error' => $prepared['error'],
			);
		}

		update_option( self::OPTION, $prepared['stored'], false );

		return array(
			'ok'    => true,
			'error' => '',
		);
	}

	/**
	 * Remove the stored credential entirely.
	 */
	public static function clear(): void {
		delete_option( self::OPTION );
	}
}
