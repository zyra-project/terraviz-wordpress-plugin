/**
 * Block-editor document panel: opt a post into the Terraviz blog stub and see
 * its sync status. Only enqueued for publish-tier users (server-side gate).
 */
import { registerPlugin } from '@wordpress/plugins';
import { PluginDocumentSettingPanel } from '@wordpress/edit-post';
import { ToggleControl, ExternalLink } from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { useEntityProp } from '@wordpress/core-data';
import { __ } from '@wordpress/i18n';

const boot = window.terravizPostPanel || {};

const OPTIN = '_terraviz_blog_optin';
const SLUG = '_terraviz_blog_slug';
const STATE = '_terraviz_blog_state';

function TerravizBlogPanel() {
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
	const slug = meta && meta[ SLUG ];
	const state = meta && meta[ STATE ];

	return (
		<PluginDocumentSettingPanel
			name="terraviz-blog"
			title={ __( 'Terraviz', 'terraviz' ) }
		>
			{ ! boot.credentialConfigured && (
				<p>
					{ __(
						'No Terraviz service token is configured, so posts can’t be surfaced yet.',
						'terraviz'
					) }
				</p>
			) }

			<ToggleControl
				label={ __( 'Show this post in Terraviz', 'terraviz' ) }
				help={ __(
					'Publishes a short, linked-back summary to the Terraviz blog for in-globe discovery, carrying the datasets/tours cited in this post. It updates whenever you update the post, and is removed if you turn this off or unpublish.',
					'terraviz'
				) }
				checked={ optedIn }
				disabled={ ! boot.credentialConfigured }
				onChange={ ( value ) =>
					setMeta( { ...meta, [ OPTIN ]: value } )
				}
			/>

			{ state === 'synced' && slug && (
				<p>
					{ __( 'Live on Terraviz:', 'terraviz' ) }{ ' ' }
					<ExternalLink href={ `${ boot.origin }/blog/${ slug }` }>
						{ slug }
					</ExternalLink>
				</p>
			) }
			{ state === 'unsynced' && (
				<p>{ __( 'Not currently shown on Terraviz.', 'terraviz' ) }</p>
			) }
			{ state === 'error' && (
				<p>
					{ __(
						'The last sync to Terraviz failed; it will retry the next time you update this post.',
						'terraviz'
					) }
				</p>
			) }
		</PluginDocumentSettingPanel>
	);
}

registerPlugin( 'terraviz-blog-panel', { render: TerravizBlogPanel } );
