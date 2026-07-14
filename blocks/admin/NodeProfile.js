/**
 * Node profile (Settings → Node profile), configure-tier.
 *
 * The host organization's identity — name, mission, about (markdown), region
 * focus, default tone, links, and logo. It grounds AI-generated drafts (the
 * generator is fed this profile) and brands the node's public blog surface.
 * Reads/writes proxy to the node's `node-profile` endpoints; the node is the
 * authoritative validator (only `orgName` is required) and returns a
 * field-error envelope we surface inline.
 */
import { __ } from '@wordpress/i18n';
import { useState, useEffect, useCallback, useRef } from '@wordpress/element';
import {
	Button,
	Notice,
	Spinner,
	TextControl,
	TextareaControl,
} from '@wordpress/components';
import {
	getNodeProfile,
	setNodeProfile,
	setNodeProfileLogo,
	deleteNodeProfileLogo,
	normalizeError,
} from './api';
import { safeHttpUrl } from './safeUrl';

const MAX_LINKS = 10;
const LOGO_MAX_BYTES = 512 * 1024;
const LOGO_TYPES = [ 'image/png', 'image/jpeg', 'image/webp' ];

// Read a File into bare base64 (no data-URI prefix), which the proxy forwards.
function fileToBase64( file ) {
	return new Promise( ( resolve, reject ) => {
		const reader = new window.FileReader();
		reader.onload = () => {
			const result = String( reader.result || '' );
			const comma = result.indexOf( ',' );
			resolve( comma >= 0 ? result.slice( comma + 1 ) : result );
		};
		reader.onerror = () =>
			reject( reader.error || new Error( 'read failed' ) );
		reader.readAsDataURL( file );
	} );
}

const EMPTY = {
	orgName: '',
	mission: '',
	aboutMd: '',
	regionFocus: '',
	defaultTone: '',
};

