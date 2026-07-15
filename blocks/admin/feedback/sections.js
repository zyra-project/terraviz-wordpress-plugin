/**
 * The Feedback sections — the AI (thumbs) and General (bug/feature/other) review
 * views, plus a per-report screenshot drill-down. Both read the node's
 * privilege-gated feedback facade (`GET /publish/feedback?view=…`) and reuse the
 * shared analytics primitives, so Insights reads as one system.
 */
import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { Button, Modal, Spinner } from '@wordpress/components';
import {
	MUTED,
	INK,
	StatTile,
	Section,
	BarList,
	Sparkline,
	Table,
	useResource,
	SectionState,
	num,
	pct,
} from '../analytics/primitives';
import { getFeedback } from '../api';

const tileRow = { display: 'flex', gap: '16px', flexWrap: 'wrap' };

/**
 * The date portion (`YYYY-MM-DD`) of an ISO timestamp.
 *
 * @param {string} iso Timestamp.
 * @return {string} The day, or '' when the input isn't a string.
 */
function day( iso ) {
	return typeof iso === 'string' ? iso.slice( 0, 10 ) : '';
}

/**
 * Return `url` only when it's an http(s) URL, else null. Feedback URLs are
 * reader-submitted, so a `javascript:` / `data:` value must never reach an
 * `href` where an admin could click it.
 *
 * @param {string} url Candidate URL.
 * @return {?string} The safe URL, or null.
 */
function safeHttpUrl( url ) {
	return /^https?:\/\//i.test( String( url || '' ).trim() ) ? url : null;
}

/**
 * Truncate a string to `n` characters with an ellipsis.
 *
 * @param {string} s   Text.
 * @param {number} [n] Max length (default 140).
 * @return {string} Possibly-truncated text.
 */
function truncate( s, n = 140 ) {
	const str = String( s || '' );
	return str.length > n ? `${ str.slice( 0, n - 1 ) }…` : str;
}

/* ---------------------------------------------------------------------- AI */

export function AiFeedbackSection( { days } ) {
	const { data, loading, error } = useResource( ( q ) => getFeedback( q ), {
		view: 'ai',
		days,
	} );
	return (
		<SectionState data={ data } loading={ loading } error={ error }>
			{ ( d ) => {
				const total = Number( d.totalCount ) || 0;
				const up = Number( d.thumbsUpCount ) || 0;
				const down = Number( d.thumbsDownCount ) || 0;
				const rated = up + down;
				// byDay comes date-descending; the sparkline wants oldest-first.
				const series = ( d.byDay || [] ).slice().reverse();
				return (
					<div>
						<Section title={ __( 'At a glance', 'terraviz' ) }>
							<div style={ tileRow }>
								<StatTile
									label={ __( 'Total ratings', 'terraviz' ) }
									value={ num( total ) }
								/>
								<StatTile
									label={ __( 'Thumbs up', 'terraviz' ) }
									value={ num( up ) }
								/>
								<StatTile
									label={ __( 'Thumbs down', 'terraviz' ) }
									value={ num( down ) }
								/>
								<StatTile
									label={ __( 'Satisfaction', 'terraviz' ) }
									value={
										rated > 0 ? pct( up / rated ) : '—'
									}
								/>
							</div>
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
									title={ __(
										'Thumbs up daily',
										'terraviz'
									) }
								>
									<Sparkline
										points={ series.map( ( r ) => ( {
											label: r.date,
											value: Number( r.up ) || 0,
										} ) ) }
										ariaLabel={ __(
											'Thumbs-up ratings per day.',
											'terraviz'
										) }
									/>
								</Section>
							</div>
							<div style={ { flex: '1 1 280px', minWidth: 0 } }>
								<Section
									title={ __(
										'Thumbs down daily',
										'terraviz'
									) }
								>
									<Sparkline
										points={ series.map( ( r ) => ( {
											label: r.date,
											value: Number( r.down ) || 0,
										} ) ) }
										ariaLabel={ __(
											'Thumbs-down ratings per day.',
											'terraviz'
										) }
									/>
								</Section>
							</div>
						</div>

						<Section title={ __( 'Top tags', 'terraviz' ) }>
							<BarList
								rows={ ( d.topTags || [] ).map( ( t ) => ( {
									label: t.tag,
									value: t.count,
								} ) ) }
								empty={ __(
									'No tagged feedback in this range.',
									'terraviz'
								) }
							/>
						</Section>

						<Section title={ __( 'Recent feedback', 'terraviz' ) }>
							<RecentAi rows={ d.recentFeedback || [] } />
						</Section>
					</div>
				);
			} }
		</SectionState>
	);
}

