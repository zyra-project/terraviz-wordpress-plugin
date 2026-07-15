/**
 * Feedback — the Insights area's review surface (Milestone C). A read-only facade
 * over the node's privilege-gated feedback endpoint (`GET /publish/feedback`),
 * split into two sub-tabs that mirror the Terraviz app's review scenes: **AI**
 * (Orbit thumbs up/down) and **General** (bug / feature / other reports). A shared
 * range filter sits above them. Bulk CSV/JSONL exports stay on the node's machine
 * endpoint; this is review, not export.
 */
import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { SelectControl } from '@wordpress/components';
import SubTabs from './SubTabs';
import { MUTED } from './analytics/primitives';
import { AiFeedbackSection, GeneralFeedbackSection } from './feedback/sections';

const RANGES = [
	{ label: __( 'Last 7 days', 'terraviz' ), value: '7' },
	{ label: __( 'Last 30 days', 'terraviz' ), value: '30' },
	{ label: __( 'Last 90 days', 'terraviz' ), value: '90' },
	{ label: __( 'Last year', 'terraviz' ), value: '365' },
];

const TABS = [
	{ key: 'ai', label: __( 'AI feedback', 'terraviz' ) },
	{ key: 'general', label: __( 'General feedback', 'terraviz' ) },
];

/**
 * @return {JSX.Element} The feedback area.
 */
export default function Feedback() {
	const [ tab, setTab ] = useState( 'ai' );
	const [ days, setDays ] = useState( '30' );

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
						'Reader ratings and reports from the globe. Trends and tags cover the selected range; the totals are all-time.',
						'terraviz'
					) }
				</p>
				<SelectControl
					label={ __( 'Range', 'terraviz' ) }
					value={ days }
					options={ RANGES }
					onChange={ setDays }
					__nextHasNoMarginBottom
				/>
			</div>

			<div style={ { marginTop: '16px' } }>
				<SubTabs tabs={ TABS } active={ tab } onSelect={ setTab } />
			</div>

			{ tab === 'general' ? (
				<GeneralFeedbackSection days={ days } />
			) : (
				<AiFeedbackSection days={ days } />
			) }
		</div>
	);
}
