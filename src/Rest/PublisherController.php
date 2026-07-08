<?php
/**
 * REST proxy for the wp-admin publisher dashboard (Phase 3a).
 *
 * @package Terraviz
 */

declare( strict_types = 1 );

namespace Terraviz\Rest;

use Terraviz\Api\PublishClient;
use Terraviz\Support\Capabilities;
use Terraviz\Support\Credential;
use Terraviz\Support\Options;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Same-origin REST endpoints under `terraviz/v1/publisher/*` that the wp-admin
 * dashboard (a React app) calls. Each one runs the Terraviz publish request
 * **server-side** via {@see PublishClient}, attaching the stored service token
 * — so the token never reaches the browser (upstream Goal 3).
 *
 * Two authorisation gates apply to every call:
 * - **WP capability**: read/draft endpoints require the draft tier
 *   (`Capabilities::can_draft`); publish/retract/delete require the publish
 *   tier (`can_publish`). The Phase-2 role→intent map is the single source of
 *   truth for who may do what through the plugin.
 * - **Credential present**: the handler short-circuits with `409
 *   credential_missing` when no service token is configured, rather than
 *   making a doomed request.
 *
 * CSRF is handled by WordPress's REST cookie nonce (`X-WP-Nonce`, sent
 * automatically by `@wordpress/api-fetch`); the Terraviz publish API itself
 * requires no additional anti-forgery token.
 *
 * The dataset body is passed through a strict field allowlist before it is
 * forwarded upstream — unknown keys are dropped and values are type-coerced.
 * The node performs the authoritative validation; this is defence in depth so
 * the proxy never forwards arbitrary caller-shaped JSON.
 */
final class PublisherController {

	private const NAMESPACE   = 'terraviz/v1';
	private const BASE        = '/publisher/datasets';
	private const EVENTS_BASE = '/publisher/events';
	private const FEEDS_BASE  = '/publisher/feeds';
	private const HERO_BASE   = '/publisher/featured-hero';
	private const MEDIA_BASE  = '/publisher/media/youtube-channels';

	/**
	 * URL-segment pattern for a dataset id (ULID or slug).
	 */
	private const ID_PATTERN = '(?P<id>[A-Za-z0-9._-]+)';

	/**
	 * Free-text / reference string fields accepted on a dataset body.
	 */
	private const STRING_FIELDS = array(
		'title',
		'slug',
		'abstract',
		'organization',
		'data_ref',
		'thumbnail_ref',
		'legend_ref',
		'caption_ref',
		'color_table_ref',
		'probing_info',
		'celestial_body',
		'website_link',
		'start_time',
		'end_time',
		'period',
		'visibility',
		'license_spdx',
		'license_url',
		'license_statement',
		'attribution_text',
		'rights_holder',
		'doi',
		'citation_text',
		'format',
		'legacy_id',
	);

	/**
	 * Numeric fields.
	 */
	private const NUMBER_FIELDS = array( 'radius_mi', 'lon_origin', 'weight' );

	/**
	 * Plain boolean fields.
	 */
	private const BOOL_FIELDS = array( 'is_hidden', 'run_tour_on_load' );

	/**
	 * Boolean-or-null fields.
	 */
	private const NULLABLE_BOOL_FIELDS = array( 'is_flipped_in_y' );

	/**
	 * List-of-string fields.
	 */
	private const LIST_FIELDS = array( 'keywords', 'tags' );

