/**
 * Curator review screen for one proposed event. Shows the event, lets the
 * curator edit the bounded set of fields the node accepts, accept/reject its
 * suggested dataset links, attach more datasets, and approve or reject it.
 *
 * The list already carries the full event object (the node exposes no per-id
 * fetch), so this receives it as a prop rather than loading it.
 */
import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import {
	Button,
	Notice,
	TextControl,
	ExternalLink,
	ToggleControl,
} from '@wordpress/components';
import { reviewEvent, normalizeError } from './api';

function initialEdits( ev ) {
	const geo = ev.geometry || {};
	const point = geo.point || {};
	return {
		occurredStart: ev.occurredStart || '',
		regionName: geo.regionName || '',
		lat: point.lat !== undefined ? String( point.lat ) : '',
		lon: point.lon !== undefined ? String( point.lon ) : '',
		imageUrl: ev.imageUrl || '',
		imageAlt: ev.imageAlt || '',
		videoEmbedUrl: ev.videoEmbedUrl || '',
	};
}

function buildEdits( edits, original ) {
	const out = {};
	[ 'occurredStart', 'regionName', 'imageUrl' ].forEach( ( key ) => {
		if ( edits[ key ] !== '' ) {
			out[ key ] = edits[ key ];
		}
	} );
	// imageAlt/videoEmbedUrl are nullable on the node: a non-empty value sets
	// the field, and clearing a field that previously had a value sends null to
	// remove it. A field that was empty and stayed empty is simply omitted, so
	// review submissions don't carry spurious nulls.
	[ 'imageAlt', 'videoEmbedUrl' ].forEach( ( key ) => {
		if ( edits[ key ] !== '' ) {
			out[ key ] = edits[ key ];
		} else if ( original[ key ] !== '' ) {
			out[ key ] = null;
		}
	} );
	if (
		edits.lat !== '' &&
		edits.lon !== '' &&
		! Number.isNaN( Number( edits.lat ) ) &&
		! Number.isNaN( Number( edits.lon ) )
	) {
		out.point = { lat: Number( edits.lat ), lon: Number( edits.lon ) };
	}
	return out;
}

