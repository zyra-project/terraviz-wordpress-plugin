/**
 * Thin wrapper over @wordpress/api-fetch for the same-origin publisher REST
 * proxy. Every call is server-side proxied to the Terraviz publish API by PHP;
 * the service token never reaches this code.
 */
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

const boot = window.terravizPublisher || {};
const root = boot.restRoot || '';

const datasetsUrl = () => `${ root }/datasets`;
const datasetUrl = ( id ) => `${ datasetsUrl() }/${ encodeURIComponent( id ) }`;

/**
 * List all of the caller's datasets, following the node's `next_cursor`
 * pagination to completion.
 *
 * The publisher list endpoint returns a single page per call
 * (`{ datasets, next_cursor }`), so a one-shot request only ever shows the
 * first page — which is why the dashboard table looked much smaller than the
 * node's catalog. We loop, passing the previous page's `next_cursor`, until the
 * node reports no more pages.
 *
 * @param {string} [status] Optional status filter ('draft'|'published'|'retracted').
 * @return {Promise<{datasets: Array}>} Every dataset across all pages.
 */
export async function listDatasets( status ) {
	// Defensive bound so a buggy/repeating cursor can never spin forever.
	// 100 pages is far beyond any real catalog size.
	const MAX_PAGES = 100;
	const datasets = [];
	let cursor;
	let page = 0;
	for ( ; page < MAX_PAGES; page++ ) {
		const query = {};
		if ( status ) {
			query.status = status;
		}
		if ( cursor ) {
			query.cursor = cursor;
		}
		const qs = new URLSearchParams( query ).toString();
		const url = qs ? `${ datasetsUrl() }?${ qs }` : datasetsUrl();
		// eslint-disable-next-line no-await-in-loop -- pages are sequential: each request needs the cursor returned by the previous one.
		const res = await apiFetch( { url } );
		if ( Array.isArray( res.datasets ) ) {
			datasets.push( ...res.datasets );
		}
		cursor = res.next_cursor;
		if ( ! cursor ) {
			break;
		}
	}
	// If we hit the page bound while the node still reports more pages, fail
	// loudly rather than silently returning a truncated list — that would
	// reintroduce the exact "missing datasets" symptom this fixes. App.js's
	// catch turns the rejection into an error notice.
	if ( cursor ) {
		throw new Error(
			__(
				'Could not load the full dataset list (too many pages). Please reload and try again.',
				'terraviz'
			)
		);
	}
	return { datasets };
}

export function getDataset( id ) {
	return apiFetch( { url: datasetUrl( id ) } );
}

export function createDataset( data ) {
	return apiFetch( { url: datasetsUrl(), method: 'POST', data } );
}

export function updateDataset( id, data ) {
	return apiFetch( { url: datasetUrl( id ), method: 'PUT', data } );
}

export function publishDataset( id ) {
	return apiFetch( { url: `${ datasetUrl( id ) }/publish`, method: 'POST' } );
}

export function retractDataset( id ) {
	return apiFetch( { url: `${ datasetUrl( id ) }/retract`, method: 'POST' } );
}

export function deleteDataset( id ) {
	return apiFetch( { url: datasetUrl( id ), method: 'DELETE' } );
}

export function initAsset( id, data ) {
	return apiFetch( {
		url: `${ datasetUrl( id ) }/asset`,
		method: 'POST',
		data,
	} );
}

export function completeAsset( id, uploadId ) {
	return apiFetch( {
		url: `${ datasetUrl( id ) }/asset/${ encodeURIComponent(
			uploadId
		) }/complete`,
		method: 'POST',
	} );
}

const eventsUrl = () => `${ root }/events`;
const eventUrl = ( id ) => `${ eventsUrl() }/${ encodeURIComponent( id ) }`;

/**
 * List the events review queue. Unlike datasets, the node returns the whole
 * queue in one shot (no cursor), each item carrying its suggested dataset
 * `links`.
 *
 * @param {string} [status] Bucket filter ('proposed'|'approved'|'rejected'|'expired'|'all').
 * @return {Promise<{events: Array}>} The queue.
 */