/**
 * The recent AI-feedback list — one card per rating, with the conversation behind
 * a native disclosure so the list stays scannable.
 *
 * @param {Object}        props
 * @param {Array<Object>} props.rows Recent feedback rows.
 * @return {JSX.Element} The list.
 */
function RecentAi( { rows } ) {
	if ( ! rows.length ) {
		return (
			<p style={ { color: MUTED, margin: 0 } }>
				{ __( 'No feedback yet.', 'terraviz' ) }
			</p>
		);
	}
	return (
		<div style={ { display: 'grid', gap: '10px' } }>
			{ rows.map( ( r, i ) => {
				const tags = Array.isArray( r.tags ) ? r.tags : [];
				const isUp = r.rating === 'thumbs-up';
				// The node's recent-feedback rows carry no id, so build a stable
				// key from the timestamp + a slice of the content — this survives a
				// prepend (new feedback) without a disclosure sticking to the wrong
				// card the way a bare array index would.
				const key = `${ r.created_at }:${ (
					r.comment ||
					r.user_message ||
					''
				).slice( 0, 32 ) }:${ i }`;
				return (
					<div
						key={ key }
						style={ {
							border: '1px solid #dcdcde',
							borderRadius: '4px',
							padding: '12px',
							background: '#fff',
						} }
					>
						<div
							style={ {
								display: 'flex',
								justifyContent: 'space-between',
								gap: '12px',
							} }
						>
							<span style={ { fontWeight: 600, color: INK } }>
								{ isUp
									? __( '👍 Helpful', 'terraviz' )
									: __( '👎 Not helpful', 'terraviz' ) }
							</span>
							<span style={ { color: MUTED, fontSize: '12px' } }>
								{ day( r.created_at ) }
							</span>
						</div>
						{ r.comment && (
							<p style={ { margin: '6px 0 0', color: INK } }>
								{ r.comment }
							</p>
						) }
						{ tags.length > 0 && (
							<p
								style={ {
									margin: '6px 0 0',
									color: MUTED,
									fontSize: '12px',
								} }
							>
								{ tags.join( ', ' ) }
							</p>
						) }
						{ ( r.user_message || r.assistant_message ) && (
							<details style={ { marginTop: '8px' } }>
								<summary
									style={ {
										cursor: 'pointer',
										color: '#2271b1',
										fontSize: '13px',
									} }
								>
									{ __( 'Show conversation', 'terraviz' ) }
								</summary>
								<div
									style={ {
										marginTop: '8px',
										fontSize: '13px',
									} }
								>
									{ r.user_message && (
										<p style={ { margin: '0 0 6px' } }>
											<strong>
												{ __( 'User:', 'terraviz' ) }
											</strong>
											{ truncate( r.user_message, 600 ) }
										</p>
									) }
									{ r.assistant_message && (
										<p style={ { margin: 0 } }>
											<strong>
												{ __( 'Orbit:', 'terraviz' ) }
											</strong>
											{ truncate(
												r.assistant_message,
												600
											) }
										</p>
									) }
								</div>
							</details>
						) }
					</div>
				);
			} ) }
		</div>
	);
}

/* ----------------------------------------------------------------- General */

