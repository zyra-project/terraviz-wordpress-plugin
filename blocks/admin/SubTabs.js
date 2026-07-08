/**
 * A wp-admin native sub-tab strip (`.nav-tab-wrapper` / `.nav-tab`), used to
 * split a dashboard screen into sibling views — e.g. the Feeds screen's
 * "News feeds | Media channels", mirroring the Publisher Portal mockup.
 *
 * Rendered as a labelled `<nav>` of buttons (these switch in-app state, and the
 * core admin `nav-tab` styling applies regardless of element). We deliberately
 * use plain navigation semantics with `aria-current` on the active button
 * rather than the ARIA `tablist`/`tab`/`tabpanel` pattern — the latter would
 * require roving tabindex, arrow-key handling, and `aria-controls` panels to be
 * correct, which this simple view switch doesn't warrant.
 */
import { __ } from '@wordpress/i18n';

/**
 * @param {Object}                              props
 * @param {Array<{key: string, label: string}>} props.tabs     Tabs, in order.
 * @param {string}                              props.active   Active tab key.
 * @param {Function}                            props.onSelect Called with a key on click.
 * @return {JSX.Element} The tab strip.
 */
export default function SubTabs( { tabs, active, onSelect } ) {
	return (
		<nav
			className="nav-tab-wrapper"
			aria-label={ __( 'Sub-sections', 'terraviz' ) }
			style={ { marginBottom: '16px' } }
		>
			{ tabs.map( ( tab ) => {
				const isActive = tab.key === active;
				return (
					<button
						key={ tab.key }
						type="button"
						aria-current={ isActive ? 'page' : undefined }
						className={
							isActive ? 'nav-tab nav-tab-active' : 'nav-tab'
						}
						style={ { cursor: 'pointer' } }
						onClick={ () => onSelect( tab.key ) }
					>
						{ tab.label }
					</button>
				);
			} ) }
		</nav>
	);
}
