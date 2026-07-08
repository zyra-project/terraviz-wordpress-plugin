/**
 * "Right now" hero management (Newsroom → Right now).
 *
 * The node exposes a single operator-wide homepage pin: a dataset shown on the
 * Terraviz front page for an activation window, with an optional headline. This
 * view reads the current pin (`GET /api/v1/featured-hero`), lets a publish-tier
 * curator set it (`PUT`) or clear it (`DELETE`), and previews the chosen
 * dataset as a catalog card — all through the same server-side proxy the rest
 * of the dashboard uses, so the service token never reaches the browser.
 *
 * The activation window is mandatory upstream; the node performs the
 * authoritative validation (ISO-8601, `start` before `end`, headline length)
 * and its field errors are surfaced inline.
 */
import { __, sprintf } from '@wordpress/i18n';
import { useState, useEffect, useCallback } from '@wordpress/element';
import {
	Button,
	Notice,
	Spinner,
	TextControl,
	ComboboxControl,
	ExternalLink,
} from '@wordpress/components';
import {
	getFeaturedHero,
	setFeaturedHero,
	clearFeaturedHero,
	listDatasets,
	normalizeError,
} from './api';
import { safeHttpUrl } from './safeUrl';

/** Node cap on a curator headline (mirrors HERO_HEADLINE_MAX_LEN upstream). */
const HEADLINE_MAX = 120;

/**
 * Format an ISO-8601 timestamp as the value an `<input type="datetime-local">`
 * expects (local wall-clock, minute precision): `YYYY-MM-DDTHH:mm`. Returns ''
 * for an empty or unparseable input.
 *
 * @param {string} iso ISO-8601 timestamp.
 * @return {string} datetime-local value, or ''.
 */
function isoToLocal( iso ) {
	if ( ! iso ) {
		return '';
	}
	const d = new Date( iso );
	if ( Number.isNaN( d.getTime() ) ) {
		return '';
	}
	const pad = ( n ) => String( n ).padStart( 2, '0' );
	return (
		`${ d.getFullYear() }-${ pad( d.getMonth() + 1 ) }-${ pad(
			d.getDate()
		) }` + `T${ pad( d.getHours() ) }:${ pad( d.getMinutes() ) }`
	);
}

/**
 * Convert a datetime-local value back to a full ISO-8601 string, or '' when
 * empty/unparseable (the node then rejects it with a field error).
 *
 * @param {string} local datetime-local value.
 * @return {string} ISO-8601 string, or ''.
 */
function localToIso( local ) {
	if ( ! local ) {
		return '';
	}
	const d = new Date( local );
	return Number.isNaN( d.getTime() ) ? '' : d.toISOString();
}

/** A default 7-day window (now → +7d) to seed the form when nothing is pinned. */
function defaultWindow() {
	const now = new Date();
	const end = new Date( now.getTime() + 7 * 24 * 60 * 60 * 1000 );
	return {
		start: isoToLocal( now.toISOString() ),
		end: isoToLocal( end.toISOString() ),
	};
}

/**
 * A lightweight catalog-card preview of the selected dataset, built from the
 * dataset list the picker already loaded (no extra fetch) plus a link out to
 * the node.
 *
 * @param {Object}  props
 * @param {?Object} props.dataset Selected dataset row, or null.
 * @param {string}  props.origin  Node origin, for the "view on Terraviz" link.
 * @return {?JSX.Element} The card, or null when nothing is selected.
 */
function DatasetPreview( { dataset, origin } ) {
	if ( ! dataset ) {
		return null;
	}
	// Prefer the node-resolved `thumbnail_url`; `thumbnail_ref` is a raw ref
	// (R2 path / Vimeo id) that usually isn't a renderable http(s) URL. Mirrors
	// the DatasetList preview cell.
	const thumb =
		safeHttpUrl( dataset.thumbnail_url ) ||
		safeHttpUrl( dataset.thumbnail_ref ) ||
		safeHttpUrl( dataset.thumbnail );
	const link = safeHttpUrl(
		`${ origin }/?dataset=${ encodeURIComponent( dataset.id ) }`
	);
	return (
		<div
			style={ {
				display: 'flex',
				gap: '12px',
				border: '1px solid #dcdcde',
				borderRadius: '4px',
				padding: '12px',
				marginTop: '8px',
				maxWidth: '520px',
			} }
		>
			{ thumb && (
				<img
					src={ thumb }
					alt=""
					style={ {
						width: '96px',
						height: '96px',
						objectFit: 'cover',
						borderRadius: '2px',
						flex: '0 0 auto',
					} }
				/>
			) }
			<div style={ { minWidth: 0 } }>
				<strong>{ dataset.title || dataset.id }</strong>
				{ dataset.abstract && (
					<p
						style={ {
							margin: '4px 0 0',
							color: '#50575e',
							fontSize: '13px',
						} }
					>
						{ dataset.abstract.length > 160
							? `${ dataset.abstract.slice( 0, 160 ) }…`
							: dataset.abstract }
					</p>
				) }
				{ link && (
					<p style={ { margin: '6px 0 0' } }>
						<ExternalLink href={ link }>
							{ __( 'View on Terraviz', 'terraviz' ) }
						</ExternalLink>
					</p>
				) }
			</div>
		</div>
	);
}

