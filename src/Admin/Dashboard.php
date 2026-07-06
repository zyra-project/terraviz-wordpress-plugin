<?php
/**
 * The wp-admin publisher dashboard page (Phase 3a).
 *
 * @package Terraviz
 */

declare( strict_types = 1 );

namespace Terraviz\Admin;

use Terraviz\Support\Capabilities;
use Terraviz\Support\Credential;
use Terraviz\Support\Options;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the "Terraviz" top-level admin menu that hosts the dataset
 * publisher dashboard — a React (`@wordpress/element`) app that talks to the
 * same-origin `terraviz/v1/publisher/*` REST proxy. All catalog mutations run
 * server-side under the stored service token; the token never reaches this
 * screen.
 *
 * The menu is shown to the draft tier and up (WP `publish_posts`); the REST
 * layer re-checks the precise Phase-2 capability on every call, so the menu
 * capability is only UX.
 */
final class Dashboard {

	/**
	 * Admin page slug.
	 */
	public const PAGE = 'terraviz-publisher';

	/**
	 * Script/style handle.
	 */
	private const HANDLE = 'terraviz-publisher';

	/**
	 * Add the top-level menu page. Hooked on `admin_menu`.
	 */
	public function add_page(): void {
		add_menu_page(
			__( 'Terraviz Publisher', 'terraviz' ),
			__( 'Terraviz', 'terraviz' ),
			'publish_posts',
			self::PAGE,
			array( $this, 'render' ),
			'dashicons-admin-site-alt3',
			58
		);
	}

	/**
	 * Render the app mount point.
	 */
	public function render(): void {
		if ( ! Capabilities::can_draft() ) {
			wp_die( esc_html__( 'You do not have permission to publish Terraviz datasets.', 'terraviz' ) );
		}

		echo '<div class="wrap"><h1>' . esc_html__( 'Terraviz Publisher', 'terraviz' ) . '</h1>';

		if ( ! $this->assets_built() ) {
			echo '<div class="notice notice-warning"><p>' .
				esc_html__( 'The Terraviz publisher assets have not been built. Run `npm run build` in the plugin directory.', 'terraviz' ) .
				'</p></div>';
		}

		echo '<div id="terraviz-publisher-app"></div></div>';
	}

	/**
	 * Enqueue the dashboard app on its own screen only. Hooked on
	 * `admin_enqueue_scripts`.
	 *
	 * @param string $hook Current admin page hook suffix.
	 */
	public function enqueue( string $hook ): void {
		if ( 'toplevel_page_' . self::PAGE !== $hook ) {
			return;
		}
		if ( ! $this->assets_built() ) {
			return;
		}

		$asset = require TERRAVIZ_PLUGIN_DIR . 'build/admin/index.asset.php';

		wp_enqueue_script(
			self::HANDLE,
			TERRAVIZ_PLUGIN_URL . 'build/admin/index.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_enqueue_style( 'wp-components' );
		wp_set_script_translations( self::HANDLE, 'terraviz' );

		wp_add_inline_script(
			self::HANDLE,
			'window.terravizPublisher = ' . wp_json_encode( $this->boot_data() ) . ';',
			'before'
		);
	}

	/**
	 * The config handed to the React app.
	 *
	 * @return array<string,mixed>
	 */
	private function boot_data(): array {
		return array(
			'restRoot'             => esc_url_raw( untrailingslashit( rest_url( 'terraviz/v1/publisher' ) ) ),
			'nonce'                => wp_create_nonce( 'wp_rest' ),
			'origin'               => Options::origin(),
			'canPublish'           => Capabilities::can_publish(),
			'canConfigure'         => Capabilities::can_configure(),
			'credentialConfigured' => Credential::configured(),
			'settingsUrl'          => admin_url( 'options-general.php?page=terraviz-settings' ),
		);
	}

	/**
	 * Whether the compiled dashboard bundle is present.
	 */
	private function assets_built(): bool {
		return is_readable( TERRAVIZ_PLUGIN_DIR . 'build/admin/index.js' )
			&& is_readable( TERRAVIZ_PLUGIN_DIR . 'build/admin/index.asset.php' );
	}
}
