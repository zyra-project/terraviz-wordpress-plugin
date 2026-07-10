/**
 * "Suggested media" pane for the event-review screen
 * (docs/BLOG_WORDPRESS_POSTS_PLAN.md §9). Offers the curator a shortlist of
 * story-image and video candidates for an event and an "upload your own" path.
 *
 * Nothing here writes to the node directly: a picked image/video is handed up
 * via `onPick`, which sets the parent's `imageUrl` / `videoEmbedUrl` edit
 * fields — the choice is saved through the existing event-review submit. The one
 * exception is the photo upload, which must round-trip the bytes through the
 * `events/:id/image` proxy first; its returned `imageUrl` is then picked the
 * same way.
 *
 * Sources: a NASA Worldview satellite snapshot (composed client-side from the
 * event's date + location), an NHC forecast cone for named tropical storms (via
 * the `nhc-storms` proxy), and agency-YouTube videos keyed by the event title
 * (via the `youtube-search` proxy). Each is best-effort and self-hides when its
 * preview fails to load.
 */
import { __ } from '@wordpress/i18n';
import { useState, useEffect, useCallback, useRef } from '@wordpress/element';
import { Button, Spinner } from '@wordpress/components';
import {
	searchYoutubeMedia,
	listNhcStorms,
	setEventImage,
	normalizeError,
} from './api';
import { safeHttpUrl } from './safeUrl';
import {
	buildWorldviewSnapshot,
	matchNhcConeSuggestion,
	youtubeSuggestions,
	looksLikeTropical,
	looksLikeQuake,
	locationFor,
	isNocookieEmbedUrl,
	buildCommonsQueryUrl,
	parseCommonsResponse,
	buildUsgsQueryUrl,
	parseUsgsQuery,
	parseShakemapDetail,
} from './mediaSources';

const KIND_LABEL = {
	worldview: __( 'Satellite snapshot', 'terraviz' ),
	shakemap: __( 'Shake intensity', 'terraviz' ),
	nhc: __( 'Forecast cone', 'terraviz' ),
	commons: __( 'Photo', 'terraviz' ),
	youtube: __( 'Video', 'terraviz' ),
};

const MAX_UPLOAD_BYTES = 4 * 1024 * 1024;
const UPLOAD_TYPES = [ 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ];
const FETCH_TIMEOUT_MS = 6000;

// A cross-origin GET to a keyless, CORS-enabled public API (Commons / USGS),
// bounded by a timeout. These sources are fetched in the browser — the plugin's
// PHP never calls a third party (only the node), so they can't be proxied.
function fetchJsonWithTimeout( url ) {
	const controller = new window.AbortController();
	const timer = setTimeout( () => controller.abort(), FETCH_TIMEOUT_MS );
	return window
		.fetch( url, { signal: controller.signal } )
		.then( ( res ) => ( res.ok ? res.json() : null ) )
		.finally( () => clearTimeout( timer ) );
}

// Nearby public-domain/CC0 photos for a located event (Wikimedia Commons).
function fetchCommonsSuggestions( event ) {
	const point = locationFor( event.geometry );
	if ( ! point ) {
		return Promise.resolve( [] );
	}
	return fetchJsonWithTimeout( buildCommonsQueryUrl( point ) )
		.then( ( json ) => ( json ? parseCommonsResponse( json ) : [] ) )
		.catch( () => [] );
}

// The ShakeMap intensity map for an earthquake event: query the FDSN event API
// for the largest shakemapped quake near the place/date, then read the
// intensity image from its detail feed. Same-host guarded in the parsers.
function fetchShakemapSuggestion( event ) {
	if ( ! looksLikeQuake( event ) ) {
		return Promise.resolve( null );
	}
	const point = locationFor( event.geometry );
	const rawDate =
		event.occurredStart || ( event.source && event.source.publishedAt );
	if ( ! point || ! rawDate || ! Number.isFinite( Date.parse( rawDate ) ) ) {
		return Promise.resolve( null );
	}
	return fetchJsonWithTimeout( buildUsgsQueryUrl( point, rawDate ) )
		.then( ( json ) => {
			const detailUrl = json && parseUsgsQuery( json );
			if ( ! detailUrl ) {
				return null;
			}
			return fetchJsonWithTimeout( detailUrl ).then( ( detail ) => {
				const url = detail && parseShakemapDetail( detail );
				return url
					? { kind: 'shakemap', url, attribution: 'USGS ShakeMap' }
					: null;
			} );
		} )
		.catch( () => null );
}

// Read a File into bare base64 (no data-URI prefix), which the proxy forwards.
function fileToBase64( file ) {
	return new Promise( ( resolve, reject ) => {
		const reader = new window.FileReader();
		reader.onload = () => {
			const result = String( reader.result || '' );
			const comma = result.indexOf( ',' );
			resolve( comma >= 0 ? result.slice( comma + 1 ) : result );
		};
		reader.onerror = () =>
			reject( reader.error || new Error( 'read failed' ) );
		reader.readAsDataURL( file );
	} );
}

