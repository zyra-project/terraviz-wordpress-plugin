<?php
/**
 * Plugin bootstrap and hook wiring.
 *
 * @package Terraviz
 */

declare( strict_types = 1 );

namespace Terraviz;

use Terraviz\Api\Catalog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Central bootstrap. Registers assets, blocks, the shortcode, the oEmbed
 * provider, and the settings screen. Holds no state beyond the singleton.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Get (and lazily wire) the singleton.
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->hooks();
		}

		return self::$instance;
	}

	/**
	 * Private constructor — use instance().
	 */
	private function __construct() {}

	/**
	 * Wire WordPress hooks.
	 */
	private function hooks(): void {
		add_action( 'init', array( $this, 'register_assets' ) );
		add_action( 'init', array( new Blocks(), 'register' ) );
		add_action( 'init', array( new Shortcode(), 'register' ) );
		add_action( 'init', array( new Oembed(), 'register' ) );
		add_action( 'rest_api_init', array( new Rest\SearchController(), 'register' ) );

		if ( is_admin() ) {
			$settings = new Settings();
			add_action( 'admin_menu', array( $settings, 'add_page' ) );
			add_action( 'admin_init', array( $settings, 'register' ) );
		}
	}

	/**
	 * Register (but do not enqueue) the shared frontend assets. The Renderer
	 * enqueues them on demand so pages without an embed load nothing extra.
	 */
	public function register_assets(): void {
		$rel_js  = 'assets/js/frontend.js';
		$rel_css = 'assets/css/frontend.css';

		wp_register_script(
			Embed\Renderer::HANDLE,
			TERRAVIZ_PLUGIN_URL . $rel_js,
			array(),
			$this->asset_version( $rel_js ),
			true
		);

		wp_register_style(
			Embed\Renderer::HANDLE,
			TERRAVIZ_PLUGIN_URL . $rel_css,
			array(),
			$this->asset_version( $rel_css )
		);
	}

	/**
	 * Version an asset for cache-busting.
	 *
	 * In production the stable plugin version is used, so the asset URL only
	 * changes on release (and stays identical across every server in a
	 * multi-server deployment). When `SCRIPT_DEBUG` is on, the file's mtime is
	 * appended so edits bust the cache during development without a version bump.
	 *
	 * @param string $relative Plugin-relative path to the asset.
	 */
	private function asset_version( string $relative ): string {
		if ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) {
			$path = TERRAVIZ_PLUGIN_DIR . $relative;
			if ( is_readable( $path ) ) {
				$mtime = filemtime( $path );
				if ( false !== $mtime ) {
					return TERRAVIZ_VERSION . '.' . (string) $mtime;
				}
			}
		}

		return TERRAVIZ_VERSION;
	}

	/**
	 * Activation: seed default settings if absent, and grant the plugin's
	 * custom `manage_terraviz` capability to administrators. No tables, no
	 * cron, no credential is provisioned.
	 *
	 * Options and roles are per-site, so on a network-wide multisite activation
	 * we apply them to every site (mirroring `uninstall.php`).
	 *
	 * @param bool|null $network_wide True when activated network-wide on multisite.
	 *                                Nullable because some callers (e.g. WP-CLI)
	 *                                invoke the `activate_{plugin}` hook with null.
	 */
	public static function on_activate( ?bool $network_wide = false ): void {
		self::for_each_site(
			static function (): void {
				if ( false === get_option( Support\Options::OPTION, false ) ) {
					add_option( Support\Options::OPTION, Support\Options::defaults() );
				}

				Support\Capabilities::grant();
			},
			(bool) $network_wide
		);
	}

	/**
	 * Deactivation: drop cached catalog data so a re-activation starts fresh,
	 * and remove the custom capability so no orphaned grant lingers. The
	 * stored credential (if any) is left in place — deactivation is not
	 * uninstall.
	 *
	 * @param bool|null $network_wide True when deactivated network-wide on multisite.
	 *                                Nullable because some callers (e.g. WP-CLI)
	 *                                invoke the `deactivate_{plugin}` hook with null.
	 */
	public static function on_deactivate( ?bool $network_wide = false ): void {
		self::for_each_site(
			static function (): void {
				Catalog::flush();
				Support\Capabilities::revoke();
			},
			(bool) $network_wide
		);
	}

	/**
	 * Run a callback once for the current site, or for every site on the
	 * network when a plugin lifecycle event fires network-wide on multisite.
	 *
	 * @param callable $callback     Per-site work.
	 * @param bool     $network_wide Whether the event was network-wide.
	 */
	private static function for_each_site( callable $callback, bool $network_wide ): void {
		if ( $network_wide && is_multisite() ) {
			$site_ids = get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			);
			foreach ( $site_ids as $site_id ) {
				switch_to_blog( (int) $site_id );
				$callback();
				restore_current_blog();
			}
			return;
		}

		$callback();
	}
}
