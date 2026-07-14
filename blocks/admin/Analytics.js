/**
 * Analytics — the read-only Insights overview (Milestone C, first slice).
 *
 * A thin facade over the node's typed analytics endpoint
 * (`GET /publish/analytics?section=overview&…`): the node owns the rollups and
 * validates every parameter; this view only picks a date range + environment and
 * draws what comes back. It carries no new write path.
 *
 * The Overview section returns `{ totals, days[], platforms, operatingSystems,
 * countries }`. Following the plugin's charting conventions: stat tiles for the
 * headline totals, single-hue horizontal bars for the platform / OS / country
 * magnitude breakdowns (one measure, one hue — no categorical palette), and a
 * sparkline for the daily sessions trend. Deeper sections (datasets, spatial,
 * funnel, errors, perf) are on the roadmap and not built here.
 */
import { __, sprintf } from '@wordpress/i18n';
import { useState, useEffect, useMemo, useCallback } from '@wordpress/element';
import { SelectControl, Spinner, Notice } from '@wordpress/components';
import { getAnalytics, normalizeError } from './api';

// The single accent hue for every mark on this screen — one measure, one hue, so
// there is no categorical palette to validate (see the dataviz conventions). It
// matches the wp-admin primary accent the sidebar already uses.
const ACCENT = '#2271b1';
const INK = '#1d2327';
const MUTED = '#646970';

const RANGES = [
	{ label: __( 'Last 7 days', 'terraviz' ), value: '7' },
	{ label: __( 'Last 30 days', 'terraviz' ), value: '30' },
	{ label: __( 'Last 90 days', 'terraviz' ), value: '90' },
	{ label: __( 'Last year', 'terraviz' ), value: '365' },
];

const ENVIRONMENTS = [
	{ label: __( 'Production', 'terraviz' ), value: 'production' },
	{ label: __( 'Preview', 'terraviz' ), value: 'preview' },
];

const cardStyle = {
	flex: '1 1 200px',
	border: '1px solid #dcdcde',
	borderRadius: '4px',
	padding: '16px',
	background: '#fff',
};

/**
 * Format an integer with thousands separators grouped for the viewer's locale
 * (`toLocaleString()` — fine here, these are internal dashboard figures).
 *
 * @param {number} n Value.
 * @return {string} Grouped digits, or '0'.
 */
function num( n ) {
	const v = Number( n );
	if ( ! Number.isFinite( v ) ) {
		return '0';
	}
	return Math.round( v ).toLocaleString();
}

/**
 * Render a duration in milliseconds as a compact `1h 2m` / `2m 3s` / `4s`.
 *
 * @param {number} ms Milliseconds.
 * @return {string} Human duration.
 */
function duration( ms ) {
	const total = Math.max( 0, Math.round( Number( ms ) || 0 ) );
	const s = Math.floor( total / 1000 );
	if ( s >= 3600 ) {
		return sprintf(
			/* translators: 1: hours, 2: minutes. */
			__( '%1$dh %2$dm', 'terraviz' ),
			Math.floor( s / 3600 ),
			Math.floor( ( s % 3600 ) / 60 )
		);
	}
	if ( s >= 60 ) {
		return sprintf(
			/* translators: 1: minutes, 2: seconds. */
			__( '%1$dm %2$ds', 'terraviz' ),
			Math.floor( s / 60 ),
			s % 60
		);
	}
	return sprintf(
		/* translators: %d: seconds. */
		__( '%ds', 'terraviz' ),
		s
	);
}

function StatTile( { label, value } ) {
	return (
		<div style={ cardStyle }>
			<div
				style={ {
					fontSize: '12px',
					textTransform: 'uppercase',
					letterSpacing: '0.04em',
					color: MUTED,
				} }
			>
				{ label }
			</div>
			<div
				style={ {
					fontSize: '28px',
					fontWeight: 700,
					lineHeight: 1.2,
					marginTop: '4px',
				} }
			>
				{ value }
			</div>
		</div>
	);
}

function Section( { title, children } ) {
	return (
		<section style={ { marginTop: '28px' } }>
			<h3
				style={ {
					fontSize: '13px',
					textTransform: 'uppercase',
					letterSpacing: '0.04em',
					color: MUTED,
					margin: '0 0 10px',
				} }
			>
				{ title }
			</h3>
			{ children }
		</section>
	);
}

/**
 * A single-hue horizontal-bar readout of a magnitude-by-identity breakdown. Each
 * row is direct-labelled with its name and value, so the chart doubles as its own
 * table (no legend, no color-only encoding). Bars are baseline-anchored and
 * sorted largest-first; the widest fills the track.
 *
 * @param {Object}                                  props
 * @param {Array<{ label: string, value: number }>} props.rows  Pre-sorted or unsorted rows.
 * @param {string}                                  props.empty Empty-state text.
 * @return {JSX.Element} The bar list.
 */
