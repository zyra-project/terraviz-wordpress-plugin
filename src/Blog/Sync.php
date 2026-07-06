<?php
/**
 * One-way WP-post → Terraviz-blog-stub sync (Phase 4, Integration G).
 *
 * @package Terraviz
 */

declare( strict_types = 1 );

namespace Terraviz\Blog;

use Terraviz\Api\Catalog;
use Terraviz\Api\PublishClient;
use Terraviz\Support\Capabilities;
use Terraviz\Support\Credential;
use Terraviz\Support\Options;
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * When an author opts a published WordPress post into "show in Terraviz", this
 * creates and maintains a Terraviz `blog_posts` **stub**: a short markdown
 * summary + a canonical link back to the WP post, carrying dataset/tour
 * grounding read from the Terraviz blocks already in the post.
 *
 * WordPress stays the source of truth — this never pulls Terraviz content back,
 * and two-way body sync is an explicit non-goal (upstream §6, §9). The stub is
 * a discoverable, grounded pointer home.
 *
 * Design notes:
 * - **Idempotency is ours to own.** The blog API has no dedup on any WP
 *   identifier and auto-suffixes slugs, so posting twice would create
 *   `my-post`, `my-post-2`, …. We persist the returned Terraviz post id in WP
 *   post meta and `PUT` on re-sync (recreating only on a `404`, i.e. the stub
 *   was deleted upstream).
 * - **The remote calls are deferred** to a near-immediate single cron event so
 *   saving a post never blocks on the network.
 * - Only a **publish-tier** user (`can_publish`) can opt a post in or trigger a
 *   sync — publishing to Terraviz is a publish action.
 */
final class Sync {

	/**
	 * Post-meta keys.
	 */
	public const OPTIN_META = '_terraviz_blog_optin';
	public const ID_META    = '_terraviz_blog_id';
	public const SLUG_META  = '_terraviz_blog_slug';
	public const STATE_META = '_terraviz_blog_state';

	/**
	 * Cron hooks.
	 */
	public const SYNC_HOOK      = 'terraviz_blog_sync';
	public const UNSYNC_HOOK    = 'terraviz_blog_unsync';
	public const UNSYNC_ID_HOOK = 'terraviz_blog_unsync_id';

	/**
	 * Catalog for resolving cited block ids/slugs to canonical dataset ids.
	 *
	 * @var Catalog
	 */
	private $catalog;

	/**
	 * Construct.
	 *
	 * @param Catalog|null $catalog Data source; the site-default catalog when null.
	 */
	public function __construct( ?Catalog $catalog = null ) {
		$this->catalog = $catalog ?? new Catalog();
	}

	/**
	 * Wire hooks.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_meta' ) );
		add_action( 'wp_after_insert_post', array( $this, 'on_after_insert' ), 10, 2 );
		add_action( 'before_delete_post', array( $this, 'on_delete' ) );
		add_action( self::SYNC_HOOK, array( $this, 'do_sync' ) );
		add_action( self::UNSYNC_HOOK, array( $this, 'do_unsync' ) );
		add_action( self::UNSYNC_ID_HOOK, array( $this, 'do_unsync_id' ) );
	}

	/**
	 * Register the post meta the editor panel and sync use.
	 */
	public function register_meta(): void {
		register_post_meta(
			'post',
			self::OPTIN_META,
			array(
				'type'          => 'boolean',
				'single'        => true,
				'default'       => false,
				'show_in_rest'  => true,
				'auth_callback' => static function ( $allowed, $meta_key, $post_id ) {
					return current_user_can( 'edit_post', (int) $post_id ) && Capabilities::can_publish();
				},
			)
		);

		// Read-only status the panel displays; written only server-side by the
		// sync, never by a REST client (auth_callback denies writes).
		foreach ( array( self::SLUG_META, self::STATE_META ) as $key ) {
			register_post_meta(
				'post',
				$key,
				array(
					'type'          => 'string',
					'single'        => true,
					'show_in_rest'  => true,
					'auth_callback' => '__return_false',
				)
			);
		}
	}

