<?php
/**
 * One-way WP-post → Terraviz-event proposal (per-post opt-in).
 *
 * @package Terraviz
 */

declare( strict_types = 1 );

namespace Terraviz\Events;

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
 * When a publish-tier author opts a published post into "propose to Terraviz
 * events", this posts a **news-event proposal** to the node — a titled,
 * source-linked item with the datasets the post cites — which enters the node's
 * curator review queue as `proposed`.
 *
 * WordPress stays the source of truth; this never pulls Terraviz content back.
 *
 * How this differs from the blog sync (and why it's simpler):
 * - The events API is **create-only for the plugin**: `POST /publish/events`
 *   returns a `proposed` event, and there is **no `PUT`, no `GET :id`, no
 *   delete**, and no publish/unpublish toggle — approval is a curator action we
 *   deliberately never call (respecting the review gate).
 * - So there is nothing to update in place and nothing to retract. This is a
 *   **propose-once** flow: each opted-in post proposes exactly one event.
 * - Idempotency is server-side on `(feedId, externalId)`, but a per-post opt-in
 *   has no feed connector, so a bare re-`POST` would insert a duplicate. We
 *   therefore guard on a post-meta **state** flag: once `proposed`, we never
 *   re-post. `externalId` (the WP post id) is still sent for provenance and for
 *   any future feed-keyed dedup.
 *
 * Consequences the editor panel makes explicit: editing the post afterwards
 * does not update the proposed event, and unpublishing/opting-out cannot
 * retract it — the curator owns it from there.
 */
final class Sync {

	/**
	 * Post-meta keys.
	 */
	public const OPTIN_META = '_terraviz_event_optin';
	public const STATE_META = '_terraviz_event_state';
	public const ID_META    = '_terraviz_event_id';

	/**
	 * Cron hook.
	 */
	public const PROPOSE_HOOK = 'terraviz_event_propose';

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
		add_action( self::PROPOSE_HOOK, array( $this, 'do_propose' ) );
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
		register_post_meta(
			'post',
			self::STATE_META,
			array(
				'type'          => 'string',
				'single'        => true,
				'show_in_rest'  => true,
				'auth_callback' => '__return_false',
			)
		);
	}

	/**
	 * Decide whether a saved post should propose an event, and schedule the
	 * deferred work. Runs after the post *and its meta* are persisted.
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
		// only a publish-tier user with a configured credential proposes.
		if ( ! Credential::configured() || ! Capabilities::can_publish() ) {
			return;
		}

		$opted_in  = (bool) get_post_meta( $post_id, self::OPTIN_META, true );
		$published = $this->is_public( $post );
		$state     = (string) get_post_meta( $post_id, self::STATE_META, true );

		// Propose exactly once: never re-post an already-proposed event (there
		// is no server-side dedup without a feed key, and no update in place).
		if ( $opted_in && $published && 'proposed' !== $state ) {
			$this->schedule( self::PROPOSE_HOOK, $post_id );
		}
	}

	/**
	 * Post the event proposal for a post. Cron handler.
	 *
	 * @param int $post_id Post id.
	 */
	public function do_propose( $post_id ): void {
		$post_id = (int) $post_id;
		$post    = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return;
		}
		// Re-validate: state may have changed between scheduling and running,
		// and the propose-once guard must hold if two saves raced.
		if ( ! $this->is_public( $post ) || ! (bool) get_post_meta( $post_id, self::OPTIN_META, true ) ) {
			return;
		}
		if ( 'proposed' === (string) get_post_meta( $post_id, self::STATE_META, true ) ) {
			return;
		}

		$client = $this->client();
		if ( null === $client ) {
			return;
		}

		$result = $client->propose_event( $this->build_body( $post ) );
		if ( ! $result['ok'] ) {
			update_post_meta( $post_id, self::STATE_META, 'error' );
			return;
		}

		// Best-effort capture of the returned id; the guard keys off state, not
		// this, so an unknown response shape doesn't break idempotency.
		$id = $this->extract_event_id( isset( $result['data'] ) && is_array( $result['data'] ) ? $result['data'] : array() );
		if ( '' !== $id ) {
			update_post_meta( $post_id, self::ID_META, $id );
		}
		update_post_meta( $post_id, self::STATE_META, 'proposed' );
	}

	/**
	 * Compose the event proposal body from a post.
	 *
	 * @param WP_Post $post Post.
	 * @return array<string,mixed>
	 */
	public function build_body( WP_Post $post ): array {
		$occurred_at = gmdate( 'Y-m-d\TH:i:s\Z', (int) get_post_time( 'U', true, $post ) );

		$body = array(
			'title'         => (string) get_the_title( $post ),
			'source'        => array(
				'name'        => (string) get_bloginfo( 'name' ),
				'url'         => (string) get_permalink( $post ),
				'publishedAt' => $occurred_at,
			),
			'externalId'    => (string) $post->ID,
			// A per-post opt-in has no real "occurred" time; the publish date is
			// a sensible default the curator can refine.
			'occurredStart' => $occurred_at,
		);

		$summary = $this->summary( $post );
		if ( '' !== $summary ) {
			$body['summary'] = $summary;
		}

		$image = get_the_post_thumbnail_url( $post, 'full' );
		if ( is_string( $image ) && '' !== $image ) {
			$body['imageUrl'] = $image;
		}

		$dataset_ids = $this->grounding( $post );
		if ( ! empty( $dataset_ids ) ) {
			$body['datasetIds'] = $dataset_ids;
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
	 * Canonical dataset ids cited by the Terraviz blocks in a post, resolved
	 * and de-duplicated. (Events carry dataset grounding only — no tour field.)
	 *
	 * @param WP_Post $post Post.
	 * @return array<int,string>
	 */
	public function grounding( WP_Post $post ): array {
		$dataset_ids = array();
		$this->walk_blocks( parse_blocks( (string) $post->post_content ), $dataset_ids );

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

		return $resolved;
	}

	/**
	 * Recursively collect cited dataset ids from Terraviz blocks.
	 *
	 * @param array<int,array<string,mixed>> $blocks      Parsed blocks.
	 * @param array<int,string>              $dataset_ids Accumulator (by ref).
	 */
	private function walk_blocks( array $blocks, array &$dataset_ids ): void {
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			$name  = isset( $block['blockName'] ) ? (string) $block['blockName'] : '';
			$attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
			$id    = isset( $attrs['id'] ) ? (string) $attrs['id'] : '';

			if ( '' !== $id && ( 'terraviz/dataset' === $name || 'terraviz/related' === $name ) ) {
				$dataset_ids[] = $id;
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$this->walk_blocks( $block['innerBlocks'], $dataset_ids );
			}
		}
	}

	/**
	 * Resolve a block-supplied id/slug/legacyId to the canonical catalog id;
	 * pass the raw value through when it can't be resolved.
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
	 * Pull an event id out of a create response, tolerating a few shapes.
	 *
	 * @param array<string,mixed> $data Decoded response body.
	 */
	private function extract_event_id( array $data ): string {
		if ( isset( $data['event'] ) && is_array( $data['event'] ) && isset( $data['event']['id'] ) ) {
			return (string) $data['event']['id'];
		}
		if ( isset( $data['id'] ) ) {
			return (string) $data['id'];
		}

		return '';
	}

	/**
	 * Whether a post is publicly visible, so its content is safe to surface.
	 * A password-protected post is `publish` but access-gated, so it is
	 * explicitly excluded.
	 *
	 * @param WP_Post $post Post.
	 */
	private function is_public( WP_Post $post ): bool {
		return 'publish' === $post->post_status && '' === (string) $post->post_password;
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
