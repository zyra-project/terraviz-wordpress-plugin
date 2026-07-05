/**
 * Shared editor UI for the Terraviz blocks.
 *
 * Every block is server-rendered (its markup comes from PHP's shared
 * Renderer), so the editor uses <ServerSideRender> for a true-to-front-end
 * preview and exposes the embed options in the block sidebar.
 */

import { __ } from '@wordpress/i18n';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import {
	PanelBody,
	TextControl,
	ToggleControl,
	SelectControl,
	Placeholder,
	ExternalLink,
} from '@wordpress/components';
import ServerSideRender from '@wordpress/server-side-render';

/**
 * Build an Edit component for a given embed type.
 *
 * @param {Object} config
 * @param {string} config.blockName Fully-qualified block name.
 * @param {string} config.type      'dataset' | 'tour' | 'catalog'.
 * @param {string} config.title     Human title for placeholders.
 * @return {Function} Edit component.
 */
export function createEdit( { blockName, type, title } ) {
	return function Edit( { attributes, setAttributes } ) {
		const blockProps = useBlockProps();
		const {
			id,
			origin,
			category,
			terrain,
			labels,
			borders,
			rotate,
			chat,
			layout,
			aspectRatio,
			poster,
			interactive,
			heading,
			showTitle,
			showAbstract,
		} = attributes;

		const needsId = type === 'dataset' || type === 'tour';
		const hasSelection = ! needsId || ( id && id.length > 0 );

		const selectorLabel =
			type === 'tour'
				? __( 'Tour slug or ID', 'terraviz' )
				: __( 'Dataset slug or ID', 'terraviz' );

		const placeholderInstructions =
			type === 'tour'
				? __(
						'Enter a Terraviz tour slug or ID in the block settings to preview the embed.',
						'terraviz'
				  )
				: __(
						'Enter a Terraviz dataset slug or ID in the block settings to preview the embed.',
						'terraviz'
				  );

		return (
			<div { ...blockProps }>
				<InspectorControls>
					<PanelBody title={ __( 'Terraviz source', 'terraviz' ) }>
						{ needsId && (
							<TextControl
								label={ selectorLabel }
								value={ id || '' }
								onChange={ ( value ) =>
									setAttributes( { id: value } )
								}
								help={ __(
									'A human-readable slug (e.g. hurricane-season-2024) or the catalog ID. Tip: paste a Terraviz dataset URL directly into the editor to auto-embed.',
									'terraviz'
								) }
							/>
						) }
						{ type === 'catalog' && (
							<TextControl
								label={ __(
									'Category filter (optional)',
									'terraviz'
								) }
								value={ category || '' }
								onChange={ ( value ) =>
									setAttributes( { category: value } )
								}
							/>
						) }
						<TextControl
							label={ __( 'Node origin (optional)', 'terraviz' ) }
							value={ origin || '' }
							onChange={ ( value ) =>
								setAttributes( { origin: value } )
							}
							placeholder={ __(
								'Uses the site default',
								'terraviz'
							) }
							help={ __(
								'Override the Terraviz node for this embed only.',
								'terraviz'
							) }
						/>
					</PanelBody>

					<PanelBody
						title={ __( 'Display', 'terraviz' ) }
						initialOpen={ false }
					>
						<ToggleControl
							label={ __( 'Interactive globe', 'terraviz' ) }
							checked={ !! interactive }
							onChange={ ( value ) =>
								setAttributes( { interactive: value } )
							}
							help={ __(
								'Off shows only the accessible thumbnail + text card.',
								'terraviz'
							) }
						/>
						<ToggleControl
							label={ __( 'Click-to-load poster', 'terraviz' ) }
							checked={ !! poster }
							onChange={ ( value ) =>
								setAttributes( { poster: value } )
							}
						/>
						<ToggleControl
							label={ __( 'Show title', 'terraviz' ) }
							checked={ !! showTitle }
							onChange={ ( value ) =>
								setAttributes( { showTitle: value } )
							}
						/>
						<ToggleControl
							label={ __( 'Show abstract', 'terraviz' ) }
							checked={ !! showAbstract }
							onChange={ ( value ) =>
								setAttributes( { showAbstract: value } )
							}
						/>
						<SelectControl
							label={ __( 'Heading level', 'terraviz' ) }
							value={ heading || 'h3' }
							options={ [
								{ label: 'H2', value: 'h2' },
								{ label: 'H3', value: 'h3' },
								{ label: 'H4', value: 'h4' },
								{ label: 'H5', value: 'h5' },
								{ label: 'H6', value: 'h6' },
							] }
							onChange={ ( value ) =>
								setAttributes( { heading: value } )
							}
						/>
						<TextControl
							label={ __( 'Aspect ratio', 'terraviz' ) }
							value={ aspectRatio || '' }
							onChange={ ( value ) =>
								setAttributes( { aspectRatio: value } )
							}
							placeholder={ __( '16:9', 'terraviz' ) }
						/>
					</PanelBody>

					<PanelBody
						title={ __( 'Globe view', 'terraviz' ) }
						initialOpen={ false }
					>
						<ToggleControl
							label={ __( 'Terrain', 'terraviz' ) }
							checked={ !! terrain }
							onChange={ ( value ) =>
								setAttributes( { terrain: value } )
							}
						/>
						<ToggleControl
							label={ __( 'Place labels', 'terraviz' ) }
							checked={ !! labels }
							onChange={ ( value ) =>
								setAttributes( { labels: value } )
							}
						/>
						<ToggleControl
							label={ __( 'Borders', 'terraviz' ) }
							checked={ !! borders }
							onChange={ ( value ) =>
								setAttributes( { borders: value } )
							}
						/>
						<ToggleControl
							label={ __( 'Auto-rotate', 'terraviz' ) }
							checked={ !! rotate }
							onChange={ ( value ) =>
								setAttributes( { rotate: value } )
							}
						/>
						<ToggleControl
							label={ __( 'Show Orbit chat', 'terraviz' ) }
							checked={ !! chat }
							onChange={ ( value ) =>
								setAttributes( { chat: value } )
							}
						/>
						<SelectControl
							label={ __( 'Layout', 'terraviz' ) }
							value={ String( layout || 1 ) }
							options={ [
								{
									label: __( 'Single globe', 'terraviz' ),
									value: '1',
								},
								{
									label: __( 'Side by side', 'terraviz' ),
									value: '2',
								},
								{
									label: __( 'Four globes', 'terraviz' ),
									value: '4',
								},
							] }
							onChange={ ( value ) =>
								setAttributes( {
									layout: parseInt( value, 10 ),
								} )
							}
						/>
					</PanelBody>
				</InspectorControls>

				{ hasSelection ? (
					<ServerSideRender
						block={ blockName }
						attributes={ attributes }
					/>
				) : (
					<Placeholder
						icon="admin-site-alt3"
						label={ title }
						instructions={ placeholderInstructions }
					>
						<ExternalLink href="https://terraviz.zyra-project.org/?catalog=true">
							{ __( 'Browse the Terraviz catalog', 'terraviz' ) }
						</ExternalLink>
					</Placeholder>
				) }
			</div>
		);
	};
}