export function listEvents( status ) {
	const url = status
		? `${ eventsUrl() }?status=${ encodeURIComponent( status ) }`
		: eventsUrl();
	return apiFetch( { url } );
}

/**
 * Submit a curator review for one event.
 *
 * @param {string} id   Event id.
 * @param {Object} data `{ event?, addDatasetIds?, links?, edits? }`.
 * @return {Promise<Object>} The reviewed event + link decisions.
 */
export function reviewEvent( id, data ) {
	return apiFetch( { url: eventUrl( id ), method: 'POST', data } );
}

const feedsUrl = () => `${ root }/feeds`;
const feedUrl = ( id ) => `${ feedsUrl() }/${ encodeURIComponent( id ) }`;

/**
 * List every feed connector (the node returns them all in one shot).
 *
 * @return {Promise<{feeds: Array}>} The connectors.
 */
export function listFeeds() {
	return apiFetch( { url: feedsUrl() } );
}

/**
 * Create a feed connector.
 *
 * @param {Object} data `{ kind, label, url, category?, enabled? }`.
 * @return {Promise<{feed: Object}>} The created connector.
 */
export function createFeed( data ) {
	return apiFetch( { url: feedsUrl(), method: 'POST', data } );
}

/**
 * Partially update a feed connector (`kind` is immutable). The node applies
 * this as a patch over POST — there is no PUT.
 *
 * @param {string} id   Feed connector id.
 * @param {Object} data `{ label?, url?, category?, enabled? }`.
 * @return {Promise<{feed: Object}>} The updated connector.
 */
export function updateFeed( id, data ) {
	return apiFetch( { url: feedUrl( id ), method: 'POST', data } );
}

/**
 * Delete a feed connector. Events it already ingested remain.
 *
 * @param {string} id Feed connector id.
 * @return {Promise<Object>} `{ deleted: true }`.
 */
export function deleteFeed( id ) {
	return apiFetch( { url: feedUrl( id ), method: 'DELETE' } );
}

/**
 * Dry-run a feed source without saving it.
 *
 * @param {{kind: string, url: string}} params Source to preview.
 * @return {Promise<{fetched: number, mappable: number, items: Array}>} Preview.
 */
export function previewFeed( { kind, url } ) {
	const qs = new URLSearchParams( { kind, url } ).toString();
	return apiFetch( { url: `${ feedsUrl() }/preview?${ qs }` } );
}

const mediaUrl = () => `${ root }/media/youtube-channels`;

/**
 * List the effective YouTube channel allowlist (built-in + custom).
 *
 * @return {Promise<{channels: Array}>} `{ channels:[{channelId,channelName,builtin}] }`.
 */
export function listMediaChannels() {
	return apiFetch( { url: mediaUrl() } );
}

/**
 * Add a custom YouTube channel by pasted URL.
 *
 * @param {string} url Channel URL.
 * @return {Promise<{channel: Object}>} The added channel.
 */
export function createMediaChannel( url ) {
	return apiFetch( { url: mediaUrl(), method: 'POST', data: { url } } );
}

/**
 * Remove a custom YouTube channel.
 *
 * @param {string} id Channel id (`UC…`).
 * @return {Promise<{removed: boolean}>} Result.
 */
export function deleteMediaChannel( id ) {
	return apiFetch( {
		url: `${ mediaUrl() }/${ encodeURIComponent( id ) }`,
		method: 'DELETE',
	} );
}

/**
 * Search agency-YouTube video candidates for the suggested-media pane. The node
 * holds the API key and pre-filters to the allowlisted channels; it degrades to
 * an empty list when no key is configured.
 *
 * @param {string} q Query (typically the event title).
 * @return {Promise<{videos: Array}>} `{ videos:[{videoId,title,channelId,channelName}] }`.
 */
