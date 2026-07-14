/**
 * The publisher dashboard root: a grouped left-sidebar IA (Overview · Catalog ·
 * Newsroom · Insights · Settings) that mirrors the Terraviz app's Publisher
 * Portal mockup, over the workflows the plugin has built. Datasets switches
 * between the list and the create/edit form; Events (publish-tier) switches
 * between the review queue and the per-event review screen; Feeds
 * (configure-tier) manages the RSS/EONET source connectors; Right now
 * (publish-tier) manages the singleton homepage hero pin. Sidebar items that
 * aren't built yet route to a "coming soon" placeholder so the IA is complete
 * from day one (Milestone A).
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
import Sidebar, { NAV, allowedKeys } from './Sidebar';
import Overview from './Overview';
import RightNow from './RightNow';
import MediaChannels from './MediaChannels';
import SubTabs from './SubTabs';
import Blog from './Blog';
import NodeProfile from './NodeProfile';
import Analytics from './Analytics';
import { deriveStatus } from './status';
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

function DatasetsSection( { boot, intent, onIntentConsumed } ) {
	// A one-shot `intent` of 'create' (from Overview's "New dataset" CTA) opens
	// the create form directly; it's consumed on mount so a later plain
	// navigation back to Datasets still defaults to the list.
	const [ view, setView ] = useState(
		intent === 'create' ? 'create' : 'list'
	);
	const [ filter, setFilter ] = useState( '' );
	const [ datasets, setDatasets ] = useState( [] );
	const [ loading, setLoading ] = useState( false );
	const [ busyId, setBusyId ] = useState( null );
	const [ notice, setNotice ] = useState( null );

	// Fetch the whole catalog once and filter client-side: the status stat tiles
	// need totals across every bucket (not just the filtered view), and it makes
	// switching filters instant. listDatasets() already walks all pages.
	const refresh = useCallback( () => {
		setLoading( true );
		listDatasets()
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
	}, [] );

	useEffect( () => {
		if ( view === 'list' ) {
			refresh();
		}
	}, [ view, refresh ] );

	useEffect( () => {
		if ( intent ) {
			onIntentConsumed();
		}
		// Mount-only: the create intent is read by the initial view state above;
		// clear it once so it doesn't reopen on a later visit. Deps omitted
		// intentionally.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

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

function EventsSection( { boot } ) {
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
				boot={ boot }
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
	const [ tab, setTab ] = useState( 'feeds' );
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

	// Editing a feed connector is a full-screen form; keep it above the sub-tabs.
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
			<SubTabs
				tabs={ [
					{ key: 'feeds', label: __( 'News feeds', 'terraviz' ) },
					{
						key: 'media',
						label: __( 'Media channels', 'terraviz' ),
					},
				] }
				active={ tab }
				onSelect={ setTab }
			/>
			{ tab === 'media' ? (
				<MediaChannels />
			) : (
				<>
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
							runAction(
								updateFeed( feed.id, { enabled } ),
								feed.id
							)
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
				</>
			) }
		</div>
	);
}

/**
 * The "coming soon" placeholder for a nav item that is on the roadmap but not
 * built yet. Keeps the IA complete without faking a feature.
 *
 * @param {Object} props
 * @param {string} props.sectionKey Nav key of the unbuilt section.
 * @return {JSX.Element} Placeholder panel.
 */
function ComingSoon( { sectionKey } ) {
	const item = NAV.flatMap( ( g ) => g.items ).find(
		( i ) => i.key === sectionKey
	);
	return (
		<div
			style={ {
				border: '1px dashed #c3c4c7',
				borderRadius: '4px',
				padding: '32px',
				textAlign: 'center',
				color: '#646970',
			} }
		>
			<p style={ { fontSize: '15px', margin: 0 } }>
				{ __(
					'This area is on the roadmap and not built yet.',
					'terraviz'
				) }
			</p>
			<p style={ { margin: '8px 0 0' } }>
				{ __(
					'Track progress in the plugin’s implementation plan.',
					'terraviz'
				) }
			</p>
			{ item && ! item.built && (
				<p style={ { margin: '4px 0 0', fontSize: '12px' } }>
					{ sectionKey }
				</p>
			) }
		</div>
	);
}

