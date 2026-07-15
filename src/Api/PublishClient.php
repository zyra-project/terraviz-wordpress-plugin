<?php
/**
 * Authenticated server-side client for Terraviz's publish API.
 *
 * @package Terraviz
 */

declare( strict_types = 1 );

namespace Terraviz\Api;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A minimal authenticated client over Terraviz's `/api/v1/publish/**` surface.
 *
 * This is the server-side publish proxy's transport: it attaches the
 * Cloudflare Access service-token headers (`Cf-Access-Client-Id` /
 * `Cf-Access-Client-Secret`) — which Cloudflare's edge exchanges for a JWT
 * before the node sees the request — so **the token never reaches the
 * browser**. Every method runs in PHP on behalf of an authorised wp-admin
 * user (see `Rest\PublisherController`).
 *
 * Like {@see Client}, it uses only the `wp_safe_remote_*` family with
 * `reject_unsafe_urls`, so it stays VIP-review-clean and refuses redirects
 * into private/reserved hosts.
 *
 * Every method returns the same normalised result array so callers never have
 * to parse HTTP by hand:
 *
 *   array{
 *     ok:      bool,                  // true on a 2xx with a JSON body
 *     status:  int,                   // HTTP status, or 0 on a transport error
 *     data:    array<string,mixed>,   // decoded response body ({} when none)
 *     error:   string,                // '' on success; a node/synthetic slug otherwise
 *     message: string,                // node/transport message
 *     errors:  array<int,mixed>,      // field-validation errors [{field,code,message}]
 *   }
 */
final class PublishClient {

	/**
	 * Node origin (scheme+host, no trailing slash).
	 *
	 * @var string
	 */
	private $origin;

	/**
	 * Authentication headers (the service-token pair).
	 *
	 * @var array<string,string>
	 */
	private $auth_headers;

	/**
	 * Request timeout in seconds.
	 *
	 * @var int
	 */
	private $timeout;

	/**
	 * Construct the client.
	 *
	 * @param string               $origin       Node origin.
	 * @param array<string,string> $auth_headers Cloudflare Access headers.
	 * @param int                  $timeout      Request timeout in seconds.
	 */
	public function __construct( string $origin, array $auth_headers, int $timeout = 10 ) {
		$this->origin       = untrailingslashit( $origin );
		$this->auth_headers = $auth_headers;
		$this->timeout      = max( 1, $timeout );
	}

	/**
	 * `GET /api/v1/publish/me` — validate the token and return the publisher
	 * profile. Kept in its original shape (`profile`, not `data`) for the
	 * settings-screen "Verify credential" probe.
	 *
	 * @return array{ok:bool,status:int,profile:array<string,mixed>|null,error:string,message:string}
	 */
	public function me(): array {
		$result = $this->send( 'GET', '/api/v1/publish/me' );

		return array(
			'ok'      => $result['ok'],
			'status'  => $result['status'],
			'profile' => $result['ok'] ? $result['data'] : null,
			'error'   => $result['error'],
			'message' => $result['message'],
		);
	}

	/**
	 * `GET /api/v1/publish/datasets` — list the caller's datasets.
	 *
	 * @param array<string,scalar> $query Optional `status`, `cursor`, `limit`.
	 * @return array<string,mixed>
	 */
	public function list_datasets( array $query = array() ): array {
		$path  = '/api/v1/publish/datasets';
		$clean = array();
		foreach ( array( 'status', 'cursor', 'limit' ) as $key ) {
			if ( isset( $query[ $key ] ) && '' !== (string) $query[ $key ] ) {
				$clean[ $key ] = (string) $query[ $key ];
			}
		}
		if ( ! empty( $clean ) ) {
			// Single-encode: add_query_arg() only urlencode_deep()s params already
			// present in the base URL (there are none here) and emits newly-added
			// args verbatim via build_query( urlencode: false ), so the
			// rawurlencode() is the one and only encoding — required so a cursor
			// containing '+' or '/' round-trips correctly.
			$path = add_query_arg( array_map( 'rawurlencode', $clean ), $path );
		}

		return $this->send( 'GET', $path );
	}

