/**
 * "Draft with AI" form for the Blog area (Blog slice 4).
 *
 * Grounds an AI blog draft in the publisher's own datasets (and an optional
 * approved event) via the node's `blog/generate`, then hands the author into
 * the WordPress editor with the result seeded as real Gutenberg blocks + the
 * grounding embeds. The draft is opted into Terraviz, so publishing it in WP
 * surfaces it on the globe through the existing sync — the from-scratch,
 * AI-assisted counterpart to "Create WordPress post".
 *
 * The AI lives on the node (Workers AI); the plugin only proxies. If the node
 * has no AI binding the generate call returns a typed error we surface here.
 */
import { __ } from '@wordpress/i18n';
import { useState, useEffect } from '@wordpress/element';
import {
	Button,
	Notice,
	Spinner,
	FormTokenField,
	SelectControl,
	TextControl,
	CheckboxControl,
} from '@wordpress/components';
import {
	listDatasets,
	listEvents,
	generateBlogDraft,
	normalizeError,
} from './api';
import { safeHttpUrl } from './safeUrl';

const LENGTHS = [
	{ label: __( 'Short (~250 words)', 'terraviz' ), value: 'short' },
	{ label: __( 'Medium (~500 words)', 'terraviz' ), value: 'medium' },
	{ label: __( 'Long (~900 words)', 'terraviz' ), value: 'long' },
];