export default function NodeProfile() {
	const [ loading, setLoading ] = useState( true );
	const [ saving, setSaving ] = useState( false );
	const [ logoBusy, setLogoBusy ] = useState( false );
	const [ notice, setNotice ] = useState( null );
	const [ errors, setErrors ] = useState( [] );
	const [ form, setForm ] = useState( EMPTY );
	const [ links, setLinks ] = useState( [] );
	const [ logoUrl, setLogoUrl ] = useState( '' );
	const fileInput = useRef( null );

	const load = useCallback( () => {
		setLoading( true );
		getNodeProfile()
			.then( ( res ) => {
				const p = res && res.profile;
				if ( p ) {
					setForm( {
						orgName: p.orgName || '',
						mission: p.mission || '',
						aboutMd: p.aboutMd || '',
						regionFocus: p.regionFocus || '',
						defaultTone: p.defaultTone || '',
					} );
					setLinks(
						Array.isArray( p.links )
							? p.links.map( ( l ) => ( {
									label: l.label || '',
									url: l.url || '',
							  } ) )
							: []
					);
					setLogoUrl( p.logoUrl || '' );
				}
			} )
			.catch( ( e ) =>
				setNotice( {
					type: 'error',
					text: normalizeError( e ).message,
				} )
			)
			.finally( () => setLoading( false ) );
	}, [] );

	useEffect( () => {
		load();
	}, [ load ] );

	const fieldError = ( name ) => {
		const hit = errors.find( ( e ) => e.field === name );
		return hit ? hit.message : null;
	};
	const set = ( key ) => ( value ) => setForm( { ...form, [ key ]: value } );

	const setLink = ( i, key ) => ( value ) =>
		setLinks(
			links.map( ( l, j ) => ( j === i ? { ...l, [ key ]: value } : l ) )
		);
	const addLink = () =>
		setLinks( [ ...links, { label: '', url: '' } ].slice( 0, MAX_LINKS ) );
	const removeLink = ( i ) => setLinks( links.filter( ( l, j ) => j !== i ) );

	const save = () => {
		setSaving( true );
		setNotice( null );
		setErrors( [] );

		const data = {
			orgName: form.orgName,
			mission: form.mission,
			aboutMd: form.aboutMd,
			regionFocus: form.regionFocus,
			defaultTone: form.defaultTone,
			// Drop fully-empty rows; the node validates each remaining pair.
			links: links
				.filter( ( l ) => l.label.trim() || l.url.trim() )
				.map( ( l ) => ( {
					label: l.label.trim(),
					url: l.url.trim(),
				} ) ),
		};

		setNodeProfile( data )
			.then( () => {
				setNotice( {
					type: 'success',
					text: __( 'Profile saved.', 'terraviz' ),
				} );
				load();
			} )
			.catch( ( e ) => {
				const n = normalizeError( e );
				setErrors( n.errors );
				setNotice( {
					type: 'error',
					text:
						n.message ||
						__(
							'Could not save the profile. Check the highlighted fields.',
							'terraviz'
						),
				} );
			} )
			.finally( () => setSaving( false ) );
	};

	const onLogoChange = () => {
		const input = fileInput.current;
		const file = input && input.files && input.files[ 0 ];
		if ( ! file ) {
			return;
		}
		setNotice( null );
		if ( ! LOGO_TYPES.includes( file.type ) ) {
			setNotice( {
				type: 'error',
				text: __( 'Use a PNG, JPEG, or WebP logo.', 'terraviz' ),
			} );
			input.value = '';
			return;
		}
		if ( file.size > LOGO_MAX_BYTES ) {
			setNotice( {
				type: 'error',
				text: __( 'The logo is too large (max 512 KB).', 'terraviz' ),
			} );
			input.value = '';
			return;
		}
		setLogoBusy( true );
		fileToBase64( file )
			.then( ( dataBase64 ) =>
				setNodeProfileLogo( { contentType: file.type, dataBase64 } )
			)
			.then( ( res ) => {
				const p = res && res.profile;
				setLogoUrl( ( p && p.logoUrl ) || '' );
				setNotice( {
					type: 'success',
					text: __( 'Logo updated.', 'terraviz' ),
				} );
			} )
			.catch( ( e ) =>
				setNotice( {
					type: 'error',
					text:
						normalizeError( e ).message ||
						__( 'Could not upload the logo.', 'terraviz' ),
				} )
			)
			.finally( () => {
				setLogoBusy( false );
				if ( input ) {
					input.value = '';
				}
			} );
	};

	const removeLogo = () => {
		setLogoBusy( true );
		setNotice( null );
		deleteNodeProfileLogo()
			.then( () => setLogoUrl( '' ) )
			.catch( ( e ) =>
				setNotice( {
					type: 'error',
					text: normalizeError( e ).message,
				} )
			)
			.finally( () => setLogoBusy( false ) );
	};

	if ( loading ) {
		return <Spinner />;
	}

	const logoPreview = safeHttpUrl( logoUrl );

	return (
		<div style={ { maxWidth: '720px' } }>
			<p style={ { color: '#646970', marginTop: 0 } }>
				{ __(
					'Your organization’s identity. It grounds AI-generated drafts and brands your public blog on the globe.',
					'terraviz'
				) }
			</p>

			{ notice && (
				<Notice
					status={ notice.type }
					onRemove={ () => setNotice( null ) }
				>
					{ notice.text }
				</Notice>
			) }

			<h3>{ __( 'Logo', 'terraviz' ) }</h3>
			<div
				style={ {
					display: 'flex',
					alignItems: 'center',
					gap: '16px',
					marginBottom: '8px',
				} }
			>
				{ logoPreview ? (
					<img
						src={ logoPreview }
						alt={ __( 'Organization logo', 'terraviz' ) }
						style={ {
							width: '64px',
							height: '64px',
							objectFit: 'contain',
							border: '1px solid #dcdcde',
							borderRadius: '4px',
							background: '#fff',
						} }
					/>
				) : (
					<div
						style={ {
							width: '64px',
							height: '64px',
							border: '1px dashed #c3c4c7',
							borderRadius: '4px',
							display: 'flex',
							alignItems: 'center',
							justifyContent: 'center',
							color: '#a7aaad',
							fontSize: '11px',
							textAlign: 'center',
						} }
					>
						{ __( 'No logo', 'terraviz' ) }
					</div>
				) }
				<div style={ { display: 'flex', gap: '8px' } }>
					<Button
						variant="secondary"
						isBusy={ logoBusy }
						disabled={ logoBusy }
						onClick={ () =>
							fileInput.current && fileInput.current.click()
						}
					>
						{ logoPreview
							? __( 'Replace logo', 'terraviz' )
							: __( 'Upload logo', 'terraviz' ) }
					</Button>
					{ logoPreview && (
						<Button
							variant="tertiary"
							isDestructive
							disabled={ logoBusy }
							onClick={ removeLogo }
						>
							{ __( 'Remove', 'terraviz' ) }
						</Button>
					) }
					<input
						ref={ fileInput }
						type="file"
						accept={ LOGO_TYPES.join( ',' ) }
						onChange={ onLogoChange }
						style={ { display: 'none' } }
					/>
				</div>
			</div>

			<h3>{ __( 'Identity', 'terraviz' ) }</h3>
			<TextControl
				label={ __( 'Organization name', 'terraviz' ) }
				value={ form.orgName }
				onChange={ set( 'orgName' ) }
				help={ fieldError( 'orgName' ) }
				__nextHasNoMarginBottom
			/>
			<TextControl
				label={ __( 'Region focus', 'terraviz' ) }
				value={ form.regionFocus }
				onChange={ set( 'regionFocus' ) }
				help={ fieldError( 'regionFocus' ) }
				__nextHasNoMarginBottom
			/>
			<TextControl
				label={ __( 'Default tone (for AI drafts)', 'terraviz' ) }
				value={ form.defaultTone }
				onChange={ set( 'defaultTone' ) }
				help={
					fieldError( 'defaultTone' ) ||
					__(
						'The house voice AI drafts fall back to when you don’t set a tone.',
						'terraviz'
					)
				}
				__nextHasNoMarginBottom
			/>
			<TextareaControl
				label={ __( 'Mission', 'terraviz' ) }
				value={ form.mission }
				onChange={ set( 'mission' ) }
				help={ fieldError( 'mission' ) }
				rows={ 3 }
				__nextHasNoMarginBottom
			/>
			<TextareaControl
				label={ __( 'About (Markdown)', 'terraviz' ) }
				value={ form.aboutMd }
				onChange={ set( 'aboutMd' ) }
				help={ fieldError( 'aboutMd' ) }
				rows={ 6 }
				__nextHasNoMarginBottom
			/>

			<h3>{ __( 'Links', 'terraviz' ) }</h3>
			{ links.length === 0 && (
				<p style={ { color: '#646970' } }>
					{ __( 'No links yet.', 'terraviz' ) }
				</p>
			) }
			{ links.map( ( link, i ) => (
				<div
					key={ i }
					style={ {
						display: 'flex',
						gap: '8px',
						alignItems: 'flex-start',
						marginBottom: '8px',
					} }
				>
					<div style={ { flex: '1 1 180px' } }>
						<TextControl
							label={ __( 'Label', 'terraviz' ) }
							value={ link.label }
							onChange={ setLink( i, 'label' ) }
							hideLabelFromVision={ i > 0 }
							__nextHasNoMarginBottom
						/>
					</div>
					<div style={ { flex: '2 1 280px' } }>
						<TextControl
							label={ __( 'URL', 'terraviz' ) }
							type="url"
							value={ link.url }
							onChange={ setLink( i, 'url' ) }
							hideLabelFromVision={ i > 0 }
							help={ fieldError( `links[${ i }]` ) }
							__nextHasNoMarginBottom
						/>
					</div>
					<Button
						variant="tertiary"
						isDestructive
						onClick={ () => removeLink( i ) }
						style={ { marginTop: i > 0 ? 0 : '24px' } }
					>
						{ __( 'Remove', 'terraviz' ) }
					</Button>
				</div>
			) ) }
			{ links.length < MAX_LINKS && (
				<Button variant="secondary" onClick={ addLink }>
					{ __( 'Add link', 'terraviz' ) }
				</Button>
			) }

			<div style={ { marginTop: '20px' } }>
				<Button
					variant="primary"
					onClick={ save }
					isBusy={ saving }
					disabled={ saving }
				>
					{ __( 'Save profile', 'terraviz' ) }
				</Button>
			</div>
		</div>
	);
}