export function searchYoutubeMedia( q ) {
	return apiFetch( {
		url: `${ root }/media/youtube-search?q=${ encodeURIComponent( q ) }`,
	} );
}

/**
 * List active tropical cyclones (proxied same-origin from NHC), so the pane can
 * match a storm name to a tropical event and offer its forecast-cone graphic.
 *
 * @return {Promise<{activeStorms: Array}>} `{ activeStorms:[{id,name}] }`.
 */
export function listNhcStorms() {
	return apiFetch( { url: `${ root }/media/nhc-storms` } );
}

/**
 * Upload the org's own photo as an event's story image. The node validates and
 * stores it, returning the resulting `imageUrl` to write through the review path.
 *
 * @param {string} id   Event id.
 * @param {Object} data `{ contentType, dataBase64, altText? }`.
 * @return {Promise<{imageUrl: string}>} The stored image URL.
 */
export function setEventImage( id, data ) {
	return apiFetch( {
		url: `${ eventUrl( id ) }/image`,
		method: 'POST',
		data,
	} );
}

const eventTourUrl = ( id ) => `${ eventUrl( id ) }/tour`;

/**
 * Generate an editable tour draft from a reviewed event.
 *
 * @param {string} id Event id.
 * @return {Promise<{tour: Object}>} `{ tour:{ id, slug, title } }`.
 */
export function generateEventTour( id ) {
	return apiFetch( { url: eventTourUrl( id ), method: 'POST' } );
}

const blogUrl = () => `${ root }/blog`;

/**
 * List blog posts (drafts included), newest-updated first. Each post is
 * decorated server-side with `wp_edit_url` when a WordPress post is linked to
 * it.
 *
 * @param {string} [status] Optional filter ('draft'|'published').
 * @return {Promise<{posts: Array}>} The posts.
 */
export function listBlog( status ) {
	const url = status
		? `${ blogUrl() }?status=${ encodeURIComponent( status ) }`
		: blogUrl();
	return apiFetch( { url } );
}

/**
 * Seed a WordPress draft post from a node blog post, linking the two so the
 * existing WP→node sync carries edits back. Idempotent — returns the existing
 * WP post if the node post is already linked.
 *
 * @param {string} id Node blog-post id.
 * @return {Promise<{wpId: number, editUrl: string, already_linked?: boolean}>} The WP post.
 */
export function importBlogToWp( id ) {
	return apiFetch( {
		url: `${ blogUrl() }/${ encodeURIComponent( id ) }/import-to-wp`,
		method: 'POST',
	} );
}

/**
 * AI-draft a blog post on the node from the selected datasets (and an optional
 * cited event), then seed a WordPress draft from the result. Returns the seeded
 * post; rejects with the node's error on 503 (no AI configured) / 502 / 400.
 *
 * @param {Object} data `{ datasetIds, eventId?, tone?, length?, includeTour? }`.
 * @return {Promise<{wpId: number, editUrl: string, tour: ?Object, tourError: ?string}>} The seeded post.
 */
export function generateBlogDraft( data ) {
	return apiFetch( {
		url: `${ blogUrl() }/generate`,
		method: 'POST',
		data,
	} );
}

const heroUrl = () => `${ root }/featured-hero`;

/**
 * Read the current "right now" hero override.
 *
 * @return {Promise<{hero: ?Object}>} `{ hero: { datasetId, window:{start,end}, headline? } | null }`.
 */
export function getFeaturedHero() {
	return apiFetch( { url: heroUrl() } );
}

/**
 * Set (upsert) the hero override. The activation window is mandatory upstream.
 *
 * @param {Object} data `{ dataset_id, window:{ start, end }, headline? }`.
 * @return {Promise<{hero: Object}>} The stored override.
 */
export function setFeaturedHero( data ) {
	return apiFetch( { url: heroUrl(), method: 'PUT', data } );
}

/**
 * Clear the hero override. Idempotent (the node returns 204 either way);
 * apiFetch resolves a 204 to null, and still parses error bodies on failure.
 *
 * @return {Promise<*>} Resolves when cleared.
 */
