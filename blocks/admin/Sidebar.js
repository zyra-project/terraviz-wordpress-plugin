/**
 * The publisher-dashboard sidebar: the grouped left-nav that mirrors the
 * Terraviz app's Publisher Portal IA (Overview · Catalog · Newsroom · Insights ·
 * Settings). It replaces the old flat `Datasets | Events | Feeds` tab strip.
 *
 * Milestone A lays out the *whole* IA up front; items with `built: false` route
 * to a "coming soon" placeholder until a later milestone fills the slot, so the
 * navigation never grows or reshuffles under the user as features land.
 */
import { __ } from '@wordpress/i18n';

/**
 * Navigation model. `tier` gates an item against the caller's capabilities:
 *  - 'draft'     — always visible (the dashboard itself requires the draft tier)
 *  - 'publish'   — requires `boot.canPublish`
 *  - 'configure' — requires `boot.canConfigure`
 *
 * Tiers match the eventual REST gate for each area, so a draft-tier user never
 * sees a nav item they could not use once it ships.
 *
 * @type {Array<{group: (string|null), items: Array<{key: string, label: string, tier: string, built: boolean}>}>}
 */
export const NAV = [
	{
		group: null,
		items: [
			{
				key: 'overview',
				label: __( 'Overview', 'terraviz' ),
				tier: 'draft',
				built: true,
			},
		],
	},
	{
		group: __( 'Catalog', 'terraviz' ),
		items: [
			{
				key: 'datasets',
				label: __( 'Datasets', 'terraviz' ),
				tier: 'draft',
				built: true,
			},
			{
				key: 'workflows',
				label: __( 'Workflows', 'terraviz' ),
				tier: 'configure',
				built: true,
			},
			{
				key: 'import',
				label: __( 'Import', 'terraviz' ),
				tier: 'configure',
				built: false,
			},
		],
	},
	{
		group: __( 'Newsroom', 'terraviz' ),
		items: [
			{
				key: 'feeds',
				label: __( 'Feeds', 'terraviz' ),
				tier: 'configure',
				built: true,
			},
			{
				key: 'events',
				label: __( 'Events', 'terraviz' ),
				tier: 'publish',
				built: true,
			},
			{
				key: 'right-now',
				label: __( 'Right now', 'terraviz' ),
				tier: 'publish',
				built: true,
			},
			{
				key: 'blog',
				label: __( 'Blog', 'terraviz' ),
				tier: 'publish',
				built: true,
			},
			{
				key: 'tours',
				label: __( 'Tours', 'terraviz' ),
				tier: 'publish',
				built: true,
			},
		],
	},
	{
		group: __( 'Insights', 'terraviz' ),
		items: [
			{
				key: 'analytics',
				label: __( 'Analytics', 'terraviz' ),
				tier: 'publish',
				built: true,
			},
			{
				key: 'feedback',
				label: __( 'Feedback', 'terraviz' ),
				tier: 'publish',
				built: true,
			},
		],
	},
	{
		group: __( 'Settings', 'terraviz' ),
		items: [
			{
				key: 'node-profile',
				label: __( 'Node profile', 'terraviz' ),
				tier: 'configure',
				built: true,
			},
			{
				key: 'team',
				label: __( 'Team', 'terraviz' ),
				tier: 'configure',
				built: false,
			},
		],
	},
];

/**
 * Whether a nav item's tier is permitted for the current caller.
 *
 * @param {string} tier Item tier ('draft'|'publish'|'configure').
 * @param {Object} boot Boot config (`canPublish`, `canConfigure`).
 * @return {boolean} True when the item should be shown.
 */
export function tierAllowed( tier, boot ) {
	if ( tier === 'publish' ) {
		return !! boot.canPublish;
	}
	if ( tier === 'configure' ) {
		return !! boot.canConfigure;
	}
	return true;
}

/**
 * The flat list of nav item keys visible to the current caller, in nav order.
 * Used to validate/normalise the active section.
 *
 * @param {Object} boot Boot config.
 * @return {string[]} Allowed section keys.
 */
export function allowedKeys( boot ) {
	return NAV.flatMap( ( g ) => g.items )
		.filter( ( item ) => tierAllowed( item.tier, boot ) )
		.map( ( item ) => item.key );
}

const groupHeadingStyle = {
	margin: '18px 0 4px',
	padding: '0 12px',
	fontSize: '11px',
	fontWeight: 600,
	letterSpacing: '0.05em',
	textTransform: 'uppercase',
	color: '#646970',
};

function itemStyle( active ) {
	return {
		display: 'flex',
		alignItems: 'center',
		justifyContent: 'space-between',
		width: '100%',
		padding: '7px 12px',
		border: 0,
		borderRadius: '2px',
		background: active ? '#f0f0f1' : 'transparent',
		boxShadow: active ? 'inset 3px 0 0 #2271b1' : 'none',
		color: active ? '#2271b1' : '#1d2327',
		fontWeight: active ? 600 : 400,
		fontSize: '14px',
		textAlign: 'left',
		cursor: 'pointer',
	};
}

const badgeStyle = {
	display: 'inline-block',
	minWidth: '18px',
	padding: '0 6px',
	borderRadius: '9px',
	background: '#d63638',
	color: '#fff',
	fontSize: '11px',
	lineHeight: '18px',
	textAlign: 'center',
	fontWeight: 600,
};

/**
 * The sidebar nav.
 *
 * @param {Object}                 props
 * @param {string}                 props.active   Active section key.
 * @param {Object}                 props.boot     Boot config.
 * @param {Object<string, number>} [props.badges] Optional count badges keyed by section (e.g. `{ events: 8 }`).
 * @param {Function}               props.onSelect Called with a section key on click.
 * @return {JSX.Element} The nav element.
 */
export default function Sidebar( { active, boot, badges = {}, onSelect } ) {
	return (
		<nav
			aria-label={ __( 'Publisher sections', 'terraviz' ) }
			style={ {
				flex: '0 0 200px',
				alignSelf: 'flex-start',
				borderRight: '1px solid #dcdcde',
				paddingRight: '12px',
				marginRight: '20px',
			} }
		>
			{ NAV.map( ( section, gi ) => {
				const items = section.items.filter( ( item ) =>
					tierAllowed( item.tier, boot )
				);
				if ( items.length === 0 ) {
					return null;
				}
				return (
					<div key={ section.group || `g${ gi }` }>
						{ section.group && (
							<div style={ groupHeadingStyle }>
								{ section.group }
							</div>
						) }
						<ul
							style={ {
								margin: 0,
								padding: 0,
								listStyle: 'none',
							} }
						>
							{ items.map( ( item ) => {
								const isActive = item.key === active;
								const badge = badges[ item.key ];
								return (
									<li
										key={ item.key }
										style={ { margin: '1px 0' } }
									>
										<button
											type="button"
											aria-current={
												isActive ? 'page' : undefined
											}
											style={ itemStyle( isActive ) }
											onClick={ () =>
												onSelect( item.key )
											}
										>
											<span>
												{ item.label }
												{ ! item.built && (
													<span
														style={ {
															marginLeft: '6px',
															fontSize: '10px',
															color: '#646970',
															fontWeight: 400,
														} }
													>
														{ __(
															'· soon',
															'terraviz'
														) }
													</span>
												) }
											</span>
											{ badge > 0 && (
												<span style={ badgeStyle }>
													{ badge }
												</span>
											) }
										</button>
									</li>
								);
							} ) }
						</ul>
					</div>
				);
			} ) }
		</nav>
	);
}
