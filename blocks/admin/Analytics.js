/**
 * Analytics — the Insights area (Milestone C). A read-only facade over the node's
 * typed rollup endpoint (`GET /publish/analytics?section=…`): a shared range +
 * environment filter above a sub-tab strip that mirrors the Terraviz app's
 * analytics scenes. Each section owns its fetch and its form; the node owns the
 * numbers and validates every parameter, so this view carries no write path.
 *
 * Section shapes and choices follow the plugin's charting conventions — stat
 * tiles for totals, single-hue horizontal bars for magnitude-by-identity,
 * sparklines for time series, plain tables for the rest (see `./analytics/`).
 */
import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { SelectControl } from '@wordpress/components';
import SubTabs from './SubTabs';
import { MUTED } from './analytics/primitives';
import {
	OverviewSection,
	DatasetsSection,
	SpatialSection,
	EngagementSection,
	PerformanceSection,
	OrbitSection,
	ResearchSection,
} from './analytics/sections';

const RANGES = [
	{ label: __( 'Last 7 days', 'terraviz' ), value: '7' },
	{ label: __( 'Last 30 days', 'terraviz' ), value: '30' },
	{ label: __( 'Last 90 days', 'terraviz' ), value: '90' },
	{ label: __( 'Last year', 'terraviz' ), value: '365' },
];

const ENVIRONMENTS = [
	{ label: __( 'Production', 'terraviz' ), value: 'production' },
	{ label: __( 'Preview', 'terraviz' ), value: 'preview' },
];

// Tab keys are the node's section names; labels mirror the Terraviz app (its
// "Engagement" / "Performance" scenes are the `funnel` / `perf` sections).
const TABS = [
	{ key: 'overview', label: __( 'Overview', 'terraviz' ) },
	{ key: 'datasets', label: __( 'Datasets', 'terraviz' ) },
	{ key: 'spatial', label: __( 'Spatial', 'terraviz' ) },
	{ key: 'funnel', label: __( 'Engagement', 'terraviz' ) },
	{ key: 'perf', label: __( 'Performance', 'terraviz' ) },
	{ key: 'orbit', label: __( 'Orbit', 'terraviz' ) },
	{ key: 'research', label: __( 'Research', 'terraviz' ) },
];

/**
 * @return {JSX.Element} The analytics area.
 */
export default function Analytics() {
	const [ tab, setTab ] = useState( 'overview' );
	const [ days, setDays ] = useState( '30' );
	const [ environment, setEnvironment ] = useState( 'production' );

	const filters = { days, environment };
	const section = ( () => {
		switch ( tab ) {
			case 'datasets':
				return <DatasetsSection { ...filters } />;
			case 'spatial':
				return <SpatialSection { ...filters } />;
			case 'funnel':
				return <EngagementSection { ...filters } />;
			case 'perf':
				return <PerformanceSection { ...filters } />;
			case 'orbit':
				return <OrbitSection { ...filters } />;
			case 'research':
				return <ResearchSection { ...filters } />;
			default:
				return <OverviewSection { ...filters } />;
		}
	} )();

	return (
		<div>
			<div
				style={ {
					display: 'flex',
					alignItems: 'baseline',
					justifyContent: 'space-between',
					gap: '12px',
					flexWrap: 'wrap',
				} }
			>
				<p
					style={ {
						color: MUTED,
						margin: '4px 0 0',
						maxWidth: '52ch',
					} }
				>
					{ __(
						'Sample-weighted estimates from the nightly rollups — complete UTC days through yesterday, external traffic only.',
						'terraviz'
					) }
				</p>
				<div
					style={ {
						display: 'flex',
						gap: '12px',
						alignItems: 'flex-end',
					} }
				>
					<SelectControl
						label={ __( 'Range', 'terraviz' ) }
						value={ days }
						options={ RANGES }
						onChange={ setDays }
						__nextHasNoMarginBottom
					/>
					<SelectControl
						label={ __( 'Environment', 'terraviz' ) }
						value={ environment }
						options={ ENVIRONMENTS }
						onChange={ setEnvironment }
						__nextHasNoMarginBottom
					/>
				</div>
			</div>

			<div style={ { marginTop: '16px' } }>
				<SubTabs tabs={ TABS } active={ tab } onSelect={ setTab } />
			</div>

			{ section }
		</div>
	);
}
