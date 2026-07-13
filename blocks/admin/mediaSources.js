/**
 * Pure candidate-builders for the event-review "Suggested media" pane
 * (docs/BLOG_WORDPRESS_POSTS_PLAN.md §9). Ported from the Terraviz app's
 * `media-suggest.ts` so the plugin composes the same public URLs the node
 * would. Nothing here fetches or writes — each returns a plain descriptor the
 * pane renders; the curator's pick is written through the existing event-review
 * edit path (`imageUrl` / `videoEmbedUrl`).
 *
 * Two same-origin proxies feed the fetched sources (`youtube-search`,
 * `nhc-storms`); everything else is composed from public, keyless URLs (a
 * Worldview snapshot is itself an image URL — no request needed to *suggest*).
 */

/** Daily global true-color — the closest thing to "what you'd have seen". */
export const WORLDVIEW_SNAPSHOT_LAYER =
	'MODIS_Terra_CorrectedReflectance_TrueColor';
const SNAPSHOT_HOST = 'https://wvs.earthdata.nasa.gov/api/v1/snapshot';
const SNAPSHOT_WIDTH = 768;
/** Padding around a point event, degrees — wide enough for context. */
const POINT_PAD_DEG = 5;
/** Minimum bbox span, degrees — a very tight bbox snapshots to noise. */
const MIN_SPAN_DEG = 2;

const clampLat = ( v ) => Math.max( -90, Math.min( 90, v ) );
const clampLon = ( v ) => Math.max( -180, Math.min( 180, v ) );

/**
 * Widen `[lo, hi]` to at least `span`, sliding the window to stay inside
 * `[boundLo, boundHi]` rather than clamping each edge independently — a
 * degenerate box at a pole or the dateline still comes out full-span.
 *
 * @param {number} lo      Low edge.
 * @param {number} hi      High edge.
 * @param {number} span    Minimum span.
 * @param {number} boundLo Lower bound.
 * @param {number} boundHi Upper bound.
 * @return {[number, number]} The widened, in-bounds range.
 */
function growSpan( lo, hi, span, boundLo, boundHi ) {
	if ( hi - lo >= span ) {
		return [ lo, hi ];
	}
	const mid = ( lo + hi ) / 2;
	let nextLo = mid - span / 2;
	let nextHi = mid + span / 2;
	if ( nextLo < boundLo ) {
		nextHi += boundLo - nextLo;
		nextLo = boundLo;
	}
	if ( nextHi > boundHi ) {
		nextLo -= nextHi - boundHi;
		nextHi = boundHi;
	}
	return [ Math.max( boundLo, nextLo ), Math.min( boundHi, nextHi ) ];
}

/**
 * Resolve an event geometry to a plain min<max snapshot box `{ n, s, w, e }`,
 * or null when there's nothing to snapshot. A bbox is grown to a visible span
 * and de-wrapped across the antimeridian; a point is padded to a window.
 *
 * @param {Object} geo Event geometry (`boundingBox` or `point`).
 * @return {?{n: number, s: number, w: number, e: number}} The box, or null.
 */
function snapshotBox( geo ) {
	const bbox = geo.boundingBox;
	const point = geo.point;
	if ( bbox ) {
		let n = clampLat( bbox.n );
		let s = clampLat( bbox.s );
		let w = clampLon( bbox.w );
		let e = clampLon( bbox.e );
		// The catalog encodes an antimeridian-crossing box as w > e. The snapshot
		// API wants a plain min<max range, so keep the wider dateline half.
		if ( w > e ) {
			if ( 180 - w >= e + 180 ) {
				e = 180;
			} else {
				w = -180;
			}
		}
		[ s, n ] = growSpan( s, n, MIN_SPAN_DEG, -90, 90 );
		[ w, e ] = growSpan( w, e, MIN_SPAN_DEG, -180, 180 );
		return { n, s, w, e };
	}
	if ( point ) {
		return {
			n: clampLat( point.lat + POINT_PAD_DEG ),
			s: clampLat( point.lat - POINT_PAD_DEG ),
			w: clampLon( point.lon - POINT_PAD_DEG ),
			e: clampLon( point.lon + POINT_PAD_DEG ),
		};
	}
	return null;
}

/**
 * Build the NASA Worldview snapshot candidate for an event, or null when the
 * event lacks what a snapshot needs (a date and a location). The returned `url`
 * is a real satellite image for that place and day — usable directly as the
 * event image.
 *
 * @param {Object} event Review event (`occurredStart`/`source`, `geometry`).
 * @return {?{kind: string, url: string, attribution: string}} Candidate or null.
 */