export function clearFeaturedHero() {
	return apiFetch( { url: heroUrl(), method: 'DELETE' } );
}

const nodeProfileUrl = () => `${ root }/node-profile`;

/**
 * Read the node's host-organization profile.
 *
 * @return {Promise<{profile: ?Object}>} `{ profile: {orgName, mission, aboutMd, regionFocus, defaultTone, links, logoUrl} | null }`.
 */
export function getNodeProfile() {
	return apiFetch( { url: nodeProfileUrl() } );
}

/**
 * Upsert the node profile. Only `orgName` is required; the node rejects bad
 * bodies with a `{ errors:[{field,code,message}] }` envelope.
 *
 * @param {Object} data `{ orgName, mission?, aboutMd?, regionFocus?, defaultTone?, links? }`.
 * @return {Promise<{profile: Object}>} The stored profile.
 */
export function setNodeProfile( data ) {
	return apiFetch( { url: nodeProfileUrl(), method: 'PUT', data } );
}

/**
 * Upload the org logo (raster only, ≤512 KB — enforced on the node).
 *
 * @param {Object} data `{ contentType, dataBase64 }`.
 * @return {Promise<{profile: Object}>} The profile with the new `logoUrl`.
 */
export function setNodeProfileLogo( data ) {
	return apiFetch( {
		url: `${ nodeProfileUrl() }/logo`,
		method: 'POST',
		data,
	} );
}

/**
 * Clear the org logo. Idempotent.
 *
 * @return {Promise<Object>} The updated profile.
 */
export function deleteNodeProfileLogo() {
	return apiFetch( {
		url: `${ nodeProfileUrl() }/logo`,
		method: 'DELETE',
	} );
}

const analyticsUrl = () => `${ root }/analytics`;

/**
 * Read a typed analytics section. The node validates every parameter against an
 * allowlist and returns `{ section, since_day, through_day, environment, data }`
 * — the `data` shape depends on the section (Overview: totals + daily `days[]` +
 * platform/OS mix + top countries). Unknown/out-of-range params are dropped by
 * the PHP proxy before they reach the node, which re-validates.
 *
 * @param {Object} [query] `{ section?, days?, environment?, event?, projection?, layer? }`.
 * @return {Promise<Object>} The section envelope.
 */
export function getAnalytics( query ) {
	const qs = new URLSearchParams( query || {} ).toString();
	const url = qs ? `${ analyticsUrl() }?${ qs }` : analyticsUrl();
	return apiFetch( { url } );
}

const feedbackUrl = () => `${ root }/feedback`;

/**
 * Read a feedback review view. `view: 'ai'` / `'general'` return a dashboard
 * envelope `{ view, days, data }` (totals + `byDay` + recent reports); `view:
 * 'screenshot'` with an `id` returns `{ id, screenshot }` (a data URL). The PHP
 * proxy allowlists the view and clamps the ranges before the node re-validates.
 *
 * @param {Object} query `{ view, days?, recent?, id? }`.
 * @return {Promise<Object>} The view payload.
 */
export function getFeedback( query ) {
	const qs = new URLSearchParams( query || {} ).toString();
	const url = qs ? `${ feedbackUrl() }?${ qs }` : feedbackUrl();
	return apiFetch( { url } );
}

/**
 * Normalise an apiFetch rejection into `{ message, errors }`. apiFetch rejects
 * with the parsed JSON error body, so field-validation errors from the node
 * (`errors: [{ field, code, message }]`) arrive intact.
 *
 * @param {*} error Rejected value from apiFetch.
 * @return {{message: string, errors: Array}} Normalised error.
 */
export function normalizeError( error ) {
	if ( ! error || typeof error !== 'object' ) {
		return { message: String( error || 'Unknown error' ), errors: [] };
	}
	return {
		message: error.message || '',
		errors: Array.isArray( error.errors ) ? error.errors : [],
	};
}
