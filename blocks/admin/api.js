/**
 * Thin wrapper over @wordpress/api-fetch for the same-origin publisher REST
 * proxy. Every call is server-side proxied to the Terraviz publish API by PHP;
 * the service token never reaches this code.
 */
import apiFetch from '@wordpress/api-fetch';

const boot = window.terravizPublisher || {};
const root = boot.restRoot || '';

const datasetsUrl = () => `${ root }/datasets`;
const datasetUrl = ( id ) => `${ datasetsUrl() }/${ encodeURIComponent( id ) }`;

export function listDatasets( status ) {
	const url = status
		? `${ datasetsUrl() }?status=${ encodeURIComponent( status ) }`
		: datasetsUrl();
	return apiFetch( { url } );
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
