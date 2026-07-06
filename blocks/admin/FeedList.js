/**
 * The feed-connector table: the RSS/EONET sources that generate proposed
 * events. Rows open the edit form; each row can be enabled/disabled inline or
 * deleted.
 */
import { __ } from '@wordpress/i18n';
import { Button, Spinner, ToggleControl } from '@wordpress/components';

function lastRun( feed ) {
	if ( feed.lastRunStatus === 'error' ) {
		return feed.lastRunError
			? `${ __( 'Error', 'terraviz' ) }: ${ feed.lastRunError }`
			: __( 'Error', 'terraviz' );
	}
	if ( feed.lastRunStatus === 'ok' && feed.lastRunAt ) {
		const d = new Date( feed.lastRunAt );
		return Number.isNaN( d.getTime() )
			? __( 'OK', 'terraviz' )
			: `${ __( 'OK', 'terraviz' ) } · ${ d.toLocaleString() }`;
	}
	return '—';
}

export default function FeedList( {
	feeds,
	loading,
	busyId,
	onNew,
	onEdit,
	onToggle,
	onDelete,
} ) {
	return (
		<div>
			<div
				style={ {
					display: 'flex',
					alignItems: 'center',
					gap: '12px',
					margin: '12px 0',
				} }
			>
				<Button variant="primary" onClick={ onNew }>
					{ __( 'New feed', 'terraviz' ) }
				</Button>
				{ loading && <Spinner /> }
			</div>

			<table className="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th>{ __( 'Label', 'terraviz' ) }</th>
						<th>{ __( 'Kind', 'terraviz' ) }</th>
						<th>{ __( 'URL', 'terraviz' ) }</th>
						<th>{ __( 'Category', 'terraviz' ) }</th>
						<th>{ __( 'Enabled', 'terraviz' ) }</th>
						<th>{ __( 'Last run', 'terraviz' ) }</th>
						<th>{ __( 'Actions', 'terraviz' ) }</th>
					</tr>
				</thead>
				<tbody>
					{ ! loading && feeds.length === 0 && (
						<tr>
							<td colSpan="7">
								{ __( 'No feed connectors yet.', 'terraviz' ) }
							</td>
						</tr>
					) }
					{ feeds.map( ( feed ) => (
						<tr key={ feed.id }>
							<td>
								<Button
									variant="link"
									onClick={ () => onEdit( feed ) }
								>
									{ feed.label || feed.id }
								</Button>
							</td>
							<td>{ feed.kind }</td>
							<td
								style={ {
									maxWidth: '260px',
									overflow: 'hidden',
									textOverflow: 'ellipsis',
									whiteSpace: 'nowrap',
								} }
							>
								{ feed.url }
							</td>
							<td>{ feed.category || '—' }</td>
							<td>
								<ToggleControl
									checked={ !! feed.enabled }
									disabled={ busyId === feed.id }
									onChange={ () =>
										onToggle( feed, ! feed.enabled )
									}
									__nextHasNoMarginBottom
								/>
							</td>
							<td>{ lastRun( feed ) }</td>
							<td>
								<Button
									variant="secondary"
									size="small"
									onClick={ () => onEdit( feed ) }
								>
									{ __( 'Edit', 'terraviz' ) }
								</Button>{ ' ' }
								<Button
									variant="link"
									isDestructive
									disabled={ busyId === feed.id }
									onClick={ () => onDelete( feed ) }
								>
									{ __( 'Delete', 'terraviz' ) }
								</Button>
							</td>
						</tr>
					) ) }
				</tbody>
			</table>
		</div>
	);
}
