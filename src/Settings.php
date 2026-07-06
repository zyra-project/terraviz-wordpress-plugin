<?php
/**
 * The Terraviz settings screen (Integration I).
 *
 * @package Terraviz
 */

declare( strict_types = 1 );

namespace Terraviz;

use Terraviz\Api\Catalog;
use Terraviz\Api\Client;
use Terraviz\Api\PublishClient;
use Terraviz\Support\Capabilities;
use Terraviz\Support\Credential;
use Terraviz\Support\Crypto;
use Terraviz\Support\Options;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One options screen under Settings → Terraviz: which node origin embeds
 * target (default the canonical node, overridable), default embed options,
 * and the telemetry posture. There is deliberately **no credential field** —
 * Phase 1 is entirely public read/embed.
 */
final class Settings {

	/**
	 * Settings group / page slug.
	 */
	private const GROUP = 'terraviz_settings_group';
	private const PAGE  = 'terraviz-settings';

	/**
	 * Add the menu page under Settings.
	 */
	public function add_page(): void {
		add_options_page(
			__( 'Terraviz', 'terraviz' ),
			__( 'Terraviz', 'terraviz' ),
			'manage_options',
			self::PAGE,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Register the setting, sections, and fields.
	 */
	public function register(): void {
		register_setting(
			self::GROUP,
			Options::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => Options::defaults(),
			)
		);

		add_settings_section(
			'terraviz_node',
			__( 'Terraviz node', 'terraviz' ),
			function () {
				echo '<p>' . esc_html__( 'Which Terraviz deployment embeds point at. The default is the canonical public node; point it at a partner-operated node if you run your own.', 'terraviz' ) . '</p>';
			},
			self::PAGE
		);

		add_settings_field(
			'origin',
			__( 'Node origin', 'terraviz' ),
			array( $this, 'field_origin' ),
			self::PAGE,
			'terraviz_node'
		);

		add_settings_section(
			'terraviz_defaults',
			__( 'Default embed options', 'terraviz' ),
			function () {
				echo '<p>' . esc_html__( 'Defaults applied to new embeds. Every block and shortcode can override these individually.', 'terraviz' ) . '</p>';
			},
			self::PAGE
		);

		add_settings_field(
			'view_defaults',
			__( 'Default view toggles', 'terraviz' ),
			array( $this, 'field_view_defaults' ),
			self::PAGE,
			'terraviz_defaults'
		);

		add_settings_field(
			'aspect_ratio',
			__( 'Default aspect ratio', 'terraviz' ),
			array( $this, 'field_aspect_ratio' ),
			self::PAGE,
			'terraviz_defaults'
		);

		add_settings_section(
			'terraviz_telemetry',
			__( 'Loading & telemetry', 'terraviz' ),
			function () {
				echo '<p>' . esc_html__( 'The interactive globe runs inside an iframe served from the Terraviz node and carries that node\'s own telemetry. This plugin makes no outbound calls of its own beyond fetching public catalog data for the server-side fallback. To respect visitor consent, embeds can defer loading the globe until the visitor asks for it.', 'terraviz' ) . '</p>';
			},
			self::PAGE
		);

		add_settings_field(
			'telemetry',
			__( 'Globe loading', 'terraviz' ),
			array( $this, 'field_telemetry' ),
			self::PAGE,
			'terraviz_telemetry'
		);

		add_settings_field(
			'lazy_poster',
			__( 'Click-to-load poster', 'terraviz' ),
			array( $this, 'field_lazy_poster' ),
			self::PAGE,
			'terraviz_telemetry'
		);

		$this->register_publishing();
	}

	/**
	 * Register the publishing settings: the capability map and the service-token
	 * slot. Only wired for users who hold the plugin's `manage_terraviz`
	 * capability, so the credential UI never renders for a lesser admin.
	 */
	private function register_publishing(): void {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			return;
		}

		register_setting(
			self::GROUP,
			Credential::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_credential' ),
				'default'           => array(),
				'show_in_rest'      => false,
			)
		);

		add_settings_section(
			'terraviz_publishing',
			__( 'Publishing', 'terraviz' ),
			function () {
				echo '<p>' . esc_html__( 'Configure the optional publisher path: map which WordPress roles may draft versus publish, and store — then validate — a Terraviz service token. Once a token is saved, permitted users manage datasets from the Terraviz Publisher dashboard. Every write is proxied server-side under this one shared token; the token is never sent to the browser.', 'terraviz' ) . '</p>';
			},
			self::PAGE
		);

