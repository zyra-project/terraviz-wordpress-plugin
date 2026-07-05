/**
 * Terraviz Right-Now Hero block registration.
 */

import { registerBlockType } from '@wordpress/blocks';
import { __ } from '@wordpress/i18n';

import metadata from './block.json';
import { createEdit } from '../shared/edit';

registerBlockType( metadata.name, {
	edit: createEdit( {
		blockName: metadata.name,
		type: 'hero',
		title: __( 'Terraviz Right-Now Hero', 'terraviz' ),
	} ),
	save: () => null,
} );
