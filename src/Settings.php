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
	 * Render the settings page shell.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$this->maybe_test_connection();
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Terraviz', 'terraviz' ); ?></h1>
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
		}

		settings_errors( 'terraviz' );
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
}
