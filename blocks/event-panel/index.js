/**
 * Block-editor document panel: propose a post to the Terraviz curator queue as
 * a news event, and see its status. Only enqueued for publish-tier users
 * (server-side gate).
 */
import { registerPlugin } from '@wordpress/plugins';
import { PluginDocumentSettingPanel } from '@wordpress/edit-post';
import { ToggleControl } from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { useEntityProp } from '@wordpress/core-data';
import { __ } from '@wordpress/i18n';

const boot = window.terravizEventPanel || {};

const OPTIN = '_terraviz_event_optin';
const STATE = '_terraviz_event_state';

function TerravizEventPanel() {
	const postType = useSelect(
		( select ) => select( 'core/editor' ).getCurrentPostType(),
		[]
	);
	const [ meta, setMeta ] = useEntityProp( 'postType', postType, 'meta' );

	// Only meaningful on standard posts.
	if ( postType !== 'post' ) {
		return null;
	}

	const optedIn = !! ( meta && meta[ OPTIN ] );
	const state = meta && meta[ STATE ];

	return (
		<PluginDocumentSettingPanel
			name="terraviz-event"
			title={ __( 'Terraviz event', 'terraviz' ) }
		>
			{ ! boot.credentialConfigured && (
				<p>
					{ __(
						'No Terraviz service token is configured, so events can’t be proposed yet.',
						'terraviz'
					) }
				</p>
			) }

			<ToggleControl
				label={ __(
					'Propose this post to Terraviz events',
					'terraviz'
				) }
				help={ __(
					'Sends this post to the Terraviz curator queue as a proposed news event — carrying the datasets it cites — for a curator to approve. It is proposed once: editing or unpublishing this post afterwards won’t change or remove the event.',
					'terraviz'
				) }
				checked={ optedIn }
				disabled={ ! boot.credentialConfigured }
				onChange={ ( value ) =>
					setMeta( { ...( meta || {} ), [ OPTIN ]: value } )
				}
			/>

			{ state === 'proposed' && (
				<p>
					{ __(
						'Proposed to Terraviz — awaiting curator review.',
						'terraviz'
					) }
				</p>
			) }
			{ state === 'error' && (
				<p>
					{ __(
						'The last proposal to Terraviz failed; it will retry the next time you update this post.',
						'terraviz'
					) }
				</p>
			) }
		</PluginDocumentSettingPanel>
	);
}

registerPlugin( 'terraviz-event-panel', { render: TerravizEventPanel } );
