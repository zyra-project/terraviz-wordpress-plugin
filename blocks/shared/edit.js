/**
 * Shared editor UI for the Terraviz blocks.
 *
 * Every block is server-rendered (its markup comes from PHP's shared
 * Renderer), so the editor uses <ServerSideRender> for a true-to-front-end
 * preview and exposes the embed options in the block sidebar. The Dataset and
 * Tour blocks get a typeahead picker (search by title via the plugin's
 * same-origin REST endpoint) so authors never type a raw catalog ULID.
 */

import { __ } from '@wordpress/i18n';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import {
	PanelBody,
	TextControl,
	ComboboxControl,
	ToggleControl,
	SelectControl,
	Placeholder,
	Button,
	ExternalLink,
} from '@wordpress/components';
import { useState, useEffect, useMemo } from '@wordpress/element';
import { useDebounce, useCopyToClipboard } from '@wordpress/compose';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';
import ServerSideRender from '@wordpress/server-side-render';

/**
 * Build the equivalent [terraviz] shortcode for the current attributes, so a
 * Classic-Editor author can copy it.
 *
 * @param {string} type       Embed type.
 * @param {Object} attributes Block attributes.
 * @return {string} Shortcode string.
 */
function buildShortcode( type, attributes ) {
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
	} = attributes;

	const parts = [ 'terraviz' ];
	if ( type === 'catalog' ) {
		parts.push( 'catalog="true"' );
		if ( category ) {
			parts.push( `category="${ category }"` );
		}
	} else {
		parts.push( `${ type }="${ id || '' }"` );
	}
	if ( origin ) {
		parts.push( `origin="${ origin }"` );
	}
	[
		[ 'terrain', terrain ],
		[ 'labels', labels ],
		[ 'borders', borders ],
		[ 'rotate', rotate ],
		[ 'chat', chat ],
	].forEach( ( [ key, on ] ) => {
		if ( on ) {
			parts.push( `${ key }="on"` );
		}
	} );
	if ( layout && layout !== 1 ) {
		parts.push( `layout="${ layout }"` );
	}
	if ( aspectRatio ) {
		parts.push( `aspect="${ aspectRatio }"` );
	}

	return `[${ parts.join( ' ' ) }]`;
}

/**
 * The dataset/tour typeahead picker.
 *
 * @param {Object}   props
 * @param {string}   props.type     'dataset' | 'tour'.
 * @param {string}   props.label    Field label.
 * @param {string}   props.value    Current id.
 * @param {Function} props.onChange Setter for the id.
 * @return {JSX.Element} The control.
 */
function SourcePicker( { type, label, value, onChange } ) {
	const [ options, setOptions ] = useState( [] );

	const search = ( input ) => {
		const q = ( input || '' ).trim();
		apiFetch( { path: addQueryArgs( '/terraviz/v1/search', { q, type } ) } )
			.then( ( items ) => {
				const mapped = ( items || [] ).map( ( item ) => ( {
					value: item.id,
					label: item.title || item.slug || item.id,
				} ) );
				// Let the author use a slug/ID typed as-is even if it isn't a
				// search hit (paste path), so nothing regresses.
				if ( q && ! mapped.some( ( o ) => o.value === q ) ) {
					mapped.push( {
						value: q,
						label: `${ __(
							'Use as entered',
							'terraviz'
						) }: ${ q }`,
					} );
				}
				setOptions( mapped );
			} )
			.catch( () => {
				setOptions( q ? [ { value: q, label: q } ] : [] );
			} );
	};

	const debouncedSearch = useDebounce( search, 300 );

	// Keep the current value selectable/displayable even before searching.
	const comboOptions = useMemo( () => {
		const base = options.slice();
		if ( value && ! base.some( ( o ) => o.value === value ) ) {
			base.unshift( { value, label: value } );
		}
		return base;
	}, [ options, value ] );

	return (
		<ComboboxControl
			label={ label }
			value={ value || '' }
			options={ comboOptions }
			onFilterValueChange={ ( input ) => debouncedSearch( input ) }
			onChange={ ( next ) => onChange( next || '' ) }
			help={ __(
				'Search by title, or type a slug/ID and pick “Use … as entered”. You can also paste a Terraviz dataset URL directly into the editor.',
				'terraviz'
			) }
		/>
	);
}

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
						'Search for a Terraviz tour in the block settings to preview the embed.',
						'terraviz'
				  )
				: __(
						'Search for a Terraviz dataset in the block settings to preview the embed.',
						'terraviz'
				  );

		const shortcode = buildShortcode( type, attributes );
		const [ copied, setCopied ] = useState( false );
		useEffect( () => setCopied( false ), [ shortcode ] );
		const copyRef = useCopyToClipboard( shortcode, () =>
			setCopied( true )
		);

		return (
			<div { ...blockProps }>
				<InspectorControls>
					<PanelBody title={ __( 'Terraviz source', 'terraviz' ) }>
						{ needsId && (
							<SourcePicker
								type={ type }
								label={ selectorLabel }
								value={ id }
								onChange={ ( next ) =>
									setAttributes( { id: next } )
								}
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
						{ hasSelection && (
							<p>
								<Button
									variant="secondary"
									ref={ copyRef }
									disabled={ ! shortcode }
								>
									{ copied
										? __( 'Copied!', 'terraviz' )
										: __( 'Copy shortcode', 'terraviz' ) }
								</Button>
								<code
									style={ {
										display: 'block',
										marginTop: '8px',
										wordBreak: 'break-all',
									} }
								>
									{ shortcode }
								</code>
							</p>
						) }
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
