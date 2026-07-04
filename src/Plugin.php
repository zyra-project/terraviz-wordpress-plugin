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
	 * Version an asset by file mtime in dev, plugin version otherwise, so
	 * caches bust correctly without a build step.
	 */
	private function asset_version( string $relative ): string {
		$path = TERRAVIZ_PLUGIN_DIR . $relative;
		if ( is_readable( $path ) ) {
			$mtime = filemtime( $path );
			if ( false !== $mtime ) {
				return TERRAVIZ_VERSION . '.' . (string) $mtime;
			}
		}

		return TERRAVIZ_VERSION;
	}

	/**
	 * Activation: nothing to provision in Phase 1 (no tables, no cron). We
	 * simply seed default settings if absent.
	 */
	public static function on_activate(): void {
		if ( false === get_option( Support\Options::OPTION, false ) ) {
			add_option( Support\Options::OPTION, Support\Options::defaults() );
		}
	}

	/**
	 * Deactivation: drop cached catalog data so a re-activation starts fresh.
	 */
	public static function on_deactivate(): void {
		Catalog::flush();
	}
}