	/**
	 * `GET /api/v1/publish/datasets/:id` — fetch one dataset.
	 *
	 * @param string $id Dataset id.
	 * @return array<string,mixed>
	 */
	public function get_dataset( string $id ): array {
		return $this->send( 'GET', '/api/v1/publish/datasets/' . rawurlencode( $id ) );
	}

	/**
	 * `POST /api/v1/publish/datasets` — create a draft.
	 *
	 * @param array<string,mixed> $body Dataset draft fields.
	 * @return array<string,mixed>
	 */
	public function create_dataset( array $body ): array {
		return $this->send( 'POST', '/api/v1/publish/datasets', $body );
	}

	/**
	 * `PUT /api/v1/publish/datasets/:id` — partial update.
	 *
	 * @param string              $id   Dataset id.
	 * @param array<string,mixed> $body Fields to change.
	 * @return array<string,mixed>
	 */
	public function update_dataset( string $id, array $body ): array {
		return $this->send( 'PUT', '/api/v1/publish/datasets/' . rawurlencode( $id ), $body );
	}

	/**
	 * `POST /api/v1/publish/datasets/:id/publish` — publish a draft.
	 *
	 * @param string $id Dataset id.
	 * @return array<string,mixed>
	 */
	public function publish_dataset( string $id ): array {
		return $this->send( 'POST', '/api/v1/publish/datasets/' . rawurlencode( $id ) . '/publish', array() );
	}

	/**
	 * `POST /api/v1/publish/datasets/:id/retract` — retract a published dataset.
	 *
	 * @param string $id Dataset id.
	 * @return array<string,mixed>
	 */
	public function retract_dataset( string $id ): array {
		return $this->send( 'POST', '/api/v1/publish/datasets/' . rawurlencode( $id ) . '/retract', array() );
	}

	/**
	 * `DELETE /api/v1/publish/datasets/:id` — hard-delete a draft/retracted row.
	 *
	 * @param string $id Dataset id.
	 * @return array<string,mixed>
	 */
	public function delete_dataset( string $id ): array {
		return $this->send( 'DELETE', '/api/v1/publish/datasets/' . rawurlencode( $id ) );
	}

	/**
	 * `POST /api/v1/publish/datasets/:id/asset` — begin an asset upload.
	 *
	 * Returns a presigned R2 `PUT` the browser uses to upload the bytes
	 * directly (the service token never touches the upload).
	 *
	 * @param string              $id   Dataset id.
	 * @param array<string,mixed> $body `{ kind, mime, size, content_digest }`.
	 * @return array<string,mixed>
	 */
	public function init_asset( string $id, array $body ): array {
		return $this->send( 'POST', '/api/v1/publish/datasets/' . rawurlencode( $id ) . '/asset', $body );
	}

	/**
	 * `POST /api/v1/publish/datasets/:id/asset/:upload_id/complete` — finalise
	 * an upload. The node re-verifies the digest and swaps the dataset's ref.
	 *
	 * @param string $id        Dataset id.
	 * @param string $upload_id Upload id from init.
	 * @return array<string,mixed>
	 */
	public function complete_asset( string $id, string $upload_id ): array {
		return $this->send(
			'POST',
			'/api/v1/publish/datasets/' . rawurlencode( $id ) . '/asset/' . rawurlencode( $upload_id ) . '/complete',
			array()
		);
	}

	/**
	 * `GET /api/v1/publish/blog` — list blog posts (drafts included),
	 * newest-updated first; `status` narrows to `draft|published`. Any signed-in
	 * publisher may read the authoring list.
	 *
	 * @param array<string,string> $query Optional `status`.
	 * @return array<string,mixed>
	 */
	public function list_blog( array $query = array() ): array {
		$path = '/api/v1/publish/blog';
		if ( ! empty( $query ) ) {
			$path .= '?' . http_build_query( $query );
		}

		return $this->send( 'GET', $path );
	}

