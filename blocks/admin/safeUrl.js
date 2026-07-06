/**
 * Guard for externally-sourced URLs that the dashboard renders as clickable
 * links (feed preview items, event source links). These come from content the
 * plugin does not control — a feed entry, an event's source — so a hostile
 * source could supply a `javascript:` or `data:` href. `@wordpress/components`
 * `ExternalLink` does not restrict the URL scheme, so callers pass a URL
 * through here first and fall back to plain text when it is not http(s).
 *
 * @param {*} url Candidate URL (any type; only strings can pass).
 * @return {?string} The URL if it is an http(s) URL, otherwise null.
 */
export function safeHttpUrl( url ) {
	return typeof url === 'string' && /^https?:\/\//i.test( url.trim() )
		? url
		: null;
}