export function buildWorldviewSnapshot( event ) {
	const geo = event.geometry || {};
	const rawDate =
		event.occurredStart || ( event.source && event.source.publishedAt );
	const ms = rawDate ? Date.parse( rawDate ) : NaN;
	if ( ! Number.isFinite( ms ) ) {
		return null;
	}

	const box = snapshotBox( geo );
	if ( ! box ) {
		return null;
	}
	const { n, s, w, e } = box;
	if ( n <= s || e <= w ) {
		return null;
	}

	const date = new Date( ms ).toISOString().slice( 0, 10 );
	const height = Math.max(
		192,
		Math.min(
			SNAPSHOT_WIDTH,
			Math.round( ( SNAPSHOT_WIDTH * ( n - s ) ) / ( e - w ) )
		)
	);
	const params = new URLSearchParams( {
		REQUEST: 'GetSnapshot',
		TIME: date,
		// EPSG:4326 axis order: lat_min, lon_min, lat_max, lon_max.
		BBOX: `${ s },${ w },${ n },${ e }`,
		CRS: 'EPSG:4326',
		LAYERS: WORLDVIEW_SNAPSHOT_LAYER,
		WIDTH: String( SNAPSHOT_WIDTH ),
		HEIGHT: String( height ),
		FORMAT: 'image/jpeg',
	} );
	return {
		kind: 'worldview',
		url: `${ SNAPSHOT_HOST }?${ params.toString() }`,
		attribution: 'NASA Worldview / GIBS',
	};
}

/**
 * The event's representative location for a nearby search: the pin when there
 * is one, else the bbox centre (antimeridian-aware — a `w > e` box wraps).
 *
 * @param {Object} geometry Event geometry (`point` or `boundingBox`).
 * @return {?{lat: number, lon: number}} The location, or null.
 */
export function locationFor( geometry ) {
	const geo = geometry || {};
	if ( geo.point ) {
		return {
			lat: clampLat( geo.point.lat ),
			lon: clampLon( geo.point.lon ),
		};
	}
	const bbox = geo.boundingBox;
	if ( ! bbox ) {
		return null;
	}
	const lat = clampLat( ( bbox.n + bbox.s ) / 2 );
	const w = clampLon( bbox.w );
	const e = clampLon( bbox.e );
	const span = w > e ? e + 360 - w : e - w;
	let lon = w + span / 2;
	if ( lon > 180 ) {
		lon -= 360;
	}
	return { lat, lon };
}

// --- Wikimedia Commons nearby public-domain photos ---------------------------

const COMMONS_API = 'https://commons.wikimedia.org/w/api.php';
const COMMONS_RADIUS_M = 10000;
const COMMONS_LIMIT = 3;
const COMMONS_THUMB_WIDTH = 1024;
/** No attribution obligation: the stored `image_url` can't carry one. */
const FREE_LICENSE_RE = /public domain|cc0/i;

/**
 * The Commons geosearch query URL for a point (anonymous CORS via `origin=*`).
 *
 * @param {{lat: number, lon: number}} point Search centre.
 * @return {string} The API URL.
 */
export function buildCommonsQueryUrl( point ) {
	const params = new URLSearchParams( {
		action: 'query',
		format: 'json',
		origin: '*',
		generator: 'geosearch',
		ggscoord: `${ point.lat }|${ point.lon }`,
		ggsradius: String( COMMONS_RADIUS_M ),
		ggslimit: '20',
		ggsnamespace: '6',
		prop: 'imageinfo',
		iiprop: 'url|mime|extmetadata',
		iiurlwidth: String( COMMONS_THUMB_WIDTH ),
	} );
	return `${ COMMONS_API }?${ params.toString() }`;
}

/**
 * Map a Commons API response to `commons` suggestions: raster images under a
 * public-domain / CC0 license only (the stored URL carries no attribution),
 * nearest-first (by generator `index`, since `pages` is pageid-keyed), capped
 * to the shortlist. The sized thumb is required — the full original can be huge.
 *
 * @param {Object} json Parsed Commons API response.
 * @return {Array} Candidate descriptors.
 */
export function parseCommonsResponse( json ) {
	const pages = json && json.query && json.query.pages;
	if ( ! pages || typeof pages !== 'object' ) {
		return [];
	}
	const ordered = Object.values( pages ).sort(
		( a, b ) =>
			( a && Number.isFinite( a.index )
				? a.index
				: Number.MAX_SAFE_INTEGER ) -
			( b && Number.isFinite( b.index )
				? b.index
				: Number.MAX_SAFE_INTEGER )
	);
	const out = [];
	for ( const page of ordered ) {
		const info = page && page.imageinfo && page.imageinfo[ 0 ];
		if ( ! info ) {
			continue;
		}
		if (
			typeof info.mime !== 'string' ||
			! info.mime.startsWith( 'image/' )
		) {
			continue;
		}
		const license =
			info.extmetadata &&
			info.extmetadata.LicenseShortName &&
			info.extmetadata.LicenseShortName.value;
		if (
			typeof license !== 'string' ||
			! FREE_LICENSE_RE.test( license )
		) {
			continue;
		}
		const url = info.thumburl;
		if (
			typeof url !== 'string' ||
			! /^https?:\/\//i.test( url ) ||
			url.length > 2048
		) {
			continue;
		}
		out.push( {
			kind: 'commons',
			url,
			attribution: 'Wikimedia Commons',
		} );
		if ( out.length >= COMMONS_LIMIT ) {
			break;
		}
	}
	return out;
}