function BarList( { rows, empty } ) {
	const sorted = [ ...rows ].sort( ( a, b ) => b.value - a.value );
	const max = sorted.reduce( ( m, r ) => Math.max( m, r.value ), 0 );
	if ( sorted.length === 0 || max <= 0 ) {
		return <p style={ { color: MUTED, margin: 0 } }>{ empty }</p>;
	}
	return (
		<div style={ { display: 'grid', gap: '8px' } }>
			{ sorted.map( ( r ) => {
				const pct = Math.max(
					2,
					Math.round( ( r.value / max ) * 100 )
				);
				return (
					<div
						key={ r.label }
						style={ {
							display: 'grid',
							gridTemplateColumns: '120px 1fr auto',
							alignItems: 'center',
							gap: '10px',
						} }
					>
						<span
							style={ {
								fontSize: '13px',
								color: INK,
								overflow: 'hidden',
								textOverflow: 'ellipsis',
								whiteSpace: 'nowrap',
							} }
							title={ r.label }
						>
							{ r.label }
						</span>
						<span
							aria-hidden="true"
							style={ {
								display: 'block',
								height: '14px',
								background: '#f0f0f1',
								borderRadius: '4px',
							} }
						>
							<span
								style={ {
									display: 'block',
									width: `${ pct }%`,
									height: '100%',
									background: ACCENT,
									borderRadius: '4px',
								} }
							/>
						</span>
						<span
							style={ {
								fontSize: '13px',
								fontWeight: 600,
								color: INK,
								fontVariantNumeric: 'tabular-nums',
							} }
						>
							{ num( r.value ) }
						</span>
					</div>
				);
			} ) }
		</div>
	);
}

/**
 * A sparkline of the daily sessions trend with a hover crosshair + tooltip. One
 * series, so the section title names it and no legend is needed. Degrades to a
 * short note when there are fewer than two days to connect.
 *
 * @param {Object}                                   props
 * @param {Array<{ day: string, sessions: number }>} props.days Daily rows, oldest-first.
 * @return {JSX.Element} The sparkline.
 */
function Sparkline( { days } ) {
	const [ hover, setHover ] = useState( null );
	const W = 640;
	const H = 120;
	const PAD = 8;

	const points = useMemo( () => {
		const max = days.reduce( ( m, d ) => Math.max( m, d.sessions ), 0 );
		const span = Math.max( 1, days.length - 1 );
		return days.map( ( d, i ) => ( {
			x: PAD + ( i / span ) * ( W - PAD * 2 ),
			y:
				H -
				PAD -
				( max > 0 ? ( d.sessions / max ) * ( H - PAD * 2 ) : 0 ),
			day: d.day,
			sessions: d.sessions,
		} ) );
	}, [ days ] );

	const onMove = useCallback(
		( e ) => {
			const rect = e.currentTarget.getBoundingClientRect();
			// Map the pointer to the SVG's user space (it scales to fit the box).
			const x = ( ( e.clientX - rect.left ) / rect.width ) * W;
			let nearest = 0;
			let best = Infinity;
			points.forEach( ( p, i ) => {
				const dist = Math.abs( p.x - x );
				if ( dist < best ) {
					best = dist;
					nearest = i;
				}
			} );
			setHover( nearest );
		},
		[ points ]
	);

	if ( days.length < 2 ) {
		return (
			<p style={ { color: MUTED, margin: 0 } }>
				{ __(
					'Not enough days in this range to plot a trend yet.',
					'terraviz'
				) }
			</p>
		);
	}

	const line = points.map( ( p ) => `${ p.x },${ p.y }` ).join( ' ' );
	const area = `${ PAD },${ H - PAD } ${ line } ${ W - PAD },${ H - PAD }`;
	const hp = hover !== null ? points[ hover ] : null;

	return (
		<div style={ { position: 'relative', maxWidth: `${ W }px` } }>
			<svg
				viewBox={ `0 0 ${ W } ${ H }` }
				width="100%"
				role="img"
				aria-label={ __(
					'Daily sessions over the selected range.',
					'terraviz'
				) }
				style={ { display: 'block', overflow: 'visible' } }
				onPointerMove={ onMove }
				onPointerLeave={ () => setHover( null ) }
			>
				<polygon points={ area } fill={ ACCENT } fillOpacity="0.08" />
				<polyline
					points={ line }
					fill="none"
					stroke={ ACCENT }
					strokeWidth="2"
					strokeLinejoin="round"
					strokeLinecap="round"
				/>
				{ hp && (
					<>
						<line
							x1={ hp.x }
							y1={ PAD }
							x2={ hp.x }
							y2={ H - PAD }
							stroke="#c3c4c7"
							strokeWidth="1"
						/>
						<circle
							cx={ hp.x }
							cy={ hp.y }
							r="4"
							fill={ ACCENT }
							stroke="#fff"
							strokeWidth="2"
						/>
					</>
				) }
			</svg>
			{ hp && (
				<div
					style={ {
						position: 'absolute',
						top: 0,
						left: `${ ( hp.x / W ) * 100 }%`,
						transform: 'translateX(-50%)',
						background: INK,
						color: '#fff',
						fontSize: '12px',
						padding: '4px 8px',
						borderRadius: '3px',
						whiteSpace: 'nowrap',
						pointerEvents: 'none',
					} }
				>
					<strong>{ num( hp.sessions ) }</strong> { hp.day }
				</div>
			) }
		</div>
	);
}

