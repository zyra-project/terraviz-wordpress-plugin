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

	private const NAMESPACE = 'terraviz/v1';
	private const BASE      = '/publisher/datasets';

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
}