// --- USGS ShakeMap intensity maps (earthquake events) ------------------------

const USGS_QUERY_API = 'https://earthquake.usgs.gov/fdsnws/event/1/query';
/** Only ever follow detail links back to the same host we queried. */
const USGS_HOST = 'earthquake.usgs.gov';
const USGS_WINDOW_DAYS = 2;
const USGS_RADIUS_KM = 300;
const USGS_MIN_MAGNITUDE = 4;

/**
 * Does this event read like an earthquake?
 *
 * @param {Object} event Review event (`title`/`summary`/`keywords`).
 * @return {boolean} True when the text names a seismic event.
 */
export function looksLikeQuake( event ) {
	const text = `${ event.title || '' } ${ event.summary || '' } ${ (
		event.keywords || []
	).join( ' ' ) }`;
	return /\b(earthquake|quake|seismic|tremor|aftershock)\b/i.test( text );
}

/**
 * The FDSN event query for "the largest shakemapped quake near this place
 * around this date" (keyless, CORS-enabled).
 *
 * @param {{lat: number, lon: number}} point   Event location.
 * @param {string}                     dateIso Event date (ISO).
 * @return {string} The API URL.
 */
export function buildUsgsQueryUrl( point, dateIso ) {
	const ms = Date.parse( dateIso );
	const day = 24 * 60 * 60 * 1000;
	const params = new URLSearchParams( {
		format: 'geojson',
		starttime: new Date( ms - USGS_WINDOW_DAYS * day )
			.toISOString()
			.slice( 0, 10 ),
		endtime: new Date( ms + USGS_WINDOW_DAYS * day )
			.toISOString()
			.slice( 0, 10 ),
		latitude: String( clampLat( point.lat ) ),
		longitude: String( clampLon( point.lon ) ),
		maxradiuskm: String( USGS_RADIUS_KM ),
		minmagnitude: String( USGS_MIN_MAGNITUDE ),
		producttype: 'shakemap',
		orderby: 'magnitude',
		limit: '1',
	} );
	return `${ USGS_QUERY_API }?${ params.toString() }`;
}

// Only same-host http(s) URLs may be followed / stored.
function sameHostUsgsUrl( raw ) {
	if ( typeof raw !== 'string' || raw.length > 2048 ) {
		return null;
	}
	let u;
	try {
		u = new URL( raw );
	} catch ( err ) {
		return null;
	}
	return ( u.protocol === 'https:' || u.protocol === 'http:' ) &&
		u.hostname === USGS_HOST
		? raw
		: null;
}

/**
 * Pull the matched quake's detail-feed URL out of the FDSN query response. Only
 * a same-host http(s) URL passes.
 *
 * @param {Object} json Parsed FDSN query response.
 * @return {?string} The detail-feed URL, or null.
 */
export function parseUsgsQuery( json ) {
	const features = json && json.features;
	const detail =
		Array.isArray( features ) &&
		features[ 0 ] &&
		features[ 0 ].properties &&
		features[ 0 ].properties.detail;
	return sameHostUsgsUrl( detail );
}

/**
 * Pull the ShakeMap intensity image out of the detail feed. Same-host
 * enforcement: this URL can become the stored event image, so a third-party URL
 * in the upstream feed must never pass through.
 *
 * @param {Object} json Parsed detail-feed response.
 * @return {?string} The intensity-image URL, or null.
 */
export function parseShakemapDetail( json ) {
	const contents =
		json &&
		json.properties &&
		json.properties.products &&
		json.properties.products.shakemap &&
		json.properties.products.shakemap[ 0 ] &&
		json.properties.products.shakemap[ 0 ].contents;
	if ( ! contents ) {
		return null;
	}
	const jpg = contents[ 'download/intensity.jpg' ];
	const png = contents[ 'download/intensity.png' ];
	const url = ( jpg && jpg.url ) || ( png && png.url );
	return sameHostUsgsUrl( url );
}

/**
 * Does this event read like a tropical cyclone?
 *
 * @param {Object} event Review event (`title`/`summary`/`keywords`).
 * @return {boolean} True when the text names a tropical system.
 */
