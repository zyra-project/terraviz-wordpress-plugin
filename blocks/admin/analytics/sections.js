/**
 * The Analytics sections — one component per sub-tab, each drawn from the shared
 * primitives. Every section fetches its own slice of the node's typed rollup
 * endpoint (`?section=…`) via `useSection`, so switching tabs loads only what's
 * shown. The node owns the numbers; these views just choose the form.
 */
import { __ } from '@wordpress/i18n';
import { useState, useEffect } from '@wordpress/element';
import { Button, SelectControl } from '@wordpress/components';
import {
	ACCENT,
	MUTED,
	StatTile,
	Section,
	BarList,
	Sparkline,
	Table,
	MixText,
	useSection,
	SectionState,
	num,
	ms,
	pct,
	duration,
} from './primitives';

const tileRow = { display: 'flex', gap: '16px', flexWrap: 'wrap' };

/**
 * Map a `Record<string,number>` to `BarList` rows.
 *
 * @param {Object<string, number>} rec       The record.
 * @param {string}                 [unknown] Label for an empty key.
 * @return {Array<{label: string, value: number}>} Rows.
 */
function recRows( rec, unknown = __( 'Unknown', 'terraviz' ) ) {
	return Object.entries( rec || {} ).map( ( [ label, value ] ) => ( {
		label: label || unknown,
		value: Number( value ) || 0,
	} ) );
}

/* ---------------------------------------------------------------- Overview */

export function OverviewSection( { days, environment } ) {
	const [ showErrors, setShowErrors ] = useState( false );
	const { data, loading, error } = useSection( 'overview', {
		days,
		environment,
	} );

	return (
		<SectionState data={ data } loading={ loading } error={ error }>
			{ ( d ) => {
				const totals = d.totals || {};
				const errorsPerSession =
					totals.sessions > 0 ? totals.errors / totals.sessions : 0;
				const trend = ( d.days || [] ).map( ( r ) => ( {
					label: r.day,
					value: Number( r.sessions ) || 0,
				} ) );
				return (
					<div>
						<Section title={ __( 'At a glance', 'terraviz' ) }>
							<div style={ tileRow }>
								<StatTile
									label={ __( 'Sessions', 'terraviz' ) }
									value={ num( totals.sessions ) }
								/>
								<StatTile
									label={ __( 'View time', 'terraviz' ) }
									value={ duration( totals.view_ms ) }
								/>
								<StatTile
									label={ __( 'Events', 'terraviz' ) }
									value={ num( totals.events ) }
								/>
								<StatTile
									label={ __( 'Errors', 'terraviz' ) }
									value={ num( totals.errors ) }
									sub={
										<Button
											variant="link"
											style={ { padding: 0 } }
											onClick={ () =>
												setShowErrors( ( v ) => ! v )
											}
										>
											{ showErrors
												? __(
														'Hide breakdown',
														'terraviz'
												  )
												: __(
														'View breakdown',
														'terraviz'
												  ) }
										</Button>
									}
								/>
								<StatTile
									label={ __(
										'Errors per session',
										'terraviz'
									) }
									value={ pct( errorsPerSession ) }
								/>
							</div>
						</Section>

						{ showErrors && (
							<Section
								title={ __( 'Error breakdown', 'terraviz' ) }
							>
								<ErrorBreakdown
									days={ days }
									environment={ environment }
								/>
							</Section>
						) }

						<Section title={ __( 'Daily sessions', 'terraviz' ) }>
							<Sparkline
								points={ trend }
								ariaLabel={ __(
									'Daily sessions over the selected range.',
									'terraviz'
								) }
							/>
						</Section>

						<div
							style={ {
								display: 'flex',
								gap: '32px',
								flexWrap: 'wrap',
							} }
						>
							<div style={ { flex: '1 1 280px', minWidth: 0 } }>
								<Section
									title={ __( 'Platforms', 'terraviz' ) }
								>
									<BarList
										rows={ recRows( d.platforms ) }
										empty={ __(
											'No platform data for this range.',
											'terraviz'
										) }
									/>
								</Section>
							</div>
							<div style={ { flex: '1 1 280px', minWidth: 0 } }>
								<Section
									title={ __(
										'Operating systems',
										'terraviz'
									) }
								>
									<BarList
										rows={ recRows( d.operatingSystems ) }
										empty={ __(
											'No operating-system data for this range.',
											'terraviz'
										) }
									/>
								</Section>
							</div>
						</div>

						<Section title={ __( 'Top countries', 'terraviz' ) }>
							<BarList
								rows={ ( d.countries || [] ).map( ( c ) => ( {
									label:
										c.country ||
										__( 'Unknown', 'terraviz' ),
									value: c.sessions,
								} ) ) }
								empty={ __(
									'No country data for this range.',
									'terraviz'
								) }
							/>
						</Section>
					</div>
				);
			} }
		</SectionState>
	);
}

