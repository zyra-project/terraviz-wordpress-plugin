/**
 * The presigned-R2 asset upload flow, driven from the browser:
 *
 *   1. hash the file locally (SHA-256) — the node re-verifies it on complete;
 *   2. `init` (server-side proxy) to mint a short-lived presigned R2 `PUT`;
 *   3. `PUT` the bytes **directly** to R2 (the service token never touches
 *      this request — only the presigned URL reaches the browser);
 *   4. `complete` (server-side proxy) — the node verifies the digest and swaps
 *      the dataset's ref. A video returns `transcoding: true` (HTTP 202).
 *
 * Note: the direct PUT is cross-origin, so the node's R2 bucket must allow this
 * WordPress site's origin in its CORS policy. That is a one-time node-operator
 * setup; a failed PUT surfaces a message pointing at it.
 */
import { __, sprintf } from '@wordpress/i18n';
import { initAsset, completeAsset } from './api';

/**
 * Compute the lowercase hex SHA-256 of a File/Blob.
 *
 * @param {Blob} file File to hash.
 * @return {Promise<string>} Hex digest.
 */
export async function sha256Hex( file ) {
	const buffer = await file.arrayBuffer();
	const digest = await window.crypto.subtle.digest( 'SHA-256', buffer );
	return Array.from( new Uint8Array( digest ) )
		.map( ( b ) => b.toString( 16 ).padStart( 2, '0' ) )
		.join( '' );
}

/**
 * Run the full upload flow for one file.
 *
 * @param {Object}   args           Arguments.
 * @param {string}   args.id        Dataset id.
 * @param {string}   args.kind      Asset kind (data|thumbnail|legend|caption|sphere_thumbnail).
 * @param {Blob}     args.file      File to upload.
 * @param {Function} [args.onStage] Called with a stage key as it progresses.
 * @return {Promise<Object>} The `complete` response body.
 */
export async function uploadAsset( { id, kind, file, onStage } ) {
	const stage = ( s ) => onStage && onStage( s );

	stage( 'hashing' );
	const hex = await sha256Hex( file );

	stage( 'initiating' );
	const init = await initAsset( id, {
		kind,
		mime: file.type || 'application/octet-stream',
		size: file.size,
		content_digest: `sha256:${ hex }`,
	} );

	// A mock backend (dev) has no real bucket; skip the byte upload and let the
	// node trust the claimed digest on complete.
	if ( ! init.mock && init.r2 && init.r2.url ) {
		stage( 'uploading' );
		const res = await window.fetch( init.r2.url, {
			method: init.r2.method || 'PUT',
			headers: init.r2.headers || {},
			body: file,
		} );
		if ( ! res.ok ) {
			throw new Error(
				sprintf(
					/* translators: %d: HTTP status code. */
					__(
						'Uploading the bytes to storage failed (HTTP %d). The Terraviz node’s storage bucket may need to allow this site’s origin (CORS).',
						'terraviz'
					),
					res.status
				)
			);
		}
	}

	stage( 'finalizing' );
	return completeAsset( id, init.upload_id );
}
