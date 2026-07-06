/**
 * The events review queue: a status filter and a table of events awaiting (or
 * past) curator review. Rows open the review screen.
 */
import { __ } from '@wordpress/i18n';
import { Button, Spinner } from '@wordpress/components';

const FILTERS = [
	{ key: 'proposed', label: __( 'Proposed', 'terraviz' ) },
	{ key: 'approved', label: __( 'Approved', 'terraviz' ) },
	{ key: 'rejected', label: __( 'Rejected', 'terraviz' ) },
	{ key: 'expired', label: __( 'Expired', 'terraviz' ) },
	{ key: 'all', label: __( 'All', 'terraviz' ) },
];

function linkCountOf( ev ) {
	if ( Array.isArray( ev.links ) ) {
		return ev.links.length;
	}
	if ( Array.isArray( ev.datasetIds ) ) {
		return ev.datasetIds.length;
	}
	return 0;
}

function occurred( ev ) {
	const raw = ev.occurredStart || ( ev.source && ev.source.publishedAt );
	if ( ! raw ) {
		return '—';
	}
	const d = new Date( raw );
	return Number.isNaN( d.getTime() ) ? String( raw ) : d.toLocaleDateString();
}

export default function EventList( {
	events,
	loading,
	filter,
	onFilter,
	onReview,
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
				<div
					className="subsubsub"
					style={ { float: 'none', margin: 0 } }
				>
					{ FILTERS.map( ( f, i ) => (
						<span key={ f.key }>
							<a
								href="#terraviz-event-filter"
								onClick={ ( e ) => {
									e.preventDefault();
									onFilter( f.key );
								} }
								className={ filter === f.key ? 'current' : '' }
							>
								{ f.label }
							</a>
							{ i < FILTERS.length - 1 ? ' | ' : '' }
						</span>
					) ) }
				</div>
				{ loading && <Spinner /> }
			</div>

			<table className="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th>{ __( 'Title', 'terraviz' ) }</th>
						<th>{ __( 'Source', 'terraviz' ) }</th>
						<th>{ __( 'When', 'terraviz' ) }</th>
						<th>{ __( 'Status', 'terraviz' ) }</th>
						<th>{ __( 'Datasets', 'terraviz' ) }</th>
						<th>{ __( 'Actions', 'terraviz' ) }</th>
					</tr>
				</thead>
				<tbody>
					{ ! loading && events.length === 0 && (
						<tr>
							<td colSpan="6">
								{ __(
									'No events in this bucket.',
									'terraviz'
								) }
							</td>
						</tr>
					) }
					{ events.map( ( ev ) => {
						const linkCount = linkCountOf( ev );
						return (
							<tr key={ ev.id }>
								<td>
									<Button
										variant="link"
										onClick={ () => onReview( ev ) }
									>
										{ ev.title || ev.id }
									</Button>
								</td>
								<td>
									{ ( ev.source && ev.source.name ) || '—' }
								</td>
								<td>{ occurred( ev ) }</td>
								<td>{ ev.status || 'proposed' }</td>
								<td>{ linkCount }</td>
								<td>
									<Button
										variant="secondary"
										size="small"
										onClick={ () => onReview( ev ) }
									>
										{ __( 'Review', 'terraviz' ) }
									</Button>
								</td>
							</tr>
						);
					} ) }
				</tbody>
			</table>
		</div>
	);
}