// The error breakdown table — the drill-down behind Overview's Errors tile.
function ErrorBreakdown( { days, environment } ) {
	const { data, loading, error } = useSection( 'errors', {
		days,
		environment,
	} );
	return (
		<SectionState data={ data } loading={ loading } error={ error }>
			{ ( d ) => (
				<Table
					columns={ [
						{
							key: 'category',
							label: __( 'Category', 'terraviz' ),
						},
						{ key: 'source', label: __( 'Source', 'terraviz' ) },
						{ key: 'code', label: __( 'Code', 'terraviz' ) },
						{
							key: 'message_class',
							label: __( 'Message', 'terraviz' ),
						},
						{
							key: 'count',
							label: __( 'Count', 'terraviz' ),
							align: 'right',
							render: ( r ) => num( r.count ),
						},
					] }
					rows={ d.errors || [] }
					empty={ __(
						'No errors recorded in this range.',
						'terraviz'
					) }
				/>
			) }
		</SectionState>
	);
}

/* ---------------------------------------------------------------- Datasets */

export function DatasetsSection( { days, environment } ) {
	const { data, loading, error } = useSection( 'datasets', {
		days,
		environment,
	} );
	return (
		<SectionState data={ data } loading={ loading } error={ error }>
			{ ( d ) => {
				const rows = ( d.datasets || [] ).map( ( r ) => ( {
					...r,
					key: r.layer_id,
					name: r.title || r.layer_id,
				} ) );
				return (
					<div>
						<Section
							title={ __( 'Most-loaded datasets', 'terraviz' ) }
						>
							<BarList
								rows={ rows.map( ( r ) => ( {
									label: r.name,
									value: r.loads,
								} ) ) }
								empty={ __(
									'No dataset loads in this range.',
									'terraviz'
								) }
							/>
						</Section>
						<Section
							title={ __( 'Engagement detail', 'terraviz' ) }
						>
							<Table
								columns={ [
									{
										key: 'name',
										label: __( 'Dataset', 'terraviz' ),
									},
									{
										key: 'loads',
										label: __( 'Loads', 'terraviz' ),
										align: 'right',
										render: ( r ) => num( r.loads ),
									},
									{
										key: 'trigger',
										label: __( 'Trigger', 'terraviz' ),
										render: ( r ) => (
											<MixText mix={ r.trigger_mix } />
										),
									},
									{
										key: 'source',
										label: __( 'Source', 'terraviz' ),
										render: ( r ) => (
											<MixText mix={ r.source_mix } />
										),
									},
									{
										key: 'p50',
										label: __( 'Load p50', 'terraviz' ),
										align: 'right',
										render: ( r ) => ms( r.load_ms_p50 ),
									},
									{
										key: 'p95',
										label: __( 'Load p95', 'terraviz' ),
										align: 'right',
										render: ( r ) => ms( r.load_ms_p95 ),
									},
									{
										key: 'dwell',
										label: __( 'Dwell', 'terraviz' ),
										align: 'right',
										render: ( r ) =>
											duration( r.dwell_ms_sum ),
									},
								] }
								rows={ rows }
								empty={ __(
									'No dataset engagement in this range.',
									'terraviz'
								) }
							/>
						</Section>
					</div>
				);
			} }
		</SectionState>
	);
}

/* ----------------------------------------------------------------- Spatial */

const SPATIAL_EVENTS = [
	{ label: __( 'Camera settled', 'terraviz' ), value: 'camera_settled' },
	{ label: __( 'Map clicks', 'terraviz' ), value: 'map_click' },
];
const PROJECTIONS = [
	{ label: __( 'All projections', 'terraviz' ), value: '' },
	{ label: __( 'Globe', 'terraviz' ), value: 'globe' },
	{ label: __( 'Mercator', 'terraviz' ), value: 'mercator' },
	{ label: __( 'VR', 'terraviz' ), value: 'vr' },
	{ label: __( 'AR', 'terraviz' ), value: 'ar' },
];
const MAX_BINS_DRAWN = 8000;