	/**
	 * `POST /api/v1/publish/blog` — create a blog-post stub (born draft).
	 *
	 * @param array<string,mixed> $body `{ title, bodyMd, summary?, datasetIds?, tourId?, eventId? }`.
	 * @return array<string,mixed>
	 */
	public function create_blog( array $body ): array {
		return $this->send( 'POST', '/api/v1/publish/blog', $body );
	}

	/**
	 * `PUT /api/v1/publish/blog/:id` — update a blog stub in place (slug and
	 * status are preserved server-side).
	 *
	 * @param string              $id   Blog post id (ULID).
	 * @param array<string,mixed> $body Fields to change.
	 * @return array<string,mixed>
	 */
	public function update_blog( string $id, array $body ): array {
		return $this->send( 'PUT', '/api/v1/publish/blog/' . rawurlencode( $id ), $body );
	}

	/**
	 * `GET /api/v1/publish/blog/:id` — fetch a blog stub (used to detect a
	 * stub deleted upstream).
	 *
	 * @param string $id Blog post id (ULID).
	 * @return array<string,mixed>
	 */
	public function get_blog( string $id ): array {
		return $this->send( 'GET', '/api/v1/publish/blog/' . rawurlencode( $id ) );
	}

	/**
	 * `POST /api/v1/publish/blog/:id` with `{ action }` — publish or unpublish
	 * a blog stub.
	 *
	 * @param string $id     Blog post id (ULID).
	 * @param string $action `publish` or `unpublish`.
	 * @return array<string,mixed>
	 */
	public function set_blog_action( string $id, string $action ): array {
		return $this->send( 'POST', '/api/v1/publish/blog/' . rawurlencode( $id ), array( 'action' => $action ) );
	}

	/**
	 * `POST /api/v1/publish/blog/generate` — AI-draft a blog post grounded in the
	 * caller's selected datasets (and an optional cited event), using the node's
	 * Workers AI. The draft is **returned, not persisted** — the plugin seeds a
	 * WordPress draft from it. Returns `200 { draft:{ title, summary, bodyMd },
	 * tour, tourError }`; `503 ai_unavailable` when the node has no AI binding,
	 * `502` on an unusable model reply, `400 no_datasets` when nothing grounds it.
	 *
	 * A single Workers AI generation on the node is slow — the node caps a draft
	 * at 30s (short/medium) or 60s (long) and a companion tour adds more — so this
	 * call uses a generous timeout well above the default, or the proxy would time
	 * out long before the node answers.
	 *
	 * @param array<string,mixed> $body    `{ datasetIds:string[], eventId?, tone?, length?, includeTour? }`.
	 * @param int                 $timeout Request timeout (seconds).
	 * @return array<string,mixed>
	 */
	public function generate_blog_draft( array $body, int $timeout = 100 ): array {
		return $this->send( 'POST', '/api/v1/publish/blog/generate', $body, $timeout );
	}

	/**
	 * `POST /api/v1/publish/events` — propose a news event. The event is born
	 * `proposed` and awaits a curator's approval on the node; there is no
	 * `PUT`/`GET :id`/delete and no publish/unpublish toggle for the caller, so
	 * this is create-only (the plugin proposes, the curator disposes).
	 *
	 * @param array<string,mixed> $body `{ title, source:{name,url,publishedAt?}, summary?,
	 *                                     externalId?, occurredStart?, imageUrl?, datasetIds? }`.
	 * @return array<string,mixed>
	 */
	public function propose_event( array $body ): array {
		return $this->send( 'POST', '/api/v1/publish/events', $body );
	}

