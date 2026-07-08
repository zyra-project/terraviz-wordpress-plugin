/**
 * The dataset list view, styled to the Publisher Portal mockup's Datasets scene:
 * a subtitle, status count tiles that double as filters, and a table with a
 * thumbnail preview, an inline status badge, format, and last-updated — plus the
 * lifecycle row actions (edit / publish / retract / delete) rendered as uniform,
 * understated links.
 *
 * The parent fetches the whole catalog once and passes it in full; this
 * component tallies the status counts from that set and applies the active
 * filter client-side, so the tiles always reflect true totals.
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { Button, Spinner } from '@wordpress/components';
import { deriveStatus, statusLabel } from './status';
import { safeHttpUrl } from './safeUrl';

const STATUSES = [ 'draft', 'published', 'retracted' ];

// Status badge palette (wp-admin-native hues): draft grey, published green,
// retracted amber.
const BADGE = {
	draft: { bg: '#f0f0f1', fg: '#50575e' },
	published: { bg: '#edfaef', fg: '#00690e' },
	retracted: { bg: '#fcf0e6', fg: '#8a4b00' },
};

function StatusBadge( { status } ) {
	const c = BADGE[ status ] || BADGE.draft;
	return (
		<span
			style={ {
				display: 'inline-block',
				padding: '1px 8px',
				borderRadius: '9px',
				fontSize: '11px',
				fontWeight: 600,
				lineHeight: '18px',
				background: c.bg,
				color: c.fg,
			} }
		>
			{ statusLabel( status ) }
		</span>
	);
}

function tileStyle( active ) {
	return {
		flex: '1 1 160px',
		textAlign: 'left',
		border: active ? '1px solid #2271b1' : '1px solid #dcdcde',
		boxShadow: active ? 'inset 0 0 0 1px #2271b1' : 'none',
		borderRadius: '4px',
		padding: '12px 16px',
		background: '#fff',
		cursor: 'pointer',
	};
}

function FilterTile( { label, count, active, onClick } ) {
	return (
		<button
			type="button"
			aria-pressed={ active }
			onClick={ onClick }
			style={ tileStyle( active ) }
		>
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
					fontSize: '24px',
					fontWeight: 700,
					lineHeight: 1.2,
					marginTop: '2px',
					color: '#1d2327',
				} }
			>
				{ count }
			</div>
		</button>
	);
}

/**
 * Format an ISO timestamp as a short local date (e.g. "May 17, 2026"), or '—'
 * when missing/unparseable.
 *
 * @param {string} iso ISO-8601 timestamp.
 * @return {string} Short date, or '—'.
 */
function shortDate( iso ) {
	if ( ! iso ) {
		return '—';
	}
	const d = new Date( iso );
	if ( Number.isNaN( d.getTime() ) ) {
		return '—';
	}
	return d.toLocaleDateString( undefined, {
		year: 'numeric',
		month: 'short',
		day: 'numeric',
	} );
}

