/**
 * The publisher dashboard root: switches between the dataset list and the
 * create/edit form, and owns the list-level lifecycle actions.
 */
import { __ } from '@wordpress/i18n';
import { useState, useEffect, useCallback } from '@wordpress/element';
import { Notice } from '@wordpress/components';
import DatasetList from './DatasetList';
import DatasetForm from './DatasetForm';
import {
	listDatasets,
	publishDataset,
	retractDataset,
	deleteDataset,
	normalizeError,
} from './api';

export default function App( { boot } ) {
	const [ view, setView ] = useState( 'list' );
	const [ filter, setFilter ] = useState( '' );
	const [ datasets, setDatasets ] = useState( [] );
	const [ loading, setLoading ] = useState( false );
	const [ busyId, setBusyId ] = useState( null );
	const [ notice, setNotice ] = useState( null );

	const refresh = useCallback( () => {
		setLoading( true );
		listDatasets( filter || undefined )
			.then( ( res ) =>
				setDatasets( Array.isArray( res.datasets ) ? res.datasets : [] )
			)
			.catch( ( e ) =>
				setNotice( {
					type: 'error',
					text: normalizeError( e ).message,
				} )
			)
			.finally( () => setLoading( false ) );
	}, [ filter ] );

	useEffect( () => {
		if ( view === 'list' && boot.credentialConfigured ) {
			refresh();
		}
	}, [ view, refresh, boot.credentialConfigured ] );

	const runAction = ( fn, id, confirmText ) => {
		// eslint-disable-next-line no-alert -- a native confirm is acceptable for a destructive wp-admin action.
		if ( confirmText && ! window.confirm( confirmText ) ) {
			return;
		}
		setBusyId( id );
		setNotice( null );
		fn( id )
			.then( () => refresh() )
			.catch( ( e ) =>
				setNotice( {
					type: 'error',
					text: normalizeError( e ).message,
				} )
			)
			.finally( () => setBusyId( null ) );
	};

	if ( ! boot.credentialConfigured ) {
		return (
			<Notice status="warning" isDismissible={ false }>
				{ __(
					'No Terraviz service token is configured yet, so publishing is unavailable.',
					'terraviz'
				) }{ ' ' }
				<a href={ boot.settingsUrl }>
					{ __(
						'Configure it under Settings → Terraviz.',
						'terraviz'
					) }
				</a>
			</Notice>
		);
	}

	if ( view === 'create' || ( view && view.editId ) ) {
		return (
			<DatasetForm
				id={ view.editId || null }
				canPublish={ !! boot.canPublish }
				onSaved={ () => {} }
				onCancel={ () => setView( 'list' ) }
			/>
		);
	}

	return (
		<div>
			{ notice && (
				<Notice
					status={ notice.type }
					onRemove={ () => setNotice( null ) }
				>
					{ notice.text }
				</Notice>
			) }
			<DatasetList
				datasets={ datasets }
				loading={ loading }
				filter={ filter }
				canPublish={ !! boot.canPublish }
				busyId={ busyId }
				onFilter={ setFilter }
				onNew={ () => setView( 'create' ) }
				onEdit={ ( id ) => setView( { editId: id } ) }
				onPublish={ ( id ) => runAction( publishDataset, id ) }
				onRetract={ ( id ) =>
					runAction(
						retractDataset,
						id,
						__( 'Retract this published dataset?', 'terraviz' )
					)
				}
				onDelete={ ( id ) =>
					runAction(
						deleteDataset,
						id,
						__(
							'Permanently delete this dataset? This cannot be undone.',
							'terraviz'
						)
					)
				}
			/>
		</div>
	);
}