	/**
	 * `GET /api/v1/publish/events` — the curator review queue. `status` narrows
	 * the bucket (`proposed` default, `approved|rejected|expired`, or `all`). The
	 * list carries full event objects (each with suggested dataset `links`), so
	 * there is no per-id fetch.
	 *
	 * @param array<string,string> $query Optional query params (e.g. `status`).
	 * @return array<string,mixed>
	 */
	public function list_events( array $query = array() ): array {
		$path = '/api/v1/publish/events';
		if ( ! empty( $query ) ) {
			$path .= '?' . http_build_query( $query );
		}

		return $this->send( 'GET', $path );
	}

	/**
	 * `POST /api/v1/publish/events/:id` — submit a curator review: approve or
	 * reject the event, accept/reject its suggested dataset links, add datasets,
	 * and apply a bounded set of edits. This is a review submission, not a
	 * publish/unpublish toggle.
	 *
	 * @param string              $id   Event id.
	 * @param array<string,mixed> $body `{ event?:'approve'|'reject', addDatasetIds?:string[],
	 *                                     links?:[{datasetId,decision}], edits?:{…} }`.
	 * @return array<string,mixed>
	 */
	public function review_event( string $id, array $body ): array {
		return $this->send( 'POST', '/api/v1/publish/events/' . rawurlencode( $id ), $body );
	}

	/**
	 * `POST /api/v1/publish/events/:id/tour` — generate an editable tour draft
	 * from a reviewed event (approved dataset pairings first, else top-scored
	 * proposed). Returns `201 { tour:{ id, slug, title } }`; a `400 no_datasets`
	 * (with a field-error envelope) when the event has no visible pairings.
	 *
	 * @param string $id Event id.
	 * @return array<string,mixed>
	 */
	public function generate_event_tour( string $id ): array {
		return $this->send( 'POST', '/api/v1/publish/events/' . rawurlencode( $id ) . '/tour', array() );
	}

	/**
	 * `GET /api/v1/publish/feeds` — list every feed connector (enabled and
	 * paused). The node restricts this to admin/service callers, so the plugin
	 * gates it at the configure tier. Each item is a full connector object with
	 * its last-run status.
	 *
	 * @return array<string,mixed>
	 */
	public function list_feeds(): array {
		return $this->send( 'GET', '/api/v1/publish/feeds' );
	}

	/**
	 * `POST /api/v1/publish/feeds` — create a feed connector.
	 *
	 * @param array<string,mixed> $body `{ kind:'eonet'|'rss', label, url, category?, enabled? }`.
	 * @return array<string,mixed>
	 */
	public function create_feed( array $body ): array {
		return $this->send( 'POST', '/api/v1/publish/feeds', $body );
	}

	/**
	 * `POST /api/v1/publish/feeds/:id` — partially update a feed connector. The
	 * node applies this as a patch (it is a POST, not a `PUT`); `kind` is
	 * immutable and cannot be changed after creation.
	 *
	 * @param string              $id   Feed connector id.
	 * @param array<string,mixed> $body `{ label?, url?, category?, enabled? }`.
	 * @return array<string,mixed>
	 */
	public function update_feed( string $id, array $body ): array {
		return $this->send( 'POST', '/api/v1/publish/feeds/' . rawurlencode( $id ), $body );
	}

	/**
	 * `DELETE /api/v1/publish/feeds/:id` — remove a feed connector. Events it
	 * already ingested remain; only the connector is deleted.
	 *
	 * @param string $id Feed connector id.
	 * @return array<string,mixed>
	 */
	public function delete_feed( string $id ): array {
		return $this->send( 'DELETE', '/api/v1/publish/feeds/' . rawurlencode( $id ) );
	}

	/**
	 * `GET /api/v1/publish/feeds/preview?kind=&url=` — dry-run a feed source
	 * without saving it: reports how many items were fetched, how many are
	 * mappable to events, and a small sample. Writes nothing.
	 *
	 * @param array<string,string> $query `{ kind, url }`.
	 * @return array<string,mixed>
	 */
	public function preview_feed( array $query ): array {
		$path = '/api/v1/publish/feeds/preview';
		if ( ! empty( $query ) ) {
			$path .= '?' . http_build_query( $query );
		}

		return $this->send( 'GET', $path );
	}