	/**
	 * Register the routes. Hooked on `rest_api_init`.
	 */
	public function register(): void {
		register_rest_route(
			self::NAMESPACE,
			self::BASE,
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'list_datasets' ),
					'permission_callback' => array( $this, 'require_draft' ),
					'args'                => array(
						'status' => array(
							'type'     => 'string',
							'required' => false,
							'enum'     => array( 'draft', 'published', 'retracted' ),
						),
						'cursor' => array(
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'sanitize_text_field',
						),
						'limit'  => array(
							'type'     => 'integer',
							'required' => false,
						),
					),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create_dataset' ),
					'permission_callback' => array( $this, 'require_draft' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			self::BASE . '/' . self::ID_PATTERN,
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_dataset' ),
					'permission_callback' => array( $this, 'require_draft' ),
				),
				array(
					'methods'             => 'PUT',
					'callback'            => array( $this, 'update_dataset' ),
					'permission_callback' => array( $this, 'require_draft' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'delete_dataset' ),
					'permission_callback' => array( $this, 'require_publish' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			self::BASE . '/' . self::ID_PATTERN . '/publish',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'publish_dataset' ),
				'permission_callback' => array( $this, 'require_publish' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			self::BASE . '/' . self::ID_PATTERN . '/retract',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'retract_dataset' ),
				'permission_callback' => array( $this, 'require_publish' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			self::BASE . '/' . self::ID_PATTERN . '/asset',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'init_asset' ),
				'permission_callback' => array( $this, 'require_draft' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			self::BASE . '/' . self::ID_PATTERN . '/asset/(?P<upload_id>[A-Za-z0-9._-]+)/complete',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'complete_asset' ),
				'permission_callback' => array( $this, 'require_draft' ),
			)
		);

		// Events curator queue. Reviewing (approving/rejecting) is an editorial
		// action, so both routes require the publish tier.
		register_rest_route(
			self::NAMESPACE,
			self::EVENTS_BASE,
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_events' ),
				'permission_callback' => array( $this, 'require_publish' ),
				'args'                => array(
					'status' => array(
						'type'     => 'string',
						'required' => false,
						'enum'     => array( 'proposed', 'approved', 'rejected', 'expired', 'all' ),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			self::EVENTS_BASE . '/' . self::ID_PATTERN,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'review_event' ),
				'permission_callback' => array( $this, 'require_publish' ),
			)
		);

