/**
 * The Overview home — the dashboard's landing page, modelled on the Publisher
 * Portal mockup's Overview scene.
 *
 * Milestone A builds it from data the plugin *already* has (dataset counts and
 * the proposed-events queue depth), so it carries no new upstream dependency.
 * The "Recent activity" and "Latest feedback" rails the mockup shows arrive with
 * the Feedback area (Milestone C); until then they render an honest placeholder
 * rather than fabricated rows.
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { Button, Spinner } from '@wordpress/components';

const cardStyle = {
	flex: '1 1 220px',
	border: '1px solid #dcdcde',
	borderRadius: '4px',
	padding: '16px',
	background: '#fff',
};

function StatTile( { label, value } ) {
	return (
		<div style={ cardStyle }>
			<div
				style={ {
					fontSize: '12px',
					textTransform: 'uppercase',
					letterSpacing: '0.04em',
					color: '#646970',
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

function NeedsCard( { title, detail, actionLabel, onAction } ) {
	return (
		<div style={ { ...cardStyle, borderLeft: '3px solid #dba617' } }>
			<div style={ { fontWeight: 600 } }>{ title }</div>
			{ detail && (
				<div
					style={ {
						color: '#646970',
						fontSize: '13px',
						margin: '4px 0 10px',
					} }
				>
					{ detail }
				</div>
			) }
			<Button
				variant="link"
				onClick={ onAction }
				style={ { padding: 0 } }
			>
				{ actionLabel }
			</Button>
		</div>
	);
}

function Section( { title, children } ) {
	return (
		<section style={ { marginTop: '28px' } }>
			{ /* h3: subordinate to App.js's h2 page title (which sits under the
			   wp-admin h1), keeping the heading hierarchy correct. */ }
			<h3
				style={ {
					fontSize: '13px',
					textTransform: 'uppercase',
					letterSpacing: '0.04em',
					color: '#646970',
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
 * @param {Object}   props
 * @param {Object}   props.boot         Boot config (`canPublish`, `origin`).
 * @param {Object}   props.summary      `{ datasets: {draft,published,retracted,total}, proposedEvents, error }`.
 * @param {boolean}  props.loading      Whether the summary is still loading.
 * @param {Function} props.onNavigate   Called with a section key to switch views.
 * @param {Function} props.onNewDataset Opens the Datasets create form directly.
 * @return {JSX.Element} The overview page.
 */
export default function Overview( {
	boot,
	summary,
	loading,
	onNavigate,
	onNewDataset,
} ) {
	if ( loading && ! summary ) {
		return (
			<p>
				<Spinner /> { __( 'Loading your catalog…', 'terraviz' ) }
			</p>
		);
	}

	const ds = ( summary && summary.datasets ) || {
		draft: 0,
		published: 0,
		retracted: 0,
		total: 0,
	};
	const proposed = summary ? summary.proposedEvents : null;
	// summary.error is set only when the dataset load failed, so the counts are
	// all zero and meaningless — show '—' (unavailable) rather than a misleading 0.
	const dsUnavailable = !! ( summary && summary.error );

	const needs = [];
	if ( boot.canPublish && proposed > 0 ) {
		needs.push(
			<NeedsCard
				key="events"
				title={ sprintf(
					/* translators: %d: number of events awaiting review. */
					_n(
						'%d event awaiting review',
						'%d events awaiting review',
						proposed,
						'terraviz'
					),
					proposed
				) }
				detail={ __(
					'Proposed by your feeds — confirm the datasets they pair with.',
					'terraviz'
				) }
				actionLabel={ __( 'Review events →', 'terraviz' ) }
				onAction={ () => onNavigate( 'events' ) }
			/>
		);
	}
	if ( ds.draft > 0 ) {
		needs.push(
			<NeedsCard
				key="drafts"
				title={ sprintf(
					/* translators: %d: number of draft datasets. */
					_n(
						'%d draft not yet published',
						'%d drafts not yet published',
						ds.draft,
						'terraviz'
					),
					ds.draft
				) }
				detail={ __(
					'Finish and publish them to the globe.',
					'terraviz'
				) }
				actionLabel={ __( 'Go to datasets →', 'terraviz' ) }
				onAction={ () => onNavigate( 'datasets' ) }
			/>
		);
	}

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
				<p style={ { color: '#646970', margin: '4px 0 0' } }>
					{ __( "Here's what needs you today.", 'terraviz' ) }
				</p>
				<div style={ { display: 'flex', gap: '8px' } }>
					<Button variant="primary" onClick={ onNewDataset }>
						{ __( 'New dataset', 'terraviz' ) }
					</Button>
					{ boot.canPublish && (
						<Button
							variant="secondary"
							onClick={ () => onNavigate( 'events' ) }
						>
							{ __( 'Review events', 'terraviz' ) }
						</Button>
					) }
				</div>
			</div>

			{ summary && summary.error && (
				<p style={ { color: '#d63638', marginTop: '12px' } }>
					{ summary.error }
				</p>
			) }

			<Section title={ __( 'Needs you', 'terraviz' ) }>
				{ needs.length > 0 ? (
					<div
						style={ {
							display: 'flex',
							gap: '16px',
							flexWrap: 'wrap',
						} }
					>
						{ needs }
					</div>
				) : (
					<p style={ { color: '#646970' } }>
						{ __(
							"You're all caught up — nothing needs your attention right now.",
							'terraviz'
						) }
					</p>
				) }
			</Section>

			<Section title={ __( 'At a glance', 'terraviz' ) }>
				<div
					style={ {
						display: 'flex',
						gap: '16px',
						flexWrap: 'wrap',
					} }
				>
					<StatTile
						label={ __( 'Published datasets', 'terraviz' ) }
						value={ dsUnavailable ? '—' : ds.published }
					/>
					<StatTile
						label={ __( 'Drafts', 'terraviz' ) }
						value={ dsUnavailable ? '—' : ds.draft }
					/>
					<StatTile
						label={ __( 'Retracted', 'terraviz' ) }
						value={ dsUnavailable ? '—' : ds.retracted }
					/>
					{ boot.canPublish && (
						<StatTile
							label={ __( 'Events in queue', 'terraviz' ) }
							value={ proposed === null ? '—' : proposed }
						/>
					) }
				</div>
			</Section>

			<Section title={ __( 'Recent activity', 'terraviz' ) }>
				<p style={ { color: '#646970' } }>
					{ __(
						'An activity and feedback feed arrives with the Insights area.',
						'terraviz'
					) }
				</p>
			</Section>
		</div>
	);
}
