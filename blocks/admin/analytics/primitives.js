/**
 * Shared building blocks for the Analytics area — formatters, chart marks, a
 * generic table, and the per-section data hook. Every section (Overview,
 * Datasets, Spatial, …) is drawn from these so the whole area reads as one
 * system: one accent hue, stat tiles for totals, single-hue horizontal bars for
 * magnitude-by-identity, sparklines for time series, plain tables for the rest.
 */
import { __, sprintf } from '@wordpress/i18n';
import { useState, useEffect, useMemo, useCallback } from '@wordpress/element';
import { Spinner, Notice } from '@wordpress/components';
import { getAnalytics, normalizeError } from '../api';

// One measure, one hue across every mark — so there is no categorical palette to
// validate. Matches the wp-admin primary accent the sidebar already uses.
export const ACCENT = '#2271b1';
export const INK = '#1d2327';
export const MUTED = '#646970';

export const cardStyle = {
	flex: '1 1 180px',
	border: '1px solid #dcdcde',
	borderRadius: '4px',
	padding: '16px',
	background: '#fff',
};

/**
 * Group an integer for the viewer's locale (`toLocaleString()`). Internal
 * dashboard figures, so locale-aware grouping is fine.
 *
 * @param {number} n Value.
 * @return {string} Grouped digits, or '0'.
 */
export function num( n ) {
	const v = Number( n );
	return Number.isFinite( v ) ? Math.round( v ).toLocaleString() : '0';
}

/**
 * A millisecond figure as `820 ms` / `1.8 s`.
 *
 * @param {number} n Milliseconds.
 * @return {string} Human latency, or '—' when null/NaN.
 */
export function ms( n ) {
	const v = Number( n );
	if ( ! Number.isFinite( v ) ) {
		return '—';
	}
	if ( v >= 1000 ) {
		return sprintf(
			/* translators: %s: seconds, one decimal. */
			__( '%ss', 'terraviz' ),
			( v / 1000 ).toFixed( 1 )
		);
	}
	return sprintf(
		/* translators: %d: milliseconds. */
		__( '%dms', 'terraviz' ),
		Math.round( v )
	);
}

/**
 * A fraction (0–1) as a percentage with one decimal.
 *
 * @param {number} n Fraction.
 * @return {string} e.g. `0.7%`.
 */
export function pct( n ) {
	const v = Number( n );
	if ( ! Number.isFinite( v ) ) {
		return '—';
	}
	return sprintf(
		/* translators: %s: percentage, one decimal. */
		__( '%s%%', 'terraviz' ),
		( v * 100 ).toFixed( 1 )
	);
}

/**
 * A duration in ms as a compact `1h 2m` / `2m 3s` / `4s`.
 *
 * @param {number} milli Milliseconds.
 * @return {string} Human duration.
 */
export function duration( milli ) {
	const total = Math.max( 0, Math.round( Number( milli ) || 0 ) );
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

/**
 * A headline figure with an optional secondary line.
 *
 * @param {Object} props
 * @param {string} props.label Tile label.
 * @param {string} props.value Formatted value.
 * @param {any}    [props.sub] Optional secondary node (a note or a link).
 * @return {JSX.Element} The tile.
 */
export function StatTile( { label, value, sub } ) {
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
			{ sub && (
				<div style={ { marginTop: '6px', fontSize: '13px' } }>
					{ sub }
				</div>
			) }
		</div>
	);
}

/**
 * A titled block, subordinate to the page's h2.
 *
 * @param {Object} props
 * @param {string} props.title    Heading text.
 * @param {any}    [props.right]  Optional right-aligned actions.
 * @param {any}    props.children Body.
 * @return {JSX.Element} The section.
 */
export function Section( { title, right, children } ) {
	return (
		<section style={ { marginTop: '28px' } }>
			<div
				style={ {
					display: 'flex',
					alignItems: 'baseline',
					justifyContent: 'space-between',
					gap: '12px',
				} }
			>
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
				{ right }
			</div>
			{ children }
		</section>
	);
}