	/**
	 * `GET /api/v1/publish/media/youtube-channels` — the effective YouTube
	 * channel allowlist: the built-in curated agency channels (`builtin:true`,
	 * non-removable) plus this node's custom channels (`builtin:false`). The node
	 * restricts this to admin/service callers, so the plugin gates it at the
	 * configure tier.
	 *
	 * @return array<string,mixed>
	 */
	public function list_media_channels(): array {
		return $this->send( 'GET', '/api/v1/publish/media/youtube-channels' );
	}

	/**
	 * `POST /api/v1/publish/media/youtube-channels` — add a custom channel by
	 * pasted URL (`{ url }`). The node resolves it to a canonical `UC…` id.
	 * Returns `201 { channel }`; `400 { errors }` for an unrecognised URL.
	 *
	 * @param array<string,mixed> $body `{ url }`.
	 * @return array<string,mixed>
	 */
	public function create_media_channel( array $body ): array {
		return $this->send( 'POST', '/api/v1/publish/media/youtube-channels', $body );
	}

	/**
	 * `DELETE /api/v1/publish/media/youtube-channels/:id` — remove one custom
	 * channel. Built-in channels aren't in the table, so removing one is a no-op
	 * `404`.
	 *
	 * @param string $id Channel id (`UC…`).
	 * @return array<string,mixed>
	 */
	public function delete_media_channel( string $id ): array {
		return $this->send( 'DELETE', '/api/v1/publish/media/youtube-channels/' . rawurlencode( $id ) );
	}

	/**
	 * `GET /api/v1/publish/media/youtube-search?q=` — agency-YouTube video
	 * candidates for a query, pre-filtered to the allowlisted channels (the
	 * payoff of the Media channels tab). KV-cached upstream; degrades to
	 * `{ videos: [] }` when no `YOUTUBE_API_KEY` is configured on the node.
	 * Returns `{ videos:[{ videoId, title, channelId, channelName }] }`.
	 *
	 * @param string $query Search query (typically the event title).
	 * @return array<string,mixed>
	 */
	public function search_youtube_media( string $query ): array {
		return $this->send( 'GET', '/api/v1/publish/media/youtube-search?' . http_build_query( array( 'q' => $query ) ) );
	}

	/**
	 * `GET /api/v1/publish/media/nhc-storms` — the active tropical-cyclone list
	 * from NHC's CurrentStorms feed, proxied same-origin (NHC serves no CORS
	 * headers). Returns `{ activeStorms:[{ id, name }] }`; the pane matches a
	 * storm by name to a tropical event and builds its forecast-cone graphic URL.
	 *
	 * @return array<string,mixed>
	 */
	public function list_nhc_storms(): array {
		return $this->send( 'GET', '/api/v1/publish/media/nhc-storms' );
	}

	/**
	 * `POST /api/v1/publish/events/:id/image` — upload the org's own photo as the
	 * event's story image (the third path next to the feed `og:image` and the
	 * suggested picks). The node validates (raster only, size-capped), stores it,
	 * and returns `{ imageUrl }`, which the pane then writes back through the
	 * event-review edit path.
	 *
	 * @param string              $id   Event id.
	 * @param array<string,mixed> $body `{ contentType, dataBase64, altText? }`.
	 * @return array<string,mixed>
	 */
	public function set_event_image( string $id, array $body ): array {
		return $this->send( 'POST', '/api/v1/publish/events/' . rawurlencode( $id ) . '/image', $body );
	}

	/**
	 * `GET /api/v1/publish/node-profile` — the singleton host-organization
	 * profile, or `{ profile: null }` when never filled in. Returns
	 * `{ profile: { orgName, mission, aboutMd, regionFocus, defaultTone, links:[{label,url}], logoUrl } }`.
	 *
	 * @return array<string,mixed>
	 */
	public function get_node_profile(): array {
		return $this->send( 'GET', '/api/v1/publish/node-profile' );
	}