	/**
	 * Decide whether a saved post should be synced or taken down, and schedule
	 * the deferred work. Runs after the post *and its meta* are persisted.
	 *
	 * @param int     $post_id Post id.
	 * @param WP_Post $post    Post object.
	 */
	public function on_after_insert( $post_id, $post ): void {
		$post_id = (int) $post_id;
		if ( ! $post instanceof WP_Post || 'post' !== $post->post_type ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		// Gate at schedule time, while the acting user's context is available:
		// only a publish-tier user with a configured credential drives a sync.
		if ( ! Credential::configured() || ! Capabilities::can_publish() ) {
			return;
		}

		$opted_in  = (bool) get_post_meta( $post_id, self::OPTIN_META, true );
		$published = ( 'publish' === $post->post_status );
		$has_stub  = '' !== (string) get_post_meta( $post_id, self::ID_META, true );
		$state     = (string) get_post_meta( $post_id, self::STATE_META, true );

		if ( $opted_in && $published ) {
			$this->schedule( self::SYNC_HOOK, $post_id );
		} elseif ( $has_stub && 'synced' === $state ) {
			// Opted out, or no longer published — take the live stub down.
			$this->schedule( self::UNSYNC_HOOK, $post_id );
		}
	}

	/**
	 * On WP-post deletion, unpublish any stub. Reads the id now (meta is gone
	 * after deletion) and defers the call by id.
	 *
	 * @param int $post_id Post id.
	 */
	public function on_delete( $post_id ): void {
		$blog_id = (string) get_post_meta( (int) $post_id, self::ID_META, true );
		if ( '' === $blog_id || ! Capabilities::can_publish() ) {
			return;
		}
		if ( ! wp_next_scheduled( self::UNSYNC_ID_HOOK, array( $blog_id ) ) ) {
			wp_schedule_single_event( time() + 1, self::UNSYNC_ID_HOOK, array( $blog_id ) );
		}
	}

	/**
	 * Create/update + publish the stub for a post. Cron handler.
	 *
	 * @param int $post_id Post id.
	 */
	public function do_sync( $post_id ): void {
		$post_id = (int) $post_id;
		$post    = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return;
		}
		// Re-validate: state may have changed between scheduling and running.
		if ( 'publish' !== $post->post_status || ! (bool) get_post_meta( $post_id, self::OPTIN_META, true ) ) {
			return;
		}

		$client = $this->client();
		if ( null === $client ) {
			return;
		}

		$body = $this->build_body( $post );
		$id   = (string) get_post_meta( $post_id, self::ID_META, true );

		if ( '' !== $id ) {
			$updated = $client->update_blog( $id, $body );
			if ( ! $updated['ok'] ) {
				if ( 404 === $updated['status'] ) {
					$id = '';
					// Stub deleted upstream — fall through to recreate.
				} else {
					$this->mark_error( $post_id );
					return;
				}
			}
		}

		if ( '' === $id ) {
			$created = $client->create_blog( $body );
			if ( ! $created['ok'] ) {
				$this->mark_error( $post_id );
				return;
			}
			$post_data = isset( $created['data']['post'] ) && is_array( $created['data']['post'] ) ? $created['data']['post'] : array();
			$id        = isset( $post_data['id'] ) ? (string) $post_data['id'] : '';
			if ( '' === $id ) {
				$this->mark_error( $post_id );
				return;
			}
			update_post_meta( $post_id, self::ID_META, $id );
			if ( isset( $post_data['slug'] ) ) {
				update_post_meta( $post_id, self::SLUG_META, (string) $post_data['slug'] );
			}
		}

		$published = $client->set_blog_action( $id, 'publish' );
		if ( ! $published['ok'] ) {
			$this->mark_error( $post_id );
			return;
		}
		if ( isset( $published['data']['post']['slug'] ) ) {
			update_post_meta( $post_id, self::SLUG_META, (string) $published['data']['post']['slug'] );
		}
		update_post_meta( $post_id, self::STATE_META, 'synced' );
	}

	/**
	 * Unpublish the stub for a post (kept so re-opt-in republishes the same
	 * row). Cron handler.
	 *
	 * @param int $post_id Post id.
	 */
	public function do_unsync( $post_id ): void {
		$post_id = (int) $post_id;
		$id      = (string) get_post_meta( $post_id, self::ID_META, true );
		if ( '' === $id ) {
			return;
		}
		$client = $this->client();
		if ( null === $client ) {
			return;
		}

		$result = $client->set_blog_action( $id, 'unpublish' );
		if ( $result['ok'] || 404 === $result['status'] ) {
			update_post_meta( $post_id, self::STATE_META, 'unsynced' );
		} else {
			$this->mark_error( $post_id );
		}
	}

	/**
	 * Unpublish a stub by its Terraviz id (used when the WP post is gone).
	 * Cron handler.
	 *
	 * @param string $blog_id Terraviz blog post id.
	 */
	public function do_unsync_id( $blog_id ): void {
		$blog_id = (string) $blog_id;
		if ( '' === $blog_id ) {
			return;
		}
		$client = $this->client();
		if ( null !== $client ) {
			$client->set_blog_action( $blog_id, 'unpublish' );
		}
	}

	/**
	 * Compose the blog-stub body from a post: title, a markdown summary + a
	 * canonical link back, and dataset/tour grounding.
	 *
	 * @param WP_Post $post Post.
	 * @return array<string,mixed>
	 */
	public function build_body( WP_Post $post ): array {
		$summary   = $this->summary( $post );
		$grounding = $this->grounding( $post );

		$body = array(
			'title'  => (string) get_the_title( $post ),
			'bodyMd' => $this->body_md( $post, $summary ),
		);
		if ( '' !== $summary ) {
			$body['summary'] = $summary;
		}
		if ( ! empty( $grounding['datasetIds'] ) ) {
			$body['datasetIds'] = $grounding['datasetIds'];
		}
		if ( '' !== $grounding['tourId'] ) {
			$body['tourId'] = $grounding['tourId'];
		}

		return $body;
	}

