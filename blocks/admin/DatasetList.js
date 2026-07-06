/**
 * The dataset list view: a status filter, a table of the caller's datasets,
 * and lifecycle actions.
 */
import { __ } from '@wordpress/i18n';
import { Button, Spinner } from '@wordpress/components';
import { deriveStatus, statusLabel } from './status';

const FILTERS = [
	{ key: '', label: __( 'All', 'terraviz' ) },
	{ key: 'draft', label: __( 'Drafts', 'terraviz' ) },
	{ key: 'published', label: __( 'Published', 'terraviz' ) },
	{ key: 'retracted', label: __( 'Retracted', 'terraviz' ) },
];

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
								href="#terraviz-filter"
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
				<Button variant="primary" onClick={ onNew }>
					{ __( 'New dataset', 'terraviz' ) }
				</Button>
				{ loading && <Spinner /> }
			</div>

			<table className="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th>{ __( 'Title', 'terraviz' ) }</th>
						<th>{ __( 'Slug', 'terraviz' ) }</th>
						<th>{ __( 'Status', 'terraviz' ) }</th>
						<th>{ __( 'Actions', 'terraviz' ) }</th>
					</tr>
				</thead>
				<tbody>
					{ ! loading && datasets.length === 0 && (
						<tr>
							<td colSpan="4">
								{ __( 'No datasets yet.', 'terraviz' ) }
							</td>
						</tr>
					) }
					{ datasets.map( ( ds ) => {
						const status = deriveStatus( ds );
						const busy = busyId === ds.id;
						// Editing a published row is a publish-tier action; the
						// server enforces this too.
						const canEditRow = canPublish || status !== 'published';
						return (
							<tr key={ ds.id }>
								<td>
									{ canEditRow ? (
										<Button
											variant="link"
											onClick={ () => onEdit( ds.id ) }
										>
											{ ds.title || ds.slug || ds.id }
										</Button>
									) : (
										ds.title || ds.slug || ds.id
									) }
								</td>
								<td>{ ds.slug || '—' }</td>
								<td>{ statusLabel( status ) }</td>
								<td>
									{ canEditRow && (
										<Button
											variant="secondary"
											size="small"
											onClick={ () => onEdit( ds.id ) }
											disabled={ busy }
										>
											{ __( 'Edit', 'terraviz' ) }
										</Button>
									) }{ ' ' }
									{ canPublish && status === 'draft' && (
										<Button
											variant="secondary"
											size="small"
											onClick={ () => onPublish( ds.id ) }
											disabled={ busy }
										>
											{ __( 'Publish', 'terraviz' ) }
										</Button>
									) }
									{ canPublish && status === 'published' && (
										<Button
											variant="secondary"
											size="small"
											onClick={ () => onRetract( ds.id ) }
											disabled={ busy }
										>
											{ __( 'Retract', 'terraviz' ) }
										</Button>
									) }{ ' ' }
									{ canPublish && status !== 'published' && (
										<Button
											variant="secondary"
											size="small"
											isDestructive
											onClick={ () => onDelete( ds.id ) }
											disabled={ busy }
										>
											{ __( 'Delete', 'terraviz' ) }
										</Button>
									) }
									{ busy && <Spinner /> }
								</td>
							</tr>
						);
					} ) }
				</tbody>
			</table>
		</div>
	);
}
