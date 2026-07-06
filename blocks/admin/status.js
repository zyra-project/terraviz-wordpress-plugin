/**
 * Dataset lifecycle helpers. The Terraviz row has no `status` column — state is
 * derived from the `published_at` / `retracted_at` timestamps (mirrors the
 * node's own `dataset-mutations.ts` derivation).
 */
import { __ } from '@wordpress/i18n';

export function deriveStatus( dataset ) {
	if ( ! dataset ) {
		return 'draft';
	}
	if ( dataset.retracted_at ) {
		return 'retracted';
	}
	if ( dataset.published_at ) {
		return 'published';
	}
	return 'draft';
}

export function statusLabel( status ) {
	switch ( status ) {
		case 'published':
			return __( 'Published', 'terraviz' );
		case 'retracted':
			return __( 'Retracted', 'terraviz' );
		default:
			return __( 'Draft', 'terraviz' );
	}
}
