/**
 * Create / edit form for a dataset draft. Handles inline field-validation
 * errors returned by the node and the publish action.
 */
import { __ } from '@wordpress/i18n';
import { useState, useEffect } from '@wordpress/element';
import {
	Button,
	Notice,
	Spinner,
	TextControl,
	TextareaControl,
	SelectControl,
} from '@wordpress/components';
import {
	getDataset,
	createDataset,
	updateDataset,
	publishDataset,
	normalizeError,
} from './api';
import { deriveStatus } from './status';

const FORMATS = [
	'video/mp4',
	'image/png',
	'image/jpeg',
	'image/webp',
	'tour/json',
];
const VISIBILITIES = [ 'public', 'federated', 'restricted', 'private' ];

const BLANK = {
	title: '',
	slug: '',
	format: 'image/png',
	visibility: 'public',
	abstract: '',
	organization: '',
	data_ref: '',
	website_link: '',
	license_spdx: '',
	license_statement: '',
	attribution_text: '',
	keywords: '',
	tags: '',
	bbox_n: '',
	bbox_s: '',
	bbox_w: '',
	bbox_e: '',
};

function toBody( values ) {
	const body = {};
	[
		'title',
		'slug',
		'format',
		'visibility',
		'abstract',
		'organization',
		'data_ref',
		'website_link',
		'license_spdx',
		'license_statement',
		'attribution_text',
	].forEach( ( key ) => {
		if ( values[ key ] !== '' ) {
			body[ key ] = values[ key ];
		}
	} );

	[ 'keywords', 'tags' ].forEach( ( key ) => {
		const list = values[ key ]
			.split( ',' )
			.map( ( s ) => s.trim() )
			.filter( Boolean );
		if ( list.length ) {
			body[ key ] = list;
		}
	} );

	const box = {};
	[
		[ 'bbox_n', 'n' ],
		[ 'bbox_s', 's' ],
		[ 'bbox_w', 'w' ],
		[ 'bbox_e', 'e' ],
	].forEach( ( [ field, corner ] ) => {
		if (
			values[ field ] !== '' &&
			! Number.isNaN( Number( values[ field ] ) )
		) {
			box[ corner ] = Number( values[ field ] );
		}
	} );
	if ( Object.keys( box ).length === 4 ) {
		body.bounding_box = box;
	}

	return body;
}

function fromDataset( ds ) {
	const box = ds.bounding_box || {};
	return {
		...BLANK,
		...Object.fromEntries(
			Object.keys( BLANK )
				.filter(
					( k ) =>
						ds[ k ] !== undefined &&
						ds[ k ] !== null &&
						! k.startsWith( 'bbox_' ) &&
						k !== 'keywords' &&
						k !== 'tags'
				)
				.map( ( k ) => [ k, String( ds[ k ] ) ] )
		),
		keywords: Array.isArray( ds.keywords ) ? ds.keywords.join( ', ' ) : '',
		tags: Array.isArray( ds.tags ) ? ds.tags.join( ', ' ) : '',
		bbox_n: box.n !== undefined ? String( box.n ) : '',
		bbox_s: box.s !== undefined ? String( box.s ) : '',
		bbox_w: box.w !== undefined ? String( box.w ) : '',
		bbox_e: box.e !== undefined ? String( box.e ) : '',
	};
}