export function looksLikeTropical( event ) {
	const text = `${ event.title || '' } ${ event.summary || '' } ${ (
		event.keywords || []
	).join( ' ' ) }`;
	return /\b(hurricane|typhoon|cyclone|tropical\s+(storm|depression))\b/i.test(
		text
	);
}

/**
 * The public 5-day forecast-cone graphic for an active storm id
 * (`al062023` → `storm_graphics/AT06/AL062023_…_sm2.png`). Null for a malformed
 * id. If NHC retires the graphic the preview 404s and the card removes itself.
 *
 * @param {string} stormId NHC storm id.
 * @return {?string} Cone graphic URL, or null.
 */
export function buildNhcConeUrl( stormId ) {
	const m = /^(al|ep|cp)(\d{2})(\d{4})$/i.exec( stormId || '' );
	if ( ! m ) {
		return null;
	}
	const dir = { al: 'AT', ep: 'EP', cp: 'CP' }[ m[ 1 ].toLowerCase() ];
	return `https://www.nhc.noaa.gov/storm_graphics/${ dir }${
		m[ 2 ]
	}/${ stormId.toUpperCase() }_5day_cone_with_line_and_wind_sm2.png`;
}

const escapeRe = ( s ) => s.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' );

/**
 * Match an active storm whose NAME appears as a word in the event title
 * ("Hurricane Delta strengthens" ↔ "Delta") and build its cone candidate. Null
 * when the event isn't tropical, has no title, or nothing matches.
 *
 * @param {Object} event        Review event.
 * @param {Array}  activeStorms `[{ id, name }]` from the nhc-storms proxy.
 * @return {?{kind: string, url: string, attribution: string}} Candidate or null.
 */
export function matchNhcConeSuggestion( event, activeStorms ) {
	if ( ! looksLikeTropical( event ) ) {
		return null;
	}
	const title = event.title || '';
	if ( ! title || ! Array.isArray( activeStorms ) ) {
		return null;
	}
	for ( const storm of activeStorms ) {
		if (
			! storm ||
			typeof storm.id !== 'string' ||
			typeof storm.name !== 'string'
		) {
			continue;
		}
		// "Two"-style numerals stay; single letters would false-match.
		if ( storm.name.length < 3 ) {
			continue;
		}
		if (
			! new RegExp( `\\b${ escapeRe( storm.name ) }\\b`, 'i' ).test(
				title
			)
		) {
			continue;
		}
		const url = buildNhcConeUrl( storm.id );
		if ( ! url ) {
			continue;
		}
		return {
			kind: 'nhc',
			url,
			attribution: 'NOAA / National Hurricane Center',
		};
	}
	return null;
}

// YouTube's mid-res thumbnail for a video id — the card preview only.
function youtubeThumbUrl( videoId ) {
	return `https://i.ytimg.com/vi/${ encodeURIComponent(
		videoId
	) }/hqdefault.jpg`;
}

// The nocookie embed URL stored as the event's `video_embed_url`.
function youtubeEmbedUrl( videoId ) {
	return `https://www.youtube-nocookie.com/embed/${ videoId }`;
}

/**
 * Client mirror of the node's embed-host guard: only our own
 * `youtube-nocookie.com/embed/{id}` form may be stored/framed.
 *
 * @param {string} raw Candidate embed URL.
 * @return {boolean} True when `raw` is a valid nocookie embed URL.
 */
export function isNocookieEmbedUrl( raw ) {
	if ( ! raw ) {
		return false;
	}
	let u;
	try {
		u = new URL( raw );
	} catch ( err ) {
		return false;
	}
	return (
		u.protocol === 'https:' &&
		u.hostname === 'www.youtube-nocookie.com' &&
		/^\/embed\/[\w-]{6,20}$/.test( u.pathname )
	);
}

/**
 * Turn a `youtube-search` response's `videos` into `youtube` candidates: `url`
 * is the thumbnail preview, `embedUrl` is the nocookie player the pick stores.
 * Skips any video whose id isn't a plausible YouTube id.
 *
 * @param {Array} videos `[{ videoId, title, channelName }]` from the proxy.
 * @return {Array} Candidate descriptors.
 */
export function youtubeSuggestions( videos ) {
	if ( ! Array.isArray( videos ) ) {
		return [];
	}
	const out = [];
	for ( const v of videos ) {
		if (
			! v ||
			typeof v.videoId !== 'string' ||
			! /^[\w-]{6,20}$/.test( v.videoId )
		) {
			continue;
		}
		out.push( {
			kind: 'youtube',
			url: youtubeThumbUrl( v.videoId ),
			embedUrl: youtubeEmbedUrl( v.videoId ),
			attribution:
				typeof v.channelName === 'string' && v.channelName
					? v.channelName
					: 'YouTube',
			title: typeof v.title === 'string' && v.title ? v.title : '',
		} );
	}
	return out;
}