	/**
	 * `PUT /api/v1/publish/node-profile` — upsert the node profile. Only
	 * `orgName` is mandatory; a `400 { errors }` field-error envelope is passed
	 * through for validation problems.
	 *
	 * @param array<string,mixed> $body `{ orgName, mission?, aboutMd?, regionFocus?, defaultTone?, links? }`.
	 * @return array<string,mixed>
	 */
	public function set_node_profile( array $body ): array {
		return $this->send( 'PUT', '/api/v1/publish/node-profile', $body );
	}

	/**
	 * `POST /api/v1/publish/node-profile/logo` — upload the org logo (raster
	 * only, ≤512 KB; the node verifies the bytes and serves it publicly). Body
	 * `{ contentType, dataBase64 }`; returns the profile with the new `logoUrl`.
	 *
	 * @param array<string,mixed> $body `{ contentType, dataBase64 }`.
	 * @return array<string,mixed>
	 */
	public function set_node_profile_logo( array $body ): array {
		return $this->send( 'POST', '/api/v1/publish/node-profile/logo', $body );
	}

	/**
	 * `DELETE /api/v1/publish/node-profile/logo` — clear the org logo. Idempotent.
	 *
	 * @return array<string,mixed>
	 */
	public function delete_node_profile_logo(): array {
		return $this->send( 'DELETE', '/api/v1/publish/node-profile/logo' );
	}

	/**
	 * `GET /api/v1/publish/analytics?section=&days=&environment=…` — the typed
	 * analytics facade over the node's daily rollups (not a SQL proxy; the node
	 * validates every parameter against an allowlist). Returns
	 * `{ section, since_day, through_day, environment, data }`; the `data` shape
	 * depends on the section (Overview: totals + daily days[] + platform/OS mix +
	 * top countries).
	 *
	 * @param array<string,string> $query Allowlisted query params.
	 * @return array<string,mixed>
	 */
	public function get_analytics( array $query ): array {
		$path = '/api/v1/publish/analytics';
		if ( ! empty( $query ) ) {
			$path .= '?' . http_build_query( $query );
		}

		return $this->send( 'GET', $path );
	}

	/**
	 * `GET /api/v1/publish/feedback?view=&days=&recent=…` — the privilege-gated
	 * feedback review facade (the node validates the view against an allowlist and
	 * clamps the ranges). Views: `ai` (thumbs dashboard), `general` (bug/feature/
	 * other dashboard), `screenshot` (one report's screenshot data URL by `id`).
	 * Returns `{ view, days, data }` for the dashboards, or `{ id, screenshot }`
	 * for a screenshot.
	 *
	 * @param array<string,int|string> $query Allowlisted query params.
	 * @return array<string,mixed>
	 */
	public function get_feedback( array $query ): array {
		$path = '/api/v1/publish/feedback';
		if ( ! empty( $query ) ) {
			$path .= '?' . http_build_query( $query );
		}

		return $this->send( 'GET', $path );
	}

	/**
	 * `GET /api/v1/publish/tours` — list the caller's tours (cursor-paginated).
	 *
	 * @param array<string,scalar> $query Optional `cursor`, `limit`.
	 * @return array<string,mixed>
	 */
	public function list_tours( array $query = array() ): array {
		$path  = '/api/v1/publish/tours';
		$clean = array();
		foreach ( array( 'cursor', 'limit' ) as $key ) {
			if ( isset( $query[ $key ] ) && '' !== (string) $query[ $key ] ) {
				$clean[ $key ] = (string) $query[ $key ];
			}
		}
		if ( ! empty( $clean ) ) {
			// Single-encode (see list_datasets) so a cursor with '+' or '/'
			// round-trips.
			$path = add_query_arg( array_map( 'rawurlencode', $clean ), $path );
		}

		return $this->send( 'GET', $path );
	}