export default function DraftWithAI( { onCancel } ) {
	const [ datasets, setDatasets ] = useState( [] );
	const [ events, setEvents ] = useState( [] );
	const [ loading, setLoading ] = useState( true );

	const [ tokens, setTokens ] = useState( [] );
	const [ eventId, setEventId ] = useState( '' );
	const [ tone, setTone ] = useState( '' );
	const [ length, setLength ] = useState( 'medium' );
	const [ includeTour, setIncludeTour ] = useState( false );

	const [ busy, setBusy ] = useState( false );
	const [ notice, setNotice ] = useState( null );

	useEffect( () => {
		let active = true;
		// Published datasets are the groundable ones (the node drops hidden /
		// unpublished selections anyway); approved events are the citable ones.
		Promise.all( [
			listDatasets( 'published' ).catch( () => ( { datasets: [] } ) ),
			listEvents( 'approved' ).catch( () => ( { events: [] } ) ),
		] )
			.then( ( [ ds, ev ] ) => {
				if ( ! active ) {
					return;
				}
				setDatasets( Array.isArray( ds.datasets ) ? ds.datasets : [] );
				setEvents( Array.isArray( ev.events ) ? ev.events : [] );
			} )
			.finally( () => active && setLoading( false ) );
		return () => {
			active = false;
		};
	}, [] );

	// FormTokenField works in display labels; keep a label→id map to resolve the
	// picks back to dataset ids (a token with no match is treated as a raw id). A
	// Map, not a plain object, so a token like `constructor`/`__proto__` can't
	// resolve to an inherited property.
	const labelFor = ( d ) => `${ d.title || d.id } (${ d.id })`;
	const labelToId = new Map();
	datasets.forEach( ( d ) => {
		labelToId.set( labelFor( d ), d.id );
	} );
	const suggestions = datasets.map( labelFor );

	const resolveIds = () =>
		tokens
			.map( ( t ) =>
				labelToId.has( t ) ? labelToId.get( t ) : String( t ).trim()
			)
			.filter( Boolean );

	const eventOptions = [
		{ label: __( '— None —', 'terraviz' ), value: '' },
		...events.map( ( e ) => ( {
			label: e.title || e.id,
			value: e.id,
		} ) ),
	];

	const submit = () => {
		const datasetIds = resolveIds();
		if ( datasetIds.length === 0 ) {
			setNotice( {
				type: 'error',
				text: __(
					'Pick at least one dataset to ground the draft in.',
					'terraviz'
				),
			} );
			return;
		}
		setBusy( true );
		setNotice( null );

		const data = { datasetIds, length };
		if ( eventId ) {
			data.eventId = eventId;
		}
		if ( tone.trim() ) {
			data.tone = tone.trim();
		}
		// A companion tour only makes sense when a cited event is selected.
		if ( includeTour && eventId ) {
			data.includeTour = true;
		}

		generateBlogDraft( data )
			.then( ( res ) => {
				const editUrl = safeHttpUrl( res && res.editUrl );
				if ( editUrl ) {
					window.location.assign( editUrl );
				} else {
					setBusy( false );
					setNotice( {
						type: 'success',
						text: __(
							'Draft created. Open it from your WordPress posts.',
							'terraviz'
						),
					} );
				}
			} )
			.catch( ( e ) => {
				setBusy( false );
				setNotice( {
					type: 'error',
					text:
						normalizeError( e ).message ||
						__(
							'Could not generate a draft. The node may not have AI drafting enabled.',
							'terraviz'
						),
				} );
			} );
	};

	if ( loading ) {
		return <Spinner />;
	}

	return (
		<div
			style={ {
				border: '1px solid #dcdcde',
				borderRadius: '4px',
				padding: '16px',
				margin: '12px 0',
				background: '#fff',
				maxWidth: '640px',
			} }
		>
			<h3 style={ { marginTop: 0 } }>
				{ __( 'Draft with AI', 'terraviz' ) }
			</h3>
			<p style={ { color: '#646970', marginTop: 0 } }>
				{ __(
					'Terraviz drafts a post grounded in the datasets you pick (and an optional current event), then opens it in WordPress for you to edit and publish. Nothing publishes automatically.',
					'terraviz'
				) }
			</p>

			{ notice && (
				<Notice
					status={ notice.type }
					onRemove={ () => setNotice( null ) }
				>
					{ notice.text }
				</Notice>
			) }

			<FormTokenField
				label={ __( 'Datasets to ground the draft in', 'terraviz' ) }
				value={ tokens }
				suggestions={ suggestions }
				onChange={ setTokens }
				__experimentalExpandOnFocus
				__nextHasNoMarginBottom
			/>

			<div style={ { marginTop: '12px' } }>
				<SelectControl
					label={ __( 'Cited event (optional)', 'terraviz' ) }
					value={ eventId }
					options={ eventOptions }
					onChange={ setEventId }
					__nextHasNoMarginBottom
				/>
			</div>

			<div style={ { marginTop: '12px' } }>
				<SelectControl
					label={ __( 'Length', 'terraviz' ) }
					value={ length }
					options={ LENGTHS }
					onChange={ setLength }
					__nextHasNoMarginBottom
				/>
			</div>

			<div style={ { marginTop: '12px' } }>
				<TextControl
					label={ __( 'Tone (optional)', 'terraviz' ) }
					value={ tone }
					onChange={ setTone }
					placeholder={ __(
						'e.g. accessible and factual',
						'terraviz'
					) }
					__nextHasNoMarginBottom
				/>
			</div>

			{ eventId && (
				<div style={ { marginTop: '12px' } }>
					<CheckboxControl
						label={ __(
							'Also generate a companion tour for the cited event',
							'terraviz'
						) }
						checked={ includeTour }
						onChange={ setIncludeTour }
						__nextHasNoMarginBottom
					/>
				</div>
			) }

			<div style={ { display: 'flex', gap: '8px', marginTop: '16px' } }>
				<Button
					variant="primary"
					onClick={ submit }
					isBusy={ busy }
					disabled={ busy }
				>
					{ busy
						? __( 'Generating…', 'terraviz' )
						: __( 'Generate draft', 'terraviz' ) }
				</Button>
				<Button
					variant="tertiary"
					onClick={ onCancel }
					disabled={ busy }
				>
					{ __( 'Cancel', 'terraviz' ) }
				</Button>
			</div>

			{ busy && (
				<p style={ { color: '#646970', marginTop: '8px' } }>
					{ __(
						'Generating on the node — this can take up to a minute or two, especially for a long draft with a companion tour. Keep this tab open.',
						'terraviz'
					) }
				</p>
			) }
		</div>
	);
}
