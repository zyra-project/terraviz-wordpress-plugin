/**
 * The workflow list — scheduled dataset-refresh pipelines. A table with the
 * cadence, target dataset, enabled state, and next/last run, plus row actions:
 * Edit, Run now, and Enable/Disable (a PATCH of the `enabled` flag).
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { Button, Spinner } from '@wordpress/components';
import { scheduleLabel } from './schedule';

const BADGE = {
	on: { bg: '#edfaef', fg: '#00690e', label: __( 'Enabled', 'terraviz' ) },
	off: { bg: '#f0f0f1', fg: '#50575e', label: __( 'Disabled', 'terraviz' ) },
};

function EnabledBadge( { enabled } ) {
	const c = enabled ? BADGE.on : BADGE.off;
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
			{ c.label }
		</span>
	);
}

/**
 * Format an ISO timestamp as a short local date-time, or '—' when missing.
 *
 * @param {string} iso ISO-8601 timestamp.
 * @return {string} Short date-time, or '—'.
 */
function shortDateTime( iso ) {
	if ( ! iso ) {
		return '—';
	}
	const d = new Date( iso );
	if ( Number.isNaN( d.getTime() ) ) {
		return '—';
	}
	return d.toLocaleString( undefined, {
		month: 'short',
		day: 'numeric',
		hour: '2-digit',
		minute: '2-digit',
	} );
}

export default function WorkflowList( {
	workflows,
	loading,
	busyId,
	onNew,
	onEdit,
	onRun,
	onToggle,
} ) {
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
				<p
					style={ {
						color: '#646970',
						margin: '4px 0 0',
						maxWidth: '60ch',
					} }
				>
					{ __(
						'Scheduled pipelines that refresh a dataset from its source on a cadence. The pipeline runs in the node’s CI; you configure and trigger it here.',
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
					<Button variant="primary" onClick={ onNew }>
						{ __( 'New workflow', 'terraviz' ) }
					</Button>
					{ loading && <Spinner /> }
				</div>
			</div>

			<table
				className="wp-list-table widefat fixed striped"
				style={ { marginTop: '16px' } }
			>
				<thead>
					<tr>
						<th>{ __( 'Name', 'terraviz' ) }</th>
						<th style={ { width: '120px' } }>
							{ __( 'Cadence', 'terraviz' ) }
						</th>
						<th>{ __( 'Target dataset', 'terraviz' ) }</th>
						<th style={ { width: '150px' } }>
							{ __( 'Next run', 'terraviz' ) }
						</th>
						<th style={ { width: '150px' } }>
							{ __( 'Last run', 'terraviz' ) }
						</th>
						<th style={ { width: '210px' } }>
							{ __( 'Actions', 'terraviz' ) }
						</th>
					</tr>
				</thead>
				<tbody>
					{ ! loading && workflows.length === 0 && (
						<tr>
							<td colSpan="6">
								{ __( 'No workflows yet.', 'terraviz' ) }
							</td>
						</tr>
					) }
					{ workflows.map( ( wf ) => {
						const busy = busyId === wf.id;
						return (
							<tr key={ wf.id }>
								<td>
									<div
										style={ {
											display: 'flex',
											alignItems: 'center',
											gap: '8px',
											flexWrap: 'wrap',
										} }
									>
										<Button
											variant="link"
											onClick={ () => onEdit( wf.id ) }
											style={ { fontWeight: 600 } }
										>
											{ wf.name || wf.id }
										</Button>
										<EnabledBadge
											enabled={ !! wf.enabled }
										/>
									</div>
								</td>
								<td>{ scheduleLabel( wf.schedule ) }</td>
								<td>
									<code>{ wf.target_dataset_id || '—' }</code>
								</td>
								<td>
									{ wf.enabled
										? shortDateTime( wf.next_run_at )
										: '—' }
								</td>
								<td>{ shortDateTime( wf.last_run_at ) }</td>
								<td>
									<div
										style={ {
											display: 'flex',
											alignItems: 'center',
											gap: '8px',
											flexWrap: 'wrap',
										} }
									>
										<Button
											variant="link"
											onClick={ () => onEdit( wf.id ) }
											disabled={ busy }
										>
											{ __( 'Edit', 'terraviz' ) }
										</Button>
										<span
											aria-hidden="true"
											style={ { color: '#c3c4c7' } }
										>
											|
										</span>
										<Button
											variant="link"
											onClick={ () => onRun( wf.id ) }
											disabled={ busy }
										>
											{ __( 'Run now', 'terraviz' ) }
										</Button>
										<span
											aria-hidden="true"
											style={ { color: '#c3c4c7' } }
										>
											|
										</span>
										<Button
											variant="link"
											onClick={ () =>
												onToggle( wf.id, ! wf.enabled )
											}
											disabled={ busy }
										>
											{ wf.enabled
												? __( 'Disable', 'terraviz' )
												: __( 'Enable', 'terraviz' ) }
										</Button>
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
						/* translators: %d: number of workflows. */
						_n(
							'%d workflow',
							'%d workflows',
							workflows.length,
							'terraviz'
						),
						workflows.length
					) }
				</p>
			) }
		</div>
	);
}