function MediaCard( { candidate, actionLabel, onUse, onFailed } ) {
	const preview = safeHttpUrl( candidate.url );
	if ( ! preview ) {
		return null;
	}
	return (
		<div
			style={ {
				border: '1px solid #dcdcde',
				borderRadius: '4px',
				overflow: 'hidden',
				width: '220px',
				background: '#fff',
			} }
		>
			<div
				style={ {
					position: 'relative',
					background: '#f6f7f7',
					aspectRatio: '16 / 9',
				} }
			>
				<img
					src={ preview }
					alt={
						candidate.title ||
						KIND_LABEL[ candidate.kind ] ||
						candidate.kind
					}
					onError={ () => onFailed( candidate.url ) }
					style={ {
						width: '100%',
						height: '100%',
						objectFit: 'cover',
						display: 'block',
					} }
				/>
				<span
					style={ {
						position: 'absolute',
						top: '6px',
						left: '6px',
						padding: '1px 6px',
						borderRadius: '9px',
						fontSize: '11px',
						fontWeight: 600,
						background: 'rgba(0,0,0,0.65)',
						color: '#fff',
					} }
				>
					{ KIND_LABEL[ candidate.kind ] || candidate.kind }
				</span>
			</div>
			<div style={ { padding: '8px' } }>
				{ candidate.title && (
					<div
						style={ {
							fontSize: '12px',
							fontWeight: 600,
							lineHeight: 1.3,
							marginBottom: '2px',
							overflow: 'hidden',
							textOverflow: 'ellipsis',
							whiteSpace: 'nowrap',
						} }
						title={ candidate.title }
					>
						{ candidate.title }
					</div>
				) }
				<div
					style={ {
						fontSize: '11px',
						color: '#646970',
						marginBottom: '6px',
					} }
				>
					{ candidate.attribution }
				</div>
				<Button
					variant="secondary"
					size="small"
					onClick={ () => onUse( candidate ) }
				>
					{ actionLabel }
				</Button>
			</div>
		</div>
	);
}