export function SpatialSection( { days, environment } ) {
	const [ event, setEvent ] = useState( 'camera_settled' );
	const [ projection, setProjection ] = useState( '' );
	const [ layer, setLayer ] = useState( '__all' );

	// Only forward the spatial refinements the node accepts; '' / '__all' mean
	// "omit" (all), which the PHP proxy achieves by simply not sending the key.
	const query = { days, environment, event };
	if ( projection ) {
		query.projection = projection;
	}
	if ( layer !== '__all' ) {
		query.layer = layer;
	}
	const { data, loading, error } = useSection( 'spatial', query );

	// The layer list only comes back with the data, but the filter controls sit
	// outside the load gate so they don't flash while a filter change refetches.
	// Remember the last non-empty list so the dropdown survives the spinner.
	const layersFromData = ( data && data.data && data.data.layers ) || null;
	const [ knownLayers, setKnownLayers ] = useState( [] );
	useEffect( () => {
		if ( layersFromData && layersFromData.length ) {
			setKnownLayers( layersFromData );
		}
	}, [ layersFromData ] );
	const layerOptions = [
		{ label: __( 'All layers', 'terraviz' ), value: '__all' },
		...knownLayers
			.filter( ( l ) => l.id )
			.map( ( l ) => ( { label: l.title || l.id, value: l.id } ) ),
	];

	return (
		<div>
			<div
				style={ {
					display: 'flex',
					gap: '12px',
					flexWrap: 'wrap',
					alignItems: 'flex-end',
				} }
			>
				<SelectControl
					label={ __( 'Interaction', 'terraviz' ) }
					value={ event }
					options={ SPATIAL_EVENTS }
					onChange={ setEvent }
					__nextHasNoMarginBottom
				/>
				<SelectControl
					label={ __( 'Projection', 'terraviz' ) }
					value={ projection }
					options={ PROJECTIONS }
					onChange={ setProjection }
					__nextHasNoMarginBottom
				/>
				<SelectControl
					label={ __( 'Dataset layer', 'terraviz' ) }
					value={ layer }
					options={ layerOptions }
					onChange={ setLayer }
					__nextHasNoMarginBottom
				/>
			</div>

			<SectionState data={ data } loading={ loading } error={ error }>
				{ ( d ) => {
					const bins = d.bins || [];
					const drawn = bins.slice( 0, MAX_BINS_DRAWN );
					// The node already returns bins hits-descending, but sort the
					// capped set defensively — it's ≤ MAX_BINS_DRAWN, not the full
					// payload, and matches the "densest cells" copy below.
					const hotspots = [ ...drawn ]
						.sort( ( a, b ) => b.hits - a.hits )
						.slice( 0, 12 );
					return (
						<div>
							<Section
								title={ __(
									'Where people looked',
									'terraviz'
								) }
							>
								<DensityMap bins={ drawn } />
								{ bins.length > MAX_BINS_DRAWN && (
									<p
										style={ {
											color: MUTED,
											fontSize: '12px',
											margin: '6px 0 0',
										} }
									>
										{ __(
											'Showing the densest 8,000 cells.',
											'terraviz'
										) }
									</p>
								) }
							</Section>

							{ event === 'map_click' && (
								<Section
									title={ __( 'Click targets', 'terraviz' ) }
								>
									<BarList
										rows={ recRows( d.hitKinds ) }
										empty={ __(
											'No click data for this range.',
											'terraviz'
										) }
									/>
								</Section>
							) }

							<Section title={ __( 'Top hotspots', 'terraviz' ) }>
								<Table
									columns={ [
										{
											key: 'lat',
											label: __( 'Lat', 'terraviz' ),
											align: 'right',
											render: ( r ) => r.lat.toFixed( 1 ),
										},
										{
											key: 'lon',
											label: __( 'Lon', 'terraviz' ),
											align: 'right',
											render: ( r ) => r.lon.toFixed( 1 ),
										},
										{
											key: 'hits',
											label: __( 'Hits', 'terraviz' ),
											align: 'right',
											render: ( r ) => num( r.hits ),
										},
									] }
									rows={ hotspots.map( ( h, i ) => ( {
										...h,
										key: `${ h.lat }:${ h.lon }:${ i }`,
									} ) ) }
									empty={ __(
										'No location data for this range.',
										'terraviz'
									) }
								/>
							</Section>
						</div>
					);
				} }
			</SectionState>
		</div>
	);
}

