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
