/**
 * Terraviz Dataset block registration.
 */

import { registerBlockType } from '@wordpress/blocks';
import { __ } from '@wordpress/i18n';

import metadata from './block.json';
import { createEdit } from '../shared/edit';

registerBlockType( metadata.name, {
	edit: createEdit( {
		blockName: metadata.name,
		type: 'dataset',
		title: __( 'Terraviz Dataset', 'terraviz' ),
	} ),
	// Dynamic block: markup is produced by the PHP render callback.
	save: () => null,
} );
