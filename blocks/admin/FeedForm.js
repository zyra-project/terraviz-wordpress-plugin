/**
 * Create / edit form for a feed connector, with a dry-run "Test feed" preview.
 * Inline field-validation errors from the node are surfaced per field. `kind`
 * is immutable, so it is only editable on create.
 */
import { __, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import {
	Button,
	Notice,
	Spinner,
	TextControl,
	SelectControl,
	ToggleControl,
	ExternalLink,
} from '@wordpress/components';
import { createFeed, updateFeed, previewFeed, normalizeError } from './api';

const KINDS = [
	{ label: 'RSS', value: 'rss' },
	{ label: 'NASA EONET', value: 'eonet' },
];

function initialValues( feed ) {
	return {
		kind: feed.kind || 'rss',
		label: feed.label || '',
		url: feed.url || '',
		category: feed.category || '',
		enabled: feed.enabled !== undefined ? !! feed.enabled : true,
	};
}

function buildBody( values, isEdit, original ) {
	const body = {};
	if ( ! isEdit ) {
		body.kind = values.kind;
	}
	[ 'label', 'url' ].forEach( ( key ) => {
		if ( values[ key ] !== '' ) {
			body[ key ] = values[ key ];
		}
	} );
	// category is nullable: clearing a value that existed sends null; an
	// always-empty category is omitted so patches carry no spurious nulls.
	if ( values.category !== '' ) {
		body.category = values.category;
	} else if ( isEdit && original.category !== '' ) {
		body.category = null;
	}
	body.enabled = values.enabled;
	return body;
}

export default function FeedForm( { feed, onSaved, onCancel } ) {
	const isEdit = Boolean( feed && feed.id );
	const original = initialValues( feed || {} );
	const [ values, setValues ] = useState( original );
	const [ saving, setSaving ] = useState( false );
	const [ errors, setErrors ] = useState( [] );
	const [ notice, setNotice ] = useState( null );
	const [ previewing, setPreviewing ] = useState( false );
	const [ preview, setPreview ] = useState( null );

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
		const body = buildBody( values, isEdit, original );
		const req = isEdit ? updateFeed( feed.id, body ) : createFeed( body );
		req.then( ( res ) => {
			onSaved( res.feed || res );
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

	const handlePreview = () => {
		setPreviewing( true );
		setPreview( null );
		setNotice( null );
		previewFeed( { kind: values.kind, url: values.url } )
			.then( ( res ) => setPreview( res ) )
			.catch( ( e ) =>
				setNotice( {
					type: 'error',
					text:
						normalizeError( e ).message ||
						__( 'Could not preview this feed.', 'terraviz' ),
				} )
			)
			.finally( () => setPreviewing( false ) );
	};

	return (
		<div style={ { maxWidth: '720px' } }>
			<h2>
				{ isEdit
					? __( 'Edit feed', 'terraviz' )
					: __( 'New feed', 'terraviz' ) }
			</h2>

			{ notice && (
				<Notice
					status={ notice.type }
					onRemove={ () => setNotice( null ) }
				>
					{ notice.text }
				</Notice>
			) }

			<SelectControl
				label={ __( 'Kind', 'terraviz' ) }
				value={ values.kind }
				options={ KINDS }
				onChange={ set( 'kind' ) }
				disabled={ isEdit }
				help={
					isEdit
						? __(
								'The connector kind cannot be changed after creation.',
								'terraviz'
						  )
						: fieldError( 'kind' )
				}
				__nextHasNoMarginBottom
			/>
			<TextControl
				label={ __( 'Label', 'terraviz' ) }
				value={ values.label }
				onChange={ set( 'label' ) }
				help={ fieldError( 'label' ) }
				__nextHasNoMarginBottom
			/>
			<TextControl
				label={ __( 'Feed URL', 'terraviz' ) }
				type="url"
				value={ values.url }
				onChange={ set( 'url' ) }
				help={
					fieldError( 'url' ) ||
					__( 'An http(s) URL for the source feed.', 'terraviz' )
				}
				__nextHasNoMarginBottom
			/>
			<TextControl
				label={ __( 'Category (optional)', 'terraviz' ) }
				value={ values.category }
				onChange={ set( 'category' ) }
				help={ fieldError( 'category' ) }
				__nextHasNoMarginBottom
			/>
			<ToggleControl
				label={ __( 'Enabled', 'terraviz' ) }
				checked={ values.enabled }
				onChange={ set( 'enabled' ) }
				help={ __(
					'Disabled connectors are kept but not polled for new events.',
					'terraviz'
				) }
				__nextHasNoMarginBottom
			/>

			<div
				style={ {
					borderTop: '1px solid #eee',
					marginTop: '16px',
					paddingTop: '12px',
				} }
			>
				<Button
					variant="secondary"
					onClick={ handlePreview }
					isBusy={ previewing }
					disabled={ previewing || values.url === '' }
				>
					{ __( 'Test feed', 'terraviz' ) }
				</Button>
				{ previewing && <Spinner /> }
				{ preview && (
					<div style={ { marginTop: '8px' } }>
						<p>
							{ sprintf(
								/* translators: 1: number of items fetched, 2: number mappable to events. */
								__(
									'Fetched %1$d item(s); %2$d mappable to events.',
									'terraviz'
								),
								preview.fetched || 0,
								preview.mappable || 0
							) }
						</p>
						{ Array.isArray( preview.items ) &&
							preview.items.length > 0 && (
								<ul style={ { margin: '0 0 0 18px' } }>
									{ preview.items.map( ( item, i ) => (
										<li key={ i }>
											{ item.url ? (
												<ExternalLink href={ item.url }>
													{ item.title || item.url }
												</ExternalLink>
											) : (
												item.title || '—'
											) }
										</li>
									) ) }
								</ul>
							) }
					</div>
				) }
			</div>

			<div style={ { display: 'flex', gap: '8px', marginTop: '16px' } }>
				<Button
					variant="primary"
					onClick={ handleSave }
					isBusy={ saving }
					disabled={ saving }
				>
					{ isEdit
						? __( 'Save changes', 'terraviz' )
						: __( 'Create feed', 'terraviz' ) }
				</Button>
				<Button
					variant="tertiary"
					onClick={ onCancel }
					disabled={ saving }
				>
					{ __( 'Back to feeds', 'terraviz' ) }
				</Button>
			</div>
		</div>
	);
}