	/**
	 * `GET /api/v1/publish/tours/:id` — fetch one tour row.
	 *
	 * @param string $id Tour id.
	 * @return array<string,mixed>
	 */
	public function get_tour( string $id ): array {
		return $this->send( 'GET', '/api/v1/publish/tours/' . rawurlencode( $id ) );
	}

	/**
	 * `POST /api/v1/publish/tours/draft` — mint a fresh draft tour (the "New tour"
	 * flow; the actual tour content is then authored in the Terraviz app).
	 *
	 * @param array<string,mixed> $body Optional `{ title }`.
	 * @return array<string,mixed>
	 */
	public function create_tour_draft( array $body = array() ): array {
		return $this->send( 'POST', '/api/v1/publish/tours/draft', $body );
	}

	/**
	 * `PUT /api/v1/publish/tours/:id` — patch tour metadata.
	 *
	 * @param string              $id   Tour id.
	 * @param array<string,mixed> $body Fields to change.
	 * @return array<string,mixed>
	 */
	public function update_tour( string $id, array $body ): array {
		return $this->send( 'PUT', '/api/v1/publish/tours/' . rawurlencode( $id ), $body );
	}

	/**
	 * `POST /api/v1/publish/tours/:id/publish` — publish a tour.
	 *
	 * @param string $id Tour id.
	 * @return array<string,mixed>
	 */
	public function publish_tour( string $id ): array {
		return $this->send( 'POST', '/api/v1/publish/tours/' . rawurlencode( $id ) . '/publish', array() );
	}

	/**
	 * `POST /api/v1/publish/tours/:id/retract` — retract a published tour.
	 *
	 * @param string $id Tour id.
	 * @return array<string,mixed>
	 */
	public function retract_tour( string $id ): array {
		return $this->send( 'POST', '/api/v1/publish/tours/' . rawurlencode( $id ) . '/retract', array() );
	}

	/**
	 * `DELETE /api/v1/publish/tours/:id` — hard-delete a tour row.
	 *
	 * @param string $id Tour id.
	 * @return array<string,mixed>
	 */
	public function delete_tour( string $id ): array {
		return $this->send( 'DELETE', '/api/v1/publish/tours/' . rawurlencode( $id ) );
	}

	/**
	 * `GET /api/v1/featured-hero` — read the current "right now" hero override.
	 *
	 * This is the *public* read endpoint (the publish route exposes no
	 * authenticated GET); the service-token headers are harmless on it and it
	 * keeps the dashboard's hero view on one client. Returns the raw override
	 * envelope `{ hero: { datasetId, window:{ start, end }, headline? } | null }`.
	 *
	 * @return array<string,mixed>
	 */
	public function get_featured_hero(): array {
		return $this->send( 'GET', '/api/v1/featured-hero' );
	}

	/**
	 * `PUT /api/v1/publish/featured-hero` — set (upsert) the singleton hero
	 * override. The activation window is mandatory upstream. Returns
	 * `{ hero: {…} }` on success; `400 { errors }` for body problems and a
	 * typed `404 not_found` when the dataset does not exist.
	 *
	 * @param array<string,mixed> $body `{ dataset_id, window:{ start, end }, headline? }`.
	 * @return array<string,mixed>
	 */
	public function set_featured_hero( array $body ): array {
		return $this->send( 'PUT', '/api/v1/publish/featured-hero', $body );
	}

	/**
	 * `DELETE /api/v1/publish/featured-hero` — clear the hero override.
	 * Idempotent: the node returns `204 No Content` whether a pin was set or
	 * not.
	 *
	 * @return array<string,mixed>
	 */
	public function clear_featured_hero(): array {
		return $this->send( 'DELETE', '/api/v1/publish/featured-hero' );
	}