		add_settings_field(
			'capability_map',
			__( 'Who may publish', 'terraviz' ),
			array( $this, 'field_capability_map' ),
			self::PAGE,
			'terraviz_publishing'
		);

		add_settings_field(
			'credential',
			__( 'Terraviz service token', 'terraviz' ),
			array( $this, 'field_credential' ),
			self::PAGE,
			'terraviz_publishing'
		);
	}

	/**
	 * Sanitise on save and flush caches (so an origin change takes effect).
	 *
	 * @param mixed $input Raw submitted value.
	 * @return array<string,mixed>
	 */
	public function sanitize( $input ): array {
		$clean = Options::sanitize( is_array( $input ) ? $input : array() );

		// An origin change must not serve stale cached data.
		$previous = Options::all();
		if ( ( $previous['origin'] ?? '' ) !== ( $clean['origin'] ?? '' ) ) {
			Catalog::flush();
		}

		return $clean;
	}

	/**
	 * Sanitise the service-token slot on save. Returns the at-rest array
	 * (client id in the clear, secret encrypted) for the Settings API to
	 * persist. The plaintext secret never leaves this method.
	 *
	 * @param mixed $input Raw submitted value.
	 * @return array<string,string>
	 */
	public function sanitize_credential( $input ): array {
		$current = get_option( Credential::OPTION, array() );
		$current = is_array( $current ) ? $current : array();

		// Only a manage_terraviz holder may change the credential; anyone else
		// (should not happen, since the fields aren't rendered for them) leaves
		// it untouched.
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			return $current;
		}

		$input = is_array( $input ) ? $input : array();

		// Explicit "remove" checkbox wins over any typed value.
		if ( ! empty( $input['clear'] ) ) {
			return array(
				'client_id'  => '',
				'secret_enc' => '',
			);
		}

		$client_id = isset( $input['client_id'] ) ? sanitize_text_field( wp_unslash( $input['client_id'] ) ) : '';

		// An empty secret field means "keep the stored secret"; a non-empty one
		// replaces it. Do not run the raw secret through sanitize_text_field —
		// it must survive verbatim to authenticate.
		$secret = isset( $input['client_secret'] ) ? (string) wp_unslash( $input['client_secret'] ) : '';
		$secret = '' === trim( $secret ) ? null : $secret;

		$prepared = Credential::prepare( $client_id, $secret );

		if ( '' !== $prepared['error'] ) {
			$message = 'no_crypto' === $prepared['error']
				? __( 'Could not store the service-token secret: this server has neither the Sodium nor OpenSSL extension, so it cannot be encrypted at rest. The client id was saved; the secret was not.', 'terraviz' )
				: __( 'Could not encrypt the service-token secret. The client id was saved; the secret was not.', 'terraviz' );
			add_settings_error( 'terraviz', 'terraviz_cred_error', $message, 'error' );
		}

		return $prepared['stored'];
	}

	/**
	 * Render the settings page shell.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$this->maybe_test_connection();
		$this->maybe_verify_credential();
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Terraviz', 'terraviz' ); ?></h1>
			<?php settings_errors( 'terraviz' ); ?>
			<form action="options.php" method="post">
				<?php
				settings_fields( self::GROUP );
				do_settings_sections( self::PAGE );
				submit_button();
				?>
			</form>

			<hr />
			<h2><?php echo esc_html__( 'Connection', 'terraviz' ); ?></h2>
			<p><?php echo esc_html__( 'Verify the plugin can reach the configured node\'s public catalog API.', 'terraviz' ); ?></p>
			<form action="" method="post">
				<?php wp_nonce_field( 'terraviz_test_connection', 'terraviz_test_nonce' ); ?>
				<input type="hidden" name="terraviz_action" value="test_connection" />
				<?php submit_button( __( 'Test connection', 'terraviz' ), 'secondary', 'submit', false ); ?>
			</form>

			<?php if ( current_user_can( Capabilities::MANAGE ) && Credential::configured() ) : ?>
				<hr />
				<h2><?php echo esc_html__( 'Verify credential', 'terraviz' ); ?></h2>
				<p><?php echo esc_html__( 'Validate the stored service token against the node with a read-only identity check (GET /api/v1/publish/me). This makes no changes to any Terraviz content.', 'terraviz' ); ?></p>
				<form action="" method="post">
					<?php wp_nonce_field( 'terraviz_verify_credential', 'terraviz_verify_nonce' ); ?>
					<input type="hidden" name="terraviz_action" value="verify_credential" />
					<?php submit_button( __( 'Verify credential', 'terraviz' ), 'secondary', 'submit', false ); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Handle the "Test connection" action: an unauthenticated read probe.
	 */
	private function maybe_test_connection(): void {
		if ( ! isset( $_POST['terraviz_action'] ) || 'test_connection' !== $_POST['terraviz_action'] ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! isset( $_POST['terraviz_test_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['terraviz_test_nonce'] ) ), 'terraviz_test_connection' ) ) {
			return;
		}

		$origin = Options::origin();
		$client = new Client( $origin );
		$doc    = $client->get_json( '/.well-known/terraviz.json' );

		if ( null === $doc ) {
			$catalog = $client->get_json( '/api/v1/catalog' );
			$doc     = ( null !== $catalog ) ? array( 'display_name' => $origin ) : null;
		}

		if ( null === $doc ) {
			add_settings_error(
				'terraviz',
				'terraviz_conn_fail',
				sprintf(
					/* translators: %s: node origin URL. */
					__( 'Could not reach %s. Check the origin and that the node is publicly accessible.', 'terraviz' ),
					esc_html( $origin )
				),
				'error'
			);
		} else {
			$name = isset( $doc['display_name'] ) ? (string) $doc['display_name'] : $origin;
			add_settings_error(
				'terraviz',
				'terraviz_conn_ok',
				sprintf(
					/* translators: %s: node display name. */
					__( 'Connected to %s.', 'terraviz' ),
					esc_html( $name )
				),
				'success'
			);
		}//end if
	}

	/**
	 * Handle the "Verify credential" action: a read-only authenticated probe
	 * of `GET /api/v1/publish/me`. Mutates nothing on the node.
	 */
	private function maybe_verify_credential(): void {
		if ( ! isset( $_POST['terraviz_action'] ) || 'verify_credential' !== $_POST['terraviz_action'] ) {
			return;
		}
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			return;
		}
		if ( ! isset( $_POST['terraviz_verify_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['terraviz_verify_nonce'] ) ), 'terraviz_verify_credential' ) ) {
			return;
		}

		$headers = Credential::headers();
		if ( empty( $headers ) ) {
			add_settings_error(
				'terraviz',
				'terraviz_cred_incomplete',
				__( 'No usable credential is stored. Enter a client id and secret, save, then verify.', 'terraviz' ),
				'error'
			);
			return;
		}

		$result = ( new PublishClient( Options::origin(), $headers ) )->me();

		if ( $result['ok'] ) {
			$profile = is_array( $result['profile'] ) ? $result['profile'] : array();
			$role    = isset( $profile['role'] ) ? (string) $profile['role'] : 'unknown';
			$status  = isset( $profile['status'] ) ? (string) $profile['status'] : 'unknown';
			add_settings_error(
				'terraviz',
				'terraviz_cred_ok',
				sprintf(
					/* translators: 1: publisher role, 2: account status. */
					__( 'Credential valid. The node recognises this token as role “%1$s” (status: %2$s).', 'terraviz' ),
					esc_html( $role ),
					esc_html( $status )
				),
				'success'
			);
			return;
		}

		add_settings_error(
			'terraviz',
			'terraviz_cred_bad',
			$this->credential_error_message( $result ),
			'error'
		);
	}

	/**
	 * Turn a PublishClient::me() failure into an operator-friendly message,
	 * mapping the node's typed error envelopes to plain guidance.
	 *
	 * @param array{status:int,error:string,message:string} $result Probe result.
	 */
	private function credential_error_message( array $result ): string {
		$status = (int) $result['status'];
		$slug   = (string) $result['error'];

		switch ( true ) {
			case 'transport' === $slug || 0 === $status:
				return sprintf(
					/* translators: %s: node origin URL. */
					__( 'Could not reach %s to verify the token. Check the node origin and network access.', 'terraviz' ),
					esc_html( Options::origin() )
				);
			case 401 === $status || 'unauthenticated' === $slug:
				return __( 'The node rejected the token (401 unauthenticated). Re-check the client id and secret.', 'terraviz' );
			case 'pending' === $slug:
				return __( 'The token is valid but the publisher account is awaiting approval on the node. Contact the node operator.', 'terraviz' );
			case 'suspended' === $slug:
				return __( 'The token is valid but the publisher account is suspended on the node.', 'terraviz' );
			case 'access_unconfigured' === $slug || 'binding_missing' === $slug || 503 === $status:
				return __( 'The node is not configured to accept publisher credentials yet (503). This is a node-side setting.', 'terraviz' );
			case 'invalid_response' === $slug:
				return __( 'The node responded but not with a valid identity document. The origin may be fronted by a proxy or login page rather than the Terraviz API.', 'terraviz' );
			default:
				$detail = '' !== $result['message'] ? $result['message'] : sprintf( 'HTTP %d', $status );
				return sprintf(
					/* translators: %s: error detail from the node. */
					__( 'The node rejected the token: %s', 'terraviz' ),
					esc_html( $detail )
				);
		}//end switch
	}

	/**
	 * Origin field.
	 */
	public function field_origin(): void {
		$value = Options::get( 'origin', TERRAVIZ_DEFAULT_ORIGIN );
		printf(
			'<input type="url" class="regular-text code" name="%1$s[origin]" value="%2$s" placeholder="%3$s" />',
			esc_attr( Options::OPTION ),
			esc_attr( (string) $value ),
			esc_attr( TERRAVIZ_DEFAULT_ORIGIN )
		);
		printf(
			'<p class="description">%s</p>',
			sprintf(
				/* translators: %s: canonical origin URL. */
				esc_html__( 'Default: %s', 'terraviz' ),
				esc_html( TERRAVIZ_DEFAULT_ORIGIN )
			)
		);
	}

	/**
	 * View-toggle default checkboxes.
	 */
	public function field_view_defaults(): void {
		$toggles = array(
			'default_terrain' => __( 'Terrain', 'terraviz' ),
			'default_labels'  => __( 'Place labels', 'terraviz' ),
			'default_borders' => __( 'Borders', 'terraviz' ),
			'default_rotate'  => __( 'Auto-rotate', 'terraviz' ),
			'default_chat'    => __( 'Show Orbit chat', 'terraviz' ),
		);

		echo '<fieldset>';
		foreach ( $toggles as $key => $label ) {
			printf(
				'<label style="display:block;margin-bottom:4px;"><input type="checkbox" name="%1$s[%2$s]" value="1" %3$s /> %4$s</label>',
				esc_attr( Options::OPTION ),
				esc_attr( $key ),
				checked( (bool) Options::get( $key, false ), true, false ),
				esc_html( $label )
			);
		}
		echo '</fieldset>';
	}

	/**
	 * Aspect-ratio field.
	 */
	public function field_aspect_ratio(): void {
		$value = (string) Options::get( 'aspect_ratio', '16:9' );
		printf(
			'<input type="text" class="small-text" name="%1$s[aspect_ratio]" value="%2$s" pattern="\d{1,3}:\d{1,3}" />',
			esc_attr( Options::OPTION ),
			esc_attr( $value )
		);
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Width:height, e.g. 16:9 or 4:3.', 'terraviz' )
		);
	}

	/**
	 * Telemetry / load-posture radios.
	 */
	public function field_telemetry(): void {
		$value   = (string) Options::get( 'telemetry', 'lazy' );
		$options = array(
			'lazy'  => __( 'Load when scrolled into view (recommended)', 'terraviz' ),
			'eager' => __( 'Load as soon as the page loads', 'terraviz' ),
		);

		echo '<fieldset>';
		foreach ( $options as $key => $label ) {
			printf(
				'<label style="display:block;margin-bottom:4px;"><input type="radio" name="%1$s[telemetry]" value="%2$s" %3$s /> %4$s</label>',
				esc_attr( Options::OPTION ),
				esc_attr( $key ),
				checked( $value, $key, false ),
				esc_html( $label )
			);
		}
		echo '</fieldset>';
	}

	/**
	 * Click-to-load poster checkbox.
	 */
	public function field_lazy_poster(): void {
		printf(
			'<label><input type="checkbox" name="%1$s[lazy_poster]" value="1" %2$s /> %3$s</label>',
			esc_attr( Options::OPTION ),
			checked( (bool) Options::get( 'lazy_poster', true ), true, false ),
			esc_html__( 'Show a thumbnail with a "Load interactive globe" button; only load the globe when the visitor clicks.', 'terraviz' )
		);
	}

	/**
	 * Read-only display of how WordPress roles map to publish intent. This is
	 * WordPress-side policy: because every publish call is proxied under one
	 * shared Terraviz identity, the plugin — not Terraviz — decides who may do
	 * what through it.
	 */
	public function field_capability_map(): void {
		$rows = array(
			array( __( 'Administrator (or a role with “Manage Terraviz”)', 'terraviz' ), Capabilities::intent_label( Capabilities::INTENT_CONFIGURE ) ),
			array( __( 'Editor', 'terraviz' ), Capabilities::intent_label( Capabilities::INTENT_PUBLISH ) ),
			array( __( 'Author', 'terraviz' ), Capabilities::intent_label( Capabilities::INTENT_DRAFT ) ),
			array( __( 'Contributor / Subscriber', 'terraviz' ), Capabilities::intent_label( Capabilities::INTENT_EMBED ) ),
		);

		echo '<table class="widefat striped" style="max-width:40em;"><thead><tr>';
		echo '<th scope="col">' . esc_html__( 'WordPress role', 'terraviz' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Intended publish access', 'terraviz' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			printf( '<tr><td>%s</td><td>%s</td></tr>', esc_html( $row[0] ), esc_html( $row[1] ) );
		}
		echo '</tbody></table>';

		$mine = Capabilities::intent_label( Capabilities::intent_for() );
		printf(
			'<p class="description">%s</p>',
			sprintf(
				/* translators: %s: the current user's mapped publish access. */
				esc_html__( 'Your account currently maps to: %s. This mapping is not enforced yet — publishing is a later release.', 'terraviz' ),
				'<strong>' . esc_html( $mine ) . '</strong>'
			)
		);
	}

	/**
	 * The service-token slot: a client-id/secret pair, plus a "remove"
	 * checkbox. The stored secret is never echoed — only a "saved" marker.
	 */
	public function field_credential(): void {
		$client_id  = Credential::client_id();
		$has_secret = Credential::has_secret();

		if ( ! Crypto::available() ) {
			printf(
				'<p class="notice notice-warning" style="padding:8px 12px;">%s</p>',
				esc_html__( 'This server has neither the Sodium nor OpenSSL PHP extension, so a service-token secret cannot be encrypted at rest. The secret field is disabled until one is available.', 'terraviz' )
			);
		}

		echo '<fieldset>';

		printf(
			'<p><label for="terraviz_cred_client_id" style="display:block;font-weight:600;">%1$s</label>' .
			'<input type="text" id="terraviz_cred_client_id" class="regular-text code" name="%2$s[client_id]" value="%3$s" autocomplete="off" spellcheck="false" placeholder="xxxxxxxx.access" /></p>',
			esc_html__( 'Client ID', 'terraviz' ),
			esc_attr( Credential::OPTION ),
			esc_attr( $client_id )
		);

		$secret_placeholder = $has_secret
			? __( 'A secret is saved — leave blank to keep it', 'terraviz' )
			: __( 'Paste the client secret', 'terraviz' );

		printf(
			'<p><label for="terraviz_cred_client_secret" style="display:block;font-weight:600;">%1$s</label>' .
			'<input type="password" id="terraviz_cred_client_secret" class="regular-text code" name="%2$s[client_secret]" value="" autocomplete="new-password" spellcheck="false" placeholder="%3$s" %4$s /></p>',
			esc_html__( 'Client Secret', 'terraviz' ),
			esc_attr( Credential::OPTION ),
			esc_attr( $secret_placeholder ),
			Crypto::available() ? '' : 'disabled'
		);

		if ( $client_id || $has_secret ) {
			printf(
				'<p><label><input type="checkbox" name="%1$s[clear]" value="1" /> %2$s</label></p>',
				esc_attr( Credential::OPTION ),
				esc_html__( 'Remove the stored credential', 'terraviz' )
			);
		}

		echo '</fieldset>';

		printf(
			'<p class="description">%s</p>',
			esc_html__( 'A Cloudflare Access service token (a Client ID + Client Secret pair) that authenticates the publisher path. The secret is encrypted at rest and never sent to the browser. Use “Verify credential” below to confirm it works.', 'terraviz' )
		);
	}
}