	/**
	 * A short plain-text summary: the post excerpt, else a trimmed body.
	 *
	 * @param WP_Post $post Post.
	 */
	private function summary( WP_Post $post ): string {
		if ( has_excerpt( $post ) ) {
			$summary = (string) $post->post_excerpt;
		} else {
			$summary = wp_trim_words( wp_strip_all_tags( (string) $post->post_content ), 55, '' );
		}
		$summary = trim( wp_strip_all_tags( $summary ) );

		if ( function_exists( 'mb_strlen' ) && mb_strlen( $summary ) > 500 ) {
			$summary = rtrim( mb_substr( $summary, 0, 497 ) ) . '…';
		}

		return $summary;
	}

	/**
	 * The markdown body: the summary plus an allowlist-safe link back to the WP
	 * post (the Terraviz markdown allowlist permits `<a>` but strips images).
	 *
	 * @param WP_Post $post    Post.
	 * @param string  $summary Summary text.
	 */
	private function body_md( WP_Post $post, string $summary ): string {
		$permalink = (string) get_permalink( $post );
		$site      = (string) get_bloginfo( 'name' );

		$lines = array();
		if ( '' !== $summary ) {
			$lines[] = $summary;
		}
		$lines[] = sprintf(
			'[%1$s](%2$s)',
			sprintf(
				/* translators: %s: site name. */
				__( 'Read the full story on %s →', 'terraviz' ),
				'' !== $site ? $site : __( 'the source site', 'terraviz' )
			),
			esc_url_raw( $permalink )
		);

		return implode( "\n\n", $lines );
	}

	/**
	 * Extract dataset/tour grounding from the Terraviz blocks in a post,
	 * resolved to canonical ids and de-duplicated.
	 *
	 * @param WP_Post $post Post.
	 * @return array{datasetIds:array<int,string>,tourId:string}
	 */
	public function grounding( WP_Post $post ): array {
		$dataset_ids = array();
		$tour_id     = '';
		$this->walk_blocks( parse_blocks( (string) $post->post_content ), $dataset_ids, $tour_id );

		$resolved = array();
		foreach ( $dataset_ids as $raw ) {
			$canonical = $this->resolve( $raw );
			if ( '' !== $canonical && ! in_array( $canonical, $resolved, true ) ) {
				$resolved[] = $canonical;
			}
			if ( count( $resolved ) >= 20 ) {
				break;
			}
		}

		return array(
			'datasetIds' => $resolved,
			'tourId'     => '' !== $tour_id ? $this->resolve( $tour_id ) : '',
		);
	}

	/**
	 * Recursively collect cited ids from Terraviz blocks.
	 *
	 * @param array<int,array<string,mixed>> $blocks      Parsed blocks.
	 * @param array<int,string>              $dataset_ids Accumulator (by ref).
	 * @param string                         $tour_id     First tour id (by ref).
	 */
	private function walk_blocks( array $blocks, array &$dataset_ids, string &$tour_id ): void {
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			$name  = isset( $block['blockName'] ) ? (string) $block['blockName'] : '';
			$attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
			$id    = isset( $attrs['id'] ) ? (string) $attrs['id'] : '';

			if ( '' !== $id ) {
				if ( 'terraviz/dataset' === $name || 'terraviz/related' === $name ) {
					$dataset_ids[] = $id;
				} elseif ( 'terraviz/tour' === $name && '' === $tour_id ) {
					$tour_id = $id;
				}
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$this->walk_blocks( $block['innerBlocks'], $dataset_ids, $tour_id );
			}
		}
	}

	/**
	 * Resolve a block-supplied id/slug/legacyId to the canonical catalog id;
	 * pass the raw value through when it can't be resolved (Terraviz hydrates
	 * only valid ids at read time, so a stale value simply won't render).
	 *
	 * @param string $raw Raw cited value.
	 */
	private function resolve( string $raw ): string {
		$dataset = $this->catalog->find_in_catalog( $raw );
		if ( null !== $dataset ) {
			$canonical = (string) $dataset->get( 'id', '' );
			if ( '' !== $canonical ) {
				return $canonical;
			}
		}

		return $raw;
	}

	/**
	 * Record a sync failure for the panel to surface.
	 *
	 * @param int $post_id Post id.
	 */
	private function mark_error( int $post_id ): void {
		update_post_meta( $post_id, self::STATE_META, 'error' );
	}

	/**
	 * Build a publish client from the configured origin + stored credential,
	 * or null when no usable credential is on file.
	 */
	private function client(): ?PublishClient {
		$headers = Credential::headers();
		if ( empty( $headers ) ) {
			return null;
		}

		return new PublishClient( Options::origin(), $headers );
	}

	/**
	 * Schedule a near-immediate single cron event, avoiding duplicates.
	 *
	 * @param string $hook    Hook name.
	 * @param int    $post_id Post id argument.
	 */
	private function schedule( string $hook, int $post_id ): void {
		if ( ! wp_next_scheduled( $hook, array( $post_id ) ) ) {
			wp_schedule_single_event( time() + 1, $hook, array( $post_id ) );
		}
	}
}
