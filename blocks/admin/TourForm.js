/**
 * Create / edit form for a tour's **metadata** (title + description). The tour's
 * content — the task sequence, camera moves, narration — is authored in the
 * Terraviz app's tour editor, not here; this form links out to it. New tours are
 * minted as an empty draft (the node's `/tours/draft` flow).
 */
import { __ } from '@wordpress/i18n';
import { useState, useEffect } from '@wordpress/element';
import {
	Button,
	Notice,
	Spinner,
	TextControl,
	TextareaControl,
	ExternalLink,
} from '@wordpress/components';
import {
	getTour,
	createTourDraft,
	updateTour,
	publishTour,
	normalizeError,
} from './api';
import { deriveStatus, statusLabel } from './status';

/**
 * The Terraviz app URL that opens this tour in the authoring editor.
 *
 * @param {string} origin Node origin.
 * @param {string} id     Tour id.
 * @return {string} The editor URL.
 */
function editorUrl( origin, id ) {
	return `${ origin.replace( /\/$/, '' ) }/?tourEdit=${ encodeURIComponent(
		id
	) }`;
}

export default function TourForm( {
	id,
	origin,
	canPublish,
	onSaved,
	onCancel,
} ) {
	const isEdit = Boolean( id );
	const [ title, setTitle ] = useState( '' );
	const [ description, setDescription ] = useState( '' );
	const [ tour, setTour ] = useState( null );
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
		getTour( id )
			.then( ( res ) => {
				if ( ! active ) {
					return;
				}
				const t = res.tour || res;
				setTour( t );
				setTitle( t.title || '' );
				setDescription( t.description || '' );
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

	const fieldError = ( name ) => {
		const hit = errors.find( ( e ) => e.field === name );
		return hit ? hit.message : null;
	};

	const handleSave = () => {
		setSaving( true );
		setErrors( [] );
		setNotice( null );
		// A new tour is a draft mint (title only); an edit patches metadata. An
		// emptied description is sent as null so the node clears it (the
		// normalizer distinguishes null from a trimmed empty string).
		const req = isEdit
			? updateTour( id, {
					title,
					description: description.trim() === '' ? null : description,
			  } )
			: createTourDraft( title ? { title } : {} );
		req.then( ( res ) => {
			const t = res.tour || res;
			setTour( t );
			setNotice( { type: 'success', text: __( 'Saved.', 'terraviz' ) } );
			onSaved( t );
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
		publishTour( id )
			.then( ( res ) => {
				const t = res.tour || res;
				setTour( t );
				setNotice( {
					type: 'success',
					text: __( 'Published.', 'terraviz' ),
				} );
				onSaved( t );
			} )
			.catch( ( e ) => {
				const n = normalizeError( e );
				setErrors( n.errors );
				setNotice( {
					type: 'error',
					text:
						n.message ||
						__( 'Could not publish this tour.', 'terraviz' ),
				} );
			} )
			.finally( () => setSaving( false ) );
	};

	if ( loading ) {
		return <Spinner />;
	}

	const status = deriveStatus( tour );

	return (
		<div style={ { maxWidth: '720px' } }>
			<h2>
				{ isEdit
					? __( 'Edit tour', 'terraviz' )
					: __( 'New tour', 'terraviz' ) }
			</h2>

			{ notice && (
				<Notice
					status={ notice.type }
					onRemove={ () => setNotice( null ) }
				>
					{ notice.text }
				</Notice>
			) }

			{ isEdit && (
				<p style={ { color: '#646970', marginTop: 0 } }>
					{ __( 'Status:', 'terraviz' ) }
					<strong>{ statusLabel( status ) }</strong>
				</p>
			) }

			<TextControl
				label={ __( 'Title', 'terraviz' ) }
				value={ title }
				onChange={ setTitle }
				help={
					fieldError( 'title' ) ||
					__( 'At least 3 characters.', 'terraviz' )
				}
				className={
					fieldError( 'title' ) ? 'terraviz-field-error' : ''
				}
				__nextHasNoMarginBottom
			/>

			{ isEdit && (
				<TextareaControl
					label={ __( 'Description', 'terraviz' ) }
					value={ description }
					onChange={ setDescription }
					help={ fieldError( 'description' ) }
					__nextHasNoMarginBottom
				/>
			) }

			{ isEdit && (
				<div
					style={ {
						margin: '16px 0',
						padding: '12px 16px',
						border: '1px solid #dcdcde',
						borderRadius: '4px',
						background: '#fff',
					} }
				>
					<strong>{ __( 'Tour content', 'terraviz' ) }</strong>
					<p style={ { color: '#646970', margin: '4px 0 8px' } }>
						{ __(
							'The task sequence, camera moves and narration are authored in the Terraviz tour editor.',
							'terraviz'
						) }
					</p>
					<ExternalLink href={ editorUrl( origin, id ) }>
						{ __( 'Edit tour content in Terraviz', 'terraviz' ) }
					</ExternalLink>
				</div>
			) }

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