export default function EventReview( { event, onReviewed, onCancel } ) {
	const [ edits, setEdits ] = useState( initialEdits( event ) );
	const [ linkDecisions, setLinkDecisions ] = useState( {} );
	const [ addIds, setAddIds ] = useState( '' );
	const [ saving, setSaving ] = useState( false );
	const [ errors, setErrors ] = useState( [] );
	const [ notice, setNotice ] = useState( null );

	const suggested = Array.isArray( event.links ) ? event.links : [];
	const set = ( key ) => ( value ) =>
		setEdits( { ...edits, [ key ]: value } );
	const fieldError = ( name ) => {
		const hit = errors.find( ( e ) => e.field === name );
		return hit ? hit.message : null;
	};

	const submit = ( decision ) => {
		setSaving( true );
		setErrors( [] );
		setNotice( null );

		const body = {};
		if ( decision ) {
			body.event = decision;
		}
		const editBody = buildEdits( edits, initialEdits( event ) );
		if ( Object.keys( editBody ).length ) {
			body.edits = editBody;
		}
		const links = Object.entries( linkDecisions )
			.filter( ( [ , d ] ) => d )
			.map( ( [ datasetId, d ] ) => ( { datasetId, decision: d } ) );
		if ( links.length ) {
			body.links = links;
		}
		const add = addIds
			.split( ',' )
			.map( ( s ) => s.trim() )
			.filter( Boolean );
		if ( add.length ) {
			body.addDatasetIds = add;
		}

		reviewEvent( event.id, body )
			.then( () => onReviewed() )
			.catch( ( e ) => {
				const n = normalizeError( e );
				setErrors( n.errors );
				setNotice( {
					type: 'error',
					text:
						n.message ||
						__(
							'Could not submit the review. Check the highlighted fields.',
							'terraviz'
						),
				} );
				setSaving( false );
			} );
	};

	return (
		<div style={ { maxWidth: '720px' } }>
			<h2>{ __( 'Review event', 'terraviz' ) }</h2>

			{ notice && (
				<Notice
					status={ notice.type }
					onRemove={ () => setNotice( null ) }
				>
					{ notice.text }
				</Notice>
			) }

			<p style={ { fontSize: '15px' } }>
				<strong>{ event.title || event.id }</strong>
				{ event.status ? ` — ${ event.status }` : '' }
			</p>
			{ event.source && event.source.url && (
				<p>
					<ExternalLink href={ event.source.url }>
						{ event.source.name || event.source.url }
					</ExternalLink>
				</p>
			) }
			{ event.summary && <p>{ event.summary }</p> }

			<h3>{ __( 'Edits', 'terraviz' ) }</h3>
			<TextControl
				label={ __( 'Occurred (ISO date/time)', 'terraviz' ) }
				value={ edits.occurredStart }
				onChange={ set( 'occurredStart' ) }
				help={ fieldError( 'occurredStart' ) }
				__nextHasNoMarginBottom
			/>
			<TextControl
				label={ __( 'Region name', 'terraviz' ) }
				value={ edits.regionName }
				onChange={ set( 'regionName' ) }
				help={
					fieldError( 'regionName' ) ||
					__(
						'A known Terraviz region name; the node rejects unknown values.',
						'terraviz'
					)
				}
				__nextHasNoMarginBottom
			/>
			<div style={ { display: 'flex', gap: '8px' } }>
				<TextControl
					label={ __( 'Latitude', 'terraviz' ) }
					type="number"
					value={ edits.lat }
					onChange={ set( 'lat' ) }
					__nextHasNoMarginBottom
				/>
				<TextControl
					label={ __( 'Longitude', 'terraviz' ) }
					type="number"
					value={ edits.lon }
					onChange={ set( 'lon' ) }
					__nextHasNoMarginBottom
				/>
			</div>
			<TextControl
				label={ __( 'Image URL', 'terraviz' ) }
				type="url"
				value={ edits.imageUrl }
				onChange={ set( 'imageUrl' ) }
				help={ fieldError( 'imageUrl' ) }
				__nextHasNoMarginBottom
			/>
			<TextControl
				label={ __( 'Image alt text', 'terraviz' ) }
				value={ edits.imageAlt }
				onChange={ set( 'imageAlt' ) }
				help={ fieldError( 'imageAlt' ) }
				__nextHasNoMarginBottom
			/>
			<TextControl
				label={ __(
					'Video embed URL (YouTube no-cookie)',
					'terraviz'
				) }
				type="url"
				value={ edits.videoEmbedUrl }
				onChange={ set( 'videoEmbedUrl' ) }
				help={ fieldError( 'videoEmbedUrl' ) }
				__nextHasNoMarginBottom
			/>

			{ suggested.length > 0 && (
				<>
					<h3>{ __( 'Suggested datasets', 'terraviz' ) }</h3>
					{ suggested.map( ( link ) => (
						<div
							key={ link.datasetId }
							style={ {
								borderTop: '1px solid #eee',
								padding: '8px 0',
							} }
						>
							<strong>
								{ link.datasetTitle || link.datasetId }
							</strong>
							{ typeof link.score === 'number'
								? ` (${ Math.round( link.score * 100 ) }%)`
								: '' }
							<div
								style={ {
									display: 'flex',
									gap: '16px',
									marginTop: '4px',
								} }
							>
								<ToggleControl
									label={ __( 'Approve', 'terraviz' ) }
									checked={
										linkDecisions[ link.datasetId ] ===
										'approve'
									}
									onChange={ ( on ) =>
										setLinkDecisions( {
											...linkDecisions,
											[ link.datasetId ]: on
												? 'approve'
												: '',
										} )
									}
									__nextHasNoMarginBottom
								/>
								<ToggleControl
									label={ __( 'Reject', 'terraviz' ) }
									checked={
										linkDecisions[ link.datasetId ] ===
										'reject'
									}
									onChange={ ( on ) =>
										setLinkDecisions( {
											...linkDecisions,
											[ link.datasetId ]: on
												? 'reject'
												: '',
										} )
									}
									__nextHasNoMarginBottom
								/>
							</div>
						</div>
					) ) }
				</>
			) }

			<h3>{ __( 'Attach datasets', 'terraviz' ) }</h3>
			<TextControl
				label={ __( 'Dataset ids (comma-separated)', 'terraviz' ) }
				value={ addIds }
				onChange={ setAddIds }
				help={ fieldError( 'addDatasetIds' ) }
				__nextHasNoMarginBottom
			/>

			<div style={ { display: 'flex', gap: '8px', marginTop: '16px' } }>
				<Button
					variant="primary"
					onClick={ () => submit( 'approve' ) }
					isBusy={ saving }
					disabled={ saving }
				>
					{ __( 'Approve', 'terraviz' ) }
				</Button>
				<Button
					variant="secondary"
					isDestructive
					onClick={ () => submit( 'reject' ) }
					isBusy={ saving }
					disabled={ saving }
				>
					{ __( 'Reject', 'terraviz' ) }
				</Button>
				<Button
					variant="secondary"
					onClick={ () => submit( null ) }
					isBusy={ saving }
					disabled={ saving }
				>
					{ __( 'Save changes', 'terraviz' ) }
				</Button>
				<Button
					variant="tertiary"
					onClick={ onCancel }
					disabled={ saving }
				>
					{ __( 'Back to queue', 'terraviz' ) }
				</Button>
			</div>
		</div>
	);
}