/**
 * A CSP-safe equirectangular density plot: each rollup cell is a dot whose
 * opacity tracks its share of the busiest cell. No basemap — continent shapes
 * emerge from the data where there's enough of it; a 30° graticule gives the eye
 * a frame.
 *
 * @param {Object}                                          props
 * @param {Array<{lat: number, lon: number, hits: number}>} props.bins Cells.
 * @return {JSX.Element} The plot.
 */
function DensityMap( { bins } ) {
	const max = bins.reduce( ( m, b ) => Math.max( m, b.hits ), 0 );
	const grat = [];
	for ( let lon = -150; lon <= 150; lon += 30 ) {
		grat.push(
			<line
				key={ `v${ lon }` }
				x1={ lon + 180 }
				y1="0"
				x2={ lon + 180 }
				y2="180"
				stroke="#e0e0e2"
				strokeWidth="0.4"
			/>
		);
	}
	for ( let lat = -60; lat <= 60; lat += 30 ) {
		grat.push(
			<line
				key={ `h${ lat }` }
				x1="0"
				y1={ 90 - lat }
				x2="360"
				y2={ 90 - lat }
				stroke="#e0e0e2"
				strokeWidth="0.4"
			/>
		);
	}
	return (
		<div
			style={ {
				border: '1px solid #dcdcde',
				borderRadius: '4px',
				background: '#fbfbfc',
				overflow: 'hidden',
			} }
		>
			<svg
				viewBox="0 0 360 180"
				width="100%"
				role="img"
				aria-label={ __(
					'Geographic density of interactions on an equirectangular grid.',
					'terraviz'
				) }
				style={ { display: 'block' } }
			>
				{ grat }
				<line
					x1="0"
					y1="90"
					x2="360"
					y2="90"
					stroke="#c3c4c7"
					strokeWidth="0.5"
				/>
				{ bins.map( ( b, i ) => (
					<circle
						key={ `${ b.lat }:${ b.lon }:${ i }` }
						cx={ b.lon + 180 }
						cy={ 90 - b.lat }
						r="1.1"
						fill={ ACCENT }
						fillOpacity={
							max > 0
								? Math.max( 0.15, ( b.hits / max ) * 0.9 )
								: 0.15
						}
					/>
				) ) }
			</svg>
		</div>
	);
}

/* -------------------------------------------------------------- Engagement */

export function EngagementSection( { days, environment } ) {
	const { data, loading, error } = useSection( 'funnel', {
		days,
		environment,
	} );
	return (
		<SectionState data={ data } loading={ loading } error={ error }>
			{ ( d ) => {
				const dayRows = d.days || [];
				const sum = ( k ) =>
					dayRows.reduce(
						( n, r ) => n + ( Number( r[ k ] ) || 0 ),
						0
					);
				const outcomes = d.outcomes || {};
				return (
					<div>
						<Section title={ __( 'At a glance', 'terraviz' ) }>
							<div style={ tileRow }>
								<StatTile
									label={ __( 'Tours started', 'terraviz' ) }
									value={ num( sum( 'tours_started' ) ) }
								/>
								<StatTile
									label={ __( 'Tours ended', 'terraviz' ) }
									value={ num( sum( 'tours_ended' ) ) }
								/>
								<StatTile
									label={ __( 'VR sessions', 'terraviz' ) }
									value={ num( sum( 'vr_started' ) ) }
								/>
								<StatTile
									label={ __( 'Orbit turns', 'terraviz' ) }
									value={ num( sum( 'orbit_turns' ) ) }
								/>
							</div>
						</Section>

						<Section
							title={ __( 'Tours started daily', 'terraviz' ) }
						>
							<Sparkline
								points={ dayRows.map( ( r ) => ( {
									label: r.day,
									value: Number( r.tours_started ) || 0,
								} ) ) }
								ariaLabel={ __(
									'Tours started per day.',
									'terraviz'
								) }
							/>
						</Section>

						<div
							style={ {
								display: 'flex',
								gap: '32px',
								flexWrap: 'wrap',
							} }
						>
							<div style={ { flex: '1 1 240px', minWidth: 0 } }>
								<Section
									title={ __( 'Tour outcomes', 'terraviz' ) }
								>
									<BarList
										rows={ recRows( outcomes.tour_ended ) }
										empty={ __(
											'No completed tours in this range.',
											'terraviz'
										) }
									/>
								</Section>
							</div>
							<div style={ { flex: '1 1 240px', minWidth: 0 } }>
								<Section
									title={ __( 'Tour sources', 'terraviz' ) }
								>
									<BarList
										rows={ recRows(
											d.toursStartedBySource
										) }
										empty={ __(
											'No tour starts in this range.',
											'terraviz'
										) }
									/>
								</Section>
							</div>
							<div style={ { flex: '1 1 240px', minWidth: 0 } }>
								<Section title={ __( 'XR modes', 'terraviz' ) }>
									<BarList
										rows={ recRows(
											outcomes.vr_session_started
										) }
										empty={ __(
											'No XR sessions in this range.',
											'terraviz'
										) }
									/>
								</Section>
							</div>
						</div>
					</div>
				);
			} }
		</SectionState>
	);
}