/**
 * A single-hue horizontal-bar readout of a magnitude-by-identity breakdown, sorted
 * largest-first and direct-labelled so it doubles as its own table.
 *
 * @param {Object}                                props
 * @param {Array<{label: string, value: number}>} props.rows     Rows.
 * @param {string}                                props.empty    Empty-state text.
 * @param {Function}                              [props.format] Value formatter (default `num`).
 * @return {JSX.Element} The bar list.
 */
export function BarList( { rows, empty, format = num } ) {
	const sorted = [ ...rows ].sort( ( a, b ) => b.value - a.value );
	const max = sorted.reduce( ( m, r ) => Math.max( m, r.value ), 0 );
	if ( sorted.length === 0 || max <= 0 ) {
		return <p style={ { color: MUTED, margin: 0 } }>{ empty }</p>;
	}
	return (
		<div style={ { display: 'grid', gap: '8px' } }>
			{ sorted.map( ( r ) => {
				const w = Math.max( 2, Math.round( ( r.value / max ) * 100 ) );
				return (
					<div
						key={ r.label }
						style={ {
							display: 'grid',
							gridTemplateColumns: '140px 1fr auto',
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
									width: `${ w }%`,
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
							{ format( r.value ) }
						</span>
					</div>
				);
			} ) }
		</div>
	);
}

/**
 * A sparkline over a numeric series with a hover crosshair + tooltip. One series,
 * so the caller's section title names it — no legend.
 *
 * @param {Object}                                props
 * @param {Array<{label: string, value: number}>} props.points    Points, oldest-first.
 * @param {string}                                props.ariaLabel Accessible description.
 * @param {Function}                              [props.format]  Tooltip value formatter.
 * @return {JSX.Element} The sparkline.
 */
export function Sparkline( { points, ariaLabel, format = num } ) {
	const [ hover, setHover ] = useState( null );
	const W = 640;
	const H = 120;
	const PAD = 8;

	const pts = useMemo( () => {
		const max = points.reduce( ( m, p ) => Math.max( m, p.value ), 0 );
		const span = Math.max( 1, points.length - 1 );
		return points.map( ( p, i ) => ( {
			x: PAD + ( i / span ) * ( W - PAD * 2 ),
			y: H - PAD - ( max > 0 ? ( p.value / max ) * ( H - PAD * 2 ) : 0 ),
			label: p.label,
			value: p.value,
		} ) );
	}, [ points ] );

	const onMove = useCallback(
		( e ) => {
			const rect = e.currentTarget.getBoundingClientRect();
			const x = ( ( e.clientX - rect.left ) / rect.width ) * W;
			let nearest = 0;
			let best = Infinity;
			pts.forEach( ( p, i ) => {
				const dist = Math.abs( p.x - x );
				if ( dist < best ) {
					best = dist;
					nearest = i;
				}
			} );
			setHover( nearest );
		},
		[ pts ]
	);

	if ( points.length < 2 ) {
		return (
			<p style={ { color: MUTED, margin: 0 } }>
				{ __(
					'Not enough days in this range to plot a trend yet.',
					'terraviz'
				) }
			</p>
		);
	}

	const line = pts.map( ( p ) => `${ p.x },${ p.y }` ).join( ' ' );
	const area = `${ PAD },${ H - PAD } ${ line } ${ W - PAD },${ H - PAD }`;
	const hp = hover !== null ? pts[ hover ] : null;

	return (
		<div style={ { position: 'relative', maxWidth: `${ W }px` } }>
			<svg
				viewBox={ `0 0 ${ W } ${ H }` }
				width="100%"
				role="img"
				aria-label={ ariaLabel }
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
					<strong>{ format( hp.value ) }</strong> { hp.label }
				</div>
			) }
		</div>
	);
}

/**
 * A plain, recessive data table. Columns declare an accessor `key`, a `label`, an
 * optional `align`, and an optional `render(row)`.
 *
 * @param {Object}                                                                 props
 * @param {Array<{key: string, label: string, align?: string, render?: Function}>} props.columns Column defs.
 * @param {Array<Object>}                                                          props.rows    Row objects.
 * @param {string}                                                                 props.empty   Empty-state text.
 * @return {JSX.Element} The table.
 */