export default function App( { boot } ) {
	const [ section, setSection ] = useState( 'overview' );
	const [ datasetsIntent, setDatasetsIntent ] = useState( null );
	const [ summary, setSummary ] = useState( null );
	const [ summaryLoading, setSummaryLoading ] = useState( true );

	// Overview's "New dataset" CTA: jump to the Datasets section AND open its
	// create form, rather than just landing on the list.
	const openNewDataset = useCallback( () => {
		setDatasetsIntent( 'create' );
		setSection( 'datasets' );
	}, [] );
	const clearDatasetsIntent = useCallback(
		() => setDatasetsIntent( null ),
		[]
	);

	// Build the Overview figures from data the plugin already has: the caller's
	// dataset list (counted by derived lifecycle status) and the proposed-events
	// queue depth. Both failures degrade to a partial card rather than blocking.
	const loadSummary = useCallback( () => {
		setSummaryLoading( true );
		// Preserve the specific failure message (e.g. listDatasets's localized
		// "too many pages, please reload" error) rather than discarding it for a
		// generic one. Assigned in the catch, which settles before Promise.all.
		let datasetsError = null;
		const datasetsP = listDatasets()
			.then( ( res ) =>
				Array.isArray( res.datasets ) ? res.datasets : []
			)
			.catch( ( e ) => {
				datasetsError = normalizeError( e ).message;
				return null;
			} );
		const eventsP = boot.canPublish
			? listEvents( 'proposed' )
					.then( ( res ) =>
						Array.isArray( res.events ) ? res.events.length : 0
					)
					.catch( () => null )
			: Promise.resolve( null );

		Promise.all( [ datasetsP, eventsP ] ).then(
			( [ datasets, proposed ] ) => {
				const counts = {
					draft: 0,
					published: 0,
					retracted: 0,
					total: 0,
				};
				let error = null;
				if ( datasets === null ) {
					error =
						datasetsError ||
						__(
							'Could not load your datasets — some figures may be unavailable.',
							'terraviz'
						);
				} else {
					datasets.forEach( ( d ) => {
						counts[ deriveStatus( d ) ] += 1;
					} );
					counts.total = datasets.length;
				}
				setSummary( {
					datasets: counts,
					proposedEvents: proposed,
					error,
				} );
				setSummaryLoading( false );
			}
		);
	}, [ boot.canPublish ] );

	// Load on mount (default section is Overview) and again whenever the user
	// returns to Overview, so the counts reflect edits made elsewhere.
	useEffect( () => {
		if ( section === 'overview' ) {
			loadSummary();
		}
	}, [ section, loadSummary ] );

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

	const keys = allowedKeys( boot );
	const activeSection = keys.includes( section ) ? section : 'overview';
	const activeItem = NAV.flatMap( ( g ) => g.items ).find(
		( i ) => i.key === activeSection
	);

	const renderContent = () => {
		switch ( activeSection ) {
			case 'overview':
				return (
					<Overview
						boot={ boot }
						summary={ summary }
						loading={ summaryLoading }
						onNavigate={ setSection }
						onNewDataset={ openNewDataset }
					/>
				);
			case 'datasets':
				return (
					<DatasetsSection
						boot={ boot }
						intent={ datasetsIntent }
						onIntentConsumed={ clearDatasetsIntent }
					/>
				);
			case 'events':
				return <EventsSection boot={ boot } />;
			case 'feeds':
				return <FeedsSection />;
			case 'right-now':
				return <RightNow boot={ boot } />;
			case 'blog':
				return <Blog boot={ boot } />;
			case 'node-profile':
				return <NodeProfile />;
			case 'analytics':
				return <Analytics />;
			default:
				return <ComingSoon sectionKey={ activeSection } />;
		}
	};

	return (
		<div style={ { display: 'flex', alignItems: 'flex-start' } }>
			<Sidebar
				active={ activeSection }
				boot={ boot }
				badges={ {
					events: ( summary && summary.proposedEvents ) || 0,
				} }
				onSelect={ setSection }
			/>
			<div style={ { flex: 1, minWidth: 0 } }>
				{ activeItem && (
					<h2 style={ { marginTop: 0 } }>{ activeItem.label }</h2>
				) }
				{ renderContent() }
			</div>
		</div>
	);
}