export default function DatasetForm( { id, canPublish, onSaved, onCancel } ) {
	const isEdit = Boolean( id );
	const [ values, setValues ] = useState( BLANK );
	const [ dataset, setDataset ] = useState( null );
	const [ loading, setLoading ] = useState( isEdit );
	const [ saving, setSaving ] = useState( false );
	const [ errors, setErrors ] = useState( [] );
	const [ notice, setNotice ] = useState( null );

	useEffect( () => {
		let active = true;
		if ( ! isEdit ) {
			return undefined;
		}
		setLoading( true );
		getDataset( id )
			.then( ( res ) => {
				if ( ! active ) {
					return;
				}
				const ds = res.dataset || res;
				setDataset( ds );
				setValues( fromDataset( ds ) );
			} )
			.catch(
				( e ) =>
					active &&
					setNotice( {
						type: 'error',
						text: normalizeError( e ).message,
					} )
			)
			.finally( () => active && setLoading( false ) );
		return () => {
			active = false;
		};
	}, [ id, isEdit ] );

	const set = ( key ) => ( value ) =>
		setValues( { ...values, [ key ]: value } );

	const fieldError = ( name ) => {
		const hit = errors.find( ( e ) => e.field === name );
		return hit ? hit.message : null;
	};

	const handleSave = () => {
		setSaving( true );
		setErrors( [] );
		setNotice( null );
		const body = toBody( values );
		const req = isEdit ? updateDataset( id, body ) : createDataset( body );
		req.then( ( res ) => {
			const ds = res.dataset || res;
			setDataset( ds );
			setNotice( { type: 'success', text: __( 'Saved.', 'terraviz' ) } );
			onSaved( ds );
		} )
			.catch( ( e ) => {
				const n = normalizeError( e );
				setErrors( n.errors );
				setNotice( {
					type: 'error',
					text:
						n.message ||
						__(
							'Could not save. Check the highlighted fields.',
							'terraviz'
						),
				} );
			} )
			.finally( () => setSaving( false ) );
	};

	const handlePublish = () => {
		setSaving( true );
		setErrors( [] );
		setNotice( null );
		publishDataset( id )
			.then( ( res ) => {
				const ds = res.dataset || res;
				setDataset( ds );
				setNotice( {
					type: 'success',
					text: __( 'Published.', 'terraviz' ),
				} );
				onSaved( ds );
			} )
			.catch( ( e ) => {
				const n = normalizeError( e );
				setErrors( n.errors );
				setNotice( {
					type: 'error',
					text:
						n.message ||
						__(
							'Could not publish. A published dataset needs a title, slug, format, visibility, data reference and a license.',
							'terraviz'
						),
				} );
			} )
			.finally( () => setSaving( false ) );
	};

	if ( loading ) {
		return <Spinner />;
	}

	const status = deriveStatus( dataset );

	return (
		<div style={ { maxWidth: '720px' } }>
			<h2>
				{ isEdit
					? __( 'Edit dataset', 'terraviz' )
					: __( 'New dataset', 'terraviz' ) }
			</h2>

			{ notice && (
				<Notice
					status={ notice.type }
					onRemove={ () => setNotice( null ) }
				>
					{ notice.text }
				</Notice>
			) }

			<TextControl
				label={ __( 'Title', 'terraviz' ) }
				value={ values.title }
				onChange={ set( 'title' ) }
				help={ fieldError( 'title' ) }
				className={
					fieldError( 'title' ) ? 'terraviz-field-error' : ''
				}
				__nextHasNoMarginBottom
			/>
			<TextControl
				label={ __( 'Slug', 'terraviz' ) }
				value={ values.slug }
				onChange={ set( 'slug' ) }
				help={
					fieldError( 'slug' ) ||
					__(
						'Lowercase letters, numbers, hyphens. Left blank, it is derived from the title.',
						'terraviz'
					)
				}
				__nextHasNoMarginBottom
			/>
			<SelectControl
				label={ __( 'Format', 'terraviz' ) }
				value={ values.format }
				options={ FORMATS.map( ( f ) => ( { label: f, value: f } ) ) }
				onChange={ set( 'format' ) }
				help={ fieldError( 'format' ) }
				__nextHasNoMarginBottom
			/>
			<SelectControl
				label={ __( 'Visibility', 'terraviz' ) }
				value={ values.visibility }
				options={ VISIBILITIES.map( ( v ) => ( {
					label: v,
					value: v,
				} ) ) }
				onChange={ set( 'visibility' ) }
				help={ fieldError( 'visibility' ) }
				__nextHasNoMarginBottom
			/>
			<TextareaControl
				label={ __( 'Abstract', 'terraviz' ) }
				value={ values.abstract }
				onChange={ set( 'abstract' ) }
				help={ fieldError( 'abstract' ) }
				__nextHasNoMarginBottom
			/>
			<TextControl
				label={ __( 'Data reference', 'terraviz' ) }
				value={ values.data_ref }
				onChange={ set( 'data_ref' ) }
				help={
					fieldError( 'data_ref' ) ||
					__(
						'An R2 ref or URL for the data. Direct asset upload arrives in a later release.',
						'terraviz'
					)
				}
				__nextHasNoMarginBottom
			/>
			<TextControl
				label={ __( 'Organization', 'terraviz' ) }
				value={ values.organization }
				onChange={ set( 'organization' ) }
				help={ fieldError( 'organization' ) }
				__nextHasNoMarginBottom
			/>
			<TextControl
				label={ __( 'Website link', 'terraviz' ) }
				type="url"
				value={ values.website_link }
				onChange={ set( 'website_link' ) }
				help={ fieldError( 'website_link' ) }
				__nextHasNoMarginBottom
			/>
			<TextControl
				label={ __( 'License (SPDX id)', 'terraviz' ) }
				value={ values.license_spdx }
				onChange={ set( 'license_spdx' ) }
				help={
					fieldError( 'license_spdx' ) ||
					__(
						'e.g. CC-BY-4.0. Publishing needs an SPDX id or a license statement.',
						'terraviz'
					)
				}
				__nextHasNoMarginBottom
			/>
			<TextareaControl
				label={ __( 'License statement', 'terraviz' ) }
				value={ values.license_statement }
				onChange={ set( 'license_statement' ) }
				help={ fieldError( 'license_statement' ) }
				__nextHasNoMarginBottom
			/>
			<TextControl
				label={ __( 'Attribution', 'terraviz' ) }
				value={ values.attribution_text }
				onChange={ set( 'attribution_text' ) }
				help={ fieldError( 'attribution_text' ) }
				__nextHasNoMarginBottom
			/>
			<TextControl
				label={ __( 'Keywords (comma-separated)', 'terraviz' ) }
				value={ values.keywords }
				onChange={ set( 'keywords' ) }
				help={ fieldError( 'keywords' ) }
				__nextHasNoMarginBottom
			/>
			<TextControl
				label={ __( 'Tags (comma-separated)', 'terraviz' ) }
				value={ values.tags }
				onChange={ set( 'tags' ) }
				help={ fieldError( 'tags' ) }
				__nextHasNoMarginBottom
			/>

			<fieldset
				style={ {
					border: '1px solid #ddd',
					padding: '8px 12px',
					margin: '12px 0',
				} }
			>
				<legend>{ __( 'Bounding box (optional)', 'terraviz' ) }</legend>
				<div
					style={ { display: 'flex', gap: '8px', flexWrap: 'wrap' } }
				>
					<TextControl
						label={ __( 'North', 'terraviz' ) }
						type="number"
						value={ values.bbox_n }
						onChange={ set( 'bbox_n' ) }
						__nextHasNoMarginBottom
					/>
					<TextControl
						label={ __( 'South', 'terraviz' ) }
						type="number"
						value={ values.bbox_s }
						onChange={ set( 'bbox_s' ) }
						__nextHasNoMarginBottom
					/>
					<TextControl
						label={ __( 'West', 'terraviz' ) }
						type="number"
						value={ values.bbox_w }
						onChange={ set( 'bbox_w' ) }
						__nextHasNoMarginBottom
					/>
					<TextControl
						label={ __( 'East', 'terraviz' ) }
						type="number"
						value={ values.bbox_e }
						onChange={ set( 'bbox_e' ) }
						__nextHasNoMarginBottom
					/>
				</div>
				{ fieldError( 'bounding_box' ) && (
					<p
						className="components-base-control__help"
						style={ { color: '#d63638' } }
					>
						{ fieldError( 'bounding_box' ) }
					</p>
				) }
			</fieldset>

			<div style={ { display: 'flex', gap: '8px', marginTop: '16px' } }>
				<Button
					variant="primary"
					onClick={ handleSave }
					isBusy={ saving }
					disabled={ saving }
				>
					{ isEdit
						? __( 'Save changes', 'terraviz' )
						: __( 'Create draft', 'terraviz' ) }
				</Button>
				{ isEdit && canPublish && status !== 'published' && (
					<Button
						variant="secondary"
						onClick={ handlePublish }
						isBusy={ saving }
						disabled={ saving }
					>
						{ __( 'Publish', 'terraviz' ) }
					</Button>
				) }
				<Button
					variant="tertiary"
					onClick={ onCancel }
					disabled={ saving }
				>
					{ __( 'Back to list', 'terraviz' ) }
				</Button>
			</div>
		</div>
	);
}