export default function RightNow( { boot } ) {
	const [ loading, setLoading ] = useState( true );
	const [ hero, setHero ] = useState( null );
	const [ datasets, setDatasets ] = useState( [] );
	const [ datasetsError, setDatasetsError ] = useState( null );

	const [ datasetId, setDatasetId ] = useState( '' );
	const [ start, setStart ] = useState( '' );
	const [ end, setEnd ] = useState( '' );
	const [ headline, setHeadline ] = useState( '' );

	const [ saving, setSaving ] = useState( false );
	const [ clearing, setClearing ] = useState( false );
	const [ errors, setErrors ] = useState( [] );
	const [ notice, setNotice ] = useState( null );

	// Seed the form from a pin (if any), else the default window.
	const seedForm = useCallback( ( pin ) => {
		const win = defaultWindow();
		setDatasetId( pin ? pin.datasetId || '' : '' );
		setStart(
			pin && pin.window ? isoToLocal( pin.window.start ) : win.start
		);
		setEnd( pin && pin.window ? isoToLocal( pin.window.end ) : win.end );
		setHeadline( pin && pin.headline ? pin.headline : '' );
	}, [] );

	const load = useCallback( () => {
		setLoading( true );
		setDatasetsError( null );
		// The hero read degrades to "no pin" on any node error (the upstream GET
		// itself fails closed to null), so a hiccup never blocks setting one.
		const heroP = getFeaturedHero()
			.then( ( res ) => ( res && res.hero ) || null )
			.catch( () => null );
		// Datasets back the picker + preview; a failure leaves a manual-id path.
		const dsP = listDatasets()
			.then( ( res ) =>
				Array.isArray( res.datasets ) ? res.datasets : []
			)
			.catch( ( e ) => {
				setDatasetsError( normalizeError( e ).message );
				return [];
			} );

		Promise.all( [ heroP, dsP ] ).then( ( [ pin, ds ] ) => {
			setHero( pin );
			setDatasets( ds );
			seedForm( pin );
			setLoading( false );
		} );
	}, [ seedForm ] );

	useEffect( () => {
		load();
	}, [ load ] );

	const fieldError = ( name ) => {
		const hit = errors.find( ( e ) => e.field === name );
		return hit ? hit.message : null;
	};

	// Picker options from the caller's datasets. If the current pin references a
	// dataset not in the list (e.g. pinned elsewhere), keep it selectable by id.
	const options = datasets
		.filter( ( d ) => d && d.id )
		.map( ( d ) => ( { value: d.id, label: d.title || d.id } ) );
	if ( datasetId && ! options.some( ( o ) => o.value === datasetId ) ) {
		options.unshift( { value: datasetId, label: datasetId } );
	}
	const selectedDataset =
		datasets.find( ( d ) => d && d.id === datasetId ) || null;

	const canSubmit =
		datasetId.trim() && start && end && ! saving && ! clearing;

	const submit = () => {
		setSaving( true );
		setErrors( [] );
		setNotice( null );

		// The PUT is a full upsert, so send `headline` explicitly every time:
		// the trimmed text, or null to clear it while keeping the same
		// dataset/window (the proxy allowlists null-clears).
		const body = {
			dataset_id: datasetId.trim(),
			window: { start: localToIso( start ), end: localToIso( end ) },
			headline: headline.trim() || null,
		};

		setFeaturedHero( body )
			.then( ( res ) => {
				const pin = ( res && res.hero ) || null;
				setHero( pin );
				seedForm( pin );
				setNotice( {
					type: 'success',
					text: __( 'The “Right now” hero is set.', 'terraviz' ),
				} );
				setSaving( false );
			} )
			.catch( ( e ) => {
				const n = normalizeError( e );
				setErrors( n.errors );
				setNotice( {
					type: 'error',
					text:
						n.message ||
						__(
							'Could not set the hero. Check the highlighted fields.',
							'terraviz'
						),
				} );
				setSaving( false );
			} );
	};

	const clear = () => {
		const confirmed =
			// eslint-disable-next-line no-alert -- a native confirm is acceptable for a destructive wp-admin action.
			window.confirm(
				__( 'Clear the current “Right now” hero pin?', 'terraviz' )
			);
		if ( ! confirmed ) {
			return;
		}
		setClearing( true );
		setErrors( [] );
		setNotice( null );

		clearFeaturedHero()
			.then( () => {
				setHero( null );
				seedForm( null );
				setNotice( {
					type: 'success',
					text: __( 'The hero pin was cleared.', 'terraviz' ),
				} );
				setClearing( false );
			} )
			.catch( ( e ) => {
				setNotice( {
					type: 'error',
					text:
						normalizeError( e ).message ||
						__( 'Could not clear the hero.', 'terraviz' ),
				} );
				setClearing( false );
			} );
	};

	if ( loading ) {
		return <Spinner />;
	}

	return (
		<div style={ { maxWidth: '720px' } }>
			<p style={ { color: '#50575e', marginTop: 0 } }>
				{ __(
					'Pin one dataset to the Terraviz homepage for a set window, with an optional headline. There is a single operator-wide hero.',
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

			<div
				style={ {
					background: hero ? '#f0f6fc' : '#f6f7f7',
					border: '1px solid #dcdcde',
					borderRadius: '4px',
					padding: '12px 16px',
					margin: '12px 0',
				} }
			>
				{ hero ? (
					<>
						<strong>
							{ __( 'Currently pinned', 'terraviz' ) }
						</strong>
						<p style={ { margin: '4px 0 0' } }>
							{ hero.headline ? `“${ hero.headline }” — ` : '' }
							<code>{ hero.datasetId }</code>
						</p>
						{ hero.window && (
							<p
								style={ {
									margin: '4px 0 0',
									color: '#50575e',
									fontSize: '13px',
								} }
							>
								{ sprintf(
									/* translators: 1: window start, 2: window end. */
									__( '%1$s → %2$s', 'terraviz' ),
									hero.window.start,
									hero.window.end
								) }
							</p>
						) }
					</>
				) : (
					<span>
						{ __(
							'No hero is pinned right now — the homepage falls back to its auto-derived “Right now”.',
							'terraviz'
						) }
					</span>
				) }
			</div>

			<h3>
				{ hero
					? __( 'Update the hero', 'terraviz' )
					: __( 'Set the hero', 'terraviz' ) }
			</h3>

			{ datasetsError && (
				<Notice status="warning" isDismissible={ false }>
					{ sprintf(
						/* translators: %s: error message. */
						__(
							'Could not load your datasets for the picker (%s). You can still type a dataset id below.',
							'terraviz'
						),
						datasetsError
					) }
				</Notice>
			) }

			{ options.length > 0 ? (
				<ComboboxControl
					label={ __( 'Dataset', 'terraviz' ) }
					value={ datasetId }
					options={ options }
					onChange={ ( value ) => setDatasetId( value || '' ) }
					help={
						fieldError( 'dataset_id' ) ||
						__(
							'The dataset to feature on the homepage.',
							'terraviz'
						)
					}
					__nextHasNoMarginBottom
				/>
			) : (
				<TextControl
					label={ __( 'Dataset id', 'terraviz' ) }
					value={ datasetId }
					onChange={ setDatasetId }
					help={ fieldError( 'dataset_id' ) }
					__nextHasNoMarginBottom
				/>
			) }

			<DatasetPreview
				dataset={ selectedDataset }
				origin={ boot.origin }
			/>

			<div style={ { display: 'flex', gap: '8px', marginTop: '12px' } }>
				<TextControl
					label={ __( 'Window start', 'terraviz' ) }
					type="datetime-local"
					value={ start }
					onChange={ setStart }
					help={
						fieldError( 'window.start' ) || fieldError( 'window' )
					}
					__nextHasNoMarginBottom
				/>
				<TextControl
					label={ __( 'Window end', 'terraviz' ) }
					type="datetime-local"
					value={ end }
					onChange={ setEnd }
					help={ fieldError( 'window.end' ) }
					__nextHasNoMarginBottom
				/>
			</div>

			<div style={ { marginTop: '12px' } }>
				<TextControl
					label={ __( 'Headline (optional)', 'terraviz' ) }
					value={ headline }
					onChange={ setHeadline }
					maxLength={ HEADLINE_MAX }
					help={
						fieldError( 'headline' ) ||
						sprintf(
							/* translators: 1: current length, 2: max length. */
							__( '%1$d / %2$d characters', 'terraviz' ),
							headline.length,
							HEADLINE_MAX
						)
					}
					__nextHasNoMarginBottom
				/>
			</div>

			<div style={ { display: 'flex', gap: '8px', marginTop: '16px' } }>
				<Button
					variant="primary"
					onClick={ submit }
					isBusy={ saving }
					disabled={ ! canSubmit }
				>
					{ hero
						? __( 'Update hero', 'terraviz' )
						: __( 'Set hero', 'terraviz' ) }
				</Button>
				{ hero && (
					<Button
						variant="secondary"
						isDestructive
						onClick={ clear }
						isBusy={ clearing }
						disabled={ saving || clearing }
					>
						{ __( 'Clear hero', 'terraviz' ) }
					</Button>
				) }
			</div>
		</div>
	);
}