export default function DatasetList( {
	datasets,
	loading,
	filter,
	canPublish,
	busyId,
	onFilter,
	onNew,
	onEdit,
	onPublish,
	onRetract,
	onDelete,
} ) {
	const counts = { draft: 0, published: 0, retracted: 0 };
	datasets.forEach( ( d ) => {
		counts[ deriveStatus( d ) ] += 1;
	} );
	const rows = filter
		? datasets.filter( ( d ) => deriveStatus( d ) === filter )
		: datasets;

	// Toggle a status filter: clicking the active tile clears it back to "all".
	const toggle = ( status ) => onFilter( filter === status ? '' : status );

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
					{ __(
						'The visualizations you publish to the globe.',
						'terraviz'
					) }
				</p>
				<div
					style={ {
						display: 'flex',
						alignItems: 'center',
						gap: '8px',
					} }
				>
					{ filter && (
						<Button variant="link" onClick={ () => onFilter( '' ) }>
							{ __( 'Show all', 'terraviz' ) }
						</Button>
					) }
					<Button variant="primary" onClick={ onNew }>
						{ __( 'New draft', 'terraviz' ) }
					</Button>
					{ loading && <Spinner /> }
				</div>
			</div>

			<div
				style={ {
					display: 'flex',
					gap: '16px',
					flexWrap: 'wrap',
					margin: '16px 0',
				} }
			>
				{ STATUSES.map( ( status ) => (
					<FilterTile
						key={ status }
						label={ statusLabel( status ) }
						count={ counts[ status ] }
						active={ filter === status }
						onClick={ () => toggle( status ) }
					/>
				) ) }
			</div>

			<table className="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th style={ { width: '52px' } }>
							{ __( 'Preview', 'terraviz' ) }
						</th>
						<th>{ __( 'Title', 'terraviz' ) }</th>
						<th>{ __( 'Slug', 'terraviz' ) }</th>
						<th style={ { width: '110px' } }>
							{ __( 'Format', 'terraviz' ) }
						</th>
						<th style={ { width: '130px' } }>
							{ __( 'Updated', 'terraviz' ) }
						</th>
						<th style={ { width: '200px' } }>
							{ __( 'Actions', 'terraviz' ) }
						</th>
					</tr>
				</thead>
				<tbody>
					{ ! loading && rows.length === 0 && (
						<tr>
							<td colSpan="6">
								{ filter
									? __(
											'No datasets in this status.',
											'terraviz'
									  )
									: __( 'No datasets yet.', 'terraviz' ) }
							</td>
						</tr>
					) }
					{ rows.map( ( ds ) => {
						const status = deriveStatus( ds );
						const busy = busyId === ds.id;
						// Editing a published row is a publish-tier action; the
						// server enforces this too.
						const canEditRow = canPublish || status !== 'published';
						const thumb =
							safeHttpUrl( ds.thumbnail_url ) ||
							safeHttpUrl( ds.thumbnail_ref );

						// Assemble the row actions, then render them joined by a
						// muted separator so edit/publish/retract/delete read as
						// one uniform action group (not mismatched buttons).
						const actions = [];
						if ( canEditRow ) {
							actions.push(
								<Button
									key="edit"
									variant="link"
									onClick={ () => onEdit( ds.id ) }
									disabled={ busy }
								>
									{ __( 'Edit', 'terraviz' ) }
								</Button>
							);
						}
						if ( canPublish && status === 'draft' ) {
							actions.push(
								<Button
									key="publish"
									variant="link"
									onClick={ () => onPublish( ds.id ) }
									disabled={ busy }
								>
									{ __( 'Publish', 'terraviz' ) }
								</Button>
							);
						}
						if ( canPublish && status === 'published' ) {
							actions.push(
								<Button
									key="retract"
									variant="link"
									onClick={ () => onRetract( ds.id ) }
									disabled={ busy }
								>
									{ __( 'Retract', 'terraviz' ) }
								</Button>
							);
						}
						if ( canPublish && status !== 'published' ) {
							actions.push(
								<Button
									key="delete"
									variant="link"
									isDestructive
									onClick={ () => onDelete( ds.id ) }
									disabled={ busy }
								>
									{ __( 'Delete', 'terraviz' ) }
								</Button>
							);
						}

						return (
							<tr key={ ds.id }>
								<td>
									{ thumb ? (
										<img
											src={ thumb }
											alt=""
											style={ {
												width: '40px',
												height: '40px',
												objectFit: 'cover',
												borderRadius: '2px',
												display: 'block',
											} }
										/>
									) : (
										<div
											aria-hidden="true"
											style={ {
												width: '40px',
												height: '40px',
												borderRadius: '2px',
												background: '#f0f0f1',
											} }
										/>
									) }
								</td>
								<td>
									<div
										style={ {
											display: 'flex',
											alignItems: 'center',
											gap: '8px',
											flexWrap: 'wrap',
										} }
									>
										{ canEditRow ? (
											<Button
												variant="link"
												onClick={ () =>
													onEdit( ds.id )
												}
												style={ {
													fontWeight: 600,
												} }
											>
												{ ds.title || ds.slug || ds.id }
											</Button>
										) : (
											<strong>
												{ ds.title || ds.slug || ds.id }
											</strong>
										) }
										<StatusBadge status={ status } />
									</div>
								</td>
								<td>{ ds.slug || '—' }</td>
								<td>{ ds.format || '—' }</td>
								<td>{ shortDate( ds.updated_at ) }</td>
								<td>
									<div
										style={ {
											display: 'flex',
											alignItems: 'center',
											gap: '8px',
											flexWrap: 'wrap',
										} }
									>
										{ actions.map( ( action, i ) => (
											<span
												key={ action.key }
												style={ {
													display: 'inline-flex',
													alignItems: 'center',
													gap: '8px',
												} }
											>
												{ i > 0 && (
													<span
														aria-hidden="true"
														style={ {
															color: '#c3c4c7',
														} }
													>
														|
													</span>
												) }
												{ action }
											</span>
										) ) }
										{ busy && <Spinner /> }
									</div>
								</td>
							</tr>
						);
					} ) }
				</tbody>
			</table>

			{ ! loading && (
				<p style={ { color: '#646970', marginTop: '8px' } }>
					{ sprintf(
						/* translators: %d: number of datasets shown. */
						_n(
							'%d dataset',
							'%d datasets',
							rows.length,
							'terraviz'
						),
						rows.length
					) }
				</p>
			) }
		</div>
	);
}