/**
 * The analytics overview: range + environment filters over a set of stat tiles, a
 * daily-sessions sparkline, and single-hue magnitude breakdowns.
 *
 * @return {JSX.Element} The analytics overview.
 */
export default function Analytics() {
	const [ days, setDays ] = useState( '30' );
	const [ environment, setEnvironment ] = useState( 'production' );
	const [ data, setData ] = useState( null );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( null );

	useEffect( () => {
		let cancelled = false;
		setLoading( true );
		setError( null );
		getAnalytics( { section: 'overview', days, environment } )
			.then( ( res ) => {
				if ( ! cancelled ) {
					setData( res || null );
				}
			} )
			.catch( ( e ) => {
				if ( ! cancelled ) {
					setError( normalizeError( e ).message );
					setData( null );
				}
			} )
			.finally( () => {
				if ( ! cancelled ) {
					setLoading( false );
				}
			} );
		return () => {
			cancelled = true;
		};
	}, [ days, environment ] );

	const d = ( data && data.data ) || {};
	const totals = d.totals || {};
	const platformRows = Object.entries( d.platforms || {} ).map(
		( [ label, value ] ) => ( { label, value } )
	);
	const osRows = Object.entries( d.operatingSystems || {} ).map(
		( [ label, value ] ) => ( { label, value } )
	);
	const countryRows = ( d.countries || [] ).map( ( c ) => ( {
		label: c.country || __( 'Unknown', 'terraviz' ),
		value: c.sessions,
	} ) );
	const trend = ( d.days || [] ).map( ( r ) => ( {
		day: r.day,
		sessions: Number( r.sessions ) || 0,
	} ) );

	return (
		<div>
			<div
				style={ {
					display: 'flex',
					alignItems: 'baseline',
					justifyContent: 'space-between',
					gap: '12px',
					flexWrap: 'wrap',
				} }
			>
				<p style={ { color: MUTED, margin: '4px 0 0' } }>
					{ __(
						'How people are engaging with your datasets on the globe.',
						'terraviz'
					) }
				</p>
				<div
					style={ {
						display: 'flex',
						gap: '12px',
						alignItems: 'flex-end',
					} }
				>
					<SelectControl
						label={ __( 'Range', 'terraviz' ) }
						value={ days }
						options={ RANGES }
						onChange={ setDays }
						__nextHasNoMarginBottom
					/>
					<SelectControl
						label={ __( 'Environment', 'terraviz' ) }
						value={ environment }
						options={ ENVIRONMENTS }
						onChange={ setEnvironment }
						__nextHasNoMarginBottom
					/>
				</div>
			</div>

			{ error && (
				<Notice
					status="error"
					isDismissible={ false }
					style={ { marginTop: '12px' } }
				>
					{ error }
				</Notice>
			) }

			{ loading && ! data ? (
				<p style={ { marginTop: '20px' } }>
					<Spinner /> { __( 'Loading analytics…', 'terraviz' ) }
				</p>
			) : (
				! error && (
					<>
						<Section title={ __( 'At a glance', 'terraviz' ) }>
							<div
								style={ {
									display: 'flex',
									gap: '16px',
									flexWrap: 'wrap',
								} }
							>
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
								/>
							</div>
						</Section>

						<Section title={ __( 'Daily sessions', 'terraviz' ) }>
							<Sparkline days={ trend } />
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
										rows={ platformRows }
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
										rows={ osRows }
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
								rows={ countryRows }
								empty={ __(
									'No country data for this range.',
									'terraviz'
								) }
							/>
						</Section>
					</>
				)
			) }
		</div>
	);
}
