/**
 * Workflow cadence helpers. The node accepts any ISO-8601 duration between PT15M
 * and P90D; the dashboard offers a set of common presets and maps a stored
 * duration back to a human label (falling back to the raw duration for a custom
 * value set outside the dashboard).
 */
import { __ } from '@wordpress/i18n';

/**
 * The cadence presets offered in the form, in ascending order.
 *
 * @type {Array<{value: string, label: string}>}
 */
export const SCHEDULE_PRESETS = [
	{ value: 'PT15M', label: __( 'Every 15 minutes', 'terraviz' ) },
	{ value: 'PT1H', label: __( 'Hourly', 'terraviz' ) },
	{ value: 'PT6H', label: __( 'Every 6 hours', 'terraviz' ) },
	{ value: 'PT12H', label: __( 'Every 12 hours', 'terraviz' ) },
	{ value: 'P1D', label: __( 'Daily', 'terraviz' ) },
	{ value: 'P1W', label: __( 'Weekly', 'terraviz' ) },
	{ value: 'P30D', label: __( 'Every 30 days', 'terraviz' ) },
];

const BY_VALUE = SCHEDULE_PRESETS.reduce( ( acc, p ) => {
	acc[ p.value ] = p.label;
	return acc;
}, {} );

/**
 * A human label for a stored ISO-8601 cadence, or the raw duration when it's a
 * custom value not in the preset set.
 *
 * @param {string} schedule ISO-8601 duration.
 * @return {string} A label, or the raw duration, or '—'.
 */
export function scheduleLabel( schedule ) {
	if ( ! schedule ) {
		return '—';
	}
	return BY_VALUE[ schedule ] || schedule;
}
