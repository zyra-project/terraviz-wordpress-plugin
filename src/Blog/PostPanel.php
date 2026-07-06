<?php
/**
 * Block-editor panel enqueue for the WP-post → Terraviz blog opt-in (Phase 4).
 *
 * @package Terraviz
 */

declare( strict_types = 1 );

namespace Terraviz\Blog;

use Terraviz\Support\Capabilities;
use Terraviz\Support\Credential;
use Terraviz\Support\Options;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enqueues the "Show this post in Terraviz" document panel in the block editor,
 * only for publish-tier users editing standard posts. The toggle it renders is
 * bound to the `_terraviz_blog_optin` post meta registered by {@see Sync}.
 */
final class PostPanel {

	/**
	 * Script handle.
	 */
	private const HANDLE = 'terraviz-post-panel';

	/**
	 * Wire hooks.
	 */
	public function register(): void {
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue' ) );
	}

	/**
	 * Enqueue the panel script on the post editor for publish-tier users.
	 */
	public function enqueue(): void {
		if ( ! Capabilities::can_publish() ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && 'post' !== $screen->post_type ) {
			return;
		}

		if ( ! $this->assets_built() ) {
			return;
		}

		$asset = require TERRAVIZ_PLUGIN_DIR . 'build/post-panel/index.asset.php';

		wp_enqueue_script(
			self::HANDLE,
			TERRAVIZ_PLUGIN_URL . 'build/post-panel/index.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);
		wp_set_script_translations( self::HANDLE, 'terraviz' );

		wp_add_inline_script(
			self::HANDLE,
			'window.terravizPostPanel = ' . wp_json_encode(
				array(
					'origin'               => Options::origin(),
					'credentialConfigured' => Credential::configured(),
				)
			) . ';',
			'before'
		);
	}

	/**
	 * Whether the compiled panel bundle is present.
	 */
	private function assets_built(): bool {
		return is_readable( TERRAVIZ_PLUGIN_DIR . 'build/post-panel/index.js' )
			&& is_readable( TERRAVIZ_PLUGIN_DIR . 'build/post-panel/index.asset.php' );
	}
}