		// Generate an editable tour draft from a reviewed event: an editorial
		// action on an event, gated at the publish tier for consistency with
		// event review. Every proxied call runs under the shared service
		// identity the node authorises, so the plugin's tier is the real gate.
		register_rest_route(
			self::NAMESPACE,
			self::EVENTS_BASE . '/' . self::ID_PATTERN . '/tour',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'generate_event_tour' ),
				'permission_callback' => array( $this, 'require_publish' ),
			)
		);

		// Feed connectors (the RSS/EONET sources that generate proposed events).
		// The node restricts every feed endpoint — reads included — to
		// admin/service callers, so all of these require the configure tier.
		register_rest_route(
			self::NAMESPACE,
			self::FEEDS_BASE,
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'list_feeds' ),
					'permission_callback' => array( $this, 'require_configure' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create_feed' ),
					'permission_callback' => array( $this, 'require_configure' ),
				),
			)
		);

		// Register the literal `preview` route before the `{id}` pattern so the
		// id matcher doesn't swallow it.
		register_rest_route(
			self::NAMESPACE,
			self::FEEDS_BASE . '/preview',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'preview_feed' ),
				'permission_callback' => array( $this, 'require_configure' ),
				'args'                => array(
					'kind' => array(
						'type'     => 'string',
						'required' => true,
						'enum'     => array( 'eonet', 'rss' ),
					),
					'url'  => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			self::FEEDS_BASE . '/' . self::ID_PATTERN,
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'update_feed' ),
					'permission_callback' => array( $this, 'require_configure' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'delete_feed' ),
					'permission_callback' => array( $this, 'require_configure' ),
				),
			)
		);

		// The "Right now" hero: the singleton homepage pin. Setting operator-wide
		// homepage content is an editorial action, so the plugin gates it at the
		// publish tier (the node itself restricts writes to its privileged
		// service identity, which every proxied call already uses). The read is
		// the *public* GET /api/v1/featured-hero, kept behind the same tier here
		// so the whole area is one gate.
		register_rest_route(
			self::NAMESPACE,
			self::HERO_BASE,
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_featured_hero' ),
					'permission_callback' => array( $this, 'require_publish' ),
				),
				array(
					'methods'             => 'PUT',
					'callback'            => array( $this, 'set_featured_hero' ),
					'permission_callback' => array( $this, 'require_publish' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'clear_featured_hero' ),
					'permission_callback' => array( $this, 'require_publish' ),
				),
			)
		);

		// Media channels (the vetted YouTube channels panel-media suggestions draw
		// from). Shown as a sub-tab of the Feeds screen; the node restricts every
		// endpoint to admin/service callers, so all require the configure tier.
		register_rest_route(
			self::NAMESPACE,
			self::MEDIA_BASE,
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'list_media_channels' ),
					'permission_callback' => array( $this, 'require_configure' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create_media_channel' ),
					'permission_callback' => array( $this, 'require_configure' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			self::MEDIA_BASE . '/' . self::ID_PATTERN,
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'delete_media_channel' ),
				'permission_callback' => array( $this, 'require_configure' ),
			)
		);
	}

	/**
	 * Draft-tier gate (create/edit/read).
	 */
	public function require_draft(): bool {
		return Capabilities::can_draft();
	}

	/**
	 * Publish-tier gate (publish/retract/delete).
	 */
	public function require_publish(): bool {
		return Capabilities::can_publish();
	}

	/**
	 * Configure-tier gate (feed-connector management). The node restricts every
	 * feed endpoint to admin/service callers, so publish tier is not enough.
	 */
	public function require_configure(): bool {
		return Capabilities::can_configure();
	}

	/**
	 * GET the dataset list.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function list_datasets( WP_REST_Request $request ): WP_REST_Response {
		$client = $this->client();
		if ( null === $client ) {
			return $this->credential_missing();
		}

		$query = array();
		foreach ( array( 'status', 'cursor', 'limit' ) as $key ) {
			$value = $request->get_param( $key );
			if ( null !== $value && '' !== (string) $value ) {
				$query[ $key ] = (string) $value;
			}
		}

		return $this->respond( $client->list_datasets( $query ) );
	}

	/**
	 * POST a new dataset draft.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function create_dataset( WP_REST_Request $request ): WP_REST_Response {
		$client = $this->client();
		if ( null === $client ) {
			return $this->credential_missing();
		}

		$body = $this->normalize_dataset_body( (array) $request->get_json_params() );

		return $this->respond( $client->create_dataset( $body ) );
	}

	/**
	 * GET one dataset.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_dataset( WP_REST_Request $request ): WP_REST_Response {
		$client = $this->client();
		if ( null === $client ) {
			return $this->credential_missing();
		}

		return $this->respond( $client->get_dataset( (string) $request->get_param( 'id' ) ) );
	}

	/**
	 * PUT a partial dataset update.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function update_dataset( WP_REST_Request $request ): WP_REST_Response {
		$client = $this->client();
		if ( null === $client ) {
			return $this->credential_missing();
		}

		$id = (string) $request->get_param( 'id' );

		$blocked = $this->published_edit_guard( $client, $id );
		if ( null !== $blocked ) {
			return $blocked;
		}

		$body = $this->normalize_dataset_body( (array) $request->get_json_params() );

		return $this->respond( $client->update_dataset( $id, $body ) );
	}

	/**
	 * GET the events review queue.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function list_events( WP_REST_Request $request ): WP_REST_Response {
		$client = $this->client();
		if ( null === $client ) {
			return $this->credential_missing();
		}

		$query  = array();
		$status = $request->get_param( 'status' );
		if ( null !== $status && '' !== (string) $status ) {
			$query['status'] = (string) $status;
		}

		return $this->respond( $client->list_events( $query ) );
	}

	/**
	 * POST a curator review for one event (approve/reject + optional edits).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function review_event( WP_REST_Request $request ): WP_REST_Response {
		$client = $this->client();
		if ( null === $client ) {
			return $this->credential_missing();
		}

		$body = $this->normalize_event_review_body( (array) $request->get_json_params() );

		return $this->respond( $client->review_event( (string) $request->get_param( 'id' ), $body ) );
	}

	/**
	 * POST generate a tour draft from a reviewed event. Body is empty — the node
	 * assembles stops from the event's vetted dataset pairings.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function generate_event_tour( WP_REST_Request $request ): WP_REST_Response {
		$client = $this->client();
		if ( null === $client ) {
			return $this->credential_missing();
		}

		return $this->respond( $client->generate_event_tour( (string) $request->get_param( 'id' ) ) );
	}

	/**
	 * GET the feed-connector list.
	 *
	 * @return WP_REST_Response
	 */
	public function list_feeds(): WP_REST_Response {
		$client = $this->client();
		if ( null === $client ) {
			return $this->credential_missing();
		}

		return $this->respond( $client->list_feeds() );
	}

	/**
	 * POST a new feed connector.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function create_feed( WP_REST_Request $request ): WP_REST_Response {
		$client = $this->client();
		if ( null === $client ) {
			return $this->credential_missing();
		}

		$body = $this->normalize_feed_body( (array) $request->get_json_params(), true );

		return $this->respond( $client->create_feed( $body ) );
	}

	/**
	 * POST a partial update to a feed connector (`kind` is immutable).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function update_feed( WP_REST_Request $request ): WP_REST_Response {
		$client = $this->client();
		if ( null === $client ) {
			return $this->credential_missing();
		}

		$body = $this->normalize_feed_body( (array) $request->get_json_params(), false );

		return $this->respond( $client->update_feed( (string) $request->get_param( 'id' ), $body ) );
	}

	/**
	 * DELETE a feed connector.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function delete_feed( WP_REST_Request $request ): WP_REST_Response {
		$client = $this->client();
		if ( null === $client ) {
			return $this->credential_missing();
		}

		return $this->respond( $client->delete_feed( (string) $request->get_param( 'id' ) ) );
	}

	/**
	 * GET a dry-run preview of a feed source (writes nothing).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function preview_feed( WP_REST_Request $request ): WP_REST_Response {
		$client = $this->client();
		if ( null === $client ) {
			return $this->credential_missing();
		}

		$query = array(
			'kind' => (string) $request->get_param( 'kind' ),
			'url'  => (string) $request->get_param( 'url' ),
		);

		return $this->respond( $client->preview_feed( $query ) );
	}

	/**
	 * GET the current "right now" hero override (raw `{ hero: {…}|null }`).
	 *
	 * @return WP_REST_Response
	 */
	public function get_featured_hero(): WP_REST_Response {
		$client = $this->client();
		if ( null === $client ) {
			return $this->credential_missing();
		}

		return $this->respond( $client->get_featured_hero() );
	}

	/**
	 * PUT (upsert) the hero override.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function set_featured_hero( WP_REST_Request $request ): WP_REST_Response {
		$client = $this->client();
		if ( null === $client ) {
			return $this->credential_missing();
		}

		$body = $this->normalize_hero_body( (array) $request->get_json_params() );

		return $this->respond( $client->set_featured_hero( $body ) );
	}

	/**
	 * DELETE (clear) the hero override. Idempotent — the node returns 204.
	 *
	 * @return WP_REST_Response
	 */
	public function clear_featured_hero(): WP_REST_Response {
		$client = $this->client();
		if ( null === $client ) {
			return $this->credential_missing();
		}

		return $this->respond( $client->clear_featured_hero() );
	}

	/**
	 * GET the effective YouTube channel allowlist (built-in + custom).
	 *
	 * @return WP_REST_Response
	 */
	public function list_media_channels(): WP_REST_Response {
		$client = $this->client();
		if ( null === $client ) {
			return $this->credential_missing();
		}

		return $this->respond( $client->list_media_channels() );
	}

	/**
	 * POST add a custom YouTube channel by URL.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function create_media_channel( WP_REST_Request $request ): WP_REST_Response {
		$client = $this->client();
		if ( null === $client ) {
			return $this->credential_missing();
		}

		$body = $this->normalize_media_channel_body( (array) $request->get_json_params() );

		return $this->respond( $client->create_media_channel( $body ) );
	}

	/**
	 * DELETE a custom YouTube channel.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function delete_media_channel( WP_REST_Request $request ): WP_REST_Response {
		$client = $this->client();
		if ( null === $client ) {
			return $this->credential_missing();
		}

		return $this->respond( $client->delete_media_channel( (string) $request->get_param( 'id' ) ) );
	}

	/**
	 * Guard changes to *published* catalog content. Editing a published dataset,
	 * or uploading a new asset to one, changes live content — a publish-tier
	 * action. A draft-tier user may only touch drafts and retracted rows. Because
	 * every WP user acts under one shared Terraviz `service` identity, this
	 * boundary can only be enforced here: check the target's current state before
	 * forwarding the write. Publish-tier users skip the extra fetch.
	 *
	 * The plugin is the *only* tier gate (the node authorises the shared
	 * identity for everything), so when the state fetch fails for a reason other
	 * than a plain 404 we fail **closed** rather than risk forwarding a
	 * draft-tier write to a published dataset.
	 *
	 * @param PublishClient $client Proxy client.
	 * @param string        $id     Dataset id.
	 * @return WP_REST_Response|null A 403 response when blocked, else null.
	 */
	private function published_edit_guard( PublishClient $client, string $id ): ?WP_REST_Response {
		if ( Capabilities::can_publish() ) {
			return null;
		}

		$current = $client->get_dataset( $id );

		if ( ! $current['ok'] ) {
			// A plain 404 is harmless to forward — the write returns the node's
			// own 404 and nothing changes. Any other failure means we could not
			// confirm the state, so fail closed.
			if ( 404 === $current['status'] ) {
				return null;
			}
			return new WP_REST_Response(
				array(
					'error'   => 'state_unverified',
					'message' => __( 'Could not verify the dataset’s state; please try again.', 'terraviz' ),
					'errors'  => array(),
				),
				502
			);
		}

		if ( $this->is_published( $current['data'] ) ) {
			return new WP_REST_Response(
				array(
					'error'   => 'forbidden_published',
					'message' => __( 'Changing a published dataset requires publish permissions. Ask an editor, or retract it first.', 'terraviz' ),
					'errors'  => array(),
				),
				403
			);
		}

		return null;
	}

	/**
	 * Whether a dataset payload represents a currently-published row (published
	 * and not retracted). Accepts either the `{ dataset: {...} }` envelope or a
	 * bare dataset array.
	 *
	 * @param array<string,mixed> $data Decoded dataset response body.
	 */
	private function is_published( array $data ): bool {
		$dataset = ( isset( $data['dataset'] ) && is_array( $data['dataset'] ) ) ? $data['dataset'] : $data;

		return ! empty( $dataset['published_at'] ) && empty( $dataset['retracted_at'] );
	}

	/**
	 * POST publish.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function publish_dataset( WP_REST_Request $request ): WP_REST_Response {
		$client = $this->client();
		if ( null === $client ) {
			return $this->credential_missing();
		}

		return $this->respond( $client->publish_dataset( (string) $request->get_param( 'id' ) ) );
	}

	/**
	 * POST retract.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function retract_dataset( WP_REST_Request $request ): WP_REST_Response {
		$client = $this->client();
		if ( null === $client ) {
			return $this->credential_missing();
		}

		return $this->respond( $client->retract_dataset( (string) $request->get_param( 'id' ) ) );
	}

	/**
	 * DELETE a draft/retracted dataset.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function delete_dataset( WP_REST_Request $request ): WP_REST_Response {
		$client = $this->client();
		if ( null === $client ) {
			return $this->credential_missing();
		}

		return $this->respond( $client->delete_dataset( (string) $request->get_param( 'id' ) ) );
	}

	/**
	 * POST an asset-upload init. Returns the presigned R2 `PUT` the browser
	 * uses to upload the bytes directly.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function init_asset( WP_REST_Request $request ): WP_REST_Response {
		$client = $this->client();
		if ( null === $client ) {
			return $this->credential_missing();
		}

		$id = (string) $request->get_param( 'id' );

		$blocked = $this->published_edit_guard( $client, $id );
		if ( null !== $blocked ) {
			return $blocked;
		}

		$body = $this->normalize_asset_init( (array) $request->get_json_params() );

		return $this->respond( $client->init_asset( $id, $body ) );
	}

	/**
	 * POST an asset-upload completion. The node re-verifies the digest and
	 * swaps the dataset's ref (a `202` means a video transcode was dispatched).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function complete_asset( WP_REST_Request $request ): WP_REST_Response {
		$client = $this->client();
		if ( null === $client ) {
			return $this->credential_missing();
		}

		$id = (string) $request->get_param( 'id' );

		$blocked = $this->published_edit_guard( $client, $id );
		if ( null !== $blocked ) {
			return $blocked;
		}

		return $this->respond( $client->complete_asset( $id, (string) $request->get_param( 'upload_id' ) ) );
	}

	/**
	 * Reduce an asset-init body to the allowlisted single-file fields. The node
	 * validates the enum / size caps / digest format authoritatively.
	 *
	 * @param array<string,mixed> $raw Decoded JSON body.
	 * @return array<string,mixed>
	 */
	public function normalize_asset_init( array $raw ): array {
		$out = array();

		if ( isset( $raw['kind'] ) && is_string( $raw['kind'] ) ) {
			$out['kind'] = sanitize_text_field( $raw['kind'] );
		}
		if ( isset( $raw['mime'] ) && is_string( $raw['mime'] ) ) {
			$out['mime'] = sanitize_text_field( $raw['mime'] );
		}
		if ( isset( $raw['size'] ) ) {
			// A byte count: accept only a non-negative integer (reject floats
			// like "1.5" and negatives). The node enforces the per-kind caps.
			$size = filter_var( $raw['size'], FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 0 ) ) );
			if ( false !== $size ) {
				$out['size'] = $size;
			}
		}
		if ( isset( $raw['content_digest'] ) && is_string( $raw['content_digest'] ) ) {
			$out['content_digest'] = sanitize_text_field( $raw['content_digest'] );
		}

		return $out;
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
	 * The `409 credential_missing` response.
	 */
	private function credential_missing(): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'error'   => 'credential_missing',
				'message' => __( 'No Terraviz service token is configured. Add one under Settings → Terraviz before publishing.', 'terraviz' ),
				'errors'  => array(),
			),
			409
		);
	}

	/**
	 * Translate a normalised PublishClient result into a REST response,
	 * preserving the upstream status code and passing field-validation errors
	 * straight through to the dashboard.
	 *
	 * @param array{ok:bool,status:int,data:array<string,mixed>,error:string,message:string,errors:array<int,mixed>} $result Client result.
	 * @return WP_REST_Response
	 */
	private function respond( array $result ): WP_REST_Response {
		if ( $result['ok'] ) {
			$status = $result['status'] > 0 ? $result['status'] : 200;
			return new WP_REST_Response( $result['data'], $status );
		}

		// A transport error carries status 0; surface it as a 502 (bad gateway)
		// since the proxy itself could not reach the node.
		$status = $result['status'] > 0 ? $result['status'] : 502;

		return new WP_REST_Response(
			array(
				'error'   => $result['error'],
				'message' => $result['message'],
				'errors'  => $result['errors'],
			),
			$status
		);
	}

	/**
	 * Reduce a caller-supplied dataset body to the allowlisted fields, coercing
	 * each to its expected type. Unknown keys are dropped. The Terraviz node is
	 * the authoritative validator; this keeps the proxy from forwarding
	 * arbitrary JSON.
	 *
	 * @param array<string,mixed> $raw Decoded JSON body.
	 * @return array<string,mixed>
	 */
	public function normalize_dataset_body( array $raw ): array {
		$out = array();

		foreach ( self::STRING_FIELDS as $key ) {
			if ( array_key_exists( $key, $raw ) && ( is_string( $raw[ $key ] ) || is_numeric( $raw[ $key ] ) ) ) {
				$out[ $key ] = (string) $raw[ $key ];
			}
		}

		foreach ( self::NUMBER_FIELDS as $key ) {
			if ( isset( $raw[ $key ] ) && is_numeric( $raw[ $key ] ) ) {
				$out[ $key ] = 0 + $raw[ $key ];
			}
		}

		foreach ( self::BOOL_FIELDS as $key ) {
			if ( array_key_exists( $key, $raw ) ) {
				// rest_sanitize_boolean() so a stringy "false"/"0" from a
				// non-dashboard caller doesn't coerce to true via (bool).
				$out[ $key ] = rest_sanitize_boolean( $raw[ $key ] );
			}
		}

		foreach ( self::NULLABLE_BOOL_FIELDS as $key ) {
			if ( array_key_exists( $key, $raw ) ) {
				$out[ $key ] = null === $raw[ $key ] ? null : rest_sanitize_boolean( $raw[ $key ] );
			}
		}

		foreach ( self::LIST_FIELDS as $key ) {
			if ( isset( $raw[ $key ] ) && is_array( $raw[ $key ] ) ) {
				$out[ $key ] = array_values( array_map( 'strval', array_filter( $raw[ $key ], 'is_scalar' ) ) );
			}
		}

		if ( isset( $raw['bounding_box'] ) && is_array( $raw['bounding_box'] ) ) {
			$box = array();
			foreach ( array( 'n', 's', 'w', 'e' ) as $corner ) {
				if ( isset( $raw['bounding_box'][ $corner ] ) && is_numeric( $raw['bounding_box'][ $corner ] ) ) {
					$box[ $corner ] = 0 + $raw['bounding_box'][ $corner ];
				}
			}
			if ( ! empty( $box ) ) {
				$out['bounding_box'] = $box;
			}
		}

		if ( isset( $raw['categories'] ) && is_array( $raw['categories'] ) ) {
			$categories = array();
			foreach ( $raw['categories'] as $facet => $values ) {
				if ( is_string( $facet ) && is_array( $values ) ) {
					$categories[ sanitize_text_field( $facet ) ] = array_values( array_map( 'strval', array_filter( $values, 'is_scalar' ) ) );
				}
			}
			if ( ! empty( $categories ) ) {
				$out['categories'] = $categories;
			}
		}

		return $out;
	}

	/**
	 * Reduce a caller-supplied event review to the allowlisted shape: the
	 * approve/reject action, per-link decisions, datasets to attach, and the
	 * bounded set of editable fields the node accepts. Unknown keys are dropped;
	 * the node performs the authoritative validation.
	 *
	 * @param array<string,mixed> $raw Decoded JSON body.
	 * @return array<string,mixed>
	 */
	public function normalize_event_review_body( array $raw ): array {
		$out = array();

		if ( isset( $raw['event'] ) && in_array( $raw['event'], array( 'approve', 'reject' ), true ) ) {
			$out['event'] = (string) $raw['event'];
		}

		if ( isset( $raw['addDatasetIds'] ) && is_array( $raw['addDatasetIds'] ) ) {
			$out['addDatasetIds'] = array_values( array_map( 'strval', array_filter( $raw['addDatasetIds'], 'is_scalar' ) ) );
		}

		if ( isset( $raw['links'] ) && is_array( $raw['links'] ) ) {
			$links = array();
			foreach ( $raw['links'] as $link ) {
				if (
					is_array( $link )
					&& isset( $link['datasetId'] ) && is_scalar( $link['datasetId'] )
					&& isset( $link['decision'] ) && in_array( $link['decision'], array( 'approve', 'reject' ), true )
				) {
					$links[] = array(
						'datasetId' => (string) $link['datasetId'],
						'decision'  => (string) $link['decision'],
					);
				}
			}
			if ( ! empty( $links ) ) {
				$out['links'] = $links;
			}
		}

		if ( isset( $raw['edits'] ) && is_array( $raw['edits'] ) ) {
			$edits = $this->normalize_event_edits( $raw['edits'] );
			if ( ! empty( $edits ) ) {
				$out['edits'] = $edits;
			}
		}

		return $out;
	}

	/**
	 * Allowlist the bounded set of editable event fields the node's review
	 * handler accepts. `imageAlt`/`videoEmbedUrl` are nullable (null clears).
	 *
	 * @param array<string,mixed> $raw Raw `edits` object.
	 * @return array<string,mixed>
	 */
	private function normalize_event_edits( array $raw ): array {
		$edits = array();

		foreach ( array( 'occurredStart', 'regionName', 'imageUrl' ) as $key ) {
			if ( array_key_exists( $key, $raw ) && ( is_string( $raw[ $key ] ) || is_numeric( $raw[ $key ] ) ) ) {
				$edits[ $key ] = (string) $raw[ $key ];
			}
		}

		foreach ( array( 'imageAlt', 'videoEmbedUrl' ) as $key ) {
			// Nullable: null clears the field; a scalar sets it. Anything else
			// (array/object) is dropped rather than stringified to "Array".
			if ( array_key_exists( $key, $raw ) && ( null === $raw[ $key ] || is_scalar( $raw[ $key ] ) ) ) {
				$edits[ $key ] = null === $raw[ $key ] ? null : (string) $raw[ $key ];
			}
		}

		if ( isset( $raw['point'] ) && is_array( $raw['point'] )
			&& isset( $raw['point']['lat'], $raw['point']['lon'] )
			&& is_numeric( $raw['point']['lat'] ) && is_numeric( $raw['point']['lon'] )
		) {
			$edits['point'] = array(
				'lat' => 0 + $raw['point']['lat'],
				'lon' => 0 + $raw['point']['lon'],
			);
		}

		return $edits;
	}

	/**
	 * Reduce a caller-supplied feed-connector body to the allowlisted shape the
	 * node accepts. `kind` is only meaningful on create (it is immutable
	 * afterwards), so it is dropped on update. Unknown keys and server-owned
	 * fields (`id`, timestamps, `lastRun*`) are dropped; the node performs the
	 * authoritative validation (length limits, URL scheme) and returns field
	 * errors we pass through.
	 *
	 * @param array<string,mixed> $raw       Decoded JSON body.
	 * @param bool                $is_create Whether this is a create (keeps `kind`).
	 * @return array<string,mixed>
	 */
	public function normalize_feed_body( array $raw, bool $is_create ): array {
		$out = array();

		if ( $is_create && isset( $raw['kind'] ) && in_array( $raw['kind'], array( 'eonet', 'rss' ), true ) ) {
			$out['kind'] = (string) $raw['kind'];
		}

		foreach ( array( 'label', 'url' ) as $key ) {
			if ( array_key_exists( $key, $raw ) && ( is_string( $raw[ $key ] ) || is_numeric( $raw[ $key ] ) ) ) {
				$out[ $key ] = trim( (string) $raw[ $key ] );
			}
		}

		// Nullable: null clears the category; a scalar sets it. Anything else
		// (array/object) is dropped rather than stringified to "Array".
		if ( array_key_exists( 'category', $raw ) && ( null === $raw['category'] || is_scalar( $raw['category'] ) ) ) {
			$out['category'] = null === $raw['category'] ? null : trim( (string) $raw['category'] );
		}

		if ( array_key_exists( 'enabled', $raw ) ) {
			$out['enabled'] = rest_sanitize_boolean( $raw['enabled'] );
		}

		return $out;
	}

	/**
	 * Reduce a caller-supplied hero-override body to the allowlisted shape the
	 * node accepts: `{ dataset_id, window:{ start, end }, headline? }`. The PUT
	 * is a full upsert (not a patch), so the window is forwarded whole; the node
	 * performs the authoritative validation (mandatory window, ISO-8601 parsing,
	 * `start` before `end`, headline length) and returns field errors we pass
	 * through. Unknown keys are dropped.
	 *
	 * @param array<string,mixed> $raw Decoded JSON body.
	 * @return array<string,mixed>
	 */
	public function normalize_hero_body( array $raw ): array {
		$out = array();

		if ( isset( $raw['dataset_id'] ) && ( is_string( $raw['dataset_id'] ) || is_numeric( $raw['dataset_id'] ) ) ) {
			$out['dataset_id'] = (string) $raw['dataset_id'];
		}

		if ( isset( $raw['window'] ) && is_array( $raw['window'] ) ) {
			$window = array();
			foreach ( array( 'start', 'end' ) as $key ) {
				if ( isset( $raw['window'][ $key ] ) && ( is_string( $raw['window'][ $key ] ) || is_numeric( $raw['window'][ $key ] ) ) ) {
					$window[ $key ] = (string) $raw['window'][ $key ];
				}
			}
			if ( ! empty( $window ) ) {
				$out['window'] = $window;
			}
		}

		// Nullable: null clears the headline; a scalar sets it. Anything else
		// (array/object) is dropped rather than stringified to "Array".
		if ( array_key_exists( 'headline', $raw ) && ( null === $raw['headline'] || is_scalar( $raw['headline'] ) ) ) {
			$out['headline'] = null === $raw['headline'] ? null : (string) $raw['headline'];
		}

		return $out;
	}

	/**
	 * Reduce a caller-supplied media-channel body to the single `url` the node
	 * accepts. The node resolves the URL to a canonical channel id and performs
	 * the authoritative validation (recognisable YouTube URL, length), returning
	 * a field-error envelope we pass through.
	 *
	 * @param array<string,mixed> $raw Decoded JSON body.
	 * @return array<string,mixed>
	 */
	public function normalize_media_channel_body( array $raw ): array {
		$out = array();

		if ( isset( $raw['url'] ) && ( is_string( $raw['url'] ) || is_numeric( $raw['url'] ) ) ) {
			$out['url'] = trim( (string) $raw['url'] );
		}

		return $out;
	}
}