export default function MediaSuggest( { event, edits, onPick } ) {
	const [ storms, setStorms ] = useState( null );
	const [ videos, setVideos ] = useState( null );
	const [ commons, setCommons ] = useState( [] );
	const [ shakemap, setShakemap ] = useState( null );
	const [ loading, setLoading ] = useState( false );
	const [ failed, setFailed ] = useState( () => new Set() );
	const [ uploadBusy, setUploadBusy ] = useState( false );
	const [ uploadError, setUploadError ] = useState( '' );
	const fileInput = useRef( null );

	const hasImage = Boolean( ( edits.imageUrl || '' ).trim() );
	const hasVideo = Boolean( ( edits.videoEmbedUrl || '' ).trim() );
	const title = ( event.title || '' ).trim();
	const located = Boolean(
		event.geometry && ( event.geometry.point || event.geometry.boundingBox )
	);
	// Offer image sources while imageless (located events, or tropical ones the
	// NHC cone matches by name); offer the video source while videoless.
	const wantImage = ! hasImage && ( located || looksLikeTropical( event ) );
	const wantVideo = ! hasVideo && Boolean( title );

	// Fetch the async sources once per event. The Worldview snapshot needs no
	// fetch (it's a composed URL); YouTube + NHC go through the node proxies,
	// while Commons + USGS are keyless CORS APIs fetched straight from the
	// browser (the plugin's PHP never calls a third party).
	useEffect( () => {
		let active = true;
		const jobs = [];
		if ( title ) {
			jobs.push(
				searchYoutubeMedia( title )
					.then( ( res ) => {
						if ( active ) {
							setVideos(
								Array.isArray( res.videos ) ? res.videos : []
							);
						}
					} )
					.catch( () => active && setVideos( [] ) )
			);
		} else {
			setVideos( [] );
		}
		if ( looksLikeTropical( event ) ) {
			jobs.push(
				listNhcStorms()
					.then( ( res ) => {
						if ( active ) {
							setStorms(
								Array.isArray( res.activeStorms )
									? res.activeStorms
									: []
							);
						}
					} )
					.catch( () => active && setStorms( [] ) )
			);
		} else {
			setStorms( [] );
		}
		jobs.push(
			fetchCommonsSuggestions( event ).then(
				( list ) =>
					active && setCommons( Array.isArray( list ) ? list : [] )
			)
		);
		jobs.push(
			fetchShakemapSuggestion( event ).then(
				( s ) => active && setShakemap( s || null )
			)
		);
		setLoading( true );
		Promise.all( jobs ).finally( () => active && setLoading( false ) );
		return () => {
			active = false;
		};
	}, [ event, title ] );

	const markFailed = useCallback( ( url ) => {
		setFailed( ( prev ) => {
			const next = new Set( prev );
			next.add( url );
			return next;
		} );
	}, [] );

	const usable = ( candidate ) => candidate && ! failed.has( candidate.url );

	// Assemble the current candidate lists from the fetched results + the live
	// "want" flags, dropping any whose preview has already failed.
	const imageCards = [];
	if ( wantImage ) {
		const worldview = buildWorldviewSnapshot( event );
		if ( usable( worldview ) ) {
			imageCards.push( worldview );
		}
		if ( usable( shakemap ) ) {
			imageCards.push( shakemap );
		}
		const cone = matchNhcConeSuggestion( event, storms || [] );
		if ( usable( cone ) ) {
			imageCards.push( cone );
		}
		( commons || [] )
			.filter( usable )
			.forEach( ( c ) => imageCards.push( c ) );
	}
	const videoCards = wantVideo
		? youtubeSuggestions( videos || [] ).filter( usable )
		: [];

	// Fill only the image URL — leave imageAlt to the curator (attribution is
	// not alt text, and a pick must not clobber alt they already typed).
	const useImage = ( candidate ) => onPick( { imageUrl: candidate.url } );

	const useVideo = ( candidate ) => {
		// Only our own nocookie embed form may be stored — mirror the node guard.
		if ( isNocookieEmbedUrl( candidate.embedUrl ) ) {
			onPick( { videoEmbedUrl: candidate.embedUrl } );
		}
	};

	const onUploadChange = () => {
		const input = fileInput.current;
		const file = input && input.files && input.files[ 0 ];
		if ( ! file ) {
			return;
		}
		setUploadError( '' );
		if ( ! UPLOAD_TYPES.includes( file.type ) ) {
			setUploadError(
				__(
					'Unsupported image type. Use JPEG, PNG, GIF, or WebP.',
					'terraviz'
				)
			);
			input.value = '';
			return;
		}
		if ( file.size > MAX_UPLOAD_BYTES ) {
			setUploadError(
				__( 'The image is too large (max 4 MB).', 'terraviz' )
			);
			input.value = '';
			return;
		}
		setUploadBusy( true );
		fileToBase64( file )
			.then( ( dataBase64 ) =>
				setEventImage( event.id, {
					contentType: file.type,
					dataBase64,
					altText: ( edits.imageAlt || '' ).trim() || undefined,
				} )
			)
			.then( ( res ) => {
				const url = res && res.imageUrl;
				if ( url ) {
					onPick( { imageUrl: url } );
				}
			} )
			.catch( ( e ) =>
				setUploadError(
					normalizeError( e ).message ||
						__( 'Could not upload the image.', 'terraviz' )
				)
			)
			.finally( () => {
				setUploadBusy( false );
				if ( input ) {
					input.value = '';
				}
			} );
	};

	if ( ! wantImage && ! wantVideo ) {
		return null;
	}

	const nothingYet =
		! loading && imageCards.length === 0 && videoCards.length === 0;

	return (
		<div
			style={ {
				border: '1px solid #dcdcde',
				borderRadius: '4px',
				padding: '12px 16px',
				margin: '16px 0',
				background: '#f6f7f7',
			} }
		>
			<h3 style={ { marginTop: 0 } }>
				{ __( 'Suggested media', 'terraviz' ) }
			</h3>
			<p style={ { color: '#646970', marginTop: 0 } }>
				{ __(
					'Pick a story image or video for this event. Your choice fills the fields above; save the review to apply it.',
					'terraviz'
				) }
			</p>

			{ loading && <Spinner /> }

			{ ( imageCards.length > 0 || videoCards.length > 0 ) && (
				<div
					style={ {
						display: 'flex',
						flexWrap: 'wrap',
						gap: '12px',
					} }
				>
					{ imageCards.map( ( c ) => (
						<MediaCard
							key={ c.url }
							candidate={ c }
							actionLabel={ __(
								'Use as event image',
								'terraviz'
							) }
							onUse={ useImage }
							onFailed={ markFailed }
						/>
					) ) }
					{ videoCards.map( ( c ) => (
						<MediaCard
							key={ c.url }
							candidate={ c }
							actionLabel={ __(
								'Use as event video',
								'terraviz'
							) }
							onUse={ useVideo }
							onFailed={ markFailed }
						/>
					) ) }
				</div>
			) }

			{ nothingYet && (
				<p style={ { color: '#646970' } }>
					{ __(
						'No suggestions for this event. Upload your own image below.',
						'terraviz'
					) }
				</p>
			) }

			{ wantImage && (
				<div style={ { marginTop: '12px' } }>
					<Button
						variant="secondary"
						isBusy={ uploadBusy }
						disabled={ uploadBusy }
						onClick={ () =>
							fileInput.current && fileInput.current.click()
						}
					>
						{ __( 'Upload your own photo…', 'terraviz' ) }
					</Button>
					<input
						ref={ fileInput }
						type="file"
						accept={ UPLOAD_TYPES.join( ',' ) }
						onChange={ onUploadChange }
						style={ { display: 'none' } }
					/>
					{ uploadError && (
						<span
							style={ {
								color: '#d63638',
								marginLeft: '8px',
								fontSize: '13px',
							} }
						>
							{ uploadError }
						</span>
					) }
				</div>
			) }
		</div>
	);
}
