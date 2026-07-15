/**
 * The run-history panel shown under a workflow's edit form: recent executions
 * with their status, trigger and timing, plus a "Run now" button that queues a
 * manual execution. The node rejects a second concurrent run with 409
 * `run_in_progress`, surfaced as a notice.
 */
import { __ } from '@wordpress/i18n';
import { useState, useEffect, useCallback } from '@wordpress/element';
import { Button, Spinner, Notice } from '@wordpress/components';
import { listWorkflowRuns, runWorkflow, normalizeError } from './api';

const STATUS = {
	queued: { bg: '#f0f0f1', fg: '#50575e' },
	running: { bg: '#e5f1fb', fg: '#0a4b78' },
	succeeded: { bg: '#edfaef', fg: '#00690e' },
	failed: { bg: '#fcf0f1', fg: '#8a2424' },
	canceled: { bg: '#fcf0e6', fg: '#8a4b00' },
};

function StatusBadge( { status } ) {
	const c = STATUS[ status ] || STATUS.queued;
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
			{ status || __( 'unknown', 'terraviz' ) }
		</span>
	);
}

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

export default function WorkflowRuns( { id } ) {
	const [ runs, setRuns ] = useState( [] );
	const [ loading, setLoading ] = useState( true );
	const [ running, setRunning ] = useState( false );
	const [ notice, setNotice ] = useState( null );

	const refresh = useCallback( () => {
		setLoading( true );
		listWorkflowRuns( id )
			.then( ( res ) =>
				setRuns( Array.isArray( res.runs ) ? res.runs : [] )
			)
			.catch( ( e ) =>
				setNotice( {
					type: 'error',
					text: normalizeError( e ).message,
				} )
			)
			.finally( () => setLoading( false ) );
	}, [ id ] );

	useEffect( () => {
		refresh();
	}, [ refresh ] );

	const handleRun = () => {
		setRunning( true );
		setNotice( null );
		runWorkflow( id )
			.then( () => {
				setNotice( {
					type: 'success',
					text: __( 'Run queued.', 'terraviz' ),
				} );
				refresh();
			} )
			.catch( ( e ) =>
				setNotice( {
					type: 'error',
					text: normalizeError( e ).message,
				} )
			)
			.finally( () => setRunning( false ) );
	};

	return (
		<div
			style={ {
				marginTop: '28px',
				borderTop: '1px solid #dcdcde',
				paddingTop: '16px',
			} }
		>
			<div
				style={ {
					display: 'flex',
					alignItems: 'center',
					justifyContent: 'space-between',
					gap: '12px',
				} }
			>
				<h3 style={ { margin: 0 } }>
					{ __( 'Run history', 'terraviz' ) }
				</h3>
				<div style={ { display: 'flex', gap: '8px' } }>
					<Button
						variant="secondary"
						onClick={ handleRun }
						isBusy={ running }
						disabled={ running }
					>
						{ __( 'Run now', 'terraviz' ) }
					</Button>
					<Button
						variant="tertiary"
						onClick={ refresh }
						disabled={ loading }
					>
						{ __( 'Refresh', 'terraviz' ) }
					</Button>
				</div>
			</div>

			{ notice && (
				<Notice
					status={ notice.type }
					onRemove={ () => setNotice( null ) }
				>
					{ notice.text }
				</Notice>
			) }

			<table
				className="wp-list-table widefat fixed striped"
				style={ { marginTop: '12px' } }
			>
				<thead>
					<tr>
						<th style={ { width: '110px' } }>
							{ __( 'Status', 'terraviz' ) }
						</th>
						<th style={ { width: '90px' } }>
							{ __( 'Trigger', 'terraviz' ) }
						</th>
						<th>{ __( 'Queued', 'terraviz' ) }</th>
						<th>{ __( 'Finished', 'terraviz' ) }</th>
						<th>{ __( 'Log', 'terraviz' ) }</th>
					</tr>
				</thead>
				<tbody>
					{ ! loading && runs.length === 0 && (
						<tr>
							<td colSpan="5">
								{ __( 'No runs yet.', 'terraviz' ) }
							</td>
						</tr>
					) }
					{ runs.map( ( run ) => (
						<tr key={ run.id }>
							<td>
								<StatusBadge status={ run.status } />
							</td>
							<td>{ run.trigger || '—' }</td>
							<td>{ shortDateTime( run.created_at ) }</td>
							<td>{ shortDateTime( run.finished_at ) }</td>
							<td>
								{ run.gha_run_id ? (
									<code>{ run.gha_run_id }</code>
								) : (
									'—'
								) }
							</td>
						</tr>
					) ) }
				</tbody>
			</table>
			{ loading && <Spinner /> }
		</div>
	);
}