/* ------------------------------------------------------------- Performance */

export function PerformanceSection( { days, environment } ) {
	const { data, loading, error } = useSection( 'perf', {
		days,
		environment,
	} );
	return (
		<SectionState data={ data } loading={ loading } error={ error }>
			{ ( d ) => (
				<Section title={ __( 'Render performance', 'terraviz' ) }>
					<Table
						columns={ [
							{
								key: 'surface',
								label: __( 'Surface', 'terraviz' ),
							},
							{
								key: 'renderer',
								label: __( 'Renderer', 'terraviz' ),
							},
							{
								key: 'samples',
								label: __( 'Samples', 'terraviz' ),
								align: 'right',
								render: ( r ) => num( r.samples ),
							},
							{
								key: 'fps',
								label: __( 'Avg FPS', 'terraviz' ),
								align: 'right',
								render: ( r ) => r.avg_fps.toFixed( 1 ),
							},
							{
								key: 'frame',
								label: __( 'Frame p95', 'terraviz' ),
								align: 'right',
								render: ( r ) => ms( r.avg_frame_p95_ms ),
							},
							{
								key: 'heap',
								label: __( 'JS heap', 'terraviz' ),
								align: 'right',
								render: ( r ) =>
									Number.isFinite( r.avg_jsheap_mb )
										? `${ r.avg_jsheap_mb.toFixed( 0 ) } MB`
										: '—',
							},
						] }
						rows={ ( d.rows || [] ).map( ( r, i ) => ( {
							...r,
							key: `${ r.surface }:${ r.renderer }:${ i }`,
						} ) ) }
						empty={ __(
							'No performance samples in this range.',
							'terraviz'
						) }
					/>
				</Section>
			) }
		</SectionState>
	);
}

/* ------------------------------------------------------------------- Orbit */

export function OrbitSection( { days, environment } ) {
	const { data, loading, error } = useSection( 'orbit', {
		days,
		environment,
	} );
	return (
		<SectionState data={ data } loading={ loading } error={ error }>
			{ ( d ) => {
				const totals = d.totals || {};
				return (
					<div>
						<Section title={ __( 'At a glance', 'terraviz' ) }>
							<div style={ tileRow }>
								<StatTile
									label={ __( 'Turns', 'terraviz' ) }
									value={ num( totals.turns ) }
								/>
								<StatTile
									label={ __( 'Rounds', 'terraviz' ) }
									value={ num( totals.rounds ) }
								/>
								<StatTile
									label={ __( 'Input tokens', 'terraviz' ) }
									value={ num( totals.input_tokens ) }
								/>
								<StatTile
									label={ __( 'Output tokens', 'terraviz' ) }
									value={ num( totals.output_tokens ) }
								/>
							</div>
						</Section>

						<Section title={ __( 'Rounds daily', 'terraviz' ) }>
							<Sparkline
								points={ ( d.days || [] ).map( ( r ) => ( {
									label: r.day,
									value: Number( r.rounds ) || 0,
								} ) ) }
								ariaLabel={ __(
									'Orbit rounds per day.',
									'terraviz'
								) }
							/>
						</Section>

						<Section title={ __( 'By model', 'terraviz' ) }>
							<Table
								columns={ [
									{
										key: 'model',
										label: __( 'Model', 'terraviz' ),
									},
									{
										key: 'turns',
										label: __( 'Turns', 'terraviz' ),
										align: 'right',
										render: ( r ) => num( r.turns ),
									},
									{
										key: 'rounds',
										label: __( 'Rounds', 'terraviz' ),
										align: 'right',
										render: ( r ) => num( r.rounds ),
									},
									{
										key: 'input_tokens',
										label: __( 'In', 'terraviz' ),
										align: 'right',
										render: ( r ) => num( r.input_tokens ),
									},
									{
										key: 'output_tokens',
										label: __( 'Out', 'terraviz' ),
										align: 'right',
										render: ( r ) => num( r.output_tokens ),
									},
								] }
								rows={ ( d.models || [] ).map( ( r ) => ( {
									...r,
									key: r.model,
								} ) ) }
								empty={ __(
									'No Orbit activity in this range.',
									'terraviz'
								) }
							/>
						</Section>
					</div>
				);
			} }
		</SectionState>
	);
}

