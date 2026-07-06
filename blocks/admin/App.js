/**
 * The publisher dashboard root: a top-level section nav (Datasets | Events |
 * Feeds) over three workflows. Datasets switches between the list and the
 * create/edit form; Events (publish-tier only) switches between the review
 * queue and the per-event review screen; Feeds (configure-tier only) manages
 * the RSS/EONET source connectors that generate proposed events.
 */
import { __ } from '@wordpress/i18n';
import { useState, useEffect, useCallback } from '@wordpress/element';
import { Notice } from '@wordpress/components';
import DatasetList from './DatasetList';
import DatasetForm from './DatasetForm';
import EventList from './EventList';
import EventReview from './EventReview';
import FeedList from './FeedList';
import FeedForm from './FeedForm';
import {
	listDatasets,
	publishDataset,
	retractDataset,
	deleteDataset,
	listEvents,
	listFeeds,
	updateFeed,
	deleteFeed,
	normalizeError,
} from './api';

function DatasetsSection( { boot } ) {
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
		if ( view === 'list' ) {
			refresh();
		}
	}, [ view, refresh ] );

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

function EventsSection() {
	const [ reviewing, setReviewing ] = useState( null );
	const [ filter, setFilter ] = useState( 'proposed' );
	const [ events, setEvents ] = useState( [] );
	const [ loading, setLoading ] = useState( false );
	const [ notice, setNotice ] = useState( null );

	const refresh = useCallback( () => {
		setLoading( true );
		listEvents( filter )
			.then( ( res ) =>
				setEvents( Array.isArray( res.events ) ? res.events : [] )
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
		if ( ! reviewing ) {
			refresh();
		}
	}, [ reviewing, refresh ] );

	if ( reviewing ) {
		return (
			<EventReview
				event={ reviewing }
				onReviewed={ () => setReviewing( null ) }
				onCancel={ () => setReviewing( null ) }
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
			<EventList
				events={ events }
				loading={ loading }
				filter={ filter }
				onFilter={ setFilter }
				onReview={ ( ev ) => setReviewing( ev ) }
			/>
		</div>
	);
}

function FeedsSection() {
	const [ editing, setEditing ] = useState( null );
	const [ feeds, setFeeds ] = useState( [] );
	const [ loading, setLoading ] = useState( false );
	const [ busyId, setBusyId ] = useState( null );
	const [ notice, setNotice ] = useState( null );

	const refresh = useCallback( () => {
		setLoading( true );
		listFeeds()
			.then( ( res ) =>
				setFeeds( Array.isArray( res.feeds ) ? res.feeds : [] )
			)
			.catch( ( e ) =>
				setNotice( {
					type: 'error',
					text: normalizeError( e ).message,
				} )
			)
			.finally( () => setLoading( false ) );
	}, [] );

	useEffect( () => {
		if ( ! editing ) {
			refresh();
		}
	}, [ editing, refresh ] );

	const runAction = ( promise, id ) => {
		setBusyId( id );
		setNotice( null );
		promise
			.then( () => refresh() )
			.catch( ( e ) =>
				setNotice( {
					type: 'error',
					text: normalizeError( e ).message,
				} )
			)
			.finally( () => setBusyId( null ) );
	};

	if ( editing ) {
		return (
			<FeedForm
				feed={ editing === 'new' ? {} : editing }
				onSaved={ () => setEditing( null ) }
				onCancel={ () => setEditing( null ) }
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
			<FeedList
				feeds={ feeds }
				loading={ loading }
				busyId={ busyId }
				onNew={ () => setEditing( 'new' ) }
				onEdit={ ( feed ) => setEditing( feed ) }
				onToggle={ ( feed, enabled ) =>
					runAction( updateFeed( feed.id, { enabled } ), feed.id )
				}
				onDelete={ ( feed ) => {
					const msg = __(
						'Delete this feed connector? Events it already ingested are kept.',
						'terraviz'
					);
					// eslint-disable-next-line no-alert -- a native confirm is acceptable for a destructive wp-admin action.
					if ( ! window.confirm( msg ) ) {
						return;
					}
					runAction( deleteFeed( feed.id ), feed.id );
				} }
			/>
		</div>
	);
}

export default function App( { boot } ) {
	const [ section, setSection ] = useState( 'datasets' );

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

	const showEvents = !! boot.canPublish;
	const showFeeds = !! boot.canConfigure;
	const activeSection =
		( section === 'events' && showEvents ) ||
		( section === 'feeds' && showFeeds )
			? section
			: 'datasets';

	return (
		<div>
			{ ( showEvents || showFeeds ) && (
				<h2
					className="nav-tab-wrapper"
					style={ { marginBottom: '16px' } }
				>
					<button
						type="button"
						className={ `nav-tab${
							activeSection === 'datasets'
								? ' nav-tab-active'
								: ''
						}` }
						onClick={ () => setSection( 'datasets' ) }
					>
						{ __( 'Datasets', 'terraviz' ) }
					</button>
					{ showEvents && (
						<button
							type="button"
							className={ `nav-tab${
								activeSection === 'events'
									? ' nav-tab-active'
									: ''
							}` }
							onClick={ () => setSection( 'events' ) }
						>
							{ __( 'Events', 'terraviz' ) }
						</button>
					) }
					{ showFeeds && (
						<button
							type="button"
							className={ `nav-tab${
								activeSection === 'feeds'
									? ' nav-tab-active'
									: ''
							}` }
							onClick={ () => setSection( 'feeds' ) }
						>
							{ __( 'Feeds', 'terraviz' ) }
						</button>
					) }
				</h2>
			) }

			{ activeSection === 'events' && <EventsSection /> }
			{ activeSection === 'feeds' && <FeedsSection /> }
			{ activeSection === 'datasets' && (
				<DatasetsSection boot={ boot } />
			) }
		</div>
	);
}
