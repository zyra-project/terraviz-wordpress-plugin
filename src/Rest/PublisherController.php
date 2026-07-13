<?php
/**
 * REST proxy for the wp-admin publisher dashboard (Phase 3a).
 *
 * @package Terraviz
 */

declare( strict_types = 1 );

namespace Terraviz\Rest;

use Terraviz\Api\PublishClient;
use Terraviz\Blog\Sync;
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

	private const NAMESPACE      = 'terraviz/v1';
	private const BASE           = '/publisher/datasets';
	private const EVENTS_BASE    = '/publisher/events';
	private const FEEDS_BASE     = '/publisher/feeds';
	private const HERO_BASE      = '/publisher/featured-hero';
	private const MEDIA_BASE     = '/publisher/media/youtube-channels';
	private const YT_SEARCH_BASE = '/publisher/media/youtube-search';
	private const NHC_BASE       = '/publisher/media/nhc-storms';
	private const BLOG_BASE      = '/publisher/blog';

	/**
	 * URL-segment pattern for a dataset id (ULID or slug).
	 */
	private const ID_PATTERN = '(?P<id>[A-Za-z0-9._-]+)';

	/**
	 * Cap on a sideloaded cover image (bytes) — `wp_safe_remote_get` reads the
	 * body into memory, so bound it well above any realistic web image.
	 */
	private const MAX_COVER_BYTES = 12582912;

	/**
	 * Cap on an event-image upload (decoded bytes). Mirrors the node's own raster
	 * limit (~4 MB) so an oversized upload is rejected before the base64 payload
	 * is forwarded, rather than after a round-trip.
	 */
	private const MAX_EVENT_IMAGE_BYTES = 4194304;

	/**
	 * Raster image MIME types accepted for an event-image upload — the same set
	 * the node stores. Kept in sync with {@see self::sideload_image()}.
	 */
	private const EVENT_IMAGE_TYPES = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' );

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

		// Media *suggestions* for the event-review pane. Unlike the channel
		// allowlist (configure-tier admin config), these are read/write actions a
		// curator takes while reviewing an event, so they gate at the publish tier
		// — consistent with event review itself. `youtube-search` and `nhc-storms`
		// are same-origin proxies (server-side API key / no upstream CORS); the
		// per-event image upload writes the org's own photo.
		register_rest_route(
			self::NAMESPACE,
			self::YT_SEARCH_BASE,
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'search_youtube_media' ),
				'permission_callback' => array( $this, 'require_publish' ),
				'args'                => array(
					'q' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			self::NHC_BASE,
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_nhc_storms' ),
				'permission_callback' => array( $this, 'require_publish' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			self::EVENTS_BASE . '/' . self::ID_PATTERN . '/image',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'set_event_image' ),
				'permission_callback' => array( $this, 'require_publish' ),
			)
		);

		// Blog list (read). Authoring stays in WordPress (the WP→node sync is the
		// bridge), so this is read-only here; the sidebar tab is publish-tier.
		register_rest_route(
			self::NAMESPACE,
			self::BLOG_BASE,
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_blog' ),
				'permission_callback' => array( $this, 'require_publish' ),
				'args'                => array(
					'status' => array(
						'type'     => 'string',
						'required' => false,
						'enum'     => array( 'draft', 'published' ),
					),
				),
			)
		);

		// AI-draft a blog post from selected datasets (+ an optional cited event)
		// and seed a WordPress draft from the result. The literal `generate` route
		// is registered before the `{id}` blog routes so the id matcher can't
		// swallow it. A write, so publish-tier.
		register_rest_route(
			self::NAMESPACE,
			self::BLOG_BASE . '/generate',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'draft_blog_with_ai' ),
				'permission_callback' => array( $this, 'require_publish' ),
			)
		);

		// Seed a WordPress post from a node blog post ("Terraviz drives the
		// initial content"): creates a WP draft prefilled from the node post and
		// links the two so the existing WP→node sync carries edits back. A write,
		// so publish-tier.
		register_rest_route(
			self::NAMESPACE,
			self::BLOG_BASE . '/' . self::ID_PATTERN . '/import-to-wp',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'import_blog_to_wp' ),
				'permission_callback' => array( $this, 'require_publish' ),
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
	 * GET agency-YouTube video candidates for the pane, keyed by the event title.
	 * The proxy passes the query straight through; the node holds the API key and
	 * pre-filters to the allowlisted channels, degrading to `{ videos: [] }`.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function search_youtube_media( WP_REST_Request $request ): WP_REST_Response {
		$client = $this->client();
		if ( null === $client ) {
			return $this->credential_missing();
		}

		return $this->respond( $client->search_youtube_media( (string) $request->get_param( 'q' ) ) );
	}

	/**
	 * GET the active tropical-cyclone list (proxied same-origin from NHC). The
	 * pane matches a storm name to a tropical event and builds its cone graphic
	 * URL client-side.
	 *
	 * @return WP_REST_Response
	 */
	public function list_nhc_storms(): WP_REST_Response {
		$client = $this->client();
		if ( null === $client ) {
			return $this->credential_missing();
		}

		return $this->respond( $client->list_nhc_storms() );
	}

	/**
	 * POST upload the org's own photo as an event's story image. The base64 body
	 * is normalized (raster MIME, size-capped) before it's forwarded; the node
	 * stores it and returns `{ imageUrl }`.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function set_event_image( WP_REST_Request $request ): WP_REST_Response {
		$client = $this->client();
		if ( null === $client ) {
			return $this->credential_missing();
		}

		$body = $this->normalize_event_image_body( (array) $request->get_json_params() );
		if ( isset( $body['error'] ) ) {
			return new WP_REST_Response(
				array(
					'error'   => 'invalid_image',
					'message' => (string) $body['error'],
				),
				400
			);
		}

		return $this->respond( $client->set_event_image( (string) $request->get_param( 'id' ), $body ) );
	}

	/**
	 * GET the blog list, each post decorated with `wp_edit_url` when a WordPress
	 * post is linked to it. Blog authoring lives in WordPress; this read view
	 * surfaces the node's posts and points each back at its WP editor (via the
	 * `Sync::ID_META` link the WP→node sync already maintains).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function list_blog( WP_REST_Request $request ): WP_REST_Response {
		$client = $this->client();
		if ( null === $client ) {
			return $this->credential_missing();
		}

		$query  = array();
		$status = $request->get_param( 'status' );
		if ( null !== $status && '' !== (string) $status ) {
			$query['status'] = (string) $status;
		}

		$result = $client->list_blog( $query );

		if ( $result['ok'] && isset( $result['data']['posts'] ) && is_array( $result['data']['posts'] ) ) {
			$node_ids = array();
			foreach ( $result['data']['posts'] as $post ) {
				if ( is_array( $post ) && isset( $post['id'] ) ) {
					$node_ids[] = (string) $post['id'];
				}
			}

			$edit_map = $this->wp_blog_edit_map( $node_ids );
			foreach ( $result['data']['posts'] as &$post ) {
				if ( is_array( $post ) && isset( $post['id'] ) ) {
					$node_id             = (string) $post['id'];
					$post['wp_edit_url'] = $edit_map[ $node_id ] ?? null;
				}
			}
			unset( $post );
		}

		return $this->respond( $result );
	}

	/**
	 * Build a map of Terraviz blog-post id → the WP post-editor URL for the
	 * WordPress post linked to it (via {@see Sync::ID_META}). The lookup is scoped
	 * to exactly the node ids being listed (a `meta_query` `IN`), so every
	 * displayed post is resolved — no arbitrary cap — while the query stays
	 * bounded by the current blog list rather than every linked post on the site.
	 * Only posts the current user may edit yield a URL; the rest map to null via
	 * the caller's `??`.
	 *
	 * @param array<int,string> $node_ids Terraviz blog-post ids to resolve.
	 * @return array<string,string>
	 */
	private function wp_blog_edit_map( array $node_ids ): array {
		$map = array();

		$node_ids = array_values( array_unique( array_filter( $node_ids, 'strlen' ) ) );
		if ( empty( $node_ids ) ) {
			return $map;
		}

		$linked = get_posts(
			array(
				'post_type'   => 'post',
				'post_status' => 'any',
				'numberposts' => count( $node_ids ), // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_numberposts -- bounded by the node ids on the current blog page; one WP post links each.
				'fields'      => 'ids',
				'meta_query'  => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- scoped IN lookup on the listed node ids to resolve their WP editor links.
					array(
						'key'     => Sync::ID_META,
						'value'   => $node_ids,
						'compare' => 'IN',
					),
				),
			)
		);

		foreach ( $linked as $wp_id ) {
			$node_id = (string) get_post_meta( (int) $wp_id, Sync::ID_META, true );
			if ( '' === $node_id ) {
				continue;
			}
			$edit_url = get_edit_post_link( (int) $wp_id, 'raw' );
			if ( $edit_url ) {
				$map[ $node_id ] = $edit_url;
			}
		}

		return $map;
	}

	/**
	 * The WP post id linked to a node blog-post id (via {@see Sync::ID_META}), or
	 * 0 when none. Used to avoid seeding a duplicate WP post for an already-linked
	 * node post.
	 *
	 * @param string $node_id Terraviz blog-post id.
	 * @return int WP post id, or 0.
	 */
	private function find_linked_wp_post( string $node_id ): int {
		if ( '' === $node_id ) {
			return 0;
		}

		$ids = get_posts(
			array(
				'post_type'   => 'post',
				'post_status' => 'any',
				'numberposts' => 1,
				'fields'      => 'ids',
				'meta_query'  => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- single-id lookup to detect an existing link.
					array(
						'key'   => Sync::ID_META,
						'value' => $node_id,
					),
				),
			)
		);

		return ! empty( $ids ) ? (int) $ids[0] : 0;
	}

	/**
	 * POST seed a WordPress draft post from a node blog post. Fetches the node
	 * post, creates a WP draft (authored by the acting user) prefilled with its
	 * title + converted markdown body, and writes the {@see Sync} link meta so the
	 * existing WP→node sync treats the two as one object (edits on the WP side
	 * carry back on publish). If the node post is already linked to a WP post, it
	 * returns that one rather than duplicating.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function import_blog_to_wp( WP_REST_Request $request ): WP_REST_Response {
		// This route creates a real WordPress post, and `wp_insert_post()` does
		// not check capabilities. The publish tier alone is not enough: it can be
		// held via `manage_terraviz` (configure tier) by a role without WordPress
		// post-editing rights. Gate on WordPress's own posting capability so a
		// Terraviz-configurer without `edit_posts` can't author WP content.
		if ( ! current_user_can( 'edit_posts' ) ) {
			return new WP_REST_Response(
				array(
					'error'   => 'forbidden',
					'message' => __( 'You need permission to create WordPress posts.', 'terraviz' ),
					'errors'  => array(),
				),
				403
			);
		}

		$client = $this->client();
		if ( null === $client ) {
			return $this->credential_missing();
		}

		$node_id = (string) $request->get_param( 'id' );

		// Don't create a second WP post for an already-linked node post.
		$existing = $this->find_linked_wp_post( $node_id );
		if ( $existing > 0 ) {
			return new WP_REST_Response(
				array(
					'wpId'           => $existing,
					'editUrl'        => (string) get_edit_post_link( $existing, 'raw' ),
					'already_linked' => true,
				),
				200
			);
		}

		$fetched = $client->get_blog( $node_id );
		if ( ! $fetched['ok'] ) {
			return $this->respond( $fetched );
		}

		$post        = ( isset( $fetched['data']['post'] ) && is_array( $fetched['data']['post'] ) ) ? $fetched['data']['post'] : array();
		$title       = isset( $post['title'] ) ? (string) $post['title'] : '';
		$body_md     = isset( $post['bodyMd'] ) ? (string) $post['bodyMd'] : '';
		$slug        = isset( $post['slug'] ) ? (string) $post['slug'] : '';
		$dataset_ids = ( isset( $post['datasetIds'] ) && is_array( $post['datasetIds'] ) ) ? $post['datasetIds'] : array();
		$tour_id     = isset( $post['tourId'] ) ? (string) $post['tourId'] : '';
		$cover_url   = isset( $post['coverImageUrl'] ) ? (string) $post['coverImageUrl'] : '';
		$cover_alt   = isset( $post['coverImageAlt'] ) ? sanitize_text_field( (string) $post['coverImageAlt'] ) : '';

		// Seed real Gutenberg blocks (not one Classic block): the converted body,
		// then Terraviz embed blocks for the datasets/tour the node post is
		// grounded in, so the linked data is live in the editor from the start.
		$content = $this->markdown_to_blocks( $body_md );
		$embeds  = $this->embed_blocks( $dataset_ids, $tour_id );
		if ( '' !== $embeds ) {
			$content = '' !== $content ? $content . "\n\n" . $embeds : $embeds;
		}

		$wp_id = wp_insert_post(
			array(
				'post_type'    => 'post',
				'post_status'  => 'draft',
				'post_title'   => '' !== $title ? $title : __( 'Untitled Terraviz post', 'terraviz' ),
				'post_content' => $content,
				'post_author'  => get_current_user_id(),
			),
			true
		);

		if ( is_wp_error( $wp_id ) ) {
			return new WP_REST_Response(
				array(
					'error'   => 'wp_insert_failed',
					'message' => $wp_id->get_error_message(),
					'errors'  => array(),
				),
				500
			);
		}

		// Link the two so the WP→node sync updates the existing stub (idempotent
		// on the returned node id) rather than creating a duplicate, and so a WP
		// publish carries the edited body back with a link home.
		update_post_meta( $wp_id, Sync::ID_META, $node_id );
		update_post_meta( $wp_id, Sync::OPTIN_META, true );
		if ( '' !== $slug ) {
			update_post_meta( $wp_id, Sync::SLUG_META, $slug );
		}

		// Bring the node post's cover image across as the WP featured image
		// (best-effort — a failed sideload just leaves the draft without one).
		if ( '' !== $cover_url ) {
			$attachment_id = $this->sideload_image( $cover_url, (int) $wp_id, $cover_alt );
			if ( $attachment_id > 0 ) {
				set_post_thumbnail( (int) $wp_id, $attachment_id );
			}
		}

		return new WP_REST_Response(
			array(
				'wpId'    => (int) $wp_id,
				'editUrl' => (string) get_edit_post_link( (int) $wp_id, 'raw' ),
			),
			201
		);
	}

	/**
	 * AI-draft a blog post from the caller's selected datasets (and an optional
	 * cited event) on the node, then seed a WordPress **draft** from the returned
	 * content — the from-scratch, AI-assisted counterpart to `import_blog_to_wp`.
	 *
	 * The node draft is *returned, not persisted* upstream, so there is no node
	 * blog id to link; the seeded WP post is opted into Terraviz so a subsequent
	 * WP publish creates the node blog stub via the existing WP→node sync. The
	 * body is seeded as real Gutenberg blocks plus the grounding embed blocks
	 * (the same converter as the import path). A companion tour, when the node
	 * generated one, is embedded too.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function draft_blog_with_ai( WP_REST_Request $request ): WP_REST_Response {
		// Creating a WP post needs WordPress's own posting capability, not just
		// the plugin's publish tier (which can be held without `edit_posts`).
		if ( ! current_user_can( 'edit_posts' ) ) {
			return new WP_REST_Response(
				array(
					'error'   => 'forbidden',
					'message' => __( 'You need permission to create WordPress posts.', 'terraviz' ),
					'errors'  => array(),
				),
				403
			);
		}

		$client = $this->client();
		if ( null === $client ) {
			return $this->credential_missing();
		}

		$body = $this->normalize_blog_generate_body( (array) $request->get_json_params() );
		if ( empty( $body['datasetIds'] ) ) {
			return new WP_REST_Response(
				array(
					'error'   => 'no_datasets',
					'message' => __( 'Select at least one dataset to ground the draft in.', 'terraviz' ),
					'errors'  => array(),
				),
				400
			);
		}

		$result = $client->generate_blog_draft( $body );
		if ( ! $result['ok'] ) {
			// Surface the node's status + reason as-is (503 no-AI, 502 unusable,
			// 400 no visible datasets, …) for the dashboard to message.
			return $this->respond( $result );
		}

		$draft   = ( isset( $result['data']['draft'] ) && is_array( $result['data']['draft'] ) ) ? $result['data']['draft'] : array();
		$title   = isset( $draft['title'] ) ? (string) $draft['title'] : '';
		$body_md = isset( $draft['bodyMd'] ) ? (string) $draft['bodyMd'] : '';
		$summary = isset( $draft['summary'] ) ? (string) $draft['summary'] : '';

		$tour       = ( isset( $result['data']['tour'] ) && is_array( $result['data']['tour'] ) ) ? $result['data']['tour'] : array();
		$tour_id    = isset( $tour['id'] ) ? (string) $tour['id'] : '';
		$tour_error = isset( $result['data']['tourError'] ) ? (string) $result['data']['tourError'] : '';

		// Seed real Gutenberg blocks (converted body) + Terraviz embed blocks for
		// the datasets the draft is grounded in and the companion tour, if any.
		$content = $this->markdown_to_blocks( $body_md );
		$embeds  = $this->embed_blocks( $body['datasetIds'], $tour_id );
		if ( '' !== $embeds ) {
			$content = '' !== $content ? $content . "\n\n" . $embeds : $embeds;
		}

		$wp_id = wp_insert_post(
			array(
				'post_type'    => 'post',
				'post_status'  => 'draft',
				'post_title'   => '' !== $title ? $title : __( 'Untitled Terraviz post', 'terraviz' ),
				'post_content' => $content,
				'post_excerpt' => sanitize_textarea_field( $summary ),
				'post_author'  => get_current_user_id(),
			),
			true
		);

		if ( is_wp_error( $wp_id ) ) {
			return new WP_REST_Response(
				array(
					'error'   => 'wp_insert_failed',
					'message' => $wp_id->get_error_message(),
					'errors'  => array(),
				),
				500
			);
		}

		// Opt the seeded draft into Terraviz (no node id to link yet — a WP
		// publish creates the stub through the existing sync), matching the
		// import path's posture.
		update_post_meta( $wp_id, Sync::OPTIN_META, true );

		return new WP_REST_Response(
			array(
				'wpId'      => (int) $wp_id,
				'editUrl'   => (string) get_edit_post_link( (int) $wp_id, 'raw' ),
				'tour'      => ! empty( $tour ) ? $tour : null,
				'tourError' => '' !== $tour_error ? $tour_error : null,
			),
			201
		);
	}

	/**
	 * Download an http(s) image into the media library and return its attachment
	 * id (0 on any failure — the caller treats a cover image as best-effort).
	 *
	 * The fetch uses `wp_safe_remote_get` (rejects private/reserved hosts), so it
	 * keeps the plugin's VIP-clean, no-SSRF posture even though the URL is a
	 * curator-chosen suggestion that may point at a third-party source (NASA
	 * Worldview, a news photo, …). Only raster web image types are accepted, and
	 * the body is size-capped.
	 *
	 * @param string $url       Image URL.
	 * @param int    $parent_id Post to attach to.
	 * @param string $alt       Alt text (already sanitized), or ''.
	 * @return int Attachment id, or 0.
	 */
	private function sideload_image( string $url, int $parent_id, string $alt ): int {
		$url = esc_url_raw( $url );
		if ( '' === $url || ! preg_match( '#^https?://#i', $url ) ) {
			return 0;
		}

		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 15,
				'redirection'         => 2,
				'reject_unsafe_urls'  => true,
				// Hard-cap the download at the HTTP layer (+1 so an over-cap body
				// trips the strlen check below) so a huge response can't exhaust
				// memory before we ever measure it.
				'limit_response_size' => self::MAX_COVER_BYTES + 1,
			)
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return 0;
		}

		$body = (string) wp_remote_retrieve_body( $response );
		if ( '' === $body || strlen( $body ) > self::MAX_COVER_BYTES ) {
			return 0;
		}

		$by_type = array(
			'image/jpeg' => 'jpg',
			'image/png'  => 'png',
			'image/gif'  => 'gif',
			'image/webp' => 'webp',
		);
		// A cheap early-out on the advertised type before writing anything to disk;
		// the authoritative check is against the stored bytes, below.
		$type = (string) wp_remote_retrieve_header( $response, 'content-type' );
		$type = strtolower( trim( explode( ';', $type )[0] ) );
		if ( ! isset( $by_type[ $type ] ) ) {
			return 0;
		}
		$ext = $by_type[ $type ];

		// Name from the URL path, else a generic one; always force the resolved
		// extension so the stored file matches its verified type.
		$name = sanitize_file_name( (string) wp_basename( (string) wp_parse_url( $url, PHP_URL_PATH ) ) );
		$name = '' !== $name ? (string) preg_replace( '/\.[A-Za-z0-9]+$/', '', $name ) : 'terraviz-cover';
		$name = ( '' !== $name ? $name : 'terraviz-cover' ) . '.' . $ext;

		$upload = wp_upload_bits( $name, null, $body );
		if ( ! empty( $upload['error'] ) || empty( $upload['file'] ) ) {
			return 0;
		}

		// Trust the *stored bytes*, not the response header: a lying `content-type`
		// could smuggle a non-image onto disk. Verify the real type of the file
		// and require it to be one of the accepted raster types.
		$checked   = wp_check_filetype_and_ext( $upload['file'], $name );
		$real_type = ( is_array( $checked ) && ! empty( $checked['type'] ) ) ? (string) $checked['type'] : '';
		if ( ! isset( $by_type[ $real_type ] ) ) {
			wp_delete_file( $upload['file'] );
			return 0;
		}
		$type = $real_type;

		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => $type,
				'post_title'     => '' !== $alt ? $alt : $name,
				'post_content'   => '',
				'post_status'    => 'inherit',
			),
			$upload['file'],
			$parent_id,
			true
		);
		if ( is_wp_error( $attachment_id ) || (int) $attachment_id <= 0 ) {
			wp_delete_file( $upload['file'] );
			return 0;
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		wp_update_attachment_metadata( (int) $attachment_id, wp_generate_attachment_metadata( (int) $attachment_id, $upload['file'] ) );

		if ( '' !== $alt ) {
			update_post_meta( (int) $attachment_id, '_wp_attachment_image_alt', $alt );
		}

		return (int) $attachment_id;
	}

	/**
	 * Parse Markdown into a list of block-level descriptors (v1): paragraphs,
	 * ATX headings (clamped to h2–h6 to nest under the post title), and unordered
	 * lists, each with inline links/bold/italic/code applied and all user text
	 * escaped. Shared by {@see markdown_to_html} and {@see markdown_to_blocks}.
	 * Faithful support for tables, ordered lists, images, and fenced code is a
	 * later refinement — the author polishes the draft in WP.
	 *
	 * @param string $md Markdown source.
	 * @return array<int,array<string,mixed>> Descriptors: `{type,html}` /
	 *                                         `{type:'heading',level,html}` /
	 *                                         `{type:'list',items:string[]}`.
	 */
	private function parse_markdown_blocks( string $md ): array {
		$md     = str_replace( array( "\r\n", "\r" ), "\n", $md );
		$blocks = preg_split( '/\n{2,}/', trim( $md ) );
		if ( ! is_array( $blocks ) ) {
			return array();
		}

		$out = array();
		foreach ( $blocks as $block ) {
			$block = trim( $block, "\n" );
			if ( '' === $block ) {
				continue;
			}

			$single = false === strpos( $block, "\n" );

			// ATX heading (single line).
			if ( $single && preg_match( '/^(#{1,6})\s+(.+)$/', $block, $m ) ) {
				$out[] = array(
					'type'  => 'heading',
					'level' => min( 6, max( 2, strlen( $m[1] ) ) ),
					'html'  => $this->md_inline( $m[2] ),
				);
				continue;
			}

			// Standalone image: `![alt](url)` on its own → an image block.
			if ( $single && preg_match( '/^!\[([^\]]*)\]\(([^)\s]+)\)$/', $block, $m ) ) {
				$out[] = array(
					'type' => 'image',
					'url'  => $m[2],
					'alt'  => $m[1],
				);
				continue;
			}

			// Standalone video URL (YouTube / Vimeo) → an embed block.
			if ( $single && $this->is_video_url( $block ) ) {
				$out[] = array(
					'type' => 'embed',
					'url'  => $block,
				);
				continue;
			}

			$lines = explode( "\n", $block );

			// Unordered list: every line is a `- ` / `* ` item.
			$is_list = true;
			foreach ( $lines as $line ) {
				if ( ! preg_match( '/^\s*[-*]\s+\S/', $line ) ) {
					$is_list = false;
					break;
				}
			}
			if ( $is_list ) {
				$items = array();
				foreach ( $lines as $line ) {
					$items[] = $this->md_inline( (string) preg_replace( '/^\s*[-*]\s+/', '', $line ) );
				}
				$out[] = array(
					'type'  => 'list',
					'items' => $items,
				);
				continue;
			}

			// Paragraph; soft-wrapped lines join with a break.
			$out[] = array(
				'type' => 'paragraph',
				'html' => implode( '<br />', array_map( array( $this, 'md_inline' ), $lines ) ),
			);
		}//end foreach

		return $out;
	}

	/**
	 * A minimal Markdown → HTML pass (see {@see parse_markdown_blocks}). Kept for
	 * plain-HTML callers; the blog seed uses {@see markdown_to_blocks}.
	 *
	 * @param string $md Markdown source.
	 * @return string HTML.
	 */
	public function markdown_to_html( string $md ): string {
		$html = array();
		foreach ( $this->parse_markdown_blocks( $md ) as $block ) {
			if ( 'heading' === $block['type'] ) {
				$html[] = '<h' . $block['level'] . '>' . $block['html'] . '</h' . $block['level'] . '>';
			} elseif ( 'list' === $block['type'] ) {
				$items  = array_map( static fn( $i ) => '<li>' . $i . '</li>', $block['items'] );
				$html[] = '<ul>' . implode( '', $items ) . '</ul>';
			} elseif ( 'image' === $block['type'] ) {
				$html[] = $this->image_html( $block['url'], $block['alt'] );
			} elseif ( 'embed' === $block['type'] ) {
				$safe   = esc_url( $block['url'] );
				$html[] = '' !== $safe ? '<p><a href="' . $safe . '">' . esc_html( $block['url'] ) . '</a></p>' : '';
			} else {
				$html[] = '<p>' . $block['html'] . '</p>';
			}
		}

		return implode( "\n\n", array_filter( $html, static fn( $h ) => '' !== $h ) );
	}

	/**
	 * A minimal Markdown → **Gutenberg block markup** pass, so a seeded draft
	 * opens as native paragraph/heading/list blocks rather than a single Classic
	 * block. Block-delimiter comments are generated here; the escaped inner HTML
	 * is passed through `wp_kses_post` (the delimiters are added around it, so
	 * they survive).
	 *
	 * @param string $md Markdown source.
	 * @return string Serialized block markup.
	 */
	public function markdown_to_blocks( string $md ): string {
		$out = array();
		foreach ( $this->parse_markdown_blocks( $md ) as $block ) {
			if ( 'heading' === $block['type'] ) {
				$level = (int) $block['level'];
				$attrs = 2 === $level ? '' : ' ' . (string) wp_json_encode( array( 'level' => $level ) );
				$inner = '<h' . $level . '>' . wp_kses_post( $block['html'] ) . '</h' . $level . '>';
				$out[] = '<!-- wp:heading' . $attrs . " -->\n" . $inner . "\n<!-- /wp:heading -->";
			} elseif ( 'list' === $block['type'] ) {
				$items = '';
				foreach ( $block['items'] as $item ) {
					$items .= '<!-- wp:list-item --><li>' . wp_kses_post( $item ) . '</li><!-- /wp:list-item -->';
				}
				$out[] = "<!-- wp:list -->\n<ul>" . $items . "</ul>\n<!-- /wp:list -->";
			} elseif ( 'image' === $block['type'] ) {
				$img = $this->image_html( $block['url'], $block['alt'] );
				if ( '' !== $img ) {
					$out[] = "<!-- wp:image -->\n<figure class=\"wp-block-image\">" . $img . "</figure>\n<!-- /wp:image -->";
				}
			} elseif ( 'embed' === $block['type'] ) {
				$embed = $this->embed_block( $block['url'] );
				if ( '' !== $embed ) {
					$out[] = $embed;
				}
			} else {
				$out[] = "<!-- wp:paragraph -->\n<p>" . wp_kses_post( $block['html'] ) . "</p>\n<!-- /wp:paragraph -->";
			}//end if
		}//end foreach

		return implode( "\n\n", $out );
	}

	/**
	 * An `<img>` from a Markdown image, with the URL `esc_url`'d (unsafe schemes
	 * dropped → no tag) and the alt escaped. Returns '' when the URL isn't safe.
	 *
	 * @param string $url Image URL.
	 * @param string $alt Alt text.
	 * @return string `<img …/>`, or ''.
	 */
	private function image_html( string $url, string $alt ): string {
		$safe = esc_url( $url );
		if ( '' === $safe ) {
			return '';
		}

		return '<img src="' . $safe . '" alt="' . esc_attr( $alt ) . '"/>';
	}

	/**
	 * Whether a string is a bare http(s) URL to a supported video host, so it can
	 * become an embed block rather than a plain link.
	 *
	 * @param string $url Candidate URL.
	 */
	private function is_video_url( string $url ): bool {
		if ( ! preg_match( '#^https?://#i', $url ) || preg_match( '/\s/', $url ) ) {
			return false;
		}
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );

		return (bool) preg_match( '/(^|\.)(youtube\.com|youtu\.be|vimeo\.com)$/', $host );
	}

	/**
	 * A `core/embed` block for a supported video URL, or '' when the URL isn't a
	 * safe http(s) URL. The editor hydrates the provider on open.
	 *
	 * @param string $url Video URL.
	 * @return string Serialized embed block, or ''.
	 */
	private function embed_block( string $url ): string {
		$safe = esc_url( $url );
		if ( '' === $safe ) {
			return '';
		}
		$attrs = (string) wp_json_encode( array( 'url' => $safe ) );

		return '<!-- wp:embed ' . $attrs . " -->\n" .
			'<figure class="wp-block-embed"><div class="wp-block-embed__wrapper">' . "\n" .
			$safe . "\n" .
			'</div></figure>' . "\n" .
			'<!-- /wp:embed -->';
	}

	/**
	 * Build Terraviz embed blocks for a post's grounding: a `terraviz/dataset`
	 * block per linked dataset id and a `terraviz/tour` block for a linked tour,
	 * under a lead-in heading. Empty when there's nothing to embed. Ids are
	 * reduced to the canonical id charset before they reach the block attribute.
	 *
	 * @param array<int,mixed> $dataset_ids Linked dataset ids.
	 * @param string           $tour_id     Linked tour id, or ''.
	 * @return string Serialized block markup, or ''.
	 */
	private function embed_blocks( array $dataset_ids, string $tour_id ): string {
		$blocks = array();

		foreach ( $dataset_ids as $id ) {
			$clean = $this->clean_block_id( (string) $id );
			if ( '' !== $clean ) {
				$blocks[] = '<!-- wp:terraviz/dataset ' . (string) wp_json_encode( array( 'id' => $clean ) ) . ' /-->';
			}
		}

		$tour = $this->clean_block_id( $tour_id );
		if ( '' !== $tour ) {
			$blocks[] = '<!-- wp:terraviz/tour ' . (string) wp_json_encode( array( 'id' => $tour ) ) . ' /-->';
		}

		if ( empty( $blocks ) ) {
			return '';
		}

		$heading = "<!-- wp:heading -->\n<h2>" . esc_html__( 'Explore the data', 'terraviz' ) . "</h2>\n<!-- /wp:heading -->";

		return $heading . "\n\n" . implode( "\n\n", $blocks );
	}

	/**
	 * Reduce an id to the canonical dataset/tour id charset (ULID or slug), so a
	 * node-supplied value can't inject anything into a block attribute.
	 *
	 * @param string $id Raw id.
	 * @return string Cleaned id.
	 */
	private function clean_block_id( string $id ): string {
		return (string) preg_replace( '/[^A-Za-z0-9._:-]/', '', trim( $id ) );
	}

	/**
	 * Inline Markdown for one text run: images, links, bold, italic, code — with
	 * all user text HTML-escaped and every URL passed through `esc_url`. Images
	 * are pulled out before links (an image contains link syntax) so both round-
	 * trip cleanly through escaping.
	 *
	 * @param string $text Raw inline Markdown.
	 * @return string HTML.
	 */
	private function md_inline( string $text ): string {
		$tokens = array();

		$text = (string) preg_replace_callback(
			'/!\[([^\]]*)\]\(([^)\s]+)\)/',
			static function ( $m ) use ( &$tokens ) {
				$idx            = count( $tokens );
				$tokens[ $idx ] = array(
					'kind' => 'img',
					'text' => $m[1],
					'url'  => $m[2],
				);
				return '{{TVTOK' . $idx . '}}';
			},
			$text
		);

		$text = (string) preg_replace_callback(
			'/\[([^\]]+)\]\(([^)\s]+)\)/',
			static function ( $m ) use ( &$tokens ) {
				$idx            = count( $tokens );
				$tokens[ $idx ] = array(
					'kind' => 'a',
					'text' => $m[1],
					'url'  => $m[2],
				);
				return '{{TVTOK' . $idx . '}}';
			},
			$text
		);

		$text = esc_html( $text );
		$text = (string) preg_replace( '/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $text );
		$text = (string) preg_replace( '/(?<!\*)\*([^*\s][^*]*)\*(?!\*)/', '<em>$1</em>', $text );
		$text = (string) preg_replace( '/`([^`]+)`/', '<code>$1</code>', $text );

		return (string) preg_replace_callback(
			'/\{\{TVTOK(\d+)\}\}/',
			function ( $m ) use ( $tokens ) {
				// Only a placeholder *we* minted maps to a token. A literal
				// `{{TVTOKn}}` typed in the body has no token, so leave it as the
				// author wrote it rather than fabricating an empty link/image.
				if ( ! isset( $tokens[ (int) $m[1] ] ) ) {
					return $m[0];
				}
				$tok = $tokens[ (int) $m[1] ];
				if ( 'img' === $tok['kind'] ) {
					return $this->image_html( $tok['url'], $tok['text'] );
				}
				return '<a href="' . esc_url( $tok['url'] ) . '">' . esc_html( $tok['text'] ) . '</a>';
			},
			$text
		);
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

	/**
	 * Validate an event-image upload body before it's forwarded. Returns the
	 * node-shaped body `{ contentType, dataBase64, altText? }` on success, or
	 * `{ error }` describing the first failure. The node performs the
	 * authoritative validation; this is defence in depth so the proxy never
	 * forwards a non-image or an oversized payload.
	 *
	 * The forwarded `contentType` is the type detected from the actual bytes (not
	 * the caller's claim), so a mislabelled file can't set a wrong MIME.
	 *
	 * @param array<string,mixed> $raw Decoded JSON body.
	 * @return array<string,mixed>
	 */
	public function normalize_event_image_body( array $raw ): array {
		$claimed = ( isset( $raw['contentType'] ) && is_string( $raw['contentType'] ) )
			? strtolower( trim( $raw['contentType'] ) ) : '';
		if ( ! in_array( $claimed, self::EVENT_IMAGE_TYPES, true ) ) {
			return array( 'error' => __( 'Unsupported image type. Use JPEG, PNG, GIF, or WebP.', 'terraviz' ) );
		}

		$data = ( isset( $raw['dataBase64'] ) && is_string( $raw['dataBase64'] ) ) ? $raw['dataBase64'] : '';
		// Accept an optional data-URI prefix but forward only the bare base64.
		$data = (string) preg_replace( '#^data:[^;,]+;base64,#i', '', trim( $data ) );
		if ( '' === $data ) {
			return array( 'error' => __( 'The image data is empty.', 'terraviz' ) );
		}

		// Preflight on the *encoded* length so an oversized payload is rejected
		// before base64_decode allocates it — base64 is ~4/3 of the decoded size.
		if ( strlen( $data ) > (int) ceil( self::MAX_EVENT_IMAGE_BYTES / 3 ) * 4 + 4 ) {
			return array( 'error' => __( 'The image is too large (max 4 MB).', 'terraviz' ) );
		}

		$decoded = base64_decode( $data, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decoding an uploaded image to validate its real type/size before forwarding.
		if ( false === $decoded || '' === $decoded ) {
			return array( 'error' => __( 'The image data is not valid base64.', 'terraviz' ) );
		}
		if ( strlen( $decoded ) > self::MAX_EVENT_IMAGE_BYTES ) {
			return array( 'error' => __( 'The image is too large (max 4 MB).', 'terraviz' ) );
		}

		// The bytes must really be a raster image of an accepted family, so a
		// mislabelled non-image can't be forwarded. Sniff the magic bytes rather
		// than getimagesizefromstring(), which warns ("Read error!") on garbage
		// input — a warning the WP test harness promotes to a failure.
		$real_type = $this->sniff_image_mime( $decoded );
		if ( ! in_array( $real_type, self::EVENT_IMAGE_TYPES, true ) ) {
			return array( 'error' => __( 'The uploaded file is not a valid image.', 'terraviz' ) );
		}

		$out = array(
			'contentType' => $real_type,
			'dataBase64'  => $data,
		);

		if ( isset( $raw['altText'] ) && ( is_string( $raw['altText'] ) || is_numeric( $raw['altText'] ) ) ) {
			$alt = sanitize_text_field( (string) $raw['altText'] );
			if ( '' !== $alt ) {
				$out['altText'] = $alt;
			}
		}

		return $out;
	}

	/**
	 * Reduce a caller-supplied AI-draft request to the fields the node's
	 * `blog/generate` accepts: a bounded, deduped, canonical-id dataset list, an
	 * optional cited event id, an optional tone string, a `length` enum, and an
	 * `includeTour` flag. The node performs the authoritative validation; this is
	 * defence in depth so the proxy never forwards arbitrary JSON.
	 *
	 * @param array<string,mixed> $raw Decoded JSON body.
	 * @return array<string,mixed>
	 */
	public function normalize_blog_generate_body( array $raw ): array {
		$out = array();

		$ids     = ( isset( $raw['datasetIds'] ) && is_array( $raw['datasetIds'] ) ) ? $raw['datasetIds'] : array();
		$cleaned = array();
		foreach ( $ids as $id ) {
			if ( is_string( $id ) || is_numeric( $id ) ) {
				$clean = $this->clean_block_id( (string) $id );
				if ( '' !== $clean ) {
					$cleaned[] = $clean;
				}
			}
		}
		// Dedup and cap at the node's POST_MAX_DATASETS (20).
		$out['datasetIds'] = array_slice( array_values( array_unique( $cleaned ) ), 0, 20 );

		if ( isset( $raw['eventId'] ) && ( is_string( $raw['eventId'] ) || is_numeric( $raw['eventId'] ) ) ) {
			$event_id = $this->clean_block_id( (string) $raw['eventId'] );
			if ( '' !== $event_id ) {
				$out['eventId'] = $event_id;
			}
		}

		if ( isset( $raw['tone'] ) && ( is_string( $raw['tone'] ) || is_numeric( $raw['tone'] ) ) ) {
			$tone = sanitize_text_field( (string) $raw['tone'] );
			if ( '' !== $tone ) {
				// Bound the tone hint so a pathological value can't bloat the prompt.
				$out['tone'] = function_exists( 'mb_substr' ) ? mb_substr( $tone, 0, 200 ) : substr( $tone, 0, 200 );
			}
		}

		$length = isset( $raw['length'] ) ? (string) $raw['length'] : '';
		if ( in_array( $length, array( 'short', 'medium', 'long' ), true ) ) {
			$out['length'] = $length;
		}

		// The node treats only a strict boolean true as "include the tour".
		if ( isset( $raw['includeTour'] )
			&& ( true === $raw['includeTour'] || 1 === $raw['includeTour'] || '1' === $raw['includeTour'] || 'true' === strtolower( (string) $raw['includeTour'] ) )
		) {
			$out['includeTour'] = true;
		}

		return $out;
	}

	/**
	 * Identify a raster image family from its leading magic bytes, or '' when the
	 * bytes aren't one of the accepted types. A pure signature check — no
	 * `getimagesizefromstring()` (which warns on unreadable input) and no GD
	 * dependency.
	 *
	 * @param string $bytes Decoded file bytes.
	 * @return string One of {@see self::EVENT_IMAGE_TYPES}, or ''.
	 */
	private function sniff_image_mime( string $bytes ): string {
		if ( strlen( $bytes ) < 12 ) {
			return '';
		}
		if ( "\xFF\xD8\xFF" === substr( $bytes, 0, 3 ) ) {
			return 'image/jpeg';
		}
		if ( "\x89PNG\r\n\x1a\n" === substr( $bytes, 0, 8 ) ) {
			return 'image/png';
		}
		$gif = substr( $bytes, 0, 6 );
		if ( 'GIF87a' === $gif || 'GIF89a' === $gif ) {
			return 'image/gif';
		}
		if ( 'RIFF' === substr( $bytes, 0, 4 ) && 'WEBP' === substr( $bytes, 8, 4 ) ) {
			return 'image/webp';
		}

		return '';
	}
}