/* ---------------------------------------------------------------- Research */

export function ResearchSection( { days, environment } ) {
	const { data, loading, error } = useSection( 'research', {
		days,
		environment,
	} );
	return (
		<SectionState data={ data } loading={ loading } error={ error }>
			{ ( d ) => (
				<div>
					<Section title={ __( 'Top searches', 'terraviz' ) }>
						<Table
							columns={ [
								{
									key: 'key',
									label: __( 'Query', 'terraviz' ),
								},
								{
									key: 'count',
									label: __( 'Count', 'terraviz' ),
									align: 'right',
									render: ( r ) => num( r.count ),
								},
								{
									key: 'avg_length',
									label: __( 'Avg length', 'terraviz' ),
									align: 'right',
									render: ( r ) => r.avg_length.toFixed( 1 ),
								},
							] }
							rows={ ( d.topSearches || [] ).map( ( r, i ) => ( {
								...r,
								key: `${ r.key }:${ i }`,
							} ) ) }
							empty={ __(
								'No searches in this range.',
								'terraviz'
							) }
						/>
					</Section>

					<Section
						title={ __( 'Searches with no results', 'terraviz' ) }
					>
						<BarList
							rows={ ( d.zeroSearches || [] ).map( ( r ) => ( {
								label: r.key,
								value: r.count,
							} ) ) }
							empty={ __(
								'No zero-result searches in this range.',
								'terraviz'
							) }
						/>
					</Section>

					<Section title={ __( 'Worst quiz questions', 'terraviz' ) }>
						<Table
							columns={ [
								{
									key: 'tour_id',
									label: __( 'Tour', 'terraviz' ),
								},
								{
									key: 'question_id',
									label: __( 'Question', 'terraviz' ),
								},
								{
									key: 'answered',
									label: __( 'Answered', 'terraviz' ),
									align: 'right',
									render: ( r ) => num( r.answered ),
								},
								{
									key: 'correct_rate',
									label: __( 'Correct', 'terraviz' ),
									align: 'right',
									render: ( r ) => pct( r.correct_rate ),
								},
							] }
							rows={ ( d.worstQuestions || [] ).map(
								( r, i ) => ( {
									...r,
									key: `${ r.tour_id }:${ r.question_id }:${ i }`,
								} )
							) }
							empty={ __(
								'No quiz activity in this range.',
								'terraviz'
							) }
						/>
					</Section>

					<Section title={ __( 'Longest dwell', 'terraviz' ) }>
						<Table
							columns={ [
								{
									key: 'key',
									label: __( 'Target', 'terraviz' ),
								},
								{
									key: 'count',
									label: __( 'Count', 'terraviz' ),
									align: 'right',
									render: ( r ) => num( r.count ),
								},
								{
									key: 'avg_ms',
									label: __( 'Avg dwell', 'terraviz' ),
									align: 'right',
									render: ( r ) => ms( r.avg_ms ),
								},
							] }
							rows={ ( d.dwell || [] ).map( ( r, i ) => ( {
								...r,
								key: `${ r.key }:${ i }`,
							} ) ) }
							empty={ __(
								'No dwell data in this range.',
								'terraviz'
							) }
						/>
					</Section>
				</div>
			) }
		</SectionState>
	);
}
