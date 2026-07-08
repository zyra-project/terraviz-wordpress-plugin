/**
 * A wp-admin native sub-tab strip (`.nav-tab-wrapper` / `.nav-tab`), used to
 * split a dashboard screen into sibling views — e.g. the Feeds screen's
 * "News feeds | Media channels", mirroring the Publisher Portal mockup.
 *
 * Rendered with buttons (not links) since these switch in-app state; the core
 * admin `nav-tab` styling applies to the class regardless of element.
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
		<div
			className="nav-tab-wrapper"
			role="tablist"
			aria-label={ __( 'Sub-sections', 'terraviz' ) }
			style={ { marginBottom: '16px' } }
		>
			{ tabs.map( ( tab ) => {
				const isActive = tab.key === active;
				return (
					<button
						key={ tab.key }
						type="button"
						role="tab"
						aria-selected={ isActive }
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
		</div>
	);
}