export function GeneralFeedbackSection( { days } ) {
	const [ shotId, setShotId ] = useState( null );
	const { data, loading, error } = useResource( ( q ) => getFeedback( q ), {
		view: 'general',
		days,
	} );
	return (
		<SectionState data={ data } loading={ loading } error={ error }>
			{ ( d ) => {
				const series = ( d.byDay || [] ).slice().reverse();
				const rows = ( d.recentFeedback || [] ).map( ( r ) => ( {
					...r,
					key: r.id,
				} ) );
				return (
					<div>
						<Section title={ __( 'At a glance', 'terraviz' ) }>
							<div style={ tileRow }>
								<StatTile
									label={ __( 'Total reports', 'terraviz' ) }
									value={ num( d.totalCount ) }
								/>
								<StatTile
									label={ __( 'Bugs', 'terraviz' ) }
									value={ num( d.bugCount ) }
								/>
								<StatTile
									label={ __( 'Features', 'terraviz' ) }
									value={ num( d.featureCount ) }
								/>
								<StatTile
									label={ __( 'Other', 'terraviz' ) }
									value={ num( d.otherCount ) }
								/>
							</div>
						</Section>

						<Section title={ __( 'Reports daily', 'terraviz' ) }>
							<Sparkline
								points={ series.map( ( r ) => ( {
									label: r.date,
									value:
										( Number( r.bugs ) || 0 ) +
										( Number( r.features ) || 0 ) +
										( Number( r.other ) || 0 ),
								} ) ) }
								ariaLabel={ __(
									'Reports submitted per day.',
									'terraviz'
								) }
							/>
						</Section>

						<Section title={ __( 'Recent reports', 'terraviz' ) }>
							<Table
								columns={ [
									{
										key: 'created_at',
										label: __( 'Date', 'terraviz' ),
										render: ( r ) => day( r.created_at ),
									},
									{
										key: 'kind',
										label: __( 'Kind', 'terraviz' ),
									},
									{
										key: 'message',
										label: __( 'Message', 'terraviz' ),
										render: ( r ) => (
											<span
												style={ {
													whiteSpace: 'normal',
												} }
												title={ r.message }
											>
												{ truncate( r.message, 120 ) }
											</span>
										),
									},
									{
										key: 'platform',
										label: __( 'Platform', 'terraviz' ),
									},
									{
										key: 'url',
										label: __( 'Page', 'terraviz' ),
										render: ( r ) => {
											const href = safeHttpUrl( r.url );
											if ( href ) {
												return (
													<a
														href={ href }
														target="_blank"
														rel="noreferrer noopener"
													>
														{ __(
															'Open',
															'terraviz'
														) }
													</a>
												);
											}
											// A non-http(s) value is shown as inert
											// text, never a clickable href.
											return r.url ? (
												<span
													style={ {
														color: MUTED,
													} }
													title={ r.url }
												>
													{ truncate( r.url, 40 ) }
												</span>
											) : (
												'—'
											);
										},
									},
									{
										key: 'shot',
										label: __( 'Screenshot', 'terraviz' ),
										render: ( r ) =>
											r.hasScreenshot ? (
												<Button
													variant="link"
													style={ { padding: 0 } }
													onClick={ () =>
														setShotId( r.id )
													}
												>
													{ __( 'View', 'terraviz' ) }
												</Button>
											) : (
												'—'
											),
									},
								] }
								rows={ rows }
								empty={ __(
									'No reports in this range.',
									'terraviz'
								) }
							/>
						</Section>

						{ shotId !== null && (
							<ScreenshotModal
								id={ shotId }
								onClose={ () => setShotId( null ) }
							/>
						) }
					</div>
				);
			} }
		</SectionState>
	);
}

/**
 * A modal that fetches and shows one report's screenshot (a data URL) on demand —
 * the list response omits the image bytes, so it's pulled only when opened.
 *
 * @param {Object}   props
 * @param {number}   props.id      general_feedback row id.
 * @param {Function} props.onClose Close handler.
 * @return {JSX.Element} The modal.
 */
function ScreenshotModal( { id, onClose } ) {
	const { data, loading, error } = useResource( ( q ) => getFeedback( q ), {
		view: 'screenshot',
		id,
	} );
	return (
		<Modal
			title={ __( 'Report screenshot', 'terraviz' ) }
			onRequestClose={ onClose }
		>
			{ loading && ! data && (
				<p>
					<Spinner /> { __( 'Loading…', 'terraviz' ) }
				</p>
			) }
			{ error && <p style={ { color: '#d63638' } }>{ error }</p> }
			{ data && data.screenshot ? (
				<img
					src={ data.screenshot }
					alt={ __( 'Feedback screenshot', 'terraviz' ) }
					style={ { maxWidth: '100%', height: 'auto' } }
				/>
			) : (
				! loading &&
				! error && (
					<p style={ { color: MUTED } }>
						{ __( 'No screenshot for this report.', 'terraviz' ) }
					</p>
				)
			) }
		</Modal>
	);
}