	/**
	 * Perform a request and normalise the response.
	 *
	 * @param string                   $method HTTP method.
	 * @param string                   $path    Path (with any query string) beginning with '/'.
	 * @param array<string,mixed>|null $body    JSON body to send, or null for none.
	 * @param int|null                 $timeout Per-call timeout (seconds); defaults to the client's.
	 * @return array{ok:bool,status:int,data:array<string,mixed>,error:string,message:string,errors:array<int,mixed>}
	 */
	private function send( string $method, string $path, ?array $body = null, ?int $timeout = null ): array {
		$url = $this->origin . $path;

		$headers = array_merge(
			array(
				'Accept'     => 'application/json',
				'User-Agent' => 'TerravizWP/' . TERRAVIZ_VERSION,
			),
			$this->auth_headers
		);

		$args = array(
			'method'             => $method,
			'timeout'            => null !== $timeout ? max( 1, $timeout ) : $this->timeout,
			'redirection'        => 2,
			'reject_unsafe_urls' => true,
			'headers'            => $headers,
		);

		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			// Cast to object so an empty body serialises to `{}` (a JSON object,
			// which the publish/retract routes expect) rather than `[]`.
			// Non-empty associative payloads and nested lists are unaffected.
			$args['body'] = wp_json_encode( (object) $body );
		}

		/**
		 * Filter the request args for a Terraviz publish-API call.
		 *
		 * @param array  $args Args passed to wp_safe_remote_request().
		 * @param string $url  Fully composed request URL.
		 */
		$args = apply_filters( 'terraviz_publish_request_args', $args, $url );

		$response = wp_safe_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $this->result( false, 0, array(), 'transport', $response->get_error_message() );
		}

		$code    = (int) wp_remote_retrieve_response_code( $response );
		$raw     = wp_remote_retrieve_body( $response );
		$decoded = ( '' !== $raw ) ? json_decode( $raw, true ) : null;
		$data    = is_array( $decoded ) ? $decoded : array();
		$errors  = ( isset( $data['errors'] ) && is_array( $data['errors'] ) ) ? $data['errors'] : array();

		if ( $code >= 200 && $code < 300 ) {
			// A 204 No Content is a legitimate bodyless success (e.g. the hero
			// DELETE route). By definition it carries no body, so — unlike a
			// 200 — it cannot be a login-page interception; accept it with
			// empty data before the non-JSON guard below.
			if ( 204 === $code ) {
				return $this->result( true, $code, array(), '', '' );
			}

			// Every other publish endpoint returns a JSON object on success. A
			// 2xx with an empty or non-JSON body means an intercepting proxy /
			// login page, not the API — treat it as a failure rather than a
			// false success.
			if ( ! is_array( $decoded ) ) {
				return $this->result( false, $code, array(), 'invalid_response', __( 'The node returned a non-JSON response.', 'terraviz' ) );
			}

			return $this->result( true, $code, $data, '', '' );
		}

		$slug = '';
		if ( isset( $data['error'] ) ) {
			$slug = (string) $data['error'];
		} elseif ( ! empty( $errors ) ) {
			$slug = 'validation';
		} else {
			$slug = 'http_' . $code;
		}

		$message = isset( $data['message'] ) ? (string) $data['message'] : '';

		return $this->result( false, $code, $data, $slug, $message, $errors );
	}

	/**
	 * Assemble a normalised result array.
	 *
	 * @param bool                $ok      Whether the call succeeded.
	 * @param int                 $status  HTTP status (0 on transport error).
	 * @param array<string,mixed> $data    Decoded body.
	 * @param string              $error   Error slug ('' on success).
	 * @param string              $message Human message.
	 * @param array<int,mixed>    $errors  Field-validation errors.
	 * @return array{ok:bool,status:int,data:array<string,mixed>,error:string,message:string,errors:array<int,mixed>}
	 */
	private function result( bool $ok, int $status, array $data, string $error, string $message, array $errors = array() ): array {
		return array(
			'ok'      => $ok,
			'status'  => $status,
			'data'    => $data,
			'error'   => $error,
			'message' => $message,
			'errors'  => $errors,
		);
	}
}