export function Table( { columns, rows, empty } ) {
	if ( ! rows || rows.length === 0 ) {
		return <p style={ { color: MUTED, margin: 0 } }>{ empty }</p>;
	}
	const cell = ( align ) => ( {
		padding: '6px 10px',
		textAlign: align || 'left',
		borderBottom: '1px solid #f0f0f1',
		fontSize: '13px',
		whiteSpace: 'nowrap',
	} );
	return (
		<div style={ { overflowX: 'auto' } }>
			<table
				style={ {
					width: '100%',
					borderCollapse: 'collapse',
					minWidth: '480px',
				} }
			>
				<thead>
					<tr>
						{ columns.map( ( c ) => (
							<th
								key={ c.key }
								style={ {
									...cell( c.align ),
									color: MUTED,
									fontSize: '11px',
									textTransform: 'uppercase',
									letterSpacing: '0.04em',
									borderBottom: '1px solid #dcdcde',
								} }
							>
								{ c.label }
							</th>
						) ) }
					</tr>
				</thead>
				<tbody>
					{ rows.map( ( row, i ) => (
						<tr key={ row.key || i }>
							{ columns.map( ( c ) => (
								<td
									key={ c.key }
									style={ {
										...cell( c.align ),
										color: INK,
										fontVariantNumeric: 'tabular-nums',
									} }
								>
									{ c.render
										? c.render( row )
										: row[ c.key ] }
								</td>
							) ) }
						</tr>
					) ) }
				</tbody>
			</table>
		</div>
	);
}

/**
 * A compact, sorted, color-free rendering of a `{ key: count }` mix — top few
 * shares as `key n` chips. Identity by text, so no categorical palette.
 *
 * @param {Object}                 props
 * @param {Object<string, number>} props.mix   The mix map.
 * @param {number}                 [props.top] Max entries to show (default 3).
 * @return {JSX.Element} The mix readout.
 */
export function MixText( { mix, top = 3 } ) {
	const entries = Object.entries( mix || {} )
		.filter( ( [ , v ] ) => Number( v ) > 0 )
		.sort( ( a, b ) => b[ 1 ] - a[ 1 ] )
		.slice( 0, top );
	if ( entries.length === 0 ) {
		return <span style={ { color: MUTED } }>—</span>;
	}
	return (
		<span style={ { color: INK } }>
			{ entries.map( ( [ k, v ], i ) => (
				<span key={ k }>
					{ i > 0 && <span style={ { color: '#c3c4c7' } }> · </span> }
					{ k } <strong>{ num( v ) }</strong>
				</span>
			) ) }
		</span>
	);
}

/**
 * Fetch one analytics section and track load/error state. Re-fetches whenever the
 * range, environment, or any extra (spatial) filter changes.
 *
 * @param {string} section Section key.
 * @param {Object} query   `{ days, environment, ...extra }` forwarded to the node.
 * @return {{data: ?Object, loading: boolean, error: ?string}} The section envelope + state.
 */
export function useSection( section, query ) {
	const [ data, setData ] = useState( null );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( null );
	// Serialise the query so a new object identity each render doesn't refetch.
	const key = JSON.stringify( query );

	useEffect( () => {
		let cancelled = false;
		setLoading( true );
		setError( null );
		getAnalytics( { section, ...JSON.parse( key ) } )
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
	}, [ section, key ] );

	return { data, loading, error };
}

/**
 * Standard load / error / empty gate for a section body. Renders the spinner while
 * first loading, the node's error inline, then delegates to `children(data)`.
 *
 * @param {Object}   props
 * @param {?Object}  props.data     Section envelope (`{ data: … }`).
 * @param {boolean}  props.loading  Loading flag.
 * @param {?string}  props.error    Error message.
 * @param {Function} props.children Render-prop called with the inner `data`.
 * @return {JSX.Element} The gated body.
 */
export function SectionState( { data, loading, error, children } ) {
	if ( error ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ error }
			</Notice>
		);
	}
	if ( loading && ! data ) {
		return (
			<p>
				<Spinner /> { __( 'Loading…', 'terraviz' ) }
			</p>
		);
	}
	return children( ( data && data.data ) || {} );
}
