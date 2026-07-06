/**
 * Guard for externally-sourced URLs that the dashboard renders as clickable
 * links (feed preview items, event source links). These come from content the
 * plugin does not control — a feed entry, an event's source — so a hostile
 * source could supply a `javascript:` or `data:` href. `@wordpress/components`
 * `ExternalLink` does not restrict the URL scheme, so callers pass a URL
 * through here first and fall back to plain text when it is not http(s).
 *
 * Returns the trimmed URL (so the rendered href carries no stray whitespace),
 * or null when it is not an http(s) string.
 *
 * @param {*} url Candidate URL (any type; only strings can pass).
 * @return {?string} The trimmed URL if it is http(s), otherwise null.
 */
export function safeHttpUrl( url ) {
	if ( typeof url !== 'string' ) {
		return null;
	}
	const trimmed = url.trim();
	return /^https?:\/\//i.test( trimmed ) ? trimmed : null;
}
